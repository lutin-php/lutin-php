<?php
declare(strict_types=1);

class LutinFileManager {
    // Paths that can NEVER be written to, even if they resolve inside projectRoot
    private const PROTECTED_PATHS = [
        'lutin.php',
    ];

    private LutinConfig $config;

    public function __construct(LutinConfig $config) {
        $this->config = $config;
    }

    /**
     * Returns the project root directory.
     */
    public function getProjectRoot(): string {
        return $this->config->getProjectRoot();
    }

    /**
     * Returns the web root directory (where lutin.php lives).
     */
    public function getWebRoot(): string {
        return $this->config->getWebRoot();
    }

    /**
     * Returns the lutin directory.
     */
    public function getLutinDir(): string {
        return $this->config->getLutinDir();
    }

    /**
     * Resolves $path relative to $projectRootDir.
     * Throws \RuntimeException if the resolved path escapes $projectRootDir
     * or matches a protected path OR is inside the lutin directory.
     * Does NOT require the file to exist (for write use-cases).
     * Returns the absolute path.
     */
    public function safePath(string $path): string {
        // Normalize the path to remove . and ..
        $absolute = realpath($this->config->getProjectRoot() . '/' . $path);

        // If realpath fails (path doesn't exist), manually construct and validate
        if ($absolute === false) {
            $parts = array_filter(explode('/', trim($path, '/')), fn($p) => $p !== '' && $p !== '.');
            $absolute = $this->config->getProjectRoot();
            foreach ($parts as $part) {
                if ($part === '..') {
                    throw new \RuntimeException('Path escape attempt');
                }
                $absolute .= '/' . $part;
            }
        }

        // Check if the path escapes projectRoot
        $realRoot = realpath($this->config->getProjectRoot());
        if ($realRoot === false) {
            throw new \RuntimeException('Root directory does not exist');
        }
        if (!str_starts_with($absolute, $realRoot . '/') && $absolute !== $realRoot) {
            throw new \RuntimeException('Path escape attempt');
        }

        // Check if the path is inside the lutin directory (PROTECTED)
        $realLutinDir = realpath($this->config->getLutinDir());
        if ($realLutinDir !== false) {
            if (str_starts_with($absolute, $realLutinDir . '/') || $absolute === $realLutinDir) {
                throw new \RuntimeException('Access to lutin directory is not allowed');
            }
        }

        // Check for protected paths (relative to projectRoot)
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
     * Checks if a path (relative to project root) points to or inside the lutin directory.
     */
    private function isLutinDirPath(string $relativePath): bool {
        $lutinDirName = basename($this->config->getLutinDir());
        // Handle case where lutin dir is inside project root
        $realProjectRoot = realpath($this->config->getProjectRoot());
        $realLutinDir = realpath($this->config->getLutinDir());
        
        if ($realLutinDir !== false && $realProjectRoot !== false) {
            // Check if lutin dir is inside project root
            if (str_starts_with($realLutinDir, $realProjectRoot . '/')) {
                $relativeLutinPath = substr($realLutinDir, strlen($realProjectRoot) + 1);
                return $relativePath === $relativeLutinPath || str_starts_with($relativePath, $relativeLutinPath . '/');
            }
        }
        
        // Fallback: check by basename match at root level
        return $relativePath === $lutinDirName || str_starts_with($relativePath, $lutinDirName . '/');
    }

    /**
     * Lists $path directory contents (relative to projectRoot).
     * Returns array of ['name' => string, 'type' => 'file'|'dir', 'path' => string (relative)]
     * Skips lutin.php and the lutin directory.
     * $path defaults to '' (project root).
     * 
     * Options:
     *   - recursive (bool): List files recursively. Default: false
     *   - search_pattern (string): Filter by name pattern. Default: null
     *   - strict_mode (bool): If false, case-insensitive partial match. Default: true
     *   - file_only (bool): Return only files. Default: false
     */
    public function listFiles(string $path = '', array $options = []): array {
        $recursive = $options['recursive'] ?? false;
        $searchPattern = $options['search_pattern'] ?? null;
        $strictMode = $options['strict_mode'] ?? true;
        $fileOnly = $options['file_only'] ?? false;

        // If searching recursively or with pattern, use the recursive search
        if ($recursive || $searchPattern !== null) {
            return $this->listFilesRecursive($path, $searchPattern, $strictMode, $fileOnly);
        }

        $dirPath = $path === '' ? $this->config->getProjectRoot() : $this->safePath($path);

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

            $relPath = $path === '' ? $item : $path . '/' . $item;
            
            // Skip the lutin directory
            if ($this->isLutinDirPath($relPath)) {
                continue;
            }

            $fullPath = $dirPath . '/' . $item;
            $isDir = is_dir($fullPath);

            // Skip directories if file_only is true
            if ($fileOnly && $isDir) {
                continue;
            }

            $entries[] = [
                'name' => $item,
                'type' => $isDir ? 'dir' : 'file',
                'path' => $relPath,
            ];
        }

        return $entries;
    }

    /**
     * Recursively list all files, optionally filtering by search pattern.
     */
    private function listFilesRecursive(
        string $path, 
        ?string $searchPattern, 
        bool $strictMode,
        bool $fileOnly
    ): array {
        $results = [];
        $basePath = $path === '' ? $this->config->getProjectRoot() : $this->safePath($path);
        
        if (!is_dir($basePath)) {
            return $results;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $fullPath = $fileInfo->getPathname();
            $relPath = substr($fullPath, strlen($this->config->getProjectRoot()) + 1);
            
            // Skip lutin.php
            if (basename($relPath) === 'lutin.php') {
                continue;
            }
            
            // Skip lutin directory
            if ($this->isLutinDirPath($relPath)) {
                continue;
            }

            $isDir = $fileInfo->isDir();

            // Skip directories if file_only is true
            if ($fileOnly && $isDir) {
                continue;
            }

            // Apply search pattern filter
            if ($searchPattern !== null) {
                if (!$this->matchesPattern($relPath, $searchPattern, $strictMode)) {
                    continue;
                }
            }

            $results[] = [
                'name' => $fileInfo->getFilename(),
                'type' => $isDir ? 'dir' : 'file',
                'path' => $relPath,
            ];
        }

        return $results;
    }

    /**
     * Check if a path matches the search pattern.
     */
    private function matchesPattern(string $path, string $pattern, bool $strictMode): bool {
        $lowerPath = strtolower($path);
        $lowerPattern = strtolower($pattern);
        
        if ($strictMode) {
            // Strict mode: exact match, starts with, or contains substring
            if ($path === $pattern) {
                return true;
            }
            if (str_starts_with($path, $pattern)) {
                return true;
            }
            // Also check substring (case-sensitive first)
            if (str_contains($path, $pattern)) {
                return true;
            }
            // Case-insensitive substring
            if (str_contains($lowerPath, $lowerPattern)) {
                return true;
            }
            return false;
        }
        
        // Non-strict mode: more permissive matching
        
        // Direct substring match (case-insensitive)
        if (str_contains($lowerPath, $lowerPattern)) {
            return true;
        }
        
        // Split pattern by common separators and check if all parts are in path
        $patternParts = preg_split('/[\s\-_\.\/]/', $lowerPattern, -1, PREG_SPLIT_NO_EMPTY);
        if (count($patternParts) > 1) {
            $allPartsFound = true;
            foreach ($patternParts as $part) {
                if (strlen($part) > 1 && !str_contains($lowerPath, $part)) {
                    $allPartsFound = false;
                    break;
                }
            }
            if ($allPartsFound) {
                return true;
            }
        }
        
        // Fuzzy match: check if characters appear in order
        return $this->fuzzyMatch($lowerPath, $lowerPattern);
    }

    /**
     * Fuzzy string matching - checks if pattern characters appear in order in text.
     */
    private function fuzzyMatch(string $text, string $pattern): bool {
        $textLen = strlen($text);
        $patternLen = strlen($pattern);
        $textIdx = 0;
        $patternIdx = 0;

        while ($textIdx < $textLen && $patternIdx < $patternLen) {
            if ($text[$textIdx] === $pattern[$patternIdx]) {
                $patternIdx++;
            }
            $textIdx++;
        }

        return $patternIdx === $patternLen;
    }

    /**
     * Reads and returns the content of $path (relative to projectRoot).
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
     * Writes $content to $path (relative to projectRoot).
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
        $backupDir = $this->config->getLutinDir() . '/backups';
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
        $backupDir = $this->config->getLutinDir() . '/backups';
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
     * Restores $backupAbsolutePath to its original file location (relative to projectRoot).
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
        
        // Reconstruct the original path relative to projectRoot
        // We need to find where this file currently exists or would exist
        $originalPath = $this->findOriginalPath($originalName);
        
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
     * Find the original file path for a restored file.
     * Tries to locate the file relative to project root, falling back to web root.
     * Returns the absolute path where the file should be restored.
     */
    private function findOriginalPath(string $originalName): string {
        // First try: look for the file in projectRoot
        $projectPath = $this->config->getProjectRoot() . '/' . $originalName;
        if (file_exists($projectPath)) {
            return $projectPath;
        }
        
        // Second try: look in webRoot (for backwards compatibility)
        $webPath = $this->config->getWebRoot() . '/' . $originalName;
        if (file_exists($webPath)) {
            return $webPath;
        }
        
        // If not found, restore to projectRoot by default
        // But we need to validate this path is safe
        return $this->safePath($originalName);
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

        // Filter to existing files - check relative to webRoot only for URL mapping
        foreach ($attempts as $candidate) {
            $fullPath = $this->config->getWebRoot() . '/' . $candidate;
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
     * - src/        → Goes to sibling of lutin directory
     * - data/       → Goes to sibling of lutin directory  
     * - lutin/      → Goes to sibling of lutin directory
     * - other dirs  → Goes to sibling of lutin directory
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
     * - everything else → sibling of lutin directory
     */
    private function copyTemplateFiles(string $templateRoot): void {
        // Copy public/ to web root
        $publicDir = $templateRoot . '/public';
        if (is_dir($publicDir)) {
            $this->recursiveCopy($publicDir, $this->config->getWebRoot());
        }

        // Copy other directories to sibling of lutin directory (i.e., project root)
        $privateRoot = dirname($this->config->getLutinDir());
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
