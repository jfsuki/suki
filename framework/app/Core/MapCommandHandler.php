<?php
// app/Core/MapCommandHandler.php

namespace App\Core;

final class MapCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private array $commands;
    /** @var callable */
    private $handler;

    /**
     * @param array<int, string> $commands
     */
    public function __construct(array $commands, callable $handler)
    {
        $this->commands = array_values(array_filter(array_map(static fn($v) => (string) $v, $commands)));
        $this->handler = $handler;
    }

    public function supports(string $commandName): bool
    {
        return in_array($commandName, $this->commands, true);
    }

    public function handle(array $command, array $context): array
    {
        return ($this->handler)($command, $context);
    }
}

