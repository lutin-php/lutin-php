<?php
declare(strict_types=1);

/**
 * Base class for all Lutin page controllers.
 * Provides common infrastructure for handling page-specific actions and rendering.
 */
abstract class AbstractLutinPage {
    protected LutinConfig $config;
    protected LutinAuth $auth;
    protected LutinFileManager $fm;

    public function __construct(LutinConfig $config, LutinAuth $auth, LutinFileManager $fm) {
        $this->config = $config;
        $this->auth = $auth;
        $this->fm = $fm;
    }

    /**
     * Handle an action request.
     * Subclasses should implement this to route actions to specific handlers.
     *
     * @param string $action The action name
     * @param string $method HTTP method (GET, POST, etc.)
     */
    abstract public function handle(string $action, string $method): void;

    /**
     * Helper: Send JSON success response
     */
    protected function jsonOk(mixed $data): void {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'data' => $data]);
        exit(0);
    }

    /**
     * Helper: Send JSON error response
     */
    protected function jsonError(string $message, int $httpCode = 400): void {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message]);
        exit(0);
    }

    /**
     * Helper: Get request body as array
     */
    protected function getBody(): array {
        $json = file_get_contents('php://input');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    /**
     * Helper: Require authentication
     */
    protected function requireAuth(): void {
        if (!$this->auth->isAuthenticated()) {
            $this->jsonError('Unauthorized', 401);
        }
    }

    /**
     * Helper: Verify CSRF token from header
     */
    protected function requireCsrf(): void {
        $token = $_SERVER['HTTP_X_LUTIN_TOKEN'] ?? '';
        try {
            $this->auth->assertCsrfToken($token);
        } catch (\Throwable) {
            $this->jsonError('CSRF token invalid', 403);
        }
    }
}
