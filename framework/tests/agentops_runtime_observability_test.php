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
        'route_reason',
        'gate_decision',
        'action_contract',
        'rag_hit',
        'source_ids',
        'evidence_ids',
        'llm_called',
        'llm_used',
        'semantic_enabled',
        'rag_attempted',
        'rag_used',
        'rag_result_count',
        'evidence_gate_status',
        'fallback_reason',
        'skill_detected',
        'skill_selected',
        'skill_executed',
        'skill_failed',
        'skill_execution_ms',
        'skill_result_status',
        'skill_fallback_reason',
        'tool_calls_count',
        'retry_count',
        'loop_guard_triggered',
        'request_mode',
        'metrics_delta',
        'latency_ms',
        'error_flag',
        'error_type',
        'supervisor_status',
        'supervisor_score',
        'supervisor_flags',
        'supervisor_reasons',
        'needs_regression_case',
        'needs_memory_hygiene',
        'needs_training_gap_review',
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
    if (!is_string($matchedEvent['route_reason'] ?? null) || trim((string) ($matchedEvent['route_reason'] ?? '')) === '') {
        $failures[] = 'route_reason debe ser string no vacio.';
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
    if (!is_bool($matchedEvent['llm_used'] ?? null)) {
        $failures[] = 'llm_used debe ser booleano.';
    }
    if (!is_bool($matchedEvent['semantic_enabled'] ?? null)) {
        $failures[] = 'semantic_enabled debe ser booleano.';
    }
    if (!is_bool($matchedEvent['rag_attempted'] ?? null)) {
        $failures[] = 'rag_attempted debe ser booleano.';
    }
    if (!is_bool($matchedEvent['rag_used'] ?? null)) {
        $failures[] = 'rag_used debe ser booleano.';
    }
    if (!is_numeric($matchedEvent['rag_result_count'] ?? null) || (int) $matchedEvent['rag_result_count'] < 0) {
        $failures[] = 'rag_result_count debe ser numerico >= 0.';
    }
    if (!is_string($matchedEvent['evidence_gate_status'] ?? null) || trim((string) ($matchedEvent['evidence_gate_status'] ?? '')) === '') {
        $failures[] = 'evidence_gate_status debe ser string no vacio.';
    }
    if (!is_string($matchedEvent['fallback_reason'] ?? null) || trim((string) ($matchedEvent['fallback_reason'] ?? '')) === '') {
        $failures[] = 'fallback_reason debe ser string no vacio.';
    }
    if (!is_bool($matchedEvent['skill_detected'] ?? null)) {
        $failures[] = 'skill_detected debe ser booleano.';
    }
    if (!is_string($matchedEvent['skill_selected'] ?? null) || trim((string) ($matchedEvent['skill_selected'] ?? '')) === '') {
        $failures[] = 'skill_selected debe ser string no vacio.';
    }
    if (!is_bool($matchedEvent['skill_executed'] ?? null)) {
        $failures[] = 'skill_executed debe ser booleano.';
    }
    if (!is_bool($matchedEvent['skill_failed'] ?? null)) {
        $failures[] = 'skill_failed debe ser booleano.';
    }
    if (!is_numeric($matchedEvent['skill_execution_ms'] ?? null) || (int) $matchedEvent['skill_execution_ms'] < 0) {
        $failures[] = 'skill_execution_ms debe ser numerico >= 0.';
    }
    if (!is_string($matchedEvent['skill_result_status'] ?? null) || trim((string) ($matchedEvent['skill_result_status'] ?? '')) === '') {
        $failures[] = 'skill_result_status debe ser string no vacio.';
    }
    if (!is_string($matchedEvent['skill_fallback_reason'] ?? null) || trim((string) ($matchedEvent['skill_fallback_reason'] ?? '')) === '') {
        $failures[] = 'skill_fallback_reason debe ser string no vacio.';
    }
    if (!is_numeric($matchedEvent['tool_calls_count'] ?? null) || (int) $matchedEvent['tool_calls_count'] < 0) {
        $failures[] = 'tool_calls_count debe ser numerico >= 0.';
    }
    if (!is_numeric($matchedEvent['retry_count'] ?? null) || (int) $matchedEvent['retry_count'] < 0) {
        $failures[] = 'retry_count debe ser numerico >= 0.';
    }
    if (!is_bool($matchedEvent['loop_guard_triggered'] ?? null)) {
        $failures[] = 'loop_guard_triggered debe ser booleano.';
    }
    if (!is_string($matchedEvent['request_mode'] ?? null) || !in_array((string) ($matchedEvent['request_mode'] ?? ''), ['operation', 'research'], true)) {
        $failures[] = 'request_mode debe ser operation o research.';
    }
    if (!is_array($matchedEvent['metrics_delta'] ?? null)) {
        $failures[] = 'metrics_delta debe ser arreglo.';
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
    if (!is_string($matchedEvent['supervisor_status'] ?? null) || !in_array((string) ($matchedEvent['supervisor_status'] ?? ''), ['healthy', 'needs_review', 'flagged'], true)) {
        $failures[] = 'supervisor_status debe ser healthy, needs_review o flagged.';
    }
    if (!is_numeric($matchedEvent['supervisor_score'] ?? null) || (int) $matchedEvent['supervisor_score'] < 0 || (int) $matchedEvent['supervisor_score'] > 100) {
        $failures[] = 'supervisor_score debe ser numerico entre 0 y 100.';
    }
    if (!is_array($matchedEvent['supervisor_flags'] ?? null)) {
        $failures[] = 'supervisor_flags debe ser arreglo.';
    }
    if (!is_array($matchedEvent['supervisor_reasons'] ?? null)) {
        $failures[] = 'supervisor_reasons debe ser arreglo.';
    }
    if (!is_bool($matchedEvent['needs_regression_case'] ?? null)) {
        $failures[] = 'needs_regression_case debe ser booleano.';
    }
    if (!is_bool($matchedEvent['needs_memory_hygiene'] ?? null)) {
        $failures[] = 'needs_memory_hygiene debe ser booleano.';
    }
    if (!is_bool($matchedEvent['needs_training_gap_review'] ?? null)) {
        $failures[] = 'needs_training_gap_review debe ser booleano.';
    }

    $runtime = is_array($matchedEvent['agentops_runtime'] ?? null) ? (array) $matchedEvent['agentops_runtime'] : null;
    if (!is_array($runtime)) {
        $failures[] = 'agentops_runtime debe ser objeto/array con esquema canonico.';
    } else {
        foreach ([
            'route_path',
            'route_reason',
            'gate_decision',
            'action_contract',
            'rag_hit',
            'source_ids',
            'evidence_ids',
            'llm_called',
            'llm_used',
            'semantic_enabled',
            'rag_attempted',
            'rag_used',
            'rag_result_count',
            'evidence_gate_status',
            'fallback_reason',
            'skill_detected',
            'skill_selected',
            'skill_executed',
            'skill_failed',
            'skill_execution_ms',
            'skill_result_status',
            'skill_fallback_reason',
            'tool_calls_count',
            'retry_count',
            'loop_guard_triggered',
            'request_mode',
            'metrics_delta',
            'latency_ms',
            'error_flag',
            'error_type',
            'supervisor',
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
        $supervisor = is_array($runtime['supervisor'] ?? null) ? (array) $runtime['supervisor'] : null;
        if (!is_array($supervisor)) {
            $failures[] = 'agentops_runtime.supervisor debe ser objeto/array.';
        } else {
            foreach ([
                'status',
                'score',
                'flags',
                'reasons',
                'route_path',
                'skill_selected',
                'rag_used',
                'evidence_gate_status',
                'fallback_reason',
                'needs_regression_case',
                'needs_memory_hygiene',
                'needs_training_gap_review',
            ] as $field) {
                if (!array_key_exists($field, $supervisor)) {
                    $failures[] = 'agentops_runtime.supervisor.' . $field . ' faltante.';
                }
            }
            if ((string) ($supervisor['status'] ?? '') !== (string) ($matchedEvent['supervisor_status'] ?? '')) {
                $failures[] = 'agentops_runtime.supervisor.status debe coincidir con supervisor_status top-level.';
            }
            if ((int) ($supervisor['score'] ?? -1) !== (int) ($matchedEvent['supervisor_score'] ?? -2)) {
                $failures[] = 'agentops_runtime.supervisor.score debe coincidir con supervisor_score top-level.';
            }
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
