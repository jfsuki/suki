<?php
// framework/tests/llm_gemini_staging_smoke.php
// Smoke dedicado para staging: Gemini primario estricto + fallback a OpenRouter + tokens/costo.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\LLM\LLMRouter;
use App\Core\TelemetryService;

$tenantId = trim((string) (getenv('LLM_SMOKE_TENANT_ID') ?: 'default'));
$projectId = trim((string) (getenv('LLM_SMOKE_PROJECT_ID') ?: 'staging_llm_smoke'));
$maxTokens = max(120, (int) (getenv('LLM_SMOKE_MAX_TOKENS') ?: 320));
$checkFallback = strtolower(trim((string) (getenv('LLM_GEMINI_SMOKE_CHECK_FALLBACK') ?: '1'))) !== '0';
$maxRetries = max(1, min(5, (int) (getenv('LLM_GEMINI_SMOKE_RETRIES') ?: 3)));

$geminiKey = trim((string) getenv('GEMINI_API_KEY'));
$openRouterKey = trim((string) getenv('OPENROUTER_API_KEY'));
$originalGeminiModel = trim((string) getenv('GEMINI_MODEL'));
$modelCandidatesRaw = trim((string) (getenv('LLM_GEMINI_SMOKE_MODELS') ?: ''));
$modelCandidates = [];
if ($modelCandidatesRaw !== '') {
    foreach (explode(',', $modelCandidatesRaw) as $candidate) {
        $value = trim((string) $candidate);
        if ($value !== '') {
            $modelCandidates[] = $value;
        }
    }
}
if ($modelCandidates === []) {
    $seed = $originalGeminiModel !== '' ? $originalGeminiModel : 'gemini-2.5-flash-lite';
    $modelCandidates = [$seed, 'gemini-2.0-flash', 'gemini-1.5-flash'];
}
$modelCandidates = array_values(array_unique($modelCandidates));
$failures = [];
$cases = [];

if ($geminiKey === '') {
    $failures[] = 'GEMINI_API_KEY no configurado.';
}

$capsule = [
    'intent' => 'LLM_GEMINI_STAGING_SMOKE',
    'user_message' => 'Mi empresa es una panaderia y cafeteria. Necesito inventario, facturacion y pagos.',
    'policy' => [
        'requires_strict_json' => true,
        'latency_budget_ms' => 2500,
        'max_output_tokens' => $maxTokens,
    ],
    'prompt_contract' => [
        'ROLE' => 'Domain Classification Assistant',
        'CONTEXT' => [
            'language' => 'es-CO',
            'goal' => 'clasificar negocio en onboarding sin inventar',
        ],
        'INPUT' => [
            'user_text' => 'Mi empresa es una panaderia y cafeteria. Necesito inventario, facturacion y pagos.',
            'business_candidate' => 'panaderia y cafeteria',
        ],
        'CONSTRAINTS' => [
            'no_invent_data' => true,
            'output_json_only' => true,
            'if_status_MATCHED_needs_documents_min_1' => true,
        ],
        'OUTPUT_FORMAT' => [
            'status' => 'MATCHED|NEW_BUSINESS|NEEDS_CLARIFICATION|INVALID_REQUEST',
            'confidence' => '0.0-1.0',
            'canonical_business_type' => '<known_profile_or_empty>',
            'business_candidate' => '<texto_normalizado>',
            'reason_short' => '<breve>',
            'needs_normalized' => ['string'],
            'documents_normalized' => ['string'],
            'clarifying_question' => '<si aplica>',
        ],
        'FAIL_RULES' => [
            'if_confidence_below' => 0.7,
            'return_on_low_confidence' => 'NEEDS_CLARIFICATION',
        ],
    ],
];

if (empty($failures)) {
    // Caso A: Gemini estricto sin fallback.
    clearRouterCircuit();
    $geminiStart = microtime(true);
    $geminiResult = null;
    $geminiError = null;
    $geminiModelUsed = '';
    $retryLog = [];
    foreach ($modelCandidates as $modelCandidate) {
        putenv('GEMINI_MODEL=' . $modelCandidate);
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                clearRouterCircuit();
                $routerGeminiOnly = new LLMRouter([
                    'providers' => [
                        'gemini' => [
                            'enabled' => true,
                            'class' => \App\Core\LLM\Providers\GeminiProvider::class,
                        ],
                    ],
                    'models' => [
                        'default' => [
                            'gemini' => (string) (getenv('GEMINI_MODEL') ?: $modelCandidate),
                        ],
                    ],
                    'limits' => ['timeout' => 25, 'max_tokens' => $maxTokens],
                ]);

                $geminiResult = $routerGeminiOnly->chat($capsule, [
                    'mode' => 'gemini',
                    'temperature' => 0.1,
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'session_id' => 'gemini_strict_' . date('Ymd_His') . '_' . $attempt,
                ]);
                $geminiError = null;
                $geminiModelUsed = $modelCandidate;
                break 2;
            } catch (\Throwable $e) {
                $geminiError = $e->getMessage();
                $retryLog[] = [
                    'model' => $modelCandidate,
                    'attempt' => $attempt,
                    'error' => $geminiError,
                ];
                if (!isTransientGeminiError($geminiError) || $attempt >= $maxRetries) {
                    break;
                }
                sleep($attempt);
            }
        }
    }
    putenv('GEMINI_MODEL=' . $originalGeminiModel);
    $geminiLatencyMs = (int) round((microtime(true) - $geminiStart) * 1000);
    $geminiUsage = normalizeUsage(is_array($geminiResult['usage'] ?? null) ? (array) $geminiResult['usage'] : []);
    $geminiJson = is_array($geminiResult['json'] ?? null) ? (array) $geminiResult['json'] : [];
    $geminiQuality = evaluateStructuredResponse($geminiJson);
    $geminiProvider = strtolower(trim((string) ($geminiResult['provider'] ?? '')));
    $geminiOk = $geminiError === null
        && $geminiProvider === 'gemini'
        && $geminiQuality['ok']
        && (int) ($geminiUsage['total_tokens'] ?? 0) > 0;

    if (!$geminiOk) {
        $failures[] = 'Caso Gemini estricto fallo.';
    }

    $cases['gemini_strict'] = [
        'ok' => $geminiOk,
        'provider' => $geminiProvider,
        'latency_ms' => $geminiLatencyMs,
        'usage' => $geminiUsage,
        'structured' => $geminiQuality,
        'error' => $geminiError,
        'model_used' => $geminiModelUsed,
        'retry_log' => $retryLog,
        'json' => $geminiJson,
    ];

    // Caso B: fallback (Gemini invalido -> OpenRouter) si OpenRouter disponible.
    if ($checkFallback && $openRouterKey !== '') {
        clearRouterCircuit();
        $origGemini = (string) getenv('GEMINI_API_KEY');
        $origModel = (string) getenv('GEMINI_MODEL');
        $fallbackModel = $modelCandidates[0] ?? ($origModel !== '' ? $origModel : 'gemini-2.5-flash-lite');
        putenv('GEMINI_MODEL=' . $fallbackModel);
        putenv('GEMINI_API_KEY=invalid_staging_key_smoke');
        $fallbackStart = microtime(true);
        $fallbackResult = null;
        $fallbackError = null;
        try {
            $routerFallback = new LLMRouter([
                'providers' => [
                    'gemini' => [
                        'enabled' => true,
                        'class' => \App\Core\LLM\Providers\GeminiProvider::class,
                    ],
                    'openrouter' => [
                        'enabled' => true,
                        'class' => \App\Core\LLM\Providers\OpenRouterProvider::class,
                    ],
                ],
                'models' => [
                    'default' => [
                        'gemini' => (string) (getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash-lite'),
                        'openrouter' => (string) (getenv('OPENROUTER_MODEL') ?: 'openrouter/free'),
                    ],
                ],
                'limits' => ['timeout' => 25, 'max_tokens' => $maxTokens],
            ]);

            $fallbackResult = $routerFallback->chat($capsule, [
                'mode' => 'gemini',
                'temperature' => 0.1,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'session_id' => 'gemini_fallback_' . date('Ymd_His'),
            ]);
        } catch (\Throwable $e) {
            $fallbackError = $e->getMessage();
        } finally {
            putenv('GEMINI_API_KEY=' . $origGemini);
            putenv('GEMINI_MODEL=' . $origModel);
        }
        $fallbackLatencyMs = (int) round((microtime(true) - $fallbackStart) * 1000);
        $fallbackUsage = normalizeUsage(is_array($fallbackResult['usage'] ?? null) ? (array) $fallbackResult['usage'] : []);
        $fallbackJson = is_array($fallbackResult['json'] ?? null) ? (array) $fallbackResult['json'] : [];
        $fallbackQuality = evaluateStructuredResponse($fallbackJson);
        $attempted = is_array($fallbackResult['attempted_providers'] ?? null)
            ? array_values((array) $fallbackResult['attempted_providers'])
            : [];
        $providerErrors = is_array($fallbackResult['provider_errors'] ?? null)
            ? (array) $fallbackResult['provider_errors']
            : [];
        $fallbackProvider = strtolower(trim((string) ($fallbackResult['provider'] ?? '')));
        $fallbackOk = $fallbackError === null
            && $fallbackProvider === 'openrouter'
            && in_array('gemini', $attempted, true)
            && in_array('openrouter', $attempted, true)
            && isset($providerErrors['gemini'])
            && $fallbackQuality['ok']
            && (int) ($fallbackUsage['total_tokens'] ?? 0) > 0;

        if (!$fallbackOk) {
            $failures[] = 'Caso fallback Gemini->OpenRouter fallo.';
        }

        $cases['gemini_fallback'] = [
            'ok' => $fallbackOk,
            'provider' => $fallbackProvider,
            'latency_ms' => $fallbackLatencyMs,
            'attempted_providers' => $attempted,
            'provider_errors' => $providerErrors,
            'usage' => $fallbackUsage,
            'structured' => $fallbackQuality,
            'error' => $fallbackError,
            'model_used' => $fallbackModel,
            'json' => $fallbackJson,
        ];
    } else {
        $cases['gemini_fallback'] = [
            'ok' => !$checkFallback || $openRouterKey === '',
            'skipped' => true,
            'reason' => $checkFallback ? 'OPENROUTER_API_KEY no configurado' : 'LLM_GEMINI_SMOKE_CHECK_FALLBACK=0',
        ];
    }
}

$telemetry = new TelemetryService();
$summary = $telemetry->summary($tenantId, $projectId, 1);

$report = [
    'ok' => empty($failures),
    'generated_at' => date('c'),
        'scope' => [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
        'gemini_model' => $originalGeminiModel !== '' ? $originalGeminiModel : 'gemini-2.5-flash-lite',
        'openrouter_model' => (string) (getenv('OPENROUTER_MODEL') ?: ''),
        'gemini_models_tested' => $modelCandidates,
        'gemini_retries' => $maxRetries,
    ],
    'cases' => $cases,
    'cost' => [
        'token_usage_summary' => $summary['token_usage'] ?? [],
    ],
    'failures' => $failures,
];

$reportPath = __DIR__ . '/tmp/llm_gemini_staging_smoke_report.json';
if (!is_dir(dirname($reportPath))) {
    mkdir(dirname($reportPath), 0775, true);
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$report['report'] = $reportPath;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(empty($failures) ? 0 : 1);

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

function evaluateStructuredResponse(array $json): array
{
    $issues = [];
    $status = strtoupper(trim((string) ($json['status'] ?? '')));
    $allowed = ['MATCHED', 'NEW_BUSINESS', 'NEEDS_CLARIFICATION', 'INVALID_REQUEST'];
    if (!in_array($status, $allowed, true)) {
        $issues[] = 'status_invalido';
    }

    $confidence = $json['confidence'] ?? null;
    if (!is_numeric($confidence) || (float) $confidence < 0.0 || (float) $confidence > 1.0) {
        $issues[] = 'confidence_invalido';
    }

    $candidate = trim((string) ($json['business_candidate'] ?? ''));
    if ($candidate === '') {
        $issues[] = 'candidate_vacio';
    }

    if (!isset($json['needs_normalized']) || !is_array($json['needs_normalized'])) {
        $issues[] = 'needs_no_array';
    }
    if (!isset($json['documents_normalized']) || !is_array($json['documents_normalized'])) {
        $issues[] = 'documents_no_array';
    }
    if ($status === 'MATCHED') {
        $needs = is_array($json['needs_normalized'] ?? null) ? (array) $json['needs_normalized'] : [];
        $docs = is_array($json['documents_normalized'] ?? null) ? (array) $json['documents_normalized'] : [];
        if (count($needs) < 1) {
            $issues[] = 'matched_needs_vacio';
        }
        if (count($docs) < 1) {
            $issues[] = 'matched_documents_vacio';
        }
    }

    $score = 1.0;
    $score -= count($issues) * 0.2;
    $score = max(0.0, min(1.0, $score));

    return [
        'ok' => empty($issues),
        'score' => round($score, 4),
        'issues' => $issues,
    ];
}

function isTransientGeminiError(string $message): bool
{
    $message = strtolower(trim($message));
    if ($message === '') {
        return false;
    }
    $needles = [
        'high demand',
        'resource has been exhausted',
        'rate limit',
        'quota',
        '429',
        'temporarily unavailable',
        'timeout',
        'deadline',
    ];
    foreach ($needles as $needle) {
        if (str_contains($message, $needle)) {
            return true;
        }
    }
    return false;
}

function clearRouterCircuit(): void
{
    try {
        $ref = new ReflectionClass(LLMRouter::class);
        if ($ref->hasProperty('circuit')) {
            $prop = $ref->getProperty('circuit');
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }
    } catch (\Throwable $e) {
        // no-op en smoke
    }
}
