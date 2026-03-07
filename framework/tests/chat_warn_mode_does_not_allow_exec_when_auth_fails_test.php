<?php
// framework/tests/chat_warn_mode_does_not_allow_exec_when_auth_fails_test.php

declare(strict_types=1);

require_once __DIR__ . '/chat_exec_security_common.php';

$runId = (string) time();
$entity = 'chat_warn_block_' . $runId;
$sessionId = 'chat_warn_block_sess_' . $runId;
$tenantId = 'default';

cleanupChatExecArtifacts($entity);

$basePayload = [
    'tenant_id' => $tenantId,
    'project_id' => 'default',
    'user_id' => 'chat_warn_user_' . $runId,
    'session_id' => $sessionId,
    'mode' => 'builder',
];
$env = [
    'ENFORCEMENT_MODE' => 'warn',
    'APP_ENV' => 'dev',
];

runChatMessageTurn(array_merge($basePayload, [
    'message' => 'quiero crear una tabla ' . $entity,
]), ['env' => $env]);

$confirm = runChatMessageTurn(array_merge($basePayload, [
    'message' => 'si',
]), ['env' => $env]);

$failures = [];
$confirmJson = $confirm['json'];
if (!is_array($confirmJson) || (string) ($confirmJson['status'] ?? '') !== 'error') {
    $failures[] = 'En warn sin auth debe bloquear comando ejecutable.';
}

$telemetry = latestTelemetryForSession($tenantId, $sessionId);
if (!is_array($telemetry)) {
    $failures[] = 'Debe registrar telemetry en bloqueo warn.';
} elseif ((string) ($telemetry['gate_decision'] ?? '') !== 'blocked') {
    $failures[] = 'Warn no puede autorizar execute_command cuando falla auth.';
}

if (entityContractExists($entity) || entityTableExists($entity)) {
    $failures[] = 'Warn sin auth no debe generar side effects ejecutables.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'confirm' => $confirmJson,
    'telemetry' => $telemetry,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

cleanupChatExecArtifacts($entity);
exit($ok ? 0 : 1);
