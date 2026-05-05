<?php
// framework/tests/acid_llm_routing_test.php
// ACID TEST: LLMRouter con config real del .env.
// Verifica que el provider primario configurado responde, latencia < 10s,
// respuesta no vacía. No usa mocks.
// Uso: php acid_llm_routing_test.php [--skip-llm]
//
// Exit 0 = PASS (o skip autorizado). Exit 1 = FAIL con provider y error exacto.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\LLM\LLMRouter;

$skipLlm = in_array('--skip-llm', $argv ?? [], true);
$failures = [];

// ── LEER CONFIG REAL ──────────────────────────────────────────────────────────
$llmConfig = [];
$configPath = FRAMEWORK_ROOT . '/config/llm.php';
if (is_file($configPath)) {
    $llmConfig = require $configPath;
} else {
    $failures[] = 'BLOQUEANTE: ' . $configPath . ' no existe. Sin config LLM no hay IA real.';
}

// ── TEST 1: Verificar que al menos un provider está enabled ──────────────────
$enabledProviders = [];
foreach ((array) ($llmConfig['providers'] ?? []) as $name => $cfg) {
    if (!empty($cfg['enabled'])) {
        $enabledProviders[] = $name;
    }
}

if (empty($enabledProviders)) {
    $failures[] = 'TEST 1 FAIL: Ningún provider LLM está habilitado en llm.php. '
        . 'Verificar GEMINI_ENABLED, OPENROUTER_ENABLED, DEEPSEEK_ENABLED en .env. '
        . 'Sin LLM habilitado, IntentClassifier Layer 2 nunca se activa.';
}

// ── TEST 2: Envío real al LLM (omitir si --skip-llm o sin credenciales) ──────
$llmAttempted = false;
$llmProvider  = '';
$llmLatencyMs = -1;
$llmResponse  = null;

if (!$skipLlm && !empty($enabledProviders) && empty($failures)) {
    try {
        $router = new LLMRouter($llmConfig);

        $capsule = [
            'policy' => [
                'requires_strict_json' => true,
                'max_output_tokens'    => 60,
                'latency_budget_ms'    => 10000,
            ],
            'prompt_contract' => [
                'TASK'          => 'Respond with a simple JSON object.',
                'OUTPUT_FORMAT' => ['status' => 'ok', 'msg' => 'string'],
                'RULES'         => ['Return ONLY valid JSON. No markdown.'],
            ],
            'user_message' => 'Say {"status":"ok","msg":"hello"}',
        ];

        $startMs = (int) round(microtime(true) * 1000);

        $llmAttempted = true;
        $result = $router->chat($capsule, [
            'tenant_id'    => 'acid_test',
            'project_id'   => 'acid_llm_routing',
            'session_id'   => 'acid_llm_' . time(),
            'temperature'  => 0.0,
        ]);

        $endMs        = (int) round(microtime(true) * 1000);
        $llmLatencyMs = $endMs - $startMs;
        $llmProvider  = (string) ($result['provider'] ?? 'unknown');
        $llmResponse  = $result;

        // Verificar que hay texto en la respuesta
        $text = trim((string) ($result['text'] ?? ''));
        if ($text === '') {
            $failures[] = 'TEST 2 FAIL: provider=' . $llmProvider . ' respondió texto vacío.';
        }

        // Verificar latencia < 10 segundos
        if ($llmLatencyMs > 10000) {
            $failures[] = 'TEST 2 FAIL: provider=' . $llmProvider . ' tardó ' . $llmLatencyMs . 'ms > 10000ms. '
                . 'Inaceptable para chat-first ERP.';
        }

        // Verificar que se reporta el proveedor real
        if ($llmProvider === '' || $llmProvider === 'unknown') {
            $failures[] = 'TEST 2 FAIL: LLMRouter no reportó el proveedor usado. '
                . 'No se puede auditar cuál provider respondió.';
        }

        // Verificar tokens reportados
        $usage = is_array($result['usage'] ?? null) ? (array) $result['usage'] : [];
        $totalTokens = (int) ($usage['total_tokens']
            ?? $usage['totalTokenCount']
            ?? $usage['input_tokens']
            ?? 0);
        if ($llmAttempted && $totalTokens === 0) {
            $failures[] = 'TEST 2 WARN: provider=' . $llmProvider
                . ' no reportó tokens usados. Telemetría de costo incompleta.';
        }

    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        // Extraer provider_statuses del mensaje de error si existe
        $providerStatuses = '';
        if (preg_match('/provider_statuses=(\{[^}]+\})/', $msg, $m)) {
            $providerStatuses = $m[1];
        }
        $failures[] = 'TEST 2 FAIL: LLMRouter lanzó excepción. Proveedores intentados: ['
            . implode(', ', $enabledProviders) . ']. '
            . 'Error: ' . substr($msg, 0, 300)
            . ($providerStatuses !== '' ? '. Statuses: ' . $providerStatuses : '');
    }
} elseif ($skipLlm) {
    // Skip autorizado con flag — no es falla
} elseif (empty($enabledProviders)) {
    // Ya registrado como falla en TEST 1
}

// ── TEST 3: Verificar config routing — primary/secondary definidos ────────────
$routing = is_array($llmConfig['routing'] ?? null) ? (array) $llmConfig['routing'] : [];
$primary = (string) ($routing['primary'] ?? '');
if ($primary === '') {
    $failures[] = 'TEST 3 FAIL: llm.php no define routing.primary. '
        . 'LLMRouter no sabe qué provider usar primero.';
}

// ── TEST 4: Verificar que Gemini está configurado para embeddings (requerido por IntentClassifier Layer 1) ──
$geminiKey      = (string) (getenv('GEMINI_API_KEY') ?: '');
$geminiEnabled  = !empty($llmConfig['providers']['gemini']['enabled'] ?? false);
if ($geminiKey === '') {
    $failures[] = 'TEST 4 FAIL: GEMINI_API_KEY no configurada. '
        . 'IntentClassifier Layer 1 (embeddings) NUNCA se activa — todo cae a keyword_fallback. '
        . 'Este es el bloqueante P0 de IA documentado en las auditorías previas.';
} elseif (!$geminiEnabled) {
    $failures[] = 'TEST 4 FAIL: GEMINI_API_KEY existe pero gemini no está enabled en llm.php. '
        . 'Los embeddings de Qdrant no funcionarán.';
}

// ── RESULTADO ─────────────────────────────────────────────────────────────────
$ok = empty($failures);
echo json_encode([
    'ok'                => $ok,
    'skip_llm'          => $skipLlm,
    'llm_attempted'     => $llmAttempted,
    'provider_used'     => $llmProvider,
    'latency_ms'        => $llmLatencyMs,
    'enabled_providers' => $enabledProviders,
    'primary_provider'  => $primary,
    'gemini_key'        => $geminiKey !== '' ? 'SET' : 'MISSING',
    'failures'          => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
