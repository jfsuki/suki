<?php
// framework/tests/conversation_kpi_gate.php
// Gate de precision conversacional para pre-release.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\TelemetryService;

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}

$tenantId = trim((string) (getenv('KPI_TENANT_ID') ?: 'default'));
$projectId = trim((string) (getenv('KPI_PROJECT_ID') ?: 'suki_erp'));
$days = max(1, min(30, (int) (getenv('KPI_DAYS') ?: 1)));

$thresholds = [
    'min_intent_accuracy' => max(0.0, min(1.0, (float) (getenv('KPI_MIN_INTENT_ACCURACY') ?: 0.9))),
    'min_correction_success' => max(0.0, min(1.0, (float) (getenv('KPI_MIN_CORRECTION_SUCCESS') ?: 0.95))),
    'min_unknown_business_success' => max(0.0, min(1.0, (float) (getenv('KPI_MIN_UNKNOWN_SUCCESS') ?: 0.9))),
    'max_fallback_rate' => max(0.0, min(1.0, (float) (getenv('KPI_MAX_FALLBACK_RATE') ?: 0.45))),
    'max_tokens_per_session' => max(1.0, (float) (getenv('KPI_MAX_TOKENS_PER_SESSION') ?: 12000)),
    'max_cost_per_session_usd' => max(0.000001, (float) (getenv('KPI_MAX_COST_PER_SESSION_USD') ?: 0.05)),
];

$reports = [
    'chat_real_100' => loadJsonFile($tmpDir . '/chat_real_100_result.json'),
    'chat_golden' => loadJsonFile($tmpDir . '/chat_golden_result.json'),
];

$failures = [];

$intentMetrics = intentAccuracyMetrics($reports['chat_real_100'], $failures);
$correctionSuccess = correctionSuccessMetric($reports['chat_golden'], $failures);
$unknownSuccess = unknownBusinessMetric($reports['chat_golden'], $failures);

$ops = (new TelemetryService())->summary($tenantId, $projectId, $days);
$fallbackCount = (int) (($ops['intent_metrics']['fallback_llm'] ?? 0));
$intentCount = (int) (($ops['intent_metrics']['count'] ?? 0));
$fallbackRate = (float) (($ops['intent_metrics']['fallback_rate'] ?? ($intentCount > 0 ? ($fallbackCount / $intentCount) : 0.0)));
$avgTokensPerSession = (float) (($ops['token_usage']['avg_tokens_per_session'] ?? 0.0));
$avgCostPerSession = (float) (($ops['token_usage']['avg_cost_per_session_usd'] ?? 0.0));
$tokenEvents = (int) (($ops['token_usage']['events'] ?? 0));

if (($intentMetrics['overall'] ?? 0.0) < $thresholds['min_intent_accuracy']) {
    $failures[] = 'Intent accuracy debajo del umbral.';
}
if ($correctionSuccess < $thresholds['min_correction_success']) {
    $failures[] = 'Exito de correccion de negocio debajo del umbral.';
}
if ($unknownSuccess < $thresholds['min_unknown_business_success']) {
    $failures[] = 'Exito en unknown-business discovery debajo del umbral.';
}
if ($intentCount > 0 && $fallbackRate > $thresholds['max_fallback_rate']) {
    $failures[] = 'Tasa de fallback LLM por encima del umbral.';
}
if ($tokenEvents > 0 && $avgTokensPerSession > $thresholds['max_tokens_per_session']) {
    $failures[] = 'Tokens promedio por sesion por encima del umbral.';
}
if ($tokenEvents > 0 && $avgCostPerSession > $thresholds['max_cost_per_session_usd']) {
    $failures[] = 'Costo promedio por sesion por encima del umbral.';
}

$ok = empty($failures);
$report = [
    'ok' => $ok,
    'generated_at' => date('c'),
    'scope' => [
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'days' => $days,
    ],
    'thresholds' => $thresholds,
    'kpis' => [
        'intent_accuracy' => $intentMetrics,
        'correction_success' => round($correctionSuccess, 6),
        'unknown_business_success' => round($unknownSuccess, 6),
        'fallback_rate' => round($fallbackRate, 6),
        'avg_tokens_per_session' => round($avgTokensPerSession, 4),
        'avg_cost_per_session_usd' => round($avgCostPerSession, 8),
    ],
    'ops_summary' => [
        'intent_metrics' => $ops['intent_metrics'] ?? [],
        'token_usage' => $ops['token_usage'] ?? [],
    ],
    'failures' => $failures,
];

$outputPath = $tmpDir . '/conversation_kpi_gate_result.json';
file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$report['report'] = $outputPath;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

function loadJsonFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($json) ? $json : null;
}

function intentAccuracyMetrics(?array $chatReal100, array &$failures): array
{
    if (!is_array($chatReal100)) {
        $failures[] = 'No existe chat_real_100_result.json para medir accuracy de intents.';
        return ['overall' => 0.0, 'sector_positive' => 0.0, 'hard_negative' => 0.0];
    }

    $results = is_array($chatReal100['results'] ?? null) ? (array) $chatReal100['results'] : [];
    $positiveTotal = 0;
    $positiveOk = 0;
    $negativeTotal = 0;
    $negativeOk = 0;

    foreach ($results as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = (string) ($row['name'] ?? '');
        $ok = !empty($row['ok']);
        if (str_starts_with($name, 'sector_positive_')) {
            $positiveTotal++;
            if ($ok) {
                $positiveOk++;
            }
        }
        if (str_starts_with($name, 'sector_hard_negative_')) {
            $negativeTotal++;
            if ($ok) {
                $negativeOk++;
            }
        }
    }

    $positiveRate = $positiveTotal > 0 ? $positiveOk / $positiveTotal : 0.0;
    $negativeRate = $negativeTotal > 0 ? $negativeOk / $negativeTotal : 0.0;
    $overallTotal = $positiveTotal + $negativeTotal;
    $overallRate = $overallTotal > 0 ? (($positiveOk + $negativeOk) / $overallTotal) : 0.0;

    if ($positiveTotal === 0 || $negativeTotal === 0) {
        $failures[] = 'chat_real_100 no tiene cobertura suficiente de intent positivos y hard-negatives.';
    }

    return [
        'overall' => round($overallRate, 6),
        'sector_positive' => round($positiveRate, 6),
        'hard_negative' => round($negativeRate, 6),
        'sector_positive_total' => $positiveTotal,
        'hard_negative_total' => $negativeTotal,
    ];
}

function correctionSuccessMetric(?array $chatGolden, array &$failures): float
{
    if (!is_array($chatGolden)) {
        $failures[] = 'No existe chat_golden_result.json para medir correction_success.';
        return 0.0;
    }
    $rows = is_array($chatGolden['results'] ?? null) ? (array) $chatGolden['results'] : [];
    $target = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $message = strtolower(trim((string) ($row['message'] ?? '')));
        if (str_contains($message, 'no soy una ferreteria')) {
            $target[] = $row;
        }
    }
    if (empty($target)) {
        $failures[] = 'chat_golden no contiene caso de correccion de negocio.';
        return 0.0;
    }
    $ok = count(array_filter($target, static fn(array $row): bool => !empty($row['ok'])));
    return $ok / count($target);
}

function unknownBusinessMetric(?array $chatGolden, array &$failures): float
{
    if (!is_array($chatGolden)) {
        $failures[] = 'No existe chat_golden_result.json para medir unknown_business_success.';
        return 0.0;
    }
    $rows = is_array($chatGolden['results'] ?? null) ? (array) $chatGolden['results'] : [];
    $target = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $message = strtolower(trim((string) ($row['message'] ?? '')));
        if (
            str_contains($message, 'laboratorio de velas aromaticas')
            || str_contains($message, 'quiero controlar produccion, inventario y facturacion')
        ) {
            $target[] = $row;
        }
    }
    if (empty($target)) {
        $failures[] = 'chat_golden no contiene caso de unknown-business discovery.';
        return 0.0;
    }
    $ok = count(array_filter($target, static fn(array $row): bool => !empty($row['ok'])));
    return $ok / count($target);
}
