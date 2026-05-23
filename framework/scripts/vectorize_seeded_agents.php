<?php
declare(strict_types=1);

/**
 * vectorize_seeded_agents.php
 *
 * Lee agentes activos desde SQLite (ai_agents), extrae trigger_phrases de
 * config_json y vectoriza cada frase en Qdrant (collection agent_training).
 *
 * Payload por punto:
 *   {tenant_id, intent: strtolower(area), utterance, memory_type, source_type, agent_id}
 *
 * IDs determinísticos: abs(crc32($agentId . "_trigger_" . $i)) — idempotente.
 *
 * Usage: php framework/scripts/vectorize_seeded_agents.php [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;
use App\Core\ProjectRegistry;

$dryRun = in_array('--dry-run', $argv, true);

echo "=== VECTORIZE SEEDED AGENTS TRIGGER PHRASES ===\n";
echo "Dry run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";

// ---------- Cargar agentes desde SQLite ----------
$sqliteDb = (new ProjectRegistry())->db();
try {
    $stmt = $sqliteDb->prepare(
        "SELECT agent_id, tenant_id, area, config_json
         FROM ai_agents
         WHERE status != 'DISABLED'
         ORDER BY tenant_id, area"
    );
    $stmt->execute();
    $agents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    echo "[ERROR] No se pudieron cargar agentes: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($agents)) {
    echo "[WARN] No hay agentes activos en ai_agents.\n";
    echo "PASS: 0 agentes, 0 triggers vectorizados\n";
    exit(0);
}

echo "[INFO] Agentes activos encontrados: " . count($agents) . "\n\n";

// ---------- Init servicios ----------
$embedder    = null;
$vectorStore = null;

if (!$dryRun) {
    try {
        $embedder = new GeminiEmbeddingService();
    } catch (\Throwable $e) {
        echo "[ERROR] GeminiEmbeddingService: " . $e->getMessage() . "\n";
        exit(1);
    }

    try {
        $vectorStore = new QdrantVectorStore(
            null, null, null, null, null, null, null, 'agent_training'
        );
    } catch (\Throwable $e) {
        echo "[ERROR] QdrantVectorStore: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// ---------- Vectorizar triggers por agente ----------
$totalAgents   = 0;
$totalTriggers = 0;
$errors        = 0;

foreach ($agents as $agent) {
    $agentId  = (string) ($agent['agent_id'] ?? '');
    $tenantId = (string) ($agent['tenant_id'] ?? '');
    $area     = (string) ($agent['area'] ?? '');

    $config        = json_decode((string) ($agent['config_json'] ?? '{}'), true);
    $triggerPhrases = is_array($config['trigger_phrases'] ?? null)
        ? (array) $config['trigger_phrases']
        : [];

    if (empty($triggerPhrases)) {
        echo "-- Agente {$agentId} ({$area}) — sin trigger_phrases, saltando\n";
        continue;
    }

    echo "-- Agente {$agentId} ({$tenantId}/{$area}) — " . count($triggerPhrases) . " triggers\n";
    $totalAgents++;

    foreach ($triggerPhrases as $i => $phrase) {
        $phrase  = trim((string) $phrase);
        if ($phrase === '') {
            continue;
        }
        $pointId = abs(crc32($agentId . "_trigger_" . $i));

        if ($dryRun) {
            echo "   [DRY] {$phrase}  (id={$pointId})\n";
            $totalTriggers++;
            continue;
        }

        try {
            $result = $embedder->embed($phrase, ['task_type' => 'RETRIEVAL_DOCUMENT']);
            $vector = is_array($result['vector'] ?? null) ? (array) $result['vector'] : [];

            if (empty($vector)) {
                echo "   [WARN] Vector vacío: {$phrase}\n";
                continue;
            }

            $vectorStore->upsertPoints([[
                'id'      => $pointId,
                'vector'  => $vector,
                'payload' => [
                    'tenant_id'   => $tenantId,
                    'intent'      => strtolower($area),
                    'utterance'   => $phrase,
                    'memory_type' => 'agent_training',
                    'source_type' => 'custom_agent_trigger',
                    'agent_id'    => $agentId,
                    'seeded_at'   => date('c'),
                ],
            ]]);

            echo "   [OK] {$phrase}\n";
            $totalTriggers++;
            usleep(250000); // 250ms — evitar rate limit Gemini

        } catch (\Throwable $e) {
            // Log pero no abortar — continúa con siguiente trigger
            echo "   [ERROR] {$phrase} — " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    echo "\n";
}

echo "PASS: {$totalAgents} agentes, {$totalTriggers} triggers vectorizados" .
     ($errors > 0 ? " ({$errors} errors)" : "") . "\n";
exit($errors > 0 ? 1 : 0);
