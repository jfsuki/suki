<?php
/**
 * seed_affirmation_intents.php
 *
 * Vectoriza utterances de 'affirmation' en Qdrant con tenant='system' para que
 * "listo/ok/dale/perfecto" NO clasifiquen como erp_accounting_post u otras operaciones ERP.
 *
 * Blocker B4: IntentClassifier asignaba score 0.73 a 'erp_accounting_post' para "listo".
 *
 * Usage:
 *   php framework/scripts/seed_affirmation_intents.php [--dry-run]
 */

declare(strict_types=1);

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$dryRun  = in_array('--dry-run', $argv, true);
$tenantId = 'system';

$utterances = [
    // Confirmaciones cortas
    'listo', 'ok', 'dale', 'perfecto', 'claro', 'entendido', 'si', 'sí',
    'de acuerdo', 'bueno', 'va', 'okay', 'correcto', 'exacto', 'así es',
    'eso es', 'bien', 'genial', 'excelente', 'procede', 'hazlo', 'adelante',
    'listo pues', 'oye si', 'me parece bien', 'está bien', 'va bien',
    'confirmado', 'afirmativo', 'por supuesto', 'con gusto', 'claro que si',
    // Variantes colombianas
    'listo pa', 'dale pues', 'oiga si', 'eso mismo', 'eso eso',
    'de una', 'de una vez', 'pilas', 'ya', 'ya mismo',
    // Confirmaciones con énfasis
    'sí señor', 'sí señora', 'así mero', 'eso mero', 'chévere',
    // Affirmation + pronombre
    'sí quiero', 'sí acepto', 'sí confirmo', 'lo confirmo', 'confirmo',
    // Agradecimientos breves (no deben disparar acciones)
    'gracias', 'muchas gracias', 'gracias mil', 'bacano',
    // Frases de continuación neutral
    'continua', 'continúa', 'sigue', 'siguiente', 'ok siguiente',
    'listo continua', 'dale continua', 'ok procede',
];

echo "[INFO] Intent: affirmation (" . count($utterances) . " utterances)\n";
echo "[INFO] Tenant: $tenantId  |  Dry run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";

try {
    $embedder    = new GeminiEmbeddingService();
    $vectorStore = (new QdrantVectorStore())->forMemoryType('agent_training');
    echo "[INFO] Collection: " . $vectorStore->collectionName() . "\n";
    $vectorStore->ensureCollection();
    $vectorStore->ensurePayloadIndexes();
} catch (\Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

$ok   = 0;
$fail = 0;

foreach ($utterances as $text) {
    $text = trim($text);
    if ($text === '') continue;

    if ($dryRun) {
        echo "   [DRY] Would embed: $text\n";
        $ok++;
        continue;
    }

    try {
        $result = $embedder->embed($text, ['task_type' => 'RETRIEVAL_DOCUMENT']);
        $vector = $result['vector'] ?? null;

        if (!is_array($vector) || empty($vector)) {
            echo "   [WARN] Empty vector for: $text\n";
            $fail++;
            continue;
        }

        $payload = [
            'tenant_id'   => $tenantId,
            'memory_type' => 'agent_training',
            'content'     => $text,
            'metadata'    => [
                'intent'    => 'affirmation',
                'source'    => 'b4_fix_seed_v1',
                'language'  => 'es',
                'seeded_at' => date('c'),
            ],
        ];

        $vectorStore->upsertPoints([
            [
                'id'      => md5($tenantId . ':affirmation:' . $text),
                'vector'  => $vector,
                'payload' => $payload,
            ]
        ]);

        echo "   [OK] $text\n";
        $ok++;
        usleep(120000); // 120ms throttle — Gemini embedding rate limit

    } catch (\Throwable $e) {
        echo "   [FAIL] $text — " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\n[RESULT] OK=$ok  FAIL=$fail\n";
if ($ok > 0 && $fail === 0) {
    echo "[DONE] Affirmation intents seeded. Run IntentClassifier test to verify 'listo' → affirmation.\n";
}
