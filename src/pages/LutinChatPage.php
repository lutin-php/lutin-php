<?php
declare(strict_types=1);

/**
 * Chat Page Controller.
 * Handles chat-related actions and AI interactions for the chat tab.
 */
class LutinChatPage extends AbstractLutinPage {

    private ?LutinChatAgent $agent = null;

    /**
     * Handle chat-specific actions.
     */
    public function handle(string $action, string $method): void {
        $this->requireAuth();

        switch ($action) {
            case 'chat':
                if ($method === 'POST') {
                    $this->requireCsrf();
                    $this->handleChat();
                    return;
                }
                break;
        }

        $this->jsonError('Unknown chat action', 404);
    }

    // ── AI Chat ────────────────────────────────────────────────────────────────

    private function handleChat(): void {
        $body = $this->getBody();
        $message = $body['message'] ?? '';
        $history = $body['history'] ?? [];

        if (empty($message)) {
            $this->jsonError('Message required', 400);
            return;
        }

        $this->getAgent()->chat($message, $history);
        // Note: chat() exits after SSE stream completes
    }

    /**
     * Lazily initialize the chat agent when needed.
     */
    private function getAgent(): LutinChatAgent {
        if ($this->agent === null) {
            $this->agent = new LutinChatAgent($this->config, $this->fm);
        }
        return $this->agent;
    }
}
