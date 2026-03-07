<?php
// framework/tests/chat_exec_tenant_binding_test.php

declare(strict_types=1);

require_once __DIR__ . '/chat_exec_security_common.php';

$runId = (string) time();
$session = [
    'auth_user' => [
        'id' => 'tenant_bind_user_' . $runId,
        'role' => 'admin',
        'tenant_id' => 'tenant_A',
        'project_id' => 'default',
    ],
];

$response = runChatMessageTurn([
    'tenant_id' => 'tenant_B',
    'project_id' => 'default',
    'user_id' => (string) $session['auth_user']['id'],
    'session_id' => 'tenant_binding_' . $runId,
    'mode' => 'builder',
    'message' => 'quiero crear una tabla tenant_bind_' . $runId,
], [
    'session' => $session,
]);

$failures = [];
$json = $response['json'];
if (!is_array($json) || (string) ($json['status'] ?? '') !== 'error') {
    $failures[] = 'Debe bloquear tenant override en chat/message autenticado.';
}
$message = mb_strtolower((string) ($json['message'] ?? ''), 'UTF-8');
if (!str_contains($message, 'tenant_id diferente')) {
    $failures[] = 'Debe informar mismatch de tenant de sesion.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'response' => $json,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
