<?php
// framework/tests/intent_router_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\IntentRouter;

$router = new IntentRouter();
$failures = [];
$previousMode = getenv('ENFORCEMENT_MODE');
putenv('ENFORCEMENT_MODE=warn');

$local = $router->route(['action' => 'respond_local', 'reply' => 'hola']);
if (!$local->isLocalResponse() || $local->reply() !== 'hola') {
    $failures[] = 'respond_local route failed';
}

$command = $router->route([
    'action' => 'execute_command',
    'command' => ['command' => 'CreateForm', 'entity' => 'clientes'],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'intent_router_test',
    'mode' => 'builder',
    'role' => 'admin',
    'is_authenticated' => true,
    'auth_tenant_id' => 'default',
]);
if (!$command->isCommand() || (string) (($command->command()['command'] ?? '')) !== 'CreateForm') {
    $failures[] = 'execute_command route failed';
}

$llm = $router->route(['action' => 'send_to_llm', 'llm_request' => ['messages' => []]]);
if (!$llm->isLocalResponse()) {
    $failures[] = 'send_to_llm route failed';
}
if (stripos($llm->reply(), 'evidencia minima') === false) {
    $failures[] = 'send_to_llm en warn debe degradar a ASK por falta de evidencia minima.';
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
