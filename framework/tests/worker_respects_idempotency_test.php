<?php
// framework/tests/worker_respects_idempotency_test.php

declare(strict_types=1);

require_once __DIR__ . '/worker_pipeline_common.php';

$failures = [];
$runToken = (string) time() . (string) random_int(1000, 9999);
$secret = 'wa-worker-idempotency-' . $runToken;
$messageId = 'wamid.WORKER_IDEMPOTENCY_' . $runToken;
$tenantId = 'default';

$request = [
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
                                    'from' => '573005555555',
                                    'text' => ['body' => 'hola idempotencia worker'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$first = runWorkerApiRoute($request);
$second = runWorkerApiRoute($request);

$firstJson = $first['json'];
$secondJson = $second['json'];
if (!is_array($firstJson) || (string) ($firstJson['status'] ?? '') !== 'success' || !(bool) ($firstJson['enqueued'] ?? false)) {
    $failures[] = 'Primer webhook debe encolar job (success + enqueued=true).';
}
if (!is_array($secondJson) || (string) ($secondJson['status'] ?? '') !== 'success') {
    $failures[] = 'Segundo webhook (retry) debe responder success.';
} elseif ((bool) ($secondJson['enqueued'] ?? true)) {
    $failures[] = 'Retry con mismo message_id no debe encolar un segundo job.';
}

$processing = processQueuedMessageUntilDone('whatsapp.inbound', $messageId, [
    'APP_ENV' => 'local',
    'ALLOW_RUNTIME_SCHEMA' => '1',
    'ENFORCEMENT_MODE' => 'strict',
], 30);
$row = is_array($processing['row'] ?? null) ? (array) $processing['row'] : null;

if (!is_array($row)) {
    $failures[] = 'No se encontro job en cola para validar idempotencia del worker.';
} elseif ((string) ($row['status'] ?? '') !== 'done') {
    $failures[] = 'El unico job idempotente debe terminar en status=done.';
}

$queueCount = workerQueueCountByMessageId('whatsapp.inbound', $messageId);
if ($queueCount !== 1) {
    $failures[] = 'Idempotencia esperada: exactamente 1 job en cola por message_id. Actual=' . $queueCount;
}

$telemetryEvents = workerTelemetryEventsByMessageId($tenantId, $messageId);
if (count($telemetryEvents) !== 1) {
    $failures[] = 'Idempotencia de procesamiento esperada: 1 evento AgentOps por message_id. Actual=' . count($telemetryEvents);
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'message_id' => $messageId,
    'queue_count' => $queueCount,
    'telemetry_events' => count($telemetryEvents),
    'job_status' => is_array($row) ? (string) ($row['status'] ?? '') : null,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
