<?php
// framework/tests/chat_exec_requires_auth_test.php

declare(strict_types=1);

require_once __DIR__ . '/chat_exec_security_common.php';

$runId = (string) time();
$entity = 'status_redteam_p0_' . $runId;
$sessionId = 'chat_exec_auth_' . $runId;

cleanupChatExecArtifacts($entity);

$basePayload = [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'user_id' => 'chat_exec_user_' . $runId,
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
    $failures[] = 'chat/message sin auth debe bloquear ejecucion en confirmacion.';
}

$message = mb_strtolower((string) ($json2['message'] ?? ''), 'UTF-8');
if ($message !== '' && !str_contains($message, 'acceso no autorizado')) {
    $failures[] = 'chat/message sin auth debe responder error generico de autorizacion.';
}

if (entityContractExists($entity)) {
    $failures[] = 'No debe crearse contrato de entidad cuando falta auth.';
}
if (entityTableExists($entity)) {
    $failures[] = 'No debe crearse tabla fisica cuando falta auth.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'step_1' => $step1['json'],
    'step_2' => $step2['json'],
    'entity' => $entity,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

cleanupChatExecArtifacts($entity);
exit($ok ? 0 : 1);
