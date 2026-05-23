<?php
declare(strict_types=1);

/**
 * vectorize_inventory_utterances.php
 *
 * Vectoriza en Qdrant (collection agent_training) las utterances del intent
 * erp_inventory_check_stock (y variantes erp_inventory_check) leídas desde
 * framework/training/intents_erp_base.json.
 *
 * IDs determinísticos via abs(crc32("inventory_" . $utterance)) — idempotente.
 *
 * Usage: php framework/scripts/vectorize_inventory_utterances.php [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

$dryRun = in_array('--dry-run', $argv, true);

// ---------- Cargar intents_erp_base.json ----------
$trainingPath = __DIR__ . '/../training/intents_erp_base.json';
if (!is_file($trainingPath)) {
    echo "[ERROR] Training file not found: {$trainingPath}\n";
    exit(1);
}

$data = json_decode((string) file_get_contents($trainingPath), true);
if (!is_array($data) || empty($data['entries'])) {
    echo "[ERROR] JSON inválido o sin 'entries' en: {$trainingPath}\n";
    exit(1);
}

// Intents objetivo: inventory check y variantes
$targetIntents = ['erp_inventory_check_stock', 'erp_inventory_check'];

$utterancesToVectorize = [];
foreach ($data['entries'] as $entry) {
    $intent = (string) ($entry['intent'] ?? '');
    if (!in_array($intent, $targetIntents, true)) {
        continue;
    }
    foreach ((array) ($entry['utterances'] ?? []) as $utt) {
        $utt = trim((string) $utt);
        if ($utt !== '') {
            $utterancesToVectorize[$utt] = $intent;
        }
    }
}

if (empty($utterancesToVectorize)) {
    echo "[ERROR] No se encontraron utterances para los intents: " . implode(', ', $targetIntents) . "\n";
    exit(1);
}

$total = count($utterancesToVectorize);
echo "[INFO] Utterances de inventario encontradas: {$total}\n";
echo "[INFO] Dry run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";

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

// ---------- Vectorizar ----------
$vectorized = 0;
$skipped    = 0;
$errors     = 0;

foreach ($utterancesToVectorize as $utterance => $intent) {
    // ID determinístico — idempotente en re-runs
    $pointId = abs(crc32("inventory_" . $utterance));

    if ($dryRun) {
        echo "   [DRY] {$intent}: {$utterance}  (id={$pointId})\n";
        $vectorized++;
        continue;
    }

    try {
        $result = $embedder->embed($utterance, ['task_type' => 'RETRIEVAL_DOCUMENT']);
        $vector = is_array($result['vector'] ?? null) ? (array) $result['vector'] : [];

        if (empty($vector)) {
            echo "   [WARN] Vector vacío para: {$utterance}\n";
            $skipped++;
            continue;
        }

        $vectorStore->upsertPoints([[
            'id'      => $pointId,
            'vector'  => $vector,
            'payload' => [
                'tenant_id'   => 'shared',
                'intent'      => 'erp_inventory_check',
                'utterance'   => $utterance,
                'memory_type' => 'agent_training',
                'source_type' => 'system_training',
                'source'      => 'vectorize_inventory_utterances',
                'seeded_at'   => date('c'),
            ],
        ]]);

        echo "   [OK] {$utterance}\n";
        $vectorized++;
        usleep(250000); // 250ms entre calls — evitar rate limit Gemini

    } catch (\Throwable $e) {
        echo "   [ERROR] {$utterance} — " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n";
echo "PASS: vectorized {$vectorized} utterances ({$skipped} skipped, {$errors} errors)\n";
exit($errors > 0 ? 1 : 0);
