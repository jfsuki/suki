<?php
// framework/tests/records_mutation_accepts_authenticated_session_test.php

declare(strict_types=1);

require_once __DIR__ . '/records_read_auth_common.php';

$failures = [];
$entity = recordsReadResolveEntityName();
recordsReadEnsureEntityMigrated($entity);

$sessionTenant = 'tenant_mutation_ok_' . (string) time();
$result = recordsReadRunApiRoute([
    'route' => 'records/' . $entity,
    'method' => 'POST',
    'session' => [
        'auth_user' => [
            'id' => 'records_mutation_user_2',
            'role' => 'admin',
            'tenant_id' => $sessionTenant,
            'project_id' => 'default',
        ],
        'csrf_token' => 'csrf-records-mutation-ok',
    ],
    'headers' => [
        'X-CSRF-TOKEN' => 'csrf-records-mutation-ok',
    ],
    'payload' => [
        'nombre' => 'Alta autenticada',
        'descripcion' => 'Mutacion con sesion valida',
        'activo' => true,
    ],
]);

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'records POST autenticado debe responder JSON.';
} else {
    $status = (string) ($json['status'] ?? '');
    if ($status !== 'success') {
        $failures[] = 'records POST autenticado debe responder success.';
    }
    $id = (int) (($json['data']['id'] ?? 0));
    if ($id <= 0) {
        $failures[] = 'records POST autenticado debe devolver id valido.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
