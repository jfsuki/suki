<?php
require_once __DIR__ . '/framework/app/autoload.php';
use App\Core\IntentRouter;

$router = new IntentRouter();
$context = [
    'tenant_id' => 'system',
    'project_id' => 'default',
    'app_id' => 'suki',
    'mode' => 'app'
];

$skillsRegistry = new \App\Core\SkillRegistry([]);
$message = "que hay hecho";
// Simulamos lo que hace el gateway: clasifica como responder localmente con el saludo
$action = "respond_local";
$reply = "Hola, soy Cami. Dime que necesitas crear o consultar.";

$reflection = new ReflectionClass($router);
$isTrivialMethod = $reflection->getMethod('isTrivialConversationQuery');
$isTrivialMethod->setAccessible(true);
$isTrivial = $isTrivialMethod->invoke($router, $message);
echo "Es trivial: " . ($isTrivial ? 'SÍ' : 'NO') . "\n";

$method = $reflection->getMethod('maybeResolveSemanticIntent');
$method->setAccessible(true);

$gatewayResult = [
    'action' => 'respond_local',
    'reply' => 'Hola, soy Cami. Dime que necesitas crear o consultar.',
    'user_text' => $message
];
$runtimeBudget = ['max_context_chunks' => 3];
$telemetry = [];

$smMethod = $reflection->getMethod('semanticMemory');
$smMethod->setAccessible(true);
$semanticMemory = $smMethod->invoke($router);
$queryMethod = (new ReflectionClass($semanticMemory))->getMethod('retrieveAgentTraining');
$queryMethod->setAccessible(true);

echo "Consultando Qdrant directamente...\n";
$retrieval = $semanticMemory->retrieveAgentTraining($message, [
    'tenant_id' => 'system',
    'app_id' => 'suki'
]);

echo "Resultado directo de Qdrant:\n";
echo json_encode($retrieval, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "Probando maybeResolveSemanticIntent...\n";
$result = $method->invoke($router, $action, $gatewayResult, $context, $runtimeBudget, $skillsRegistry, $telemetry);

echo "Resultado de la resolución semántica:\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
