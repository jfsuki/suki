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

        $data['tenant_id'] = $tenantId;
        $id = $this->repository->createProduct($data);
        
        return $this->repository->findProduct($tenantId, $id) ?? [];
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

    public function registerSale(string $tenantId, string $productId, float $qty, string $ref, string $userId): array
    {
        $product = $this->repository->findProduct($tenantId, $productId);
        if (!$product) {
            throw new RuntimeException('Product not found.');
        }

        if ((float)($product['stock_actual'] ?? 0) < $qty) {
            // Optional: Block sale if no stock, or just notify.
            // For now, let's allow negative stock as some businesses operate this way, 
            // but log a warning if needed.
        }

        $this->repository->recordMovement([
            'tenant_id' => $tenantId,
            'producto_id' => $product['id'],
            'tipo' => 'SALIDA',
            'cantidad' => $qty,
            'referencia_externa' => $ref,
            'usuario_id' => $userId
        ]);

        return $this->repository->findProduct($tenantId, $productId) ?? [];
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
