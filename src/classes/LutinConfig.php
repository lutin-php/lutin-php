<?php
declare(strict_types=1);

class LutinConfig {
    // Internal state
    private array $data = [];
    private string $projectRootDir;   // absolute path to the project root (configurable)
    private string $webRootDir;       // absolute path to the web root (where lutin.php lives)
    private string $lutinDir;         // absolute path to the lutin directory (auto-derived as projectRoot/lutin)

    public function __construct(?string $projectRootDir = null, ?string $webRootDir = null, ?string $lutinDir = null) {
        // Default project root is parent of current directory (src/)
        $this->projectRootDir = $projectRootDir ? rtrim($projectRootDir, '/') : dirname(__DIR__);
        
        // Default web root is the directory where lutin.php lives (src/ in dev)
        $this->webRootDir = $webRootDir ? rtrim($webRootDir, '/') : __DIR__;
        
        // Lutin dir is auto-derived inside project root, or can be overridden for backwards compatibility
        $this->lutinDir = $lutinDir ? rtrim($lutinDir, '/') : $this->projectRootDir . '/lutin';
    }

    /**
     * Returns the project root directory.
     */
    public function getProjectRoot(): string {
        return $this->projectRootDir;
    }

    /**
     * Returns the web root directory (where lutin.php lives).
     * This is typically a subdirectory of project root (e.g., public/, www/).
     */
    public function getWebRoot(): string {
        return $this->webRootDir;
    }

    /**
     * Returns the lutin directory (stores config, backups, temp).
     */
    public function getLutinDir(): string {
        return $this->lutinDir;
    }

    /**
     * Returns the path to the config file (in lutin directory).
     */
    private function getConfigPath(): string {
        return $this->lutinDir . '/config.json';
    }

    /**
     * Returns the path to the backup directory (in lutin directory).
     */
    public function getBackupDir(): string {
        return $this->lutinDir . '/backups';
    }

    /**
     * Returns the path to the temp directory (in lutin directory).
     */
    public function getTempDir(): string {
        return $this->lutinDir . '/temp';
    }

    /**
     * Reads config.json from the lutin directory into $this->data.
     * Returns false if file missing.
     */
    public function load(): bool {
        $path = $this->getConfigPath();
        if (!file_exists($path)) {
            return false;
        }
        $content = file_get_contents($path);
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $this->data = is_array($decoded) ? $decoded : [];
        // Update directories from loaded config if present
        if (!empty($this->data['project_root'])) {
            $this->projectRootDir = $this->data['project_root'];
        }
        if (!empty($this->data['web_root'])) {
            $this->webRootDir = $this->data['web_root'];
        }
        if (!empty($this->data['lutin_dir'])) {
            $this->lutinDir = $this->data['lutin_dir'];
        }
        return true;
    }

    /**
     * Writes $this->data back to config.json in the lutin directory.
     * Creates lutin directory if needed.
     */
    public function save(): void {
        if (!is_dir($this->lutinDir)) {
            mkdir($this->lutinDir, 0700, true);
            // Write .htaccess to protect the directory
            file_put_contents($this->lutinDir . '/.htaccess', "Deny from all\n");
        }

        // Store directories in config for persistence
        $this->data['project_root'] = $this->projectRootDir;
        $this->data['web_root'] = $this->webRootDir;
        $this->data['lutin_dir'] = $this->lutinDir;

        $path = $this->getConfigPath();
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json);
    }

    /**
     * Returns true when config is missing or lacks 'password_hash' / 'provider' keys.
     */
    public function isFirstRun(): bool {
        return empty($this->data) ||
               !isset($this->data['password_hash']) ||
               !isset($this->data['provider']);
    }

    /**
     * Returns true when template has not been selected yet.
     * This is true after setup until user chooses a template or "empty project".
     */
    public function needsTemplateSelection(): bool {
        // If first run, we haven't even done setup yet
        if ($this->isFirstRun()) {
            return false;
        }
        // Check if template selection has been made
        return !isset($this->data['template_selected']);
    }

    /**
     * Mark template selection as complete.
     */
    public function setTemplateSelected(?string $templateId = null): void {
        $this->data['template_selected'] = true;
        $this->data['template_id'] = $templateId ?: 'empty';
    }

    /**
     * Get the selected template ID.
     */
    public function getTemplateId(): ?string {
        return $this->data['template_id'] ?? null;
    }

    // Typed getters (return null if key absent)
    public function getPasswordHash(): ?string {
        return $this->data['password_hash'] ?? null;
    }

    public function getProvider(): ?string {
        return $this->data['provider'] ?? null;
    }

    public function getApiKey(): ?string {
        return $this->data['api_key'] ?? null;
    }

    public function getModel(): ?string {
        return $this->data['model'] ?? null;
    }

    public function getSiteUrl(): ?string {
        return $this->data['site_url'] ?? null;
    }

    // Setters (call save() separately)
    public function setPasswordHash(string $hash): void {
        $this->data['password_hash'] = $hash;
    }

    public function setProvider(string $provider): void {
        $this->data['provider'] = $provider;
    }

    public function setApiKey(string $key): void {
        $this->data['api_key'] = $key;
    }

    public function setModel(string $model): void {
        $this->data['model'] = $model;
    }

    public function setSiteUrl(string $url): void {
        $this->data['site_url'] = $url;
    }

    public function setProjectRoot(string $projectRoot): void {
        $this->projectRootDir = rtrim($projectRoot, '/');
        $this->data['project_root'] = $this->projectRootDir;
        // Auto-update lutin dir if it was at the default location
        if (!isset($this->data['lutin_dir']) || $this->lutinDir === $this->projectRootDir . '/lutin') {
            $this->lutinDir = $this->projectRootDir . '/lutin';
            $this->data['lutin_dir'] = $this->lutinDir;
        }
    }

    public function setWebRoot(string $webRoot): void {
        $this->webRootDir = rtrim($webRoot, '/');
        $this->data['web_root'] = $this->webRootDir;
    }

    public function setLutinDir(string $lutinDir): void {
        $this->lutinDir = rtrim($lutinDir, '/');
        $this->data['lutin_dir'] = $this->lutinDir;
    }

    // Raw access for the config tab UI
    public function toArray(): array {
        return $this->data;
    }

    public function fromArray(array $data): void {
        $this->data = $data;
        if (!empty($data['project_root'])) {
            $this->projectRootDir = $data['project_root'];
        }
        if (!empty($data['web_root'])) {
            $this->webRootDir = $data['web_root'];
        }
        if (!empty($data['lutin_dir'])) {
            $this->lutinDir = $data['lutin_dir'];
        }
    }
}
