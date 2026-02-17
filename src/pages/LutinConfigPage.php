<?php
declare(strict_types=1);

/**
 * Config Page Controller.
 * Handles configuration-related actions: settings, backups, and restore.
 */
class LutinConfigPage extends AbstractLutinPage {

    /**
     * Handle config-specific actions.
     */
    public function handle(string $action, string $method): void {
        $this->requireAuth();

        switch ($action) {
            case 'config':
                if ($method === 'POST') {
                    $this->requireCsrf();
                    $this->handleConfigSave();
                    return;
                }
                break;

            case 'backups':
                if ($method === 'GET') {
                    $this->handleBackups();
                    return;
                }
                break;

            case 'restore':
                if ($method === 'POST') {
                    $this->requireCsrf();
                    $this->handleRestore();
                    return;
                }
                break;
        }

        $this->jsonError('Unknown config action', 404);
    }

    // ── Configuration ──────────────────────────────────────────────────────────

    private function handleConfigSave(): void {
        $body = $this->getBody();

        try {
            if (!empty($body['provider'])) {
                $this->config->setProvider($body['provider']);
            }
            if (!empty($body['api_key'])) {
                $this->config->setApiKey($body['api_key']);
            }
            if (!empty($body['model'])) {
                $this->config->setModel($body['model']);
            }
            if (!empty($body['site_url'])) {
                $this->config->setSiteUrl($body['site_url']);
            }
            $this->config->save();
            $this->jsonOk([]);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    // ── Backups ────────────────────────────────────────────────────────────────

    private function handleBackups(): void {
        try {
            $backups = $this->fm->listBackups();
            $this->jsonOk($backups);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    private function handleRestore(): void {
        $body = $this->getBody();
        $backupPath = $body['path'] ?? '';

        try {
            $restoredPath = $this->fm->restore($backupPath);
            $this->jsonOk(['restored_to' => $restoredPath]);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }
}
