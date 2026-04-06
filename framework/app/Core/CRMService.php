<?php
// framework/app/Core/CRMService.php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class CRMService
{
    private CRMRepository $repository;

    public function __construct(?CRMRepository $repository = null)
    {
        $this->repository = $repository ?? new CRMRepository();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function registerLead(string $tenantId, array $data): array
    {
        if (empty($data['nombre'])) {
            throw new RuntimeException('Customer name is required.');
        }

        $data['tenant_id'] = $tenantId;
        $data['status'] = $data['status'] ?? 'LEAD';
        
        $id = $this->repository->createCustomer($data);
        return $this->repository->findCustomer($tenantId, $id) ?? [];
    }

    /**
     * @param array<string, mixed> $updates
     */
    public function updateCustomerInfo(string $tenantId, string $customerId, array $updates): array
    {
        $this->repository->updateCustomer($tenantId, $customerId, $updates);
        return $this->repository->findCustomer($tenantId, $customerId) ?? [];
    }

    public function convertLeadToProspect(string $tenantId, string $customerId): array
    {
        return $this->updateCustomerInfo($tenantId, $customerId, ['status' => 'PROSPECTO']);
    }

    public function convertToClient(string $tenantId, string $customerId): array
    {
        return $this->updateCustomerInfo($tenantId, $customerId, ['status' => 'CLIENTE']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchCustomers(string $tenantId, array $filters = []): array
    {
        return $this->repository->listCustomers($tenantId, $filters);
    }

    /**
     * @return array<string, int>
     */
    public function getCRMStats(string $tenantId): array
    {
        $all = $this->repository->listCustomers($tenantId, [], 1000);
        $stats = [
            'total' => count($all),
            'leads' => 0,
            'prospects' => 0,
            'clients' => 0
        ];

        foreach ($all as $c) {
            $status = strtoupper((string) ($c['status'] ?? ''));
            if ($status === 'LEAD') $stats['leads']++;
            elseif ($status === 'PROSPECTO') $stats['prospects']++;
            elseif ($status === 'CLIENTE') $stats['clients']++;
        }

        return $stats;
    }
}
