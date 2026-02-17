<?php
declare(strict_types=1);

/**
 * Chat Agent for Lutin.
 * Handles conversational AI interactions with file management capabilities.
 * Extends AbstractLutinAgent with specific tool definitions for file operations.
 */
class LutinChatAgent extends AbstractLutinAgent {

    /** Cached system prompt (base + AGENTS.md if present) */
    private ?string $systemPrompt = null;

    /**
     * Base system prompt for the chat agent.
     */
    protected function getBaseSystemPrompt(): string {
        return 'You are Lutin, an AI assistant integrated into a PHP website editor. ' .
            'You can read files, list directories, and write files anywhere in the project (relative to project root). ' .
            'The web root (public files) is typically in a subdirectory like "public/" or "www/". ' .
            'Always prefer making minimal, targeted changes. Never modify lutin.php or access the lutin/ directory. ' .
            'When asked to create or modify a page, read the existing files first to understand the structure.';
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

        $basePrompt = $this->addFileContentToPrompt($basePrompt, $agentsMdPath, 'AGENTS.md');

        $this->systemPrompt = $basePrompt;
        return $basePrompt;
    }

    /**
     * Returns the tool schema array for file operations.
     * 
     * @return array Provider-agnostic tool definitions
     */
    protected function buildToolDefinitions(): array {
        return $this->selectTools(['list_files', 'read_file', 'write_file']);
    }
}
