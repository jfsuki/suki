<?php
// framework/app/Core/AccountingService.php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AccountingService
{
    private AccountingRepository $repository;

    public function __construct(?AccountingRepository $repository = null)
    {
        $this->repository = $repository ?? new AccountingRepository();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function recordManualEntry(string $tenantId, array $data, string $userId): array
    {
        if (empty($data['lines']) || count($data['lines']) < 2) {
            throw new RuntimeException('Journal entry must have at least 2 lines (double entry bookkeeping).');
        }

        $totalDebe = 0;
        $totalHaber = 0;
        foreach ($data['lines'] as $line) {
            $totalDebe += (float) ($line['debe'] ?? 0);
            $totalHaber += (float) ($line['haber'] ?? 0);
        }

        if (abs($totalDebe - $totalHaber) > 0.001) {
            throw new RuntimeException('Journal entry is not balanced (Debe vs Haber).');
        }

        $header = [
            'tenant_id' => $tenantId,
            'fecha' => $data['fecha'] ?? date('Y-m-d'),
            'referencia' => $data['referencia'] ?? '',
            'glosa' => $data['glosa'] ?? '',
            'total_debe' => $totalDebe,
            'total_haber' => $totalHaber,
            'estado' => 'CONTABILIZADO',
            'usuario_id' => $userId
        ];

        $id = $this->repository->createJournalEntry($header, $data['lines']);
        return ['id' => $id, 'status' => 'SUCCESS', 'total' => $totalDebe];
    }

    /**
     * Records a sale impact on accounting.
     */
    public function recordSaleAccounting(string $tenantId, float $total, string $ref, string $userId): array
    {
        // Simple Sale Example: 
        // Debit: Cash/Caja (1105) 
        // Credit: Sales/Ingresos (4135)
        
        $lines = [
            [
                'cuenta_id' => 1, // Assume 1 is 1105 (Caja) from seed
                'debe' => $total,
                'haber' => 0,
                'glosa_linea' => 'Venta Ref ' . $ref
            ],
            [
                'cuenta_id' => 2, // Assume 2 is 4135 (Ventas) from seed
                'debe' => 0,
                'haber' => $total,
                'glosa_linea' => 'Venta Ref ' . $ref
            ]
        ];

        return $this->recordManualEntry($tenantId, [
            'fecha' => date('Y-m-d'),
            'referencia' => $ref,
            'glosa' => 'Contabilización automática de venta',
            'lines' => $lines
        ], $userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBalanceSheet(string $tenantId): array
    {
        return $this->repository->listAccounts($tenantId);
    }
}
