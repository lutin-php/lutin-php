<?php
// scripts/build.php — Build script to concatenate all source parts into dist/lutin.php
declare(strict_types=1);

// Change to repo root
chdir(__DIR__ . '/..');

// File order for class concatenation
$classFiles = [
    'src/classes/LutinConfig.php',
    'src/classes/LutinAuth.php',
    'src/classes/LutinFileManager.php',
    'src/classes/LutinAgent.php',
    'src/classes/LutinRouter.php',
    'src/classes/LutinView.php',
];

$viewFiles = [
    'layout'        => 'src/views/layout.php',
    'setup_wizard'  => 'src/views/setup_wizard.php',
    'login'         => 'src/views/login.php',
    'tab_chat'      => 'src/views/tab_chat.php',
    'tab_editor'    => 'src/views/tab_editor.php',
    'tab_config'    => 'src/views/tab_config.php',
    'tab_templates' => 'src/views/tab_templates.php',
];

$jsFile    = 'src/assets/app.js';
$entryFile = 'src/index.php';
$outputFile = 'dist/lutin.php';

// Helper: strip opening <?php tag and declare statements (keep only for first file)
function stripOpenTag(string $content, bool $isFirstFile = false): string {
    // Remove opening <?php tag
    $content = preg_replace('/^<\?php\s*\n?/', '', $content, 1);

    // If not the first file, also remove declare(strict_types=1) which must be first
    if (!$isFirstFile) {
        $content = preg_replace('/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*\n?/', '', $content, 1);
    }

    return $content;
}

$out = "<?php\n";
$out .= "declare(strict_types=1);\n";
$out .= "// Lutin.php v" . trim(file_get_contents('VERSION')) . "\n";
$out .= "// Built: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Inline classes
$isFirstClass = true;
foreach ($classFiles as $file) {
    if (!file_exists($file)) {
        fwrite(STDERR, "Warning: {$file} does not exist, skipping\n");
        continue;
    }
    $out .= "// ── " . basename($file) . " ─────\n";
    $out .= stripOpenTag(file_get_contents($file), $isFirstClass) . "\n";
    $isFirstClass = false;
}

// 2. Inline JS as PHP const
if (file_exists($jsFile)) {
    $out .= "const LUTIN_JS = <<<'LUTINJS'\n";
    $out .= file_get_contents($jsFile) . "\n";
    $out .= "LUTINJS;\n\n";
}

// 3. Inline views as PHP consts
foreach ($viewFiles as $name => $file) {
    if (!file_exists($file)) {
        fwrite(STDERR, "Warning: {$file} does not exist, skipping\n");
        continue;
    }
    $constName = 'LUTIN_VIEW_' . strtoupper($name);
    $out .= "const {$constName} = <<<'LUTINVIEW'\n";
    $out .= file_get_contents($file) . "\n";
    $out .= "LUTINVIEW;\n\n";
}

// 4. Entry point
if (file_exists($entryFile)) {
    $out .= stripOpenTag(file_get_contents($entryFile)) . "\n";
}

if (!is_dir('dist')) mkdir('dist');
file_put_contents($outputFile, $out);
echo "Built: {$outputFile} (" . filesize($outputFile) . " bytes)\n";
