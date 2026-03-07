<?php
// framework/tests/action_allowlist_enforcement_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\IntentRouter;

$failures = [];
$previousMode = getenv('ENFORCEMENT_MODE');

putenv('ENFORCEMENT_MODE=strict');
$strictRouter = new IntentRouter();

$allowed = $strictRouter->route([
    'action' => 'execute_command',
    'command' => ['command' => 'CreateRecord', 'entity' => 'clientes', 'data' => ['nombre' => 'Ana']],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'allowlist_allowed',
    'mode' => 'app',
    'role' => 'admin',
    'is_authenticated' => true,
    'auth_tenant_id' => 'default',
]);
$allowedTelemetry = $allowed->telemetry();
if (!$allowed->isCommand()) {
    $failures[] = 'strict: CreateRecord debe pasar por allowlist (crud.create)';
}
if ((string) ($allowedTelemetry['gate_decision'] ?? '') !== 'allow') {
    $failures[] = 'strict: gate_decision esperado allow para CreateRecord';
}

$blocked = $strictRouter->route([
    'action' => 'execute_command',
    'command' => ['command' => 'DeleteRecord', 'entity' => 'clientes', 'id' => 1],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'allowlist_blocked',
    'mode' => 'app',
    'role' => 'admin',
    'is_authenticated' => true,
    'auth_tenant_id' => 'default',
]);
$blockedTelemetry = $blocked->telemetry();
if (!$blocked->isLocalResponse()) {
    $failures[] = 'strict: DeleteRecord fuera de catalogo debe bloquearse';
}
if ((string) ($blockedTelemetry['gate_decision'] ?? '') !== 'blocked') {
    $failures[] = 'strict: gate_decision esperado blocked para DeleteRecord';
}

putenv('ENFORCEMENT_MODE=warn');
$warnRouter = new IntentRouter();
$warn = $warnRouter->route([
    'action' => 'execute_command',
    'command' => ['command' => 'DeleteRecord', 'entity' => 'clientes', 'id' => 1],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'allowlist_warn',
    'mode' => 'app',
    'role' => 'admin',
    'is_authenticated' => true,
    'auth_tenant_id' => 'default',
]);
$warnTelemetry = $warn->telemetry();
if (!$warn->isLocalResponse()) {
    $failures[] = 'warn: DeleteRecord no allowlisted debe bloquearse por hard gate.';
}
if ((string) ($warnTelemetry['gate_decision'] ?? '') !== 'blocked') {
    $failures[] = 'warn: gate_decision esperado blocked para DeleteRecord en hard gate.';
}
if (!is_array($warnTelemetry['contract_versions'] ?? null)) {
    $failures[] = 'warn: contract_versions obligatorio en telemetry';
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
