<?php
// framework/tests/worker_logs_route_path_test.php

declare(strict_types=1);

require_once __DIR__ . '/worker_pipeline_common.php';

$failures = [];
$runToken = (string) time() . (string) random_int(1000, 9999);
$messageId = 'tgmsg_worker_route_' . $runToken;
$updateId = (int) ('8' . $runToken);
$tenantId = 'default';

$webhook = runWorkerApiRoute([
    'route' => 'channels/telegram/webhook',
    'method' => 'POST',
    'env' => [
        'APP_ENV' => 'local',
        'ALLOW_INSECURE_WEBHOOKS' => '1',
        'ALLOW_RUNTIME_SCHEMA' => '1',
    ],
    'payload' => [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $messageId,
            'chat' => ['id' => 'tg_route_worker_chat_' . $runToken],
            'text' => 'hola worker route path',
        ],
    ],
]);

$webhookJson = $webhook['json'];
if (!is_array($webhookJson) || (string) ($webhookJson['status'] ?? '') !== 'success') {
    $failures[] = 'Telegram webhook debe responder success antes de procesar en worker.';
}
if (!is_array($webhookJson) || !(bool) ($webhookJson['enqueued'] ?? false)) {
    $failures[] = 'Telegram webhook debe encolar mensaje para worker.';
}

$processing = processQueuedMessageUntilDone('telegram.inbound', $messageId, [
    'APP_ENV' => 'local',
    'ALLOW_RUNTIME_SCHEMA' => '1',
    'ENFORCEMENT_MODE' => 'strict',
], 30);
$row = is_array($processing['row'] ?? null) ? (array) $processing['row'] : null;

if (!is_array($row)) {
    $failures[] = 'No se encontro job telegram en cola.';
} elseif ((string) ($row['status'] ?? '') !== 'done') {
    $failures[] = 'Worker debe dejar el job telegram en done.';
}

$telemetryEvents = workerTelemetryEventsByMessageId($tenantId, $messageId);
if (count($telemetryEvents) < 1) {
    $failures[] = 'Worker debe generar AgentOps telemetry con message_id para telegram.';
}

$routeFound = false;
$gateFound = false;
$versionsFound = false;
foreach ($telemetryEvents as $event) {
    if (!is_array($event)) {
        continue;
    }
    $routePath = trim((string) ($event['route_path'] ?? ''));
    $gateDecision = trim((string) ($event['gate_decision'] ?? ''));
    $versions = is_array($event['contract_versions'] ?? null) ? (array) $event['contract_versions'] : [];

    if ($routePath !== '' && $routePath !== 'unknown') {
        $routeFound = true;
    }
    if ($gateDecision !== '' && $gateDecision !== 'plumbing_only') {
        $gateFound = true;
    }
    if (!empty($versions)) {
        $versionsFound = true;
    }
}

if (!$routeFound) {
    $failures[] = 'AgentOps debe incluir route_path para mensajes procesados por worker.';
}
if (!$gateFound) {
    $failures[] = 'AgentOps debe incluir gate_decision real (no plumbing_only).';
}
if (!$versionsFound) {
    $failures[] = 'AgentOps debe incluir contract_versions en procesamiento por worker.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'message_id' => $messageId,
    'job_status' => is_array($row) ? (string) ($row['status'] ?? '') : null,
    'telemetry_events' => count($telemetryEvents),
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
