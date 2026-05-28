<?php
// framework/app/Core/DashboardService.php

namespace App\Core;

use App\Core\POSRepository;
use App\Core\PurchasesRepository;
use App\Core\QuotationRepository;

/**
 * DashboardService
 * Agrega métricas de negocio para visualización gráfica.
 */
class DashboardService
{
    private POSRepository $posRepo;
    private PurchasesRepository $purchasesRepo;
    private QuotationRepository $quoteRepo;

    public function __construct(
        ?POSRepository $posRepo = null,
        ?PurchasesRepository $purchasesRepo = null,
        ?QuotationRepository $quoteRepo = null
    ) {
        $this->posRepo = $posRepo ?? new POSRepository();
        $this->purchasesRepo = $purchasesRepo ?? new PurchasesRepository();
        $this->quoteRepo = $quoteRepo ?? new QuotationRepository();
    }

    /**
     * Obtiene las métricas principales del tablero.
     */
    public function getMetrics(string $tenantId): array
    {
        $sales = $this->posRepo->listSales($tenantId, [], 100);
        $purchases = $this->purchasesRepo->listPurchases($tenantId, [], 100);
        $quotes = $this->quoteRepo->listQuotations($tenantId, [], 100);

        $totalSales = 0;
        $totalPurchases = 0;
        $activeQuotes = 0;

        foreach ($sales as $s) {
            $totalSales += (float)($s['total'] ?? 0);
        }

        foreach ($purchases as $p) {
            $totalPurchases += (float)($p['total'] ?? 0);
        }

        foreach ($quotes as $q) {
            if ($q['status'] === 'sent' || $q['status'] === 'approved') {
                $activeQuotes++;
            }
        }

        // Serie temporal real — últimos 7 días agrupados por fecha
        $dayNames  = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
        $last7     = [];
        $labels    = [];
        for ($i = 6; $i >= 0; $i--) {
            $ts        = strtotime("-{$i} days");
            $date      = date('Y-m-d', $ts);
            $last7[]   = $date;
            $labels[]  = $dayNames[(int) date('w', $ts)];
        }
        $salesByDay    = array_fill_keys($last7, 0.0);
        $expensesByDay = array_fill_keys($last7, 0.0);
        foreach ($sales as $s) {
            $d = substr((string) ($s['created_at'] ?? ''), 0, 10);
            if (isset($salesByDay[$d])) { $salesByDay[$d] += (float) ($s['total'] ?? 0); }
        }
        foreach ($purchases as $p) {
            $d = substr((string) ($p['created_at'] ?? ''), 0, 10);
            if (isset($expensesByDay[$d])) { $expensesByDay[$d] += (float) ($p['total'] ?? 0); }
        }
        $chartData = [
            'labels'   => $labels,
            'sales'    => array_values($salesByDay),
            'expenses' => array_values($expensesByDay),
        ];

        $queryLimit = 100;
        return [
            'summary' => [
                'total_sales' => $totalSales,
                'total_purchases' => $totalPurchases,
                'active_quotes' => $activeQuotes,
                'balance' => $totalSales - $totalPurchases,
                'records_loaded' => [
                    'sales' => count($sales),
                    'purchases' => count($purchases),
                    'quotes' => count($quotes),
                ],
                'query_limit' => $queryLimit,
                'truncated' => count($sales) >= $queryLimit || count($purchases) >= $queryLimit || count($quotes) >= $queryLimit,
            ],
            'charts' => $chartData
        ];
    }
}
