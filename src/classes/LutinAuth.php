<?php
declare(strict_types=1);

class LutinAuth {
    private const SESSION_KEY  = 'lutin_authenticated';
    private const TOKEN_KEY    = 'lutin_csrf_token';

    private LutinConfig $config;

    public function __construct(LutinConfig $config) {
        $this->config = $config;
    }

    /**
     * Starts session if not already started.
     */
    public function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Returns true if the session is authenticated.
     */
    public function isAuthenticated(): bool {
        return isset($_SESSION[self::SESSION_KEY]) && $_SESSION[self::SESSION_KEY] === true;
    }

    /**
     * Verifies $password against config hash. Returns true on success.
     * On success, sets session flag and regenerates session ID.
     */
    public function login(string $password): bool {
        $hash = $this->config->getPasswordHash();
        if ($hash === null) {
            return false;
        }
        if (!password_verify($password, $hash)) {
            return false;
        }
        $_SESSION[self::SESSION_KEY] = true;
        session_regenerate_id(true);
        return true;
    }

    /**
     * Destroys session.
     */
    public function logout(): void {
        session_destroy();
    }

    /**
     * Returns (or lazily creates) the CSRF token for this session.
     */
    public function getCsrfToken(): string {
        if (!isset($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Validates a token from request headers/body. Throws on failure.
     */
    public function assertCsrfToken(string $token): void {
        $expected = $this->getCsrfToken();
        if (!hash_equals($expected, $token)) {
            throw new \RuntimeException('CSRF token validation failed');
        }
    }
}
