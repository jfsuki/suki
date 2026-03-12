<?php

declare(strict_types=1);

namespace App\Core;

final class WooCommerceAdapter extends AbstractEcommerceAdapter
{
    public function getPlatformKey(): string
    {
        return 'woocommerce';
    }

    protected function platformLabel(): string
    {
        return 'WooCommerce';
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
            'api_key_or_consumer_key' => ['api_key', 'consumer_key'],
            'secret_or_consumer_secret' => ['secret', 'consumer_secret'],
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
            'api_family' => 'woocommerce_rest',
            'recommended_ping_path' => '/wp-json/wc/v3',
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

        return rtrim($storeUrl, '/') . '/wp-json/wc/v3';
    }
}
