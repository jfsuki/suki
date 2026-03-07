<?php
// framework/tests/gates_required_block_action_when_schema_invalid_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\IntentRouter;

$failures = [];
$previousMode = getenv('ENFORCEMENT_MODE');
putenv('ENFORCEMENT_MODE=strict');

$router = new IntentRouter();
$result = $router->route([
    'action' => 'execute_command',
    'command' => [
        'command' => 'CreateRecord',
        'entity' => 'clientes',
        // invalid schema on purpose: missing data array
    ],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'schema_invalid_gate',
    'role' => 'admin',
    'mode' => 'app',
    'is_authenticated' => true,
    'auth_tenant_id' => 'default',
]);

$telemetry = $result->telemetry();
if (!$result->isLocalResponse()) {
    $failures[] = 'strict: schema invalid debe bloquear execute_command.';
}
if ((string) ($telemetry['gate_decision'] ?? '') !== 'blocked') {
    $failures[] = 'strict: gate_decision esperado blocked para schema invalid.';
}

$gateResults = is_array($telemetry['gate_results'] ?? null) ? (array) $telemetry['gate_results'] : [];
$schemaGateFound = false;
$schemaGateFailed = false;
foreach ($gateResults as $gate) {
    if (!is_array($gate)) {
        continue;
    }
    if ((string) ($gate['name'] ?? '') !== 'schema_gate') {
        continue;
    }
    $schemaGateFound = true;
    if (((bool) ($gate['required'] ?? false)) && !((bool) ($gate['passed'] ?? true))) {
        $schemaGateFailed = true;
    }
}
if (!$schemaGateFound) {
    $failures[] = 'strict: telemetry debe incluir schema_gate en gate_results.';
}
if (!$schemaGateFailed) {
    $failures[] = 'strict: schema_gate requerido debe fallar cuando payload no cumple schema.';
}

if ($previousMode === false) {
    putenv('ENFORCEMENT_MODE');
} else {
    putenv('ENFORCEMENT_MODE=' . $previousMode);
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
