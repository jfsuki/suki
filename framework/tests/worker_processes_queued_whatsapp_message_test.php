<?php
// framework/tests/worker_processes_queued_whatsapp_message_test.php

declare(strict_types=1);

require_once __DIR__ . '/worker_pipeline_common.php';

$failures = [];
$runToken = (string) time() . (string) random_int(1000, 9999);
$secret = 'wa-worker-process-' . $runToken;
$messageId = 'wamid.WORKER_PROCESS_' . $runToken;
$tenantId = 'default';

$webhook = runWorkerApiRoute([
    'route' => 'channels/whatsapp/webhook',
    'method' => 'POST',
    'env' => [
        'WHATSAPP_APP_SECRET' => $secret,
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
    ],
    'headers' => [
        'X-Hub-Signature-256' => workerTestWhatsAppSignature($secret),
    ],
    'payload' => [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'messages' => [
                                [
                                    'id' => $messageId,
                                    'from' => '573004444444',
                                    'text' => ['body' => 'hola worker whatsapp'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);

$webhookJson = $webhook['json'];
if (!is_array($webhookJson) || (string) ($webhookJson['status'] ?? '') !== 'success') {
    $failures[] = 'WhatsApp webhook debe responder success antes del procesamiento en worker.';
}
if (!is_array($webhookJson) || !(bool) ($webhookJson['enqueued'] ?? false)) {
    $failures[] = 'WhatsApp webhook debe encolar el mensaje para worker (enqueued=true).';
}

$processing = processQueuedMessageUntilDone('whatsapp.inbound', $messageId, [
    'APP_ENV' => 'local',
    'ALLOW_RUNTIME_SCHEMA' => '1',
    'ENFORCEMENT_MODE' => 'strict',
], 30);

$row = is_array($processing['row'] ?? null) ? (array) $processing['row'] : null;
if (!is_array($row)) {
    $failures[] = 'Worker no encontro el job de WhatsApp en cola.';
} else {
    if ((string) ($row['status'] ?? '') !== 'done') {
        $failures[] = 'Worker debe dejar jobs_queue.status=done para WhatsApp procesado.';
    }
    if ((int) ($row['attempts'] ?? 0) < 1) {
        $failures[] = 'Worker debe incrementar attempts al procesar job.';
    }
}

$matchedWorkerEvent = null;
foreach ((array) ($processing['runs'] ?? []) as $run) {
    $events = is_array($run['events'] ?? null) ? (array) $run['events'] : [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $result = is_array($event['result'] ?? null) ? (array) $event['result'] : [];
        if ((string) ($result['message_id'] ?? '') !== $messageId) {
            continue;
        }
        $matchedWorkerEvent = $result;
    }
}

$runtimeEvents = workerRuntimeLogEventsByMessageId($messageId);
if (!is_array($matchedWorkerEvent) && !empty($runtimeEvents)) {
    $matchedWorkerEvent = (array) end($runtimeEvents);
}

if (is_array($matchedWorkerEvent)) {
    if ((string) ($matchedWorkerEvent['mode'] ?? '') !== 'pipeline_runtime') {
        $failures[] = 'Worker debe ejecutar pipeline real (mode=pipeline_runtime).';
    }
    if (trim((string) ($matchedWorkerEvent['route_path'] ?? '')) === '' || (string) ($matchedWorkerEvent['route_path'] ?? '') === 'unknown') {
        $failures[] = 'Worker debe resolver route_path con router+gates.';
    }
    if ((string) ($matchedWorkerEvent['gate_decision'] ?? '') === 'plumbing_only') {
        $failures[] = 'Worker no debe reportar gate_decision=plumbing_only.';
    }
} else {
    $failures[] = 'Worker debe dejar evidencia de resultado por message_id (stdout o runtime log).';
}

$telemetryEvents = workerTelemetryEventsByMessageId($tenantId, $messageId);
if (count($telemetryEvents) < 1) {
    $failures[] = 'Worker debe generar trazas AgentOps (telemetry) para mensaje de WhatsApp.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'message_id' => $messageId,
    'job_status' => is_array($row) ? (string) ($row['status'] ?? '') : null,
    'worker_event' => $matchedWorkerEvent,
    'runtime_events' => count($runtimeEvents),
    'telemetry_events' => count($telemetryEvents),
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
