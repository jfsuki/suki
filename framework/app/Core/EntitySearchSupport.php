<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

final class EntitySearchSupport
{
    /** @var array<int, string> */
    private const SUPPORTED_TYPES = [
        'product',
        'sale',
        'purchase',
        'invoice',
        'customer',
        'supplier',
        'order',
        'media_file',
    ];

    /** @var array<string, array<int, string>> */
    private const ENTITY_ALIASES = [
        'product' => [
            'product', 'products', 'producto', 'productos', 'item', 'items', 'articulo', 'articulos',
            'referencia', 'referencias', 'sku', 'barcode', 'codigo de barras',
        ],
        'sale' => [
            'sale', 'sales', 'venta', 'ventas', 'ticket', 'tickets', 'transaccion venta', 'transaccion_venta',
        ],
        'purchase' => [
            'purchase', 'purchases', 'compra', 'compras', 'orden de compra', 'orden_compra', 'purchase order',
        ],
        'invoice' => [
            'invoice', 'invoices', 'factura', 'facturas', 'documento fiscal', 'fiscal',
        ],
        'customer' => [
            'customer', 'customers', 'cliente', 'clientes', 'tercero', 'terceros',
        ],
        'supplier' => [
            'supplier', 'suppliers', 'proveedor', 'proveedores', 'vendor', 'vendors',
        ],
        'order' => [
            'order', 'orders', 'pedido', 'pedidos', 'orden', 'ordenes',
        ],
        'media_file' => [
            'media', 'archivo', 'archivos', 'documento', 'documentos', 'imagen', 'imagenes', 'adjunto', 'adjuntos',
        ],
    ];

    public static function supportedTypes(): array
    {
        return self::SUPPORTED_TYPES;
    }

    public static function normalizeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        $value = strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function normalizeEntityType(?string $value): ?string
    {
        $value = self::normalizeText((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (self::ENTITY_ALIASES as $entityType => $aliases) {
            if ($value === $entityType || in_array($value, $aliases, true)) {
                return $entityType;
            }
        }

        return null;
    }

    public static function inferEntityTypeFromText(string $text): ?string
    {
        $normalized = self::normalizeText($text);
        if ($normalized === '') {
            return null;
        }

        foreach (self::ENTITY_ALIASES as $entityType => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/(?:^|\b)' . preg_quote($alias, '/') . '(?:$|\b)/u', $normalized) === 1) {
                    return $entityType;
                }
            }
        }

        if (preg_match('/\b(vende|vender|venta|cotiza|cotizar)\b/u', $normalized) === 1) {
            return 'product';
        }

        return null;
    }

    /**
     * @return array{date_from:?string,date_to:?string,recency_hint:?string}
     */
    public static function inferRecencyFilters(string $text): array
    {
        $normalized = self::normalizeText($text);
        if ($normalized === '') {
            return ['date_from' => null, 'date_to' => null, 'recency_hint' => null];
        }

        $today = new DateTimeImmutable('today');
        if (preg_match('/\b(ayer|yesterday)\b/u', $normalized) === 1) {
            $day = $today->modify('-1 day');
            return [
                'date_from' => $day->format('Y-m-d 00:00:00'),
                'date_to' => $day->format('Y-m-d 23:59:59'),
                'recency_hint' => 'yesterday',
            ];
        }

        if (preg_match('/\b(hoy|today)\b/u', $normalized) === 1) {
            return [
                'date_from' => $today->format('Y-m-d 00:00:00'),
                'date_to' => $today->format('Y-m-d 23:59:59'),
                'recency_hint' => 'today',
            ];
        }

        if (preg_match('/\b(ultimo|ultima|ultimos|ultimas|latest|last|reciente|recientes)\b/u', $normalized) === 1) {
            return ['date_from' => null, 'date_to' => null, 'recency_hint' => 'latest'];
        }

        return ['date_from' => null, 'date_to' => null, 'recency_hint' => null];
    }

    /**
     * @return array<int, string>
     */
    public static function aliasesForType(string $entityType): array
    {
        $entityType = self::normalizeEntityType($entityType) ?? '';
        if ($entityType === '') {
            return [];
        }

        return self::ENTITY_ALIASES[$entityType] ?? [];
    }
}
