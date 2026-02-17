<?php
// Define root directories
// LUTIN_ROOT: directory containing lutin.php (web root)
// LUTIN_PROJECT_ROOT: directory for the project (configurable, defaults to parent of LUTIN_ROOT)
// LUTIN_LUTIN_DIR: directory for Lutin's internal files (auto-created as projectRoot/lutin)
define('LUTIN_ROOT', __DIR__);
define('LUTIN_PROJECT_ROOT', getenv('LUTIN_PROJECT_ROOT') ?: dirname(__DIR__));
define('LUTIN_LUTIN_DIR', getenv('LUTIN_LUTIN_DIR') ?: LUTIN_PROJECT_ROOT . '/lutin');
define('LUTIN_VERSION', '1.0.0');

// In dev: require_once each class file.
// (In dist build, all classes are already inlined above this block.)
if (!class_exists('LutinConfig')) {
    require_once __DIR__ . '/classes/LutinConfig.php';
    require_once __DIR__ . '/classes/LutinAuth.php';
    require_once __DIR__ . '/classes/LutinFileManager.php';
    // Agent provider adapters
    require_once __DIR__ . '/agent_providers/LutinProviderAdapter.php';
    require_once __DIR__ . '/agent_providers/AnthropicAdapter.php';
    require_once __DIR__ . '/agent_providers/OpenAIAdapter.php';
    // Agent classes
    require_once __DIR__ . '/agents/AbstractLutinAgent.php';
    require_once __DIR__ . '/agents/LutinChatAgent.php';
    require_once __DIR__ . '/agents/LutinEditorAgent.php';
    require_once __DIR__ . '/classes/LutinRouter.php';
    require_once __DIR__ . '/classes/LutinView.php';
}

// Bootstrap - pass current directories as defaults, config will override if saved
$config = new LutinConfig(LUTIN_PROJECT_ROOT, LUTIN_ROOT, LUTIN_LUTIN_DIR);
$config->load();

$auth = new LutinAuth($config);
$auth->startSession();

// Use the configured web root from config (which may have been loaded from config.json)
$fm    = new LutinFileManager($config);
$view  = new LutinView($config, $auth);

// Agent is initialized lazily by router when needed
$router = new LutinRouter($config, $auth, $fm, null, $view);
$router->dispatch();
