<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use RuntimeException;

final class PurchasesService
{
    /** @var array<int, string> */
    private const DOCUMENT_TYPES = [
        'supplier_invoice',
        'supplier_xml',
        'support_document',
        'payment_proof',
        'general_attachment',
    ];

    private PurchasesRepository $repository;
    private EntitySearchService $entitySearch;
    private MediaService $mediaService;
    private AuditLogger $auditLogger;
    private PurchasesEventLogger $eventLogger;

    public function __construct(
        ?PurchasesRepository $repository = null,
        ?EntitySearchService $entitySearch = null,
        MediaService|AuditLogger|null $mediaService = null,
        PurchasesEventLogger|AuditLogger|null $auditLogger = null,
        ?PurchasesEventLogger $eventLogger = null
    ) {
        $this->repository = $repository ?? new PurchasesRepository();
        $this->entitySearch = $entitySearch ?? new EntitySearchService();

        if ($mediaService instanceof MediaService) {
            $this->mediaService = $mediaService;
            $this->auditLogger = $auditLogger instanceof AuditLogger ? $auditLogger : new AuditLogger();
            $this->eventLogger = $eventLogger ?? ($auditLogger instanceof PurchasesEventLogger ? $auditLogger : new PurchasesEventLogger());
            return;
        }

        $this->mediaService = new MediaService();
        $this->auditLogger = $mediaService instanceof AuditLogger ? $mediaService : new AuditLogger();
        $this->eventLogger = $auditLogger instanceof PurchasesEventLogger ? $auditLogger : ($eventLogger ?? new PurchasesEventLogger());
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function attachDocumentToPurchaseDraft(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $draftId = $this->requireString($payload['draft_id'] ?? $payload['purchase_draft_id'] ?? null, 'draft_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $draft = $this->loadDraft($tenantId, $draftId, $appId);
        $media = $this->loadLinkedMedia($tenantId, $this->requireString($payload['media_file_id'] ?? null, 'media_file_id'), $appId);
        $existing = $this->repository->listDocuments($tenantId, [
            'app_id' => $appId,
            'purchase_draft_id' => $draftId,
            'media_file_id' => (string) ($media['id'] ?? ''),
        ], 1);
        if ($existing !== []) {
            $document = $this->decorateDocument($this->validatedDocument((array) $existing[0]), $tenantId, $appId, $media);
            $this->logDocumentEvent('attach_document_to_draft', $tenantId, $appId, $document, $this->latencyMs($startedAt), [
                'result_status' => 'success',
                'deduped' => true,
            ]);

            return $document;
        }

        $document = $this->repository->createDocument($this->documentRecordFromPayload($payload, $tenantId, $appId, $media, [
            'purchase_id' => null,
            'purchase_draft_id' => $draftId,
            'supplier_id' => $draft['supplier_id'] ?? null,
            'currency' => $draft['currency'] ?? null,
            'total_amount' => $draft['total'] ?? null,
            'link_scope' => 'purchase_draft',
        ]));
        $document = $this->decorateDocument($this->validatedDocument($document), $tenantId, $appId, $media);
        $this->logDocumentEvent('attach_document_to_draft', $tenantId, $appId, $document, $this->latencyMs($startedAt));

        return $document;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function attachDocumentToPurchase(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $purchase = $this->resolvePurchaseReference($tenantId, $appId, $payload);
        $media = $this->loadLinkedMedia($tenantId, $this->requireString($payload['media_file_id'] ?? null, 'media_file_id'), $appId);
        $purchaseId = (string) ($purchase['id'] ?? '');
        $existing = $this->repository->listDocuments($tenantId, [
            'app_id' => $appId,
            'purchase_id' => $purchaseId,
            'media_file_id' => (string) ($media['id'] ?? ''),
        ], 1);
        if ($existing !== []) {
            $document = $this->decorateDocument($this->validatedDocument((array) $existing[0]), $tenantId, $appId, $media);
            $this->logDocumentEvent('attach_document', $tenantId, $appId, $document, $this->latencyMs($startedAt), [
                'result_status' => 'success',
                'deduped' => true,
            ]);

            return $document;
        }

        $document = $this->repository->createDocument($this->documentRecordFromPayload($payload, $tenantId, $appId, $media, [
            'purchase_id' => $purchaseId,
            'purchase_draft_id' => $this->nullableString($purchase['draft_id'] ?? null),
            'supplier_id' => $purchase['supplier_id'] ?? null,
            'currency' => $purchase['currency'] ?? null,
            'total_amount' => $purchase['total'] ?? null,
            'link_scope' => 'purchase',
        ]));
        $document = $this->decorateDocument($this->validatedDocument($document), $tenantId, $appId, $media);
        $this->logDocumentEvent('attach_document', $tenantId, $appId, $document, $this->latencyMs($startedAt), [
            'purchase_number' => $purchase['purchase_number'] ?? null,
        ]);

        return $document;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listPurchaseDocuments(string $tenantId, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $resolvedFilters = $this->documentFilters($tenantId, $filters, $appId);
        $limit = max(1, min(50, (int) ($resolvedFilters['limit'] ?? 20)));
        $items = $this->repository->listDocuments($tenantId, $resolvedFilters, $limit);
        $documents = array_map(
            fn(array $document): array => $this->decorateDocument($this->validatedDocument($document), $tenantId, $appId),
            $items
        );

        $latencyMs = $this->latencyMs($startedAt);
        $this->auditLogger->log('purchases_list_documents', 'purchase_document', null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'purchases',
            'action_name' => 'list_documents',
            'purchase_id' => $resolvedFilters['purchase_id'] ?? null,
            'purchase_draft_id' => $resolvedFilters['purchase_draft_id'] ?? null,
            'supplier_id' => $resolvedFilters['supplier_id'] ?? null,
            'document_type' => $resolvedFilters['document_type'] ?? null,
            'line_count' => count($documents),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('list_documents', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'list_documents',
            'purchase_id' => $resolvedFilters['purchase_id'] ?? null,
            'purchase_draft_id' => $resolvedFilters['purchase_draft_id'] ?? null,
            'supplier_id' => $resolvedFilters['supplier_id'] ?? null,
            'document_type' => $resolvedFilters['document_type'] ?? null,
            'result_count' => count($documents),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return $documents;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPurchaseDocument(string $tenantId, string $documentId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $document = $this->decorateDocument($this->loadPurchaseDocument($tenantId, $documentId, $appId), $tenantId, $appId);
        $this->logDocumentEvent('get_document', $tenantId, $appId, $document, $this->latencyMs($startedAt));

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    public function detachPurchaseDocument(string $tenantId, string $documentId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $document = $this->decorateDocument($this->loadPurchaseDocument($tenantId, $documentId, $appId), $tenantId, $appId);
        $deleted = $this->repository->deleteDocument($tenantId, $documentId, $appId);
        if (!is_array($deleted)) {
            throw new RuntimeException('PURCHASE_DOCUMENT_NOT_FOUND');
        }
        $this->logDocumentEvent('detach_document', $tenantId, $appId, $document, $this->latencyMs($startedAt));

        return ['deleted' => true, 'document' => $document];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function registerDocumentMetadata(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $documentId = $this->requireString($payload['purchase_document_id'] ?? $payload['document_id'] ?? $payload['id'] ?? null, 'purchase_document_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $document = $this->loadPurchaseDocument($tenantId, $documentId, $appId);
        $updates = [];

        if (array_key_exists('document_type', $payload) && $payload['document_type'] !== null && $payload['document_type'] !== '') {
            $updates['document_type'] = $this->documentType($payload['document_type']);
        }
        foreach (['document_number', 'currency', 'notes'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $this->nullableString($payload[$field]);
            }
        }
        if (array_key_exists('issue_date', $payload)) {
            $updates['issue_date'] = $this->nullableDateTime($payload['issue_date']);
        }
        if (array_key_exists('total_amount', $payload)) {
            $updates['total_amount'] = $this->nullableMoney($payload['total_amount']);
        }
        if (array_key_exists('supplier_id', $payload) || array_key_exists('supplier_query', $payload) || array_key_exists('supplier', $payload) || array_key_exists('query', $payload)) {
            $updates['supplier_id'] = $this->resolveSupplierIdForDocument($tenantId, $appId, $payload);
        }
        if (array_key_exists('metadata', $payload) || array_key_exists('metadata_json', $payload)) {
            $updates['metadata'] = array_merge(
                is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [],
                $this->documentMetadata($payload['metadata'] ?? $payload['metadata_json'] ?? [])
            );
        }

        $updated = $this->repository->updateDocument($tenantId, $documentId, $updates, $appId);
        if (!is_array($updated)) {
            throw new RuntimeException('PURCHASE_DOCUMENT_NOT_FOUND');
        }

        $document = $this->decorateDocument($this->validatedDocument($updated), $tenantId, $appId);
        $this->logDocumentEvent('register_document_metadata', $tenantId, $appId, $document, $this->latencyMs($startedAt));

        return $document;
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
     * @return array<string, mixed>
     */
    private function loadPurchaseDocument(string $tenantId, string $documentId, ?string $appId): array
    {
        $document = $this->repository->findDocument($tenantId, $documentId, $appId);
        if (!is_array($document)) {
            throw new RuntimeException('PURCHASE_DOCUMENT_NOT_FOUND');
        }

        return $this->validatedDocument($document);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolvePurchaseReference(string $tenantId, ?string $appId, array $payload): array
    {
        $purchaseId = $this->nullableString($payload['purchase_id'] ?? null);
        if ($purchaseId !== null) {
            return $this->loadPurchase($tenantId, $purchaseId, $appId);
        }

        $purchaseNumber = $this->nullableString($payload['purchase_number'] ?? $payload['number'] ?? null);
        if ($purchaseNumber !== null) {
            return $this->getPurchaseByNumber($tenantId, $purchaseNumber, $appId);
        }

        $query = $this->nullableString($payload['purchase_query'] ?? $payload['query'] ?? null);
        if ($query === null) {
            throw new RuntimeException('PURCHASE_REFERENCE_REQUIRED');
        }

        try {
            return $this->getPurchaseByNumber($tenantId, $query, $appId);
        } catch (RuntimeException $e) {
            if ((string) $e->getMessage() !== 'PURCHASE_NOT_FOUND') {
                throw $e;
            }
        }

        $resolution = $this->entitySearch->resolveBestMatch($tenantId, $query, [
            'entity_type' => 'purchase',
            'limit' => max(2, (int) ($payload['limit'] ?? 5)),
        ], $appId);
        if ((bool) ($resolution['resolved'] ?? false) && is_array($resolution['result'] ?? null)) {
            return $this->loadPurchase($tenantId, (string) (((array) $resolution['result'])['entity_id'] ?? ''), $appId);
        }
        if (count((array) ($resolution['candidates'] ?? [])) > 0) {
            throw new RuntimeException('PURCHASE_AMBIGUOUS');
        }

        throw new RuntimeException('PURCHASE_NOT_FOUND');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function documentFilters(string $tenantId, array $filters, ?string $appId): array
    {
        $resolved = [
            'app_id' => $appId,
            'document_type' => array_key_exists('document_type', $filters)
                ? $this->documentType($filters['document_type'] ?? null, true)
                : null,
            'document_number' => $this->nullableString($filters['document_number'] ?? null),
            'media_file_id' => $this->nullableString($filters['media_file_id'] ?? null),
            'supplier_id' => $this->nullableString($filters['supplier_id'] ?? null),
            'date_from' => $this->nullableDateTime($filters['date_from'] ?? null),
            'date_to' => $this->nullableDateTime($filters['date_to'] ?? null),
            'limit' => max(1, min(50, (int) ($filters['limit'] ?? 20))),
        ];

        $draftId = $this->nullableString($filters['purchase_draft_id'] ?? $filters['draft_id'] ?? null);
        if ($draftId !== null) {
            $this->loadDraft($tenantId, $draftId, $appId);
            $resolved['purchase_draft_id'] = $draftId;
        }

        if (
            array_key_exists('purchase_id', $filters)
            || array_key_exists('purchase_number', $filters)
            || array_key_exists('purchase_query', $filters)
            || array_key_exists('query', $filters)
            || array_key_exists('number', $filters)
        ) {
            $purchase = $this->resolvePurchaseReference($tenantId, $appId, $filters);
            $resolved['purchase_id'] = (string) ($purchase['id'] ?? '');
        }

        if (($resolved['supplier_id'] ?? null) === null && (
            array_key_exists('supplier_query', $filters)
            || array_key_exists('supplier', $filters)
            || array_key_exists('supplier_name', $filters)
        )) {
            $resolved['supplier_id'] = $this->resolveSupplierIdForDocument($tenantId, $appId, $filters);
        }

        return array_filter(
            $resolved,
            static fn($value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function documentRecordFromPayload(array $payload, string $tenantId, ?string $appId, array $media, array $context): array
    {
        $createdAt = date('Y-m-d H:i:s');
        $metadata = array_merge(
            $this->documentMetadata($payload['metadata'] ?? $payload['metadata_json'] ?? []),
            [
                'hooks' => $this->documentHooks(),
                'linked_media' => $this->mediaSnapshot($media),
                'link_scope' => (string) ($context['link_scope'] ?? 'purchase'),
            ]
        );

        return [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'purchase_id' => $this->nullableString($context['purchase_id'] ?? null),
            'purchase_draft_id' => $this->nullableString($context['purchase_draft_id'] ?? null),
            'media_file_id' => (string) ($media['id'] ?? ''),
            'document_type' => $this->documentType($payload['document_type'] ?? null),
            'document_number' => $this->nullableString($payload['document_number'] ?? null),
            'supplier_id' => $this->resolveSupplierIdForDocument($tenantId, $appId, $payload, $this->nullableString($context['supplier_id'] ?? null)),
            'issue_date' => $this->nullableDateTime($payload['issue_date'] ?? null),
            'total_amount' => $this->nullableMoney($payload['total_amount'] ?? ($context['total_amount'] ?? null)),
            'currency' => $this->nullableString($payload['currency'] ?? ($context['currency'] ?? null)),
            'notes' => $this->nullableString($payload['notes'] ?? null),
            'metadata' => $metadata,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
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
     * @return array<string, string>
     */
    private function documentHooks(): array
    {
        return [
            'ocr_extraction' => 'pending',
            'xml_parsing' => 'pending',
            'dian_support_document' => 'pending',
            'accounting_posting' => 'pending',
            'accounts_payable_reconciliation' => 'pending',
            'inventory_evidence_linkage' => 'pending',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLinkedMedia(string $tenantId, string $mediaId, ?string $appId): array
    {
        try {
            return $this->mediaService->get($tenantId, $mediaId, $appId);
        } catch (RuntimeException $e) {
            if ((string) $e->getMessage() === 'MEDIA_NOT_FOUND') {
                throw new RuntimeException('PURCHASE_DOCUMENT_MEDIA_NOT_FOUND');
            }
            throw $e;
        }
    }

    private function resolveSupplierIdForDocument(string $tenantId, ?string $appId, array $payload, ?string $fallbackSupplierId = null): ?string
    {
        $supplierId = $this->nullableString($payload['supplier_id'] ?? null);
        if ($supplierId !== null) {
            $supplier = $this->entitySearch->getByReference($tenantId, 'supplier', $supplierId, [], $appId);
            if (!is_array($supplier)) {
                throw new RuntimeException('PURCHASE_SUPPLIER_NOT_FOUND');
            }

            return (string) ($supplier['entity_id'] ?? '');
        }

        $supplierQuery = $this->nullableString($payload['supplier_query'] ?? $payload['supplier'] ?? $payload['supplier_name'] ?? null);
        if ($supplierQuery !== null) {
            $supplier = $this->resolveSupplier($tenantId, $appId, ['query' => $supplierQuery] + $payload);

            return (string) ($supplier['entity_id'] ?? '');
        }

        return $fallbackSupplierId;
    }

    private function documentType($value, bool $nullable = false): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return $nullable ? null : 'general_attachment';
        }
        if (!in_array($value, self::DOCUMENT_TYPES, true)) {
            throw new RuntimeException('PURCHASE_DOCUMENT_TYPE_INVALID');
        }

        return $value;
    }

    private function nullableDateTime($value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable(str_replace('T', ' ', $value)))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            throw new RuntimeException('PURCHASE_DOCUMENT_ISSUE_DATE_INVALID');
        }
    }

    private function nullableMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('PURCHASE_DOCUMENT_TOTAL_INVALID');
        }

        return round((float) $value, 4);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function documentMetadata($value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function mediaSnapshot(array $media): array
    {
        return [
            'media_file_id' => (string) ($media['id'] ?? ''),
            'entity_type' => (string) ($media['entity_type'] ?? ''),
            'entity_id' => (string) ($media['entity_id'] ?? ''),
            'file_type' => (string) ($media['file_type'] ?? ''),
            'mime_type' => (string) ($media['mime_type'] ?? ''),
            'storage_path' => (string) ($media['storage_path'] ?? ''),
            'file_size' => max(0, (int) ($media['file_size'] ?? 0)),
            'original_name' => (string) ($media['original_name'] ?? ''),
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

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function validatedDocument(array $document): array
    {
        $document['metadata'] = is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [];
        PurchasesContractValidator::validateDocument($document);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed>|null $media
     * @return array<string, mixed>
     */
    private function decorateDocument(array $document, string $tenantId, ?string $appId, ?array $media = null): array
    {
        $document['metadata'] = is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [];
        $document['media'] = $media ?? $this->loadLinkedMedia($tenantId, (string) ($document['media_file_id'] ?? ''), $appId);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $extra
     */
    private function logDocumentEvent(string $actionName, string $tenantId, ?string $appId, array $document, int $latencyMs, array $extra = []): void
    {
        $payload = $extra + [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'purchases',
            'action_name' => $actionName,
            'purchase_id' => $document['purchase_id'] ?? null,
            'purchase_draft_id' => $document['purchase_draft_id'] ?? null,
            'purchase_document_id' => (string) ($document['id'] ?? ''),
            'media_file_id' => (string) ($document['media_file_id'] ?? ''),
            'supplier_id' => $document['supplier_id'] ?? null,
            'document_type' => (string) ($document['document_type'] ?? 'general_attachment'),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ];
        $this->auditLogger->log('purchases_' . $actionName, 'purchase_document', (string) ($document['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    private function stableTenantInt(string $tenantId): int
    {
        return abs(crc32($tenantId)) % 1000000;
    }
}
