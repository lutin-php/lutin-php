<?php
declare(strict_types=1);

/**
 * Editor Page Controller.
 * Handles all editor-related actions: file operations and AI chat.
 */
class LutinEditorPage extends AbstractLutinPage {

    private ?LutinEditorAgent $agent = null;

    /**
     * Handle editor-specific actions.
     * These are the standard file operations used by the editor tab.
     */
    public function handle(string $action, string $method): void {
        $this->requireAuth();

        switch ($action) {
            // File operations (used by editor file explorer)
            case 'list':
                if ($method === 'GET') {
                    $this->handleList();
                    return;
                }
                break;

            case 'read':
                if ($method === 'GET') {
                    $this->handleRead();
                    return;
                }
                break;

            case 'write':
                if ($method === 'POST') {
                    $this->requireCsrf();
                    $this->handleWrite();
                    return;
                }
                break;

            case 'search':
                if ($method === 'GET') {
                    $this->handleSearch();
                    return;
                }
                break;

            // AI Chat (editor-specific)
            case 'editor_chat':
                if ($method === 'POST') {
                    $this->requireCsrf();
                    $this->handleChat();
                    return;
                }
                break;
        }

        $this->jsonError('Unknown editor action', 404);
    }

    // ── File Operations ────────────────────────────────────────────────────────

    private function handleList(): void {
        $path = $_GET['path'] ?? '';

        try {
            $files = $this->fm->listFiles($path);
            $this->jsonOk($files);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    private function handleRead(): void {
        $path = $_GET['path'] ?? '';

        try {
            $content = $this->fm->readFile($path);
            $this->jsonOk(['path' => $path, 'content' => $content]);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    private function handleWrite(): void {
        $body = $this->getBody();
        $path = $body['path'] ?? '';
        $content = $body['content'] ?? '';

        try {
            $this->fm->writeFile($path, $content);
            $this->jsonOk(['ok' => true]);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    private function handleSearch(): void {
        $query = $_GET['q'] ?? '';
        $strict = ($_GET['strict'] ?? 'false') === 'true';
        $filesOnly = ($_GET['files_only'] ?? 'true') === 'true';
        $limit = min((int)($_GET['limit'] ?? 20), 100);

        if (empty($query)) {
            $this->jsonOk([]);
            return;
        }

        try {
            $options = [
                'recursive' => true,
                'search_pattern' => $query,
                'strict_mode' => $strict,
                'file_only' => $filesOnly,
            ];
            $files = $this->fm->listFiles('', $options);
            
            if (count($files) > $limit) {
                $files = array_slice($files, 0, $limit);
            }
            
            $this->jsonOk($files);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    // ── AI Chat ────────────────────────────────────────────────────────────────

    private function handleChat(): void {
        $body = $this->getBody();
        $message = $body['message'] ?? '';
        $history = $body['history'] ?? [];
        $currentFile = $body['current_file'] ?? null;
        $currentContent = $body['current_content'] ?? null;

        if (empty($message)) {
            $this->jsonError('Message required', 400);
            return;
        }

        $agent = $this->getAgent();
        $agent->setCurrentFile($currentFile, $currentContent);
        $agent->chat($message, $history);
        // Note: chat() exits after SSE stream completes
    }

    /**
     * Lazily initialize the editor agent when needed.
     */
    private function getAgent(): LutinEditorAgent {
        if ($this->agent === null) {
            $this->agent = new LutinEditorAgent($this->config, $this->fm);
        }
        return $this->agent;
    }
}
