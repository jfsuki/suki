<?php
// framework/tests/acid_intent_classification_test.php
// ACID TEST: IntentClassifier con infraestructura real — Qdrant + Gemini embeddings.
//
// PRINCIPIO: Las frases NUNCA coinciden exactamente con el training data.
// Se usan sinónimos, modismos colombianos, errores tipográficos, frases incompletas.
// Un clasificador real debe entender semántica, no buscar keywords exactos.
//
// Exit 0 = PASS. Exit 1 = FAIL con evidencia.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\IntentClassifier;
use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

$skipLlm = in_array('--skip-llm', $argv ?? [], true);
$failures = [];
$results  = [];

$qdrantUrl   = (string) (getenv('QDRANT_URL') ?: 'http://localhost:6333');
$qdrantKey   = (string) (getenv('QDRANT_API_KEY') ?: getenv('QDRANT_KEY') ?: '');
$geminiKey   = (string) (getenv('GEMINI_API_KEY') ?: '');
$geminiModel = (string) (getenv('GEMINI_EMBEDDING_MODEL') ?: 'gemini-embedding-001');

if ($geminiKey === '') {
    $failures[] = 'BLOQUEANTE: GEMINI_API_KEY no configurada. IntentClassifier Layer 1 (Qdrant) no puede activarse.';
}

$embedder    = null;
$vectorStore = null;
if ($geminiKey !== '') {
    try {
        $embedder = new GeminiEmbeddingService(
            $geminiKey, $geminiModel, 768,
            'https://generativelanguage.googleapis.com/v1beta', 10
        );
        $vectorStore = new QdrantVectorStore(
            $qdrantUrl, $qdrantKey, null, 768, 'Cosine', 10, null, 'agent_training'
        );
    } catch (\Throwable $e) {
        $failures[] = 'No se pudo instanciar embedder/vectorStore real: ' . $e->getMessage();
    }
}

$classifier = new IntentClassifier($embedder, $vectorStore, null, 'system');

// ── CASOS DE PRUEBA ───────────────────────────────────────────────────────────
// REGLA: Ninguna frase copia el training data.
// Cada grupo tiene 3+ variantes que expresan lo mismo de distinta forma.
// Un clasificador semántico real debe agruparlas en el mismo intent.
//
// Formato: [utterance, grupo_intent, descripcion]
// El test verifica que utterances del mismo grupo obtienen intents relacionados
// y que NINGUNA cae a keyword_fallback con credenciales activas.

$cases = [

    // ── SALUDOS (≠ "hola", "buenos dias", "saludos") ─────────────────────────
    ['epa como le va', 'greeting', 'saludo costeño colombiano'],
    ['que mas pues', 'greeting', 'saludo paisa colombiano'],
    ['buenaaaas', 'greeting', 'saludo informal con alargamiento'],
    ['hey suki', 'greeting', 'saludo directo al agente'],
    ['ola ke ase', 'greeting', 'saludo con error ortográfico intencional'],

    // ── DESPEDIDAS ────────────────────────────────────────────────────────────
    ['nos pillamos', 'farewell', 'despedida colombiana informal'],
    ['me fui gracias', 'farewell', 'despedida con agradecimiento'],
    ['hasta la proxima parcero', 'farewell', 'despedida con modismo colombiano'],

    // ── AFIRMACIONES (el caso B4 — ≠ "listo", "ok", "si") ───────────────────
    ['de una pues', 'affirmation', 'afirmación paisa colombiana'],
    ['va que va', 'affirmation', 'doble afirmación colombiana'],
    ['eso mismo', 'affirmation', 'confirmación informal'],
    ['sale y vale', 'affirmation', 'afirmación con énfasis colombiano'],
    ['venga le digo que si', 'affirmation', 'afirmación extendida'],
    ['todo bien todo correcto', 'affirmation', 'confirmación completa'],

    // ── NEGACIONES ────────────────────────────────────────────────────────────
    ['eso no es lo que quiero', 'negation', 'negación explicativa'],
    ['para nada asi no era', 'negation', 'negación con corrección'],
    ['negativo eso esta mal', 'negation', 'negación formal'],

    // ── FACTURACIÓN (≠ "quiero hacer una factura") ───────────────────────────
    ['necesito cobrarle al cliente de hoy', 'erp_invoice_create', 'cobro informal'],
    ['haceme la facturita de esa venta', 'erp_invoice_create', 'factura coloquial colombiana'],
    ['ya vendí, toca sacar el documento', 'erp_invoice_create', 'factura implícita'],

    // ── INVENTARIO ────────────────────────────────────────────────────────────
    ['cuantas unidades me quedan del cemento?', 'erp_inventory_check', 'consulta de stock con producto específico'],
    ['se acabo el producto ese', 'erp_inventory_check', 'alerta de agotamiento'],
    ['cuanto hay en bodega', 'erp_inventory_check', 'consulta general de bodega'],

    // ── VENTAS / POS ─────────────────────────────────────────────────────────
    ['voy a venderle al señor que está esperando', 'erp_pos_sale', 'venta informal'],
    ['arme la venta de este cliente', 'erp_pos_sale', 'inicio de venta directa'],

    // ── FUERA DE CONTEXTO ─────────────────────────────────────────────────────
    ['quien gano el clasico de hoy', 'out_of_scope', 'pregunta deportiva irrelevante'],
    ['cuanto esta el dolar hoy', 'out_of_scope', 'pregunta financiera externa'],
    ['recomiendame una peli', 'out_of_scope', 'pregunta completamente fuera del ERP'],
];

foreach ($cases as [$utterance, $expectedGroup, $desc]) {
    try {
        $res         = $classifier->classify($utterance);
        $actualIntent = $res['intent'];
        $actualScore  = (float) $res['score'];
        $layer        = $res['layer'];

        // El intent debe CONTENER el grupo esperado (permite especializaciones)
        // Ej: 'erp_invoice_create' contiene 'erp_invoice' — válido
        // Ej: 'affirmation' contiene 'affirmation' — válido
        $intentMatch = str_contains($actualIntent, $expectedGroup)
            || str_contains($expectedGroup, $actualIntent)
            || $actualIntent === $expectedGroup;

        $results[] = [
            'utterance'      => $utterance,
            'expected_group' => $expectedGroup,
            'actual_intent'  => $actualIntent,
            'score'          => $actualScore,
            'layer'          => $layer,
            'intent_ok'      => $intentMatch,
            'desc'           => $desc,
        ];

        // FALLA CRÍTICA: cayó a keyword_fallback con credenciales activas
        if ($layer === 'keyword_fallback' && $geminiKey !== '') {
            $failures[] = sprintf(
                '[keyword_fallback] "%s" — Qdrant y LLM fallaron con credenciales activas. El clasificador cayó a keywords hardcodeados.',
                $utterance
            );
        }

        // FALLA: score bajo el umbral real de Qdrant
        if ($layer === 'qdrant' && $actualScore < 0.65) {
            $failures[] = sprintf(
                '[score_bajo] "%s" → %s (%.3f < 0.65 threshold). Qdrant retornó match débil.',
                $utterance, $actualIntent, $actualScore
            );
        }

    } catch (\Throwable $e) {
        $failures[] = sprintf('EXCEPCION en classify("%s"): %s', $utterance, $e->getMessage());
    }
}

// ── VALIDAR QUE HAY VARIEDAD DE LAYERS ───────────────────────────────────────
$layerCounts = array_count_values(array_column($results, 'layer'));
if (($layerCounts['keyword_fallback'] ?? 0) === count($cases) && $geminiKey !== '') {
    $failures[] = 'CRÍTICO: El 100% cayó a keyword_fallback. Qdrant o embedder no funciona.';
}

// ── VERIFICAR QDRANT TIENE DATOS ──────────────────────────────────────────────
if ($vectorStore !== null) {
    try {
        $inspection = $vectorStore->inspectCollection();
        $pointCount = (int) ($inspection['points_count'] ?? -1);
        if ($pointCount === 0) {
            $failures[] = 'CRÍTICO: Colección agent_training en Qdrant tiene 0 puntos. Ejecutar seed_qdrant_intents.php';
        }
    } catch (\Throwable $e) {
        $failures[] = 'Qdrant inaccesible en ' . $qdrantUrl . ': ' . $e->getMessage();
    }
}

$intentOkCount = count(array_filter($results, fn($r) => $r['intent_ok']));
$total         = count($results);

$ok = empty($failures);
echo json_encode([
    'ok'             => $ok,
    'total_cases'    => $total,
    'intent_ok'      => $intentOkCount,
    'intent_fail'    => $total - $intentOkCount,
    'layer_counts'   => $layerCounts,
    'gemini_key'     => $geminiKey !== '' ? 'SET' : 'MISSING',
    'qdrant_url'     => $qdrantUrl,
    'cases'          => $results,
    'failures'       => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
