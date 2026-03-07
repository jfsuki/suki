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

use App\Core\ChatAgent;
use App\Core\LogSanitizer;
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

function workerSafeKey(string $value): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $value) ?? '';
    $safe = trim($safe, '_');
    return $safe !== '' ? $safe : 'unknown';
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function buildInboundChatPayload(string $jobType, array $payload): array
{
    $channel = $jobType === 'whatsapp.inbound' ? 'whatsapp' : 'telegram';
    $tenantId = trim((string) ($payload['tenant_id'] ?? 'default'));
    if ($tenantId === '') {
        $tenantId = 'default';
    }
    $projectId = trim((string) ($payload['project_id'] ?? 'default'));
    if ($projectId === '') {
        $projectId = 'default';
    }

    $text = trim((string) ($payload['text'] ?? ''));
    $externalUser = $channel === 'whatsapp'
        ? trim((string) ($payload['from'] ?? ''))
        : trim((string) ($payload['chat_id'] ?? ''));
    if ($externalUser === '') {
        $externalUser = 'unknown';
    }
    $externalUserSafe = workerSafeKey($externalUser);
    $sessionId = 'queue_' . $channel . '_' . $externalUserSafe;
    $userId = $channel . '_' . $externalUserSafe;

    $messageId = trim((string) ($payload['message_id'] ?? $payload['update_id'] ?? ''));
    if ($messageId === '') {
        $messageId = $channel . '_hash_' . substr(hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 16);
    }

    return [
        'processable' => $text !== '',
        'reason' => $text !== '' ? 'ok' : 'empty_text_payload',
        'channel' => $channel,
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionId,
        'user_id' => $userId,
        'message_id' => $messageId,
        'message_text' => $text,
        'chat_payload' => [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'channel' => $channel,
            'mode' => 'app',
            'session_id' => $sessionId,
            'user_id' => $userId,
            'message_id' => $messageId,
            'message' => $text,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function findLatestAgentOpsEvent(string $tenantId, string $sessionId, string $messageId): array
{
    $tenantSafe = workerSafeKey($tenantId);
    $baseDir = PROJECT_ROOT . '/storage/tenants/' . $tenantSafe . '/telemetry';
    if (!is_dir($baseDir)) {
        return [];
    }

    $dateCandidates = [
        date('Y-m-d'),
        date('Y-m-d', strtotime('-1 day')),
    ];
    foreach ($dateCandidates as $datePart) {
        $path = $baseDir . '/' . $datePart . '.log.jsonl';
        if (!is_file($path)) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            continue;
        }
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $decoded = json_decode((string) $lines[$i], true);
            if (!is_array($decoded)) {
                continue;
            }
            if ((string) ($decoded['session_id'] ?? '') !== $sessionId) {
                continue;
            }
            if ($messageId !== '' && (string) ($decoded['message_id'] ?? '') !== $messageId) {
                continue;
            }
            return $decoded;
        }
    }

    return [];
}

/**
 * @param array<string, mixed> $record
 */
function appendWorkerRuntimeLog(array $record): void
{
    $dir = PROJECT_ROOT . '/storage/runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        return;
    }
    $record['ts'] = $record['ts'] ?? date('c');
    $line = json_encode(LogSanitizer::sanitizeArray($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return;
    }
    @file_put_contents($dir . '/queue_worker.log.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Process queue payload via real chat pipeline (router + gates + agentops).
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function processQueuedJob(array $job): array
{
    $jobType = trim((string) ($job['job_type'] ?? ''));
    $jobId = (int) ($job['id'] ?? 0);
    $payload = is_array($job['payload'] ?? null) ? (array) $job['payload'] : [];

    if ($jobType === 'telegram.inbound' || $jobType === 'whatsapp.inbound') {
        $inbound = buildInboundChatPayload($jobType, $payload);
        $channel = (string) ($inbound['channel'] ?? 'unknown');
        if (!(bool) ($inbound['processable'] ?? false)) {
            $result = [
                'processed' => true,
                'channel' => $channel,
                'job_type' => $jobType,
                'mode' => 'pipeline_skip',
                'skip_reason' => (string) ($inbound['reason'] ?? 'not_processable'),
                'route_path' => 'cache>rules',
                'gate_decision' => 'allow',
                'message_id' => (string) ($inbound['message_id'] ?? ''),
                'session_id' => (string) ($inbound['session_id'] ?? ''),
            ];
            appendWorkerRuntimeLog(array_merge($result, ['job_id' => $jobId]));
            return $result;
        }

        $startedAt = microtime(true);
        $agent = new ChatAgent();
        $agentResult = $agent->handle((array) ($inbound['chat_payload'] ?? []));
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $tenantId = (string) ($inbound['tenant_id'] ?? 'default');
        $sessionId = (string) ($inbound['session_id'] ?? '');
        $messageId = (string) ($inbound['message_id'] ?? '');
        $agentOps = findLatestAgentOpsEvent($tenantId, $sessionId, $messageId);
        $routePath = trim((string) ($agentOps['route_path'] ?? ''));
        $gateDecision = trim((string) ($agentOps['gate_decision'] ?? ''));
        $contractVersions = is_array($agentOps['contract_versions'] ?? null) ? (array) $agentOps['contract_versions'] : [];

        $result = [
            'processed' => true,
            'channel' => $channel,
            'job_type' => $jobType,
            'mode' => 'pipeline_runtime',
            'status' => (string) ($agentResult['status'] ?? 'unknown'),
            'route_path' => $routePath !== '' ? $routePath : 'unknown',
            'gate_decision' => $gateDecision !== '' ? $gateDecision : 'unknown',
            'message_id' => $messageId,
            'session_id' => $sessionId,
            'latency_ms' => $latencyMs,
            'contract_versions' => $contractVersions,
            'reply' => (string) (($agentResult['data']['reply'] ?? '') ?: ($agentResult['message'] ?? '')),
        ];
        appendWorkerRuntimeLog(array_merge($result, [
            'job_id' => $jobId,
            'tenant_id' => $tenantId,
            'project_id' => (string) ($inbound['project_id'] ?? 'default'),
        ]));
        return $result;
    }

    $result = [
        'processed' => true,
        'job_type' => $jobType !== '' ? $jobType : 'unknown',
        'mode' => 'pipeline_passthrough',
    ];
    appendWorkerRuntimeLog(array_merge($result, ['job_id' => $jobId]));
    return $result;
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
