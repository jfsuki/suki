<?php
// framework/tests/records_mutation_cross_tenant_block_test.php

declare(strict_types=1);

require_once __DIR__ . '/records_read_auth_common.php';

$failures = [];
$entity = recordsReadResolveEntityName();
recordsReadEnsureEntityMigrated($entity);

$tenantA = 'tenant_cross_a_' . (string) time();
$tenantB = 'tenant_cross_b_' . (string) time();

$create = recordsReadRunApiRoute([
    'route' => 'records/' . $entity,
    'method' => 'POST',
    'session' => [
        'auth_user' => [
            'id' => 'records_cross_user_a',
            'role' => 'admin',
            'tenant_id' => $tenantA,
            'project_id' => 'default',
        ],
        'csrf_token' => 'csrf-records-cross-a',
    ],
    'headers' => [
        'X-CSRF-TOKEN' => 'csrf-records-cross-a',
    ],
    'payload' => [
        'nombre' => 'Registro tenant A',
        'descripcion' => 'Base para prueba cross tenant',
    ],
]);

$createJson = $create['json'];
$createdId = 0;
if (!is_array($createJson) || (string) ($createJson['status'] ?? '') !== 'success') {
    $failures[] = 'Precondicion: no se pudo crear registro en tenant A.';
} else {
    $createdId = (int) (($createJson['data']['id'] ?? 0));
    if ($createdId <= 0) {
        $failures[] = 'Precondicion: el registro de tenant A no devolvio id valido.';
    }
}

if ($createdId > 0) {
    $attack = recordsReadRunApiRoute([
        'route' => 'records/' . $entity . '/' . $createdId,
        'method' => 'DELETE',
        'query' => [
            'tenant_id' => $tenantA,
        ],
        'session' => [
            'auth_user' => [
                'id' => 'records_cross_user_b',
                'role' => 'admin',
                'tenant_id' => $tenantB,
                'project_id' => 'default',
            ],
            'csrf_token' => 'csrf-records-cross-b',
        ],
        'headers' => [
            'X-CSRF-TOKEN' => 'csrf-records-cross-b',
        ],
    ]);

    $attackJson = $attack['json'];
    if (!is_array($attackJson)) {
        $failures[] = 'DELETE cross-tenant debe responder JSON de error.';
    } else {
        $status = (string) ($attackJson['status'] ?? '');
        $message = (string) ($attackJson['message'] ?? '');
        if ($status !== 'error') {
            $failures[] = 'DELETE cross-tenant debe bloquearse.';
        }
        if (!str_contains($message, 'Acceso no autorizado')) {
            $failures[] = 'DELETE cross-tenant debe responder mensaje generico.';
        }
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
