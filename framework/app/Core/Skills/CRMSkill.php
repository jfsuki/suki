<?php
// framework/app/Core/Skills/CRMSkill.php

declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\CRMService;
use RuntimeException;

final class CRMSkill
{
    private CRMService $service;

    public function __construct(?CRMService $service = null)
    {
        $this->service = $service ?? new CRMService();
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handle(array $input, array $context = []): array
    {
        $action = trim((string) ($input['action'] ?? 'search_customers'));
        $tenantId = (string) ($context['tenant_id'] ?? 'default');
        $userId = (string) ($context['user_id'] ?? 'system_ai');

        return match ($action) {
            'register_lead' => $this->service->registerLead($tenantId, (array) ($input['data'] ?? [])),
            'update_customer' => $this->service->updateCustomerInfo(
                $tenantId, 
                (string) ($input['customer_id'] ?? ''), 
                (array) ($input['updates'] ?? [])
            ),
            'search_customers' => $this->service->searchCustomers($tenantId, (array) ($input['filters'] ?? [])),
            'convert_to_prospect' => $this->service->convertLeadToProspect($tenantId, (string) ($input['customer_id'] ?? '')),
            'convert_to_client' => $this->service->convertToClient($tenantId, (string) ($input['customer_id'] ?? '')),
            'crm_stats' => $this->service->getCRMStats($tenantId),
            default => throw new RuntimeException("Unknown CRM action: {$action}"),
        };
    }
}
