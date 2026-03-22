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

$projectEnvLoader = PROJECT_ROOT . '/config/env_loader.php';
if (is_file($projectEnvLoader)) {
    require_once $projectEnvLoader;
}

spl_autoload_register(function ($class) {
    // 1. PSR-4 direct mapping (Primary)
    if (str_starts_with($class, 'App\\')) {
        $path = str_replace('\\', '/', substr($class, 4));
        $file = FRAMEWORK_ROOT . '/app/' . $path . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }

    // 2. Legacy Flat Candidates (Fallback)
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
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
