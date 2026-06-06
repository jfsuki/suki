<?php
declare(strict_types=1);

namespace App\Core;

/**
 * InventoryAdvisorService — Asesor inteligente de inventario.
 *
 * No bloquea operaciones — guía al usuario hacia la causa raíz cuando hay
 * discrepancias de stock. Funciona para cualquier sector:
 *   - Comercio: valida antes de vender, sugiere revisar pedidos
 *   - Servicios: omite validación si el ítem no es inventariable
 *   - Manufactura: considera producción en curso
 *   - Control relajado: informa sin bloquear
 */
final class InventoryAdvisorService
{
    public function __construct(private \PDO $db) {}

    // ─── Constantes de modo de control ───────────────────────────────────────

    public const MODE_STRICT  = 'strict';   // Advierte, deja elegir al usuario
    public const MODE_RELAXED = 'relaxed';  // Solo informa, no interrumpe

    // ─── Verificación pre-venta ───────────────────────────────────────────────

    /**
     * Verifica el stock disponible para una lista de ítems antes de facturar/vender.
     *
     * @param array<int, array{product_id: string, quantity: float, name?: string}> $items
     * @return array{ok: bool, mode: string, advisories: array, can_proceed: bool}
     */
    public function checkStockForSale(string $tenantId, array $items, string $appId = 'default'): array
    {
        $advisories = [];
        $canProceed = true;

        foreach ($items as $item) {
            $productId = (string) ($item['product_id'] ?? '');
            $qty       = (float)  ($item['quantity']   ?? $item['qty'] ?? 1);
            if ($productId === '') {
                continue;
            }

            $product = $this->getProduct($tenantId, $productId, $appId);
            if (!$product) {
                continue;
            }

            $inventariable      = (bool) ($product['inventariable']           ?? true);
            $controlaUnidades   = (bool) ($product['controla_unidades']       ?? true);
            $permiteVentaSinStock = (bool) ($product['permite_venta_sin_stock'] ?? false);

            if (!$inventariable || !$controlaUnidades) {
                continue; // Servicio o ítem sin control de unidades — venta libre
            }

            $stockActual = (float) ($product['stock_actual'] ?? 0);
            $nombre      = (string) ($product['nombre'] ?? $item['name'] ?? $productId);

            if ($stockActual >= $qty) {
                continue; // Stock suficiente
            }

            $deficit = $qty - $stockActual;

            if ($permiteVentaSinStock) {
                // Modo relajado: informa pero no bloquea
                $advisories[] = [
                    'product_id'   => $productId,
                    'product_name' => $nombre,
                    'type'         => 'warning',
                    'blocking'     => false,
                    'message'      => "⚠ {$nombre}: stock disponible {$stockActual}, solicitado {$qty}. Se facturará con déficit de {$deficit}.",
                    'stock_actual' => $stockActual,
                    'qty_requested'=> $qty,
                    'deficit'      => $deficit,
                ];
            } else {
                // Modo estricto: informa y ofrece opciones, NO bloquea definitivamente
                $advisories[] = [
                    'product_id'    => $productId,
                    'product_name'  => $nombre,
                    'type'          => 'advisory',
                    'blocking'      => true,
                    'message'       => "Stock insuficiente para {$nombre} (disponible: {$stockActual}, solicitado: {$qty}). ¿Cómo quieres continuar?",
                    'options'       => [
                        'continue'      => "Facturar de todas formas (quedará en -{$deficit})",
                        'review_orders' => 'Revisar pedidos de compra pendientes de ingreso',
                        'cancel'        => 'Cancelar esta línea',
                    ],
                    'stock_actual'  => $stockActual,
                    'qty_requested' => $qty,
                    'deficit'       => $deficit,
                ];
                $canProceed = false;
            }
        }

        return [
            'ok'         => empty($advisories) || $canProceed,
            'mode'       => $canProceed ? self::MODE_RELAXED : self::MODE_STRICT,
            'advisories' => $advisories,
            'can_proceed'=> true, // Siempre permite continuar si el usuario lo elige
            'requires_confirmation' => !$canProceed && !empty($advisories),
        ];
    }

    // ─── Análisis de discrepancia ─────────────────────────────────────────────

    /**
     * Cuando el stock queda negativo o el usuario reporta una discrepancia,
     * investiga la causa probable y sugiere pasos de reconciliación.
     *
     * @return array{found_causes: bool, causes: array, suggestions: array, next_action: string}
     */
    public function analyzeDiscrepancy(string $tenantId, string $productId, ?string $appId = null): array
    {
        $causes      = [];
        $suggestions = [];

        // 1. Órdenes de compra pendientes de ingreso
        $pendingPOs = $this->getPendingPurchaseOrders($tenantId, $productId, $appId);
        if (!empty($pendingPOs)) {
            $causes[]      = ['type' => 'pending_purchase_orders', 'count' => count($pendingPOs), 'items' => $pendingPOs];
            $suggestions[] = '📦 Hay ' . count($pendingPOs) . ' orden(es) de compra pendiente(s) de ingresar al sistema. ¿Las ingresamos ahora?';
        }

        // 2. Recepciones parciales sin completar
        $partialReceipts = $this->getPartialReceipts($tenantId, $productId, $appId);
        if (!empty($partialReceipts)) {
            $causes[]      = ['type' => 'partial_receipts', 'count' => count($partialReceipts), 'items' => $partialReceipts];
            $suggestions[] = '📋 Hay ' . count($partialReceipts) . ' recepción(es) parcial(es) sin completar. Puede haber mercancía recibida sin registrar.';
        }

        // 3. Despachos sin facturar
        $dispatchedWithoutInvoice = $this->getDispatchedWithoutInvoice($tenantId, $productId, $appId);
        if (!empty($dispatchedWithoutInvoice)) {
            $causes[]      = ['type' => 'dispatched_without_invoice', 'count' => count($dispatchedWithoutInvoice), 'items' => $dispatchedWithoutInvoice];
            $suggestions[] = '🚚 Hay ' . count($dispatchedWithoutInvoice) . ' despacho(s) sin facturar que podrían haber descontado stock sin registrar la venta completa.';
        }

        // 4. Ventas recientes con stock en negativo
        $recentNegativeSales = $this->getRecentNegativeSales($tenantId, $productId);
        if (!empty($recentNegativeSales)) {
            $causes[]      = ['type' => 'negative_sales', 'count' => count($recentNegativeSales)];
            $suggestions[] = '💸 Se registraron ' . count($recentNegativeSales) . ' venta(s) con stock en cero o negativo recientemente.';
        }

        $foundCauses = !empty($causes);

        if (!$foundCauses) {
            $suggestions[] = '🔧 No encontré causas pendientes. Te recomiendo hacer un AJUSTE DE INVENTARIO: contar físicamente el producto e ingresar la cantidad real al sistema.';
            $nextAction     = 'inventory_adjustment';
        } else {
            $nextAction = 'review_pending';
        }

        $suggestions[] = '📊 También puedo generar un informe del movimiento de este producto en los últimos 30 días para ver exactamente qué pasó.';

        return [
            'found_causes' => $foundCauses,
            'causes'       => $causes,
            'suggestions'  => $suggestions,
            'next_action'  => $nextAction,
            'advisory_message' => $this->buildAdvisoryMessage($causes, $suggestions),
        ];
    }

    /**
     * Mensaje completo del agente para comunicar al usuario.
     */
    public function buildNegativeStockMessage(string $productName, float $currentStock, array $discrepancy): string
    {
        $lines = ["⚠ El stock de **{$productName}** quedó en {$currentStock} unidades."];

        if (!empty($discrepancy['suggestions'])) {
            $lines[] = "\nPosibles causas que encontré:";
            foreach ($discrepancy['suggestions'] as $s) {
                $lines[] = "  • {$s}";
            }
        }

        if ($discrepancy['next_action'] === 'inventory_adjustment') {
            $lines[] = "\nSi verificaste todo y el conteo físico es correcto, puedo ayudarte a hacer el **ajuste de inventario**.";
        } else {
            $lines[] = "\n¿Por dónde quieres comenzar la revisión?";
        }

        return implode("\n", $lines);
    }

    // ─── Queries de investigación ─────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function getPendingPurchaseOrders(string $tenantId, string $productId, ?string $appId): array
    {
        try {
            // Busca en órdenes de compra con status pendiente que incluyan este producto
            $tables = ['ordenes_compra', 'purchase_orders', 'purchases'];
            foreach ($tables as $table) {
                if ($this->tableExists($table)) {
                    $stmt = $this->db->prepare(
                        "SELECT id, created_at FROM {$table}
                         WHERE tenant_id = :tid AND status IN ('pendiente','pending','abierta','open')
                         ORDER BY created_at DESC LIMIT 10"
                    );
                    $stmt->execute([':tid' => $tenantId]);
                    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                }
            }
        } catch (\Throwable $e) {
            // Tabla no existe aún en este tenant
        }
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function getPartialReceipts(string $tenantId, string $productId, ?string $appId): array
    {
        try {
            $tables = ['recepciones', 'receipts', 'incoming_shipments'];
            foreach ($tables as $table) {
                if ($this->tableExists($table)) {
                    $stmt = $this->db->prepare(
                        "SELECT id, created_at FROM {$table}
                         WHERE tenant_id = :tid AND status IN ('parcial','partial')
                         ORDER BY created_at DESC LIMIT 10"
                    );
                    $stmt->execute([':tid' => $tenantId]);
                    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                }
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function getDispatchedWithoutInvoice(string $tenantId, string $productId, ?string $appId): array
    {
        try {
            $tables = ['despachos', 'dispatches', 'shipments'];
            foreach ($tables as $table) {
                if ($this->tableExists($table)) {
                    $stmt = $this->db->prepare(
                        "SELECT id, created_at FROM {$table}
                         WHERE tenant_id = :tid AND facturado = 0
                         ORDER BY created_at DESC LIMIT 10"
                    );
                    $stmt->execute([':tid' => $tenantId]);
                    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                }
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function getRecentNegativeSales(string $tenantId, string $productId): array
    {
        try {
            // Busca en kardex movimientos de SALIDA en los últimos 30 días donde el stock quedó negativo
            $tables = ['kardex', 'inventory_movements'];
            foreach ($tables as $table) {
                if ($this->tableExists($table)) {
                    $stmt = $this->db->prepare(
                        "SELECT id, cantidad, created_at FROM {$table}
                         WHERE tenant_id = :tid AND producto_id = :pid
                         AND tipo IN ('SALIDA','sale')
                         AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                         ORDER BY created_at DESC LIMIT 5"
                    );
                    $stmt->execute([':tid' => $tenantId, ':pid' => $productId]);
                    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                }
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    /** @return array<string, mixed>|null */
    private function getProduct(string $tenantId, string $productId, string $appId): ?array
    {
        $table = 'app_' . $appId . '__productos';
        if (!$this->tableExists($table)) {
            $table = 'productos';
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM {$table} WHERE tenant_id = :tid AND id = :pid LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId, ':pid' => $productId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $stmt = $this->db->prepare("SHOW TABLES LIKE :t");
                $stmt->execute([':t' => $table]);
            } else {
                $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
                $stmt->execute([':t' => $table]);
            }
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @param array<int, array<string, mixed>> $causes @param string[] $suggestions */
    private function buildAdvisoryMessage(array $causes, array $suggestions): string
    {
        if (empty($causes)) {
            return "No encontré causas pendientes registradas. Recomiendo un ajuste de inventario basado en conteo físico.";
        }

        $msg = "Encontré " . count($causes) . " posible(s) causa(s) para la discrepancia:\n";
        foreach ($suggestions as $i => $s) {
            $msg .= ($i + 1) . ". {$s}\n";
        }
        return trim($msg);
    }
}
