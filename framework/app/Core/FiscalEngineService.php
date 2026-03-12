<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class FiscalEngineService
{
    /** @var array<int, string> */
    private const DOCUMENT_TYPES = [
        'sales_invoice',
        'support_document',
        'credit_note',
        'debit_note',
        'pos_ticket_fiscal_hook',
        'purchase_fiscal_hook',
    ];

    /** @var array<int, string> */
    private const STATUSES = [
        'draft',
        'pending',
        'prepared',
        'submitted',
        'accepted',
        'rejected',
        'canceled',
    ];

    /** @var array<string, array<int, string>> */
    private const STATUS_TRANSITIONS = [
        'draft' => ['draft', 'pending', 'prepared', 'canceled'],
        'pending' => ['pending', 'prepared', 'submitted', 'rejected', 'canceled'],
        'prepared' => ['prepared', 'pending', 'submitted', 'canceled'],
        'submitted' => ['submitted', 'accepted', 'rejected', 'canceled'],
        'accepted' => ['accepted', 'canceled'],
        'rejected' => ['rejected', 'draft', 'pending', 'prepared', 'canceled'],
        'canceled' => ['canceled'],
    ];

    /** @var array<int, string> */
    private const ACTIVE_DUPLICATE_STATUSES = [
        'draft',
        'pending',
        'prepared',
        'submitted',
        'accepted',
    ];

    private FiscalEngineRepository $repository;
    private POSService $posService;
    private PurchasesService $purchasesService;
    private EntitySearchService $entitySearch;
    private AuditLogger $auditLogger;
    private FiscalEngineEventLogger $eventLogger;

    public function __construct(
        ?FiscalEngineRepository $repository = null,
        ?POSService $posService = null,
        ?PurchasesService $purchasesService = null,
        ?EntitySearchService $entitySearch = null,
        ?AuditLogger $auditLogger = null,
        ?FiscalEngineEventLogger $eventLogger = null
    ) {
        $this->repository = $repository ?? new FiscalEngineRepository();
        $this->posService = $posService ?? new POSService();
        $this->purchasesService = $purchasesService ?? new PurchasesService();
        $this->entitySearch = $entitySearch ?? new EntitySearchService();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->eventLogger = $eventLogger ?? new FiscalEngineEventLogger();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSalesInvoiceFromSale(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $sale = $this->resolveSaleForBuilder($tenantId, $appId, $payload);
        $documentType = $this->salesInvoiceDocumentType($payload['document_type'] ?? null);

        return $this->createDocumentEntry([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'source_module' => 'pos',
            'source_entity_type' => 'sale',
            'source_entity_id' => (string) ($sale['id'] ?? ''),
            'document_type' => $documentType,
            'status' => $payload['status'] ?? 'prepared',
            'document_number' => $payload['document_number'] ?? null,
            'currency' => $payload['currency'] ?? ($sale['currency'] ?? null),
            'receiver_party_id' => $payload['receiver_party_id'] ?? ($sale['customer_id'] ?? null),
            'issue_date' => $payload['issue_date'] ?? ($sale['created_at'] ?? null),
            'metadata' => [
                'builder' => [
                    'name' => 'create_sales_invoice_from_sale',
                    'source' => 'pos_sale',
                ],
                'references' => [
                    'sale_id' => (string) ($sale['id'] ?? ''),
                    'sale_number' => (string) ($sale['sale_number'] ?? ''),
                ],
            ],
        ], 'create_sales_invoice_from_sale', $this->duplicatePolicy($payload, 'return_existing'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCreditNoteFromSaleOrReturn(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $source = $this->resolveCreditNoteSource($tenantId, $appId, $payload);
        $sale = is_array($source['sale'] ?? null) ? (array) $source['sale'] : [];
        $return = is_array($source['return'] ?? null) ? (array) $source['return'] : [];

        return $this->createDocumentEntry([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'source_module' => 'pos',
            'source_entity_type' => (string) ($source['source_entity_type'] ?? 'sale'),
            'source_entity_id' => (string) ($source['source_entity_id'] ?? ''),
            'document_type' => 'credit_note',
            'status' => $payload['status'] ?? 'prepared',
            'currency' => $payload['currency'] ?? ($sale['currency'] ?? null),
            'receiver_party_id' => $payload['receiver_party_id'] ?? ($sale['customer_id'] ?? null),
            'issue_date' => $payload['issue_date'] ?? ($return['created_at'] ?? $sale['updated_at'] ?? $sale['created_at'] ?? null),
            'metadata' => [
                'builder' => [
                    'name' => 'create_credit_note_from_sale_or_return',
                    'source' => (string) ($source['source_entity_type'] ?? 'sale'),
                ],
                'references' => [
                    'sale_id' => (string) ($sale['id'] ?? ''),
                    'sale_number' => (string) ($sale['sale_number'] ?? ''),
                    'return_id' => (string) ($return['id'] ?? ''),
                    'return_number' => (string) ($return['return_number'] ?? ''),
                    'reason' => $this->nullableString($payload['reason'] ?? $return['reason'] ?? null),
                ],
            ],
        ], 'create_credit_note', $this->duplicatePolicy($payload, 'return_existing'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSupportDocumentFromPurchase(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $purchase = $this->resolvePurchaseForBuilder($tenantId, $appId, $payload);
        $documentType = $this->supportDocumentType($payload['document_type'] ?? null);

        return $this->createDocumentEntry([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'source_module' => 'purchases',
            'source_entity_type' => 'purchase',
            'source_entity_id' => (string) ($purchase['id'] ?? ''),
            'document_type' => $documentType,
            'status' => $payload['status'] ?? 'prepared',
            'currency' => $payload['currency'] ?? ($purchase['currency'] ?? null),
            'issuer_party_id' => $payload['issuer_party_id'] ?? ($purchase['supplier_id'] ?? null),
            'issue_date' => $payload['issue_date'] ?? ($purchase['created_at'] ?? null),
            'metadata' => [
                'builder' => [
                    'name' => 'create_support_document_from_purchase',
                    'source' => 'purchase',
                ],
                'references' => [
                    'purchase_id' => (string) ($purchase['id'] ?? ''),
                    'purchase_number' => (string) ($purchase['purchase_number'] ?? ''),
                ],
            ],
        ], 'create_support_document_from_purchase', $this->duplicatePolicy($payload, 'return_existing'));
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDocumentPayload(string $tenantId, string $documentId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $document = $this->getDocument($tenantId, $documentId, $appId);
        $payload = $this->validatedPayload($this->documentPayloadFromDocument($document, $appId));

        $this->logDocumentEvent('build_document_payload', $tenantId, $appId, $document, $this->latencyMs($startedAt), [
            'duplicate_blocked' => $this->duplicateBlockedFromDocument($document),
        ]);

        return $payload;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listDocumentsByType(string $tenantId, string $documentType, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $documentType = $this->documentType($documentType);
        $items = $this->loadDocuments($tenantId, $filters + ['document_type' => $documentType], $appId);
        $selected = is_array($items[0] ?? null) ? (array) $items[0] : [];

        $this->eventLogger->log('list_documents_by_type', $tenantId, [
            'query' => 'list_documents_by_type',
            'filters' => ['document_type' => $documentType] + $filters,
            'result_count' => count($items),
            'selected_entity_type' => 'fiscal_document',
            'selected_entity_id' => (string) ($selected['id'] ?? ''),
            'document_type' => $documentType,
            'latency_ms' => $this->latencyMs($startedAt),
        ]);

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDocumentFromSource(array $payload): array
    {
        return $this->createDocumentEntry($payload, 'create_document', $this->duplicatePolicy($payload, 'allow'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createDocumentEntry(array $payload, string $actionName, string $duplicatePolicy): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $sourceModule = $this->normalizeSourceModule($payload['source_module'] ?? null);
        $sourceEntityType = $this->normalizeSourceEntityType($payload['source_entity_type'] ?? null);
        $sourceEntityId = $this->requireString($payload['source_entity_id'] ?? null, 'source_entity_id');
        $documentType = $this->documentType($payload['document_type'] ?? null);
        $status = array_key_exists('status', $payload) && $payload['status'] !== null && $payload['status'] !== ''
            ? $this->status($payload['status'])
            : 'prepared';

        $sourceContext = $this->resolveSourceContext($tenantId, $appId, $sourceModule, $sourceEntityType, $sourceEntityId);
        $sourceSnapshot = is_array($sourceContext['source_snapshot'] ?? null) ? (array) $sourceContext['source_snapshot'] : [];
        $defaults = is_array($sourceContext['defaults'] ?? null) ? (array) $sourceContext['defaults'] : [];
        $sourceLines = is_array($sourceContext['lines'] ?? null) ? (array) $sourceContext['lines'] : [];
        $lines = $this->normalizeInputLines(is_array($payload['lines'] ?? null) ? (array) $payload['lines'] : $sourceLines);
        $summary = $this->calculateFiscalTotalsSummary($lines, $payload + $defaults);

        $duplicate = $this->findActiveDuplicateDocument(
            $tenantId,
            $sourceModule,
            $sourceEntityType,
            $sourceEntityId,
            $documentType,
            $appId
        );
        if (is_array($duplicate)) {
            if ($duplicatePolicy === 'block') {
                throw new RuntimeException('FISCAL_DUPLICATE_ACTIVE_DOCUMENT');
            }
            if ($duplicatePolicy === 'return_existing') {
                $document = $this->decorateDuplicateMetadata($duplicate, $actionName);
                $this->logDocumentEvent($actionName, $tenantId, $appId, $document, $this->latencyMs($startedAt), [
                    'duplicate_blocked' => true,
                ]);

                return $document;
            }
        }

        $createdAt = date('Y-m-d H:i:s');
        $document = $this->repository->transaction(function () use (
            $tenantId,
            $appId,
            $sourceModule,
            $sourceEntityType,
            $sourceEntityId,
            $documentType,
            $status,
            $payload,
            $defaults,
            $sourceSnapshot,
            $summary,
            $lines,
            $createdAt
        ): array {
            $document = $this->repository->createDocument([
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'source_module' => $sourceModule,
                'source_entity_type' => $sourceEntityType,
                'source_entity_id' => $sourceEntityId,
                'document_type' => $documentType,
                'document_number' => $this->nullableString($payload['document_number'] ?? $defaults['document_number'] ?? null),
                'status' => $status,
                'issuer_party_id' => $this->nullableString($payload['issuer_party_id'] ?? $defaults['issuer_party_id'] ?? null),
                'receiver_party_id' => $this->nullableString($payload['receiver_party_id'] ?? $defaults['receiver_party_id'] ?? null),
                'issue_date' => $this->nullableString($payload['issue_date'] ?? $defaults['issue_date'] ?? null),
                'currency' => $this->nullableString($payload['currency'] ?? $defaults['currency'] ?? null),
                'subtotal' => $summary['subtotal'],
                'tax_total' => $summary['tax_total'],
                'total' => $summary['total'],
                'external_provider' => $this->nullableString($payload['external_provider'] ?? $defaults['external_provider'] ?? null),
                'external_reference' => $this->nullableString($payload['external_reference'] ?? $defaults['external_reference'] ?? null),
                'metadata' => $this->buildDocumentMetadata($payload, $sourceSnapshot, $defaults),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $documentId = $this->requireString($document['id'] ?? null, 'fiscal_document_id');
            if ($lines !== []) {
                $this->repository->replaceLines($tenantId, $documentId, $lines, $appId);
            }

            $loaded = $this->repository->loadDocumentAggregate($tenantId, $documentId, $appId);
            if (!is_array($loaded)) {
                throw new RuntimeException('FISCAL_DOCUMENT_NOT_FOUND');
            }

            return $loaded;
        });

        $document = $this->validatedDocument($document);
        $this->logDocumentEvent($actionName, $tenantId, $appId, $document, $this->latencyMs($startedAt), [
            'duplicate_blocked' => false,
        ]);

        return $document;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function duplicatePolicy(array $payload, string $default): string
    {
        $allowDuplicate = $payload['allow_duplicate'] ?? null;
        if ($allowDuplicate === true || $allowDuplicate === 1 || $allowDuplicate === '1' || $allowDuplicate === 'true') {
            return 'allow';
        }

        $policy = strtolower((string) ($payload['duplicate_policy'] ?? ''));
        if (in_array($policy, ['allow', 'block', 'return_existing'], true)) {
            return $policy;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function replaceDocumentLines(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $documentId = $this->requireString($payload['fiscal_document_id'] ?? $payload['document_id'] ?? null, 'fiscal_document_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $document = $this->loadDocument($tenantId, $documentId, $appId);
        $lines = $this->normalizeInputLines(is_array($payload['lines'] ?? null) ? (array) $payload['lines'] : []);
        $summary = $this->calculateFiscalTotalsSummary($lines, $payload + $document);

        $document = $this->repository->transaction(function () use ($tenantId, $documentId, $appId, $document, $lines, $summary): array {
            $this->repository->replaceLines($tenantId, $documentId, $lines, $appId);
            $updated = $this->repository->updateDocument($tenantId, $documentId, [
                'subtotal' => $summary['subtotal'],
                'tax_total' => $summary['tax_total'],
                'total' => $summary['total'],
                'metadata' => array_merge(
                    is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [],
                    ['line_sync' => ['updated_at' => date('c')]]
                ),
            ], $appId);
            if (!is_array($updated)) {
                throw new RuntimeException('FISCAL_DOCUMENT_NOT_FOUND');
            }

            return $updated;
        });

        $document = $this->validatedDocument($document);
        $this->logDocumentEvent('replace_lines', $tenantId, $appId, $document, $this->latencyMs($startedAt));

        return $document;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $base
     * @return array<string, float|null>
     */
    public function calculateFiscalTotalsSummary(array $lines, array $base = []): array
    {
        if ($lines === []) {
            return [
                'subtotal' => $this->nullableMoney($base['subtotal'] ?? null),
                'tax_total' => $this->nullableMoney($base['tax_total'] ?? null),
                'total' => $this->nullableMoney($base['total'] ?? null),
            ];
        }

        $subtotal = 0.0;
        $taxTotal = 0.0;
        $total = 0.0;
        foreach ($lines as $line) {
            $amounts = $this->lineAmounts($line);
            $subtotal += $amounts['subtotal'];
            $taxTotal += $amounts['tax_total'];
            $total += $amounts['total'];
        }

        return [
            'subtotal' => $this->money($subtotal),
            'tax_total' => $this->money($taxTotal),
            'total' => $this->money($total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocument(string $tenantId, string $documentId, ?string $appId = null): array
    {
        return $this->validatedDocument($this->loadDocument($tenantId, $documentId, $appId));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listDocuments(string $tenantId, array $filters = [], ?string $appId = null): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $normalized = $this->normalizeListFilters($filters, $appId);
        $rows = $this->repository->listDocuments($tenantId, $normalized, max(1, (int) ($normalized['limit'] ?? 10)));
        $items = [];
        foreach ($rows as $row) {
            $loaded = $this->repository->loadDocumentAggregate($tenantId, (string) ($row['id'] ?? ''), $this->nullableString($normalized['app_id'] ?? null));
            if (is_array($loaded)) {
                $items[] = $this->validatedDocument($loaded);
            }
        }

        $selected = is_array($items[0] ?? null) ? (array) $items[0] : [];
        $this->eventLogger->log('list_documents', $tenantId, [
            'query' => 'list_documents',
            'result_count' => count($items),
            'selected_entity_type' => 'fiscal_document',
            'selected_entity_id' => (string) ($selected['id'] ?? ''),
            'latency_ms' => 0,
        ]);

        return $items;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getDocumentBySource(string $tenantId, string $sourceModule, string $sourceEntityType, string $sourceEntityId, array $filters = [], ?string $appId = null): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $sourceModule = $this->normalizeSourceModule($sourceModule);
        $sourceEntityType = $this->normalizeSourceEntityType($sourceEntityType);
        $sourceEntityId = $this->requireString($sourceEntityId, 'source_entity_id');
        $documentType = $this->nullableString($filters['document_type'] ?? null);
        $appId = $this->nullableString($filters['app_id'] ?? $filters['project_id'] ?? $appId);
        $matches = $this->repository->findBySource($tenantId, $sourceModule, $sourceEntityType, $sourceEntityId, $appId, $documentType, max(1, (int) ($filters['limit'] ?? 10)));
        if ($matches === []) {
            throw new RuntimeException('FISCAL_DOCUMENT_NOT_FOUND');
        }
        if (count($matches) > 1) {
            throw new RuntimeException('FISCAL_DOCUMENT_SOURCE_AMBIGUOUS');
        }

        $document = $this->repository->loadDocumentAggregate($tenantId, (string) ($matches[0]['id'] ?? ''), $appId);
        if (!is_array($document)) {
            throw new RuntimeException('FISCAL_DOCUMENT_NOT_FOUND');
        }

        return $this->validatedDocument($document);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordEvent(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $documentId = $this->requireString($payload['fiscal_document_id'] ?? $payload['document_id'] ?? null, 'fiscal_document_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $document = $this->loadDocument($tenantId, $documentId, $appId);
        $event = $this->repository->createEvent([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'fiscal_document_id' => $documentId,
            'event_type' => $this->requireString($payload['event_type'] ?? null, 'event_type'),
            'event_status' => $this->requireString($payload['event_status'] ?? 'recorded', 'event_status'),
            'payload' => is_array($payload['payload'] ?? $payload['payload_json'] ?? null) ? (array) ($payload['payload'] ?? $payload['payload_json']) : [],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $event = $this->validatedEvent($event);
        $this->logDocumentEvent('record_event', $tenantId, $appId, $document, $this->latencyMs($startedAt), [
            'event_type' => (string) ($event['event_type'] ?? ''),
            'fiscal_status' => (string) ($document['status'] ?? ''),
        ]);

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateStatus(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $documentId = $this->requireString($payload['fiscal_document_id'] ?? $payload['document_id'] ?? null, 'fiscal_document_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $document = $this->loadDocument($tenantId, $documentId, $appId);
        $currentStatus = $this->status($document['status'] ?? 'draft');
        $targetStatus = $this->status($payload['status'] ?? null);

        if (!in_array($targetStatus, self::STATUS_TRANSITIONS[$currentStatus] ?? [], true)) {
            throw new RuntimeException('FISCAL_STATUS_TRANSITION_INVALID');
        }

        $updated = $this->repository->transaction(function () use ($tenantId, $documentId, $appId, $document, $targetStatus, $payload): array {
            $metadata = array_merge(
                is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [],
                [
                    'status_tracking' => [
                        'previous_status' => (string) ($document['status'] ?? 'draft'),
                        'updated_status' => $targetStatus,
                        'updated_at' => date('c'),
                    ],
                ]
            );

            $updated = $this->repository->updateDocument($tenantId, $documentId, [
                'status' => $targetStatus,
                'metadata' => $metadata,
            ], $appId);
            if (!is_array($updated)) {
                throw new RuntimeException('FISCAL_DOCUMENT_NOT_FOUND');
            }

            $event = $this->repository->createEvent([
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'fiscal_document_id' => $documentId,
                'event_type' => 'status_updated',
                'event_status' => $targetStatus,
                'payload' => [
                    'previous_status' => (string) ($document['status'] ?? 'draft'),
                    'status' => $targetStatus,
                    'reason' => $this->nullableString($payload['reason'] ?? null),
                ],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (!is_array($event)) {
                throw new RuntimeException('FISCAL_EVENT_INSERT_FETCH_FAILED');
            }

            return $updated;
        });

        $updated = $this->validatedDocument($updated);
        $this->logDocumentEvent('update_status', $tenantId, $appId, $updated, $this->latencyMs($startedAt), [
            'fiscal_status' => (string) ($updated['status'] ?? ''),
        ]);

        return $updated;
    }

    private function loadDocument(string $tenantId, string $documentId, ?string $appId = null): array
    {
        $document = $this->repository->loadDocumentAggregate($tenantId, $documentId, $appId);
        if (!is_array($document)) {
            throw new RuntimeException('FISCAL_DOCUMENT_NOT_FOUND');
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveSaleForBuilder(string $tenantId, ?string $appId, array $payload): array
    {
        $sourceModule = $this->nullableString($payload['source_module'] ?? null);
        if ($sourceModule !== null && strtolower($sourceModule) !== 'pos') {
            throw new RuntimeException('FISCAL_SALES_INVOICE_SOURCE_INVALID');
        }

        $sourceEntityType = $this->nullableString($payload['source_entity_type'] ?? null);
        if ($sourceEntityType !== null && !in_array(strtolower($sourceEntityType), ['sale', 'pos_sale'], true)) {
            throw new RuntimeException('FISCAL_SALES_INVOICE_SOURCE_INVALID');
        }

        $saleId = $this->nullableString($payload['sale_id'] ?? null);
        if ($saleId === null && $sourceEntityType !== null && in_array(strtolower($sourceEntityType), ['sale', 'pos_sale'], true)) {
            $saleId = $this->nullableString($payload['source_entity_id'] ?? null);
        }

        if ($saleId !== null) {
            $sale = $this->posService->getSale($tenantId, $saleId, $appId);
            if (!in_array((string) ($sale['status'] ?? ''), ['completed', 'canceled'], true)) {
                throw new RuntimeException('FISCAL_SALES_INVOICE_SOURCE_INVALID');
            }

            return $sale;
        }

        $saleNumber = $this->nullableString($payload['sale_number'] ?? $payload['number'] ?? null);
        if ($saleNumber !== null) {
            $sale = $this->posService->getSaleByNumber($tenantId, $saleNumber, $appId);
            if (!in_array((string) ($sale['status'] ?? ''), ['completed', 'canceled'], true)) {
                throw new RuntimeException('FISCAL_SALES_INVOICE_SOURCE_INVALID');
            }

            return $sale;
        }

        throw new RuntimeException('FISCAL_SALES_INVOICE_SOURCE_INVALID');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveCreditNoteSource(string $tenantId, ?string $appId, array $payload): array
    {
        $sourceModule = $this->nullableString($payload['source_module'] ?? null);
        if ($sourceModule !== null && strtolower($sourceModule) !== 'pos') {
            throw new RuntimeException('FISCAL_CREDIT_NOTE_SOURCE_INVALID');
        }

        $sourceEntityType = strtolower((string) ($this->nullableString($payload['source_entity_type'] ?? null) ?? ''));
        if ($sourceEntityType !== '' && !in_array($sourceEntityType, ['sale', 'pos_sale', 'return', 'pos_return'], true)) {
            throw new RuntimeException('FISCAL_CREDIT_NOTE_SOURCE_INVALID');
        }

        $returnId = $this->nullableString($payload['return_id'] ?? null);
        if ($returnId === null && in_array($sourceEntityType, ['return', 'pos_return'], true)) {
            $returnId = $this->nullableString($payload['source_entity_id'] ?? null);
        }

        if ($returnId !== null) {
            $return = $this->posService->getReturn($tenantId, $returnId, $appId);
            $saleId = $this->requireString($return['sale_id'] ?? null, 'sale_id');
            $sale = $this->posService->getSale($tenantId, $saleId, $appId);

            return [
                'source_entity_type' => 'return',
                'source_entity_id' => (string) ($return['id'] ?? ''),
                'sale' => $sale,
                'return' => $return,
            ];
        }

        $sale = $this->resolveSaleForBuilder($tenantId, $appId, $payload);
        $saleMetadata = is_array($sale['metadata'] ?? null) ? (array) $sale['metadata'] : [];
        if ((string) ($sale['status'] ?? '') !== 'canceled' && !is_array($saleMetadata['cancelation'] ?? null)) {
            throw new RuntimeException('FISCAL_CREDIT_NOTE_SOURCE_INVALID');
        }

        return [
            'source_entity_type' => 'sale',
            'source_entity_id' => (string) ($sale['id'] ?? ''),
            'sale' => $sale,
            'return' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolvePurchaseForBuilder(string $tenantId, ?string $appId, array $payload): array
    {
        $sourceModule = $this->nullableString($payload['source_module'] ?? null);
        if ($sourceModule !== null && strtolower($sourceModule) !== 'purchases') {
            throw new RuntimeException('FISCAL_SUPPORT_DOCUMENT_SOURCE_INVALID');
        }

        $sourceEntityType = $this->nullableString($payload['source_entity_type'] ?? null);
        if ($sourceEntityType !== null && strtolower($sourceEntityType) !== 'purchase') {
            throw new RuntimeException('FISCAL_SUPPORT_DOCUMENT_SOURCE_INVALID');
        }

        $purchaseId = $this->nullableString($payload['purchase_id'] ?? null);
        if ($purchaseId === null && $sourceEntityType !== null && strtolower($sourceEntityType) === 'purchase') {
            $purchaseId = $this->nullableString($payload['source_entity_id'] ?? null);
        }

        if ($purchaseId !== null) {
            return $this->purchasesService->getPurchase($tenantId, $purchaseId, $appId);
        }

        $purchaseNumber = $this->nullableString($payload['purchase_number'] ?? $payload['number'] ?? null);
        if ($purchaseNumber !== null) {
            return $this->purchasesService->getPurchaseByNumber($tenantId, $purchaseNumber, $appId);
        }

        throw new RuntimeException('FISCAL_SUPPORT_DOCUMENT_SOURCE_INVALID');
    }

    private function salesInvoiceDocumentType($value): string
    {
        $resolved = $this->nullableString($value) ?? 'sales_invoice';
        $resolved = strtolower($resolved);
        if (!in_array($resolved, ['sales_invoice', 'pos_ticket_fiscal_hook'], true)) {
            throw new RuntimeException('FISCAL_DOCUMENT_TYPE_INVALID');
        }

        return $resolved;
    }

    private function supportDocumentType($value): string
    {
        $resolved = $this->nullableString($value) ?? 'support_document';
        $resolved = strtolower($resolved);
        if (!in_array($resolved, ['support_document', 'purchase_fiscal_hook'], true)) {
            throw new RuntimeException('FISCAL_DOCUMENT_TYPE_INVALID');
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $sourceSnapshot
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function buildDocumentMetadata(array $payload, array $sourceSnapshot, array $defaults): array
    {
        $metadata = is_array($defaults['metadata'] ?? null) ? (array) $defaults['metadata'] : [];
        $metadata = array_merge($metadata, is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : []);
        $metadata['source_snapshot'] = $sourceSnapshot;
        $metadata['hooks'] = array_merge(
            is_array($metadata['hooks'] ?? null) ? (array) $metadata['hooks'] : [],
            [
                'provider_submission' => 'pending',
                'response_handling' => 'pending',
                'tax_breakdown' => 'pending',
                'withholding' => 'pending',
                'exemptions' => 'pending',
                'fiscal_profile' => 'pending',
            ]
        );

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSourceContext(string $tenantId, ?string $appId, string $sourceModule, string $sourceEntityType, string $sourceEntityId): array
    {
        if ($sourceModule === 'pos' && in_array($sourceEntityType, ['sale', 'pos_sale'], true)) {
            $sale = $this->posService->getSale($tenantId, $sourceEntityId, $appId);
            return [
                'source_snapshot' => [
                    'module' => 'pos',
                    'entity_type' => $sourceEntityType,
                    'entity_id' => (string) ($sale['id'] ?? ''),
                    'sale_number' => (string) ($sale['sale_number'] ?? ''),
                    'session_id' => (string) ($sale['session_id'] ?? ''),
                ],
                'defaults' => [
                    'receiver_party_id' => $this->nullableString($sale['customer_id'] ?? null),
                    'issue_date' => $this->nullableString($sale['created_at'] ?? null),
                    'currency' => $this->nullableString($sale['currency'] ?? null),
                    'subtotal' => $sale['subtotal'] ?? null,
                    'tax_total' => $sale['tax_total'] ?? null,
                    'total' => $sale['total'] ?? null,
                    'metadata' => [
                        'hooks' => [
                            'pos_sale_link' => 'ready',
                            'fiscal_provider' => 'pending',
                            'inventory' => 'pending',
                            'cash' => 'pending',
                        ],
                    ],
                ],
                'lines' => array_map([$this, 'mapSaleLineToFiscalLine'], (array) ($sale['lines'] ?? [])),
            ];
        }

        if ($sourceModule === 'pos' && in_array($sourceEntityType, ['return', 'pos_return'], true)) {
            $return = $this->posService->getReturn($tenantId, $sourceEntityId, $appId);
            $saleId = $this->requireString($return['sale_id'] ?? null, 'sale_id');
            $sale = $this->posService->getSale($tenantId, $saleId, $appId);

            return [
                'source_snapshot' => [
                    'module' => 'pos',
                    'entity_type' => $sourceEntityType,
                    'entity_id' => (string) ($return['id'] ?? ''),
                    'sale_id' => $saleId,
                    'sale_number' => (string) ($sale['sale_number'] ?? ''),
                    'return_number' => (string) ($return['return_number'] ?? ''),
                    'session_id' => (string) ($sale['session_id'] ?? ''),
                ],
                'defaults' => [
                    'receiver_party_id' => $this->nullableString($sale['customer_id'] ?? null),
                    'issue_date' => $this->nullableString($return['created_at'] ?? null),
                    'currency' => $this->nullableString($sale['currency'] ?? null),
                    'subtotal' => $return['subtotal'] ?? null,
                    'tax_total' => $return['tax_total'] ?? null,
                    'total' => $return['total'] ?? null,
                    'metadata' => [
                        'hooks' => [
                            'pos_return_link' => 'ready',
                            'cash_adjustment' => 'pending',
                            'inventory_restock' => 'pending',
                            'fiscal_credit_note' => 'pending',
                        ],
                    ],
                ],
                'lines' => array_map([$this, 'mapReturnLineToFiscalLine'], (array) ($return['lines'] ?? [])),
            ];
        }

        if ($sourceModule === 'purchases' && $sourceEntityType === 'purchase') {
            $purchase = $this->purchasesService->getPurchase($tenantId, $sourceEntityId, $appId);
            return [
                'source_snapshot' => [
                    'module' => 'purchases',
                    'entity_type' => 'purchase',
                    'entity_id' => (string) ($purchase['id'] ?? ''),
                    'purchase_number' => (string) ($purchase['purchase_number'] ?? ''),
                ],
                'defaults' => [
                    'issuer_party_id' => $this->nullableString($purchase['supplier_id'] ?? null),
                    'issue_date' => $this->nullableString($purchase['created_at'] ?? null),
                    'currency' => $this->nullableString($purchase['currency'] ?? null),
                    'subtotal' => $purchase['subtotal'] ?? null,
                    'tax_total' => $purchase['tax_total'] ?? null,
                    'total' => $purchase['total'] ?? null,
                    'metadata' => [
                        'hooks' => [
                            'purchase_support_document' => 'pending',
                            'accounts_payable' => 'pending',
                            'inventory_entry' => 'pending',
                        ],
                    ],
                ],
                'lines' => array_map([$this, 'mapPurchaseLineToFiscalLine'], (array) ($purchase['lines'] ?? [])),
            ];
        }

        if ($sourceModule === 'purchases' && in_array($sourceEntityType, ['purchase_document', 'document'], true)) {
            $document = $this->purchasesService->getPurchaseDocument($tenantId, $sourceEntityId, $appId);
            return [
                'source_snapshot' => [
                    'module' => 'purchases',
                    'entity_type' => 'purchase_document',
                    'entity_id' => (string) ($document['id'] ?? ''),
                    'purchase_id' => (string) ($document['purchase_id'] ?? ''),
                    'document_type' => (string) ($document['document_type'] ?? ''),
                    'document_number' => (string) ($document['document_number'] ?? ''),
                    'media_file_id' => (string) ($document['media_file_id'] ?? ''),
                ],
                'defaults' => [
                    'document_number' => $this->nullableString($document['document_number'] ?? null),
                    'issuer_party_id' => $this->nullableString($document['supplier_id'] ?? null),
                    'issue_date' => $this->nullableString($document['issue_date'] ?? null),
                    'currency' => $this->nullableString($document['currency'] ?? null),
                    'subtotal' => $document['total_amount'] ?? null,
                    'tax_total' => 0.0,
                    'total' => $document['total_amount'] ?? null,
                    'metadata' => [
                        'linked_media' => is_array($document['media'] ?? null) ? (array) $document['media'] : [],
                        'hooks' => [
                            'purchase_document_link' => 'ready',
                            'xml_parsing' => 'pending',
                            'ocr' => 'pending',
                        ],
                    ],
                ],
                'lines' => [],
            ];
        }

        $entity = $this->entitySearch->getByReference($tenantId, $sourceEntityType, $sourceEntityId, [], $appId);
        if (!is_array($entity)) {
            throw new RuntimeException('FISCAL_SOURCE_NOT_FOUND');
        }

        return [
            'source_snapshot' => [
                'module' => $sourceModule,
                'entity_type' => $sourceEntityType,
                'entity_id' => $sourceEntityId,
                'label' => (string) ($entity['label'] ?? ''),
                'matched_by' => (string) ($entity['matched_by'] ?? ''),
                'source_module' => (string) ($entity['source_module'] ?? ''),
            ],
            'defaults' => [
                'metadata' => [
                    'hooks' => [
                        'provider_submission' => 'pending',
                        'source_resolution' => 'entity_search',
                    ],
                ],
            ],
            'lines' => [],
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function mapSaleLineToFiscalLine(array $line): array
    {
        $metadata = is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [];
        $pricing = is_array($metadata['pricing_snapshot'] ?? null) ? (array) $metadata['pricing_snapshot'] : [];
        $lineSubtotal = array_key_exists('line_subtotal', $pricing)
            ? $this->nullableMoney($pricing['line_subtotal'])
            : $this->nullableMoney(($line['qty'] ?? null) !== null && ($line['unit_price'] ?? null) !== null ? ((float) ($line['qty'] ?? 0) * (float) ($line['unit_price'] ?? 0)) : null);
        $lineTax = array_key_exists('line_tax', $pricing)
            ? $this->nullableMoney($pricing['line_tax'])
            : $this->nullableMoney(($line['line_total'] ?? null) !== null && $lineSubtotal !== null ? ((float) ($line['line_total'] ?? 0) - (float) $lineSubtotal) : null);

        return [
            'product_id' => $this->nullableString($line['product_id'] ?? null),
            'description' => trim((string) ($line['product_label'] ?? 'producto')) ?: 'producto',
            'qty' => $line['qty'] ?? null,
            'unit_amount' => $line['unit_price'] ?? null,
            'tax_rate' => $line['tax_rate'] ?? null,
            'line_total' => $line['line_total'] ?? null,
            'metadata' => [
                'origin' => [
                    'sale_line_id' => (string) ($line['id'] ?? ''),
                    'sale_id' => (string) ($line['sale_id'] ?? ''),
                    'sku' => $this->nullableString($line['sku'] ?? null),
                    'barcode' => $this->nullableString($line['barcode'] ?? null),
                ],
                'pricing_snapshot' => [
                    'line_subtotal' => $lineSubtotal,
                    'line_tax' => $lineTax,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function mapReturnLineToFiscalLine(array $line): array
    {
        $metadata = is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [];
        $pricing = is_array($metadata['pricing_snapshot'] ?? null) ? (array) $metadata['pricing_snapshot'] : [];

        return [
            'product_id' => $this->nullableString($line['product_id'] ?? null),
            'description' => trim((string) ($line['product_label'] ?? 'producto')) ?: 'producto',
            'qty' => $line['qty'] ?? null,
            'unit_amount' => $line['unit_price'] ?? null,
            'tax_rate' => $pricing['tax_rate'] ?? null,
            'line_total' => $line['line_total'] ?? null,
            'metadata' => [
                'origin' => [
                    'return_line_id' => (string) ($line['id'] ?? ''),
                    'return_id' => (string) ($line['return_id'] ?? ''),
                    'sale_line_id' => (string) ($line['sale_line_id'] ?? ''),
                    'sku' => $this->nullableString($line['sku'] ?? null),
                    'barcode' => $this->nullableString($line['barcode'] ?? null),
                ],
                'pricing_snapshot' => [
                    'line_subtotal' => $this->nullableMoney($pricing['line_subtotal'] ?? null),
                    'line_tax' => $this->nullableMoney($pricing['line_tax'] ?? null),
                ],
                'adjustment_kind' => 'credit_note_candidate',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function mapPurchaseLineToFiscalLine(array $line): array
    {
        $qty = $this->nullableMoney($line['qty'] ?? null);
        $unitCost = $this->nullableMoney($line['unit_cost'] ?? null);
        $lineSubtotal = ($qty !== null && $unitCost !== null) ? $this->money($qty * $unitCost) : null;
        $lineTotal = $this->nullableMoney($line['line_total'] ?? null);
        $lineTax = ($lineSubtotal !== null && $lineTotal !== null) ? $this->money($lineTotal - $lineSubtotal) : null;

        return [
            'product_id' => $this->nullableString($line['product_id'] ?? null),
            'description' => trim((string) ($line['product_label'] ?? 'item')) ?: 'item',
            'qty' => $line['qty'] ?? null,
            'unit_amount' => $line['unit_cost'] ?? null,
            'tax_rate' => $line['tax_rate'] ?? null,
            'line_total' => $line['line_total'] ?? null,
            'metadata' => [
                'origin' => [
                    'purchase_line_id' => (string) ($line['id'] ?? ''),
                    'purchase_id' => (string) ($line['purchase_id'] ?? ''),
                    'sku' => $this->nullableString($line['sku'] ?? null),
                    'supplier_sku' => $this->nullableString($line['supplier_sku'] ?? null),
                ],
                'pricing_snapshot' => [
                    'line_subtotal' => $lineSubtotal,
                    'line_tax' => $lineTax,
                ],
            ],
        ];
    }

    /**
     * @param array<int, mixed> $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeInputLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new RuntimeException('FISCAL_LINE_INVALID');
            }
            $description = trim((string) ($line['description'] ?? $line['product_label'] ?? ''));
            if ($description === '') {
                throw new RuntimeException('FISCAL_LINE_DESCRIPTION_REQUIRED');
            }
            $qty = $this->nullablePositiveNumber($line['qty'] ?? null, 'FISCAL_LINE_QTY_INVALID');
            $unitAmount = $this->nullableMoney($line['unit_amount'] ?? $line['unit_price'] ?? $line['unit_cost'] ?? null);
            $taxRate = $this->nullableDecimal($line['tax_rate'] ?? null, 'FISCAL_LINE_TAX_RATE_INVALID');
            $metadata = is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [];
            $lineTotal = $this->nullableMoney($line['line_total'] ?? null);

            if ($lineTotal === null && $qty !== null && $unitAmount !== null) {
                $lineSubtotal = $this->money($qty * $unitAmount);
                $lineTax = $taxRate !== null ? $this->money($lineSubtotal * ($taxRate / 100)) : 0.0;
                $lineTotal = $this->money($lineSubtotal + $lineTax);
                $metadata = array_merge($metadata, [
                    'pricing_snapshot' => [
                        'line_subtotal' => $lineSubtotal,
                        'line_tax' => $lineTax,
                    ],
                ]);
            }

            $normalized[] = [
                'product_id' => $this->nullableString($line['product_id'] ?? null),
                'description' => $description,
                'qty' => $qty,
                'unit_amount' => $unitAmount,
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
                'metadata' => $metadata,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $line
     * @return array{subtotal:float,tax_total:float,total:float}
     */
    private function lineAmounts(array $line): array
    {
        $metadata = is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [];
        $pricing = is_array($metadata['pricing_snapshot'] ?? null) ? (array) $metadata['pricing_snapshot'] : [];
        $qty = $this->nullableMoney($line['qty'] ?? null);
        $unitAmount = $this->nullableMoney($line['unit_amount'] ?? null);
        $taxRate = $this->nullableMoney($line['tax_rate'] ?? null);
        $lineTotal = $this->nullableMoney($line['line_total'] ?? null);

        $subtotal = array_key_exists('line_subtotal', $pricing)
            ? $this->money((float) $pricing['line_subtotal'])
            : ($qty !== null && $unitAmount !== null
                ? $this->money($qty * $unitAmount)
                : ($lineTotal !== null && $taxRate !== null
                    ? $this->money($lineTotal / (1 + ($taxRate / 100)))
                    : $this->money($lineTotal ?? 0.0)));

        $taxTotal = array_key_exists('line_tax', $pricing)
            ? $this->money((float) $pricing['line_tax'])
            : ($lineTotal !== null
                ? $this->money($lineTotal - $subtotal)
                : ($taxRate !== null ? $this->money($subtotal * ($taxRate / 100)) : 0.0));

        $total = $lineTotal !== null ? $this->money($lineTotal) : $this->money($subtotal + $taxTotal);

        return [
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeListFilters(array $filters, ?string $appId): array
    {
        $normalized = [
            'source_module' => $this->nullableString($filters['source_module'] ?? null),
            'source_entity_type' => $this->nullableString($filters['source_entity_type'] ?? null),
            'source_entity_id' => $this->nullableString($filters['source_entity_id'] ?? null),
            'document_type' => $this->nullableString($filters['document_type'] ?? null),
            'status' => $this->nullableString($filters['status'] ?? null),
            'document_number' => $this->nullableString($filters['document_number'] ?? null),
            'external_provider' => $this->nullableString($filters['external_provider'] ?? null),
            'external_reference' => $this->nullableString($filters['external_reference'] ?? null),
            'date_from' => $this->nullableString($filters['date_from'] ?? null),
            'date_to' => $this->nullableString($filters['date_to'] ?? null),
            'limit' => max(1, min(50, (int) ($filters['limit'] ?? 10))),
        ];
        $resolvedAppId = $this->nullableString($filters['app_id'] ?? $filters['project_id'] ?? $appId);
        if ($resolvedAppId !== null) {
            $normalized['app_id'] = $resolvedAppId;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function loadDocuments(string $tenantId, array $filters = [], ?string $appId = null): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $normalized = $this->normalizeListFilters($filters, $appId);
        $rows = $this->repository->listDocuments($tenantId, $normalized, max(1, (int) ($normalized['limit'] ?? 10)));
        $items = [];
        foreach ($rows as $row) {
            $loaded = $this->repository->loadDocumentAggregate($tenantId, (string) ($row['id'] ?? ''), $this->nullableString($normalized['app_id'] ?? null));
            if (is_array($loaded)) {
                $items[] = $this->validatedDocument($loaded);
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveDuplicateDocument(
        string $tenantId,
        string $sourceModule,
        string $sourceEntityType,
        string $sourceEntityId,
        string $documentType,
        ?string $appId = null
    ): ?array {
        $matches = $this->repository->findBySource(
            $tenantId,
            $sourceModule,
            $sourceEntityType,
            $sourceEntityId,
            $appId,
            $documentType,
            25
        );

        foreach ($matches as $match) {
            $status = strtolower(trim((string) ($match['status'] ?? '')));
            if (!in_array($status, self::ACTIVE_DUPLICATE_STATUSES, true)) {
                continue;
            }

            $loaded = $this->repository->loadDocumentAggregate($tenantId, (string) ($match['id'] ?? ''), $appId);
            if (is_array($loaded)) {
                return $this->validatedDocument($loaded);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function decorateDuplicateMetadata(array $document, string $actionName): array
    {
        $metadata = is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [];
        $metadata['duplicate_prevention'] = [
            'duplicate_blocked' => true,
            'returned_existing' => true,
            'policy' => 'return_existing',
            'action_name' => $actionName,
            'existing_document_id' => (string) ($document['id'] ?? ''),
            'checked_at' => date('c'),
        ];
        $document['metadata'] = $metadata;

        return $this->validatedDocument($document);
    }

    private function normalizeSourceModule($value): string
    {
        return strtolower($this->requireString($value, 'source_module'));
    }

    private function normalizeSourceEntityType($value): string
    {
        return strtolower($this->requireString($value, 'source_entity_type'));
    }

    private function documentType($value): string
    {
        $value = strtolower($this->requireString($value, 'document_type'));
        if (!in_array($value, self::DOCUMENT_TYPES, true)) {
            throw new RuntimeException('FISCAL_DOCUMENT_TYPE_INVALID');
        }

        return $value;
    }

    private function status($value): string
    {
        $value = strtolower($this->requireString($value, 'status'));
        if (!in_array($value, self::STATUSES, true)) {
            throw new RuntimeException('FISCAL_STATUS_INVALID');
        }

        return $value;
    }

    private function money(float $value): float
    {
        return round($value, 4);
    }

    private function nullableMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('FISCAL_AMOUNT_INVALID');
        }

        return $this->money((float) $value);
    }

    private function nullableDecimal($value, string $errorCode): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException($errorCode);
        }

        return round((float) $value, 4);
    }

    private function nullablePositiveNumber($value, string $errorCode): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new RuntimeException($errorCode);
        }

        return round((float) $value, 4);
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
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function validatedDocument(array $document): array
    {
        $document['metadata'] = is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [];
        $document['lines'] = is_array($document['lines'] ?? null) ? (array) $document['lines'] : [];
        $document['events'] = is_array($document['events'] ?? null) ? (array) $document['events'] : [];
        FiscalEngineContractValidator::validateDocument($document);

        return $document;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function validatedEvent(array $event): array
    {
        $event['payload'] = is_array($event['payload'] ?? null) ? (array) $event['payload'] : [];
        FiscalEngineContractValidator::validateEvent($event);

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validatedPayload(array $payload): array
    {
        $payload['header'] = is_array($payload['header'] ?? null) ? (array) $payload['header'] : [];
        $payload['summary'] = is_array($payload['summary'] ?? null) ? (array) $payload['summary'] : [];
        $payload['references'] = is_array($payload['references'] ?? null) ? (array) $payload['references'] : [];
        $payload['metadata'] = is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [];
        $payload['lines'] = array_map(
            static function ($line): array {
                $line = is_array($line) ? (array) $line : [];
                $line['metadata'] = is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [];

                return $line;
            },
            is_array($payload['lines'] ?? null) ? (array) $payload['lines'] : []
        );
        FiscalEngineContractValidator::validatePayload($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function documentPayloadFromDocument(array $document, ?string $appId = null): array
    {
        $metadata = is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [];
        $lines = [];
        foreach ((array) ($document['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lines[] = [
                'line_id' => (string) ($line['id'] ?? ''),
                'product_id' => $this->nullableString($line['product_id'] ?? null),
                'description' => trim((string) ($line['description'] ?? 'item')) ?: 'item',
                'qty' => $this->nullableMoney($line['qty'] ?? null),
                'unit_amount' => $this->nullableMoney($line['unit_amount'] ?? null),
                'tax_rate' => $this->nullableMoney($line['tax_rate'] ?? null),
                'line_total' => $this->nullableMoney($line['line_total'] ?? null),
                'metadata' => is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [],
            ];
        }

        return [
            'fiscal_document_id' => (string) ($document['id'] ?? ''),
            'tenant_id' => (string) ($document['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($appId ?? $document['app_id'] ?? null),
            'document_type' => (string) ($document['document_type'] ?? ''),
            'status' => (string) ($document['status'] ?? ''),
            'header' => [
                'document_number' => $this->nullableString($document['document_number'] ?? null),
                'issue_date' => $this->nullableString($document['issue_date'] ?? null),
                'currency' => $this->nullableString($document['currency'] ?? null),
                'issuer_party_id' => $this->nullableString($document['issuer_party_id'] ?? null),
                'receiver_party_id' => $this->nullableString($document['receiver_party_id'] ?? null),
                'source_module' => (string) ($document['source_module'] ?? ''),
                'source_entity_type' => (string) ($document['source_entity_type'] ?? ''),
                'source_entity_id' => (string) ($document['source_entity_id'] ?? ''),
            ],
            'summary' => [
                'subtotal' => $this->nullableMoney($document['subtotal'] ?? null),
                'tax_total' => $this->nullableMoney($document['tax_total'] ?? null),
                'total' => $this->nullableMoney($document['total'] ?? null),
            ],
            'lines' => $lines,
            'references' => $this->buildPayloadReferences($document, $appId),
            'metadata' => array_merge($metadata, [
                'duplicate_blocked' => $this->duplicateBlockedFromDocument($document),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function buildPayloadReferences(array $document, ?string $appId = null): array
    {
        $metadata = is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [];
        $builderReferences = is_array($metadata['references'] ?? null) ? (array) $metadata['references'] : [];
        $references = [
            'source' => [
                'source_module' => (string) ($document['source_module'] ?? ''),
                'source_entity_type' => (string) ($document['source_entity_type'] ?? ''),
                'source_entity_id' => (string) ($document['source_entity_id'] ?? ''),
            ],
            'builder' => is_array($metadata['builder'] ?? null) ? (array) $metadata['builder'] : [],
            'source_snapshot' => is_array($metadata['source_snapshot'] ?? null) ? (array) $metadata['source_snapshot'] : [],
            'business_references' => $builderReferences,
        ];

        $relatedDocument = $this->relatedFiscalDocumentReference($document, $appId);
        if ($relatedDocument !== null) {
            $references['related_fiscal_document'] = $relatedDocument;
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function relatedFiscalDocumentReference(array $document, ?string $appId = null): ?array
    {
        if ((string) ($document['document_type'] ?? '') !== 'credit_note') {
            return null;
        }

        $metadata = is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [];
        $references = is_array($metadata['references'] ?? null) ? (array) $metadata['references'] : [];
        $saleId = $this->nullableString($references['sale_id'] ?? null);
        if ($saleId === null) {
            return null;
        }

        foreach (['sales_invoice', 'pos_ticket_fiscal_hook'] as $candidateType) {
            $matches = $this->repository->findBySource(
                (string) ($document['tenant_id'] ?? ''),
                'pos',
                'sale',
                $saleId,
                $this->nullableString($appId ?? $document['app_id'] ?? null),
                $candidateType,
                1
            );
            if ($matches === []) {
                continue;
            }

            $match = (array) $matches[0];
            return [
                'fiscal_document_id' => (string) ($match['id'] ?? ''),
                'document_type' => (string) ($match['document_type'] ?? ''),
                'document_number' => $this->nullableString($match['document_number'] ?? null),
                'status' => (string) ($match['status'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function duplicateBlockedFromDocument(array $document): bool
    {
        $metadata = is_array($document['metadata'] ?? null) ? (array) $document['metadata'] : [];
        $duplicate = is_array($metadata['duplicate_prevention'] ?? null) ? (array) $metadata['duplicate_prevention'] : [];

        return ($duplicate['duplicate_blocked'] ?? false) === true;
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
            'module' => 'fiscal',
            'action_name' => $actionName,
            'fiscal_document_id' => (string) ($document['id'] ?? ''),
            'source_module' => (string) ($document['source_module'] ?? ''),
            'source_entity_type' => (string) ($document['source_entity_type'] ?? ''),
            'source_entity_id' => (string) ($document['source_entity_id'] ?? ''),
            'document_type' => (string) ($document['document_type'] ?? ''),
            'fiscal_status' => (string) ($document['status'] ?? ''),
            'line_count' => count((array) ($document['lines'] ?? [])),
            'total' => $document['total'] === null ? null : (float) $document['total'],
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ];
        $this->auditLogger->log('fiscal_' . $actionName, 'fiscal_document', (string) ($document['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }
}
