<?php
// tests/test_core.php — Lutin unit tests (no Composer)
declare(strict_types=1);

// Change to repo root for relative paths
chdir(__DIR__ . '/..');

// ── Bootstrap ──────────────────────────────────────────────────────────────
// Require classes directly (not the compiled dist file)
require_once __DIR__ . '/../src/classes/LutinConfig.php';
require_once __DIR__ . '/../src/classes/LutinAuth.php';
require_once __DIR__ . '/../src/classes/LutinFileManager.php';

// ── Helpers ────────────────────────────────────────────────────────────────
function assert_eq(mixed $actual, mixed $expected, string $label): void {
    if ($actual !== $expected) {
        throw new \Exception("Assertion failed: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assert_true(bool $val, string $label): void {
    if (!$val) {
        throw new \Exception("Assertion failed: {$label} (expected true, got false)");
    }
}

function assert_throws(callable $fn, string $exClass, string $label): void {
    try {
        $fn();
        throw new \Exception("Assertion failed: {$label} (expected {$exClass} to be thrown, but none was)");
    } catch (\Throwable $e) {
        if (!($e instanceof $exClass)) {
            throw new \Exception("Assertion failed: {$label} (expected {$exClass}, got " . get_class($e) . ": {$e->getMessage()})");
        }
    }
}

// Scratch dir — fresh for each run, deleted at shutdown
$scratch = sys_get_temp_dir() . '/lutin_test_' . getmypid();
if (is_dir($scratch)) {
    shell_exec('rm -rf ' . escapeshellarg($scratch));
}
mkdir($scratch, 0700, true);
register_shutdown_function(fn() => @shell_exec('rm -rf ' . escapeshellarg($scratch)));

// ── Test suite ─────────────────────────────────────────────────────────────
$tests = [];

// Test: LutinConfig — isFirstRun when no file
$tests['LutinConfig::isFirstRun (no file)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site1';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    $cfg = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    assert_true($cfg->isFirstRun(), 'isFirstRun should be true when config missing');
};

// Test: LutinConfig — isFirstRun after save
$tests['LutinConfig::isFirstRun (after save)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site2';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    $cfg = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $cfg->setPasswordHash(password_hash('secret', PASSWORD_BCRYPT));
    $cfg->setProvider('anthropic');
    $cfg->setApiKey('sk-test');
    $cfg->save();
    $cfg2 = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $cfg2->load();
    assert_true(!$cfg2->isFirstRun(), 'isFirstRun should be false after save');
};

// Test: LutinFileManager::safePath — path escape attempt
$tests['LutinFileManager::safePath (escape attempt)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site3';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    $cfg = new LutinConfig($projectRoot, $lutinDir);
    $fm  = new LutinFileManager($cfg);
    assert_throws(
        fn() => $fm->safePath('../../etc/passwd'),
        \RuntimeException::class,
        'safePath must throw on path escape'
    );
};

// Test: LutinFileManager::safePath — blocks lutin.php
$tests['LutinFileManager::safePath (protected lutin.php)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site4';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    touch($projectRoot . '/lutin.php');
    $cfg = new LutinConfig($projectRoot, $lutinDir);
    $fm  = new LutinFileManager($cfg);
    assert_throws(
        fn() => $fm->safePath('lutin.php'),
        \RuntimeException::class,
        'safePath must throw for lutin.php'
    );
};

// Test: LutinFileManager::safePath — blocks lutin directory
$tests['LutinFileManager::safePath (protected lutin dir)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site4b';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    mkdir($lutinDir, 0700, true);
    file_put_contents($lutinDir . '/config.json', '{"test": true}');
    $cfg = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $fm  = new LutinFileManager($cfg);
    assert_throws(
        fn() => $fm->safePath('lutin/config.json'),
        \RuntimeException::class,
        'safePath must throw for lutin directory'
    );
};

// Test: LutinFileManager::writeFile — creates backup before overwrite
$tests['LutinFileManager::writeFile (backup created)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site5';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    mkdir($lutinDir . '/backups', 0700, true);
    // Create file in project root
    file_put_contents($projectRoot . '/index.php', '<?php echo "v1";');
    $cfg = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $fm  = new LutinFileManager($cfg);
    // Write using path relative to project root
    $fm->writeFile('index.php', '<?php echo "v2";');
    // Backup dir should contain exactly one file
    $backups = glob($lutinDir . '/backups/*index.php');
    assert_true(count($backups) === 1, 'One backup should exist after writeFile');
    assert_true(str_contains(file_get_contents($backups[0]), 'v1'), 'Backup must contain original content');
    assert_true(file_get_contents($projectRoot . '/index.php') === '<?php echo "v2";', 'Live file must contain new content');
};

// Test: LutinFileManager::urlToFile — heuristic mapping
$tests['LutinFileManager::urlToFile (basic heuristics)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site6';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($webRoot . '/pages', 0700, true);
    mkdir($lutinDir, 0700, true);
    touch($webRoot . '/pages/about.php');
    touch($webRoot . '/about.php');
    $cfg = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $fm  = new LutinFileManager($cfg);
    $candidates = $fm->urlToFile('https://example.com/about');
    assert_true(count($candidates) >= 1, 'urlToFile must find at least one candidate');
    assert_true(in_array('about.php', $candidates) || in_array('pages/about.php', $candidates),
        'urlToFile must include about.php or pages/about.php');
};

// Test: LutinAgent — AGENTS.md from lutin directory is included in system prompt
$tests['LutinAgent::buildSystemPrompt (with AGENTS.md)'] = function() use ($scratch) {
    require_once __DIR__ . '/../src/agent_providers/LutinProviderAdapter.php';
    require_once __DIR__ . '/../src/agent_providers/AnthropicAdapter.php';
    require_once __DIR__ . '/../src/agent_providers/OpenAIAdapter.php';
    require_once __DIR__ . '/../src/agents/LutinAgent.php';
    require_once __DIR__ . '/../src/agents/LutinChatAgent.php';
    
    $projectRoot = $scratch . '/site7';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    mkdir($lutinDir, 0700, true);
    
    // Create AGENTS.md in lutin directory
    file_put_contents($lutinDir . '/AGENTS.md', "# Project Guidelines\n\nUse Tailwind CSS.");
    
    // Create a mock config with required values
    $cfg = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $cfg->setProvider('anthropic');
    $cfg->setApiKey('sk-test');
    
    $fm = new LutinFileManager($cfg);
    $agent = new LutinChatAgent($cfg, $fm);
    
    // Use reflection to test the private method
    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('buildSystemPrompt');
    $method->setAccessible(true);
    $systemPrompt = $method->invoke($agent);
    
    assert_true(str_contains($systemPrompt, 'You are Lutin'), 'Base prompt should be present');
    assert_true(str_contains($systemPrompt, 'AGENTS.md'), 'AGENTS.md reference should be present');
    assert_true(str_contains($systemPrompt, 'Tailwind CSS'), 'AGENTS.md content should be included');
};

// Test: LutinAgent — buildSystemPrompt works without AGENTS.md
$tests['LutinAgent::buildSystemPrompt (without AGENTS.md)'] = function() use ($scratch) {
    require_once __DIR__ . '/../src/agent_providers/LutinProviderAdapter.php';
    require_once __DIR__ . '/../src/agent_providers/AnthropicAdapter.php';
    require_once __DIR__ . '/../src/agent_providers/OpenAIAdapter.php';
    require_once __DIR__ . '/../src/agents/LutinAgent.php';
    require_once __DIR__ . '/../src/agents/LutinChatAgent.php';
    
    $projectRoot = $scratch . '/site8';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    mkdir($lutinDir, 0700, true);
    
    // No AGENTS.md file
    
    $cfg = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $cfg->setProvider('anthropic');
    $cfg->setApiKey('sk-test');
    
    $fm = new LutinFileManager($cfg);
    $agent = new LutinChatAgent($cfg, $fm);
    
    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('buildSystemPrompt');
    $method->setAccessible(true);
    $systemPrompt = $method->invoke($agent);
    
    assert_true(str_contains($systemPrompt, 'You are Lutin'), 'Base prompt should be present');
    assert_true(!str_contains($systemPrompt, 'AGENTS.md:'), 'AGENTS.md section should not be present');
};

// ── Runner ─────────────────────────────────────────────────────────────────
$passed = 0;
$failed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo str_pad($name, 55, '.') . " PASS\n";
        $passed++;
    } catch (\Throwable $e) {
        echo str_pad($name, 55, '.') . " FAIL\n";
        echo "  " . $e->getMessage() . "\n";
        $failed++;
    }
}
echo "\n";
if ($failed === 0) {
    echo "All {$passed} tests passed.\n";
    exit(0);
} else {
    echo "{$failed} test(s) FAILED, {$passed} passed.\n";
    exit(1);
}
