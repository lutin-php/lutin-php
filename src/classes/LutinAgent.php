<?php
declare(strict_types=1);

interface LutinProviderAdapter {
    /**
     * Sends a request to the AI API.
     * Returns a generator that yields SSE-formatted strings.
     * Each yielded string is either:
     *   - A text delta:   "data: " . json_encode(['type'=>'text','delta'=>'...']) . "\n\n"
     *   - A tool call:    "data: " . json_encode(['type'=>'tool_call','name'=>'...','input'=>[...],'id'=>'...']) . "\n\n"
     *   - A stop signal:  "data: " . json_encode(['type'=>'stop','stop_reason'=>'...']) . "\n\n"
     */
    public function stream(array $messages, array $tools): \Generator;
}

class AnthropicAdapter implements LutinProviderAdapter {
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model) {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function stream(array $messages, array $tools): \Generator {
        $url = 'https://api.anthropic.com/v1/messages';

        $payload = [
            'model' => $this->model,
            'max_tokens' => 8192,
            'system' => 'You are Lutin, an AI assistant integrated into a PHP website editor. ' .
                'You can read files, list directories, and write files on the server. ' .
                'Always prefer making minimal, targeted changes. Never modify lutin.php or .lutin/ system files. ' .
                'When asked to create or modify a page, read the existing files first to understand the structure.',
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

class OpenAIAdapter implements LutinProviderAdapter {
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model) {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function stream(array $messages, array $tools): \Generator {
        $url = 'https://api.openai.com/v1/chat/completions';

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
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
}

class LutinAgent {
    private const MAX_ITERATIONS = 10;

    private LutinConfig $config;
    private LutinFileManager $fm;
    private LutinProviderAdapter $adapter;

    // Message history accumulated during this request (role/content pairs)
    private array $messages = [];

    // Tool definitions sent to the API
    private array $toolDefinitions;

    public function __construct(LutinConfig $config, LutinFileManager $fm) {
        $this->config = $config;
        $this->fm = $fm;
        $this->adapter = $this->buildAdapter();
        $this->toolDefinitions = $this->buildToolDefinitions();
    }

    /**
     * Selects the correct adapter based on config->getProvider().
     * Throws \RuntimeException if provider is unknown.
     */
    private function buildAdapter(): LutinProviderAdapter {
        $provider = $this->config->getProvider();
        $apiKey = $this->config->getApiKey();
        $model = $this->config->getModel() ?? 'claude-3-5-haiku-20241022';

        if ($apiKey === null) {
            throw new \RuntimeException('API key not configured');
        }

        return match ($provider) {
            'anthropic' => new AnthropicAdapter($apiKey, $model),
            'openai' => new OpenAIAdapter($apiKey, $model),
            default => throw new \RuntimeException('Unknown provider: ' . $provider),
        };
    }

    /**
     * Returns the tool schema array in the format expected by the current provider.
     */
    private function buildToolDefinitions(): array {
        $provider = $this->config->getProvider();

        $tools = [
            [
                'name' => 'list_files',
                'description' => 'Lists files in a directory. Path is relative to site root.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Directory path relative to root'],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => 'read_file',
                'description' => 'Reads a file. Path is relative to site root.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'File path relative to root'],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => 'write_file',
                'description' => 'Writes or creates a file. Path is relative to site root.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'File path relative to root'],
                        'content' => ['type' => 'string', 'description' => 'File content'],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
        ];

        if ($provider === 'openai') {
            // OpenAI format
            return array_map(function($tool) {
                return [
                    'type' => 'function',
                    'function' => $tool,
                ];
            }, $tools);
        }

        // Anthropic format
        return $tools;
    }

    /**
     * Main entry point.
     * 1. Sets up SSE headers
     * 2. Appends the user message to $this->messages
     * 3. Runs the agentic loop
     */
    public function chat(string $userMessage, array $history): void {
        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        ob_end_clean();
        ob_implicit_flush(true);

        // Restore message history
        $this->messages = $history;

        // Add user message
        $this->messages[] = ['role' => 'user', 'content' => $userMessage];

        // Run agentic loop
        $this->runLoop();

        // Final done marker
        echo "data: [DONE]\n\n";
        flush();
    }

    /**
     * Agentic loop (called recursively up to MAX_ITERATIONS = 10)
     */
    private function runLoop(int $iteration = 0): void {
        if ($iteration >= self::MAX_ITERATIONS) {
            $this->sseFlush(['type' => 'stop', 'stop_reason' => 'max_iterations']);
            return;
        }

        try {
            $generator = $this->adapter->stream($this->messages, $this->toolDefinitions);

            $assistantContent = [];
            $textBuffer = '';
            $stopReason = null;
            $toolCalls = [];

            foreach ($generator as $event) {
                $line = trim(substr($event, 6)); // Remove "data: " prefix
                $data = json_decode($line, true);

                if ($data === null) {
                    continue;
                }

                if ($data['type'] === 'text') {
                    $textBuffer .= $data['delta'];
                    $this->sseFlush(['type' => 'text', 'delta' => $data['delta']]);
                } elseif ($data['type'] === 'tool_call') {
                    // Store tool call for execution
                    $toolCalls[] = [
                        'id' => $data['id'],
                        'name' => $data['name'],
                        'input' => $data['input'] ?? [],
                    ];
                    $this->sseFlush([
                        'type' => 'tool_start',
                        'name' => $data['name'],
                        'input' => $data['input'] ?? [],
                        'id' => $data['id']
                    ]);
                } elseif ($data['type'] === 'stop') {
                    $stopReason = $data['stop_reason'] ?? 'end_turn';
                } elseif ($data['type'] === 'error') {
                    // Forward errors to client
                    $this->sseFlush(['type' => 'error', 'message' => $data['message']]);
                    return;
                }
            }

            // Build the assistant message for history
            if (!empty($textBuffer)) {
                $assistantContent[] = ['type' => 'text', 'text' => $textBuffer];
            }
            foreach ($toolCalls as $tool) {
                $assistantContent[] = [
                    'type' => 'tool_use',
                    'id' => $tool['id'],
                    'name' => $tool['name'],
                    'input' => $tool['input'],
                ];
            }

            // Add assistant message to history (needed for proper context)
            if (!empty($assistantContent)) {
                $this->messages[] = ['role' => 'assistant', 'content' => $assistantContent];
            }

            // Check stop reason and execute tools if needed
            if ($stopReason === 'tool_use' || $stopReason === 'tool_calls') {
                if (!empty($toolCalls)) {
                    // Execute tools and add results to messages
                    foreach ($toolCalls as $tool) {
                        $result = $this->executeTool($tool['name'], $tool['input']);
                        
                        // Add tool result to message history
                        $this->messages[] = [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'tool_result',
                                    'tool_use_id' => $tool['id'],
                                    'content' => $result,
                                ]
                            ]
                        ];
                        
                        $this->sseFlush([
                            'type' => 'tool_result',
                            'id' => $tool['id'],
                            'result' => $result,
                        ]);
                    }
                    
                    // Continue loop for next response
                    $this->runLoop($iteration + 1);
                } else {
                    // No tools to execute, just end
                    $this->sseFlush(['type' => 'stop', 'stop_reason' => $stopReason]);
                }
            } else {
                // End of conversation
                $this->sseFlush(['type' => 'stop', 'stop_reason' => $stopReason ?? 'end_turn']);
            }
        } catch (\Throwable $e) {
            $this->sseFlush(['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Dispatches a tool call from the AI to the appropriate LutinFileManager method.
     * Returns the result as a string
     */
    private function executeTool(string $name, array $input): string {
        try {
            return match ($name) {
                'list_files' => json_encode($this->fm->listFiles($input['path'] ?? '')),
                'read_file' => $this->fm->readFile($input['path'] ?? ''),
                'write_file' => (function() use ($input) {
                    $this->fm->writeFile($input['path'] ?? '', $input['content'] ?? '');
                    return json_encode(['ok' => true]);
                })(),
                default => 'Unknown tool: ' . $name,
            };
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Sends a single SSE event.
     * Format:  "data: {json}\n\n"
     * Flushes immediately.
     */
    private function sseFlush(array $payload): void {
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
    }
}
