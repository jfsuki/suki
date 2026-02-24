<?php
// framework/tests/chat_stress.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\TelemetryService;

$concurrency = max(1, (int) (getenv('CHAT_STRESS_CONCURRENCY') ?: 4));
$iterations = max(5, (int) (getenv('CHAT_STRESS_ITERATIONS') ?: 40));
$tenantId = (string) (getenv('CHAT_STRESS_TENANT_ID') ?: 'default');
$projectId = (string) (getenv('CHAT_STRESS_PROJECT_ID') ?: 'default');
$mode = (string) (getenv('CHAT_STRESS_MODE') ?: 'app');

$p95Max = max(1, (int) (getenv('CHAT_STRESS_P95_MAX_MS') ?: 1200));
$p99Max = max(1, (int) (getenv('CHAT_STRESS_P99_MAX_MS') ?: 1800));
$errorRateMax = (float) (getenv('CHAT_STRESS_MAX_ERROR_RATE') ?: 0.05);

$workerScript = __DIR__ . '/chat_stress_worker.php';
$processes = [];
$outputs = [];

for ($w = 0; $w < $concurrency; $w++) {
    $config = [
        'worker_id' => $w + 1,
        'iterations' => $iterations,
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'mode' => $mode,
        'session_base' => 'stress_chat_' . time(),
        'user_base' => 'stress_user_' . time(),
    ];
    $encoded = base64_encode((string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($encoded);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 1));
    if (!is_resource($proc)) {
        continue;
    }
    $processes[] = [
        'proc' => $proc,
        'pipes' => $pipes,
        'worker' => $w + 1,
    ];
}

$totalRequested = $concurrency * $iterations;
$latencies = [];
$okCount = 0;
$errorCount = 0;
$workerErrors = [];

foreach ($processes as $entry) {
    $stdout = stream_get_contents($entry['pipes'][1]);
    $stderr = stream_get_contents($entry['pipes'][2]);
    fclose($entry['pipes'][1]);
    fclose($entry['pipes'][2]);
    $exit = proc_close($entry['proc']);

    $decoded = json_decode((string) $stdout, true);
    if ($exit !== 0 || !is_array($decoded)) {
        $workerErrors[] = [
            'worker' => $entry['worker'],
            'exit_code' => $exit,
            'stderr' => trim((string) $stderr),
            'stdout' => trim((string) $stdout),
        ];
        continue;
    }

    $outputs[] = $decoded;
    $latencies = array_merge($latencies, array_map('intval', (array) ($decoded['latencies_ms'] ?? [])));
    $okCount += (int) ($decoded['ok_count'] ?? 0);
    $errorCount += (int) ($decoded['error_count'] ?? 0);
}

$completed = $okCount + $errorCount;
$errorRate = $completed > 0 ? ($errorCount / $completed) : 1.0;
$summary = [
    'requested_calls' => $totalRequested,
    'completed_calls' => $completed,
    'ok_count' => $okCount,
    'error_count' => $errorCount,
    'error_rate' => round($errorRate, 6),
    'p50_latency_ms' => percentile($latencies, 50),
    'p95_latency_ms' => percentile($latencies, 95),
    'p99_latency_ms' => percentile($latencies, 99),
    'min_latency_ms' => empty($latencies) ? 0 : min($latencies),
    'max_latency_ms' => empty($latencies) ? 0 : max($latencies),
    'avg_latency_ms' => empty($latencies) ? 0 : (int) round(array_sum($latencies) / max(1, count($latencies))),
];

$telemetry = [];
try {
    $telemetry = (new TelemetryService())->summary($tenantId, $projectId, 1);
} catch (\Throwable $e) {
    $telemetry = ['error' => $e->getMessage()];
}

$failures = [];
if (!empty($workerErrors)) {
    $failures[] = 'One or more stress workers failed.';
}
if ($summary['completed_calls'] < $totalRequested) {
    $failures[] = 'Not all chat stress calls completed.';
}
if ($summary['p95_latency_ms'] > $p95Max) {
    $failures[] = 'p95 latency exceeds threshold (' . $summary['p95_latency_ms'] . ' > ' . $p95Max . ').';
}
if ($summary['p99_latency_ms'] > $p99Max) {
    $failures[] = 'p99 latency exceeds threshold (' . $summary['p99_latency_ms'] . ' > ' . $p99Max . ').';
}
if ($summary['error_rate'] > $errorRateMax) {
    $failures[] = 'error rate exceeds threshold (' . $summary['error_rate'] . ' > ' . $errorRateMax . ').';
}

$ok = empty($failures);
$result = [
    'ok' => $ok,
    'suite' => 'chat_stress',
    'config' => [
        'concurrency' => $concurrency,
        'iterations_per_worker' => $iterations,
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'mode' => $mode,
        'thresholds' => [
            'p95_max_ms' => $p95Max,
            'p99_max_ms' => $p99Max,
            'max_error_rate' => $errorRateMax,
        ],
    ],
    'metrics' => $summary,
    'telemetry_summary' => $telemetry,
    'worker_errors' => $workerErrors,
    'failures' => $failures,
];

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
$reportPath = $tmpDir . '/chat_stress_result.json';
file_put_contents($reportPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$result['report'] = $reportPath;

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

function percentile(array $values, int $percentile): int
{
    if (empty($values)) {
        return 0;
    }
    sort($values);
    $index = (int) ceil(($percentile / 100) * count($values)) - 1;
    $index = max(0, min(count($values) - 1, $index));
    return (int) $values[$index];
}

