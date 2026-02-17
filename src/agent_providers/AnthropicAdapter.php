<?php
declare(strict_types=1);

/**
 * Anthropic (Claude) provider adapter.
 * Implements the LutinProviderAdapter interface for Anthropic's API.
 */
class AnthropicAdapter implements LutinProviderAdapter {
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
}
