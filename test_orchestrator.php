<?php
require __DIR__ . '/framework/app/autoload.php';
require_once __DIR__ . '/framework/app/Core/RuntimeSchemaPolicy.php';
require_once __DIR__ . '/framework/app/Core/Agents/Memory/TokenBudgeter.php';
require_once __DIR__ . '/framework/app/Core/Agents/Memory/SemanticCache.php';
require_once __DIR__ . '/framework/app/Core/Agents/Memory/MemoryWindow.php';
require_once __DIR__ . '/framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php';
require_once __DIR__ . '/framework/app/Core/Agents/Processes/AppExecutionProcess.php';
require_once __DIR__ . '/framework/app/Core/Agents/Orchestrator/ChatOrchestrator.php';

use App\Core\Agents\Memory\TokenBudgeter;
use App\Core\Agents\Memory\SemanticCache;
use App\Core\Agents\Processes\BuilderOnboardingProcess;
use App\Core\Agents\Processes\AppExecutionProcess;
use App\Core\Agents\Orchestrator\ChatOrchestrator;

putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('APP_ENV=local');

$dbPath = __DIR__ . '/project/storage/meta/project_registry.sqlite';
$db = new \PDO('sqlite:' . $dbPath);
$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$budgeter = new TokenBudgeter();
$cache = new SemanticCache($db, 3600);
$builder = new BuilderOnboardingProcess();
$app = new AppExecutionProcess();

$orchestrator = new ChatOrchestrator($budgeter, $cache, $builder, $app);

echo "=== ChatOrchestrator Delegation Test ===\n\n";

// TEST 1: Builder Mode
$stateBuilder = [
    'tenant_id' => '102',
    'mode' => 'builder',
    'active_task' => 'business_type'
];
echo ">> User says: 'vendo pollo asado' (Mode: Builder)\n";
$respuestaBuilder = $orchestrator->handle("Tengo un restaurante que vende pollo asado", $stateBuilder);
print_r($respuestaBuilder);


// TEST 2: App Mode
$stateApp = [
    'tenant_id' => '102',
    'mode' => 'app',
    'active_task' => 'none'
];
echo "\n>> User says: 'necesito cobrar una factura' (Mode: App)\n";
$respuestaApp = $orchestrator->handle("necesito cobrar una factura de venta", $stateApp);
print_r($respuestaApp);

// TEST 3: Semantic Cache Hit via Orchestrator
echo "\n>> User says again: 'necesito cobrar una factura' (Mode: App - Should Hit Cache)\n";
$respuestaAppCache = $orchestrator->handle("necesito cobrar una factura de venta", $stateApp);
echo "Cache Hit Result Action: " . $respuestaAppCache['action'] . "\n";

echo "\nDone.\n";
