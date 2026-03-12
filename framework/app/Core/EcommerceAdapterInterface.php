<?php

declare(strict_types=1);

namespace App\Core;

interface EcommerceAdapterInterface
{
    public function getPlatformKey(): string;

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function validateCredentials(array $store, array $credentials): array;

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    public function getConnectionStatus(array $store): array;

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    public function getStoreMetadata(array $store): array;

    /**
     * @return array<string, bool>
     */
    public function listCapabilities(): array;

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function ping(array $store, array $credentials): array;
}
