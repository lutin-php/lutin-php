<?php
declare(strict_types=1);

/**
 * Interface for AI provider adapters.
 * Each adapter must implement the stream() method to communicate with a specific AI provider.
 */
interface LutinProviderAdapter {
    /**
     * Sends a request to the AI API.
     * Returns a generator that yields SSE-formatted strings.
     * Each yielded string is either:
     *   - A text delta:   "data: " . json_encode(['type'=>'text','delta'=>'...']) . "\n\n"
     *   - A tool call:    "data: " . json_encode(['type'=>'tool_call','name'=>'...','input'=>[...],'id'=>'...']) . "\n\n"
     *   - A stop signal:  "data: " . json_encode(['type'=>'stop','stop_reason'=>'...']) . "\n\n"
     * 
     * @param array $messages The conversation history
     * @param array $tools Tool definitions for the provider
     * @param string $systemPrompt The system prompt to use
     * @return \Generator Yields SSE-formatted strings
     */
    public function stream(array $messages, array $tools, string $systemPrompt = ''): \Generator;
}
