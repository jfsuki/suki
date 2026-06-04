<?php

declare(strict_types=1);

// framework/app/Core/FinancialStatementsService.php

namespace App\Core;

use PDO;

/**
 * Real financial statements built from journal_entries + puc_nacional.
 * Returns structured data or a safe empty structure — never throws on missing tables.
 */
final class FinancialStatementsService
{
    private PDO    $db;
    private string $tenantId;

    public function __construct(Database $database, string $tenantId)
    {
        $this->db       = $database->getPdo();
        $this->tenantId = $tenantId;
    }

    // -------------------------------------------------------------------------
    // P&L
    // -------------------------------------------------------------------------

    /**
     * @return array{revenues: list<array{account:string,name:string,amount:float}>,
     *               expenses: list<array{account:string,name:string,amount:float}>,
     *               net_income: float, period: array{start:string,end:string}}
     *         | array{error: string, data: null}
     */
    public function getProfitAndLoss(string $periodStart, string $periodEnd): array
    {
        if (!$this->tablesExist()) {
            return ['error' => 'Tablas contables no disponibles', 'data' => null];
        }

        $revenues = $this->sumByAccountClass(['4'], $periodStart, $periodEnd);
        $expenses = $this->sumByAccountClass(['5'], $periodStart, $periodEnd);

        $totalRevenue = array_sum(array_column($revenues, 'amount'));
        $totalExpense = array_sum(array_column($expenses, 'amount'));

        if (empty($revenues) && empty($expenses)) {
            return [
                'revenues'   => [],
                'expenses'   => [],
                'net_income' => 0.0,
                'period'     => ['start' => $periodStart, 'end' => $periodEnd],
                'note'       => 'Sin movimientos en el período',
            ];
        }

        return [
            'revenues'   => $revenues,
            'expenses'   => $expenses,
            'net_income' => round($totalRevenue - $totalExpense, 2),
            'period'     => ['start' => $periodStart, 'end' => $periodEnd],
        ];
    }

    // -------------------------------------------------------------------------
    // Balance Sheet
    // -------------------------------------------------------------------------

    /**
     * @return array{assets: list<array{account:string,name:string,amount:float}>,
     *               liabilities: list<array{account:string,name:string,amount:float}>,
     *               equity: list<array{account:string,name:string,amount:float}>,
     *               balanced: bool, discrepancy: float}
     *         | array{error: string, data: null}
     */
    public function getBalanceSheet(string $asOfDate): array
    {
        if (!$this->tablesExist()) {
            return ['error' => 'Tablas contables no disponibles', 'data' => null];
        }

        $assets      = $this->sumByAccountClassUpTo(['1', '2'], $asOfDate, 'debit_net');
        $liabilities = $this->sumByAccountClassUpTo(['2', '3'], $asOfDate, 'credit_net');
        $equity      = $this->sumByAccountClassUpTo(['3'], $asOfDate, 'credit_net');

        $totalAssets      = array_sum(array_column($assets, 'amount'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount'));
        $totalEquity      = array_sum(array_column($equity, 'amount'));

        $discrepancy = round(abs($totalAssets - ($totalLiabilities + $totalEquity)), 2);

        return [
            'assets'      => $assets,
            'liabilities' => $liabilities,
            'equity'      => $equity,
            'balanced'    => $discrepancy <= 0.01,
            'discrepancy' => $discrepancy,
        ];
    }

    // -------------------------------------------------------------------------
    // Cash Flow
    // -------------------------------------------------------------------------

    /**
     * @return array{inflows: list<array{account:string,name:string,amount:float}>,
     *               outflows: list<array{account:string,name:string,amount:float}>,
     *               net_flow: float}
     *         | array{error: string, data: null}
     */
    public function getCashFlow(string $periodStart, string $periodEnd): array
    {
        if (!$this->tablesExist()) {
            return ['error' => 'Tablas contables no disponibles', 'data' => null];
        }

        $inflows  = $this->sumCashMovements('debit',  $periodStart, $periodEnd);
        $outflows = $this->sumCashMovements('credit', $periodStart, $periodEnd);

        $netFlow = round(
            array_sum(array_column($inflows, 'amount')) - array_sum(array_column($outflows, 'amount')),
            2
        );

        return [
            'inflows'  => $inflows,
            'outflows' => $outflows,
            'net_flow' => $netFlow,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function tablesExist(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM journal_entries LIMIT 0');
            $this->db->query('SELECT 1 FROM puc_nacional LIMIT 0');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SUM debit/credit per account for given classes within a period.
     * Joins with puc_nacional for human-readable account names.
     *
     * @param list<string>  $classes  PUC class digits, e.g. ['4'] for income
     * @return list<array{account:string,name:string,amount:float}>
     */
    private function sumByAccountClass(array $classes, string $start, string $end): array
    {
        $placeholders = implode(',', array_fill(0, count($classes), '?'));
        $params = array_merge(
            [$this->tenantId, $start, $end],
            $classes
        );

        // For income (class 4): credits exceed debits normally → net = credit - debit
        // For expense (class 5): debits exceed credits normally → net = debit - credit
        $sql = "
            SELECT
                je.account_code                  AS account,
                COALESCE(p.name, je.account_code) AS name,
                ROUND(SUM(je.credit) - SUM(je.debit), 2) AS amount
            FROM journal_entries je
            LEFT JOIN puc_nacional p ON p.code = je.account_code
            WHERE je.tenant_id = ?
              AND je.entry_date BETWEEN ? AND ?
              AND LEFT(je.account_code, 1) IN ({$placeholders})
            GROUP BY je.account_code, p.name
            HAVING amount <> 0
            ORDER BY je.account_code
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(fn(array $r) => [
            'account' => (string) $r['account'],
            'name'    => (string) $r['name'],
            'amount'  => (float)  abs((float) $r['amount']),
        ], $rows));
    }

    /**
     * Balance-sheet variant: accumulate all entries up to $asOfDate.
     *
     * @param list<string> $classes
     * @param 'debit_net'|'credit_net' $netSide
     * @return list<array{account:string,name:string,amount:float}>
     */
    private function sumByAccountClassUpTo(array $classes, string $asOfDate, string $netSide): array
    {
        $placeholders = implode(',', array_fill(0, count($classes), '?'));
        $params = array_merge([$this->tenantId, $asOfDate], $classes);

        $amountExpr = $netSide === 'debit_net'
            ? 'ROUND(SUM(je.debit) - SUM(je.credit), 2)'
            : 'ROUND(SUM(je.credit) - SUM(je.debit), 2)';

        $sql = "
            SELECT
                je.account_code                  AS account,
                COALESCE(p.name, je.account_code) AS name,
                {$amountExpr}                     AS amount
            FROM journal_entries je
            LEFT JOIN puc_nacional p ON p.code = je.account_code
            WHERE je.tenant_id = ?
              AND je.entry_date <= ?
              AND LEFT(je.account_code, 1) IN ({$placeholders})
            GROUP BY je.account_code, p.name
            HAVING amount > 0
            ORDER BY je.account_code
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(fn(array $r) => [
            'account' => (string) $r['account'],
            'name'    => (string) $r['name'],
            'amount'  => (float)  $r['amount'],
        ], $rows));
    }

    /**
     * Cash-flow: account class 1 (bank/cash accounts), one side per call.
     *
     * @param 'debit'|'credit' $side
     * @return list<array{account:string,name:string,amount:float}>
     */
    private function sumCashMovements(string $side, string $start, string $end): array
    {
        $sql = "
            SELECT
                je.account_code                  AS account,
                COALESCE(p.name, je.account_code) AS name,
                ROUND(SUM(je.{$side}), 2)         AS amount
            FROM journal_entries je
            LEFT JOIN puc_nacional p ON p.code = je.account_code
            WHERE je.tenant_id = ?
              AND je.entry_date BETWEEN ? AND ?
              AND LEFT(je.account_code, 1) = '1'
              AND je.{$side} > 0
            GROUP BY je.account_code, p.name
            HAVING amount > 0
            ORDER BY je.account_code
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->tenantId, $start, $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(fn(array $r) => [
            'account' => (string) $r['account'],
            'name'    => (string) $r['name'],
            'amount'  => (float)  $r['amount'],
        ], $rows));
    }
}
