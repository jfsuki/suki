<?php
// app/Core/MemoryRepositoryInterface.php

namespace App\Core;

interface MemoryRepositoryInterface
{
    public function getGlobalMemory(string $category, string $key, array $default = []): array;

    public function saveGlobalMemory(string $category, string $key, array $value): void;

    public function getTenantMemory(string $tenantId, string $key, array $default = []): array;

    public function saveTenantMemory(string $tenantId, string $key, array $value): void;

    public function getUserMemory(string $tenantId, string $userId, string $key, array $default = []): array;

    public function saveUserMemory(string $tenantId, string $userId, string $key, array $value): void;

    public function appendShortTermMemory(
        string $tenantId,
        string $userId,
        string $sessionId,
        string $channel,
        string $direction,
        string $message,
        array $meta = []
    ): void;

    public function getShortTermMemory(string $tenantId, string $sessionId, int $limit = 20): array;
}

