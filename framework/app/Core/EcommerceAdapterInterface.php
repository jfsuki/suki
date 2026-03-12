<?php

declare(strict_types=1);

namespace App\Core;

interface EcommerceAdapterInterface
{
    public function getPlatformKey(): string;

    public function supportsProductSync(): bool;

    public function supportsOrderSync(): bool;

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

    /**
     * @param array<string, mixed> $externalPayload
     * @return array<string, mixed>
     */
    public function normalizeExternalProduct(array $externalPayload): array;

    /**
     * @param array<string, mixed> $localProductPayload
     * @return array<string, mixed>
     */
    public function buildProductPayload(array $localProductPayload): array;

    /**
     * @param array<string, mixed> $externalPayload
     * @return array<string, mixed>
     */
    public function normalizeExternalOrder(array $externalPayload): array;

    /**
     * @param array<string, mixed> $localPayload
     * @return array<string, mixed>
     */
    public function buildOrderReferencePayload(array $localPayload): array;
}
