<?php
// bin/worker.php

declare(strict_types=1);

$workspaceRoot = dirname(__DIR__);
$projectRoot = $workspaceRoot . '/project';
$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: ($workspaceRoot . '/framework');

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', $projectRoot);
}
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $frameworkRoot);
}
if (!defined('FRAMEWORK_ROOT')) {
    define('FRAMEWORK_ROOT', $frameworkRoot);
}

$envLoader = PROJECT_ROOT . '/config/env_loader.php';
if (is_file($envLoader)) {
    require_once $envLoader;
}

require_once FRAMEWORK_ROOT . '/vendor/autoload.php';
require_once FRAMEWORK_ROOT . '/app/autoload.php';

use App\Core\OperationalQueueStore;

function workerOptionBool(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function workerOptionValue(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, $name . '=')) {
            continue;
        }
        $parts = explode('=', $arg, 2);
        return $parts[1] ?? $default;
    }
    return $default;
}

/**
 * Process queue payload plumbing only.
 * No LLM/Qdrant/business execution at this stage.
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function processQueuedJob(array $job): array
{
    $jobType = trim((string) ($job['job_type'] ?? ''));
    $payload = is_array($job['payload'] ?? null) ? (array) $job['payload'] : [];

    if ($jobType === 'telegram.inbound' || $jobType === 'whatsapp.inbound') {
        $channel = $jobType === 'whatsapp.inbound' ? 'whatsapp' : 'telegram';
        $idempotencyHint = trim((string) ($payload['update_id'] ?? $payload['message_id'] ?? ''));
        return [
            'processed' => true,
            'channel' => $channel,
            'job_type' => $jobType,
            'idempotency_hint' => $idempotencyHint,
            'route_path' => 'queue>router>gates>action',
            'gate_decision' => 'plumbing_only',
            'mode' => 'plumbing_only',
        ];
    }

    return [
        'processed' => true,
        'job_type' => $jobType !== '' ? $jobType : 'unknown',
        'mode' => 'plumbing_only',
    ];
}

$argv = $_SERVER['argv'] ?? [];
$once = workerOptionBool($argv, '--once');
$sleepSeconds = (int) (workerOptionValue($argv, '--sleep', '2') ?? '2');
$sleepSeconds = max(1, min(30, $sleepSeconds));
$maxJobs = (int) (workerOptionValue($argv, '--max-jobs', $once ? '1' : '0') ?? '0');
$maxJobs = max(0, $maxJobs);
$workerId = (string) (gethostname() ?: 'host') . ':' . (string) getmypid();

$processed = 0;
$failed = 0;
$startedAt = date('Y-m-d H:i:s');

try {
    $queue = new OperationalQueueStore();
} catch (\Throwable $e) {
    fwrite(STDERR, '[worker] queue init failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

while (true) {
    if ($maxJobs > 0 && $processed >= $maxJobs) {
        break;
    }

    try {
        $job = $queue->lockNextJob($workerId);
    } catch (\Throwable $e) {
        fwrite(STDERR, '[worker] lockNextJob failed: ' . $e->getMessage() . PHP_EOL);
        if ($once) {
            break;
        }
        sleep($sleepSeconds);
        continue;
    }

    if (!is_array($job)) {
        if ($once) {
            break;
        }
        sleep($sleepSeconds);
        continue;
    }

    $jobId = (int) ($job['id'] ?? 0);
    if ($jobId <= 0) {
        if ($once) {
            break;
        }
        sleep($sleepSeconds);
        continue;
    }

    try {
        $result = processQueuedJob($job);
        $queue->ackJob($jobId);
        $processed++;
        echo json_encode([
            'ok' => true,
            'worker_id' => $workerId,
            'job_id' => $jobId,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (\Throwable $e) {
        $failed++;
        $queue->failJob($jobId, [
            'error' => $e->getMessage(),
            'at' => date('Y-m-d H:i:s'),
        ]);
        fwrite(STDERR, '[worker] job failed id=' . $jobId . ': ' . $e->getMessage() . PHP_EOL);
    }

    if ($once) {
        break;
    }
}

echo json_encode([
    'ok' => true,
    'worker_id' => $workerId,
    'once' => $once,
    'started_at' => $startedAt,
    'finished_at' => date('Y-m-d H:i:s'),
    'processed' => $processed,
    'failed' => $failed,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
