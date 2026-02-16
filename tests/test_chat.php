<?php
// tests/test_chat.php — Tests for chat functionality
declare(strict_types=1);

// Change to repo root for relative paths
chdir(__DIR__ . '/..');

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/../src/classes/LutinConfig.php';
require_once __DIR__ . '/../src/classes/LutinAuth.php';
require_once __DIR__ . '/../src/classes/LutinFileManager.php';
require_once __DIR__ . '/../src/classes/LutinAgent.php';
require_once __DIR__ . '/../src/classes/LutinView.php';
require_once __DIR__ . '/../src/classes/LutinRouter.php';

// ── Helpers ────────────────────────────────────────────────────────────────
function assert_true(bool $val, string $label): void {
    if (!$val) {
        throw new \Exception("Assertion failed: {$label} (expected true, got false)");
    }
}

// Scratch dir
$scratch = sys_get_temp_dir() . '/lutin_chat_test_' . getmypid();
if (is_dir($scratch)) {
    shell_exec('rm -rf ' . escapeshellarg($scratch));
}
mkdir($scratch, 0700, true);
register_shutdown_function(fn() => @shell_exec('rm -rf ' . escapeshellarg($scratch)));

// ── Test suite ─────────────────────────────────────────────────────────────
$tests = [];

// Test: AnthropicAdapter instantiation
$tests['LutinAgent::AnthropicAdapter (instantiation)'] = function() {
    $adapter = new AnthropicAdapter('test-key-123', 'claude-3-5-haiku-20241022');
    assert_true($adapter instanceof LutinProviderAdapter, 'AnthropicAdapter should implement LutinProviderAdapter');
};

// Test: OpenAIAdapter instantiation
$tests['LutinAgent::OpenAIAdapter (instantiation)'] = function() {
    $adapter = new OpenAIAdapter('sk-test-key', 'gpt-4');
    assert_true($adapter instanceof LutinProviderAdapter, 'OpenAIAdapter should implement LutinProviderAdapter');
};

// Test: LutinAgent instantiation with Anthropic
$tests['LutinAgent::instantiation (Anthropic)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site1';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    mkdir($lutinDir, 0700, true);
    $config = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $config->setPasswordHash(password_hash('test', PASSWORD_BCRYPT));
    $config->setProvider('anthropic');
    $config->setApiKey('test-api-key');
    $config->setModel('claude-3-5-haiku-20241022');
    $config->save();

    $fm = new LutinFileManager($config);
    $agent = new LutinAgent($config, $fm);
    assert_true($agent instanceof LutinAgent, 'LutinAgent should instantiate');
};

// Test: LutinAgent instantiation with OpenAI
$tests['LutinAgent::instantiation (OpenAI)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site2';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    mkdir($lutinDir, 0700, true);
    $config = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $config->setPasswordHash(password_hash('test', PASSWORD_BCRYPT));
    $config->setProvider('openai');
    $config->setApiKey('sk-test-key');
    $config->setModel('gpt-4');
    $config->save();

    $fm = new LutinFileManager($config);
    $agent = new LutinAgent($config, $fm);
    assert_true($agent instanceof LutinAgent, 'LutinAgent should instantiate with OpenAI');
};

// Test: Chat method exists
$tests['LutinAgent::chat (method exists)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site3';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    mkdir($lutinDir, 0700, true);
    $config = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $config->setPasswordHash(password_hash('test', PASSWORD_BCRYPT));
    $config->setProvider('anthropic');
    $config->setApiKey('test-api-key');
    $config->setModel('claude-3-5-haiku-20241022');
    $config->save();

    $fm = new LutinFileManager($config);
    $agent = new LutinAgent($config, $fm);

    assert_true(method_exists($agent, 'chat'), 'LutinAgent should have chat method');
};

// Test: Router initializes with all dependencies
$tests['LutinRouter::initialization'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site4';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    mkdir($lutinDir, 0700, true);
    $config = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $config->setPasswordHash(password_hash('test', PASSWORD_BCRYPT));
    $config->setProvider('anthropic');
    $config->setApiKey('test-api-key');
    $config->setModel('claude-3-5-haiku-20241022');
    $config->save();

    $auth = new LutinAuth($config);
    $auth->startSession();
    $fm = new LutinFileManager($config);
    $view = new LutinView($config, $auth);

    $router = new LutinRouter($config, $auth, $fm, null, $view);
    assert_true($router instanceof LutinRouter, 'Router should instantiate with all dependencies');
};

// Test: handleChat method exists
$tests['LutinRouter::handleChat (exists)'] = function() use ($scratch) {
    $projectRoot = $scratch . '/site5';
    $webRoot = $projectRoot;
    $lutinDir = $projectRoot . '/lutin';
    mkdir($projectRoot, 0700, true);
    mkdir($lutinDir, 0700, true);
    $config = new LutinConfig($projectRoot, $webRoot, $lutinDir);
    $config->setPasswordHash(password_hash('test', PASSWORD_BCRYPT));
    $config->setProvider('anthropic');
    $config->setApiKey('test-api-key');
    $config->setModel('claude-3-5-haiku-20241022');
    $config->save();

    $auth = new LutinAuth($config);
    $auth->startSession();

    $fm = new LutinFileManager($config);
    $view = new LutinView($config, $auth);

    $reflection = new ReflectionClass(new LutinRouter($config, $auth, $fm, null, $view));
    assert_true($reflection->hasMethod('handleChat'), 'Router should have handleChat method');
};

// Test: AnthropicAdapter stream returns generator
$tests['LutinAgent::AnthropicAdapter::stream (returns generator)'] = function() {
    $adapter = new AnthropicAdapter('test-key-123', 'claude-3-5-haiku-20241022');
    $messages = [['role' => 'user', 'content' => 'hello']];
    $tools = [];

    $result = $adapter->stream($messages, $tools);
    assert_true($result instanceof \Generator, 'stream() should return a Generator');
};

// Test: OpenAIAdapter stream returns generator
$tests['LutinAgent::OpenAIAdapter::stream (returns generator)'] = function() {
    $adapter = new OpenAIAdapter('sk-test-key', 'gpt-4');
    $messages = [['role' => 'user', 'content' => 'hello']];
    $tools = [];

    $result = $adapter->stream($messages, $tools);
    assert_true($result instanceof \Generator, 'stream() should return a Generator');
};

// ── Runner ─────────────────────────────────────────────────────────────────
$passed = 0;
$failed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo str_pad($name, 70, '.') . " PASS\n";
        $passed++;
    } catch (\Throwable $e) {
        echo str_pad($name, 70, '.') . " FAIL\n";
        echo "  " . $e->getMessage() . "\n";
        $failed++;
    }
}
echo "\n";
if ($failed === 0) {
    echo "All {$passed} chat tests passed.\n";
    exit(0);
} else {
    echo "{$failed} test(s) FAILED, {$passed} passed.\n";
    exit(1);
}
