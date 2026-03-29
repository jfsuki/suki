<?php
// framework/tests/llm_smoke.php
// Smoke real de LLM (Gemini) con validacion de fallback, salida estructurada y tokens/costo.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\LLM\LLMRouter;
use App\Core\LogSanitizer;
use App\Core\TelemetryService;

$appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('SUKI_ENV') ?: 'local')));
$allowSkip = (string) (getenv('ALLOW_LLM_SMOKE_SKIP') ?: '0') === '1';
$mandatoryEnv = in_array($appEnv, ['staging', 'stage', 'production', 'prod'], true);

$tenantId = trim((string) (getenv('LLM_SMOKE_TENANT_ID') ?: 'default'));
$projectId = trim((string) (getenv('LLM_SMOKE_PROJECT_ID') ?: 'staging_llm_smoke'));
$mode = trim((string) (getenv('LLM_SMOKE_MODE') ?: 'auto'));
$maxTokens = max(120, (int) (getenv('LLM_SMOKE_MAX_TOKENS') ?: 320));

$startedAt = microtime(true);
$failures = [];
$attempts = 1;
$sleepSeconds = [];
$skipped = false;
$skipReason = '';

$geminiKey = trim((string) getenv('GEMINI_API_KEY'));
$groqKey = trim((string) getenv('GROQ_API_KEY'));
$openRouterKey = trim((string) getenv('OPENROUTER_API_KEY'));
$claudeKey = trim((string) getenv('CLAUDE_API_KEY'));
$mistralKey = trim((string) getenv('MISTRAL_API_KEY'));
$deepSeekKey = trim((string) getenv('DEEPSEEK_API_KEY'));
$preferredProvider = strtolower(trim((string) (getenv('LLM_SMOKE_PRIMARY') ?: 'mistral')));

$providerCatalog = [];
if ($geminiKey !== '') {
    $providerCatalog['gemini'] = \App\Core\LLM\Providers\GeminiProvider::class;
}
if ($groqKey !== '') {
    $providerCatalog['groq'] = \App\Core\LLM\Providers\GroqProvider::class;
}
if ($openRouterKey !== '') {
    $providerCatalog['openrouter'] = \App\Core\LLM\Providers\OpenRouterProvider::class;
}
if ($claudeKey !== '') {
    $providerCatalog['claude'] = \App\Core\LLM\Providers\ClaudeProvider::class;
}
if ($mistralKey !== '') {
    $providerCatalog['mistral'] = \App\Core\LLM\Providers\MistralProvider::class;
}
if ($deepSeekKey !== '') {
    $providerCatalog['deepseek'] = \App\Core\LLM\Providers\DeepSeekProvider::class;
}
if (empty($providerCatalog)) {
    $missingMessage = 'No hay proveedores LLM configurados (GEMINI/GROQ/OPENROUTER/CLAUDE).';
    if (!$mandatoryEnv && $allowSkip) {
        $skipped = true;
        $skipReason = $missingMessage . ' SKIP autorizado por ALLOW_LLM_SMOKE_SKIP=1 en APP_ENV=' . $appEnv . '.';
    } else {
        $hint = $mandatoryEnv
            ? ' APP_ENV=' . $appEnv . ' exige llm_smoke obligatorio.'
            : ' Activa ALLOW_LLM_SMOKE_SKIP=1 solo para avanzar en local sin llaves.';
        $failures[] = $missingMessage . $hint;
    }
}
if ($skipped && $mandatoryEnv) {
    $skipped = false;
    $skipReason = '';
    $failures[] = 'LLM smoke no puede omitirse en APP_ENV=' . $appEnv . '.';
}
if (!$skipped && !isset($providerCatalog[$preferredProvider])) {
    $preferredProvider = (string) array_key_first($providerCatalog);
}

$capsule = [
    'intent' => 'LLM_SMOKE',
    'user_message' => 'Necesito una app para fabricar bolsos con inventario, produccion y facturacion.',
    'policy' => [
        'requires_strict_json' => true,
        'latency_budget_ms' => 1000,
        'max_output_tokens' => $maxTokens,
    ],
    // Prompt contract-first: ROLE/CONTEXT/INPUT/CONSTRAINTS/OUTPUT_FORMAT/FAIL_RULES.
    'prompt_contract' => [
        'ROLE' => 'Eres un clasificador de negocio para onboarding de apps.',
        'CONTEXT' => 'Sistema chat-first contract-first. No inventar estructura.',
        'INPUT' => [
            'user_text' => 'Necesito una app para fabricar bolsos con inventario, produccion y facturacion.',
            'known_domains' => [
                'FERRETERIA', 'FARMACIA', 'RESTAURANTE', 'MANTENIMIENTO',
                'PRODUCCION', 'BELLEZA', 'IGLESIA', 'EDUCACION', 'SERVICIOS_PRO',
            ],
        ],
        'CONSTRAINTS' => [
            'Respuesta SOLO JSON valido',
            'No markdown',
            'No inventes datos',
            'confidence entre 0 y 1',
        ],
        'OUTPUT_FORMAT' => [
            'decision' => 'MATCH|NEEDS_CLARIFICATION',
            'business_candidate' => 'string',
            'confidence' => 'number',
            'next_question' => 'string',
            'reason' => 'string',
        ],
        'FAIL_RULES' => [
            'Si confidence < 0.70 entonces decision=NEEDS_CLARIFICATION',
            'Si falta informacion critica entonces decision=NEEDS_CLARIFICATION',
        ],
    ],
];

$llmResult = null;
$lastError = null;

if (empty($failures) && !$skipped) {
    $providersConfig = [];
    foreach ($providerCatalog as $name => $className) {
        $providersConfig[$name] = [
            'enabled' => true,
            'class' => $className,
        ];
    }

    $modeForRouter = in_array($preferredProvider, ['gemini', 'groq', 'openrouter', 'claude', 'mistral', 'deepseek'], true)
        ? $preferredProvider
        : $mode;

    $router = new LLMRouter([
        'providers' => $providersConfig,
        'models' => [
            'default' => [
                'gemini' => (string) (getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash-lite'),
                'groq' => (string) (getenv('GROQ_MODEL') ?: 'llama-3.1-8b-instant'),
                'openrouter' => (string) (getenv('OPENROUTER_MODEL') ?: 'qwen/qwen-2.5-coder-32b-instruct'),
                'claude' => (string) (getenv('CLAUDE_MODEL') ?: 'claude-3-5-haiku-latest'),
                'mistral' => (string) (getenv('MISTRAL_MODEL') ?: 'pixtral-large-latest'),
                'deepseek' => (string) (getenv('DEEPSEEK_MODEL') ?: 'deepseek-chat'),
            ],
        ],
        'limits' => [
            'timeout' => 25,
            'max_tokens' => $maxTokens,
        ],
    ]);

    try {
        $llmResult = $router->chat($capsule, [
            'mode' => $modeForRouter,
            'temperature' => 0.1,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'session_id' => 'llm_smoke_' . date('Ymd_His'),
        ]);
        $lastError = null;
    } catch (\Throwable $e) {
        $lastError = LogSanitizer::sanitizeString((string) $e->getMessage());
    }
}

$durationMs = (int) round((microtime(true) - $startedAt) * 1000);
$provider = strtolower(trim((string) ($llmResult['provider'] ?? '')));
$usageRaw = is_array($llmResult['usage'] ?? null) ? (array) $llmResult['usage'] : [];
$usage = normalizeUsage($usageRaw);
$json = is_array($llmResult['json'] ?? null) ? (array) $llmResult['json'] : [];
$attemptedProviders = is_array($llmResult['attempted_providers'] ?? null) ? (array) $llmResult['attempted_providers'] : [];
$providerErrors = is_array($llmResult['provider_errors'] ?? null) ? (array) $llmResult['provider_errors'] : [];
$providerErrors = LogSanitizer::sanitizeArray($providerErrors);
$failoverCount = (int) ($llmResult['failover_count'] ?? 0);
$primaryHadQuota = false;
foreach ($providerErrors as $error) {
    $msg = strtolower(trim((string) $error));
    if ($msg !== '' && (str_contains($msg, 'quota') || str_contains($msg, '429') || str_contains($msg, 'rate limit'))) {
        $primaryHadQuota = true;
        break;
    }
}
$fallbackUsed = $failoverCount > 0 && $provider !== '' && $provider !== $preferredProvider;

$expectedKeys = ['decision', 'business_candidate', 'confidence', 'next_question', 'reason'];
$presentKeys = 0;
foreach ($expectedKeys as $key) {
    if (array_key_exists($key, $json)) {
        $presentKeys++;
    }
}

$typeChecks = 0;
$decision = strtoupper(trim((string) ($json['decision'] ?? '')));
if (in_array($decision, ['MATCH', 'NEEDS_CLARIFICATION'], true)) {
    $typeChecks++;
}
if (trim((string) ($json['business_candidate'] ?? '')) !== '') {
    $typeChecks++;
}
$confidence = $json['confidence'] ?? null;
if (is_numeric($confidence) && (float) $confidence >= 0.0 && (float) $confidence <= 1.0) {
    $typeChecks++;
}
if (trim((string) ($json['next_question'] ?? '')) !== '') {
    $typeChecks++;
}
if (trim((string) ($json['reason'] ?? '')) !== '') {
    $typeChecks++;
}

$fieldPrecision = round($presentKeys / count($expectedKeys), 4);
$typePrecision = round($typeChecks / count($expectedKeys), 4);
$overallPrecision = round(($fieldPrecision * 0.6) + ($typePrecision * 0.4), 4);
$didAttemptLlm = !$skipped && !empty($providerCatalog);
$structuredOk = !$didAttemptLlm ? true : (($presentKeys === count($expectedKeys)) && ($typeChecks >= 4));
$fallbackOk = !$didAttemptLlm ? true : ($lastError === null && (!$primaryHadQuota || $fallbackUsed));
$tokensOk = !$didAttemptLlm ? true : ((int) ($usage['total_tokens'] ?? 0) > 0);

if ($lastError !== null && !$skipped) {
    $diagnostic = probeGeminiDiagnostic();
    $suffix = $diagnostic !== '' ? ' | Gemini diagnostic: ' . $diagnostic : '';
    $failures[] = 'Fallo LLM: ' . $lastError . $suffix;
}
if (!$fallbackOk && !$skipped) {
    $failures[] = 'Fallback por quota no verificado. Provider=' . ($provider !== '' ? $provider : 'none');
}
if (!$structuredOk && !$skipped) {
    $failures[] = 'Salida estructurada invalida. Presentes=' . $presentKeys . ' TiposOK=' . $typeChecks;
}
if (!$tokensOk && !$skipped) {
    $failures[] = 'No se detectaron tokens en uso LLM.';
}

$summary = [];
if (!$skipped) {
    $telemetry = new TelemetryService();
    $status = empty($failures) ? 'success' : 'error';
    $telemetry->recordIntentMetric([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'mode' => 'builder',
        'intent' => 'LLM_SMOKE',
        'action' => 'send_to_llm',
        'latency_ms' => $durationMs,
        'status' => $status,
    ]);

    $providerForUsage = $provider !== '' ? $provider : 'gemini';
    $telemetry->recordTokenUsage([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'provider' => $providerForUsage,
        'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
        'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
        'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
    ]);
    $summary = $telemetry->summary($tenantId, $projectId, 1);
}

$report = [
    'ok' => $skipped ? true : empty($failures),
    'skipped' => $skipped,
    'skip_reason' => $skipReason,
    'generated_at' => date('c'),
    'scope' => [
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'mode' => $mode,
        'preferred_provider' => $preferredProvider,
        'app_env' => $appEnv,
        'allow_llm_smoke_skip' => $allowSkip,
        'llm_smoke_mandatory' => $mandatoryEnv,
    ],
    'attempts' => $attempts,
    'retry_sleep_seconds' => $sleepSeconds,
    'llm' => [
        'provider' => $provider,
        'fallback_verified' => $fallbackOk,
        'fallback_used' => $fallbackUsed,
        'failover_count' => $failoverCount,
        'attempted_providers' => $attemptedProviders,
        'provider_errors' => $providerErrors,
        'latency_ms' => $durationMs,
        'usage' => $usage,
        'attempt_executed' => $didAttemptLlm,
    ],
    'structured_output' => [
        'ok' => $structuredOk,
        'validation_skipped' => !$didAttemptLlm,
        'required_keys' => $expectedKeys,
        'json' => $json,
    ],
    'precision' => [
        'field_precision' => $fieldPrecision,
        'type_precision' => $typePrecision,
        'overall' => $overallPrecision,
    ],
    'cost' => [
        'summary_token_usage' => $summary['token_usage'] ?? [],
    ],
    'prompt_contract_checklist' => [
        'ROLE' => true,
        'CONTEXT' => true,
        'INPUT' => true,
        'CONSTRAINTS' => true,
        'OUTPUT_FORMAT' => true,
        'FAIL_RULES' => true,
    ],
    'failures' => $failures,
];
$report = LogSanitizer::sanitizeArray($report);

$reportPath = __DIR__ . '/tmp/llm_smoke_report.json';
if (!is_dir(dirname($reportPath))) {
    mkdir(dirname($reportPath), 0775, true);
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$report['report'] = $reportPath;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($skipped || empty($failures)) ? 0 : 1);

function normalizeUsage(array $usage): array
{
    $prompt = (int) ($usage['prompt_tokens']
        ?? $usage['promptTokenCount']
        ?? $usage['input_tokens']
        ?? $usage['inputTokenCount']
        ?? 0);
    $completion = (int) ($usage['completion_tokens']
        ?? $usage['candidatesTokenCount']
        ?? $usage['output_tokens']
        ?? $usage['outputTokenCount']
        ?? 0);
    $total = (int) ($usage['total_tokens']
        ?? $usage['totalTokenCount']
        ?? ($prompt + $completion));

    return [
        'prompt_tokens' => max(0, $prompt),
        'completion_tokens' => max(0, $completion),
        'total_tokens' => max(0, $total),
    ];
}

function probeGeminiDiagnostic(): string
{
    try {
        $client = new \App\Core\GeminiClient();
        $client->generate('ping', ['max_tokens' => 5, 'temperature' => 0.0]);
        return 'ping_ok';
    } catch (\Throwable $e) {
        return LogSanitizer::sanitizeString(trim((string) $e->getMessage()));
    }
}
