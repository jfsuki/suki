<?php
// framework/tests/command_bus_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\CommandBus;
use App\Core\MapCommandHandler;

$bus = new CommandBus();
$bus->register(new MapCommandHandler(['CreateEntity', 'CreateForm'], static function (array $command, array $context): array {
    return [
        'ok' => true,
        'command' => (string) ($command['command'] ?? ''),
        'mode' => (string) ($context['mode'] ?? ''),
    ];
}));

$failures = [];

$okResult = $bus->dispatch(['command' => 'CreateEntity'], ['mode' => 'builder']);
if (empty($okResult['ok']) || (string) ($okResult['mode'] ?? '') !== 'builder') {
    $failures[] = 'Dispatch known command failed';
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

