<?php
// framework/app/Core/AgentCoordinationService.php

declare(strict_types=1);

namespace App\Core;

use App\Core\Skills\InventorySkill;
use App\Core\Skills\AccountingSkill;
use App\Core\InternalEventBus;

/**
 * Servicio encargado de la coordinacion entre agentes ERP.
 * Define los vinculos automaticos entre dominios (Event-Driven).
 */
final class AgentCoordinationService
{
    private static bool $booted = false;

    /**
     * Inicializa las suscripciones de eventos.
     * Debe llamarse al inicio de la ejecucion del ChatAgent o gateway.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        $bus = InternalEventBus::getInstance();

        // 1. Cuando se finaliza una venta en el POS
        $bus->subscribe('sale.finalized', function(array $payload) {
            self::handleSaleFinalized($payload);
        });

        self::$booted = true;
    }

    /**
     * Coordina el impacto de una venta finalizada.
     */
    private static function handleSaleFinalized(array $payload): void
    {
        $tenantId = $payload['tenant_id'] ?? 'default';
        $sale = $payload['sale'] ?? [];
        $userId = $payload['user_id'] ?? 'system_event_bus';

        if (empty($sale)) {
            return;
        }

        $registry = new ProjectRegistry();
        $registry->logAgentEvent('EVENT_BUS', $tenantId, 'sale.finalized', "Iniciando coordinacion para venta #{$sale['sale_number']}", 'INFO');

        // A. REBAJAR INVENTARIO
        try {
            $inventory = new InventorySkill();
            $lines = (array) ($sale['lines'] ?? []);
            foreach ($lines as $line) {
                if (empty($line['product_id'])) continue;
                
                $inventory->handle([
                    'action' => 'register_sale',
                    'product_id' => $line['product_id'],
                    'qty' => (float) ($line['qty'] ?? 0),
                    'ref' => "POS-#{$sale['sale_number']}"
                ], ['tenant_id' => $tenantId, 'user_id' => $userId]);
            }
            $registry->logAgentEvent('Agent_Inventario', $tenantId, 'stock_adjusted', "Stock rebajado para venta #{$sale['sale_number']}", 'SUCCESS');
        } catch (\Throwable $e) {
            $registry->logAgentEvent('Agent_Inventario', $tenantId, 'error', "Error rebajando stock: " . $e->getMessage(), 'ERROR');
            error_log("Coordination Error (Inventory): " . $e->getMessage());
        }

        // B. REGISTRAR CONTABILIDAD
        try {
            $accounting = new AccountingSkill();
            $accounting->handle([
                'action' => 'record_sale_accounting',
                'total' => (float) ($sale['total'] ?? 0),
                'ref' => "POS-" . ($sale['sale_number'] ?? $sale['id'])
            ], ['tenant_id' => $tenantId, 'user_id' => $userId]);
            $registry->logAgentEvent('Agent_Finanzas', $tenantId, 'accounting_recorded', "Asiento contable registrado para venta #{$sale['sale_number']}", 'SUCCESS');
        } catch (\Throwable $e) {
            $registry->logAgentEvent('Agent_Finanzas', $tenantId, 'error', "Error registrando contabilidad: " . $e->getMessage(), 'ERROR');
            error_log("Coordination Error (Accounting): " . $e->getMessage());
        }
    }
}
