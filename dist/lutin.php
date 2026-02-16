<?php
declare(strict_types=1);
// Lutin.php v1.0.0
// Built: 2026-02-16 22:53:18

// ── LutinConfig.php ─────
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

// ── LutinAuth.php ─────
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

// ── LutinFileManager.php ─────
class LutinFileManager {
    // Paths that can NEVER be written to, even if they resolve inside webRoot
    private const PROTECTED_PATHS = [
        'lutin.php',
    ];

    private string $webRootDir;    // absolute path to the web root (where website files live)
    private string $dataDir;       // absolute path to the data directory (backups, config)
    private LutinConfig $config;

    public function __construct(string $webRootDir, string $dataDir, LutinConfig $config) {
        $this->webRootDir = rtrim($webRootDir, '/');
        $this->dataDir = rtrim($dataDir, '/');
        $this->config = $config;
    }

    /**
     * Returns the web root directory (where website files are stored).
     */
    public function getWebRoot(): string {
        return $this->webRootDir;
    }

    /**
     * Resolves $path relative to $webRootDir.
     * Throws \RuntimeException if the resolved path escapes $webRootDir
     * or matches a protected path.
     * Does NOT require the file to exist (for write use-cases).
     * Returns the absolute path.
     */
    public function safePath(string $path): string {
        // Normalize the path to remove . and ..
        $absolute = realpath($this->webRootDir . '/' . $path);

        // If realpath fails (path doesn't exist), manually construct and validate
        if ($absolute === false) {
            $parts = array_filter(explode('/', trim($path, '/')), fn($p) => $p !== '' && $p !== '.');
            $absolute = $this->webRootDir;
            foreach ($parts as $part) {
                if ($part === '..') {
                    throw new \RuntimeException('Path escape attempt');
                }
                $absolute .= '/' . $part;
            }
        }

        // Check if the path escapes webRoot
        $realRoot = realpath($this->webRootDir);
        if ($realRoot === false) {
            throw new \RuntimeException('Root directory does not exist');
        }
        if (!str_starts_with($absolute, $realRoot . '/') && $absolute !== $realRoot) {
            throw new \RuntimeException('Path escape attempt');
        }

        // Check for protected paths (relative to webRoot)
        $relative = substr($absolute, strlen($realRoot) + 1);
        if ($this->isProtected($relative)) {
            throw new \RuntimeException('Protected path');
        }

        return $absolute;
    }

    /**
     * Returns true if $relativePath is a protected Lutin-internal path.
     */
    private function isProtected(string $relativePath): bool {
        // Check exact protected paths
        foreach (self::PROTECTED_PATHS as $protected) {
            if ($relativePath === $protected) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lists $path directory contents (relative to webRoot).
     * Returns array of ['name' => string, 'type' => 'file'|'dir', 'path' => string (relative)]
     * Skips lutin.php.
     * $path defaults to '' (root).
     */
    public function listFiles(string $path = ''): array {
        $dirPath = $path === '' ? $this->webRootDir : $this->safePath($path);

        if (!is_dir($dirPath)) {
            throw new \RuntimeException('Not a directory');
        }

        $entries = [];
        $items = scandir($dirPath);
        if ($items === false) {
            throw new \RuntimeException('Cannot read directory');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            // Skip lutin.php
            if ($item === 'lutin.php') {
                continue;
            }

            $fullPath = $dirPath . '/' . $item;
            $relPath = $path === '' ? $item : $path . '/' . $item;
            $isDir = is_dir($fullPath);

            $entries[] = [
                'name' => $item,
                'type' => $isDir ? 'dir' : 'file',
                'path' => $relPath,
            ];
        }

        return $entries;
    }

    /**
     * Reads and returns the content of $path (relative to webRoot).
     * Goes through safePath().
     * Throws on protected path or read error.
     */
    public function readFile(string $path): string {
        $fullPath = $this->safePath($path);
        if (!is_file($fullPath)) {
            throw new \RuntimeException('File not found');
        }
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException('Cannot read file');
        }
        return $content;
    }

    /**
     * Writes $content to $path (relative to webRoot).
     * 1. Calls safePath() — throws on violation.
     * 2. If file exists, creates backup first via createBackup().
     * 3. Creates parent directories if needed (mkdir recursive).
     * 4. Writes file.
     */
    public function writeFile(string $path, string $content): void {
        $fullPath = $this->safePath($path);

        // Create backup if file exists
        if (file_exists($fullPath)) {
            $this->createBackup($fullPath);
        }

        // Create parent directories
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Write file
        if (file_put_contents($fullPath, $content) === false) {
            throw new \RuntimeException('Cannot write file');
        }
    }

    /**
     * Copies the current version of $absoluteFilePath into the backup directory.
     * Backup filename: YYYY-MM-DD_HH-II-SS_<basename>
     * Returns the absolute path of the backup file created.
     */
    public function createBackup(string $absoluteFilePath): string {
        $backupDir = $this->dataDir . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0700, true);
        }

        $basename = basename($absoluteFilePath);
        $timestamp = date('Y-m-d_H-i-s');
        $backupFilename = $timestamp . '_' . $basename;
        $backupPath = $backupDir . '/' . $backupFilename;

        if (!copy($absoluteFilePath, $backupPath)) {
            throw new \RuntimeException('Cannot create backup');
        }

        return $backupPath;
    }

    /**
     * Lists all backup files sorted newest-first.
     * Returns array of [
     *   'backup_path'   => string (absolute),
     *   'original_name' => string (basename without timestamp prefix),
     *   'timestamp'     => string (human-readable),
     *   'size'          => int,
     * ]
     */
    public function listBackups(): array {
        $backupDir = $this->dataDir . '/backups';
        if (!is_dir($backupDir)) {
            return [];
        }

        $backups = [];
        $files = scandir($backupDir, SCANDIR_SORT_DESCENDING);
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fullPath = $backupDir . '/' . $file;
            if (!is_file($fullPath)) {
                continue;
            }

            // Parse timestamp from filename: YYYY-MM-DD_HH-II-SS_<original>
            if (preg_match('/^(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})_(.+)$/', $file, $m)) {
                $timestampStr = $m[1];
                $originalName = $m[2];
                // Convert timestamp format for display
                $readable = str_replace('_', ' ', str_replace('-', '-', $timestampStr));
                $readable = preg_replace('/(\d{4})-(\d{2})-(\d{2}) (\d{2})-(\d{2})-(\d{2})/',
                    '$1-$2-$3 $4:$5:$6', $readable);

                $backups[] = [
                    'backup_path'   => $fullPath,
                    'original_name' => $originalName,
                    'timestamp'     => $readable,
                    'size'          => filesize($fullPath),
                ];
            }
        }

        return $backups;
    }

    /**
     * Restores $backupAbsolutePath to its original file location (relative to webRoot).
     * 1. Derives original path from backup filename (strip timestamp prefix).
     * 2. Creates a new backup of the CURRENT live file before overwriting.
     * 3. Writes backup content to original path.
     * Returns the absolute path of the restored file.
     */
    public function restore(string $backupAbsolutePath): string {
        if (!file_exists($backupAbsolutePath)) {
            throw new \RuntimeException('Backup file not found');
        }

        // Parse original filename from backup path
        $basename = basename($backupAbsolutePath);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_(.+)$/', $basename, $m)) {
            throw new \RuntimeException('Invalid backup filename format');
        }
        $originalName = $m[1];
        $originalPath = $this->webRootDir . '/' . $originalName;

        // Create backup of current file before overwriting
        if (file_exists($originalPath)) {
            $this->createBackup($originalPath);
        }

        // Restore from backup
        $content = file_get_contents($backupAbsolutePath);
        if ($content === false) {
            throw new \RuntimeException('Cannot read backup file');
        }

        // Create parent directories if needed
        $dir = dirname($originalPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($originalPath, $content) === false) {
            throw new \RuntimeException('Cannot restore file');
        }

        return $originalPath;
    }

    /**
     * Given a URL string, returns a ranked list of candidate file paths (relative to webRoot).
     * Uses heuristics:
     *   - Extract path component from URL.
     *   - Try exact match: /foo/bar → foo/bar (file or dir+index).
     *   - Try .php extension: /foo/bar → foo/bar.php
     *   - Try pages/ prefix: /foo/bar → pages/bar.php, pages/foo/bar.php
     *   - Try index inside dir: /foo/ → foo/index.php
     *   - Filter to only existing files.
     * Returns array of relative path strings, best match first.
     * Returns [] if nothing found.
     */
    public function urlToFile(string $url): array {
        // Parse URL to extract path
        $parsed = parse_url($url);
        $urlPath = $parsed['path'] ?? '/';

        // Remove leading/trailing slashes and normalize
        $urlPath = trim($urlPath, '/');
        if ($urlPath === '' || $urlPath === 'index') {
            $urlPath = 'index.php';
        }

        $candidates = [];

        // Try various heuristics
        $attempts = [];

        // Attempt 1: Direct match (with .php if not present)
        if (!str_ends_with($urlPath, '.php')) {
            $attempts[] = $urlPath . '.php';
        }
        $attempts[] = $urlPath;
        $attempts[] = $urlPath . '/index.php';

        // Attempt 2: pages/ prefix
        if (!str_contains($urlPath, '/')) {
            $attempts[] = 'pages/' . $urlPath . '.php';
        }
        if (str_contains($urlPath, '/')) {
            $baseName = basename($urlPath);
            $attempts[] = 'pages/' . $baseName . '.php';
            $attempts[] = 'pages/' . $urlPath . '.php';
        }

        // Filter to existing files
        foreach ($attempts as $candidate) {
            $fullPath = $this->webRootDir . '/' . $candidate;
            if (file_exists($fullPath) && is_file($fullPath)) {
                $candidates[] = $candidate;
            }
        }

        // Remove duplicates while preserving order
        return array_unique($candidates);
    }

    // ── Template Installation ───────────────────────────────────────────────────

    /**
     * Downloads and installs a starter template from a ZIP URL.
     * 
     * The ZIP structure follows lutin-starters convention:
     * - public/     → Contents go to web root (where lutin.php lives)
     * - src/        → Goes to sibling of data directory
     * - data/       → Goes to sibling of data directory  
     * - lutin/      → Goes to sibling of data directory
     * - other dirs  → Goes to sibling of data directory
     * 
     * @param string $zipUrl URL to download the ZIP from
     * @param string|null $expectedHash Optional SHA-256 hash for integrity verification
     * @throws \RuntimeException on error
     */
    public function installTemplate(string $zipUrl, ?string $expectedHash = null): void {
        $tempDir = $this->config->getTempDir();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0700, true);
        }

        $zipPath = $tempDir . '/template_' . uniqid() . '.zip';
        $extractDir = $tempDir . '/template_' . uniqid() . '_extracted';

        $success = false;
        try {
            // Download the ZIP
            $this->downloadFile($zipUrl, $zipPath);
            
            // Verify downloaded file is a valid ZIP
            $fileSize = filesize($zipPath);
            $handle = fopen($zipPath, 'rb');
            $magic = fread($handle, 4);
            fclose($handle);
            if ($magic !== "PK\x03\x04") {
                throw new \RuntimeException("Downloaded file is not a valid ZIP (magic bytes: " . bin2hex($magic) . ", size: {$fileSize})");
            }

            // Verify hash if provided
            if ($expectedHash !== null) {
                $actualHash = hash_file('sha256', $zipPath);
                // Strip 'sha256-' prefix if present (build-zips.php format)
                $expectedHash = str_starts_with($expectedHash, 'sha256-') 
                    ? substr($expectedHash, 7) 
                    : $expectedHash;
                if ($actualHash !== $expectedHash) {
                    throw new \RuntimeException('Template hash verification failed');
                }
            }

            // Extract the ZIP
            $this->extractZip($zipPath, $extractDir);

            // Find the actual template root (might be nested inside a folder)
            $templateRoot = $this->findTemplateRoot($extractDir);
            if ($templateRoot === null) {
                // Debug: list what was extracted
                $extractedContents = $this->listDirForDebug($extractDir);
                throw new \RuntimeException(
                    'Invalid template structure: no public/ folder found. ' .
                    "Extracted to: {$extractDir}. Contents: " . json_encode($extractedContents)
                );
            }

            // Install the template
            $this->copyTemplateFiles($templateRoot);
            $success = true;

        } finally {
            // Cleanup only on success
            if ($success) {
                $this->recursiveDelete($zipPath);
                $this->recursiveDelete($extractDir);
            }
            // On failure, files are left for debugging
        }
    }

    /**
     * Download a file from URL to local path.
     */
    private function downloadFile(string $url, string $localPath): void {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize download');
        }

        $fp = fopen($localPath, 'wb');
        if ($fp === false) {
            curl_close($ch);
            throw new \RuntimeException('Failed to create local file for download');
        }

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success) {
            unlink($localPath);
            throw new \RuntimeException('Download failed: ' . curl_error($ch));
        }

        if ($httpCode !== 200) {
            unlink($localPath);
            throw new \RuntimeException('Download failed: HTTP ' . $httpCode);
        }
    }

    /**
     * Extract a ZIP file to a directory.
     */
    private function extractZip(string $zipPath, string $extractDir): void {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZIP extension not available');
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            throw new \RuntimeException('Failed to open ZIP file: error code ' . $result);
        }

        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        $zip->extractTo($extractDir);
        $zip->close();
    }

    /**
     * Find the template root directory (containing public/ folder).
     * Returns null if no valid structure found.
     */
    private function findTemplateRoot(string $extractDir): ?string {
        // Direct match: extracted/public exists
        if (is_dir($extractDir . '/public')) {
            return $extractDir;
        }

        // Look for a subdirectory containing public/
        $entries = scandir($extractDir);
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $extractDir . '/' . $entry;
            if (is_dir($path) && is_dir($path . '/public')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * List directory contents for debugging purposes.
     * Returns array of entries with their types.
     */
    private function listDirForDebug(string $dir, int $depth = 0): array {
        if ($depth > 2) {
            return ['(max depth reached)'];
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return ['(cannot read directory)'];
        }
        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $result[$entry . '/'] = $depth < 2 ? $this->listDirForDebug($path, $depth + 1) : '(dir)';
            } else {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * Copy template files to their appropriate locations.
     * 
     * - public/ → web root
     * - everything else → sibling of data directory
     */
    private function copyTemplateFiles(string $templateRoot): void {
        // Copy public/ to web root
        $publicDir = $templateRoot . '/public';
        if (is_dir($publicDir)) {
            $this->recursiveCopy($publicDir, $this->webRootDir);
        }

        // Copy other directories to sibling of data directory
        $privateRoot = dirname($this->dataDir);
        $entries = scandir($templateRoot);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'public') {
                    continue;
                }
                $srcPath = $templateRoot . '/' . $entry;
                if (is_dir($srcPath)) {
                    $destPath = $privateRoot . '/' . $entry;
                    $this->recursiveCopy($srcPath, $destPath);
                }
            }
        }
    }

    /**
     * Recursively copy a directory or file.
     */
    private function recursiveCopy(string $src, string $dst): void {
        if (is_file($src)) {
            // Skip lutin.php protection
            if (basename($src) === 'lutin.php') {
                return;
            }
            $dir = dirname($dst);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            copy($src, $dst);
            return;
        }

        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        $entries = scandir($src);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $srcPath = $src . '/' . $entry;
            $dstPath = $dst . '/' . $entry;

            // Skip lutin.php
            if ($entry === 'lutin.php') {
                continue;
            }

            if (is_dir($srcPath)) {
                $this->recursiveCopy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    /**
     * Recursively delete a file or directory.
     */
    private function recursiveDelete(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->recursiveDelete($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}

// ── LutinAgent.php ─────
interface LutinProviderAdapter {
    /**
     * Sends a request to the AI API.
     * Returns a generator that yields SSE-formatted strings.
     * Each yielded string is either:
     *   - A text delta:   "data: " . json_encode(['type'=>'text','delta'=>'...']) . "\n\n"
     *   - A tool call:    "data: " . json_encode(['type'=>'tool_call','name'=>'...','input'=>[...],'id'=>'...']) . "\n\n"
     *   - A stop signal:  "data: " . json_encode(['type'=>'stop','stop_reason'=>'...']) . "\n\n"
     * 
     * @param string $systemPrompt The system prompt to use (combines base prompt + AGENTS.md if present)
     */
    public function stream(array $messages, array $tools, string $systemPrompt = ''): \Generator;
}

class AnthropicAdapter implements LutinProviderAdapter {
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model) {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function stream(array $messages, array $tools, string $systemPrompt = ''): \Generator {
        $url = 'https://api.anthropic.com/v1/messages';

        // Use provided system prompt or fall back to default
        $system = $systemPrompt ?: 'You are Lutin, an AI assistant integrated into a PHP website editor. ' .
            'You can read files, list directories, and write files on the server. ' .
            'Always prefer making minimal, targeted changes. Never modify lutin.php or .lutin/ system files. ' .
            'When asked to create or modify a page, read the existing files first to understand the structure.';

        $payload = [
            'model' => $this->model,
            'max_tokens' => 8192,
            'system' => $system,
            'messages' => $messages,
            'tools' => $tools,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 300,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check for curl errors
        if ($response === false) {
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'API request failed: ' . $curlError
            ]) . "\n\n";
            return;
        }

        // Check for HTTP errors
        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? 'HTTP ' . $httpCode . ' error';
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'API error: ' . $errorMessage
            ]) . "\n\n";
            return;
        }

        // Parse the non-streaming response
        $data = json_decode($response, true);
        if (!$data || !isset($data['content'])) {
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'Unexpected API response format'
            ]) . "\n\n";
            return;
        }

        // Process content blocks
        foreach ($data['content'] as $block) {
            if ($block['type'] === 'text') {
                yield 'data: ' . json_encode(['type' => 'text', 'delta' => $block['text']]) . "\n\n";
            } elseif ($block['type'] === 'tool_use') {
                yield 'data: ' . json_encode([
                    'type' => 'tool_call',
                    'id' => $block['id'] ?? 'unknown',
                    'name' => $block['name'] ?? 'unknown',
                    'input' => $block['input'] ?? [],
                ]) . "\n\n";
            }
        }

        // Yield stop signal
        $stopReason = $data['stop_reason'] ?? 'end_turn';
        yield 'data: ' . json_encode(['type' => 'stop', 'stop_reason' => $stopReason]) . "\n\n";
    }
}

class OpenAIAdapter implements LutinProviderAdapter {
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model) {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function stream(array $messages, array $tools, string $systemPrompt = ''): \Generator {
        $url = 'https://api.openai.com/v1/chat/completions';

        // Use provided system prompt or fall back to default
        $system = $systemPrompt ?: 'You are Lutin, an AI assistant integrated into a PHP website editor. ' .
            'You can read files, list directories, and write files on the server. ' .
            'Always prefer making minimal, targeted changes. Never modify lutin.php or .lutin/ system files. ' .
            'When asked to create or modify a page, read the existing files first to understand the structure.';

        // Prepend system message to messages array
        $fullMessages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $messages
        );

        $payload = [
            'model' => $this->model,
            'messages' => $fullMessages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 300,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check for curl errors
        if ($response === false) {
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'API request failed: ' . $curlError
            ]) . "\n\n";
            return;
        }

        // Check for HTTP errors
        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? 'HTTP ' . $httpCode . ' error';
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'API error: ' . $errorMessage
            ]) . "\n\n";
            return;
        }

        // Parse the non-streaming response
        $data = json_decode($response, true);
        if (!$data || !isset($data['choices'][0]['message'])) {
            yield 'data: ' . json_encode([
                'type' => 'error',
                'message' => 'Unexpected API response format'
            ]) . "\n\n";
            return;
        }

        $message = $data['choices'][0]['message'];

        // Handle tool calls
        if (isset($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $toolCall) {
                $function = $toolCall['function'] ?? [];
                $arguments = [];
                if (isset($function['arguments'])) {
                    $arguments = json_decode($function['arguments'], true) ?? [];
                }
                yield 'data: ' . json_encode([
                    'type' => 'tool_call',
                    'id' => $toolCall['id'] ?? 'unknown',
                    'name' => $function['name'] ?? 'unknown',
                    'input' => $arguments,
                ]) . "\n\n";
            }
        }

        // Handle text content
        if (isset($message['content']) && !empty($message['content'])) {
            yield 'data: ' . json_encode(['type' => 'text', 'delta' => $message['content']]) . "\n\n";
        }

        // Yield stop signal
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'stop';
        yield 'data: ' . json_encode(['type' => 'stop', 'stop_reason' => $finishReason]) . "\n\n";
    }
}

class LutinAgent {
    private const MAX_ITERATIONS = 10;

    private LutinConfig $config;
    private LutinFileManager $fm;
    private LutinProviderAdapter $adapter;

    // Message history accumulated during this request (role/content pairs)
    private array $messages = [];

    // Tool definitions sent to the API
    private array $toolDefinitions;

    // Cached system prompt (base + AGENTS.md if present)
    private ?string $systemPrompt = null;

    public function __construct(LutinConfig $config, LutinFileManager $fm) {
        $this->config = $config;
        $this->fm = $fm;
        $this->adapter = $this->buildAdapter();
        $this->toolDefinitions = $this->buildToolDefinitions();
    }

    /**
     * Builds the system prompt by combining the base prompt with AGENTS.md content if present.
     * The AGENTS.md file is read from the data directory (outside web root).
     */
    private function buildSystemPrompt(): string {
        if ($this->systemPrompt !== null) {
            return $this->systemPrompt;
        }

        $basePrompt = 'You are Lutin, an AI assistant integrated into a PHP website editor. ' .
            'You can read files, list directories, and write files on the server. ' .
            'Always prefer making minimal, targeted changes. Never modify lutin.php or .lutin/ system files. ' .
            'When asked to create or modify a page, read the existing files first to understand the structure.';

        $dataDir = $this->config->getDataDir();
        $agentsMdPath = $dataDir . '/AGENTS.md';

        if (file_exists($agentsMdPath) && is_readable($agentsMdPath)) {
            $agentsContent = file_get_contents($agentsMdPath);
            if ($agentsContent !== false) {
                $basePrompt .= "\n\n---\n\nThe following is additional context about this specific project from AGENTS.md:\n\n" . $agentsContent;
            }
        }

        $this->systemPrompt = $basePrompt;
        return $basePrompt;
    }

    /**
     * Selects the correct adapter based on config->getProvider().
     * Throws \RuntimeException if provider is unknown.
     */
    private function buildAdapter(): LutinProviderAdapter {
        $provider = $this->config->getProvider();
        $apiKey = $this->config->getApiKey();
        $model = $this->config->getModel() ?? 'claude-3-5-haiku-20241022';

        if ($apiKey === null) {
            throw new \RuntimeException('API key not configured');
        }

        return match ($provider) {
            'anthropic' => new AnthropicAdapter($apiKey, $model),
            'openai' => new OpenAIAdapter($apiKey, $model),
            default => throw new \RuntimeException('Unknown provider: ' . $provider),
        };
    }

    /**
     * Returns the tool schema array in the format expected by the current provider.
     */
    private function buildToolDefinitions(): array {
        $provider = $this->config->getProvider();

        $tools = [
            [
                'name' => 'list_files',
                'description' => 'Lists files in a directory. Path is relative to site root.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'Directory path relative to root'],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => 'read_file',
                'description' => 'Reads a file. Path is relative to site root.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'File path relative to root'],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => 'write_file',
                'description' => 'Writes or creates a file. Path is relative to site root.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'File path relative to root'],
                        'content' => ['type' => 'string', 'description' => 'File content'],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
        ];

        if ($provider === 'openai') {
            // OpenAI format
            return array_map(function($tool) {
                return [
                    'type' => 'function',
                    'function' => $tool,
                ];
            }, $tools);
        }

        // Anthropic format
        return $tools;
    }

    /**
     * Main entry point.
     * 1. Sets up SSE headers
     * 2. Appends the user message to $this->messages
     * 3. Runs the agentic loop
     */
    public function chat(string $userMessage, array $history): void {
        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        ob_end_clean();
        ob_implicit_flush(true);

        // Restore message history
        $this->messages = $history;

        // Add user message
        $this->messages[] = ['role' => 'user', 'content' => $userMessage];

        // Run agentic loop
        $this->runLoop();

        // Final done marker
        echo "data: [DONE]\n\n";
        flush();
    }

    /**
     * Agentic loop (called recursively up to MAX_ITERATIONS = 10)
     */
    private function runLoop(int $iteration = 0): void {
        if ($iteration >= self::MAX_ITERATIONS) {
            $this->sseFlush(['type' => 'stop', 'stop_reason' => 'max_iterations']);
            return;
        }

        try {
            $systemPrompt = $this->buildSystemPrompt();
            $generator = $this->adapter->stream($this->messages, $this->toolDefinitions, $systemPrompt);

            $assistantContent = [];
            $textBuffer = '';
            $stopReason = null;
            $toolCalls = [];

            foreach ($generator as $event) {
                $line = trim(substr($event, 6)); // Remove "data: " prefix
                $data = json_decode($line, true);

                if ($data === null) {
                    continue;
                }

                if ($data['type'] === 'text') {
                    $textBuffer .= $data['delta'];
                    $this->sseFlush(['type' => 'text', 'delta' => $data['delta']]);
                } elseif ($data['type'] === 'tool_call') {
                    // Store tool call for execution
                    $toolCalls[] = [
                        'id' => $data['id'],
                        'name' => $data['name'],
                        'input' => $data['input'] ?? [],
                    ];
                    $this->sseFlush([
                        'type' => 'tool_start',
                        'name' => $data['name'],
                        'input' => $data['input'] ?? [],
                        'id' => $data['id']
                    ]);
                } elseif ($data['type'] === 'stop') {
                    $stopReason = $data['stop_reason'] ?? 'end_turn';
                } elseif ($data['type'] === 'error') {
                    // Forward errors to client
                    $this->sseFlush(['type' => 'error', 'message' => $data['message']]);
                    return;
                }
            }

            // Build the assistant message for history
            if (!empty($textBuffer)) {
                $assistantContent[] = ['type' => 'text', 'text' => $textBuffer];
            }
            foreach ($toolCalls as $tool) {
                $assistantContent[] = [
                    'type' => 'tool_use',
                    'id' => $tool['id'],
                    'name' => $tool['name'],
                    'input' => $tool['input'],
                ];
            }

            // Add assistant message to history (needed for proper context)
            if (!empty($assistantContent)) {
                $this->messages[] = ['role' => 'assistant', 'content' => $assistantContent];
            }

            // Check stop reason and execute tools if needed
            if ($stopReason === 'tool_use' || $stopReason === 'tool_calls') {
                if (!empty($toolCalls)) {
                    // Execute tools and add results to messages
                    foreach ($toolCalls as $tool) {
                        $result = $this->executeTool($tool['name'], $tool['input']);
                        
                        // Add tool result to message history
                        $this->messages[] = [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'tool_result',
                                    'tool_use_id' => $tool['id'],
                                    'content' => $result,
                                ]
                            ]
                        ];
                        
                        $this->sseFlush([
                            'type' => 'tool_result',
                            'id' => $tool['id'],
                            'result' => $result,
                        ]);
                    }
                    
                    // Continue loop for next response
                    $this->runLoop($iteration + 1);
                } else {
                    // No tools to execute, just end
                    $this->sseFlush(['type' => 'stop', 'stop_reason' => $stopReason]);
                }
            } else {
                // End of conversation
                $this->sseFlush(['type' => 'stop', 'stop_reason' => $stopReason ?? 'end_turn']);
            }
        } catch (\Throwable $e) {
            $this->sseFlush(['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Dispatches a tool call from the AI to the appropriate LutinFileManager method.
     * Returns the result as a string
     */
    private function executeTool(string $name, array $input): string {
        try {
            return match ($name) {
                'list_files' => json_encode($this->fm->listFiles($input['path'] ?? '')),
                'read_file' => $this->fm->readFile($input['path'] ?? ''),
                'write_file' => (function() use ($input) {
                    $this->fm->writeFile($input['path'] ?? '', $input['content'] ?? '');
                    return json_encode(['ok' => true]);
                })(),
                default => 'Unknown tool: ' . $name,
            };
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Sends a single SSE event.
     * Format:  "data: {json}\n\n"
     * Flushes immediately.
     */
    private function sseFlush(array $payload): void {
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
    }
}

// ── LutinRouter.php ─────
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

// ── LutinView.php ─────
class LutinView {
    private LutinConfig $config;
    private LutinAuth $auth;

    public function __construct(LutinConfig $config, LutinAuth $auth) {
        $this->config = $config;
        $this->auth = $auth;
    }

    public function renderSetupWizard(): void {
        $this->renderLayout('setup', function() {
            echo $this->getViewContent('setup_wizard');
        });
    }

    public function renderLogin(): void {
        $this->renderLayout('login', function() {
            echo $this->getViewContent('login');
        });
    }

    public function renderApp(): void {
        $this->renderLayout('chat', function() {
            echo $this->getViewContent('tab_chat');
            echo $this->getViewContent('tab_editor');
            echo $this->getViewContent('tab_config');
        });
    }

    public function renderTemplateSelection(): void {
        $this->renderLayout('templates', function() {
            echo $this->getViewContent('tab_templates');
        });
    }

    /**
     * Outputs the layout wrapper and calls $contentCallback to emit tab content.
     */
    private function renderLayout(string $activeTab, callable $contentCallback): void {
        $csrfToken = $this->auth->getCsrfToken();
        $siteTitle = 'Website Editor';

        $jsConfig = [
            'provider' => $this->config->getProvider(),
            'model' => $this->config->getModel(),
            'siteUrl' => $this->config->getSiteUrl(),
        ];

        ob_start();
        $contentCallback();
        $tabContent = ob_get_clean();

        $appJs = $this->getViewContent('app');

        // Get layout content
        $layoutContent = $this->getViewContent('layout');

        // Parse and render layout
        eval('?>' . $layoutContent);
    }

    /**
     * Gets view content (from constant or file).
     */
    private function getViewContent(string $name): string {
        // Check if we're in compiled mode (constants defined)
        $constName = 'LUTIN_VIEW_' . strtoupper($name);
        if ($name === 'app') {
            $constName = 'LUTIN_JS';
        }

        if (defined($constName)) {
            return constant($constName);
        }

        // Fall back to file loading for dev mode
        $filePath = __DIR__ . '/../views/';
        if ($name === 'app') {
            $filePath .= 'app.js';
        } else {
            $filePath .= str_replace('_', '_', $name) . '.php';
        }

        if (file_exists($filePath)) {
            return file_get_contents($filePath);
        }

        return '';
    }
}

const LUTIN_JS = <<<'LUTINJS'
// ── STATE ─────────────────────────────────────────────────────────────────────
const state = {
  csrfToken: document.querySelector('meta[name="lutin-token"]')?.content ?? '',
  currentFile: null,        // relative path of open file
  cmEditor: null,           // CodeMirror instance
  chatHistory: [],          // [{role, content}] accumulated for context
  isStreaming: false,       // true while SSE is open
};

// ── UTILS ─────────────────────────────────────────────────────────────────────
async function apiPost(action, body) {
  const response = await fetch(`?action=${action}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Lutin-Token': state.csrfToken,
    },
    body: JSON.stringify(body),
  });
  const data = await response.json();
  // Include HTTP status in response
  data.httpStatus = response.status;
  return data;
}

async function apiGet(action, params = {}) {
  const query = new URLSearchParams({ action, ...params }).toString();
  const response = await fetch(`?${query}`, {
    headers: {
      'X-Lutin-Token': state.csrfToken,
    },
  });
  const data = await response.json();
  // Include HTTP status in response
  data.httpStatus = response.status;
  return data;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function showToast(message, type = 'info') {
  const className = `toast-${type}`;
  const toast = document.createElement('div');
  toast.className = `toast ${className}`;
  toast.textContent = message;
  toast.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 1rem;
    background: ${type === 'error' ? '#dc3545' : type === 'success' ? '#28a745' : '#17a2b8'};
    color: white;
    border-radius: 4px;
    z-index: 9999;
    max-width: 300px;
  `;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

// ── TABS ──────────────────────────────────────────────────────────────────────
function initTabs() {
  function showTab(tabName) {
    // Hide all sections
    document.querySelectorAll('section').forEach(s => s.style.display = 'none');

    // Show selected section
    const section = document.getElementById(`tab-${tabName}`);
    if (section) section.style.display = 'block';

    // Update nav styling if nav exists
    const navLinks = document.querySelectorAll('nav a');
    if (navLinks.length > 0) {
      navLinks.forEach(a => {
        a.removeAttribute('aria-current');
        if (a.href.includes(`#${tabName}`)) {
          a.setAttribute('aria-current', 'page');
        }
      });
    }
  }

  // Handle hash changes - only show a different tab if hash is explicitly set
  window.addEventListener('hashchange', () => {
    if (location.hash) {
      const hash = location.hash.slice(1);
      showTab(hash);
    }
  });

  // Initial show - only if hash is explicitly set in URL
  // Otherwise, trust the CSS to show the correct initial tab (from PHP)
  if (location.hash) {
    const initialTab = location.hash.slice(1);
    showTab(initialTab);
  }
  // If no hash, update nav styling to match the visible tab (from CSS)
  else {
    const visibleTab = document.querySelector('section[style*="display: block"]') ||
                        document.querySelector('section');
    if (visibleTab) {
      const tabName = visibleTab.id.replace('tab-', '');
      const navLinks = document.querySelectorAll('nav a');
      if (navLinks.length > 0) {
        navLinks.forEach(a => {
          a.removeAttribute('aria-current');
          if (a.href.includes(`#${tabName}`)) {
            a.setAttribute('aria-current', 'page');
          }
        });
      }
    }
  }
}

// ── CHAT ──────────────────────────────────────────────────────────────────────
function initChat() {
  const chatForm = document.getElementById('chat-form');
  const chatInput = document.getElementById('chat-input');
  const chatMessages = document.getElementById('chat-messages');

  if (!chatForm) return;

  chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = chatInput.value.trim();
    if (!message) return;

    state.chatHistory.push({ role: 'user', content: message });
    appendMessage('user', message);
    chatInput.value = '';

    state.isStreaming = true;
    // Show loading indicator
    const loadingId = showLoadingIndicator();
    await openChatStream(message, loadingId);
    hideLoadingIndicator(loadingId);
    state.isStreaming = false;
  });
}

function showLoadingIndicator() {
  const chatMessages = document.getElementById('chat-messages');
  if (!chatMessages) return null;
  
  const id = 'loading-' + Date.now();
  const loading = document.createElement('article');
  loading.id = id;
  loading.className = 'message message--assistant message--loading';
  loading.innerHTML = `
    <div class="message__content">
      <span class="loading-dots">Thinking<span>.</span><span>.</span><span>.</span></span>
    </div>
  `;
  chatMessages.appendChild(loading);
  chatMessages.scrollTop = chatMessages.scrollHeight;
  return id;
}

function hideLoadingIndicator(id) {
  if (!id) return;
  const el = document.getElementById(id);
  if (el) el.remove();
}

function appendMessage(role, content) {
  const chatMessages = document.getElementById('chat-messages');
  if (!chatMessages) return;

  const article = document.createElement('article');
  article.className = `message message--${role}`;
  article.innerHTML = `<div class="message__content">${escapeHtml(content)}</div>`;
  chatMessages.appendChild(article);
  chatMessages.scrollTop = chatMessages.scrollHeight;

  return article;
}

async function openChatStream(userText, loadingId) {
  let assistantBubble = null;
  let assistantText = '';
  let receivedAnyData = false;
  
  try {
    const response = await fetch('?action=chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Lutin-Token': state.csrfToken,
      },
      body: JSON.stringify({
        message: userText,
        history: state.chatHistory,
      }),
    });

    if (!response.ok) {
      hideLoadingIndicator(loadingId);
      const errorText = await response.text();
      let errorMsg = 'Error sending message';
      try {
        const errorJson = JSON.parse(errorText);
        errorMsg = errorJson.error || errorMsg;
      } catch {}
      appendErrorMessage(errorMsg);
      return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n\n');
      buffer = lines.pop();

      for (const line of lines) {
        if (!line.startsWith('data: ')) continue;

        const jsonStr = line.slice(6);
        if (jsonStr === '[DONE]') continue;

        try {
          const event = JSON.parse(jsonStr);
          receivedAnyData = true;
          
          // Hide loading indicator once we start receiving data
          if (assistantBubble === null && loadingId) {
            hideLoadingIndicator(loadingId);
          }
          
          const result = handleSseEvent(event, assistantBubble, (bubble) => {
            assistantBubble = bubble;
          }, (text) => {
            assistantText += text;
          });
          if (result && result.bubble) {
            assistantBubble = result.bubble;
          }
        } catch (e) {
          console.error('Failed to parse SSE event:', e, line);
        }
      }
    }

    // Hide loading indicator if still showing (no data received or done)
    hideLoadingIndicator(loadingId);
    
    // If no data was received at all, show an error
    if (!receivedAnyData) {
      appendErrorMessage('No response received from the AI. Please check your API configuration.');
      return;
    }

    if (assistantText) {
      state.chatHistory.push({ role: 'assistant', content: assistantText });
    }
  } catch (error) {
    hideLoadingIndicator(loadingId);
    console.error('Chat stream error:', error);
    appendErrorMessage('Stream error: ' + error.message);
  }
}

function appendErrorMessage(message) {
  const chatMessages = document.getElementById('chat-messages');
  if (!chatMessages) return;
  
  const article = document.createElement('article');
  article.className = 'message message--assistant message--error';
  article.innerHTML = `
    <div class="message__content" style="color: #dc3545;">
      <strong>❌ Error:</strong> ${escapeHtml(message)}
      <br><small>Check your <a href="#config" onclick="showTab('config')">API configuration</a> and try again.</small>
    </div>
  `;
  chatMessages.appendChild(article);
  chatMessages.scrollTop = chatMessages.scrollHeight;
}

function handleSseEvent(event, bubbleEl, setBubble, appendText) {
  const chatMessages = document.getElementById('chat-messages');
  let result = { bubble: bubbleEl };

  if (event.type === 'text') {
    if (!bubbleEl) {
      bubbleEl = document.createElement('article');
      bubbleEl.className = 'message message--assistant';
      bubbleEl.innerHTML = '<div class="message__content"></div>';
      chatMessages.appendChild(bubbleEl);
      setBubble(bubbleEl);
      result.bubble = bubbleEl;
    }
    const content = bubbleEl.querySelector('.message__content');
    content.textContent += event.delta;
    appendText(event.delta);
    chatMessages.scrollTop = chatMessages.scrollHeight;
  } else if (event.type === 'error') {
    // Display error message to user
    showToast('Chat error: ' + event.message, 'error');
    if (!bubbleEl) {
      bubbleEl = document.createElement('article');
      bubbleEl.className = 'message message--assistant message--error';
      bubbleEl.innerHTML = '<div class="message__content"></div>';
      chatMessages.appendChild(bubbleEl);
      setBubble(bubbleEl);
      result.bubble = bubbleEl;
    }
    const content = bubbleEl.querySelector('.message__content');
    content.innerHTML = '❌ <strong>Error:</strong> ' + escapeHtml(event.message) + 
      '<br><small>Check your <a href="#config" onclick="showTab(\'config\')">API configuration</a></small>';
    appendText('');
    chatMessages.scrollTop = chatMessages.scrollHeight;
  } else if (event.type === 'tool_start' || event.type === 'tool_call') {
    if (!bubbleEl) {
      bubbleEl = document.createElement('article');
      bubbleEl.className = 'message message--assistant';
      bubbleEl.innerHTML = '<div class="message__content"></div>';
      chatMessages.appendChild(bubbleEl);
      setBubble(bubbleEl);
      result.bubble = bubbleEl;
    }
    // Create or update tool call details element
    const detailsId = 'tool-' + event.id;
    let details = document.getElementById(detailsId);
    if (!details) {
      details = document.createElement('details');
      details.id = detailsId;
      details.dataset.status = 'running';
      details.innerHTML = `
        <summary>🔧 ${escapeHtml(event.name)} (${event.id})</summary>
        <pre class="tool-input">${escapeHtml(JSON.stringify(event.input || {}, null, 2))}</pre>
        <pre class="tool-result" style="display:none; background:#1a472a; color:#90ee90; padding:0.5rem;"></pre>
      `;
      bubbleEl.appendChild(details);
    }
    chatMessages.scrollTop = chatMessages.scrollHeight;
  } else if (event.type === 'tool_result') {
    // Update existing tool call with result
    const detailsId = 'tool-' + event.id;
    const details = document.getElementById(detailsId);
    if (details) {
      details.dataset.status = 'done';
      const resultPre = details.querySelector('.tool-result');
      if (resultPre) {
        resultPre.style.display = 'block';
        resultPre.textContent = 'Result: ' + escapeHtml(event.result);
      }
      const summary = details.querySelector('summary');
      if (summary) {
        summary.textContent = summary.textContent.replace('🔧', '✅');
      }
    }
  } else if (event.type === 'stop') {
    // Conversation ended normally, nothing special to do
    console.log('Chat stopped:', event.stop_reason);
  }
  
  return result;
}

// ── EDITOR ────────────────────────────────────────────────────────────────────
function initEditor() {
  const cmContainer = document.getElementById('codemirror-container');
  if (!cmContainer) return;

  state.cmEditor = CodeMirror(cmContainer, {
    lineNumbers: true,
    theme: 'default',
    mode: 'php',
    indentUnit: 4,
    tabSize: 4,
    indentWithTabs: false,
    lineWrapping: false,
    value: '// Select a file to edit',
  });

  const saveBtn = document.getElementById('save-btn');
  if (saveBtn) {
    saveBtn.addEventListener('click', saveFile);
  }
}

async function openFile(path) {
  try {
    const result = await apiGet('read', { path });
    if (!result.ok) {
      showToast('Error reading file: ' + result.error, 'error');
      return;
    }

    state.currentFile = path;
    const mode = detectMode(path);
    state.cmEditor.setOption('mode', mode);
    state.cmEditor.setValue(result.data.content);

    document.getElementById('editor-filename').textContent = path;
    document.getElementById('save-btn').disabled = false;
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

async function saveFile() {
  if (!state.currentFile) {
    showToast('No file selected', 'warning');
    return;
  }

  try {
    const result = await apiPost('write', {
      path: state.currentFile,
      content: state.cmEditor.getValue(),
    });

    if (result.ok) {
      showToast('File saved!', 'success');
    } else {
      showToast('Error: ' + result.error, 'error');
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

function detectMode(path) {
  if (path.endsWith('.php')) return 'php';
  if (path.endsWith('.js')) return 'javascript';
  if (path.endsWith('.css')) return 'css';
  if (path.endsWith('.html') || path.endsWith('.htm')) return 'htmlmixed';
  return 'null';
}

// ── FILE TREE ─────────────────────────────────────────────────────────────────
function initFileTree() {
  const fileList = document.getElementById('file-list');
  if (!fileList) return;

  loadDir('', fileList);
}

async function loadDir(path, containerEl) {
  try {
    const result = await apiGet('list', { path });
    if (!result.ok) {
      showToast('Error: ' + result.error, 'error');
      return;
    }

    containerEl.innerHTML = '';
    for (const entry of result.data) {
      renderFileEntry(entry, containerEl);
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

function renderFileEntry(entry, containerEl) {
  const div = document.createElement('div');
  div.style.paddingLeft = '1rem';

  if (entry.type === 'dir') {
    const details = document.createElement('details');
    const summary = document.createElement('summary');
    summary.textContent = '📁 ' + entry.name;
    summary.style.cursor = 'pointer';
    const subDir = document.createElement('div');

    details.appendChild(summary);
    details.appendChild(subDir);

    details.addEventListener('toggle', async () => {
      if (details.open && subDir.children.length === 0) {
        await loadDir(entry.path, subDir);
      }
    });

    div.appendChild(details);
  } else {
    const link = document.createElement('a');
    link.href = '#';
    link.textContent = '📄 ' + entry.name;
    link.style.display = 'block';
    link.style.cursor = 'pointer';
    link.onclick = (e) => {
      e.preventDefault();
      openFile(entry.path);
    };
    div.appendChild(link);
  }

  containerEl.appendChild(div);
}

// ── CONFIG ────────────────────────────────────────────────────────────────────
function initConfig() {
  const configForm = document.getElementById('config-form');
  if (configForm) {
    configForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      await saveConfig();
    });
    loadConfig();
  }

  loadBackups();
}

function loadConfig() {
  if (!window.LUTIN_CONFIG) return;

  document.getElementById('config-provider').value = window.LUTIN_CONFIG.provider || 'anthropic';
  document.getElementById('config-model').value = window.LUTIN_CONFIG.model || '';
  document.getElementById('config-site-url').value = window.LUTIN_CONFIG.siteUrl || '';
}

async function saveConfig() {
  const apiKeyInput = document.getElementById('config-api-key');
  const apiKeyValue = apiKeyInput.value;

  const formData = {
    provider: document.getElementById('config-provider').value,
    api_key: apiKeyValue,
    model: document.getElementById('config-model').value,
    site_url: document.getElementById('config-site-url').value,
  };

  try {
    const result = await apiPost('config', formData);
    if (result.ok) {
      // Keep the API key visible in the field (it was just entered by user)
      // This provides better UX - user sees their input was saved
      showToast('Config saved!', 'success');
      // Update the config in memory so subsequent saves use current values
      window.LUTIN_CONFIG = window.LUTIN_CONFIG || {};
      window.LUTIN_CONFIG.provider = formData.provider;
      window.LUTIN_CONFIG.model = formData.model;
      window.LUTIN_CONFIG.siteUrl = formData.site_url;
    } else {
      showToast('Error: ' + result.error, 'error');
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

async function loadBackups(showError = true) {
  try {
    const result = await apiGet('backups');
    if (!result.ok) {
      // Silently ignore 401 (unauthorized) during init - user may not be authenticated yet
      if (result.httpStatus === 401) {
        return;
      }
      if (showError) {
        showToast('Error loading backups: ' + result.error, 'error');
      }
      return;
    }

    const backupList = document.getElementById('backup-list');
    if (!backupList) return;

    backupList.innerHTML = '';

    // Show message if no backups
    if (!result.data || result.data.length === 0) {
      backupList.innerHTML = '<p style="color: #999;">No backups yet</p>';
      return;
    }

    for (const backup of result.data) {
      const div = document.createElement('div');
      div.className = 'backup-entry';
      div.style.cssText = `
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem;
        border: 1px solid #ddd;
        margin-bottom: 0.5rem;
        border-radius: 4px;
      `;
      div.innerHTML = `
        <div>
          <strong>${escapeHtml(backup.original_name)}</strong><br>
          <small>${escapeHtml(backup.timestamp)} (${backup.size} bytes)</small>
        </div>
        <div>
          <button data-backup-path="${escapeHtml(backup.backup_path)}" class="btn-view">View</button>
          <button data-backup-path="${escapeHtml(backup.backup_path)}" class="btn-restore">Restore</button>
        </div>
      `;
      backupList.appendChild(div);
    }

    // Add event listeners
    document.querySelectorAll('.btn-view').forEach(btn => {
      btn.addEventListener('click', async () => {
        await viewBackup(btn.dataset.backupPath);
      });
    });

    document.querySelectorAll('.btn-restore').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (confirm('Restore this backup?')) {
          await restoreBackup(btn.dataset.backupPath);
        }
      });
    });
  } catch (error) {
    if (showError) {
      showToast('Error: ' + error.message, 'error');
    }
  }
}

async function viewBackup(backupPath) {
  try {
    const result = await apiGet('read', { path: backupPath });
    if (result.ok) {
      state.cmEditor.setValue(result.data.content);
      document.getElementById('editor-filename').textContent = backupPath + ' (backup)';
    } else {
      showToast('Error: ' + result.error, 'error');
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

async function restoreBackup(backupPath) {
  try {
    const result = await apiPost('restore', { path: backupPath });
    if (result.ok) {
      showToast('Backup restored!', 'success');
      await loadBackups();
    } else {
      showToast('Error: ' + result.error, 'error');
    }
  } catch (error) {
    showToast('Error: ' + error.message, 'error');
  }
}

// ── URL LOOKUP ────────────────────────────────────────────────────────────────
function initUrlLookup() {
  const urlForm = document.getElementById('url-lookup-form');
  if (!urlForm) return;

  urlForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = document.getElementById('url-input').value;
    if (!url) return;

    try {
      const result = await apiGet('url_map', { url });
      if (result.ok && result.data.length > 0) {
        if (result.data.length === 1) {
          openFile(result.data[0]);
        } else {
          // Show picker
          const choice = prompt('Multiple matches found:\n' + result.data.join('\n') + '\n\nEnter number (0-' + (result.data.length - 1) + '):');
          if (choice !== null && choice in result.data) {
            openFile(result.data[choice]);
          }
        }
      } else {
        showToast('No matching file found', 'warning');
      }
    } catch (error) {
      showToast('Error: ' + error.message, 'error');
    }
  });
}

// ── SETUP WIZARD ──────────────────────────────────────────────────────────────
function initSetup() {
  const setupForm = document.getElementById('setup-form');
  if (!setupForm) return;

  setupForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const password = document.getElementById('setup-password').value;
    const confirm = document.getElementById('setup-confirm').value;

    if (password !== confirm) {
      showToast('Passwords do not match', 'error');
      return;
    }

    try {
      const result = await apiPost('setup', {
        password,
        confirm,
        provider: document.getElementById('setup-provider').value,
        api_key: document.getElementById('setup-api-key').value,
        model: document.getElementById('setup-model').value,
        site_url: document.getElementById('setup-site-url').value,
      });

      if (result.ok) {
        showToast('Setup complete! Redirecting...', 'success');
        setTimeout(() => location.reload(), 1000);
      } else {
        showToast('Setup error: ' + result.error, 'error');
      }
    } catch (error) {
      showToast('Error: ' + error.message, 'error');
    }
  });
}

// ── LOGIN ──────────────────────────────────────────────────────────────────────
function initLogin() {
  const loginForm = document.getElementById('login-form');
  if (!loginForm) return;

  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    try {
      const result = await apiPost('login', {
        password: document.getElementById('login-password').value,
      });

      if (result.ok) {
        showToast('Logged in! Redirecting...', 'success');
        setTimeout(() => location.reload(), 1000);
      } else {
        showToast('Login failed: ' + result.error, 'error');
      }
    } catch (error) {
      showToast('Error: ' + error.message, 'error');
    }
  });
}

// ── TEMPLATE SELECTION ───────────────────────────────────────────────────────
function initTemplates() {
  const templatesGrid = document.getElementById('templates-grid');
  if (!templatesGrid) return;

  // Load available templates
  loadTemplates();

  // Add click handlers for template selection
  templatesGrid.addEventListener('click', (e) => {
    const btn = e.target.closest('.select-template-btn');
    if (!btn) return;

    const templateId = btn.dataset.templateId;
    const templateCard = btn.closest('.template-card');
    const zipUrl = templateCard?.dataset.zipUrl;
    const hash = templateCard?.dataset.hash;

    installTemplate(templateId, zipUrl, hash);
  });
}

async function loadTemplates() {
  const loadingEl = document.getElementById('templates-loading');
  const errorEl = document.getElementById('templates-error');
  const gridEl = document.getElementById('templates-grid');

  try {
    const result = await apiGet('templates');
    
    if (result.ok && result.data.templates) {
      // Add template cards
      for (const template of result.data.templates) {
        addTemplateCard(template);
      }
    }

    // Show the grid (even if empty, since we have "Empty Project" option)
    loadingEl.style.display = 'none';
    gridEl.style.display = 'grid';

    if (result.data.error) {
      console.warn('Template loading issue:', result.data.error);
    }
  } catch (error) {
    console.error('Failed to load templates:', error);
    loadingEl.style.display = 'none';
    errorEl.style.display = 'block';
    gridEl.style.display = 'grid';
  }
}

function addTemplateCard(template) {
  const gridEl = document.getElementById('templates-grid');
  if (!gridEl) return;

  const article = document.createElement('article');
  article.className = 'template-card';
  article.dataset.templateId = template.id;
  article.dataset.zipUrl = template.download_url || '';
  article.dataset.hash = template.hash || '';
  article.style.cssText = 'cursor: pointer; border: 2px solid transparent;';

  const name = escapeHtml(template.name || template.id);
  const description = escapeHtml(template.description || 'A starter template for your project.');

  article.innerHTML = `
    <h3>${name}</h3>
    <p>${description}</p>
    <button type="button" class="select-template-btn" data-template-id="${escapeHtml(template.id)}">Select Template</button>
  `;

  // Insert before the last child (the Empty Project option should stay first)
  const emptyCard = gridEl.querySelector('[data-template-id=""]');
  if (emptyCard && emptyCard.nextElementSibling) {
    gridEl.insertBefore(article, emptyCard.nextElementSibling);
  } else {
    gridEl.appendChild(article);
  }
}

async function installTemplate(templateId, zipUrl, hash) {
  const installingEl = document.getElementById('template-installing');
  const gridEl = document.getElementById('templates-grid');

  // Show installing state
  if (installingEl) installingEl.style.display = 'block';
  if (gridEl) gridEl.style.opacity = '0.5';

  try {
    const result = await apiPost('install_template', {
      template_id: templateId,
      zip_url: zipUrl,
      hash: hash,
    });

    if (result.ok) {
      showToast(templateId ? 'Template installed successfully!' : 'Starting with empty project', 'success');
      setTimeout(() => location.reload(), 1500);
    } else {
      const errorMsg = result.error || 'Unknown error';
      console.error('[Lutin] Template installation failed:', errorMsg);
      console.error('[Lutin] Template ID:', templateId, 'ZIP URL:', zipUrl);
      showToast('Installation failed: ' + errorMsg, 'error');
      if (installingEl) installingEl.style.display = 'none';
      if (gridEl) gridEl.style.opacity = '1';
    }
  } catch (error) {
    console.error('[Lutin] Template installation error:', error);
    showToast('Installation error: ' + error.message, 'error');
    if (installingEl) installingEl.style.display = 'none';
    if (gridEl) gridEl.style.opacity = '1';
  }
}

// ── INIT ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initSetup();
  initLogin();
  initTabs();
  initChat();
  initEditor();
  initFileTree();
  initConfig();
  initUrlLookup();
  initTemplates();
});

// Make showTab globally accessible for inline onclick handlers
window.showTab = function(tabName) {
  // Hide all sections
  document.querySelectorAll('section').forEach(s => s.style.display = 'none');

  // Show selected section
  const section = document.getElementById(`tab-${tabName}`);
  if (section) section.style.display = 'block';

  // Update nav styling if nav exists
  const navLinks = document.querySelectorAll('nav a');
  if (navLinks.length > 0) {
    navLinks.forEach(a => {
      a.removeAttribute('aria-current');
      if (a.href.includes(`#${tabName}`)) {
        a.setAttribute('aria-current', 'page');
      }
    });
  }
  
  // Update hash
  location.hash = tabName;
};

LUTINJS;

const LUTIN_VIEW_LAYOUT = <<<'LUTINVIEW'
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="lutin-token" content="<?= htmlspecialchars($csrfToken) ?>">
  <title>Lutin — <?= htmlspecialchars($siteTitle) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
  <style>
    /* Hide all sections by default, JavaScript will show the active one */
    section { display: none; }
    #tab-<?= htmlspecialchars($activeTab) ?> { display: block; } /* Show active tab initially */
    
    /* Loading indicator animation */
    .message--loading .loading-dots span {
      animation: loadingDots 1.4s infinite ease-in-out both;
      display: inline-block;
    }
    .message--loading .loading-dots span:nth-child(1) { animation-delay: -0.32s; }
    .message--loading .loading-dots span:nth-child(2) { animation-delay: -0.16s; }
    .message--loading .loading-dots span:nth-child(3) { animation-delay: 0s; }
    
    @keyframes loadingDots {
      0%, 80%, 100% { opacity: 0; }
      40% { opacity: 1; }
    }
    
    .message--error .message__content {
      color: #dc3545;
      background: rgba(220, 53, 69, 0.1);
      padding: 0.75rem;
      border-radius: 4px;
    }
    
    /* Chat message styling improvements */
    .message {
      margin-bottom: 1rem;
    }
    .message__content {
      padding: 0.75rem 1rem;
      border-radius: 4px;
    }
    .message--user .message__content {
      background: var(--primary);
      color: white;
      margin-left: 2rem;
    }
    .message--assistant .message__content {
      background: var(--card-background-color);
      margin-right: 2rem;
    }
  </style>
  <script>window.LUTIN_CONFIG = <?= json_encode($jsConfig) ?>;</script>
</head>
<body>
  <?php if (in_array($activeTab, ['chat', 'editor', 'config'])): ?>
  <nav>
    <ul>
      <li><a href="#chat">Chat</a></li>
      <li><a href="#editor">Editor</a></li>
      <li><a href="#config">Config</a></li>
    </ul>
  </nav>
  <?php endif; ?>
  <main><?= $tabContent ?></main>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.js"></script>
  <script><?= $appJs ?></script>
</body>
</html>

LUTINVIEW;

const LUTIN_VIEW_SETUP_WIZARD = <<<'LUTINVIEW'
<section id="tab-setup">
  <article style="max-width: 400px; margin: 3rem auto;">
    <h2>Set up Lutin</h2>
    <form id="setup-form">
      <label>
        Password
        <input type="password" id="setup-password" name="password" required>
      </label>
      <label>
        Confirm Password
        <input type="password" id="setup-confirm" name="confirm" required>
      </label>
      <label>
        AI Provider
        <select id="setup-provider" name="provider">
          <option value="anthropic">Anthropic (Claude)</option>
          <option value="openai">OpenAI (GPT)</option>
        </select>
      </label>
      <label>
        API Key
        <input type="password" id="setup-api-key" name="api_key" required>
      </label>
      <label>
        Model
        <input type="text" id="setup-model" name="model" placeholder="claude-3-5-haiku-20241022">
      </label>
      <label>
        Site URL (optional)
        <input type="url" id="setup-site-url" name="site_url" placeholder="https://example.com">
      </label>
      <label>
        Data Directory (optional)
        <input type="text" id="setup-data-dir" name="data_dir" placeholder="../lutin">
        <small>Where Lutin stores config and backups. Default is outside the web root for security.</small>
      </label>
      <button type="submit">Set up Lutin</button>
    </form>
  </article>
</section>

LUTINVIEW;

const LUTIN_VIEW_LOGIN = <<<'LUTINVIEW'
<section id="tab-login">
  <article style="max-width: 400px; margin: 3rem auto;">
    <h2>Login</h2>
    <form id="login-form">
      <label>
        Password
        <input type="password" id="login-password" name="password" required>
      </label>
      <button type="submit">Login</button>
    </form>
  </article>
</section>

LUTINVIEW;

const LUTIN_VIEW_TAB_CHAT = <<<'LUTINVIEW'
<section id="tab-chat">
  <div id="chat-messages" style="height: 500px; overflow-y: auto; margin-bottom: 1rem; padding: 1rem; border: 1px solid #ccc; border-radius: 4px;"></div>
  <form id="chat-form">
    <textarea id="chat-input" placeholder="Ask Lutin…" rows="3"></textarea>
    <button type="submit">Send</button>
  </form>
</section>

LUTINVIEW;

const LUTIN_VIEW_TAB_EDITOR = <<<'LUTINVIEW'
<section id="tab-editor">
  <div style="display: grid; grid-template-columns: 250px 1fr; gap: 1rem; height: 600px;">
    <aside id="file-tree">
      <form id="url-lookup-form">
        <fieldset>
          <input id="url-input" type="url" placeholder="Paste page URL…">
          <button type="submit">Find</button>
        </fieldset>
      </form>
      <div id="file-list"></div>
    </aside>
    <div id="editor-panel">
      <div id="editor-toolbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #ccc;">
        <span id="editor-filename" style="font-weight: bold;">No file open</span>
        <button id="save-btn" disabled>Save</button>
      </div>
      <div id="codemirror-container" style="border: 1px solid #ccc; border-radius: 4px;"></div>
    </div>
  </div>
</section>

LUTINVIEW;

const LUTIN_VIEW_TAB_CONFIG = <<<'LUTINVIEW'
<section id="tab-config">
  <div style="max-width: 600px; margin: 0 auto;">
    <h2>Configuration</h2>
    <form id="config-form">
      <label>
        AI Provider
        <select id="config-provider" name="provider">
          <option value="anthropic">Anthropic (Claude)</option>
          <option value="openai">OpenAI (GPT)</option>
        </select>
      </label>
      <label>
        API Key
        <input type="password" id="config-api-key" name="api_key">
      </label>
      <label>
        Model
        <input type="text" id="config-model" name="model">
      </label>
      <label>
        Site URL (optional)
        <input type="url" id="config-site-url" name="site_url">
      </label>
      <button type="submit">Save Config</button>
    </form>

    <hr>

    <h3>Backups</h3>
    <div id="backup-list"></div>
  </div>
</section>

LUTINVIEW;

const LUTIN_VIEW_TAB_TEMPLATES = <<<'LUTINVIEW'
<section id="tab-templates">
  <article style="max-width: 800px; margin: 2rem auto;">
    <h2>Choose a Starter Template</h2>
    <p>Select a template to get started quickly, or choose "Empty Project" to start from scratch.</p>
    
    <div id="templates-loading" class="loading-indicator">
      <p>Loading available templates...</p>
    </div>
    
    <div id="templates-error" style="display: none;" class="message message--error">
      <p>Failed to load templates. You can still start with an empty project.</p>
    </div>
    
    <div id="templates-grid" class="grid" style="display: none;">
      <!-- Empty project option -->
      <article class="template-card" data-template-id="" style="cursor: pointer; border: 2px solid transparent;">
        <h3>🚀 Empty Project</h3>
        <p>Start from scratch with a clean slate.</p>
        <button type="button" class="select-template-btn" data-template-id="">Start Empty</button>
      </article>
      
      <!-- Template cards will be inserted here -->
    </div>
    
    <div id="template-installing" style="display: none; margin-top: 2rem;">
      <p>Installing template... <span class="loading-dots">Please wait</span></p>
      <progress id="install-progress" style="width: 100%;"></progress>
    </div>
  </article>
</section>

<style>
.template-card {
  padding: 1.5rem;
  border-radius: 8px;
  background: var(--card-background-color);
  transition: border-color 0.2s, transform 0.2s;
}
.template-card:hover {
  border-color: var(--primary);
  transform: translateY(-2px);
}
.template-card.selected {
  border-color: var(--primary);
}
.template-card h3 {
  margin-top: 0;
  margin-bottom: 0.5rem;
}
.template-card p {
  margin-bottom: 1rem;
  color: var(--muted-color);
  font-size: 0.9rem;
}
.select-template-btn {
  width: 100%;
}
</style>

LUTINVIEW;

// Define root directories
// LUTIN_ROOT: directory containing lutin.php (web root)
// LUTIN_DATA_DIR: directory for Lutin's data (outside web root by default)
define('LUTIN_ROOT', __DIR__);
define('LUTIN_DATA_DIR', getenv('LUTIN_DATA_DIR') ?: dirname(__DIR__) . '/lutin');
define('LUTIN_VERSION', '1.0.0');

// In dev: require_once each class file.
// (In dist build, all classes are already inlined above this block.)
if (!class_exists('LutinConfig')) {
    require_once __DIR__ . '/classes/LutinConfig.php';
    require_once __DIR__ . '/classes/LutinAuth.php';
    require_once __DIR__ . '/classes/LutinFileManager.php';
    require_once __DIR__ . '/classes/LutinAgent.php';
    require_once __DIR__ . '/classes/LutinRouter.php';
    require_once __DIR__ . '/classes/LutinView.php';
}

// Bootstrap
$config = new LutinConfig(LUTIN_ROOT, LUTIN_DATA_DIR);
$config->load();

$auth = new LutinAuth($config);
$auth->startSession();

$fm    = new LutinFileManager(LUTIN_ROOT, $config->getDataDir(), $config);
$view  = new LutinView($config, $auth);

// Agent is initialized lazily by router when needed
$router = new LutinRouter($config, $auth, $fm, null, $view);
$router->dispatch();

