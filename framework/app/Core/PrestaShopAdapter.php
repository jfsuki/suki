<?php

declare(strict_types=1);

namespace App\Core;

final class PrestaShopAdapter extends AbstractEcommerceAdapter
{
    public function getPlatformKey(): string
    {
        return 'prestashop';
    }

    protected function platformLabel(): string
    {
        return 'PrestaShop';
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
            'webhooks' => false,
            'customers_read' => true,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function requiredCredentialGroups(): array
    {
        return [
            'api_key_or_webservice_key' => ['api_key', 'webservice_key', 'token', 'key'],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function requiredStoreFields(): array
    {
        return ['store_url'];
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    protected function platformMetadata(array $store): array
    {
        return [
            'api_family' => 'prestashop_webservice',
            'recommended_ping_path' => '/api/',
        ];
    }

    /**
     * @param array<string, mixed> $store
     */
    protected function defaultPingEndpoint(array $store): ?string
    {
        $storeUrl = $this->nullableString($store['store_url'] ?? null);
        if ($storeUrl === null) {
            return null;
        }

        return rtrim($storeUrl, '/') . '/api/';
    }
}
