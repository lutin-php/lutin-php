<?php
declare(strict_types=1);

class LutinRouter {
    private LutinConfig $config;
    private LutinAuth $auth;
    private LutinFileManager $fm;
    private ?LutinAgent $agent;
    private LutinView $view;

    public function __construct(
        LutinConfig $config,
        LutinAuth $auth,
        LutinFileManager $fm,
        ?LutinAgent $agent,
        LutinView $view
    ) {
        $this->config = $config;
        $this->auth = $auth;
        $this->fm = $fm;
        $this->agent = $agent;
        $this->view = $view;
    }

    /**
     * Lazily initialize the agent when needed.
     */
    private function getAgent(): LutinAgent {
        if ($this->agent === null) {
            $this->agent = new LutinAgent($this->config, $this->fm);
        }
        return $this->agent;
    }

    /**
     * Main dispatch entry point. Called from index.php.
     */
    public function dispatch(): void {
        $action = $_GET['action'] ?? null;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            // Route dispatch
            if ($method === 'GET' && $action === null) {
                $this->renderPage();
            } elseif ($method === 'POST' && $action === 'setup') {
                $this->handleSetup();
            } elseif ($method === 'POST' && $action === 'login') {
                $this->handleLogin();
            } elseif ($method === 'POST' && $action === 'logout') {
                $this->requireAuth();
                $this->requireCsrf();
                $this->handleLogout();
            } elseif ($method === 'POST' && $action === 'chat') {
                $this->requireAuth();
                $this->requireCsrf();
                $this->handleChat();
            } elseif ($method === 'GET' && $action === 'list') {
                $this->requireAuth();
                $this->handleList();
            } elseif ($method === 'GET' && $action === 'read') {
                $this->requireAuth();
                $this->handleRead();
            } elseif ($method === 'POST' && $action === 'write') {
                $this->requireAuth();
                $this->requireCsrf();
                $this->handleWrite();
            } elseif ($method === 'GET' && $action === 'backups') {
                $this->requireAuth();
                $this->handleBackups();
            } elseif ($method === 'POST' && $action === 'restore') {
                $this->requireAuth();
                $this->requireCsrf();
                $this->handleRestore();
            } elseif ($method === 'GET' && $action === 'url_map') {
                $this->requireAuth();
                $this->handleUrlMap();
            } elseif ($method === 'POST' && $action === 'config') {
                $this->requireAuth();
                $this->requireCsrf();
                $this->handleConfigSave();
            } else {
                $this->jsonError('Unknown action', 404);
            }
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    // ── Action handlers ───────────────────────────────────────────────────────

    private function renderPage(): void {
        if ($this->config->isFirstRun()) {
            $this->view->renderSetupWizard();
        } elseif (!$this->auth->isAuthenticated()) {
            $this->view->renderLogin();
        } else {
            $this->view->renderApp();
        }
    }

    private function handleSetup(): void {
        if (!$this->config->isFirstRun()) {
            $this->jsonError('Already configured', 403);
            return;
        }

        $body = $this->getBody();

        // Validate passwords match
        if (($body['password'] ?? '') !== ($body['confirm'] ?? '')) {
            $this->jsonError('Passwords do not match', 400);
            return;
        }

        if (empty($body['password'])) {
            $this->jsonError('Password required', 400);
            return;
        }

        // Set data directory if provided
        if (!empty($body['data_dir'])) {
            $this->config->setDataDir($body['data_dir']);
        }

        // Save configuration
        $this->config->setPasswordHash(password_hash($body['password'], PASSWORD_BCRYPT));
        $this->config->setProvider($body['provider'] ?? 'anthropic');
        $this->config->setApiKey($body['api_key'] ?? '');
        $this->config->setModel($body['model'] ?? 'claude-3-5-haiku-20241022');
        if (!empty($body['site_url'])) {
            $this->config->setSiteUrl($body['site_url']);
        }

        $this->config->save();

        // Log in the user
        $this->auth->login($body['password']);

        $this->jsonOk(['redirect' => '?']);
    }

    private function handleLogin(): void {
        if ($this->auth->isAuthenticated()) {
            $this->jsonOk([]);
            return;
        }

        $body = $this->getBody();
        $password = $body['password'] ?? '';

        if ($this->auth->login($password)) {
            $this->jsonOk([]);
        } else {
            $this->jsonError('Invalid password', 401);
        }
    }

    private function handleLogout(): void {
        $this->auth->logout();
        $this->jsonOk([]);
    }

    private function handleChat(): void {
        $body = $this->getBody();
        $message = $body['message'] ?? '';
        $history = $body['history'] ?? [];

        if (empty($message)) {
            $this->jsonError('Message required', 400);
            return;
        }

        $this->getAgent()->chat($message, $history);
    }

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

    private function handleUrlMap(): void {
        $url = $_GET['url'] ?? '';

        try {
            $candidates = $this->fm->urlToFile($url);
            $this->jsonOk($candidates);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function jsonOk(mixed $data): void {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'data' => $data]);
        exit(0);
    }

    private function jsonError(string $message, int $httpCode = 400): void {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message]);
        exit(0);
    }

    private function requireAuth(): void {
        if (!$this->auth->isAuthenticated()) {
            $this->jsonError('Unauthorized', 401);
        }
    }

    private function requireCsrf(): void {
        $token = $_SERVER['HTTP_X_LUTIN_TOKEN'] ?? '';
        try {
            $this->auth->assertCsrfToken($token);
        } catch (\Throwable) {
            $this->jsonError('CSRF token invalid', 403);
        }
    }

    private function getBody(): array {
        $json = file_get_contents('php://input');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
