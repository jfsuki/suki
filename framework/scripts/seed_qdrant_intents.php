<?php
/**
 * seed_qdrant_intents.php
 *
 * Reads framework/contracts/agents/intent_training_regional.json and ingests
 * each training example into Qdrant under the `agent_training` memory_type.
 *
 * Usage:
 *   php framework/scripts/seed_qdrant_intents.php [--tenant=default] [--dry-run]
 *
 * After injection, the IntentClassifier's Layer 1 (Qdrant) will resolve
 * regional synonyms without calling the LLM on every message.
 */

declare(strict_types=1);

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

require_once __DIR__ . '/../../framework/bootstrap.php';

// ---------- CLI Args ----------
$dryRun   = in_array('--dry-run', $argv, true);
$tenantId = 'system'; // always inject as system so all tenants benefit
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = substr($arg, 9);
    }
}

// ---------- Load Training Base ----------
$trainingPath = __DIR__ . '/../../framework/contracts/agents/intent_training_regional.json';
if (!is_file($trainingPath)) {
    echo "[ERROR] Training file not found: $trainingPath\n";
    exit(1);
}

$raw  = file_get_contents($trainingPath);
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['intents'])) {
    echo "[ERROR] Invalid or empty training file.\n";
    exit(1);
}

$intents = $data['intents'];
echo "[INFO] Loaded " . count($intents) . " intent categories from training base.\n";
echo "[INFO] Tenant: $tenantId  |  Dry run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";

// ---------- Init Services ----------
try {
    $embedder   = new GeminiEmbeddingService();
    $vectorStore = new QdrantVectorStore();
} catch (\Throwable $e) {
    echo "[ERROR] Failed to init Qdrant/Embedder: " . $e->getMessage() . "\n";
    exit(1);
}

// ---------- Ingest Loop ----------
$totalInserted = 0;
$totalSkipped  = 0;
$totalFailed   = 0;

foreach ($intents as $intent => $examples) {
    echo "-- Intent: $intent (" . count($examples) . " examples) --\n";

    foreach ($examples as $text) {
        $text = trim((string) $text);
        if ($text === '') {
            continue;
        }

        if ($dryRun) {
            echo "   [DRY] Would embed: $text\n";
            $totalInserted++;
            continue;
        }

        try {
            // Get embedding
            $embeddingResult = $embedder->embed($text, ['task_type' => 'RETRIEVAL_DOCUMENT']);
            $vector = $embeddingResult['vector'] ?? null;

            if (!is_array($vector) || empty($vector)) {
                echo "   [WARN] Empty vector for: $text\n";
                $totalSkipped++;
                continue;
            }

            // Build Qdrant payload
            $payload = [
                'tenant_id'   => $tenantId,
                'memory_type' => 'agent_training',
                'content'     => $text,
                'metadata'    => [
                    'intent'    => $intent,
                    'source'    => 'regional_seed_v1',
                    'language'  => 'es',
                    'seeded_at' => date('c'),
                ],
            ];

            $vectorStore->upsertPoints(
                [['id' => md5($tenantId . ':' . $intent . ':' . $text), 'vector' => $vector, 'payload' => $payload]],
            );

            echo "   [OK] $text\n";
            $totalInserted++;

            // Throttle: avoid rate-limit on embedding API
            usleep(250000); // 250ms

        } catch (\Throwable $e) {
            echo "   [FAIL] $text → " . $e->getMessage() . "\n";
            $totalFailed++;
        }
    }
    echo "\n";
}

// ---------- Summary ----------
echo "========================================\n";
echo "Seed complete.\n";
echo "  Inserted : $totalInserted\n";
echo "  Skipped  : $totalSkipped\n";
echo "  Failed   : $totalFailed\n";
echo "========================================\n";
