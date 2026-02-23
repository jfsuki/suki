<?php
// framework/tests/command_bus_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\CommandBus;
use App\Core\CreateIndexCommandHandler;
use App\Core\CreateEntityCommandHandler;
use App\Core\CreateFormCommandHandler;
use App\Core\CreateRelationCommandHandler;
use App\Core\CrudCommandHandler;
use App\Core\InstallPlaybookCommandHandler;
use App\Core\MapCommandHandler;

$bus = new CommandBus();
$bus->register(new CreateEntityCommandHandler());
$bus->register(new CreateFormCommandHandler());
$bus->register(new CreateRelationCommandHandler());
$bus->register(new CreateIndexCommandHandler());
$bus->register(new InstallPlaybookCommandHandler());
$bus->register(new CrudCommandHandler());
$bus->register(new MapCommandHandler(['AuthLogin'], static fn(array $command, array $context): array => [
    'status' => 'success',
    'reply' => 'auth handler ok',
]));

$reply = static fn(
    string $text,
    string $channel,
    string $sessionId,
    string $userId,
    string $status = 'success',
    array $data = []
): array => [
    'status' => $status,
    'reply' => $text,
    'data' => $data,
    'channel' => $channel,
    'session_id' => $sessionId,
    'user_id' => $userId,
];

$baseContext = [
    'channel' => 'test',
    'session_id' => 'sess',
    'user_id' => 'user',
    'reply' => $reply,
];

$failures = [];

$entityGuard = $bus->dispatch(
    ['command' => 'CreateEntity', 'entity' => 'clientes'],
    array_merge($baseContext, ['mode' => 'app'])
);
if ((string) ($entityGuard['status'] ?? '') !== 'error') {
    $failures[] = 'CreateEntity guard failed';
}

$formGuard = $bus->dispatch(
    ['command' => 'CreateForm', 'entity' => 'clientes'],
    array_merge($baseContext, ['mode' => 'app'])
);
if ((string) ($formGuard['status'] ?? '') !== 'error') {
    $failures[] = 'CreateForm guard failed';
}

$relationGuard = $bus->dispatch(
    ['command' => 'CreateRelation', 'source_entity' => 'clientes', 'target_entity' => 'ventas'],
    array_merge($baseContext, ['mode' => 'app'])
);
if ((string) ($relationGuard['status'] ?? '') !== 'error') {
    $failures[] = 'CreateRelation guard failed';
}

$indexGuard = $bus->dispatch(
    ['command' => 'CreateIndex', 'entity' => 'clientes', 'field' => 'nombre'],
    array_merge($baseContext, ['mode' => 'app'])
);
if ((string) ($indexGuard['status'] ?? '') !== 'error') {
    $failures[] = 'CreateIndex guard failed';
}

$playbookGuard = $bus->dispatch(
    ['command' => 'InstallPlaybook'],
    array_merge($baseContext, ['mode' => 'app'])
);
if ((string) ($playbookGuard['status'] ?? '') !== 'error') {
    $failures[] = 'InstallPlaybook guard failed';
}

$crudGuard = $bus->dispatch(
    ['command' => 'CreateRecord', 'entity' => 'clientes', 'data' => []],
    array_merge($baseContext, ['mode' => 'builder'])
);
if ((string) ($crudGuard['status'] ?? '') !== 'error') {
    $failures[] = 'Crud mode guard failed';
}

$authResult = $bus->dispatch(
    ['command' => 'AuthLogin'],
    $baseContext
);
if ((string) ($authResult['status'] ?? '') !== 'success') {
    $failures[] = 'Map auth fallback failed';
}

try {
    $bus->dispatch(['command' => 'Unknown'], ['mode' => 'app']);
    $failures[] = 'Unknown command should fail';
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'COMMAND_NOT_SUPPORTED') {
        $failures[] = 'Unexpected exception code for unknown command';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
