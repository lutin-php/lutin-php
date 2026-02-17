<?php
declare(strict_types=1);

/**
 * Base class for all Lutin AI agents.
 * Provides common infrastructure: provider adapter, message history, agentic loop, and SSE output.
 * Subclasses must implement tool definitions and tool execution logic.
 */
abstract class LutinAgent {
    /** Maximum number of iterations in the agentic loop to prevent infinite loops */
    protected const MAX_ITERATIONS = 10;

    protected LutinConfig $config;
    protected LutinFileManager $fm;
    protected LutinProviderAdapter $adapter;

    /** Message history accumulated during this request (role/content pairs) */
    protected array $messages = [];

    /** Tool definitions sent to the API */
    protected array $toolDefinitions;

    /** Cached system prompt (base + AGENTS.md if present) */
    protected ?string $systemPrompt = null;

    /**
     * Base system prompt. Subclasses can override or extend this.
     */
    protected function getBaseSystemPrompt(): string {
        return 'You are Lutin, an AI assistant integrated into a PHP website editor. ' .
            'You can read files, list directories, and write files anywhere in the project (relative to project root). ' .
            'The web root (public files) is typically in a subdirectory like "public/" or "www/". ' .
            'Always prefer making minimal, targeted changes. Never modify lutin.php or access the lutin/ directory. ' .
            'When asked to create or modify a page, read the existing files first to understand the structure.';
    }

    public function __construct(LutinConfig $config, LutinFileManager $fm) {
        $this->config = $config;
        $this->fm = $fm;
        $this->adapter = $this->buildAdapter();
        $this->toolDefinitions = $this->buildToolDefinitions();
    }

    /**
     * Builds the system prompt by combining the base prompt with AGENTS.md content if present.
     * The AGENTS.md file is read from the lutin directory.
     */
    protected function buildSystemPrompt(): string {
        if ($this->systemPrompt !== null) {
            return $this->systemPrompt;
        }

        $basePrompt = $this->getBaseSystemPrompt();

        $lutinDir = $this->config->getLutinDir();
        $agentsMdPath = $lutinDir . '/AGENTS.md';

        if (file_exists($agentsMdPath) && is_readable($agentsMdPath)) {
            $agentsContent = file_get_contents($agentsMdPath);
            if ($agentsContent !== false) {
                $basePrompt .= "\n\n---\n\nThe following is additional context about this specific project from AGENTS.md:\n\n" . $agentsContent;
            }
        }

        $this->systemPrompt = $basePrompt;
        return $basePrompt;
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
     * Subclasses must implement this to define their available tools.
     */
    abstract protected function buildToolDefinitions(): array;

    /**
     * Executes a tool call from the AI.
     * Subclasses must implement this to handle their specific tools.
     * 
     * @param string $name The tool name
     * @param array $input The tool input parameters
     * @return string The result as a string (typically JSON-encoded)
     */
    abstract protected function executeTool(string $name, array $input): string;

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
