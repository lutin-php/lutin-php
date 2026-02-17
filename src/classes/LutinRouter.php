<?php
declare(strict_types=1);

/**
 * Main Router for Lutin.
 * Routes actions to the appropriate Page class based on the 'tab' parameter.
 */
class LutinRouter {
    private LutinConfig $config;
    private LutinAuth $auth;
    private LutinFileManager $fm;
    private LutinView $view;

    /** Map tabs to their page controller classes */
    private const TAB_PAGES = [
        'chat'   => LutinChatPage::class,
        'editor' => LutinEditorPage::class,
        'config' => LutinConfigPage::class,
    ];

    public function __construct(
        LutinConfig $config,
        LutinAuth $auth,
        LutinFileManager $fm,
        LutinView $view
    ) {
        $this->config = $config;
        $this->auth = $auth;
        $this->fm = $fm;
        $this->view = $view;
    }

    /**
     * Main dispatch entry point. Called from index.php.
     */
    public function dispatch(): void {
        $action = $_GET['action'] ?? null;
        $tab = $_GET['tab'] ?? null;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            // Route to page based on tab parameter (if valid)
            if ($tab !== null && isset(self::TAB_PAGES[$tab])) {
                $pageClass = self::TAB_PAGES[$tab];
                $page = new $pageClass($this->config, $this->auth, $this->fm);
                $page->handle($action ?? '', $method);
                return;
            }

            // Fall back to global handlers for non-tab actions (setup, login, etc.)
            $this->dispatchGlobal($action, $method);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Dispatch global actions not handled by page controllers.
     */
    private function dispatchGlobal(?string $action, string $method): void {
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
        } elseif ($method === 'GET' && $action === 'url_map') {
            $this->requireAuth();
            $this->handleUrlMap();
        } elseif ($method === 'GET' && $action === 'templates') {
            $this->requireAuth();
            $this->handleTemplatesList();
        } elseif ($method === 'POST' && $action === 'install_template') {
            $this->requireAuth();
            $this->requireCsrf();
            $this->handleInstallTemplate();
        } else {
            $this->jsonError('Unknown action', 404);
        }
    }

    // ── Action handlers ───────────────────────────────────────────────────────

    private function renderPage(): void {
        if ($this->config->isFirstRun()) {
            $this->view->renderSetupWizard();
        } elseif (!$this->auth->isAuthenticated()) {
            $this->view->renderLogin();
        } elseif ($this->config->needsTemplateSelection()) {
            $this->view->renderTemplateSelection();
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

        // Set project root directory if provided
        if (!empty($body['project_root'])) {
            $this->config->setProjectRoot($body['project_root']);
        }

        // Set web root directory if provided
        if (!empty($body['web_root'])) {
            $this->config->setWebRoot($body['web_root']);
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

    private function handleUrlMap(): void {
        $url = $_GET['url'] ?? '';

        try {
            $candidates = $this->fm->urlToFile($url);
            $this->jsonOk($candidates);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    private function handleTemplatesList(): void {
        $manifestUrl = 'https://raw.githubusercontent.com/lutin-php/lutin-starters/main/starters.json';
        $cacheDir = $this->config->getTempDir();
        $cacheFile = $cacheDir . '/starters.json';
        $maxAge = 86400; // 1 day in seconds

        // Ensure cache directory exists
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0700, true);
        }

        // Check if we need to fetch fresh data
        $needsFetch = true;
        $cachedData = null;
        
        if (file_exists($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            $cachedData = file_get_contents($cacheFile);
            if ($age < $maxAge && $cachedData !== false) {
                $needsFetch = false;
            }
        }

        $response = null;
        $fetchError = null;
        $httpCode = 0;
        
        if ($needsFetch) {
            $ch = curl_init($manifestUrl);
            if ($ch === false) {
                $fetchError = 'Failed to initialize curl';
            } else {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode === 200 && $response !== false) {
                    // Cache the successful response
                    file_put_contents($cacheFile, $response);
                } else {
                    $fetchError = 'HTTP ' . $httpCode;
                    if ($curlError) {
                        $fetchError .= ' (' . $curlError . ')';
                    }
                    // Fall back to cached data if available (even if old)
                    if ($cachedData === null && file_exists($cacheFile)) {
                        $cachedData = file_get_contents($cacheFile);
                    }
                }
            }
        }

        // Use cached data if fetch failed or wasn't needed
        if ($response === null || $response === false) {
            $response = $cachedData;
        }

        if ($response === null || $response === false || empty($response)) {
            $this->jsonOk([
                'templates' => [], 
                'error' => 'Failed to fetch templates' . ($fetchError ? ': ' . $fetchError : ''),
                'http_code' => $httpCode,
                'cache_file' => $cacheFile,
                'cache_exists' => file_exists($cacheFile)
            ]);
            return;
        }

        try {
            $manifest = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            $templates = $manifest['starters'] ?? [];
            $this->jsonOk([
                'templates' => $templates,
                'cached' => !$needsFetch,
                'fetch_error' => $fetchError,
                'http_code' => $httpCode,
                'cache_file' => $cacheFile
            ]);
        } catch (\JsonException $e) {
            // Save bad response for debugging
            file_put_contents($cacheFile . '.error', $response);
            $this->jsonOk([
                'templates' => [], 
                'error' => 'Invalid template data: ' . $e->getMessage(),
                'response_preview' => substr($response, 0, 200)
            ]);
        }
    }

    private function handleInstallTemplate(): void {
        $body = $this->getBody();
        $templateId = $body['template_id'] ?? null;

        // Empty project (no template)
        if ($templateId === null || $templateId === '') {
            $this->config->setTemplateSelected(null);
            $this->config->save();
            $this->jsonOk(['installed' => true, 'template_id' => null]);
            return;
        }

        // Install a specific template
        $zipUrl = $body['zip_url'] ?? null;
        $hash = $body['hash'] ?? null;

        if (empty($zipUrl)) {
            $this->jsonError('Template download URL required', 400);
            return;
        }

        try {
            $this->fm->installTemplate($zipUrl, $hash);
            $this->config->setTemplateSelected($templateId);
            $this->config->save();
            $this->jsonOk(['installed' => true, 'template_id' => $templateId]);
        } catch (\Throwable $e) {
            $this->jsonError('Template installation failed: ' . $e->getMessage(), 500);
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
