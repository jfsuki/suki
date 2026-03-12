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

    public function supportsProductSync(): bool
    {
        $capabilities = $this->listCapabilities();

        return $this->getPlatformKey() !== 'unknown'
            && (($capabilities['products_read'] ?? false) === true || ($capabilities['products_write'] ?? false) === true);
    }

    public function supportsOrderSync(): bool
    {
        $capabilities = $this->listCapabilities();

        return $this->getPlatformKey() !== 'unknown'
            && (($capabilities['orders_read'] ?? false) === true || ($capabilities['orders_write'] ?? false) === true);
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

    /**
     * @param array<string, mixed> $externalPayload
     * @return array<string, mixed>
     */
    public function normalizeExternalProduct(array $externalPayload): array
    {
        $externalProductId = $this->firstStringValue($externalPayload, [
            'external_product_id',
            'external_id',
            'product_id',
            'id',
        ]);
        $externalSku = $this->firstStringValue($externalPayload, [
            'external_sku',
            'sku',
            'reference',
            'code',
            'product_reference',
        ]);
        $name = $this->firstStringValue($externalPayload, ['name', 'title', 'nombre', 'label']);
        $status = $this->firstStringValue($externalPayload, ['status', 'state']);

        return [
            'platform' => $this->getPlatformKey(),
            'adapter_key' => $this->getPlatformKey(),
            'supports_product_sync' => $this->supportsProductSync(),
            'external_product_id' => $externalProductId,
            'external_sku' => $externalSku,
            'name' => $name,
            'status' => $status,
            'normalized' => $externalProductId !== null,
            'normalization_result' => $externalProductId !== null ? 'normalized' : 'missing_external_product_id',
            'sync_direction' => 'pull_store_to_local',
            'metadata' => [
                'foundation_only' => true,
                'source_field_count' => count($externalPayload),
                'source_fields' => array_values(array_filter(array_map(
                    static fn($key): string => is_string($key) ? trim($key) : '',
                    array_keys($externalPayload)
                ), static fn(string $key): bool => $key !== '')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $localProductPayload
     * @return array<string, mixed>
     */
    public function buildProductPayload(array $localProductPayload): array
    {
        $name = $this->firstStringValue($localProductPayload, ['name', 'label', 'product_label', 'title']);
        if ($name === null) {
            $fallbackId = $this->firstStringValue($localProductPayload, ['local_product_id', 'id']) ?? '';
            $name = 'Product ' . $fallbackId;
        }
        $sku = $this->firstStringValue($localProductPayload, ['sku', 'reference', 'external_sku']);
        $description = $this->firstStringValue($localProductPayload, ['description', 'subtitle']);

        return [
            'platform' => $this->getPlatformKey(),
            'adapter_key' => $this->getPlatformKey(),
            'supports_product_sync' => $this->supportsProductSync(),
            'build_result' => $this->supportsProductSync() ? 'payload_ready' : 'not_supported',
            'sync_direction' => 'push_local_to_store',
            'foundation_only' => true,
            'payload' => [
                'name' => $name,
                'sku' => $sku,
                'description' => $description,
                'source_local_product_id' => $this->firstStringValue($localProductPayload, ['local_product_id', 'id']),
                'source_reference' => $this->firstStringValue($localProductPayload, ['reference', 'sku']),
            ],
            'metadata' => [
                'api_family' => $this->platformMetadata([])['api_family'] ?? $this->getPlatformKey(),
                'remote_operation' => 'products.upsert.pending',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $externalPayload
     * @return array<string, mixed>
     */
    public function normalizeExternalOrder(array $externalPayload): array
    {
        $externalOrderId = $this->firstStringValue($externalPayload, [
            'external_order_id',
            'external_id',
            'order_id',
            'id',
            'number',
        ]);
        $externalStatus = $this->firstStringValue($externalPayload, [
            'external_status',
            'status',
            'order_status',
            'current_state',
            'financial_status',
            'fulfillment_status',
        ]);
        $currency = $this->firstStringValue($externalPayload, ['currency', 'currency_code', 'currency_iso']);
        $currency = $currency !== null ? strtoupper($currency) : null;
        $total = $this->firstNumericValue($externalPayload, ['total', 'grand_total', 'amount_total', 'total_paid']);
        $lineItems = $this->normalizeOrderLineItems($externalPayload);

        return [
            'platform' => $this->getPlatformKey(),
            'adapter_key' => $this->getPlatformKey(),
            'supports_order_sync' => $this->supportsOrderSync(),
            'external_order_id' => $externalOrderId,
            'external_status' => $externalStatus,
            'normalized_status' => $this->normalizeOrderStatusValue($externalStatus),
            'currency' => $currency,
            'total' => $total,
            'line_items' => $lineItems,
            'line_count' => count($lineItems),
            'customer_reference' => $this->firstStringValue($externalPayload, [
                'customer_email',
                'email',
                'customer_id',
                'customer_reference',
            ]),
            'normalized' => $externalOrderId !== null,
            'normalization_result' => $externalOrderId !== null ? 'normalized' : 'missing_external_order_id',
            'metadata' => [
                'foundation_only' => true,
                'source_field_count' => count($externalPayload),
                'source_fields' => array_values(array_filter(array_map(
                    static fn($key): string => is_string($key) ? trim($key) : '',
                    array_keys($externalPayload)
                ), static fn(string $key): bool => $key !== '')),
                'future_hooks' => [
                    'local_sale_creation' => 'pending',
                    'fiscal_linkage' => 'pending',
                    'inventory_movement' => 'pending',
                    'customer_resolution' => 'pending',
                    'payment_reconciliation' => 'pending',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $localPayload
     * @return array<string, mixed>
     */
    public function buildOrderReferencePayload(array $localPayload): array
    {
        $currency = $this->firstStringValue($localPayload, ['currency', 'currency_code']);
        $currency = $currency !== null ? strtoupper($currency) : null;

        return [
            'platform' => $this->getPlatformKey(),
            'adapter_key' => $this->getPlatformKey(),
            'supports_order_sync' => $this->supportsOrderSync(),
            'build_result' => $this->supportsOrderSync() ? 'payload_ready' : 'not_supported',
            'foundation_only' => true,
            'payload' => [
                'source_local_reference_type' => $this->firstStringValue($localPayload, ['local_reference_type', 'reference_type']),
                'source_local_reference_id' => $this->firstStringValue($localPayload, ['local_reference_id', 'reference_id', 'id']),
                'status' => $this->normalizeOrderStatusValue($this->firstStringValue($localPayload, ['local_status', 'status'])),
                'currency' => $currency,
                'total' => $this->firstNumericValue($localPayload, ['total', 'amount_total']),
            ],
            'metadata' => [
                'api_family' => $this->platformMetadata([])['api_family'] ?? $this->getPlatformKey(),
                'remote_operation' => 'orders.upsert.pending',
            ],
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
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    protected function firstStringValue(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->nullableString($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    protected function firstNumericValue(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (is_numeric($value)) {
                return round((float) $value, 4);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeOrderLineItems(array $payload): array
    {
        $lineSource = $payload['line_items'] ?? $payload['items'] ?? $payload['products'] ?? null;
        if (!is_array($lineSource)) {
            return [];
        }

        $normalized = [];
        foreach ($lineSource as $line) {
            if (!is_array($line)) {
                continue;
            }
            $normalized[] = [
                'external_product_id' => $this->firstStringValue($line, ['external_product_id', 'product_id', 'id']),
                'sku' => $this->firstStringValue($line, ['sku', 'reference', 'product_reference']),
                'name' => $this->firstStringValue($line, ['name', 'title', 'product_name']),
                'quantity' => $this->firstNumericValue($line, ['quantity', 'qty', 'units']),
                'unit_price' => $this->firstNumericValue($line, ['unit_price', 'price', 'price_unit']),
                'total' => $this->firstNumericValue($line, ['total', 'line_total', 'subtotal']),
            ];
        }

        return $normalized;
    }

    protected function normalizeOrderStatusValue(?string $status): string
    {
        $status = strtolower(trim((string) $status));
        if ($status === '') {
            return 'unknown';
        }

        return match (true) {
            in_array($status, ['pending', 'on-hold', 'awaiting_payment', 'unpaid', 'payment_pending', 'held'], true) => 'pending',
            in_array($status, ['paid', 'authorized'], true) => 'paid',
            in_array($status, ['processing', 'in_progress', 'confirmed', 'preparing'], true) => 'processing',
            in_array($status, ['completed', 'delivered', 'shipped', 'fulfilled'], true) => 'completed',
            in_array($status, ['canceled', 'cancelled', 'void', 'annulled'], true) => 'canceled',
            in_array($status, ['refunded', 'partial_refund', 'partially_refunded', 'returned'], true) => 'refunded',
            default => 'unknown',
        };
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
