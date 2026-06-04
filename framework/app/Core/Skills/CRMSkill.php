<?php
// framework/app/Core/Skills/CRMSkill.php

declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\CRMService;
use App\Core\DataQualityGuard;
use App\Core\BusinessConfigRepository;
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
        $action   = trim((string) ($input['action'] ?? 'search_customers'));
        $tenantId = (string) ($context['tenant_id'] ?? 'default');

        if (in_array($action, ['register_lead', 'update_customer'], true)) {
            $data = (array) ($input['data'] ?? $input['updates'] ?? []);
            $guard = $this->resolveGuard($tenantId, $context);
            $violations = $guard->validate($data);
            if (!empty($violations)) {
                return ['ok' => false, 'error' => 'data_quality', 'violations' => $violations];
            }
        }

        return match ($action) {
            'register_lead'      => $this->service->registerLead($tenantId, (array) ($input['data'] ?? [])),
            'update_customer'    => $this->service->updateCustomerInfo(
                $tenantId,
                (string) ($input['customer_id'] ?? ''),
                (array) ($input['updates'] ?? [])
            ),
            'search_customers'   => $this->service->searchCustomers($tenantId, (array) ($input['filters'] ?? [])),
            'convert_to_prospect'=> $this->service->convertLeadToProspect($tenantId, (string) ($input['customer_id'] ?? '')),
            'convert_to_client'  => $this->service->convertToClient($tenantId, (string) ($input['customer_id'] ?? '')),
            'crm_stats'          => $this->service->getCRMStats($tenantId),
            default              => throw new RuntimeException("Unknown CRM action: {$action}"),
        };
    }

    private function resolveGuard(string $tenantId, array $context): DataQualityGuard
    {
        $country = (string) ($context['country'] ?? '');
        if ($country === '') {
            try {
                $repo = new BusinessConfigRepository();
                $config = $repo->findByTenant($tenantId);
                $country = (string) ($config['country'] ?? 'CO');
            } catch (\Throwable) {
                $country = 'CO';
            }
        }
        return new DataQualityGuard($country);
    }
}
