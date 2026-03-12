<?php

declare(strict_types=1);

namespace App\Core;

final class TiendanubeAdapter extends AbstractEcommerceAdapter
{
    public function getPlatformKey(): string
    {
        return 'tiendanube';
    }

    protected function platformLabel(): string
    {
        return 'Tiendanube';
    }

    /**
     * @return array<string, bool>
     */
    protected function capabilityMap(): array
    {
        return [
            'products_read' => true,
            'products_write' => true,
            'orders_read' => true,
            'orders_write' => true,
            'inventory_sync' => true,
            'webhooks' => true,
            'customers_read' => true,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function requiredCredentialGroups(): array
    {
        return [
            'access_token_or_token' => ['access_token', 'token'],
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function validateCredentials(array $store, array $credentials): array
    {
        $validation = parent::validateCredentials($store, $credentials);
        $externalStoreId = $this->nullableString($this->resolvePath($store, 'metadata.external_store_id'));
        $storeUrl = $this->nullableString($store['store_url'] ?? null);

        if ($externalStoreId === null && $storeUrl === null) {
            $missingStoreFields = is_array($validation['missing_store_fields'] ?? null)
                ? (array) $validation['missing_store_fields']
                : [];
            $missingStoreFields[] = 'store_url_or_metadata.external_store_id';
            $validation['missing_store_fields'] = array_values(array_unique($missingStoreFields));
            $validation['valid'] = false;
            $validation['validation_result'] = $this->hasAnyScalarValue($credentials)
                ? 'missing_store_locator'
                : 'missing_credentials';
            $validation['connection_status'] = $this->hasAnyScalarValue($credentials) ? 'failed' : 'not_configured';
        }

        return $validation;
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    protected function platformMetadata(array $store): array
    {
        return [
            'api_family' => 'nuvemshop_api',
            'requires_store_locator' => true,
            'external_store_id' => $this->nullableString($this->resolvePath($store, 'metadata.external_store_id')),
        ];
    }

    /**
     * @param array<string, mixed> $store
     */
    protected function defaultPingEndpoint(array $store): ?string
    {
        $externalStoreId = $this->nullableString($this->resolvePath($store, 'metadata.external_store_id'));
        if ($externalStoreId !== null) {
            return 'https://api.tiendanube.com/v1/' . $externalStoreId;
        }

        return $this->nullableString($store['store_url'] ?? null);
    }

    /**
     * @param array<string, mixed> $localProductPayload
     * @return array<string, mixed>
     */
    public function buildProductPayload(array $localProductPayload): array
    {
        $built = parent::buildProductPayload($localProductPayload);
        $payload = is_array($built['payload'] ?? null) ? (array) $built['payload'] : [];
        $payload['published'] = false;
        $built['payload'] = $payload;

        return $built;
    }
}
