<?php
// framework/tests/agentops_runtime_observability_test.php

declare(strict_types=1);

require_once __DIR__ . '/worker_pipeline_common.php';

$failures = [];
$runToken = (string) time() . (string) random_int(1000, 9999);
$messageId = 'tgmsg_agentops_obs_' . $runToken;
$updateId = (int) ('91' . $runToken);
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
            'chat' => ['id' => 'tg_agentops_obs_chat_' . $runToken],
            'text' => 'hola observabilidad agentops',
        ],
    ],
]);

$webhookJson = $webhook['json'];
if (!is_array($webhookJson) || (string) ($webhookJson['status'] ?? '') !== 'success') {
    $failures[] = 'Telegram webhook debe responder success antes de delegar al worker.';
}
if (!is_array($webhookJson) || !(bool) ($webhookJson['enqueued'] ?? false)) {
    $failures[] = 'Telegram webhook debe encolar el mensaje para worker.';
}

$processing = processQueuedMessageUntilDone('telegram.inbound', $messageId, [
    'APP_ENV' => 'local',
    'ALLOW_RUNTIME_SCHEMA' => '1',
    'ENFORCEMENT_MODE' => 'strict',
], 30);
$row = is_array($processing['row'] ?? null) ? (array) $processing['row'] : null;
if (!is_array($row)) {
    $failures[] = 'No se encontro job telegram para validar telemetria AgentOps.';
} elseif ((string) ($row['status'] ?? '') !== 'done') {
    $failures[] = 'Worker debe marcar job telegram en done para validar telemetria.';
}

$telemetryEvents = workerTelemetryEventsByMessageId($tenantId, $messageId);
if (count($telemetryEvents) < 1) {
    $failures[] = 'AgentOps debe registrar al menos un evento para message_id en worker.';
}

$matchedEvent = null;
foreach ($telemetryEvents as $event) {
    if (!is_array($event)) {
        continue;
    }
    if ((string) ($event['event_name'] ?? '') !== 'response.emitted') {
        continue;
    }
    $matchedEvent = $event;
    break;
}
if (!is_array($matchedEvent) && !empty($telemetryEvents)) {
    $matchedEvent = is_array($telemetryEvents[0]) ? $telemetryEvents[0] : null;
}
if (!is_array($matchedEvent)) {
    $failures[] = 'No se encontro evento de telemetria util para validar AgentOps runtime.';
}

if (is_array($matchedEvent)) {
    $requiredFields = [
        'route_path',
        'gate_decision',
        'action_contract',
        'rag_hit',
        'source_ids',
        'evidence_ids',
        'llm_called',
        'latency_ms',
        'error_flag',
        'error_type',
        'agentops_runtime',
    ];
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $matchedEvent)) {
            $failures[] = 'Campo AgentOps faltante en runtime telemetry: ' . $field;
        }
    }

    if (!is_string($matchedEvent['route_path'] ?? null) || trim((string) $matchedEvent['route_path']) === '') {
        $failures[] = 'route_path debe ser string no vacio.';
    }
    if (!is_string($matchedEvent['gate_decision'] ?? null) || trim((string) $matchedEvent['gate_decision']) === '') {
        $failures[] = 'gate_decision debe ser string no vacio.';
    }
    if (!is_string($matchedEvent['action_contract'] ?? null)) {
        $failures[] = 'action_contract debe ser string.';
    }
    if (!is_bool($matchedEvent['rag_hit'] ?? null)) {
        $failures[] = 'rag_hit debe ser booleano.';
    }
    if (!is_array($matchedEvent['source_ids'] ?? null)) {
        $failures[] = 'source_ids debe ser arreglo.';
    }
    if (!is_array($matchedEvent['evidence_ids'] ?? null)) {
        $failures[] = 'evidence_ids debe ser arreglo.';
    }
    if (!is_bool($matchedEvent['llm_called'] ?? null)) {
        $failures[] = 'llm_called debe ser booleano.';
    }
    if (!is_numeric($matchedEvent['latency_ms'] ?? null) || (int) $matchedEvent['latency_ms'] < 0) {
        $failures[] = 'latency_ms debe ser numerico >= 0.';
    }
    if (!is_bool($matchedEvent['error_flag'] ?? null)) {
        $failures[] = 'error_flag debe ser booleano.';
    }
    if (!is_string($matchedEvent['error_type'] ?? null) || trim((string) $matchedEvent['error_type']) === '') {
        $failures[] = 'error_type debe ser string no vacio.';
    }

    $runtime = is_array($matchedEvent['agentops_runtime'] ?? null) ? (array) $matchedEvent['agentops_runtime'] : null;
    if (!is_array($runtime)) {
        $failures[] = 'agentops_runtime debe ser objeto/array con esquema canonico.';
    } else {
        foreach ([
            'route_path',
            'gate_decision',
            'action_contract',
            'rag_hit',
            'source_ids',
            'evidence_ids',
            'llm_called',
            'latency_ms',
            'error_flag',
            'error_type',
        ] as $field) {
            if (!array_key_exists($field, $runtime)) {
                $failures[] = 'agentops_runtime.' . $field . ' faltante.';
            }
        }
        if (is_array($runtime) && array_key_exists('route_path', $runtime) && (string) ($runtime['route_path'] ?? '') !== (string) ($matchedEvent['route_path'] ?? '')) {
            $failures[] = 'agentops_runtime.route_path debe coincidir con route_path top-level.';
        }
        if (is_array($runtime) && array_key_exists('gate_decision', $runtime) && (string) ($runtime['gate_decision'] ?? '') !== (string) ($matchedEvent['gate_decision'] ?? '')) {
            $failures[] = 'agentops_runtime.gate_decision debe coincidir con gate_decision top-level.';
        }
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'message_id' => $messageId,
    'telemetry_events' => count($telemetryEvents),
    'event_name' => is_array($matchedEvent) ? (string) ($matchedEvent['event_name'] ?? '') : null,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
