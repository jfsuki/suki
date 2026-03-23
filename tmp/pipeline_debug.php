<?php
// Diagnóstico profundo del pipeline interno del orquestador
// Ejecutar con: php tmp/pipeline_debug.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

define('PROJECT_ROOT', 'c:/laragon/www/suki/project');
require_once PROJECT_ROOT . '/config/env_loader.php';
$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);
require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/app/autoload.php';

echo "=== CLASE TokenBudgeter ===\n";
$paths = [
    FRAMEWORK_ROOT . '/app/Core/Agents/Memory/TokenBudgeter.php',
    FRAMEWORK_ROOT . '/app/Core/Agents/Tools/TokenBudgeter.php',
];
foreach ($paths as $p) {
    echo (file_exists($p) ? '✓ ' : '✗ ') . $p . "\n";
}
echo "namespace App\\Core\\Agents\\Memory\\TokenBudgeter exists: " . (class_exists('App\\Core\\Agents\\Memory\\TokenBudgeter') ? 'YES' : 'NO') . "\n";
echo "namespace App\\Core\\Agents\\Tools\\TokenBudgeter exists: " . (class_exists('App\\Core\\Agents\\Tools\\TokenBudgeter') ? 'YES' : 'NO') . "\n";

echo "\n=== INTENTO DE RUTA DIRECTA ===\n";
try {
    // Simular ChatOrchestrator directamente
    $budgeter  = new \App\Core\Agents\Memory\TokenBudgeter();
    echo "TokenBudgeter OK\n";
    $cache     = new \App\Core\Agents\Memory\SemanticCache(null, 7200);
    echo "SemanticCache OK\n";
    $builder   = new \App\Core\Agents\Processes\BuilderOnboardingProcess();
    echo "BuilderOnboardingProcess OK\n";
    $app       = new \App\Core\Agents\Processes\AppExecutionProcess();
    echo "AppExecutionProcess OK\n";
} catch (\Throwable $e) {
    echo "FAILED AT INSTANTIATION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== ORQUESTADOR HANDLE DIRECTO ===\n";
try {
    $llm = new \App\Core\LLM\LLMRouter();
    $orchestrator = new \App\Core\Agents\Orchestrator\ChatOrchestrator(
        $budgeter, $cache, $builder, $app, null, null, $llm
    );
    
    $start = microtime(true);
    $result = $orchestrator->handle('demo', 'admin', 'hola mundo', 'builder', 'default');
    $ms = round((microtime(true) - $start) * 1000);
    echo "RESULT ({$ms}ms): " . json_encode(['action' => $result['action'] ?? '?', 'reply' => substr($result['reply'] ?? '', 0, 100)]) . "\n";
} catch (\Throwable $e) {
    echo "ORCHESTRATOR ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . " L:" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== LLM REAL TEST ===\n";
try {
    $llm = new \App\Core\LLM\LLMRouter();
    $capsule = [
        'messages' => [
            ['role' => 'system', 'content' => 'Eres un asistente. Responde en JSON con {\"reply\":\"...\"}'],
            ['role' => 'user', 'content' => 'di hola']
        ]
    ];
    $response = $llm->chat($capsule);
    echo "LLM STATUS: " . ($response['status'] ?? 'unknown') . "\n";
    echo "LLM REPLY: " . substr($response['reply'] ?? json_encode($response), 0, 200) . "\n";
} catch (\Throwable $e) {
    echo "LLM ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . " L:" . $e->getLine() . "\n";
}
