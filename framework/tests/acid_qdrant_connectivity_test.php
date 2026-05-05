<?php
// framework/tests/acid_qdrant_connectivity_test.php
// ACID TEST: Qdrant real — conectividad, colección, puntos, búsqueda semántica.
// NO usa mocks. Falla explícitamente si Qdrant no responde o la colección está vacía.
//
// Exit 0 = PASS. Exit 1 = FAIL con evidencia exacta.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

$failures = [];

$qdrantUrl   = (string) (getenv('QDRANT_URL') ?: 'http://localhost:6333');
$qdrantKey   = (string) (getenv('QDRANT_API_KEY') ?: getenv('QDRANT_KEY') ?: '');
$geminiKey   = (string) (getenv('GEMINI_API_KEY') ?: '');
$geminiModel = (string) (getenv('GEMINI_EMBEDDING_MODEL') ?: 'gemini-embedding-001');

// ── TEST 1: Qdrant responde ────────────────────────────────────────────────────
$curlCheck = false;
try {
    $ch = curl_init($qdrantUrl . '/collections');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    if ($qdrantKey !== '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['api-key: ' . $qdrantKey]);
    }
    $raw      = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $failures[] = 'TEST 1 FAIL: Qdrant en ' . $qdrantUrl . ' respondió HTTP ' . $httpCode . '. '
            . 'Verificar que Qdrant está corriendo y QDRANT_URL es correcto.';
    } else {
        $curlCheck = true;
    }
} catch (\Throwable $e) {
    $failures[] = 'TEST 1 FAIL: No se pudo conectar a Qdrant en ' . $qdrantUrl . ': ' . $e->getMessage();
}

// ── TEST 2: Colección agent_training existe y tiene puntos > 0 ─────────────────
$pointCount = -1;
if ($curlCheck) {
    try {
        $store = new QdrantVectorStore(
            $qdrantUrl,
            $qdrantKey,
            null,
            768,
            'Cosine',
            10,
            null,
            'agent_training'
        );
        $info = $store->inspectCollection();
        if (($info['exists'] ?? false) !== true) {
            $failures[] = 'TEST 2 FAIL: Colección "agent_training" NO existe en Qdrant. '
                . 'Ejecutar: php framework/scripts/vectorize_training_dataset.php para crearla.';
        } else {
            // Intentar contar puntos via /collections/agent_training/points/count
            $ch2 = curl_init($qdrantUrl . '/collections/agent_training/points/count');
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, '{}');
            curl_setopt($ch2, CURLOPT_HTTPHEADER, array_filter([
                'Content-Type: application/json',
                $qdrantKey !== '' ? 'api-key: ' . $qdrantKey : '',
            ]));
            $rawCount = curl_exec($ch2);
            curl_close($ch2);

            $countData = json_decode((string) $rawCount, true);
            $pointCount = (int) ($countData['result']['count'] ?? -1);

            if ($pointCount === 0) {
                $failures[] = 'TEST 2 FAIL: Colección "agent_training" existe pero tiene 0 puntos. '
                    . 'IntentClassifier Layer 1 nunca clasificará correctamente. '
                    . 'Vectorizar: php framework/scripts/vectorize_training_dataset.php';
            } elseif ($pointCount < 10 && $pointCount > 0) {
                $failures[] = 'TEST 2 WARN: Colección "agent_training" tiene solo ' . $pointCount
                    . ' puntos. Se esperan al menos 26 intents con múltiples ejemplos cada uno.';
            }
        }
    } catch (\Throwable $e) {
        $failures[] = 'TEST 2 FAIL: Error inspeccionando agent_training: ' . $e->getMessage();
    }
}

// ── TEST 3: Búsqueda semántica real — "quiero hacer una venta" ─────────────────
if ($curlCheck && $geminiKey !== '' && $pointCount > 0) {
    try {
        $embedder = new GeminiEmbeddingService(
            $geminiKey,
            $geminiModel,
            768,
            'https://generativelanguage.googleapis.com/v1beta',
            30
        );
        $store3 = new QdrantVectorStore(
            $qdrantUrl,
            $qdrantKey,
            null,
            768,
            'Cosine',
            30,
            null,
            'agent_training'
        );

        $emb = $embedder->embed('quiero hacer una venta', ['task_type' => 'RETRIEVAL_QUERY']);
        $results = $store3->query(
            $emb['vector'],
            ['must' => [
                ['key' => 'memory_type', 'match' => ['value' => 'agent_training']],
                ['key' => 'tenant_id', 'match' => ['value' => 'system']],
            ]],
            3,
            true
        );

        if (empty($results)) {
            $failures[] = 'TEST 3 FAIL: Búsqueda "quiero hacer una venta" retornó 0 resultados. '
                . 'La colección agent_training no tiene intents de ventas/POS vectorizados.';
        } else {
            $topScore  = (float) ($results[0]['score'] ?? 0.0);
            $topIntent = (string) ($results[0]['payload']['metadata']['intent'] ?? 'UNKNOWN');

            if ($topScore < 0.65) {
                $failures[] = 'TEST 3 FAIL: "quiero hacer una venta" — score=' . round($topScore, 3)
                    . ' < 0.65 (umbral IntentClassifier). intent=' . $topIntent . '. '
                    . 'El dataset de entrenamiento no tiene suficientes ejemplos de intent de ventas.';
            }
        }
    } catch (\Throwable $e) {
        $failures[] = 'TEST 3 FAIL: Búsqueda semántica falló: ' . $e->getMessage();
    }
} elseif ($geminiKey === '') {
    // Sin Gemini no podemos hacer embeddings reales
    $failures[] = 'TEST 3 NO EJECUTADO: GEMINI_API_KEY no configurada. '
        . 'La búsqueda semántica real de IntentClassifier Layer 1 NUNCA se activa.';
}

// ── TEST 4: Búsqueda "cerrar caja" ────────────────────────────────────────────
if ($curlCheck && $geminiKey !== '' && $pointCount > 0) {
    try {
        $embedder4 = new GeminiEmbeddingService(
            $geminiKey,
            $geminiModel,
            768,
            'https://generativelanguage.googleapis.com/v1beta',
            30
        );
        $store4 = new QdrantVectorStore(
            $qdrantUrl,
            $qdrantKey,
            null,
            768,
            'Cosine',
            30,
            null,
            'agent_training'
        );

        $emb4 = $embedder4->embed('cerrar caja', ['task_type' => 'RETRIEVAL_QUERY']);
        $res4 = $store4->query(
            $emb4['vector'],
            ['must' => [
                ['key' => 'memory_type', 'match' => ['value' => 'agent_training']],
                ['key' => 'tenant_id', 'match' => ['value' => 'system']],
            ]],
            3,
            true
        );

        if (empty($res4)) {
            $failures[] = 'TEST 4 FAIL: Búsqueda "cerrar caja" retornó 0 resultados. '
                . 'Intent erp_cash_close_register no vectorizado en agent_training.';
        } else {
            $score4  = (float) ($res4[0]['score'] ?? 0.0);
            $intent4 = (string) ($res4[0]['payload']['metadata']['intent'] ?? 'UNKNOWN');

            if ($score4 < 0.65) {
                $failures[] = 'TEST 4 FAIL: "cerrar caja" score=' . round($score4, 3)
                    . ' < 0.65. intent=' . $intent4 . '. '
                    . 'Dataset insuficiente para intent de cierre de caja.';
            }
        }
    } catch (\Throwable $e) {
        $failures[] = 'TEST 4 FAIL: ' . $e->getMessage();
    }
}

// ── RESULTADO ─────────────────────────────────────────────────────────────────
$ok = empty($failures);
echo json_encode([
    'ok'          => $ok,
    'qdrant_url'  => $qdrantUrl,
    'gemini_key'  => $geminiKey !== '' ? 'SET' : 'MISSING',
    'point_count' => $pointCount,
    'failures'    => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
