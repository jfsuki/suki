<?php
// app/autoload.php
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('FRAMEWORK_ROOT')) {
    define('FRAMEWORK_ROOT', APP_ROOT);
}
if (!defined('PROJECT_ROOT')) {
    $workspaceRoot = dirname(APP_ROOT);
    define('PROJECT_ROOT', $workspaceRoot . '/project');
}

spl_autoload_register(function ($class) {
    $baseName = basename(str_replace('\\', '/', $class));

    $candidates = [
        FRAMEWORK_ROOT . '/app/Core/' . $baseName . '.php',
        FRAMEWORK_ROOT . '/app/Core/Agents/' . $baseName . '.php',
        FRAMEWORK_ROOT . '/app/Core/LLM/' . $baseName . '.php',
        FRAMEWORK_ROOT . '/app/Core/LLM/Providers/' . $baseName . '.php',
        FRAMEWORK_ROOT . '/app/Jobs/' . $baseName . '.php',
        FRAMEWORK_ROOT . '/app/controller/' . $baseName . '.php',
        PROJECT_ROOT . '/app/controller/' . $baseName . '.php',
    ];

    foreach ($candidates as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
