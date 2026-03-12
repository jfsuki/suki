<?php

declare(strict_types=1);

namespace App\Core;

final class EcommerceAdapterResolver
{
    public function resolve(?string $platform): EcommerceAdapterInterface
    {
        $platform = strtolower(trim((string) $platform));

        return match ($platform) {
            'woocommerce' => new WooCommerceAdapter(),
            'tiendanube' => new TiendanubeAdapter(),
            'prestashop' => new PrestaShopAdapter(),
            default => new UnknownEcommerceAdapter($platform !== '' ? $platform : 'unknown'),
        };
    }
}
