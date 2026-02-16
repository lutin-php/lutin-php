<?php
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
