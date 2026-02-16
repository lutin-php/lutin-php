<?php
declare(strict_types=1);

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
}
