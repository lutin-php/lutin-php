<?php
declare(strict_types=1);

/**
 * Chat Agent for Lutin.
 * Handles conversational AI interactions with file management capabilities.
 * Extends the base LutinAgent with specific tool definitions for file operations.
 */
class LutinChatAgent extends LutinAgent {

    /**
     * Returns the tool schema array for file operations.
     * Tools: list_files, read_file, write_file
     */
    protected function buildToolDefinitions(): array {
        $provider = $this->config->getProvider();

        $tools = [
            [
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
            [
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
            [
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
     * Dispatches a tool call from the AI to the appropriate LutinFileManager method.
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
                default => 'Unknown tool: ' . $name,
            };
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }
}
