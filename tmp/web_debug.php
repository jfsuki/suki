<?php
// Diagnóstico Web: simular exactamente lo que hace api.php desde el contexto del servidor
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Mismo bootstrap que api.php
define('PROJECT_ROOT', 'c:/laragon/www/suki/project');
require_once PROJECT_ROOT . '/config/env_loader.php';

$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);

require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/app/autoload.php';

echo "=== AUTOLOAD OK ===\n";

// Verificar clases instaladas
$classes = [
    'App\\Core\\ChatAgent',
    'App\\Core\\Agents\\Orchestrator\\ChatOrchestrator',
    'App\\Core\\Agents\\Memory\\SemanticCache',
    'App\\Core\\Agents\\Memory\\MemoryWindow',
    'App\\Core\\Agents\\Processes\\BuilderOnboardingProcess',
    'App\\Core\\Agents\\Processes\\AppExecutionProcess',
    'App\\Core\\Agents\\Tools\\TokenBudgeter',
];

foreach ($classes as $cls) {
    $path = FRAMEWORK_ROOT . '/app/' . str_replace('\\', '/', substr($cls, 4)) . '.php';
    $exists_file = file_exists($path);
    echo ($exists_file ? '✓' : '✗') . " FILE: $path\n";
}

echo "\n=== ENV KEYS ===\n";
$keys = ['MISTRAL_API_KEY','DEEPSEEK_API_KEY','QDRANT_URL','QDRANT_API_KEY','LLM_ROUTER_MODE'];
foreach ($keys as $k) {
    $v = getenv($k);
    echo "$k = " . ($v ? substr($v, 0, 10) . '...' : '(empty)') . "\n";
}

echo "\n=== PERMISSIONS ===\n";
$paths = [
    PROJECT_ROOT . '/storage/meta',
    PROJECT_ROOT . '/storage/security',
    PROJECT_ROOT . '/storage/meta/ops_semantic_cache.sqlite',
];
foreach ($paths as $p) {
    if (!file_exists($p)) {
        echo "✗ NOT EXISTS: $p\n";
    } else {
        $w = is_writable($p);
        echo ($w ? '✓ WRITABLE' : '✗ NOT WRITABLE') . ": $p\n";
    }
}

echo "\n=== RUNTIME SchemaPolicy ENV ===\n";
echo "ALLOW_RUNTIME_SCHEMA=" . (getenv('ALLOW_RUNTIME_SCHEMA') ?: '(not set)') . "\n";
echo "APP_ENV=" . (getenv('APP_ENV') ?: '(not set)') . "\n";

echo "\n=== PHP VENDOR AUTOLOAD ===\n";
$vendorPath = FRAMEWORK_ROOT . '/vendor/autoload.php';
echo file_exists($vendorPath) ? "✓ vendor/autoload.php EXISTS\n" : "✗ vendor/autoload.php MISSING\n";

echo "\n=== CHATAGENT HANDLE TEST ===\n";
try {
    $agent = new \App\Core\ChatAgent();
    $result = $agent->handle([
        'message' => 'hola',
        'mode' => 'builder',
        'tenant_id' => 'demo',
        'user_id' => 'admin',
        'project_id' => 'default',
        'role' => 'guest',
        'is_authenticated' => false,
    ]);
    echo "STATUS: " . ($result['status'] ?? 'unknown') . "\n";
    echo "MESSAGE: " . ($result['message'] ?? '') . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . " L:" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
