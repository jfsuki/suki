<?php
declare(strict_types=1);

/**
 * update_agent_collections.php
 *
 * Para cada agente en SQLite sin qdrant_collection asignada:
 *   1. Deriva el nombre de colección via AgentKnowledgeService::deriveCollection()
 *   2. Actualiza qdrant_collection en SQLite
 *   3. Intenta ensureCollection() en Qdrant (no fatal si falla)
 *
 * Idempotente — puede correrse múltiples veces sin efecto colateral.
 *
 * Usage: php framework/scripts/update_agent_collections.php [--all]
 *   --all   Actualiza también agentes que ya tienen qdrant_collection
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ProjectRegistry;
use App\Core\AgentKnowledgeService;
use App\Core\QdrantVectorStore;

$forceAll = in_array('--all', $argv, true);

echo "=== UPDATE AGENT QDRANT COLLECTIONS ===\n";
echo "Mode: " . ($forceAll ? 'ALL agents' : 'only agents without qdrant_collection') . "\n\n";

$sqliteDb = (new ProjectRegistry())->db();

// Verificar columna qdrant_collection existe
try {
    $sqliteDb->query("SELECT qdrant_collection FROM ai_agents LIMIT 1");
} catch (\Throwable $e) {
    // Columna no existe — crear via migrate_ai_agents.php
    echo "[WARN] Columna qdrant_collection no existe en ai_agents.\n";
    echo "       Ejecutar: php framework/scripts/migrate_ai_agents.php\n";
    // No abortar — intentar añadir la columna
    try {
        $sqliteDb->exec("ALTER TABLE ai_agents ADD COLUMN qdrant_collection TEXT DEFAULT ''");
        echo "[INFO] Columna qdrant_collection agregada.\n\n";
    } catch (\Throwable $e2) {
        echo "[ERROR] " . $e2->getMessage() . "\n";
        exit(1);
    }
}

// Cargar agentes objetivo
try {
    if ($forceAll) {
        $stmt = $sqliteDb->prepare(
            "SELECT agent_id, tenant_id, area, qdrant_collection FROM ai_agents WHERE status != 'DISABLED'"
        );
    } else {
        $stmt = $sqliteDb->prepare(
            "SELECT agent_id, tenant_id, area, qdrant_collection FROM ai_agents
             WHERE status != 'DISABLED' AND (qdrant_collection IS NULL OR qdrant_collection = '')"
        );
    }
    $stmt->execute();
    $agents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($agents)) {
    echo "[INFO] Todos los agentes ya tienen qdrant_collection asignada.\n";
    echo "PASS: 0 agentes actualizados\n";
    exit(0);
}

echo "[INFO] Agentes a actualizar: " . count($agents) . "\n\n";

$updated  = 0;
$qdrantOk = 0;
$errors   = 0;

foreach ($agents as $agent) {
    $agentId  = (string) ($agent['agent_id']  ?? '');
    $tenantId = (string) ($agent['tenant_id'] ?? '');
    $area     = (string) ($agent['area']      ?? '');

    if ($agentId === '' || $tenantId === '' || $area === '') {
        echo "   [SKIP] Agente con datos incompletos: " . json_encode($agent) . "\n";
        continue;
    }

    $collection = AgentKnowledgeService::deriveCollection($tenantId, $area);

    try {
        $sqliteDb->prepare(
            "UPDATE ai_agents SET qdrant_collection = ? WHERE agent_id = ?"
        )->execute([$collection, $agentId]);
        echo "   [OK] {$agentId} ({$tenantId}/{$area}) → {$collection}\n";
        $updated++;
    } catch (\Throwable $e) {
        echo "   [ERROR] UPDATE {$agentId}: " . $e->getMessage() . "\n";
        $errors++;
        continue;
    }

    // Intentar crear colección en Qdrant — no fatal
    try {
        $store = new QdrantVectorStore(null, null, $collection);
        $store->ensureCollection();
        echo "            Qdrant collection ensured: {$collection}\n";
        $qdrantOk++;
    } catch (\Throwable $e) {
        // Qdrant puede no estar disponible en dev — no bloquear
        echo "            [WARN] Qdrant ensure skipped: " . $e->getMessage() . "\n";
    }
}

echo "\nPASS: {$updated} agentes actualizados, {$qdrantOk} colecciones Qdrant aseguradas" .
     ($errors > 0 ? ", {$errors} errors" : "") . "\n";
exit($errors > 0 ? 1 : 0);
