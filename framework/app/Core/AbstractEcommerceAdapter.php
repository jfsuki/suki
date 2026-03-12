<?php

declare(strict_types=1);

namespace App\Core;

abstract class AbstractEcommerceAdapter implements EcommerceAdapterInterface
{
    /** @var array<int, string> */
    private const CAPABILITY_KEYS = [
        'products_read',
        'products_write',
        'orders_read',
        'orders_write',
        'inventory_sync',
        'webhooks',
        'customers_read',
    ];

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function validateCredentials(array $store, array $credentials): array
    {
        $missingStoreFields = $this->missingStoreFields($store);
        $missingCredentials = $this->missingCredentialGroups($credentials);
        $hasCredentials = $this->hasAnyScalarValue($credentials);
        $valid = $missingStoreFields === [] && $missingCredentials === [];

        return [
            'platform' => $this->storePlatform($store),
            'adapter_key' => $this->getPlatformKey(),
            'valid' => $valid,
            'validation_result' => $valid
                ? 'credentials_format_valid'
                : ($hasCredentials ? 'credentials_incomplete' : 'missing_credentials'),
            'connection_status' => $valid
                ? 'pending_validation'
                : ($hasCredentials ? 'failed' : 'not_configured'),
            'required_credentials' => $this->credentialRequirementLabels(),
            'required_store_fields' => $this->storeFieldLabels(),
            'missing_credentials' => $missingCredentials,
            'missing_store_fields' => $missingStoreFields,
            'remote_ping_supported' => $this->supportsRemotePing(),
            'capabilities' => $this->listCapabilities(),
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    public function getConnectionStatus(array $store): array
    {
        $storedStatus = strtolower(trim((string) ($store['connection_status'] ?? '')));
        if (!in_array($storedStatus, ['not_configured', 'pending_validation', 'validated', 'failed'], true)) {
            $storedStatus = 'not_configured';
        }

        return [
            'platform' => $this->storePlatform($store),
            'adapter_key' => $this->getPlatformKey(),
            'connection_status' => $storedStatus,
            'validation_result' => $storedStatus === 'validated' ? 'stored_validated' : 'stored_status_only',
            'remote_ping_supported' => $this->supportsRemotePing(),
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    public function getStoreMetadata(array $store): array
    {
        $capabilities = $this->listCapabilities();

        return [
            'store_id' => trim((string) ($store['id'] ?? '')),
            'platform' => $this->storePlatform($store),
            'adapter_key' => $this->getPlatformKey(),
            'platform_label' => $this->platformLabel(),
            'store_name' => trim((string) ($store['store_name'] ?? '')),
            'store_url' => $this->nullableString($store['store_url'] ?? null),
            'status' => trim((string) ($store['status'] ?? 'inactive')) ?: 'inactive',
            'connection_status' => trim((string) ($store['connection_status'] ?? 'not_configured')) ?: 'not_configured',
            'currency' => $this->nullableString($store['currency'] ?? null),
            'timezone' => $this->nullableString($store['timezone'] ?? null),
            'capabilities' => $capabilities,
            'remote_ping_supported' => $this->supportsRemotePing(),
            'required_credentials' => $this->credentialRequirementLabels(),
            'required_store_fields' => $this->storeFieldLabels(),
            'metadata' => [
                'adapter_scope' => 'foundation',
                'platform_label' => $this->platformLabel(),
                'capability_count' => count(array_filter($capabilities, static fn(bool $enabled): bool => $enabled)),
                'default_ping_endpoint' => $this->defaultPingEndpoint($store),
            ] + $this->platformMetadata($store),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function listCapabilities(): array
    {
        return array_replace(array_fill_keys(self::CAPABILITY_KEYS, false), $this->capabilityMap());
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function ping(array $store, array $credentials): array
    {
        $validation = $this->validateCredentials($store, $credentials);
        if (($validation['valid'] ?? false) !== true) {
            return [
                'platform' => $this->storePlatform($store),
                'adapter_key' => $this->getPlatformKey(),
                'ping_attempted' => false,
                'reachable' => false,
                'validation_result' => (string) ($validation['validation_result'] ?? 'missing_credentials'),
                'connection_status' => (string) ($validation['connection_status'] ?? 'not_configured'),
                'result_status' => 'failed',
                'checked_endpoint' => $this->defaultPingEndpoint($store),
                'message' => 'The store configuration is incomplete for a safe ping.',
            ];
        }

        return [
            'platform' => $this->storePlatform($store),
            'adapter_key' => $this->getPlatformKey(),
            'ping_attempted' => false,
            'reachable' => false,
            'validation_result' => $this->supportsRemotePing() ? 'remote_ping_disabled' : 'not_implemented',
            'connection_status' => (string) ($validation['connection_status'] ?? 'pending_validation'),
            'result_status' => $this->supportsRemotePing() ? 'safe_failure' : 'not_implemented',
            'checked_endpoint' => $this->defaultPingEndpoint($store),
            'message' => $this->supportsRemotePing()
                ? 'Remote ping is disabled for this adapter foundation.'
                : 'Remote ping is not implemented in this adapter foundation.',
        ];
    }

    protected function platformLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->getPlatformKey()));
    }

    protected function supportsRemotePing(): bool
    {
        return false;
    }

    /**
     * @return array<string, bool>
     */
    protected function capabilityMap(): array
    {
        return [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function requiredCredentialGroups(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function requiredStoreFields(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    protected function platformMetadata(array $store): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $store
     */
    protected function defaultPingEndpoint(array $store): ?string
    {
        $storeUrl = $this->nullableString($store['store_url'] ?? null);

        return $storeUrl !== null ? rtrim($storeUrl, '/') : null;
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<int, string>
     */
    protected function missingCredentialGroups(array $credentials): array
    {
        $normalized = $this->normalizeCredentialMap($credentials);
        $missing = [];
        foreach ($this->requiredCredentialGroups() as $label => $keys) {
            $found = false;
            foreach ($keys as $key) {
                $value = $this->nullableString($normalized[strtolower($key)] ?? null);
                if ($value !== null) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $store
     * @return array<int, string>
     */
    protected function missingStoreFields(array $store): array
    {
        $missing = [];
        foreach ($this->requiredStoreFields() as $field) {
            if ($this->nullableString($this->resolvePath($store, $field)) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    protected function normalizeCredentialMap(array $credentials): array
    {
        $normalized = [];
        foreach ($credentials as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[strtolower(trim($key))] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function hasAnyScalarValue(array $payload): bool
    {
        foreach ($payload as $value) {
            if ($this->nullableString($value) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function credentialRequirementLabels(): array
    {
        return array_keys($this->requiredCredentialGroups());
    }

    /**
     * @return array<int, string>
     */
    protected function storeFieldLabels(): array
    {
        return $this->requiredStoreFields();
    }

    /**
     * @param array<string, mixed> $payload
     * @return mixed
     */
    protected function resolvePath(array $payload, string $path)
    {
        $current = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param mixed $value
     */
    protected function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $store
     */
    protected function storePlatform(array $store): string
    {
        $platform = strtolower(trim((string) ($store['platform'] ?? '')));

        return $platform !== '' ? $platform : $this->getPlatformKey();
    }
}
