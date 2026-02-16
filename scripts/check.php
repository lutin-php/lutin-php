<?php
// scripts/check.php — Boot probe script
// Runs only from CLI
if (php_sapi_name() !== 'cli') exit(1);

// Change to repo root
$root = __DIR__ . '/..';
chdir($root);

$port    = rand(18000, 19000);
$docroot = $root . '/dist';
$pidFile = sys_get_temp_dir() . '/lutin_check.pid';

// 1. Start built-in server
$cmd = sprintf(
    'php -S localhost:%d -t %s %s/dist/lutin.php > /dev/null 2>&1 & echo $!',
    $port, escapeshellarg($docroot), escapeshellarg($root)
);
$pid = trim(shell_exec($cmd));
file_put_contents($pidFile, $pid);
usleep(500_000); // wait 0.5s for server to start

// 2. Send GET request via wget if available, fall back to file_get_contents
$url = "http://localhost:{$port}/";
$body = '';
$code = 0;

if (function_exists('curl_init')) {
    // Use curl if available
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
} else {
    // Fall back to stream wrapper
    try {
        $body = file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 5]
        ]));
        $code = 200;
    } catch (\Throwable) {
        $body = '';
        $code = 0;
    }
}

// 3. Kill server
@posix_kill((int)$pid, SIGTERM);
sleep(1); // wait for process to die
@unlink($pidFile);

// 4. Assert
$ok = ($code === 200 && str_contains($body, 'lutin-token'));
echo $ok
    ? "check.php: PASS (HTTP {$code}, lutin-token found)\n"
    : "check.php: FAIL (HTTP {$code})\n" . substr($body, 0, 500) . "\n";
exit($ok ? 0 : 1);
