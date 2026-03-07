<?php
// framework/tests/chat_informational_without_auth_ok_test.php

declare(strict_types=1);

require_once __DIR__ . '/chat_exec_security_common.php';

$runId = (string) time();
$sessionId = 'chat_info_public_' . $runId;
$tenantId = 'default';
$sentinelEntity = 'chat_info_sentinel_' . $runId;
cleanupChatExecArtifacts($sentinelEntity);

$response = runChatMessageTurn([
    'tenant_id' => $tenantId,
    'project_id' => 'default',
    'user_id' => 'chat_info_user_' . $runId,
    'session_id' => $sessionId,
    'mode' => 'builder',
    'message' => 'hola, que puedes hacer aqui?',
]);

$failures = [];
$json = $response['json'];
if (!is_array($json) || (string) ($json['status'] ?? '') !== 'success') {
    $failures[] = 'Consulta informativa sin auth debe responder success.';
}

$reply = trim((string) (($json['data']['reply'] ?? '')));
if ($reply === '') {
    $failures[] = 'Consulta informativa debe devolver respuesta legible.';
}

if (entityContractExists($sentinelEntity) || entityTableExists($sentinelEntity)) {
    $failures[] = 'Consulta informativa no debe generar side effects.';
}

$telemetry = latestTelemetryForSession($tenantId, $sessionId);
if (is_array($telemetry) && (string) ($telemetry['action'] ?? '') === 'execute_command') {
    $failures[] = 'Consulta informativa no debe enrutar a execute_command.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'response' => $json,
    'telemetry' => $telemetry,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
