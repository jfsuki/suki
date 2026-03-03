<?php
// framework/tests/records_get_with_signed_token_ok_test.php

declare(strict_types=1);

require_once __DIR__ . '/records_read_auth_common.php';

$failures = [];
$entity = recordsReadResolveEntityName();
recordsReadEnsureEntityMigrated($entity);

$tenantId = '101';
$secret = 'records-test-secret-ok';
$route = 'records/' . $entity;
$token = recordsReadSignToken([
    'scope' => 'records:read',
    'tenant_id' => $tenantId,
    'exp' => time() + 300,
    'method' => 'GET',
    'path' => $route,
], $secret);

$result = recordsReadRunApiRoute([
    'route' => $route,
    'method' => 'GET',
    'query' => [
        'tenant_id' => $tenantId,
        't' => $token,
    ],
    'env' => [
        'RECORDS_READ_SECRET' => $secret,
        'RECORDS_READ_TTL_SEC' => '900',
    ],
]);

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'records GET con token firmado valido debe responder JSON.';
} else {
    $status = (string) ($json['status'] ?? '');
    if ($status !== 'success') {
        $failures[] = 'records GET con token firmado valido debe responder success.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

