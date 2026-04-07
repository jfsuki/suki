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

        // Simulación de serie temporal para gráficos (últimos 7 días)
        $chartData = [
            'labels' => ['Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom'],
            'sales' => [rand(100,500), rand(200,600), rand(150,450), rand(300,700), rand(400,800), rand(500,900), rand(600,1000)],
            'expenses' => [rand(50,200), rand(100,300), rand(80,250), rand(150,400), rand(200,500), rand(250,600), rand(300,700)]
        ];

        return [
            'summary' => [
                'total_sales' => $totalSales,
                'total_purchases' => $totalPurchases,
                'active_quotes' => $activeQuotes,
                'balance' => $totalSales - $totalPurchases
            ],
            'charts' => $chartData
        ];
    }
}
