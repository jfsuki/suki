<?php
/**
 * seed_erp_intents.php
 *
 * Seeds framework/training/intents_erp_base.json into Qdrant collection
 * `agent_training` under tenant `system`.
 *
 * Each entry in `entries[]` maps to one intent with N utterances. Every
 * utterance is embedded individually and upserted as a Qdrant point.
 *
 * The point id is deterministic: md5("system:{intent}:{utterance}"), so
 * re-running the script is safe — identical content overwrites itself
 * (Qdrant upsert semantics).
 *
 * Usage:
 *   php framework/scripts/seed_erp_intents.php [--dry-run] [--tenant=system]
 *
 * Flags:
 *   --dry-run          List what would be inserted without calling any API.
 *   --tenant=<id>      Override the default tenant (default: system).
 *   --delay=<ms>       Milliseconds to sleep between API calls (default: 250).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

// ---------- Parse CLI flags ----------
$dryRun   = false;
$tenantId = 'system';
$delayMs  = 250;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = trim(substr($arg, strlen('--tenant=')));
        continue;
    }
    if (str_starts_with($arg, '--delay=')) {
        $delayMs = max(0, (int) substr($arg, strlen('--delay=')));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        printUsage();
        exit(0);
    }
}

// ---------- Load training data ----------
$trainingPath = __DIR__ . '/../training/intents_erp_base.json';
if (!is_file($trainingPath)) {
    echo "[ERROR] Training file not found: {$trainingPath}\n";
    exit(1);
}

$raw  = file_get_contents($trainingPath);
$data = json_decode((string) $raw, true);

if (!is_array($data) || empty($data['entries'])) {
    echo "[ERROR] Invalid JSON or missing 'entries' key in: {$trainingPath}\n";
    exit(1);
}

$entries = $data['entries'];
$totalIntents    = count($entries);
$totalUtterances = array_sum(array_map(fn($e) => count($e['utterances'] ?? []), $entries));

echo "[INFO] Loaded {$totalIntents} intents / {$totalUtterances} utterances from intents_erp_base.json\n";
echo "[INFO] Tenant : {$tenantId}\n";
echo "[INFO] Dry run: " . ($dryRun ? 'YES — no API calls will be made' : 'NO — will embed + upsert') . "\n\n";

// ---------- Init services (skip on dry-run to allow offline listing) ----------
$embedder    = null;
$vectorStore = null;

if (!$dryRun) {
    try {
        $embedder = new GeminiEmbeddingService();
    } catch (\Throwable $e) {
        echo "[ERROR] GeminiEmbeddingService init failed: " . $e->getMessage() . "\n";
        echo "[ERROR] Check GEMINI_API_KEY in project/config/.env\n";
        exit(1);
    }

    try {
        $vectorStore = new QdrantVectorStore(
            null,           // baseUrl  — from QDRANT_URL env
            null,           // apiKey   — from QDRANT_API_KEY env
            null,           // collection — resolved via memoryType
            null,           // vectorSize
            null,           // distance
            null,           // timeoutSec
            null,           // transport
            'agent_training' // memoryType — canonical collection
        );
    } catch (\Throwable $e) {
        echo "[ERROR] QdrantVectorStore init failed: " . $e->getMessage() . "\n";
        echo "[ERROR] Check QDRANT_URL in project/config/.env\n";
        exit(1);
    }
}

// ---------- Seed loop ----------
$totalInserted = 0;
$totalSkipped  = 0;
$totalErrors   = 0;

foreach ($entries as $entry) {
    $intent     = (string) ($entry['intent'] ?? 'unknown');
    $utterances = (array)  ($entry['utterances'] ?? []);
    $skill      = (string) ($entry['skill'] ?? '');
    $domain     = (string) ($entry['domain'] ?? 'erp');

    if ($utterances === []) {
        echo "-- Intent: {$intent} — [SKIP] no utterances defined\n\n";
        continue;
    }

    echo "-- Intent: {$intent} (" . count($utterances) . " utterances)" . ($skill !== '' ? " [skill:{$skill}]" : '') . " --\n";

    foreach ($utterances as $utterance) {
        $utterance = trim((string) $utterance);
        if ($utterance === '') {
            continue;
        }

        // Deterministic id: same content always produces same id → safe re-run
        $pointId = md5("{$tenantId}:{$intent}:{$utterance}");

        if ($dryRun) {
            echo "   [DRY] {$intent}: {$utterance}  (id={$pointId})\n";
            $totalInserted++;
            continue;
        }

        try {
            $embeddingResult = $embedder->embed($utterance, ['task_type' => 'RETRIEVAL_DOCUMENT']);
            $vector = $embeddingResult['vector'] ?? null;

            if (!is_array($vector) || $vector === []) {
                echo "   [WARN] Empty vector for: {$utterance}\n";
                $totalSkipped++;
                continue;
            }

            $payload = [
                'tenant_id'   => $tenantId,
                'memory_type' => 'agent_training',
                'content'     => $utterance,
                'metadata'    => [
                    'intent'     => $intent,
                    'skill'      => $skill,
                    'domain'     => $domain,
                    'tenant_id'  => $tenantId,
                    'source'     => 'erp_base_seed',
                    'seeded_at'  => date('c'),
                    'language'   => 'es-419',
                ],
            ];

            $vectorStore->upsertPoints([
                ['id' => $pointId, 'vector' => $vector, 'payload' => $payload],
            ]);

            echo "   [OK] {$intent}: {$utterance}\n";
            $totalInserted++;

            // Throttle to avoid Gemini rate limits
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

        } catch (\Throwable $e) {
            echo "   [ERROR] {$intent}: {$utterance} — " . $e->getMessage() . "\n";
            $totalErrors++;
            // Continue — never abort the full seed on a single failure
        }
    }

    echo "\n";
}

// ---------- Summary ----------
$mode = $dryRun ? 'DRY RUN' : 'REAL';
echo "========================================\n";
echo "Seed complete [{$mode}].\n";
echo "  Intents    : {$totalIntents}\n";
echo "  Inserted   : {$totalInserted}\n";
echo "  Skipped    : {$totalSkipped}\n";
echo "  Errors     : {$totalErrors}\n";
echo "========================================\n";
if ($dryRun) {
    echo "Re-run without --dry-run to actually embed and upsert.\n";
}

// ---------- Helpers ----------
function printUsage(): void
{
    echo "Usage:\n";
    echo "  php framework/scripts/seed_erp_intents.php [--dry-run] [--tenant=system] [--delay=250]\n\n";
    echo "Flags:\n";
    echo "  --dry-run        List intents/utterances without calling any API.\n";
    echo "  --tenant=<id>    Target tenant id (default: system).\n";
    echo "  --delay=<ms>     Milliseconds between embedding API calls (default: 250).\n\n";
    echo "Source: framework/training/intents_erp_base.json\n";
    echo "Target: Qdrant collection agent_training, tenant system\n";
}
