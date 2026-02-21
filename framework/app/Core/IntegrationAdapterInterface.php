<?php
// app/Core/IntegrationAdapterInterface.php

namespace App\Core;

interface IntegrationAdapterInterface
{
    /**
     * @param array<string,mixed> $integration
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function execute(string $action, array $integration, array $payload, array $context = []): array;
}

