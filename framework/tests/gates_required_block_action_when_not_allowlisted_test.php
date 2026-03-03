<?php
// framework/tests/gates_required_block_action_when_not_allowlisted_test.php

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
        'command' => 'DeleteRecord',
        'entity' => 'clientes',
        'id' => 99,
    ],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'allowlist_block_gate',
    'role' => 'admin',
    'mode' => 'app',
]);

$telemetry = $result->telemetry();
if (!$result->isLocalResponse()) {
    $failures[] = 'strict: comando no allowlisted debe bloquearse antes de ejecutar.';
}
if ((string) ($telemetry['gate_decision'] ?? '') !== 'blocked') {
    $failures[] = 'strict: gate_decision esperado blocked para comando no allowlisted.';
}

$violations = is_array($telemetry['contract_violations'] ?? null) ? (array) $telemetry['contract_violations'] : [];
$allowlistViolation = false;
foreach ($violations as $violation) {
    if (str_contains((string) $violation, 'action_not_allowlisted')) {
        $allowlistViolation = true;
        break;
    }
}
if (!$allowlistViolation) {
    $failures[] = 'strict: debe registrar violation action_not_allowlisted.';
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

