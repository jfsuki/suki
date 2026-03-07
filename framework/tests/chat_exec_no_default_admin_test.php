<?php
// framework/tests/chat_exec_no_default_admin_test.php

declare(strict_types=1);

require_once __DIR__ . '/chat_exec_security_common.php';

$runId = (string) time();
$entity = 'chat_no_admin_' . $runId;
$sessionId = 'chat_no_admin_sess_' . $runId;
$tenantId = 'default';

cleanupChatExecArtifacts($entity);

$basePayload = [
    'tenant_id' => $tenantId,
    'project_id' => 'default',
    'user_id' => 'chat_no_admin_user_' . $runId,
    'session_id' => $sessionId,
    'mode' => 'builder',
];

runChatMessageTurn(array_merge($basePayload, [
    'message' => 'quiero crear una tabla ' . $entity,
]));
$confirm = runChatMessageTurn(array_merge($basePayload, [
    'message' => 'si',
]));

$failures = [];
$confirmJson = $confirm['json'];
if (!is_array($confirmJson) || (string) ($confirmJson['status'] ?? '') !== 'error') {
    $failures[] = 'Sin auth el comando ejecutable debe bloquearse.';
}

$telemetry = latestTelemetryForSession($tenantId, $sessionId);
if (!is_array($telemetry)) {
    $failures[] = 'Debe existir telemetry para validar contexto auth.';
} else {
    if ((bool) ($telemetry['is_authenticated'] ?? true) !== false) {
        $failures[] = 'Telemetry debe marcar is_authenticated=false sin sesion.';
    }
    $role = strtolower(trim((string) ($telemetry['effective_role'] ?? '')));
    if ($role === 'admin') {
        $failures[] = 'Role efectivo no puede ser admin cuando no hay auth.';
    }
    if ((string) ($telemetry['gate_decision'] ?? '') !== 'blocked') {
        $failures[] = 'Gate decision debe ser blocked para ejecutable sin auth.';
    }
}

if (entityContractExists($entity) || entityTableExists($entity)) {
    $failures[] = 'No debe existir side effect ejecutable en test no_default_admin.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'confirm' => $confirmJson,
    'telemetry' => $telemetry,
    'entity' => $entity,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

cleanupChatExecArtifacts($entity);
exit($ok ? 0 : 1);
