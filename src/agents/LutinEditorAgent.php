<?php
declare(strict_types=1);

/**
 * Editor Agent for Lutin.
 * Specialized agent for the editor page that helps users with code editing tasks.
 * Provides file management capabilities plus the ability to open files in the editor.
 */
class LutinEditorAgent extends AbstractLutinAgent {

    /** Cached system prompt */
    private ?string $systemPrompt = null;

    /** Currently open file in the editor (if any) */
    private ?string $currentFile = null;

    /** Content of the currently open file */
    private ?string $currentContent = null;

    /**
     * Sets the current file context for this agent instance.
     * This should be called before chat() to provide file context.
     *
     * @param string|null $path Relative path to the current file
     * @param string|null $content Content of the current file
     */
    public function setCurrentFile(?string $path, ?string $content): void {
        $this->currentFile = $path;
        $this->currentContent = $content;
    }

    /**
     * Base system prompt for the editor agent.
     */
    protected function getBaseSystemPrompt(): string {
        return 'You are Lutin Editor, an AI coding assistant integrated into a web-based code editor. ' .
            'Your purpose is to help users write, edit, and understand code.\n\n' .
            'CAPABILITIES:\n' .
            '- You can read files to understand the project structure\n' .
            '- You can write or modify files to make changes\n' .
            '- You can open files in the editor to show them to the user\n' .
            '- You can list directories to explore the codebase\n\n' .
            'EDITOR CONTEXT:\n' .
            '- The user is working in a file editor with syntax highlighting\n' .
            '- The web root (public files) is typically in a subdirectory like "public/" or "www/"\n' .
            '- The project root contains all source code, configuration, and website files\n' .
            '- Paths are always relative to the project root\n\n' .
            'GUIDELINES:\n' .
            '- Always prefer making minimal, targeted changes\n' .
            '- When suggesting code changes, explain what you\'re doing and why\n' .
            '- If you need to reference another file, use open_file_in_editor to show it\n' .
            '- Never modify lutin.php or access the lutin/ directory\n' .
            '- When writing files, ensure the code is complete and valid\n' .
            '- If the user asks about the current file, the content is already provided in context\n\n' .
            'WORKFLOW:\n' .
            '1. If the user asks about specific code, check if you need to read other files for context\n' .
            '2. When making changes, write the complete file content\n' .
            '3. After making changes, offer to open related files if relevant\n' .
            '4. Always confirm successful file operations';
    }

    /**
     * Builds the system prompt by combining the base prompt with:
     * - Current file context (if a file is open)
     * - AGENTS.md content if present
     */
    protected function buildSystemPrompt(): string {
        if ($this->systemPrompt !== null) {
            return $this->systemPrompt;
        }

        $basePrompt = $this->getBaseSystemPrompt();

        // Add current file context if available
        if ($this->currentFile !== null && $this->currentContent !== null) {
            $basePrompt .= "\n\n---\n\nCURRENT FILE CONTEXT:\n" .
                "File: {$this->currentFile}\n" .
                "Content:\n```\n" . $this->currentContent . "\n```\n" .
                "The user is currently editing this file. You can reference it when answering questions.";
        }

        // Add AGENTS.md content if present
        $lutinDir = $this->config->getLutinDir();
        $agentsMdPath = $lutinDir . '/AGENTS.md';
        $basePrompt = $this->addFileContentToPrompt($basePrompt, $agentsMdPath, 'AGENTS.md');

        $this->systemPrompt = $basePrompt;
        return $basePrompt;
    }

    /**
     * Returns the tool schema array for editor operations.
     * Includes all file management tools plus open_file_in_editor.
     *
     * @return array Provider-agnostic tool definitions
     */
    protected function buildToolDefinitions(): array {
        return $this->selectTools(['list_files', 'read_file', 'write_file', 'open_file_in_editor']);
    }

    /**
     * Executes a tool call from the AI.
     * Extends parent to handle open_file_in_editor tool.
     *
     * @param string $name The tool name
     * @param array $input The tool input parameters
     * @return string The result as a string (typically JSON-encoded)
     */
    protected function executeTool(string $name, array $input): string {
        if ($name === 'open_file_in_editor') {
            $path = $input['path'] ?? '';
            if (empty($path)) {
                return json_encode(['ok' => false, 'error' => 'Path required']);
            }

            // Validate the file exists
            try {
                // This will throw if file doesn't exist or isn't readable
                $content = $this->fm->readFile($path);
                return json_encode([
                    'ok' => true,
                    'path' => $path,
                    'size' => strlen($content),
                ]);
            } catch (\Throwable $e) {
                return json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
        }

        return parent::executeTool($name, $input);
    }
}
