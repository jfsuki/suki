<?php
// framework/app/Core/InventoryService.php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class InventoryService
{
    private InventoryRepository $repository;

    public function __construct(?InventoryRepository $repository = null)
    {
        $this->repository = $repository ?? new InventoryRepository();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addProduct(string $tenantId, array $data): array
    {
        if (empty($data['sku']) || empty($data['nombre'])) {
            throw new RuntimeException('SKU and Product Name are required.');
        }

        // Check if SKU exists
        if ($this->repository->findProduct($tenantId, (string) $data['sku'])) {
            throw new RuntimeException('Product SKU already exists in this tenant.');
        }

        $id = $this->repository->createProduct($data);
        
        return $this->repository->findProduct($tenantId, $id) ?? [];
    }

    /**
     * Mass import products (from CSV/Excel mapping)
     * @param array<int, array<string, mixed>> $rows
     */
    public function importProducts(string $tenantId, array $rows): array
    {
        $prepared = [];
        foreach ($rows as $row) {
            if (empty($row['sku']) || empty($row['nombre'])) continue;
            
            $prepared[] = [
                'tenant_id' => $tenantId,
                'sku' => (string) $row['sku'],
                'nombre' => (string) $row['nombre'],
                'descripcion' => (string) ($row['descripcion'] ?? ''),
                'precio_venta' => (float) ($row['precio_venta'] ?? 0),
                'stock_actual' => (float) ($row['stock_actual'] ?? 0),
                'stock_minimo' => (float) ($row['stock_minimo'] ?? 5),
                'categoria' => (string) ($row['categoria'] ?? 'General'),
            ];
        }

        $count = $this->repository->bulkCreateProducts($prepared);
        
        return [
            'ok' => true,
            'count' => $count,
            'message' => "Se han importado {$count} productos correctamente."
        ];
    }

    /**
     * @param array<string, mixed> $updates
     */
    public function updateProductInfo(string $tenantId, string $productId, array $updates): array
    {
        $this->repository->updateProduct($tenantId, $productId, $updates);
        return $this->repository->findProduct($tenantId, $productId) ?? [];
    }

    public function adjustStock(string $tenantId, string $productId, float $qty, string $reason, string $userId): array
    {
        $product = $this->repository->findProduct($tenantId, $productId);
        if (!$product) {
            throw new RuntimeException('Product not found.');
        }

        $this->repository->recordMovement([
            'tenant_id' => $tenantId,
            'producto_id' => $product['id'],
            'tipo' => 'AJUSTE',
            'cantidad' => $qty,
            'motivo' => $reason,
            'usuario_id' => $userId
        ]);

        return $this->repository->findProduct($tenantId, $productId) ?? [];
    }

    /**
     * Registra una salida de inventario por venta.
     *
     * Respeta los campos de control del producto:
     *  - inventariable=false → no descuenta stock (servicio o ítem sin inventario)
     *  - controla_unidades=false → no descuenta unidades (solo control contable)
     *  - permite_venta_sin_stock=false → retorna advisory cuando hay déficit
     *
     * @return array{product: array, advisory: array|null, stock_after: float}
     */
    public function registerSale(string $tenantId, string $productId, float $qty, string $ref, string $userId): array
    {
        $product = $this->repository->findProduct($tenantId, $productId);
        if (!$product) {
            throw new RuntimeException('Product not found.');
        }

        $inventariable    = filter_var($product['inventariable']           ?? true,  FILTER_VALIDATE_BOOLEAN);
        $controlaUnidades = filter_var($product['controla_unidades']       ?? true,  FILTER_VALIDATE_BOOLEAN);
        $permiteNegativo  = filter_var($product['permite_venta_sin_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Servicios e ítems no inventariables: registrar la venta pero NO descontar stock
        if (!$inventariable || !$controlaUnidades) {
            return [
                'product'     => $product,
                'advisory'    => null,
                'stock_after' => (float) ($product['stock_actual'] ?? 0),
                'stock_deducted' => false,
            ];
        }

        $stockActual = (float) ($product['stock_actual'] ?? 0);
        $advisory    = null;

        if ($stockActual < $qty) {
            $deficit  = $qty - $stockActual;
            $advisory = [
                'type'          => $permiteNegativo ? 'warning' : 'advisory',
                'stock_antes'   => $stockActual,
                'qty_solicitada'=> $qty,
                'deficit'       => $deficit,
                'message'       => $permiteNegativo
                    ? "⚠ Venta registrada con stock insuficiente. Déficit: {$deficit} unidades."
                    : "Stock insuficiente (disponible: {$stockActual}, solicitado: {$qty}). Venta procesada. Revisar pendientes.",
            ];

            error_log("[InventoryAdvisor] tenant={$tenantId} product={$productId} stock={$stockActual} sold={$qty} deficit={$deficit}");
        }

        $this->repository->recordMovement([
            'tenant_id'          => $tenantId,
            'producto_id'        => $product['id'],
            'tipo'               => 'SALIDA',
            'cantidad'           => $qty,
            'referencia_externa' => $ref,
            'usuario_id'         => $userId,
            'costo_unitario'     => (float) ($product['costo_unitario'] ?? 0),
        ]);

        $updated = $this->repository->findProduct($tenantId, $productId) ?? $product;

        return [
            'product'        => $updated,
            'advisory'       => $advisory,
            'stock_after'    => (float) ($updated['stock_actual'] ?? 0),
            'stock_deducted' => true,
        ];
    }

    public function registerPurchase(string $tenantId, string $productId, float $qty, string $ref, string $userId): array
    {
        $product = $this->repository->findProduct($tenantId, $productId);
        if (!$product) {
            throw new RuntimeException('Product not found.');
        }

        $this->repository->recordMovement([
            'tenant_id' => $tenantId,
            'producto_id' => $product['id'],
            'tipo' => 'ENTRADA',
            'cantidad' => $qty,
            'referencia_externa' => $ref,
            'usuario_id' => $userId
        ]);

        return $this->repository->findProduct($tenantId, $productId) ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLowStockAlerts(string $tenantId): array
    {
        return $this->repository->listProducts($tenantId, ['low_stock' => true]);
    }

    public function getProductStock(string $tenantId, string $idOrSku): array
    {
        $product = $this->repository->findProduct($tenantId, $idOrSku);
        if (!$product) {
            throw new RuntimeException('Product not found.');
        }

        return [
            'id' => $product['id'],
            'sku' => $product['sku'],
            'nombre' => $product['nombre'],
            'stock_actual' => $product['stock_actual'],
            'stock_minimo' => $product['stock_minimo'],
            'status' => ((float)$product['stock_actual'] <= (float)$product['stock_minimo']) ? 'CRITICAL' : 'OK'
        ];
    }
}
