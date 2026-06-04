<?php
// framework/app/Core/Skills/AccountingSkill.php

declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\AccountingService;
use App\Core\AccountingRepository;
use App\Core\Database;
use App\Core\FinancialStatementsService;
use RuntimeException;

final class AccountingSkill
{
    private AccountingService $service;

    public function __construct(?AccountingService $service = null)
    {
        $this->service = $service ?? new AccountingService();
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private const SKILL_ACTION_MAP = [
        'accounting_post'                 => 'record_entry',
        'accounting_record_entry'         => 'record_entry',
        'accounting_balance_sheet'        => 'balance_sheet',
        'accounting_record_sale'          => 'record_sale_accounting',
        'accounting_profit_loss'          => 'profit_loss',
        'accounting_cash_flow'            => 'cash_flow',
        'accounting_financial_statements' => 'balance_sheet',
    ];

    /**
     * Entry point when DynamicSkillRegistry routes via "handler@execute" — translates
     * skill name to action so each catalog entry routes to the correct method.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function execute(string $skillName, array $input, array $context = []): array
    {
        $mapped = self::SKILL_ACTION_MAP[$skillName] ?? null;
        if ($mapped !== null) {
            $input['action'] = $mapped;
        }
        return $this->handle($input, $context);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handle(array $input, array $context = []): array
    {
        $action   = trim((string) ($input['action'] ?? 'balance_sheet'));
        $tenantId = (string) ($context['tenant_id'] ?? 'default');
        $userId   = (string) ($context['user_id'] ?? 'system_ai');

        return match ($action) {
            'record_entry'           => $this->service->recordManualEntry($tenantId, (array) ($input['data'] ?? []), $userId),
            'balance_sheet'          => $this->handleBalanceSheet($tenantId, $input),
            'list_accounts'          => $this->service->getBalanceSheet($tenantId),
            'record_sale_accounting' => $this->service->recordSaleAccounting(
                $tenantId,
                (float) ($input['total'] ?? 0),
                (string) ($input['ref'] ?? 'SALE'),
                $userId
            ),
            // Financial statements — real P&L, Balance Sheet, Cash Flow
            'profit_loss'  => $this->handleProfitLoss($tenantId, $input),
            'cash_flow'    => $this->handleCashFlow($tenantId, $input),
            default        => throw new RuntimeException("Unknown accounting action: {$action}"),
        };
    }

    // -------------------------------------------------------------------------
    // Financial statements handlers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $input */
    private function handleBalanceSheet(string $tenantId, array $input): array
    {
        $asOf = (string) ($input['as_of_date'] ?? date('Y-m-d'));

        try {
            $svc    = new FinancialStatementsService(new Database(), $tenantId);
            $result = $svc->getBalanceSheet($asOf);

            if (isset($result['error'])) {
                // Fallback to legacy service when tables not available yet
                return $this->service->getBalanceSheet($tenantId);
            }

            return $this->formatBalanceSheet($result, $asOf);
        } catch (\Throwable $e) {
            return $this->service->getBalanceSheet($tenantId);
        }
    }

    /** @param array<string, mixed> $input */
    private function handleProfitLoss(string $tenantId, array $input): array
    {
        $start = (string) ($input['period_start'] ?? date('Y-m-01'));
        $end   = (string) ($input['period_end']   ?? date('Y-m-d'));

        try {
            $svc    = new FinancialStatementsService(new Database(), $tenantId);
            $result = $svc->getProfitAndLoss($start, $end);

            if (isset($result['error'])) {
                return ['reply' => 'No hay datos contables disponibles para el período.', 'data' => null];
            }

            return $this->formatProfitLoss($result);
        } catch (\Throwable $e) {
            return ['reply' => 'Error al generar P&G: ' . $e->getMessage(), 'data' => null];
        }
    }

    /** @param array<string, mixed> $input */
    private function handleCashFlow(string $tenantId, array $input): array
    {
        $start = (string) ($input['period_start'] ?? date('Y-m-01'));
        $end   = (string) ($input['period_end']   ?? date('Y-m-d'));

        try {
            $svc    = new FinancialStatementsService(new Database(), $tenantId);
            $result = $svc->getCashFlow($start, $end);

            if (isset($result['error'])) {
                return ['reply' => 'No hay datos contables disponibles para el período.', 'data' => null];
            }

            return $this->formatCashFlow($result);
        } catch (\Throwable $e) {
            return ['reply' => 'Error al generar flujo de caja: ' . $e->getMessage(), 'data' => null];
        }
    }

    // -------------------------------------------------------------------------
    // Formatters — return chat-friendly text in 'reply' key
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function formatBalanceSheet(array $data, string $asOf): array
    {
        $lines   = ["**Balance General al {$asOf}**\n"];
        $lines[] = "**Activos**";
        foreach ((array) ($data['assets'] ?? []) as $row) {
            $lines[] = "  {$row['account']} {$row['name']}: $" . number_format((float) $row['amount'], 2, ',', '.');
        }
        $lines[] = "\n**Pasivos**";
        foreach ((array) ($data['liabilities'] ?? []) as $row) {
            $lines[] = "  {$row['account']} {$row['name']}: $" . number_format((float) $row['amount'], 2, ',', '.');
        }
        $lines[] = "\n**Patrimonio**";
        foreach ((array) ($data['equity'] ?? []) as $row) {
            $lines[] = "  {$row['account']} {$row['name']}: $" . number_format((float) $row['amount'], 2, ',', '.');
        }
        $balanced = ($data['balanced'] ?? false) ? 'Ecuación contable: BALANCEADO' : 'Ecuación contable: DESCUADRADO (diferencia: $' . number_format((float) ($data['discrepancy'] ?? 0), 2) . ')';
        $lines[]  = "\n_{$balanced}_";

        return ['reply' => implode("\n", $lines), 'data' => $data];
    }

    /** @param array<string, mixed> $data */
    private function formatProfitLoss(array $data): array
    {
        $p = $data['period'] ?? [];
        $lines   = ["**Estado de Resultados ({$p['start']} — {$p['end']})**\n"];
        $lines[] = "**Ingresos**";
        foreach ((array) ($data['revenues'] ?? []) as $row) {
            $lines[] = "  {$row['account']} {$row['name']}: $" . number_format((float) $row['amount'], 2, ',', '.');
        }
        $lines[] = "\n**Gastos**";
        foreach ((array) ($data['expenses'] ?? []) as $row) {
            $lines[] = "  {$row['account']} {$row['name']}: $" . number_format((float) $row['amount'], 2, ',', '.');
        }
        $net     = (float) ($data['net_income'] ?? 0);
        $sign    = $net >= 0 ? 'Utilidad neta' : 'Pérdida neta';
        $lines[] = "\n**{$sign}: $" . number_format(abs($net), 2, ',', '.') . "**";

        if (!empty($data['note'])) {
            $lines[] = "\n_{$data['note']}_";
        }

        return ['reply' => implode("\n", $lines), 'data' => $data];
    }

    /** @param array<string, mixed> $data */
    private function formatCashFlow(array $data): array
    {
        $lines   = ["**Flujo de Caja**\n"];
        $lines[] = "**Entradas**";
        foreach ((array) ($data['inflows'] ?? []) as $row) {
            $lines[] = "  {$row['account']} {$row['name']}: $" . number_format((float) $row['amount'], 2, ',', '.');
        }
        $lines[] = "\n**Salidas**";
        foreach ((array) ($data['outflows'] ?? []) as $row) {
            $lines[] = "  {$row['account']} {$row['name']}: $" . number_format((float) $row['amount'], 2, ',', '.');
        }
        $net     = (float) ($data['net_flow'] ?? 0);
        $sign    = $net >= 0 ? 'Flujo neto positivo' : 'Flujo neto negativo';
        $lines[] = "\n**{$sign}: $" . number_format(abs($net), 2, ',', '.') . "**";

        return ['reply' => implode("\n", $lines), 'data' => $data];
    }
}
