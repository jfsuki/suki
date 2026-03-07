<?php
// framework/tests/records_mutation_requires_auth_test.php

declare(strict_types=1);

require_once __DIR__ . '/records_read_auth_common.php';

$failures = [];
$entity = recordsReadResolveEntityName();
recordsReadEnsureEntityMigrated($entity);

$result = recordsReadRunApiRoute([
    'route' => 'records/' . $entity,
    'method' => 'POST',
    'payload' => [
        'nombre' => 'Mutation auth guard',
        'descripcion' => 'Debe bloquearse sin sesion',
    ],
]);

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'records POST sin auth debe responder JSON de error.';
} else {
    $status = (string) ($json['status'] ?? '');
    $message = (string) ($json['message'] ?? '');
    if ($status !== 'error') {
        $failures[] = 'records POST sin auth debe bloquearse.';
    }
    $validMessage = str_contains($message, 'iniciar sesion') || str_contains($message, 'Acceso no autorizado');
    if (!$validMessage) {
        $failures[] = 'records POST sin auth debe devolver mensaje de acceso denegado.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
