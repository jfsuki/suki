<?php
// Diagnóstico del orden de proveedores del LLMRouter
define('PROJECT_ROOT', 'c:/laragon/www/suki/project');
require_once PROJECT_ROOT . '/config/env_loader.php';
$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);
require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/app/autoload.php';

$llm = new \App\Core\LLM\LLMRouter();

// Acceder mediante Reflection al orden real de proveedores
$ref = new ReflectionClass($llm);

$config = $ref->getProperty('config');
$config->setAccessible(true);
$cfg = $config->getValue($llm);

echo "=== CONFIG PROVEEDORES ===\n";
foreach ($cfg['providers'] ?? [] as $name => $provider) {
    $enabled = !empty($provider['enabled']);
    $class = $provider['class'] ?? 'NO_CLASS';
    echo ($enabled ? '✓' : '✗') . " $name → $class\n";
}

echo "\n=== ENV KEYS ===\n";
echo "MISTRAL_API_KEY: " . (getenv('MISTRAL_API_KEY') ? 'SET (' . strlen(getenv('MISTRAL_API_KEY')) . ' chars)' : '(empty)') . "\n";
echo "OPENROUTER_API_KEY: " . (getenv('OPENROUTER_API_KEY') ? 'SET' : '(empty)') . "\n";
echo "DEEPSEEK_API_KEY: " . (getenv('DEEPSEEK_API_KEY') ? 'SET' : '(empty)') . "\n";

echo "\n=== PROVEEDOR PRIMARIO ===\n";
echo "LLM_PRIMARY_PROVIDER: " . (getenv('LLM_PRIMARY_PROVIDER') ?: '(not set)') . "\n";
echo "LLM_ROUTER_MODE: " . (getenv('LLM_ROUTER_MODE') ?: '(not set)') . "\n";

echo "\n=== LLM CONFIG FILE ===\n";
$cfgFile = FRAMEWORK_ROOT . '/config/llm.php';
if (file_exists($cfgFile)) {
    echo "FILE EXISTS: YES\n";
    $fileCfg = require $cfgFile;
    echo "routing.primary: " . ($fileCfg['routing']['primary'] ?? 'not found') . "\n";
    echo "providers: " . implode(', ', array_keys($fileCfg['providers'] ?? [])) . "\n";
    foreach ($fileCfg['providers'] ?? [] as $name => $prov) {
        $enabled = !empty($prov['enabled']);
        echo ($enabled ? '✓' : '✗') . " $name\n";
    }
} else {
    echo "FILE EXISTS: NO (usando config fallback hardcodeada)\n";
}

echo "\n=== TEST MISTRAL DIRECTO ===\n";
try {
    $mistralClass = 'App\\Core\\LLM\\Providers\\MistralProvider';
    if (!class_exists($mistralClass)) {
        echo "ERROR: Clase MistralProvider no encontrada\n";
    } else {
        $mistral = new $mistralClass($cfg);
        $result = $mistral->sendChat([
            ['role' => 'user', 'content' => 'di hola en 3 palabras']
        ], ['max_tokens' => 30]);
        echo "MISTRAL RESPONSE: " . substr(($result['text'] ?? ''), 0, 100) . "\n";
    }
} catch (\Throwable $e) {
    echo "MISTRAL ERROR: " . $e->getMessage() . "\n";
}
