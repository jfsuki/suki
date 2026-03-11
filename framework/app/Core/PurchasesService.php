<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use RuntimeException;

final class PurchasesService
{
    private PurchasesRepository $repository;
    private EntitySearchService $entitySearch;
    private AuditLogger $auditLogger;
    private PurchasesEventLogger $eventLogger;

    public function __construct(
        ?PurchasesRepository $repository = null,
        ?EntitySearchService $entitySearch = null,
        ?AuditLogger $auditLogger = null,
        ?PurchasesEventLogger $eventLogger = null
    ) {
        $this->repository = $repository ?? new PurchasesRepository();
        $this->entitySearch = $entitySearch ?? new EntitySearchService();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->eventLogger = $eventLogger ?? new PurchasesEventLogger();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDraft(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);

        $draft = $this->repository->createDraft([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'supplier_id' => $this->nullableString($payload['supplier_id'] ?? null),
            'status' => 'open',
            'currency' => $this->nullableString($payload['currency'] ?? null),
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'notes' => $this->nullableString($payload['notes'] ?? null),
            'metadata' => is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $draft = $this->validatedDraft($draft);
        $this->logDraftEvent('create_draft', $tenantId, $appId, $draft, $this->latencyMs($startedAt));

        return $draft;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addLineToDraft(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $draftId = $this->requireString($payload['draft_id'] ?? $payload['purchase_draft_id'] ?? null, 'draft_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $draft = $this->loadEditableDraft($tenantId, $draftId, $appId);
        $qty = $this->quantity($payload['qty'] ?? $payload['quantity'] ?? 1);
        $unitCost = $this->money($payload['unit_cost'] ?? $payload['cost'] ?? null);
        $taxRate = $this->nullableDecimal($payload['tax_rate'] ?? null);
        $product = $this->resolveProductIfProvided($tenantId, $appId, $payload);
        $label = $this->nullableString($payload['product_label'] ?? null)
            ?? trim((string) ($product['label'] ?? ''));
        if ($label === '') {
            throw new RuntimeException('PURCHASE_PRODUCT_LABEL_REQUIRED');
        }

        $totals = $this->calculateLineTotals($qty, $unitCost, $taxRate);
        $line = $this->repository->insertLine([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'purchase_draft_id' => $draftId,
            'product_id' => $this->nullableString($product['entity_id'] ?? null),
            'sku' => $this->nullableString($payload['sku'] ?? null),
            'supplier_sku' => $this->nullableString($payload['supplier_sku'] ?? null),
            'product_label' => $label,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'tax_rate' => $taxRate,
            'line_total' => $totals['line_total'],
            'metadata' => [
                'pricing' => $totals,
                'resolved_product' => $product === null ? [] : [
                    'entity_id' => (string) ($product['entity_id'] ?? ''),
                    'label' => (string) ($product['label'] ?? ''),
                    'matched_by' => (string) ($product['matched_by'] ?? ''),
                    'source_module' => (string) ($product['source_module'] ?? 'entity_search'),
                ],
                'hooks' => $this->pendingHooks(),
            ],
        ]);

        $draft = $this->recalculateDraftTotals($tenantId, $draftId, $appId);
        $this->logDraftEvent('add_draft_line', $tenantId, $appId, $draft, $this->latencyMs($startedAt), [
            'product_id' => $line['product_id'] ?? null,
        ]);

        return $draft;
    }

    /**
     * @return array<string, mixed>
     */
    public function removeLineFromDraft(string $tenantId, string $draftId, string $lineId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $draft = $this->loadEditableDraft($tenantId, $draftId, $appId);
        $line = $this->repository->deleteLine($tenantId, $draftId, $lineId, $appId);
        if (!is_array($line)) {
            throw new RuntimeException('PURCHASE_DRAFT_LINE_NOT_FOUND');
        }

        $draft = $this->recalculateDraftTotals($tenantId, $draftId, $appId);
        $this->logDraftEvent('remove_draft_line', $tenantId, $appId, $draft, $this->latencyMs($startedAt), [
            'product_id' => $line['product_id'] ?? null,
        ]);

        return $draft;
    }

    /**
     * @return array<string, mixed>
     */
    public function recalculateDraftTotals(string $tenantId, string $draftId, ?string $appId = null): array
    {
        $draft = $this->loadDraft($tenantId, $draftId, $appId);
        $lines = $this->repository->listLines($tenantId, $draftId, $appId);
        $subtotal = 0.0;
        $taxTotal = 0.0;

        foreach ($lines as $line) {
            $totals = $this->calculateLineTotals(
                $this->quantity($line['qty'] ?? 0),
                $this->money($line['unit_cost'] ?? 0),
                $this->nullableDecimal($line['tax_rate'] ?? null)
            );
            $subtotal += $totals['line_subtotal'];
            $taxTotal += $totals['line_tax'];
            $updated = $this->repository->updateLine($tenantId, $draftId, (string) ($line['id'] ?? ''), [
                'line_total' => $totals['line_total'],
                'metadata' => array_merge(is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [], ['pricing' => $totals]),
            ], $appId);
            if (!is_array($updated)) {
                throw new RuntimeException('PURCHASE_DRAFT_LINE_NOT_FOUND');
            }
        }

        $draft = $this->repository->updateDraft($tenantId, $draftId, [
            'subtotal' => $this->roundMoney($subtotal),
            'tax_total' => $this->roundMoney($taxTotal),
            'total' => $this->roundMoney($subtotal + $taxTotal),
        ], $appId);
        if (!is_array($draft)) {
            throw new RuntimeException('PURCHASE_DRAFT_NOT_FOUND');
        }

        return $this->validatedDraft($draft);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function attachSupplierToDraft(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $draftId = $this->requireString($payload['draft_id'] ?? $payload['purchase_draft_id'] ?? null, 'draft_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $draft = $this->loadEditableDraft($tenantId, $draftId, $appId);
        $supplier = $this->resolveSupplier($tenantId, $appId, $payload);

        $draft = $this->repository->updateDraft($tenantId, $draftId, [
            'supplier_id' => (string) ($supplier['entity_id'] ?? ''),
            'metadata' => array_merge(
                is_array($draft['metadata'] ?? null) ? (array) $draft['metadata'] : [],
                [
                    'supplier_snapshot' => [
                        'entity_id' => (string) ($supplier['entity_id'] ?? ''),
                        'label' => (string) ($supplier['label'] ?? ''),
                        'matched_by' => (string) ($supplier['matched_by'] ?? ''),
                        'source_module' => (string) ($supplier['source_module'] ?? 'entity_search'),
                    ],
                ]
            ),
        ], $appId);
        if (!is_array($draft)) {
            throw new RuntimeException('PURCHASE_DRAFT_NOT_FOUND');
        }

        $draft = $this->validatedDraft($draft);
        $this->logDraftEvent('attach_supplier', $tenantId, $appId, $draft, $this->latencyMs($startedAt));

        return $draft;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function finalizeDraft(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $draftId = $this->requireString($payload['draft_id'] ?? $payload['purchase_draft_id'] ?? null, 'draft_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $createdByUserId = $this->nullableString($payload['created_by_user_id'] ?? $payload['requested_by_user_id'] ?? $payload['user_id'] ?? null);

        if (is_array($this->repository->findPurchaseByDraftId($tenantId, $draftId, $appId))) {
            throw new RuntimeException('PURCHASE_DRAFT_ALREADY_FINALIZED');
        }

        $draft = $this->loadEditableDraft($tenantId, $draftId, $appId);
        $draft = $this->recalculateDraftTotals($tenantId, $draftId, $appId);
        $lines = is_array($draft['lines'] ?? null) ? (array) $draft['lines'] : [];
        if ($lines === []) {
            throw new RuntimeException('PURCHASE_DRAFT_EMPTY');
        }
        if (!$this->draftTotalsAreValid($draft, $lines)) {
            throw new RuntimeException('PURCHASE_DRAFT_TOTALS_INVALID');
        }

        $createdAt = date('Y-m-d H:i:s');
        $result = $this->repository->transaction(function () use ($tenantId, $draftId, $appId, $draft, $lines, $payload, $createdByUserId, $createdAt): array {
            if (is_array($this->repository->findPurchaseByDraftId($tenantId, $draftId, $appId))) {
                throw new RuntimeException('PURCHASE_DRAFT_ALREADY_FINALIZED');
            }

            $purchase = $this->repository->createPurchase([
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'purchase_number' => null,
                'supplier_id' => $draft['supplier_id'] ?? null,
                'draft_id' => $draftId,
                'status' => 'registered',
                'currency' => $draft['currency'] ?? null,
                'subtotal' => $draft['subtotal'] ?? 0,
                'tax_total' => $draft['tax_total'] ?? 0,
                'total' => $draft['total'] ?? 0,
                'notes' => $draft['notes'] ?? null,
                'created_by_user_id' => $createdByUserId,
                'metadata' => array_merge(
                    is_array($draft['metadata'] ?? null) ? (array) $draft['metadata'] : [],
                    is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
                    ['origin' => 'purchase_draft', 'draft_id' => $draftId, 'hooks' => $this->pendingHooks(), 'finalized_at' => $createdAt]
                ),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $purchaseId = $this->requireString($purchase['id'] ?? null, 'purchase_id');
            $purchaseNumber = $this->buildPurchaseNumber($tenantId, $purchaseId, $createdAt);
            $purchase = $this->repository->updatePurchase($tenantId, $purchaseId, [
                'purchase_number' => $purchaseNumber,
                'metadata' => array_merge(is_array($purchase['metadata'] ?? null) ? (array) $purchase['metadata'] : [], ['numbering' => ['scope' => 'tenant', 'status' => 'assigned']]),
                'updated_at' => $createdAt,
            ], $appId);
            if (!is_array($purchase)) {
                throw new RuntimeException('PURCHASE_NOT_FOUND');
            }

            foreach ($lines as $line) {
                $this->repository->insertPurchaseLine([
                    'tenant_id' => $tenantId,
                    'app_id' => $appId,
                    'purchase_id' => $purchaseId,
                    'product_id' => $this->nullableString($line['product_id'] ?? null),
                    'sku' => $this->nullableString($line['sku'] ?? null),
                    'supplier_sku' => $this->nullableString($line['supplier_sku'] ?? null),
                    'product_label' => (string) ($line['product_label'] ?? ''),
                    'qty' => $line['qty'] ?? 0,
                    'unit_cost' => $line['unit_cost'] ?? 0,
                    'tax_rate' => $line['tax_rate'] ?? null,
                    'line_total' => $line['line_total'] ?? 0,
                    'metadata' => is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            $updatedDraft = $this->repository->updateDraft($tenantId, $draftId, [
                'status' => 'completed',
                'metadata' => array_merge(
                    is_array($draft['metadata'] ?? null) ? (array) $draft['metadata'] : [],
                    ['finalized_purchase_id' => $purchaseId, 'purchase_number' => $purchaseNumber, 'finalized_at' => $createdAt, 'hooks' => $this->pendingHooks()]
                ),
                'updated_at' => $createdAt,
            ], $appId);
            if (!is_array($updatedDraft)) {
                throw new RuntimeException('PURCHASE_DRAFT_NOT_FOUND');
            }

            $purchase = $this->repository->loadPurchaseAggregate($tenantId, $purchaseId, $appId);
            if (!is_array($purchase)) {
                throw new RuntimeException('PURCHASE_NOT_FOUND');
            }

            return ['purchase' => $purchase, 'draft' => $updatedDraft];
        });

        $purchase = $this->validatedPurchase((array) ($result['purchase'] ?? []));
        $draft = $this->validatedDraft((array) ($result['draft'] ?? []));
        $this->logPurchaseEvent('finalize', $tenantId, $appId, $purchase, $this->latencyMs($startedAt), ['purchase_draft_id' => $draftId]);

        return [
            'purchase' => $purchase,
            'draft' => $draft,
            'purchase_id' => (string) ($purchase['id'] ?? ''),
            'purchase_number' => (string) ($purchase['purchase_number'] ?? ''),
            'line_count' => count((array) ($purchase['lines'] ?? [])),
            'hooks' => is_array($purchase['metadata']['hooks'] ?? null) ? (array) $purchase['metadata']['hooks'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDraft(string $tenantId, string $draftId, ?string $appId = null): array
    {
        return $this->loadDraft($tenantId, $draftId, $appId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPurchase(string $tenantId, string $purchaseId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $purchase = $this->loadPurchase($tenantId, $purchaseId, $appId);
        $this->logPurchaseEvent('get_purchase', $tenantId, $appId, $purchase, $this->latencyMs($startedAt));

        return $purchase;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listPurchases(string $tenantId, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 10)));
        $items = $this->repository->listPurchases($tenantId, $filters + ['app_id' => $appId], $limit);
        $purchases = [];
        foreach ($items as $item) {
            $purchaseId = (string) ($item['id'] ?? '');
            if ($purchaseId === '') {
                continue;
            }
            $purchase = $this->repository->loadPurchaseAggregate($tenantId, $purchaseId, $appId);
            if (is_array($purchase)) {
                $purchases[] = $this->validatedPurchase($purchase);
            }
        }

        $latencyMs = $this->latencyMs($startedAt);
        $this->auditLogger->log('purchases_list', 'purchase', null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'purchases',
            'action_name' => 'list',
            'supplier_id' => $this->nullableString($filters['supplier_id'] ?? null),
            'line_count' => array_sum(array_map(fn(array $purchase): int => count((array) ($purchase['lines'] ?? [])), $purchases)),
            'total' => array_sum(array_map(fn(array $purchase): float => (float) ($purchase['total'] ?? 0), $purchases)),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('list', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'list',
            'supplier_id' => $this->nullableString($filters['supplier_id'] ?? null),
            'result_count' => count($purchases),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return $purchases;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPurchaseByNumber(string $tenantId, string $purchaseNumber, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $purchaseNumber = $this->requireString($purchaseNumber, 'purchase_number');
        $purchase = $this->repository->findPurchaseByNumber($tenantId, $purchaseNumber, $appId);
        if (!is_array($purchase)) {
            throw new RuntimeException('PURCHASE_NOT_FOUND');
        }

        $loaded = $this->loadPurchase($tenantId, (string) ($purchase['id'] ?? ''), $appId);
        $this->logPurchaseEvent('get_by_number', $tenantId, $appId, $loaded, $this->latencyMs($startedAt));

        return $loaded;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDraft(string $tenantId, string $draftId, ?string $appId): array
    {
        $draft = $this->repository->loadDraftAggregate($tenantId, $draftId, $appId);
        if (!is_array($draft)) {
            throw new RuntimeException('PURCHASE_DRAFT_NOT_FOUND');
        }

        return $this->validatedDraft($draft);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPurchase(string $tenantId, string $purchaseId, ?string $appId): array
    {
        $purchase = $this->repository->loadPurchaseAggregate($tenantId, $purchaseId, $appId);
        if (!is_array($purchase)) {
            throw new RuntimeException('PURCHASE_NOT_FOUND');
        }

        return $this->validatedPurchase($purchase);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEditableDraft(string $tenantId, string $draftId, ?string $appId): array
    {
        $draft = $this->loadDraft($tenantId, $draftId, $appId);
        if ((string) ($draft['status'] ?? '') !== 'open') {
            throw new RuntimeException('PURCHASE_DRAFT_NOT_OPEN');
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveSupplier(string $tenantId, ?string $appId, array $payload): array
    {
        $supplierId = $this->nullableString($payload['supplier_id'] ?? null);
        if ($supplierId !== null) {
            $result = $this->entitySearch->getByReference($tenantId, 'supplier', $supplierId, [], $appId);
            if (!is_array($result)) {
                throw new RuntimeException('PURCHASE_SUPPLIER_NOT_FOUND');
            }

            return $result;
        }

        $query = $this->supplierQuery($payload);
        if ($query === '') {
            throw new RuntimeException('PURCHASE_SUPPLIER_REFERENCE_REQUIRED');
        }

        $resolution = $this->entitySearch->resolveBestMatch($tenantId, $query, [
            'entity_type' => 'supplier',
            'limit' => max(2, (int) ($payload['limit'] ?? 5)),
        ], $appId);
        if ((bool) ($resolution['resolved'] ?? false) && is_array($resolution['result'] ?? null)) {
            return (array) $resolution['result'];
        }
        if (count((array) ($resolution['candidates'] ?? [])) > 0) {
            throw new RuntimeException('PURCHASE_SUPPLIER_AMBIGUOUS');
        }

        throw new RuntimeException('PURCHASE_SUPPLIER_NOT_FOUND');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveProductIfProvided(string $tenantId, ?string $appId, array $payload): ?array
    {
        $productId = $this->nullableString($payload['product_id'] ?? null);
        $query = $this->productQuery($payload);
        if ($productId === null && $query === '') {
            return null;
        }

        if ($productId !== null) {
            $result = $this->entitySearch->getByReference($tenantId, 'product', $productId, [], $appId);
            if (!is_array($result)) {
                throw new RuntimeException('PURCHASE_PRODUCT_NOT_FOUND');
            }

            return $result;
        }

        $resolution = $this->entitySearch->resolveBestMatch($tenantId, $query, [
            'entity_type' => 'product',
            'limit' => max(2, (int) ($payload['limit'] ?? 5)),
        ], $appId);
        if ((bool) ($resolution['resolved'] ?? false) && is_array($resolution['result'] ?? null)) {
            return (array) $resolution['result'];
        }
        if (count((array) ($resolution['candidates'] ?? [])) > 0) {
            throw new RuntimeException('PURCHASE_PRODUCT_AMBIGUOUS');
        }

        throw new RuntimeException('PURCHASE_PRODUCT_NOT_FOUND');
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<int, array<string, mixed>> $lines
     */
    private function draftTotalsAreValid(array $draft, array $lines): bool
    {
        $subtotal = 0.0;
        $taxTotal = 0.0;
        foreach ($lines as $line) {
            $totals = $this->calculateLineTotals(
                $this->quantity($line['qty'] ?? 0),
                $this->money($line['unit_cost'] ?? 0),
                $this->nullableDecimal($line['tax_rate'] ?? null)
            );
            $subtotal += $totals['line_subtotal'];
            $taxTotal += $totals['line_tax'];
        }

        return abs($this->roundMoney($subtotal) - (float) ($draft['subtotal'] ?? 0)) < 0.01
            && abs($this->roundMoney($taxTotal) - (float) ($draft['tax_total'] ?? 0)) < 0.01
            && abs($this->roundMoney($subtotal + $taxTotal) - (float) ($draft['total'] ?? 0)) < 0.01;
    }

    /**
     * @return array{line_subtotal:float,line_tax:float,line_total:float}
     */
    private function calculateLineTotals(float $qty, float $unitCost, ?float $taxRate): array
    {
        $lineSubtotal = $this->roundMoney($qty * $unitCost);
        $lineTax = $taxRate === null ? 0.0 : $this->roundMoney($lineSubtotal * ($taxRate / 100));

        return [
            'line_subtotal' => $lineSubtotal,
            'line_tax' => $lineTax,
            'line_total' => $this->roundMoney($lineSubtotal + $lineTax),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pendingHooks(): array
    {
        return [
            'inventory_entry' => 'pending',
            'accounts_payable' => 'pending',
            'fiscal_support_document' => 'pending',
            'media_documents' => 'available',
            'accounting_posting' => 'pending',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function supplierQuery(array $payload): string
    {
        foreach (['query', 'supplier_query', 'supplier', 'name', 'nombre'] as $key) {
            $value = $this->nullableString($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function productQuery(array $payload): string
    {
        foreach (['query', 'product_query', 'sku', 'supplier_sku'] as $key) {
            $value = $this->nullableString($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return '';
    }

    private function buildPurchaseNumber(string $tenantId, string $purchaseId, string $createdAt): string
    {
        $prefix = strtoupper(substr((preg_replace('/[^A-Za-z0-9]/', '', $tenantId) ?? ''), 0, 6));
        if ($prefix === '') {
            $prefix = (string) $this->stableTenantInt($tenantId);
        }
        $date = $this->purchaseDateTime($createdAt)->format('Ymd');
        $serial = ctype_digit($purchaseId) ? str_pad($purchaseId, 6, '0', STR_PAD_LEFT) : strtoupper($purchaseId);

        return 'PUR-' . $prefix . '-' . $date . '-' . $serial;
    }

    private function purchaseDateTime(string $value): DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return new DateTimeImmutable('now');
        }

        try {
            return new DateTimeImmutable(str_replace('T', ' ', $value));
        } catch (\Throwable $e) {
            return new DateTimeImmutable('now');
        }
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $extra
     */
    private function logDraftEvent(string $actionName, string $tenantId, ?string $appId, array $draft, int $latencyMs, array $extra = []): void
    {
        $payload = $extra + [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'purchases',
            'action_name' => $actionName,
            'purchase_draft_id' => (string) ($draft['id'] ?? ''),
            'supplier_id' => $draft['supplier_id'] ?? null,
            'line_count' => count((array) ($draft['lines'] ?? [])),
            'total' => (float) ($draft['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ];
        $this->auditLogger->log('purchases_' . $actionName, 'purchase_draft', (string) ($draft['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    /**
     * @param array<string, mixed> $purchase
     * @param array<string, mixed> $extra
     */
    private function logPurchaseEvent(string $actionName, string $tenantId, ?string $appId, array $purchase, int $latencyMs, array $extra = []): void
    {
        $payload = $extra + [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'purchases',
            'action_name' => $actionName,
            'purchase_id' => (string) ($purchase['id'] ?? ''),
            'purchase_number' => $purchase['purchase_number'] ?? null,
            'supplier_id' => $purchase['supplier_id'] ?? null,
            'line_count' => count((array) ($purchase['lines'] ?? [])),
            'total' => (float) ($purchase['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ];
        $this->auditLogger->log('purchases_' . $actionName, 'purchase', (string) ($purchase['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    private function quantity($value): float
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('PURCHASE_QTY_REQUIRED');
        }
        $qty = round((float) $value, 4);
        if ($qty <= 0) {
            throw new RuntimeException('PURCHASE_QTY_INVALID');
        }

        return $qty;
    }

    private function nullableDecimal($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('PURCHASE_TAX_RATE_INVALID');
        }

        return round((float) $value, 4);
    }

    private function money($value): float
    {
        if ($value === null || $value === '') {
            throw new RuntimeException('PURCHASE_UNIT_COST_REQUIRED');
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('PURCHASE_UNIT_COST_INVALID');
        }

        return round((float) $value, 4);
    }

    private function roundMoney(float $value): float
    {
        return round($value, 4);
    }

    private function requireString($value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        }

        return $value;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function latencyMs(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function validatedDraft(array $draft): array
    {
        $draft['lines'] = is_array($draft['lines'] ?? null) ? (array) $draft['lines'] : [];
        $draft['metadata'] = is_array($draft['metadata'] ?? null) ? (array) $draft['metadata'] : [];
        PurchasesContractValidator::validateDraft($draft);

        return $draft;
    }

    /**
     * @param array<string, mixed> $purchase
     * @return array<string, mixed>
     */
    private function validatedPurchase(array $purchase): array
    {
        $purchase['lines'] = is_array($purchase['lines'] ?? null) ? (array) $purchase['lines'] : [];
        $purchase['metadata'] = is_array($purchase['metadata'] ?? null) ? (array) $purchase['metadata'] : [];
        PurchasesContractValidator::validatePurchase($purchase);

        return $purchase;
    }

    private function stableTenantInt(string $tenantId): int
    {
        return abs(crc32($tenantId)) % 1000000;
    }
}
