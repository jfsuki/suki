<?php
// app/Core/CommandHandlerInterface.php

namespace App\Core;

interface CommandHandlerInterface
{
    public function supports(string $commandName): bool;

    public function handle(array $command, array $context): array;
}

