<?php

declare(strict_types=1);

namespace App\Core;

final class UnknownEcommerceAdapter implements EcommerceAdapterInterface
{
    private string $requestedPlatform;

    public function __construct(string $requestedPlatform = 'unknown')
    {
        $requestedPlatform = strtolower(trim($requestedPlatform));
        $this->requestedPlatform = $requestedPlatform !== '' ? $requestedPlatform : 'unknown';
    }

    public function getPlatformKey(): string
    {
        return 'unknown';
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function validateCredentials(array $store, array $credentials): array
    {
        return [
            'platform' => $this->platformFromStore($store),
            'adapter_key' => $this->getPlatformKey(),
            'valid' => false,
            'validation_result' => 'unsupported_platform',
            'connection_status' => 'failed',
            'required_credentials' => [],
            'required_store_fields' => [],
            'missing_credentials' => [],
            'missing_store_fields' => [],
            'remote_ping_supported' => false,
            'capabilities' => $this->listCapabilities(),
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    public function getConnectionStatus(array $store): array
    {
        return [
            'platform' => $this->platformFromStore($store),
            'adapter_key' => $this->getPlatformKey(),
            'connection_status' => 'failed',
            'validation_result' => 'unsupported_platform',
            'remote_ping_supported' => false,
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    public function getStoreMetadata(array $store): array
    {
        return [
            'store_id' => trim((string) ($store['id'] ?? '')),
            'platform' => $this->platformFromStore($store),
            'adapter_key' => $this->getPlatformKey(),
            'platform_label' => 'Unknown',
            'store_name' => trim((string) ($store['store_name'] ?? '')),
            'store_url' => $this->nullableString($store['store_url'] ?? null),
            'status' => trim((string) ($store['status'] ?? 'inactive')) ?: 'inactive',
            'connection_status' => trim((string) ($store['connection_status'] ?? 'failed')) ?: 'failed',
            'currency' => $this->nullableString($store['currency'] ?? null),
            'timezone' => $this->nullableString($store['timezone'] ?? null),
            'capabilities' => $this->listCapabilities(),
            'remote_ping_supported' => false,
            'required_credentials' => [],
            'required_store_fields' => [],
            'metadata' => [
                'adapter_scope' => 'fallback',
                'requested_platform' => $this->requestedPlatform,
                'default_ping_endpoint' => null,
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function listCapabilities(): array
    {
        return [
            'products_read' => false,
            'products_write' => false,
            'orders_read' => false,
            'orders_write' => false,
            'inventory_sync' => false,
            'webhooks' => false,
            'customers_read' => false,
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function ping(array $store, array $credentials): array
    {
        return [
            'platform' => $this->platformFromStore($store),
            'adapter_key' => $this->getPlatformKey(),
            'ping_attempted' => false,
            'reachable' => false,
            'validation_result' => 'unsupported_platform',
            'connection_status' => 'failed',
            'result_status' => 'not_implemented',
            'checked_endpoint' => null,
            'message' => 'No adapter foundation is available for this platform.',
        ];
    }

    /**
     * @param array<string, mixed> $store
     */
    private function platformFromStore(array $store): string
    {
        $platform = strtolower(trim((string) ($store['platform'] ?? '')));

        return $platform !== '' ? $platform : $this->requestedPlatform;
    }

    /**
     * @param mixed $value
     */
    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
