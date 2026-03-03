<?php
// framework/tests/records_get_token_tenant_mismatch_fail_test.php

declare(strict_types=1);

require_once __DIR__ . '/records_read_auth_common.php';

$failures = [];
$entity = recordsReadResolveEntityName();
recordsReadEnsureEntityMigrated($entity);

$tokenTenantId = '101';
$requestTenantId = '202';
$secret = 'records-test-secret-tenant';
$route = 'records/' . $entity;
$token = recordsReadSignToken([
    'scope' => 'records:read',
    'tenant_id' => $tokenTenantId,
    'exp' => time() + 300,
    'method' => 'GET',
    'path' => $route,
], $secret);

$result = recordsReadRunApiRoute([
    'route' => $route,
    'method' => 'GET',
    'query' => [
        'tenant_id' => $requestTenantId,
        't' => $token,
    ],
    'env' => [
        'RECORDS_READ_SECRET' => $secret,
        'RECORDS_READ_TTL_SEC' => '900',
    ],
]);

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'records GET con tenant mismatch debe responder JSON de error.';
} else {
    $status = (string) ($json['status'] ?? '');
    $message = (string) ($json['message'] ?? '');
    if ($status !== 'error') {
        $failures[] = 'records GET con tenant mismatch debe bloquearse.';
    }
    if (!str_contains($message, 'Acceso no autorizado')) {
        $failures[] = 'records GET con tenant mismatch debe usar mensaje generico.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

