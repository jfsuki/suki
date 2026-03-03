<?php
// framework/tests/records_get_requires_auth_test.php

declare(strict_types=1);

require_once __DIR__ . '/records_read_auth_common.php';

$failures = [];
$entity = recordsReadResolveEntityName();
recordsReadEnsureEntityMigrated($entity);

$result = recordsReadRunApiRoute([
    'route' => 'records/' . $entity,
    'method' => 'GET',
    'query' => [
        'tenant_id' => '101',
    ],
    'env' => [
        'RECORDS_READ_SECRET' => 'records-test-secret',
        'RECORDS_READ_TTL_SEC' => '900',
    ],
]);

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'records GET sin auth/token debe responder JSON de error.';
} else {
    $status = (string) ($json['status'] ?? '');
    $message = (string) ($json['message'] ?? '');
    if ($status !== 'error') {
        $failures[] = 'records GET sin auth/token debe bloquearse.';
    }
    if (!str_contains($message, 'Acceso no autorizado')) {
        $failures[] = 'records GET sin auth/token debe devolver mensaje generico de acceso no autorizado.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

