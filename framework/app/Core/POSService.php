<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class POSService
{
    private POSRepository $repository;
    private EntitySearchService $entitySearch;
    private EntityRegistry $entityRegistry;
    private AuditLogger $auditLogger;
    private POSEventLogger $eventLogger;

    public function __construct(
        ?POSRepository $repository = null,
        ?EntitySearchService $entitySearch = null,
        ?EntityRegistry $entityRegistry = null,
        ?AuditLogger $auditLogger = null,
        ?POSEventLogger $eventLogger = null
    ) {
        $this->repository = $repository ?? new POSRepository();
        $this->entitySearch = $entitySearch ?? new EntitySearchService();
        $this->entityRegistry = $entityRegistry ?? new EntityRegistry();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->eventLogger = $eventLogger ?? new POSEventLogger();
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
        $sessionId = $this->nullableString($payload['session_id'] ?? null);
        if ($sessionId !== null && !$this->sessionExists($tenantId, $sessionId, $appId)) {
            throw new RuntimeException('POS_SESSION_NOT_FOUND');
        }

        $draft = $this->repository->createDraft([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'session_id' => $sessionId,
            'status' => 'open',
            'customer_id' => $this->nullableString($payload['customer_id'] ?? null),
            'currency' => $this->nullableString($payload['currency'] ?? null),
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'metadata' => is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $draft = $this->validatedDraft($draft);
        $latencyMs = $this->latencyMs($startedAt);
        $this->auditLogger->log('pos_create_draft', 'sale_draft', $draft['id'], [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'draft_id' => $draft['id'],
            'session_id' => $sessionId,
            'module' => 'pos',
            'action_name' => 'create_draft',
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('create_draft', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'create_draft',
            'draft_id' => $draft['id'],
            'session_id' => $sessionId,
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

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
        $draftId = $this->requireString($payload['draft_id'] ?? $payload['sale_draft_id'] ?? null, 'draft_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $draft = $this->loadEditableDraft($tenantId, $draftId, $appId);

        $qty = $this->quantity($payload['qty'] ?? $payload['quantity'] ?? 1);
        $product = $this->resolveProduct($tenantId, $appId, $payload);
        $unitPrice = $this->explicitOrResolvedPrice($payload['unit_price'] ?? null, $product['unit_price'] ?? null);
        if ($unitPrice === null) {
            throw new RuntimeException('POS_PRODUCT_PRICE_UNAVAILABLE');
        }

        $taxRate = $this->nullableDecimal($payload['tax_rate'] ?? null);
        if ($taxRate === null && array_key_exists('tax_rate', $product)) {
            $taxRate = $this->nullableDecimal($product['tax_rate']);
        }

        $line = $this->repository->insertLine([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'sale_draft_id' => $draftId,
            'product_id' => (string) ($product['entity_id'] ?? ''),
            'sku' => $this->nullableString($product['sku'] ?? null),
            'barcode' => $this->nullableString($product['barcode'] ?? null),
            'product_label' => trim((string) ($product['label'] ?? 'producto')) ?: 'producto',
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'line_total' => $this->money($qty * $unitPrice),
            'metadata' => [
                'resolved_product' => [
                    'matched_by' => (string) ($product['matched_by'] ?? ''),
                    'source_module' => (string) ($product['source_module'] ?? 'entity_search'),
                    'entity_contract' => (string) (($product['metadata_json']['entity_contract'] ?? $product['metadata']['entity_contract'] ?? '')),
                ],
                'hooks' => [
                    'inventory' => 'pending',
                    'fiscal_engine' => 'pending',
                    'receipt' => 'pending',
                    'media' => 'available',
                ],
            ],
        ]);

        $draft = $this->recalculateDraftTotals($tenantId, $draftId, $appId);
        $latencyMs = $this->latencyMs($startedAt);
        $this->auditLogger->log('pos_add_draft_line', 'sale_draft', $draftId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'product_id' => (string) ($line['product_id'] ?? ''),
            'action_name' => 'add_draft_line',
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('add_draft_line', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'add_draft_line',
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'product_id' => (string) ($line['product_id'] ?? ''),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
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
            throw new RuntimeException('POS_DRAFT_LINE_NOT_FOUND');
        }

        $draft = $this->recalculateDraftTotals($tenantId, $draftId, $appId);
        $latencyMs = $this->latencyMs($startedAt);
        $this->auditLogger->log('pos_remove_draft_line', 'sale_draft', $draftId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'product_id' => (string) ($line['product_id'] ?? ''),
            'action_name' => 'remove_draft_line',
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('remove_draft_line', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'remove_draft_line',
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'product_id' => (string) ($line['product_id'] ?? ''),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return $draft;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recalculateDraftTotals(string $tenantId, string $draftId, ?string $appId = null, array $payload = []): array
    {
        $draft = $this->loadDraft($tenantId, $draftId, $appId);
        $lines = $this->repository->listLines($tenantId, $draftId, $appId);
        $subtotal = 0.0;
        $taxTotal = 0.0;

        foreach ($lines as $line) {
            $lineTotal = $this->money((float) ($line['qty'] ?? 0) * (float) ($line['unit_price'] ?? 0));
            $subtotal += $lineTotal;
            $lineTaxRate = $this->nullableDecimal($line['tax_rate'] ?? null);
            if ($lineTaxRate !== null) {
                $taxTotal += $this->money($lineTotal * ($lineTaxRate / 100));
            }
        }

        $draft = $this->repository->updateDraft($tenantId, $draftId, [
            'subtotal' => $this->money($subtotal),
            'tax_total' => $this->money($taxTotal),
            'total' => $this->money($subtotal + $taxTotal),
            'metadata' => array_merge(
                is_array($draft['metadata'] ?? null) ? (array) $draft['metadata'] : [],
                is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : []
            ),
        ], $appId);
        if (!is_array($draft)) {
            throw new RuntimeException('POS_DRAFT_NOT_FOUND');
        }

        $draft['lines'] = $lines;

        return $this->validatedDraft($draft);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function attachCustomerToDraft(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $draftId = $this->requireString($payload['draft_id'] ?? $payload['sale_draft_id'] ?? null, 'draft_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $draft = $this->loadEditableDraft($tenantId, $draftId, $appId);

        $customer = $this->resolveCustomer($tenantId, $appId, $payload);
        $draft = $this->repository->updateDraft($tenantId, $draftId, [
            'customer_id' => (string) ($customer['entity_id'] ?? ''),
            'metadata' => array_merge(
                is_array($draft['metadata'] ?? null) ? (array) $draft['metadata'] : [],
                [
                    'customer_resolution' => [
                        'matched_by' => (string) ($customer['matched_by'] ?? ''),
                        'source_module' => (string) ($customer['source_module'] ?? 'entity_search'),
                        'label' => (string) ($customer['label'] ?? ''),
                    ],
                ]
            ),
        ], $appId);
        if (!is_array($draft)) {
            throw new RuntimeException('POS_DRAFT_NOT_FOUND');
        }

        $draft = $this->validatedDraft($draft);
        $latencyMs = $this->latencyMs($startedAt);
        $this->auditLogger->log('pos_attach_customer', 'sale_draft', $draftId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'action_name' => 'attach_customer',
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('attach_customer', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'attach_customer',
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return $draft;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDraft(string $tenantId, string $draftId, ?string $appId = null): array
    {
        return $this->loadDraft($tenantId, $draftId, $appId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOpenDrafts(string $tenantId, ?string $appId = null, int $limit = 20): array
    {
        $drafts = $this->repository->listDrafts($tenantId, [
            'app_id' => $appId,
            'status' => 'open',
        ], $limit);

        return array_map(fn(array $draft): array => $this->validatedDraft($draft), $drafts);
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareSaleForCheckout(string $tenantId, string $draftId, ?string $appId = null): array
    {
        $draft = $this->loadDraft($tenantId, $draftId, $appId);
        $lines = is_array($draft['lines'] ?? null) ? (array) $draft['lines'] : [];
        $blockers = [];
        if ($lines === []) {
            $blockers[] = 'draft_has_no_lines';
        }
        if (trim((string) ($draft['status'] ?? '')) !== 'open') {
            $blockers[] = 'draft_not_open';
        }

        return [
            'draft' => $draft,
            'checkout_ready' => $blockers === [],
            'blockers' => $blockers,
            'hooks' => [
                'cash_context' => 'pending',
                'receipt' => 'pending',
                'fiscal_engine' => 'pending',
                'inventory' => 'pending',
            ],
        ];
    }

    private function sessionExists(string $tenantId, string $sessionId, ?string $appId): bool
    {
        $session = $this->repository->findSession($tenantId, $sessionId, $appId);
        if (!is_array($session)) {
            return false;
        }

        POSContractValidator::validateSession($session);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDraft(string $tenantId, string $draftId, ?string $appId): array
    {
        $draft = $this->repository->loadDraftAggregate($tenantId, $draftId, $appId);
        if (!is_array($draft)) {
            throw new RuntimeException('POS_DRAFT_NOT_FOUND');
        }

        return $this->validatedDraft($draft);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEditableDraft(string $tenantId, string $draftId, ?string $appId): array
    {
        $draft = $this->loadDraft($tenantId, $draftId, $appId);
        if ((string) ($draft['status'] ?? '') !== 'open') {
            throw new RuntimeException('POS_DRAFT_NOT_OPEN');
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveProduct(string $tenantId, ?string $appId, array $payload): array
    {
        $productId = $this->nullableString($payload['product_id'] ?? null);
        if ($productId !== null) {
            $result = $this->entitySearch->getByReference($tenantId, 'product', $productId, [], $appId);
            if (!is_array($result)) {
                throw new RuntimeException('POS_PRODUCT_NOT_FOUND');
            }

            return $this->hydrateProduct($tenantId, $appId, $result);
        }

        $query = $this->productQuery($payload);
        if ($query === '') {
            throw new RuntimeException('POS_PRODUCT_REFERENCE_REQUIRED');
        }

        $resolution = $this->entitySearch->resolveBestMatch($tenantId, $query, [
            'entity_type' => 'product',
            'limit' => max(2, (int) ($payload['limit'] ?? 5)),
        ], $appId);
        if ((bool) ($resolution['resolved'] ?? false) && is_array($resolution['result'] ?? null)) {
            return $this->hydrateProduct($tenantId, $appId, (array) $resolution['result']);
        }
        if (count((array) ($resolution['candidates'] ?? [])) > 0) {
            throw new RuntimeException('POS_PRODUCT_AMBIGUOUS');
        }

        throw new RuntimeException('POS_PRODUCT_NOT_FOUND');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveCustomer(string $tenantId, ?string $appId, array $payload): array
    {
        $customerId = $this->nullableString($payload['customer_id'] ?? null);
        if ($customerId !== null) {
            $result = $this->entitySearch->getByReference($tenantId, 'customer', $customerId, [], $appId);
            if (!is_array($result)) {
                throw new RuntimeException('POS_CUSTOMER_NOT_FOUND');
            }

            return $result;
        }

        $query = $this->customerQuery($payload);
        if ($query === '') {
            throw new RuntimeException('POS_CUSTOMER_REFERENCE_REQUIRED');
        }

        $resolution = $this->entitySearch->resolveBestMatch($tenantId, $query, [
            'entity_type' => 'customer',
            'limit' => max(2, (int) ($payload['limit'] ?? 5)),
        ], $appId);
        if ((bool) ($resolution['resolved'] ?? false) && is_array($resolution['result'] ?? null)) {
            return (array) $resolution['result'];
        }
        if (count((array) ($resolution['candidates'] ?? [])) > 0) {
            throw new RuntimeException('POS_CUSTOMER_AMBIGUOUS');
        }

        throw new RuntimeException('POS_CUSTOMER_NOT_FOUND');
    }

    /**
     * @param array<string, mixed> $resolved
     * @return array<string, mixed>
     */
    private function hydrateProduct(string $tenantId, ?string $appId, array $resolved): array
    {
        $snapshot = $this->repository->loadDynamicEntitySnapshot($resolved, $tenantId, $appId, $this->entityRegistry);
        if (!is_array($snapshot)) {
            throw new RuntimeException('POS_PRODUCT_NOT_FOUND');
        }

        return array_merge($resolved, $snapshot);
    }

    /**
     * @param mixed $value
     */
    private function explicitOrResolvedPrice($explicit, $resolved): ?float
    {
        if ($explicit !== null && $explicit !== '' && is_numeric($explicit)) {
            return $this->money((float) $explicit);
        }
        if ($resolved !== null && $resolved !== '' && is_numeric($resolved)) {
            return $this->money((float) $resolved);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function productQuery(array $payload): string
    {
        foreach (['query', 'product_query', 'product_reference', 'sku', 'barcode', 'reference', 'product'] as $key) {
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
    private function customerQuery(array $payload): string
    {
        foreach (['query', 'customer_query', 'customer_reference', 'customer'] as $key) {
            $value = $this->nullableString($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private function quantity($value): float
    {
        if (!is_numeric($value)) {
            throw new RuntimeException('POS_INVALID_QTY');
        }

        $value = (float) $value;
        if ($value <= 0) {
            throw new RuntimeException('POS_INVALID_QTY');
        }

        return round($value, 4);
    }

    /**
     * @param mixed $value
     */
    private function nullableDecimal($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return $this->money((float) $value);
    }

    /**
     * @param mixed $value
     */
    private function money($value): float
    {
        return round((float) $value, 4);
    }

    /**
     * @param mixed $value
     */
    private function requireString($value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException('POS_' . strtoupper($field) . '_REQUIRED');
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
        if (!array_key_exists('lines', $draft)) {
            $draft['lines'] = [];
        }
        if (!array_key_exists('metadata', $draft) && array_key_exists('metadata_json', $draft)) {
            $draft['metadata'] = is_array($draft['metadata_json']) ? (array) $draft['metadata_json'] : [];
        }

        POSContractValidator::validateDraft($draft);

        return $draft;
    }
}
