<?php
// framework/tests/records_mutation_rejects_payload_tenant_override_test.php

declare(strict_types=1);

require_once __DIR__ . '/records_read_auth_common.php';

$failures = [];
$entity = recordsReadResolveEntityName();
recordsReadEnsureEntityMigrated($entity);

$sessionTenant = 'tenant_auth_override_guard';
$result = recordsReadRunApiRoute([
    'route' => 'records/' . $entity,
    'method' => 'POST',
    'session' => [
        'auth_user' => [
            'id' => 'records_mutation_user_1',
            'role' => 'admin',
            'tenant_id' => $sessionTenant,
            'project_id' => 'default',
        ],
        'csrf_token' => 'csrf-records-mutation-tenant-override',
    ],
    'headers' => [
        'X-CSRF-TOKEN' => 'csrf-records-mutation-tenant-override',
    ],
    'payload' => [
        'tenant_id' => 'tenant_attack',
        'nombre' => 'Intento tenant override',
        'descripcion' => 'Debe bloquearse por mismatch tenant/session',
    ],
]);

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'records POST con tenant override debe responder JSON de error.';
} else {
    $status = (string) ($json['status'] ?? '');
    $message = (string) ($json['message'] ?? '');
    if ($status !== 'error') {
        $failures[] = 'records POST con tenant override debe bloquearse.';
    }
    if (!str_contains($message, 'Acceso no autorizado')) {
        $failures[] = 'records POST con tenant override debe responder mensaje generico.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
