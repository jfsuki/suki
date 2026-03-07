<?php
// framework/tests/redteam_poc_chat_exec_test.php

declare(strict_types=1);

require_once __DIR__ . '/chat_exec_security_common.php';

$entity = 'status_redteam_p0';
$legacyEntity = 'redteam_p0_01';
$runId = (string) time();
$sessionId = 'redteam_poc_chat_exec_' . $runId;
$tenantId = 'default';

cleanupChatExecArtifacts($entity);
cleanupChatExecArtifacts($legacyEntity);

$basePayload = [
    'tenant_id' => $tenantId,
    'project_id' => 'default',
    'user_id' => 'redteam_poc_user_' . $runId,
    'session_id' => $sessionId,
    'mode' => 'builder',
];

$step1 = runChatMessageTurn(array_merge($basePayload, [
    'message' => 'quiero crear una tabla ' . $entity,
]));
$step2 = runChatMessageTurn(array_merge($basePayload, [
    'message' => 'si',
]));

$failures = [];
$json2 = $step2['json'];
if (!is_array($json2) || (string) ($json2['status'] ?? '') !== 'error') {
    $failures[] = 'PoC redteam debe bloquear confirmacion ejecutable sin auth.';
}

$message2 = mb_strtolower((string) ($json2['message'] ?? ''), 'UTF-8');
if ($message2 !== '' && str_contains($message2, 'tabla creada')) {
    $failures[] = 'PoC redteam no puede devolver "Tabla creada" sin auth.';
}

if (entityContractExists($entity) || entityTableExists($entity)) {
    $failures[] = 'PoC redteam no debe generar entidad ni tabla.';
}

$telemetry = latestTelemetryForSession($tenantId, $sessionId);
if (!is_array($telemetry) || (string) ($telemetry['gate_decision'] ?? '') !== 'blocked') {
    $failures[] = 'PoC redteam debe quedar bloqueado por hard gates.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'entity' => $entity,
    'step_1' => $step1['json'],
    'step_2' => $step2['json'],
    'telemetry' => $telemetry,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

cleanupChatExecArtifacts($entity);
cleanupChatExecArtifacts($legacyEntity);
exit($ok ? 0 : 1);
