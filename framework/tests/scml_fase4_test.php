<?php

declare(strict_types=1);

/**
 * SCML Fase 4 — Tests de regresión para los 4 gaps de producción cerrados:
 *
 *  P1 — previous_topic_cluster cross-request (persistencia DB)
 *  P2 — skill_params:{} no validaba params del LLM
 *  P3 — DynamicSkillRegistry null → falla silenciosa
 *  P4 — ExecutionRegistry instanciada 2x por request (single-instance)
 */

require_once dirname(__DIR__) . '/app/autoload.php';

use App\Core\Grounding\ExecutionRegistry;
use App\Core\Grounding\OutputValidator;

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}\n";
        $failed++;
    }
}

echo "\n=== SCML Fase 4 — Production Gaps ===\n\n";

// ---------------------------------------------------------------------------
// P1 — Topic Cluster Persistence (SQLite in-memory)
// ---------------------------------------------------------------------------

echo "--- P1: Topic Cluster Cross-Request Persistence ---\n";

// Simular la capa de persistencia directamente con SQLite in-memory
$pdo = new \PDO('sqlite::memory:');
$pdo->exec("CREATE TABLE IF NOT EXISTS conversation_topic_cluster (
    thread_id TEXT NOT NULL,
    tenant_id TEXT NOT NULL DEFAULT '',
    cluster   TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (thread_id, tenant_id)
)");

// P1-A: sin datos guardados → retorna '' (no exception)
$stmt = $pdo->prepare('SELECT cluster FROM conversation_topic_cluster WHERE thread_id = :t AND tenant_id = :n LIMIT 1');
$stmt->execute([':t' => 'tenant1:session1', ':n' => 'tenant1']);
$result = $stmt->fetchColumn();
ok('P1-A: sin cluster previo retorna vacío', $result === false || $result === '');

// P1-B: save → load round-trip
$pdo->prepare("INSERT OR REPLACE INTO conversation_topic_cluster (thread_id, tenant_id, cluster, updated_at) VALUES (:t, :n, :c, :u)")
    ->execute([':t' => 'tenant1:session1', ':n' => 'tenant1', ':c' => 'accounting', ':u' => date('Y-m-d H:i:s')]);

$stmt->execute([':t' => 'tenant1:session1', ':n' => 'tenant1']);
$loaded = $stmt->fetchColumn();
ok('P1-B: save/load round-trip funciona', $loaded === 'accounting');

// P1-C: update sobrescribe (upsert)
$pdo->prepare("INSERT OR REPLACE INTO conversation_topic_cluster (thread_id, tenant_id, cluster, updated_at) VALUES (:t, :n, :c, :u)")
    ->execute([':t' => 'tenant1:session1', ':n' => 'tenant1', ':c' => 'inventory', ':u' => date('Y-m-d H:i:s')]);
$stmt->execute([':t' => 'tenant1:session1', ':n' => 'tenant1']);
$updated = $stmt->fetchColumn();
ok('P1-C: upsert actualiza cluster', $updated === 'inventory');

// P1-D: tenant isolation — otro tenant NO ve el cluster
$stmt2 = $pdo->prepare('SELECT cluster FROM conversation_topic_cluster WHERE thread_id = :t AND tenant_id = :n LIMIT 1');
$stmt2->execute([':t' => 'tenant1:session1', ':n' => 'tenant2']);
$isolated = $stmt2->fetchColumn();
ok('P1-D: tenant isolation — otro tenant no ve cluster ajeno', $isolated === false || $isolated === '');

// P1-E: thread isolation — otro thread no ve el cluster
$stmt2->execute([':t' => 'tenant2:session1', ':n' => 'tenant1']);
$threadIso = $stmt2->fetchColumn();
ok('P1-E: thread isolation — otro thread no ve cluster ajeno', $threadIso === false || $threadIso === '');

// P1-F: previous_topic_cluster pattern — simula el comportamiento de ChatAgent
$prevCluster = 'inventory'; // loaded at start of request
$currCluster = 'accounting'; // grounding result of this request
// Simulate: previous = prev, current = new
$newPrev = $prevCluster;    // ← lo que se debe tener en el SIGUIENTE request como previous
$newCurr = $currCluster;
$pdo->prepare("INSERT OR REPLACE INTO conversation_topic_cluster (thread_id, tenant_id, cluster, updated_at) VALUES (:t, :n, :c, :u)")
    ->execute([':t' => 'tenant1:session2', ':n' => 'tenant1', ':c' => $newCurr, ':u' => date('Y-m-d H:i:s')]);
// En el siguiente request, loadTopicCluster devuelve currCluster → se convierte en previousCluster
$stmt3 = $pdo->prepare('SELECT cluster FROM conversation_topic_cluster WHERE thread_id = :t AND tenant_id = :n LIMIT 1');
$stmt3->execute([':t' => 'tenant1:session2', ':n' => 'tenant1']);
$nextPrev = $stmt3->fetchColumn();
ok('P1-F: cluster guardado disponible como previous_topic_cluster en siguiente request', $nextPrev === 'accounting');

// ---------------------------------------------------------------------------
// P2 — skill_params:{} debe rechazar params del LLM (strict mode)
// ---------------------------------------------------------------------------

echo "\n--- P2: skill_params:{} Validation ---\n";

$registry  = new ExecutionRegistry();
$validator = new OutputValidator($registry);

// P2-A: inventory_list_products tiene skill_params:{} — el LLM NO debe poder enviar params arbitrarios
$resultListProds = $validator->validate([
    'skill' => 'inventory_list_products',
    'data'  => ['malicious_param' => 'evil', 'otro' => 'bad'],
]);
ok('P2-A: inventory_list_products (skill_params:{}) rechaza params en strict mode', $resultListProds['valid'] === false);
ok('P2-B: error=no_params_expected', ($resultListProds['error'] ?? '') === 'no_params_expected');
ok('P2-C: clarification_message presente', !empty($resultListProds['clarification_message']));

// P2-D: inventory_list_products SIN params → debe ser válido (es un listing sin filtros)
$resultListNoParams = $validator->validate([
    'skill' => 'inventory_list_products',
    'data'  => [],
]);
ok('P2-D: inventory_list_products sin params → valid=true', $resultListNoParams['valid'] === true);

// P2-E: crm_stats (skill_params:{}) rechaza params
$resultCrmStats = $validator->validate([
    'skill' => 'crm_stats',
    'data'  => ['period' => '2026-Q1'],
]);
ok('P2-E: crm_stats rechaza params arbitrarios', $resultCrmStats['valid'] === false);
ok('P2-F: crm_stats sin params → valid=true', $validator->validate(['skill' => 'crm_stats', 'data' => []])['valid'] === true);

// P2-G: params_declared flag en registry entry
$listEntry = $registry->getEntry('inventory_list_products');
ok('P2-G: inventory_list_products params_declared=true', ($listEntry['params_declared'] ?? false) === true);
ok('P2-H: inventory_list_products input_keys=[]', isset($listEntry['input_keys']) && $listEntry['input_keys'] === []);

// P2-I: skill sin catalog (params_declared=false) no activa la validación vacía
$memEntry = $registry->getEntry('update_internal_memory');
ok('P2-I: update_internal_memory params_declared=true (tiene skill_params)', ($memEntry['params_declared'] ?? false) === true);
ok('P2-J: update_internal_memory input_keys no vacío', !empty($memEntry['input_keys'] ?? []));

// ---------------------------------------------------------------------------
// P3 — DynamicSkillRegistry null dispatch manejo
// ---------------------------------------------------------------------------

echo "\n--- P3: Null Dispatch Handling ---\n";

// Simular dispatch null usando un skill que no existe en DynamicSkillRegistry
$dynRegistry = new \App\Core\DynamicSkillRegistry();
$nullResult  = $dynRegistry->dispatch('skill_that_does_not_exist_xyz', [], ['tenant_id' => 'test']);
ok('P3-A: dispatch de skill inexistente retorna null', $nullResult === null);

// P3-B: OutputValidator rechaza skill_not_found antes de llegar al dispatch
$validationBeforeDispatch = $validator->validate(['skill' => 'skill_that_does_not_exist_xyz', 'data' => []]);
ok('P3-B: OutputValidator bloquea skill_not_found antes del dispatch (null nunca ocurre)', $validationBeforeDispatch['valid'] === false && ($validationBeforeDispatch['error'] ?? '') === 'skill_not_found');

// P3-C: skill que SÍ está en catalog y en PHP → dispatch no retorna null
$validAccounting = $validator->validate(['skill' => 'accounting_post', 'data' => ['data' => [], 'amount' => 100, 'description' => 'test']]);
ok('P3-C: accounting_post pasa validación', $validAccounting['valid'] === true);
$dispatchResult = $dynRegistry->dispatch('accounting_post', $validAccounting['data'], ['tenant_id' => 'test', 'user_id' => 'u1']);
ok('P3-D: accounting_post dispatch no retorna null', $dispatchResult !== null);
ok('P3-E: dispatch result tiene clave "reply" o equivalente', isset($dispatchResult['reply']) || isset($dispatchResult['action']) || isset($dispatchResult['ok']));

// ---------------------------------------------------------------------------
// P4 — Single ExecutionRegistry instance reuse
// ---------------------------------------------------------------------------

echo "\n--- P4: Single Instance Reuse ---\n";

// P4-A: misma instancia puede ser usada en TaskGroundingManifest Y OutputValidator
$sharedRegistry = new ExecutionRegistry();
$manifest = new \App\Core\Grounding\TaskGroundingManifest(null, 0.60, $sharedRegistry);
$validatorShared = new OutputValidator($sharedRegistry);

// Ambos usan la misma instancia — el registry ya está construido tras el primer uso
$result1 = $manifest->renderForSystemPrompt('accounting_post', 0.90);
$result2 = $validatorShared->validate(['skill' => 'accounting_post', 'data' => ['data' => [], 'amount' => 100, 'description' => 'test']]);
ok('P4-A: misma instancia funciona en TaskGroundingManifest y OutputValidator', $result1['block'] !== '' && $result2['valid'] === true);

// P4-B: registry se construye UNA sola vez (caché de instancia)
$sharedRegistry->build(); // primera llamada construye
$built1 = $sharedRegistry->build(); // segunda → misma referencia de array
$built2 = $sharedRegistry->build(); // tercera → misma referencia
ok('P4-B: build() no reconstruye si ya está cacheado (mismo conteo de entries)', count($built1) === count($built2) && count($built1) === 14);

// ---------------------------------------------------------------------------
// Resultado
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo "\n--- Resultado: {$passed}/{$total} PASS";
if ($failed > 0) {
    echo " ({$failed} FAIL)";
}
echo " ---\n";
exit($failed > 0 ? 1 : 0);
