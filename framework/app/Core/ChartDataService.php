<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Servicio de datos para reportes y gráficas.
 * Ejecuta consultas SQL predefinidas por tipo de reporte.
 * El agente solo pasa tenant_id + tipo + rango de fechas → recibe datos listos para Chart.js.
 */
final class ChartDataService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
    }

    /**
     * Punto de entrada principal: retorna datos + meta para el reporte.
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getReportData(
        string $tenantId,
        string $reportType,
        string $desde,
        string $hasta,
        array $params = []
    ): array {
        $desde = $desde !== '' ? $desde : date('Y-m-01');
        $hasta = $hasta !== '' ? $hasta : date('Y-m-d');

        return match ($reportType) {
            'ventas_resumen'     => $this->ventasResumen($tenantId, $desde, $hasta),
            'ventas_por_dia'     => $this->ventasPorDia($tenantId, $desde, $hasta),
            'top_productos'      => $this->topProductos($tenantId, $desde, $hasta, (int) ($params['limit'] ?? 10)),
            'inventario_actual'  => $this->inventarioActual($tenantId),
            'cuentas_cobrar'     => $this->cuentasPorCobrar($tenantId),
            'compras_vs_ventas'  => $this->comprasVsVentas($tenantId, $desde, $hasta),
            'kpi_dashboard'      => $this->kpiDashboard($tenantId, $desde, $hasta),
            default              => $this->ventasResumen($tenantId, $desde, $hasta),
        };
    }

    // ─── Reportes ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function ventasResumen(string $tenantId, string $desde, string $hasta): array
    {
        $ns = $this->ns($tenantId);
        $pdo = $this->db->getPdo();

        $r = $this->safeQuery($pdo,
            "SELECT COUNT(*) as total_ventas, 
                    COALESCE(SUM(total), 0) as total_amount,
                    COALESCE(SUM(tax_total), 0) as total_iva,
                    COALESCE(AVG(total), 0) as ticket_promedio
             FROM {$ns}pos_sales
             WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = 'completed'",
            [$tenantId, $desde, $hasta]
        );

        $summary = $r[0] ?? [];

        // Ventas por semana para sparkline
        $byWeek = $this->safeQuery($pdo,
            "SELECT strftime('%W', created_at) as semana,
                    COUNT(*) as ventas,
                    COALESCE(SUM(total), 0) as monto
             FROM {$ns}pos_sales
             WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = 'completed'
             GROUP BY semana ORDER BY semana",
            [$tenantId, $desde, $hasta]
        );

        return [
            'report_type'    => 'ventas_resumen',
            'periodo'        => ['desde' => $desde, 'hasta' => $hasta],
            'summary'        => $summary,
            'chart_type'     => 'bar',
            'chart_title'    => 'Ventas por Semana',
            'labels'         => array_column($byWeek, 'semana'),
            'datasets'       => [
                ['label' => 'Monto ($)', 'data' => array_column($byWeek, 'monto'), 'key' => 'primary'],
                ['label' => 'Ventas (#)', 'data' => array_column($byWeek, 'ventas'), 'key' => 'secondary'],
            ],
            'table_columns'  => ['semana', 'ventas', 'monto'],
            'table_rows'     => $byWeek,
        ];
    }

    /** @return array<string, mixed> */
    private function ventasPorDia(string $tenantId, string $desde, string $hasta): array
    {
        $ns = $this->ns($tenantId);
        $pdo = $this->db->getPdo();

        $rows = $this->safeQuery($pdo,
            "SELECT DATE(created_at) as fecha,
                    COUNT(*) as ventas,
                    COALESCE(SUM(total), 0) as monto
             FROM {$ns}pos_sales
             WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = 'completed'
             GROUP BY fecha ORDER BY fecha",
            [$tenantId, $desde, $hasta]
        );

        return [
            'report_type'   => 'ventas_por_dia',
            'periodo'       => ['desde' => $desde, 'hasta' => $hasta],
            'chart_type'    => 'line',
            'chart_title'   => 'Ventas por Día',
            'labels'        => array_column($rows, 'fecha'),
            'datasets'      => [
                ['label' => 'Monto ($)', 'data' => array_column($rows, 'monto'), 'key' => 'primary'],
            ],
            'table_columns' => ['fecha', 'ventas', 'monto'],
            'table_rows'    => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function topProductos(string $tenantId, string $desde, string $hasta, int $limit): array
    {
        $ns = $this->db->getPdo();
        $nsStr = $this->ns($tenantId);
        $pdo = $this->db->getPdo();

        $rows = $this->safeQuery($pdo,
            "SELECT i.name as producto,
                    COUNT(li.id) as veces_vendido,
                    COALESCE(SUM(li.quantity), 0) as unidades,
                    COALESCE(SUM(li.line_total), 0) as total
             FROM {$nsStr}pos_sale_items li
             LEFT JOIN {$nsStr}pos_items i ON i.id = li.item_id
             WHERE li.tenant_id = ? 
             GROUP BY li.item_id, i.name
             ORDER BY total DESC
             LIMIT ?",
            [$tenantId, $limit]
        );

        return [
            'report_type'   => 'top_productos',
            'chart_type'    => 'bar',
            'chart_title'   => "Top {$limit} Productos más Vendidos",
            'labels'        => array_column($rows, 'producto'),
            'datasets'      => [
                ['label' => 'Total ($)', 'data' => array_column($rows, 'total'), 'key' => 'primary'],
            ],
            'table_columns' => ['producto', 'veces_vendido', 'unidades', 'total'],
            'table_rows'    => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function inventarioActual(string $tenantId): array
    {
        $ns = $this->ns($tenantId);
        $pdo = $this->db->getPdo();

        $rows = $this->safeQuery($pdo,
            "SELECT i.name as producto, i.sku,
                    COALESCE(k.stock_quantity, 0) as stock,
                    COALESCE(k.min_stock, 0) as minimo,
                    CASE WHEN COALESCE(k.stock_quantity,0) <= COALESCE(k.min_stock,0) THEN 'BAJO' ELSE 'OK' END as estado
             FROM {$ns}pos_items i
             LEFT JOIN {$ns}inventory_kardex k ON k.item_id = i.id AND k.tenant_id = i.tenant_id
             WHERE i.tenant_id = ?
             ORDER BY estado DESC, stock ASC
             LIMIT 100",
            [$tenantId]
        );

        $bajo   = array_filter($rows, static fn($r) => ($r['estado'] ?? '') === 'BAJO');
        $alertas = count($bajo);

        return [
            'report_type'   => 'inventario_actual',
            'chart_type'    => 'doughnut',
            'chart_title'   => 'Estado de Inventario',
            'labels'        => ['Stock OK', 'Stock Bajo'],
            'datasets'      => [
                ['label' => 'Productos', 'data' => [count($rows) - $alertas, $alertas], 'key' => 'primary'],
            ],
            'summary'       => ['total_productos' => count($rows), 'alertas_stock_bajo' => $alertas],
            'table_columns' => ['producto', 'sku', 'stock', 'minimo', 'estado'],
            'table_rows'    => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function cuentasPorCobrar(string $tenantId): array
    {
        // Facturas aceptadas sin fecha de pago registrada
        $ns = $this->ns($tenantId);
        $pdo = $this->db->getPdo();

        $rows = $this->safeQuery($pdo,
            "SELECT fd.document_number as factura,
                    fd.issue_date,
                    fd.total,
                    fd.status,
                    CAST(julianday('now') - julianday(fd.issue_date) AS INTEGER) as dias_vencido
             FROM {$ns}fiscal_documents fd
             WHERE fd.tenant_id = ? AND fd.status = 'accepted'
             ORDER BY dias_vencido DESC
             LIMIT 50",
            [$tenantId]
        );

        $grupos = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($rows as $r) {
            $d = (int) ($r['dias_vencido'] ?? 0);
            match (true) {
                $d <= 30 => $grupos['0-30']++,
                $d <= 60 => $grupos['31-60']++,
                $d <= 90 => $grupos['61-90']++,
                default  => $grupos['90+']++,
            };
        }

        return [
            'report_type'   => 'cuentas_cobrar',
            'chart_type'    => 'bar',
            'chart_title'   => 'Cuentas por Cobrar (Aging)',
            'labels'        => array_keys($grupos),
            'datasets'      => [
                ['label' => 'Facturas', 'data' => array_values($grupos), 'key' => 'primary'],
            ],
            'table_columns' => ['factura', 'issue_date', 'total', 'dias_vencido', 'status'],
            'table_rows'    => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function comprasVsVentas(string $tenantId, string $desde, string $hasta): array
    {
        $ns  = $this->ns($tenantId);
        $pdo = $this->db->getPdo();

        $ventas = $this->safeQuery($pdo,
            "SELECT strftime('%m', created_at) as mes, COALESCE(SUM(total), 0) as monto
             FROM {$ns}pos_sales WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status='completed'
             GROUP BY mes ORDER BY mes",
            [$tenantId, $desde, $hasta]
        );

        $compras = $this->safeQuery($pdo,
            "SELECT strftime('%m', created_at) as mes, COALESCE(SUM(total_amount), 0) as monto
             FROM {$ns}purchases WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY mes ORDER BY mes",
            [$tenantId, $desde, $hasta]
        );

        $meses = ['01','02','03','04','05','06','07','08','09','10','11','12'];
        $vMap  = array_column($ventas, 'monto', 'mes');
        $cMap  = array_column($compras, 'monto', 'mes');

        return [
            'report_type'   => 'compras_vs_ventas',
            'chart_type'    => 'bar',
            'chart_title'   => 'Compras vs Ventas',
            'labels'        => $meses,
            'datasets'      => [
                ['label' => 'Ventas ($)', 'data' => array_map(fn($m) => (float) ($vMap[$m] ?? 0), $meses), 'key' => 'primary'],
                ['label' => 'Compras ($)', 'data' => array_map(fn($m) => (float) ($cMap[$m] ?? 0), $meses), 'key' => 'secondary'],
            ],
            'table_columns' => ['mes', 'ventas', 'compras'],
            'table_rows'    => array_map(fn($m) => [
                'mes'     => $m,
                'ventas'  => $vMap[$m] ?? 0,
                'compras' => $cMap[$m] ?? 0,
            ], $meses),
        ];
    }

    /** @return array<string, mixed> */
    private function kpiDashboard(string $tenantId, string $desde, string $hasta): array
    {
        $ns  = $this->ns($tenantId);
        $pdo = $this->db->getPdo();

        $ventas = $this->safeQuery($pdo,
            "SELECT COUNT(*) as ventas, COALESCE(SUM(total),0) as monto
             FROM {$ns}pos_sales WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? AND status='completed'",
            [$tenantId, $desde, $hasta]
        );
        $compras = $this->safeQuery($pdo,
            "SELECT COUNT(*) as compras, COALESCE(SUM(total_amount),0) as monto
             FROM {$ns}purchases WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ?",
            [$tenantId, $desde, $hasta]
        );
        $clientes = $this->safeQuery($pdo,
            "SELECT COUNT(DISTINCT customer_id) as total FROM {$ns}pos_sales WHERE tenant_id=? AND DATE(created_at) BETWEEN ? AND ? AND status='completed'",
            [$tenantId, $desde, $hasta]
        );

        return [
            'report_type' => 'kpi_dashboard',
            'chart_type'  => 'kpi',
            'chart_title' => 'KPIs del Período',
            'periodo'     => ['desde' => $desde, 'hasta' => $hasta],
            'kpis' => [
                ['label' => 'Total Ventas',    'value' => $ventas[0]['monto'] ?? 0,   'format' => 'currency', 'icon' => '💰'],
                ['label' => 'Nro. Ventas',     'value' => $ventas[0]['ventas'] ?? 0,  'format' => 'number',   'icon' => '🛒'],
                ['label' => 'Total Compras',   'value' => $compras[0]['monto'] ?? 0,  'format' => 'currency', 'icon' => '📦'],
                ['label' => 'Clientes Únicos', 'value' => $clientes[0]['total'] ?? 0, 'format' => 'number',   'icon' => '👥'],
            ],
            'labels'        => [],
            'datasets'      => [],
            'table_columns' => [],
            'table_rows'    => [],
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function ns(string $tenantId): string
    {
        return TableNamespace::resolve($tenantId) . '__';
    }

    /**
     * @param array<mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function safeQuery(\PDO $pdo, string $sql, array $params): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (array) $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }
}
