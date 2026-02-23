<?php
// app/Core/CommandBus.php

namespace App\Core;

use RuntimeException;

final class CommandBus
{
    /** @var array<int, CommandHandlerInterface> */
    private array $handlers = [];

    public function register(CommandHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function dispatch(array $command, array $context = []): array
    {
        $commandName = (string) ($command['command'] ?? '');
        if ($commandName === '') {
            throw new RuntimeException('COMMAND_MISSING');
        }

        foreach ($this->handlers as $handler) {
            if ($handler->supports($commandName)) {
                return $handler->handle($command, $context);
            }
        }

        throw new RuntimeException('COMMAND_NOT_SUPPORTED');
    }
}

