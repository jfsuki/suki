<?php
// framework/tests/diagnose_scenario_9.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;

$agent = new ChatAgent();
$payload = [
    'message' => 'crear tabla clientes nombre:texto nit:texto',
    'tenant_id' => 'default',
    'user_id' => 'diag_user',
    'session_id' => 'sess_diag',
    'mode' => 'builder',
    'channel' => 'diag',
    'is_authenticated' => true,
    'auth_user_id' => 'diag_user',
    'auth_tenant_id' => 'default',
    'role' => 'admin',
    'test_mode' => true,
];

try {
    $out = $agent->handle($payload);
    echo "--- OUTPUT ---\n";
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} catch (\Throwable $e) {
    echo "--- EXCEPTION ---\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
