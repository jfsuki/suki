<?php
// framework/app/Core/InventoryMessageParser.php

declare(strict_types=1);

namespace App\Core;

final class InventoryMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $telemetry = ['module_used' => 'inventory', 'action' => $skillName];

        return match ($skillName) {
            'inventory_adjust_stock' => $this->parseAdjustStock($message, $pairs, $telemetry),
            'inventory_create_product' => $this->parseCreateProduct($message, $pairs, $telemetry),
            'inventory_check_stock' => $this->parseCheckStock($message, $pairs, $telemetry),
            'inventory_list_products' => $this->parseListProducts($pairs, $telemetry),
            default => [
                'kind' => 'ask_user', 
                'reply' => 'No pude interpretar la operación de inventario.', 
                'telemetry' => $telemetry
            ],
        };
    }

    private function parseAdjustStock(string $message, array $pairs, array $telemetry): array
    {
        $sku = $pairs['sku'] ?? $this->findSku($message);
        $qty = (float) ($pairs['qty'] ?? $pairs['cantidad'] ?? 1);
        $isAdd = !str_contains(strtolower($message), 'quita') && !str_contains(strtolower($message), 'resta');

        if (empty($sku)) {
            return [
                'kind' => 'ask_user',
                'reply' => 'Por favor dime el SKU o código del producto para ajustar el stock.',
                'telemetry' => $telemetry
            ];
        }

        return [
            'kind' => 'command',
            'action' => 'adjust_stock',
            'sku' => $sku,
            'qty' => $isAdd ? $qty : -$qty,
            'tipo' => $isAdd ? 'ENTRADA' : 'SALIDA',
            'motivo' => $pairs['motivo'] ?? 'Ajuste manual via AI',
            'telemetry' => $telemetry
        ];
    }

    private function parseCreateProduct(string $message, array $pairs, array $telemetry): array
    {
        $nombre = $pairs['nombre'] ?? $pairs['name'] ?? '';
        $sku = $pairs['sku'] ?? '';
        $precio = (float) ($pairs['precio'] ?? $pairs['price'] ?? 0);

        if (empty($nombre)) {
            return [
                'kind' => 'ask_user',
                'reply' => 'Necesito al menos el nombre del producto para crearlo.',
                'telemetry' => $telemetry
            ];
        }

        return [
            'kind' => 'command',
            'action' => 'create_product',
            'data' => [
                'nombre' => $nombre,
                'sku' => $sku,
                'precio_venta' => $precio,
                'stock_actual' => (float) ($pairs['stock'] ?? 0)
            ],
            'telemetry' => $telemetry
        ];
    }

    private function parseCheckStock(string $message, array $pairs, array $telemetry): array
    {
        return [
            'kind' => 'command',
            'action' => 'check_stock',
            'sku' => $pairs['sku'] ?? $this->findSku($message),
            'telemetry' => $telemetry
        ];
    }

    private function parseListProducts(array $pairs, array $telemetry): array
    {
        return [
            'kind' => 'command',
            'action' => 'list_products',
            'filters' => [
                'nombre' => $pairs['nombre'] ?? null,
                'sku' => $pairs['sku'] ?? null
            ],
            'telemetry' => $telemetry
        ];
    }

    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+):\s*([a-zA-Z0-9_-]+)/', $message, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $pairs[strtolower($match[1])] = $match[2];
        }
        return $pairs;
    }

    private function findSku(string $message): ?string
    {
        if (preg_match('/\b[A-Za-z0-9]{3,15}\b/', $message, $match)) {
            return $match[0];
        }
        return null;
    }
}
