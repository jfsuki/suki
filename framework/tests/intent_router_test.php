<?php
// framework/tests/intent_router_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\IntentRouter;

$router = new IntentRouter();
$failures = [];

$local = $router->route(['action' => 'respond_local', 'reply' => 'hola']);
if (!$local->isLocalResponse() || $local->reply() !== 'hola') {
    $failures[] = 'respond_local route failed';
}

$command = $router->route(['action' => 'execute_command', 'command' => ['command' => 'CreateEntity']]);
if (!$command->isCommand() || (string) (($command->command()['command'] ?? '')) !== 'CreateEntity') {
    $failures[] = 'execute_command route failed';
}

$llm = $router->route(['action' => 'send_to_llm', 'llm_request' => ['messages' => []]]);
if (!$llm->isLlmRequest()) {
    $failures[] = 'send_to_llm route failed';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

