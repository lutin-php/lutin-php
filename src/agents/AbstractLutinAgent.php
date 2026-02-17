<?php
declare(strict_types=1);

/**
 * Base class for all Lutin AI agents.
 * Provides common infrastructure: provider adapter, message history, agentic loop, SSE output,
 * and file management tool execution.
 * Subclasses must implement buildSystemPrompt and select tools via buildToolDefinitions().
 */
abstract class AbstractLutinAgent {
    /** Maximum number of iterations in the agentic loop to prevent infinite loops */
    protected const MAX_ITERATIONS = 10;

    /** Full list of all available tools */
    protected const COMMON_AVAILABLE_TOOLS = [
        'list_files' => [
            'name' => 'list_files',
            'description' => 'Lists files in a directory. Path is relative to project root (parent of web root). Use empty string "" for project root.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Directory path relative to project root (e.g., "src", "public", "docs")'],
                ],
                'required' => ['path'],
            ],
        ],
        'read_file' => [
            'name' => 'read_file',
            'description' => 'Reads a file. Path is relative to project root.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'File path relative to project root (e.g., "src/classes/MyClass.php")'],
                ],
                'required' => ['path'],
            ],
        ],
        'write_file' => [
            'name' => 'write_file',
            'description' => 'Writes or creates a file. Path is relative to project root. Cannot write to the lutin/ directory.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'File path relative to project root (e.g., "src/classes/MyClass.php")'],
                    'content' => ['type' => 'string', 'description' => 'File content'],
                ],
                'required' => ['path', 'content'],
            ],
        ],
    ];

    protected LutinConfig $config;
    protected LutinFileManager $fm;
    protected LutinProviderAdapter $adapter;

    /** Message history accumulated during this request (role/content pairs) */
    protected array $messages = [];

    /** Tool definitions sent to the API */
    protected array $toolDefinitions;

    public function __construct(LutinConfig $config, LutinFileManager $fm) {
        $this->config = $config;
        $this->fm = $fm;
        $this->adapter = $this->buildAdapter();
        // Build provider-agnostic tools (subclass defines which tools via buildToolDefinitions), then format for the specific provider
        $rawTools = $this->buildToolDefinitions();
        $this->toolDefinitions = $this->adapter->formatTools($rawTools);
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
     * Builds the system prompt.
     * Subclasses must implement this to define their system prompt.
     */
    abstract protected function buildSystemPrompt(): string;

    /**
     * Returns the tool schema array in provider-agnostic format.
     * Subclasses override this to select which tools they want from COMMON_AVAILABLE_TOOLS.
     * 
     * @return array Provider-agnostic tool definitions
     */
    protected function buildToolDefinitions(): array {
        // By default, return all available tools
        // Subclasses should override to select specific tools via parent::buildToolDefinitions(['tool1', 'tool2'])
        return array_values(self::COMMON_AVAILABLE_TOOLS);
    }

    /**
     * Helper to select specific tools from COMMON_AVAILABLE_TOOLS.
     * 
     * @param string[] $toolNames List of tool names to include
     * @return array Provider-agnostic tool definitions
     */
    protected function selectTools(array $toolNames): array {
        $tools = [];
        foreach ($toolNames as $name) {
            if (isset(self::COMMON_AVAILABLE_TOOLS[$name])) {
                $tools[] = self::COMMON_AVAILABLE_TOOLS[$name];
            }
        }
        return $tools;
    }

    /**
     * Helper method to append file content to a prompt.
     * 
     * @param string $prompt The current prompt
     * @param string $filePath Path to the file to read
     * @param string $label Label to include before the content (e.g., "AGENTS.md")
     * @return string The updated prompt
     */
    protected function addFileContentToPrompt(string $prompt, string $filePath, string $label): string {
        if (file_exists($filePath) && is_readable($filePath)) {
            $content = file_get_contents($filePath);
            if ($content !== false) {
                $prompt .= "\n\n---\n\nThe following is additional context about this specific project from {$label}:\n\n" . $content;
            }
        }
        return $prompt;
    }

    /**
     * Executes a tool call from the AI.
     * Handles file management tools from COMMON_AVAILABLE_TOOLS.
     * Subclasses can override to add custom tool handling.
     * 
     * @param string $name The tool name
     * @param array $input The tool input parameters
     * @return string The result as a string (typically JSON-encoded)
     */
    protected function executeTool(string $name, array $input): string {
        try {
            return match ($name) {
                'list_files' => json_encode($this->fm->listFiles($input['path'] ?? '')),
                'read_file' => $this->fm->readFile($input['path'] ?? ''),
                'write_file' => (function() use ($input) {
                    $this->fm->writeFile($input['path'] ?? '', $input['content'] ?? '');
                    return json_encode(['ok' => true]);
                })(),
                default => $this->executeCustomTool($name, $input),
            };
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Hook for subclasses to implement custom tool execution.
     * Called when a tool name is not recognized by executeTool().
     * 
     * @param string $name The tool name
     * @param array $input The tool input parameters
     * @return string The result as a string (typically JSON-encoded)
     */
    protected function executeCustomTool(string $name, array $input): string {
        return 'Unknown tool: ' . $name;
    }

    /**
     * Main entry point for agent interaction.
     * Sets up SSE headers, processes the user message, and runs the agentic loop.
     * 
     * @param string $userMessage The user's input message
     * @param array $history Previous conversation history
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
     * Agentic loop (called recursively up to MAX_ITERATIONS).
     * Communicates with the AI provider, handles responses, executes tools, and continues
     * the conversation until completion or max iterations reached.
     * 
     * @param int $iteration Current iteration count
     */
    protected function runLoop(int $iteration = 0): void {
        if ($iteration >= self::MAX_ITERATIONS) {
            $this->sseFlush(['type' => 'stop', 'stop_reason' => 'max_iterations']);
            return;
        }

        try {
            $systemPrompt = $this->buildSystemPrompt();
            $generator = $this->adapter->stream($this->messages, $this->toolDefinitions, $systemPrompt);

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
     * Sends a single SSE event.
     * Format:  "data: {json}\n\n"
     * Flushes immediately.
     * 
     * @param array $payload The data to send
     */
    protected function sseFlush(array $payload): void {
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
    }
}
