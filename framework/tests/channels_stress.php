<?php
// framework/tests/channels_stress.php

declare(strict_types=1);

$concurrency = max(1, (int) (getenv('CHANNEL_STRESS_CONCURRENCY') ?: 3));
$iterations = max(3, (int) (getenv('CHANNEL_STRESS_ITERATIONS') ?: 20));
$tenantId = (string) (getenv('CHANNEL_STRESS_TENANT_ID') ?: 'default');
$projectId = (string) (getenv('CHANNEL_STRESS_PROJECT_ID') ?: 'default');

$p95Max = max(1, (int) (getenv('CHANNEL_STRESS_P95_MAX_MS') ?: 1800));
$p99Max = max(1, (int) (getenv('CHANNEL_STRESS_P99_MAX_MS') ?: 2600));
$errorRateMax = (float) (getenv('CHANNEL_STRESS_MAX_ERROR_RATE') ?: 0.05);

$workerScript = __DIR__ . '/channels_stress_worker.php';
$processes = [];

for ($w = 0; $w < $concurrency; $w++) {
    $config = [
        'worker_id' => $w + 1,
        'iterations' => $iterations,
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
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

$latTg = [];
$latWa = [];
$tgOk = 0;
$tgErr = 0;
$waOk = 0;
$waErr = 0;
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

    $latTg = array_merge($latTg, array_map('intval', (array) (($decoded['telegram']['latencies_ms'] ?? []))));
    $latWa = array_merge($latWa, array_map('intval', (array) (($decoded['whatsapp']['latencies_ms'] ?? []))));
    $tgOk += (int) ($decoded['telegram']['ok_count'] ?? 0);
    $tgErr += (int) ($decoded['telegram']['error_count'] ?? 0);
    $waOk += (int) ($decoded['whatsapp']['ok_count'] ?? 0);
    $waErr += (int) ($decoded['whatsapp']['error_count'] ?? 0);

    $errors = (array) ($decoded['errors'] ?? []);
    foreach ($errors as $err) {
        if (count($workerErrors) >= 10) {
            break;
        }
        if (is_array($err)) {
            $workerErrors[] = $err;
        }
    }
}

$tgTotal = $tgOk + $tgErr;
$waTotal = $waOk + $waErr;
$globalTotal = $tgTotal + $waTotal;
$globalErr = $tgErr + $waErr;
$globalErrorRate = $globalTotal > 0 ? ($globalErr / $globalTotal) : 1.0;

$metrics = [
    'telegram' => [
        'requested' => $concurrency * $iterations,
        'completed' => $tgTotal,
        'ok_count' => $tgOk,
        'error_count' => $tgErr,
        'error_rate' => $tgTotal > 0 ? round($tgErr / $tgTotal, 6) : 1.0,
        'p50_latency_ms' => percentile($latTg, 50),
        'p95_latency_ms' => percentile($latTg, 95),
        'p99_latency_ms' => percentile($latTg, 99),
    ],
    'whatsapp' => [
        'requested' => $concurrency * $iterations,
        'completed' => $waTotal,
        'ok_count' => $waOk,
        'error_count' => $waErr,
        'error_rate' => $waTotal > 0 ? round($waErr / $waTotal, 6) : 1.0,
        'p50_latency_ms' => percentile($latWa, 50),
        'p95_latency_ms' => percentile($latWa, 95),
        'p99_latency_ms' => percentile($latWa, 99),
    ],
    'global' => [
        'requested' => ($concurrency * $iterations) * 2,
        'completed' => $globalTotal,
        'error_rate' => round($globalErrorRate, 6),
        'p95_latency_ms' => percentile(array_merge($latTg, $latWa), 95),
        'p99_latency_ms' => percentile(array_merge($latTg, $latWa), 99),
    ],
];

$failures = [];
if ($metrics['telegram']['completed'] < $metrics['telegram']['requested']) {
    $failures[] = 'telegram channel did not complete all stress calls.';
}
if ($metrics['whatsapp']['completed'] < $metrics['whatsapp']['requested']) {
    $failures[] = 'whatsapp channel did not complete all stress calls.';
}
if ($metrics['global']['p95_latency_ms'] > $p95Max) {
    $failures[] = 'channel p95 latency exceeds threshold (' . $metrics['global']['p95_latency_ms'] . ' > ' . $p95Max . ').';
}
if ($metrics['global']['p99_latency_ms'] > $p99Max) {
    $failures[] = 'channel p99 latency exceeds threshold (' . $metrics['global']['p99_latency_ms'] . ' > ' . $p99Max . ').';
}
if ($metrics['global']['error_rate'] > $errorRateMax) {
    $failures[] = 'channel error rate exceeds threshold (' . $metrics['global']['error_rate'] . ' > ' . $errorRateMax . ').';
}
if (!empty($workerErrors) && count($workerErrors) > 0) {
    // Only treat hard worker failures as blocking.
    foreach ($workerErrors as $err) {
        if (is_array($err) && isset($err['exit_code'])) {
            $failures[] = 'one or more channel stress workers failed.';
            break;
        }
    }
}

$ok = empty($failures);
$result = [
    'ok' => $ok,
    'suite' => 'channels_stress',
    'config' => [
        'concurrency' => $concurrency,
        'iterations_per_worker' => $iterations,
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'thresholds' => [
            'p95_max_ms' => $p95Max,
            'p99_max_ms' => $p99Max,
            'max_error_rate' => $errorRateMax,
        ],
    ],
    'metrics' => $metrics,
    'worker_errors' => $workerErrors,
    'failures' => $failures,
];

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
$reportPath = $tmpDir . '/channels_stress_result.json';
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

