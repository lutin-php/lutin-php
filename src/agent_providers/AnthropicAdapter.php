<?php
declare(strict_types=1);

/**
 * Anthropic (Claude) provider adapter.
 * Implements the AbstractLutinAdapter interface for Anthropic's API.
 */
class AnthropicAdapter implements AbstractLutinAdapter {
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model) {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function stream(array $messages, array $tools, string $systemPrompt = ''): \Generator {
        $url = 'https://api.anthropic.com/v1/messages';

        // Use provided system prompt or fall back to default
        $system = $systemPrompt ?: 'You are Lutin, an AI assistant integrated into a PHP website editor. ' .
            'You can read files, list directories, and write files anywhere in the project (relative to project root). ' .
            'The web root (public files) is typically in a subdirectory like "public/" or "www/". ' .
            'Always prefer making minimal, targeted changes. Never modify lutin.php or access the lutin/ directory. ' .
            'When asked to create or modify a page, read the existing files first to understand the structure.';

        $payload = [
            'model' => $this->model,
            'max_tokens' => 8192,
            'system' => $system,
            'messages' => $messages,
            'tools' => $tools,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
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
        if (!$data || !isset($data['content'])) {
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'Unexpected API response format'
            ]) . "\n\n";
            return;
        }

        // Process content blocks
        foreach ($data['content'] as $block) {
            if ($block['type'] === 'text') {
                yield 'data: ' . json_encode(['type' => 'text', 'delta' => $block['text']]) . "\n\n";
            } elseif ($block['type'] === 'tool_use') {
                yield 'data: ' . json_encode([
                    'type' => 'tool_call',
                    'id' => $block['id'] ?? 'unknown',
                    'name' => $block['name'] ?? 'unknown',
                    'input' => $block['input'] ?? [],
                ]) . "\n\n";
            }
        }

        // Yield stop signal
        $stopReason = $data['stop_reason'] ?? 'end_turn';
        yield 'data: ' . json_encode(['type' => 'stop', 'stop_reason' => $stopReason]) . "\n\n";
    }

    /**
     * Anthropic uses tools in the generic format directly.
     * No transformation needed.
     */
    public function formatTools(array $tools): array {
        return $tools;
    }

    public function prepareHistory(array $messages): array {
        $prepared = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];

            // Conversion Assistant (Appels d'outils)
            if ($role === 'assistant' && !empty($msg['tool_calls'])) {
                $content = [];
                if (!empty($msg['content'])) {
                    $content[] = ['type' => 'text', 'text' => $msg['content']];
                }
                foreach ($msg['tool_calls'] as $tc) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'],
                        'name' => $tc['name'],
                        'input' => $tc['input']
                    ];
                }
                $prepared[] = ['role' => 'assistant', 'content' => $content];
                continue;
            }

            // Conversion Tool Result -> User role avec type tool_result
            if ($role === 'tool_result') {
                $prepared[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $msg['tool_call_id'],
                        'content' => $msg['content']
                    ]]
                ];
                continue;
            }

            // Cas standard
            $prepared[] = [
                'role' => $role,
                'content' => $msg['content']
            ];
        }

        return $prepared;
    }
}
