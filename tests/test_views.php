<?php
// tests/test_views.php — Unit tests for view rendering
declare(strict_types=1);

// Change to repo root for relative paths
chdir(__DIR__ . '/..');

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/../src/classes/LutinConfig.php';
require_once __DIR__ . '/../src/classes/LutinAuth.php';
require_once __DIR__ . '/../src/classes/LutinView.php';

// ── Helpers ────────────────────────────────────────────────────────────────
function assert_contains(string $haystack, string $needle, string $label): void {
    if (strpos($haystack, $needle) === false) {
        throw new \Exception("Assertion failed: {$label}\nExpected to find: {$needle}\nIn: " . substr($haystack, 0, 200) . "...");
    }
}

function assert_not_contains(string $haystack, string $needle, string $label): void {
    if (strpos($haystack, $needle) !== false) {
        throw new \Exception("Assertion failed: {$label}\nShould NOT find: {$needle}\nBut found in: " . substr($haystack, 0, 200) . "...");
    }
}

function assert_true(bool $val, string $label): void {
    if (!$val) {
        throw new \Exception("Assertion failed: {$label} (expected true, got false)");
    }
}

// Scratch dir — fresh for each run, deleted at shutdown
$scratch = sys_get_temp_dir() . '/lutin_view_test_' . getmypid();
if (is_dir($scratch)) {
    shell_exec('rm -rf ' . escapeshellarg($scratch));
}
mkdir($scratch, 0700, true);
register_shutdown_function(fn() => @shell_exec('rm -rf ' . escapeshellarg($scratch)));

// ── Test suite ─────────────────────────────────────────────────────────────
$tests = [];

// Test: Setup wizard shows tab-setup visible
$tests['LutinView::renderSetupWizard (tab visible)'] = function() use ($scratch) {
    $webRoot = $scratch . '/site1/web';
    $dataDir = $scratch . '/site1/lutin';
    mkdir($webRoot, 0700, true);
    mkdir($dataDir, 0700, true);
    $config = new LutinConfig($webRoot, $dataDir);
    $auth = new LutinAuth($config);
    $auth->startSession();
    $view = new LutinView($config, $auth);

    ob_start();
    $view->renderSetupWizard();
    $html = ob_get_clean();

    // Check tab-setup is in HTML
    assert_contains($html, 'id="tab-setup"', 'tab-setup element should be in HTML');

    // Check CSS shows tab-setup
    assert_contains($html, '#tab-setup { display: block', 'CSS should show tab-setup with display: block');
};

// Test: Login page shows tab-login visible
$tests['LutinView::renderLogin (tab visible)'] = function() use ($scratch) {
    $webRoot = $scratch . '/site2/web';
    $dataDir = $scratch . '/site2/lutin';
    mkdir($webRoot, 0700, true);
    mkdir($dataDir, 0700, true);
    $config = new LutinConfig($webRoot, $dataDir);
    $auth = new LutinAuth($config);
    $auth->startSession();
    $view = new LutinView($config, $auth);

    ob_start();
    $view->renderLogin();
    $html = ob_get_clean();

    // Check tab-login is in HTML
    assert_contains($html, 'id="tab-login"', 'tab-login element should be in HTML');

    // Check CSS shows tab-login
    assert_contains($html, '#tab-login { display: block', 'CSS should show tab-login with display: block');
};

// Test: App page shows tab-chat visible (and others hidden)
$tests['LutinView::renderApp (tab-chat visible)'] = function() use ($scratch) {
    $webRoot = $scratch . '/site3/web';
    $dataDir = $scratch . '/site3/lutin';
    mkdir($webRoot, 0700, true);
    mkdir($dataDir, 0700, true);
    $config = new LutinConfig($webRoot, $dataDir);
    $config->setPasswordHash(password_hash('test', PASSWORD_BCRYPT));
    $config->setProvider('anthropic');
    $config->setApiKey('sk-test');
    $config->save();

    $auth = new LutinAuth($config);
    $auth->startSession();
    $view = new LutinView($config, $auth);

    ob_start();
    $view->renderApp();
    $html = ob_get_clean();

    // Check all tabs are in HTML
    assert_contains($html, 'id="tab-chat"', 'tab-chat element should be in HTML');
    assert_contains($html, 'id="tab-editor"', 'tab-editor element should be in HTML');
    assert_contains($html, 'id="tab-config"', 'tab-config element should be in HTML');

    // Check CSS shows only tab-chat initially
    assert_contains($html, '#tab-chat { display: block', 'CSS should show tab-chat with display: block');

    // Check general section hiding rule
    assert_contains($html, 'section { display: none', 'CSS should hide all sections by default');
};

// Test: Setup wizard doesn't show other tabs
$tests['LutinView::renderSetupWizard (other tabs hidden)'] = function() use ($scratch) {
    $webRoot = $scratch . '/site4/web';
    $dataDir = $scratch . '/site4/lutin';
    mkdir($webRoot, 0700, true);
    mkdir($dataDir, 0700, true);
    $config = new LutinConfig($webRoot, $dataDir);
    $auth = new LutinAuth($config);
    $auth->startSession();
    $view = new LutinView($config, $auth);

    ob_start();
    $view->renderSetupWizard();
    $html = ob_get_clean();

    // Setup wizard should NOT have chat, editor, or config tabs
    assert_not_contains($html, 'id="tab-chat"', 'tab-chat should NOT be in setup wizard');
    assert_not_contains($html, 'id="tab-editor"', 'tab-editor should NOT be in setup wizard');
    assert_not_contains($html, 'id="tab-config"', 'tab-config should NOT be in setup wizard');
};

// Test: CSRF token is present
$tests['LutinView::renderSetupWizard (CSRF token present)'] = function() use ($scratch) {
    $webRoot = $scratch . '/site5/web';
    $dataDir = $scratch . '/site5/lutin';
    mkdir($webRoot, 0700, true);
    mkdir($dataDir, 0700, true);
    $config = new LutinConfig($webRoot, $dataDir);
    $auth = new LutinAuth($config);
    $auth->startSession();
    $view = new LutinView($config, $auth);

    ob_start();
    $view->renderSetupWizard();
    $html = ob_get_clean();

    // Check CSRF token meta tag
    assert_contains($html, 'meta name="lutin-token"', 'CSRF token meta tag should be present');
};

// Test: CodeMirror libraries are loaded
$tests['LutinView::renderApp (CodeMirror loaded)'] = function() use ($scratch) {
    $webRoot = $scratch . '/site6/web';
    $dataDir = $scratch . '/site6/lutin';
    mkdir($webRoot, 0700, true);
    mkdir($dataDir, 0700, true);
    $config = new LutinConfig($webRoot, $dataDir);
    $config->setPasswordHash(password_hash('test', PASSWORD_BCRYPT));
    $config->setProvider('anthropic');
    $config->setApiKey('sk-test');
    $config->save();

    $auth = new LutinAuth($config);
    $auth->startSession();
    $view = new LutinView($config, $auth);

    ob_start();
    $view->renderApp();
    $html = ob_get_clean();

    // Check CodeMirror CSS and JS are loaded
    assert_contains($html, 'codemirror.min.css', 'CodeMirror CSS should be loaded');
    assert_contains($html, 'codemirror.min.js', 'CodeMirror JS should be loaded');
};

// Test: App page shows nav (app tabs only)
$tests['LutinView::renderApp (nav visible)'] = function() use ($scratch) {
    $webRoot = $scratch . '/site7/web';
    $dataDir = $scratch . '/site7/lutin';
    mkdir($webRoot, 0700, true);
    mkdir($dataDir, 0700, true);
    $config = new LutinConfig($webRoot, $dataDir);
    $config->setPasswordHash(password_hash('test', PASSWORD_BCRYPT));
    $config->setProvider('anthropic');
    $config->setApiKey('sk-test');
    $config->save();

    $auth = new LutinAuth($config);
    $auth->startSession();
    $view = new LutinView($config, $auth);

    ob_start();
    $view->renderApp();
    $html = ob_get_clean();

    // Check nav is present with app tabs
    assert_contains($html, '<nav>', 'nav element should be in app');
    assert_contains($html, 'href="#chat"', 'chat link should be in nav');
    assert_contains($html, 'href="#editor"', 'editor link should be in nav');
    assert_contains($html, 'href="#config"', 'config link should be in nav');
};

// Test: Setup wizard doesn't show nav (not an app page)
$tests['LutinView::renderSetupWizard (nav hidden)'] = function() use ($scratch) {
    $webRoot = $scratch . '/site8/web';
    $dataDir = $scratch . '/site8/lutin';
    mkdir($webRoot, 0700, true);
    mkdir($dataDir, 0700, true);
    $config = new LutinConfig($webRoot, $dataDir);
    $auth = new LutinAuth($config);
    $auth->startSession();
    $view = new LutinView($config, $auth);

    ob_start();
    $view->renderSetupWizard();
    $html = ob_get_clean();

    // Check nav is NOT present in setup wizard (unless explicitly checking for it)
    // We check that app nav links are not there
    assert_not_contains($html, 'href="#chat"', 'chat link should NOT be in setup wizard');
    assert_not_contains($html, 'href="#editor"', 'editor link should NOT be in setup wizard');
    assert_not_contains($html, 'href="#config"', 'config link should NOT be in setup wizard');
};

// Test: Login page doesn't show nav (not an app page)
$tests['LutinView::renderLogin (nav hidden)'] = function() use ($scratch) {
    $webRoot = $scratch . '/site9/web';
    $dataDir = $scratch . '/site9/lutin';
    mkdir($webRoot, 0700, true);
    mkdir($dataDir, 0700, true);
    $config = new LutinConfig($webRoot, $dataDir);
    $auth = new LutinAuth($config);
    $auth->startSession();
    $view = new LutinView($config, $auth);

    ob_start();
    $view->renderLogin();
    $html = ob_get_clean();

    // Check nav is NOT present in login page
    assert_not_contains($html, 'href="#chat"', 'chat link should NOT be in login');
    assert_not_contains($html, 'href="#editor"', 'editor link should NOT be in login');
    assert_not_contains($html, 'href="#config"', 'config link should NOT be in login');
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
    echo "All {$passed} view tests passed.\n";
    exit(0);
} else {
    echo "{$failed} test(s) FAILED, {$passed} passed.\n";
    exit(1);
}
