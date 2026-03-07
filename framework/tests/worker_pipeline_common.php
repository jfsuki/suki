<?php
// framework/tests/worker_pipeline_common.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$envLoader = dirname(__DIR__, 2) . '/project/config/env_loader.php';
if (is_file($envLoader)) {
    require_once $envLoader;
}

use App\Core\Database;

/**
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function runWorkerApiRoute(array $request): array
{
    $helper = __DIR__ . '/api_route_turn.php';
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $raw = (string) shell_exec($cmd);
    $json = json_decode($raw, true);
    return [
        'raw' => trim($raw),
        'json' => is_array($json) ? $json : null,
    ];
}

function workerTestWhatsAppSignature(string $secret): string
{
    // api_route_turn injects payload via $_POST; php://input is empty in tests.
    return 'sha256=' . hash_hmac('sha256', '', $secret);
}

/**
 * @return array<string, mixed>|null
 */
function workerQueueLatestRowByMessageId(string $jobType, string $messageId): ?array
{
    $db = Database::connection();
    $stmt = $db->prepare(
        'SELECT id, tenant_id, job_type, status, attempts, payload_json, created_at, updated_at
         FROM jobs_queue
         WHERE job_type = :job_type AND payload_json LIKE :needle
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':job_type' => $jobType,
        ':needle' => '%' . $messageId . '%',
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function workerQueueCountByMessageId(string $jobType, string $messageId): int
{
    $db = Database::connection();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM jobs_queue WHERE job_type = :job_type AND payload_json LIKE :needle'
    );
    $stmt->execute([
        ':job_type' => $jobType,
        ':needle' => '%' . $messageId . '%',
    ]);
    return (int) $stmt->fetchColumn();
}

function workerPrioritizeJobById(int $jobId): void
{
    if ($jobId <= 0) {
        return;
    }
    $db = Database::connection();
    $stmt = $db->prepare(
        'UPDATE jobs_queue
         SET status = :status,
             available_at = :available_at,
             locked_at = NULL,
             locked_by = NULL,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => 'pending',
        ':available_at' => '1970-01-01 00:00:00',
        ':updated_at' => date('Y-m-d H:i:s'),
        ':id' => $jobId,
    ]);
}

/**
 * @param array<string, string> $env
 * @return array{raw:string,events:array<int,array<string,mixed>>,summary:array<string,mixed>}
 */
function runQueueWorkerOnce(array $env = []): array
{
    $original = [];
    foreach ($env as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        $current = getenv($key);
        $original[$key] = $current === false ? null : (string) $current;
        putenv($key . '=' . $value);
    }

    $workerPath = dirname(__DIR__, 2) . '/bin/worker.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath) . ' --once';
    $raw = (string) shell_exec($cmd);

    foreach ($original as $key => $value) {
        if ($value === null) {
            putenv($key);
        } else {
            putenv($key . '=' . $value);
        }
    }

    $events = [];
    $lines = preg_split('/\R+/', trim($raw)) ?: [];
    foreach ($lines as $line) {
        $decoded = json_decode((string) $line, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }

    $summary = [];
    if (!empty($events)) {
        $candidate = end($events);
        if (is_array($candidate)) {
            $summary = $candidate;
        }
    }

    return [
        'raw' => trim($raw),
        'events' => $events,
        'summary' => $summary,
    ];
}

/**
 * @param array<string, string> $env
 * @return array{row:array<string,mixed>|null,runs:array<int,array<string,mixed>>}
 */
function processQueuedMessageUntilDone(
    string $jobType,
    string $messageId,
    array $env = [],
    int $maxRuns = 20
): array {
    $runs = [];
    $row = workerQueueLatestRowByMessageId($jobType, $messageId);
    if (is_array($row)) {
        workerPrioritizeJobById((int) ($row['id'] ?? 0));
        $row = workerQueueLatestRowByMessageId($jobType, $messageId);
    }
    if (is_array($row) && in_array((string) ($row['status'] ?? ''), ['done', 'failed'], true)) {
        return ['row' => $row, 'runs' => $runs];
    }

    for ($i = 0; $i < $maxRuns; $i++) {
        $runs[] = runQueueWorkerOnce($env);
        $row = workerQueueLatestRowByMessageId($jobType, $messageId);
        if (is_array($row) && in_array((string) ($row['status'] ?? ''), ['done', 'failed'], true)) {
            break;
        }
        usleep(100000);
    }

    return [
        'row' => $row,
        'runs' => $runs,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function workerTelemetryEventsByMessageId(string $tenantId, string $messageId): array
{
    $tenantSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tenantId) ?? 'default';
    $tenantSafe = trim($tenantSafe, '_');
    if ($tenantSafe === '') {
        $tenantSafe = 'default';
    }

    $baseDir = dirname(__DIR__, 2) . '/project/storage/tenants/' . $tenantSafe . '/telemetry';
    if (!is_dir($baseDir)) {
        return [];
    }

    $dates = [
        date('Y-m-d'),
        date('Y-m-d', strtotime('-1 day')),
    ];

    $events = [];
    foreach ($dates as $datePart) {
        $path = $baseDir . '/' . $datePart . '.log.jsonl';
        if (!is_file($path)) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (!is_array($decoded)) {
                continue;
            }
            if ((string) ($decoded['message_id'] ?? '') !== $messageId) {
                continue;
            }
            $events[] = $decoded;
        }
    }

    return $events;
}

/**
 * @return array<int, array<string, mixed>>
 */
function workerRuntimeLogEventsByMessageId(string $messageId): array
{
    $path = dirname(__DIR__, 2) . '/project/storage/runtime/queue_worker.log.jsonl';
    if (!is_file($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $events = [];
    foreach ($lines as $line) {
        $decoded = json_decode((string) $line, true);
        if (!is_array($decoded)) {
            continue;
        }
        if ((string) ($decoded['message_id'] ?? '') !== $messageId) {
            continue;
        }
        $events[] = $decoded;
    }
    return $events;
}
