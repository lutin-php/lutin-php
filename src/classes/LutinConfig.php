<?php
declare(strict_types=1);

class LutinConfig {
    // Internal state
    private array $data = [];
    private string $webRootDir;   // absolute path to the web root (directory containing lutin.php)
    private string $dataDir;      // absolute path to the data directory (outside web root)

    public function __construct(string $webRootDir, ?string $dataDir = null) {
        $this->webRootDir = rtrim($webRootDir, '/');
        // Default data directory is ../lutin (outside web root)
        $this->dataDir = $dataDir ? rtrim($dataDir, '/') : dirname($this->webRootDir) . '/lutin';
    }

    /**
     * Returns the web root directory (where lutin.php lives).
     */
    public function getWebRoot(): string {
        return $this->webRootDir;
    }

    /**
     * Returns the data directory (outside web root).
     */
    public function getDataDir(): string {
        return $this->dataDir;
    }

    /**
     * Returns the path to the config file (in data directory).
     */
    private function getConfigPath(): string {
        return $this->dataDir . '/config.json';
    }

    /**
     * Returns the path to the backup directory (in data directory).
     */
    public function getBackupDir(): string {
        return $this->dataDir . '/backups';
    }

    /**
     * Returns the path to the temp directory (in data directory).
     */
    public function getTempDir(): string {
        return $this->dataDir . '/temp';
    }

    /**
     * Reads config.json from the data directory into $this->data.
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
        // Update dataDir from loaded config if present
        if (!empty($this->data['data_dir'])) {
            $this->dataDir = $this->data['data_dir'];
        }
        return true;
    }

    /**
     * Writes $this->data back to config.json in the data directory.
     * Creates data directory if needed.
     */
    public function save(): void {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0700, true);
            // Write .htaccess to protect the directory (if inside web root or not)
            file_put_contents($this->dataDir . '/.htaccess', "Deny from all\n");
        }

        // Store data_dir in config for persistence
        $this->data['data_dir'] = $this->dataDir;

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

    public function setDataDir(string $dataDir): void {
        $this->dataDir = rtrim($dataDir, '/');
        $this->data['data_dir'] = $this->dataDir;
    }

    // Raw access for the config tab UI
    public function toArray(): array {
        return $this->data;
    }

    public function fromArray(array $data): void {
        $this->data = $data;
        if (!empty($data['data_dir'])) {
            $this->dataDir = $data['data_dir'];
        }
    }
}
