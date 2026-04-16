<?php
declare(strict_types=1);

/**
 * OpenAI (GPT) provider adapter.
 * Implements the AbstractLutinAdapter interface for OpenAI's API.
 */
class OpenAIGenericAdapter implements AbstractLutinAdapter {
    private string $url;
    private string $apiKey;
    private string $model;

    public function __construct(string $url, string $apiKey, string $model) {
        $this->url = $url;
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function stream(array $messages, array $tools, string $systemPrompt = ''): \Generator {

        // Use provided system prompt or fall back to default
        $system = $systemPrompt ?: 'You are Lutin, an AI assistant integrated into a PHP website editor. ' .
            'You can read files, list directories, and write files anywhere in the project (relative to project root). ' .
            'The web root (public files) is typically in a subdirectory like "public/" or "www/". ' .
            'Always prefer making minimal, targeted changes. Never modify lutin.php or access the lutin/ directory. ' .
            'When asked to create or modify a page, read the existing files first to understand the structure.';

        // Prepend system message to messages array
        $fullMessages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $messages
        );

        $payload = [
            'model' => $this->model,
            'messages' => $fullMessages,
            'stream' => false,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        // TMP
        file_put_contents('php://stderr', json_encode($payload, JSON_PRETTY_PRINT));

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'User-Agent: Lutin-Agent-PHP/1.0',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 300,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check for curl errors
        if ($response === false) {
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'API request failed: ' . $curlError
            ]) . "\n\n";
            return;
        }

        // Check for HTTP errors
        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? 'HTTP ' . $httpCode . ' error';
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'API error: ' . $errorMessage
            ]) . "\n\n";
            return;
        }

        // Parse the non-streaming response
        $data = json_decode($response, true);
        if (!$data || !isset($data['choices'][0]['message'])) {
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'Unexpected API response format'
            ]) . "\n\n";
            return;
        }

        $message = $data['choices'][0]['message'];

        // Handle tool calls
        if (isset($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $toolCall) {
                $function = $toolCall['function'] ?? [];
                $arguments = [];
                if (isset($function['arguments'])) {
                    $arguments = json_decode($function['arguments'], true) ?? [];
                }
                yield 'data: ' . json_encode([
                    'type' => 'tool_call',
                    'id' => $toolCall['id'] ?? 'unknown',
                    'name' => $function['name'] ?? 'unknown',
                    'input' => $arguments,
                ]) . "\n\n";
            }
        }

        // Handle text content
        if (isset($message['content']) && !empty($message['content'])) {
            yield 'data: ' . json_encode(['type' => 'text', 'delta' => $message['content']]) . "\n\n";
        }

        // Yield stop signal
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'stop';
        yield 'data: ' . json_encode(['type' => 'stop', 'stop_reason' => $finishReason]) . "\n\n";
    }

    /**
     * OpenAI wraps each tool in a specific structure:
     * [
     *   'type' => 'function',
     *   'function' => [name, description, parameters]
     * ]
     * 
     * Note: We map 'input_schema' to 'parameters' for OpenAI.
     */
    public function formatTools(array $tools): array {
        return array_map(function($tool) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => (object)($tool['input_schema'] ?? [
                        'type' => 'object',
                        'properties' => (object)[]
                    ]),
                ],
            ];
        }, $tools);
    }

    public function prepareHistory(array $messages): array {
        $prepared = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];
            $content = $msg['content'] ?? '';

            // Si c'est un message assistant avec des appels d'outils
            if ($role === 'assistant' && !empty($msg['tool_calls'])) {
                $toolCalls = [];
                foreach ($msg['tool_calls'] as $tc) {
                    $toolCalls[] = [
                        'id' => $tc['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'],
                            'arguments' => json_encode($tc['input'])
                        ]
                    ];
                }
                $prepared[] = [
                    'role' => 'assistant',
                    'content' => is_array($content) ? null : $content, // OpenAI n'aime pas les tableaux ici
                    'tool_calls' => $toolCalls
                ];
                continue;
            }

            // Si c'est un résultat d'outil
            if ($role === 'tool_result') {
                $prepared[] = [
                    'role' => 'tool',
                    'tool_call_id' => $msg['tool_call_id'],
                    'content' => is_string($msg['content']) ? $msg['content'] : json_encode($msg['content'])
                ];
                continue;
            }

            // Cas standard (user, system, ou assistant texte pur)
            $prepared[] = [
                'role' => $role,
                'content' => is_array($content) ? ($content[0]['text'] ?? '') : $content
            ];
        }

        return $prepared;
    }
}
