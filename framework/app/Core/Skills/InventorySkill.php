<?php
// framework/app/Core/Skills/InventorySkill.php

declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\InventoryService;
use App\Core\InventoryRepository;
use RuntimeException;

final class InventorySkill
{
    private InventoryService $service;

    public function __construct(?InventoryService $service = null)
    {
        $this->service = $service ?? new InventoryService();
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handle(array $input, array $context = []): array
    {
        $action = trim((string) ($input['action'] ?? 'check_stock'));
        $tenantId = (string) ($context['tenant_id'] ?? 'default');
        $userId = (string) ($context['user_id'] ?? 'system_ai');

        return match ($action) {
            'check_stock' => $this->service->getProductStock($tenantId, (string) ($input['id_or_sku'] ?? '')),
            'adjust_stock' => $this->service->adjustStock(
                $tenantId, 
                (string) ($input['product_id'] ?? ''), 
                (float) ($input['qty'] ?? 0), 
                (string) ($input['reason'] ?? 'Manual adjustment by AI'),
                $userId
            ),
            'register_sale' => $this->service->registerSale(
                $tenantId,
                (string) ($input['product_id'] ?? ''),
                (float) ($input['qty'] ?? 0),
                (string) ($input['ref'] ?? ''),
                $userId
            ),
            'list_products' => $this->handleListProducts($tenantId, $input),
            'add_product' => $this->service->addProduct($tenantId, (array) ($input['data'] ?? [])),
            'low_stock_alerts' => $this->service->getLowStockAlerts($tenantId),
            default => throw new RuntimeException("Unknown inventory action: {$action}"),
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    private function handleListProducts(string $tenantId, array $input): array
    {
        $filters = [];
        if (!empty($input['sku'])) $filters['sku'] = $input['sku'];
        if (!empty($input['nombre'])) $filters['nombre'] = $input['nombre'];
        if (!empty($input['categoria'])) $filters['categoria'] = $input['categoria'];
        if (isset($input['low_stock'])) $filters['low_stock'] = (bool) $input['low_stock'];

        $repository = new InventoryRepository(); // Quick repo access for listing
        return $repository->listProducts($tenantId, $filters, (int) ($input['limit'] ?? 20));
    }
}
