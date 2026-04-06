<?php
// framework/app/Core/Skills/AccountingSkill.php

declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\AccountingService;
use App\Core\AccountingRepository;
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
    public function handle(array $input, array $context = []): array
    {
        $action = trim((string) ($input['action'] ?? 'balance_sheet'));
        $tenantId = (string) ($context['tenant_id'] ?? 'default');
        $userId = (string) ($context['user_id'] ?? 'system_ai');

        return match ($action) {
            'record_entry' => $this->service->recordManualEntry($tenantId, (array) ($input['data'] ?? []), $userId),
            'balance_sheet' => $this->service->getBalanceSheet($tenantId),
            'list_accounts' => $this->service->getBalanceSheet($tenantId), // Same for now
            'record_sale_accounting' => $this->service->recordSaleAccounting(
                $tenantId, 
                (float) ($input['total'] ?? 0), 
                (string) ($input['ref'] ?? 'SALE'), 
                $userId
            ),
            default => throw new RuntimeException("Unknown accounting action: {$action}"),
        };
    }
}
