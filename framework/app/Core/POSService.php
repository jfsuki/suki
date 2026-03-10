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
    public function resolveProductForPOS(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $productQuery = $this->productQuery($payload);
        $limit = max(1, min(10, (int) ($payload['limit'] ?? 5)));
        $matchedBy = '';
        $matchedProductId = '';
        $resolvedProduct = null;
        $candidates = [];
        $resultStatus = 'not_found';

        $productId = $this->nullableString($payload['product_id'] ?? null);
        if ($productId !== null) {
            $result = $this->entitySearch->getByReference($tenantId, 'product', $productId, [], $appId);
            if (is_array($result)) {
                $resolvedProduct = $this->hydrateProduct($tenantId, $appId, $result);
                $matchedBy = (string) ($resolvedProduct['matched_by'] ?? 'entity_search');
                $matchedProductId = (string) ($resolvedProduct['entity_id'] ?? '');
                $resultStatus = 'success';
            }
        } elseif ($productQuery !== '') {
            [$directCandidates, $lookupMode] = $this->directProductCandidates($tenantId, $appId, $productQuery, $limit, $payload);
            if ($directCandidates !== []) {
                $selected = $this->selectResolvedProductCandidate($directCandidates);
                if (is_array($selected)) {
                    $resolvedProduct = $this->hydrateResolvedProductCandidate($tenantId, $appId, $selected);
                    $matchedBy = (string) ($resolvedProduct['matched_by'] ?? '');
                    $matchedProductId = (string) ($resolvedProduct['entity_id'] ?? '');
                    $resultStatus = 'success';
                } else {
                    $candidates = array_slice($directCandidates, 0, $limit);
                    $resultStatus = 'clarification_required';
                }
            } elseif ($lookupMode !== 'barcode') {
                $fallback = $this->entitySearch->resolveBestMatch($tenantId, $productQuery, [
                    'entity_type' => 'product',
                    'limit' => max(2, $limit),
                ], $appId);
                if ((bool) ($fallback['resolved'] ?? false) && is_array($fallback['result'] ?? null)) {
                    $resolvedProduct = $this->hydrateProduct($tenantId, $appId, (array) $fallback['result']);
                    $matchedBy = 'entity_search';
                    $matchedProductId = (string) ($resolvedProduct['entity_id'] ?? '');
                    $resultStatus = 'success';
                } else {
                    $candidates = array_slice((array) ($fallback['candidates'] ?? []), 0, $limit);
                    if ($candidates !== []) {
                        $resultStatus = 'clarification_required';
                    }
                }
            }
        }

        $latencyMs = $this->latencyMs($startedAt);
        $ambiguityCount = $resolvedProduct === null ? count($candidates) : 0;
        $this->auditLogger->log('pos_find_product', 'product', $matchedProductId !== '' ? $matchedProductId : null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'pos',
            'action_name' => 'find_product',
            'draft_id' => $this->nullableString($payload['draft_id'] ?? $payload['sale_draft_id'] ?? null),
            'product_query' => $productQuery,
            'matched_product_id' => $matchedProductId,
            'product_id' => $matchedProductId,
            'matched_by' => $matchedBy,
            'ambiguity_count' => $ambiguityCount,
            'latency_ms' => $latencyMs,
            'result_status' => $resultStatus,
        ]);
        $this->eventLogger->log('find_product', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'find_product',
            'draft_id' => $this->nullableString($payload['draft_id'] ?? $payload['sale_draft_id'] ?? null),
            'product_query' => $productQuery,
            'matched_product_id' => $matchedProductId,
            'product_id' => $matchedProductId,
            'matched_by' => $matchedBy,
            'ambiguity_count' => $ambiguityCount,
            'latency_ms' => $latencyMs,
            'result_status' => $resultStatus,
        ]);

        return [
            'resolved' => is_array($resolvedProduct),
            'result' => $resolvedProduct,
            'candidates' => is_array($resolvedProduct) ? [] : $candidates,
            'result_count' => is_array($resolvedProduct) ? 1 : count($candidates),
            'product_query' => $productQuery,
            'matched_product_id' => $matchedProductId,
            'matched_by' => $matchedBy,
            'latency_ms' => $latencyMs,
            'result_status' => $resultStatus,
            'needs_clarification' => !is_array($resolvedProduct) && $candidates !== [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function getProductCandidatesForPOS(array $payload): array
    {
        $resolved = $this->resolveProductForPOS($payload);
        $items = [];
        if ((bool) ($resolved['resolved'] ?? false) && is_array($resolved['result'] ?? null)) {
            $items[] = (array) $resolved['result'];
        } elseif (is_array($resolved['candidates'] ?? null)) {
            $items = array_values((array) $resolved['candidates']);
        }
        $ambiguityCount = (bool) ($resolved['resolved'] ?? false) ? 0 : count($items);

        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $draftId = $this->nullableString($payload['draft_id'] ?? $payload['sale_draft_id'] ?? null);
        $this->auditLogger->log('pos_get_product_candidates', 'product', (string) ($resolved['matched_product_id'] ?? '') ?: null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'pos',
            'action_name' => 'get_product_candidates',
            'draft_id' => $draftId,
            'product_query' => (string) ($resolved['product_query'] ?? ''),
            'matched_product_id' => (string) ($resolved['matched_product_id'] ?? ''),
            'product_id' => (string) ($resolved['matched_product_id'] ?? ''),
            'matched_by' => (string) ($resolved['matched_by'] ?? ''),
            'ambiguity_count' => $ambiguityCount,
            'latency_ms' => (int) ($resolved['latency_ms'] ?? 0),
            'result_status' => (string) ($resolved['result_status'] ?? 'not_found'),
        ]);
        $this->eventLogger->log('get_product_candidates', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'get_product_candidates',
            'draft_id' => $draftId,
            'product_query' => (string) ($resolved['product_query'] ?? ''),
            'matched_product_id' => (string) ($resolved['matched_product_id'] ?? ''),
            'product_id' => (string) ($resolved['matched_product_id'] ?? ''),
            'matched_by' => (string) ($resolved['matched_by'] ?? ''),
            'ambiguity_count' => $ambiguityCount,
            'latency_ms' => (int) ($resolved['latency_ms'] ?? 0),
            'result_status' => (string) ($resolved['result_status'] ?? 'not_found'),
        ]);

        return [
            'query' => (string) ($resolved['product_query'] ?? ''),
            'items' => $items,
            'candidates' => $items,
            'result_count' => count($items),
            'matched_product_id' => (string) ($resolved['matched_product_id'] ?? ''),
            'matched_by' => (string) ($resolved['matched_by'] ?? ''),
            'latency_ms' => (int) ($resolved['latency_ms'] ?? 0),
            'result_status' => (string) ($resolved['result_status'] ?? 'not_found'),
            'needs_clarification' => (bool) ($resolved['needs_clarification'] ?? false),
            'resolved' => (bool) ($resolved['resolved'] ?? false),
            'result' => is_array($resolved['result'] ?? null) ? (array) $resolved['result'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addLineToDraft(array $payload): array
    {
        return $this->addLineByProductReference($payload + ['action_name' => 'add_draft_line']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addLineByProductReference(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $draftId = $this->requireString($payload['draft_id'] ?? $payload['sale_draft_id'] ?? null, 'draft_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $draft = $this->loadEditableDraft($tenantId, $draftId, $appId);
        $qty = $this->quantity($payload['qty'] ?? $payload['quantity'] ?? 1);
        $product = $this->resolveProduct($tenantId, $appId, $payload);
        $taxRate = $this->nullableDecimal($payload['tax_rate'] ?? null);
        if ($taxRate === null && array_key_exists('tax_rate', $product)) {
            $taxRate = $this->nullableDecimal($product['tax_rate']);
        }

        $pricing = $this->calculateLinePricing(
            $qty,
            $payload['base_price'] ?? $product['base_price'] ?? $product['unit_price'] ?? null,
            $payload['override_price'] ?? null,
            $taxRate
        );

        $line = $this->repository->insertLine([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'sale_draft_id' => $draftId,
            'product_id' => (string) ($product['entity_id'] ?? ''),
            'sku' => $this->nullableString($product['sku'] ?? null),
            'barcode' => $this->nullableString($product['barcode'] ?? null),
            'product_label' => trim((string) ($product['label'] ?? 'producto')) ?: 'producto',
            'qty' => $qty,
            'base_price' => $pricing['base_price'],
            'override_price' => $pricing['override_price'],
            'effective_unit_price' => $pricing['effective_unit_price'],
            'unit_price' => $pricing['unit_price'],
            'line_subtotal' => $pricing['line_subtotal'],
            'tax_rate' => $pricing['tax_rate'],
            'line_tax' => $pricing['line_tax'],
            'line_total' => $pricing['line_total'],
            'metadata' => [
                'resolved_product' => [
                    'matched_by' => (string) ($product['matched_by'] ?? ''),
                    'source_module' => (string) ($product['source_module'] ?? 'pos'),
                    'entity_contract' => (string) (($product['metadata_json']['entity_contract'] ?? $product['metadata']['entity_contract'] ?? '')),
                ],
                'pricing' => [
                    'base_price' => $pricing['base_price'],
                    'override_price' => $pricing['override_price'],
                    'effective_unit_price' => $pricing['effective_unit_price'],
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
        $actionName = (string) ($payload['action_name'] ?? 'add_line_by_reference');
        $this->auditLogger->log('pos_' . $actionName, 'sale_draft', $draftId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'product_id' => (string) ($line['product_id'] ?? ''),
            'matched_product_id' => (string) ($line['product_id'] ?? ''),
            'product_query' => $this->productQuery($payload),
            'matched_by' => (string) ($product['matched_by'] ?? ''),
            'ambiguity_count' => 0,
            'action_name' => $actionName,
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log($actionName, $tenantId, [
            'app_id' => $appId,
            'action_name' => $actionName,
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'product_id' => (string) ($line['product_id'] ?? ''),
            'matched_product_id' => (string) ($line['product_id'] ?? ''),
            'product_query' => $this->productQuery($payload),
            'matched_by' => (string) ($product['matched_by'] ?? ''),
            'ambiguity_count' => 0,
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
        $this->applyDraftLineChanges($tenantId, $draftId, $appId, $payload);
        $lines = $this->repository->listLines($tenantId, $draftId, $appId);
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $recalculatedLines = [];

        foreach ($lines as $line) {
            $pricing = $this->calculateLinePricing(
                $this->quantity($line['qty'] ?? 1),
                $line['base_price'] ?? $line['effective_unit_price'] ?? $line['unit_price'] ?? null,
                $line['override_price'] ?? null,
                $line['tax_rate'] ?? null
            );
            $updatedLine = $this->repository->updateLine($tenantId, $draftId, (string) ($line['id'] ?? ''), [
                'qty' => $this->quantity($line['qty'] ?? 1),
                'base_price' => $pricing['base_price'],
                'override_price' => $pricing['override_price'],
                'effective_unit_price' => $pricing['effective_unit_price'],
                'unit_price' => $pricing['unit_price'],
                'line_subtotal' => $pricing['line_subtotal'],
                'tax_rate' => $pricing['tax_rate'],
                'line_tax' => $pricing['line_tax'],
                'line_total' => $pricing['line_total'],
                'metadata' => array_merge(
                    is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [],
                    [
                        'pricing' => [
                            'base_price' => $pricing['base_price'],
                            'override_price' => $pricing['override_price'],
                            'effective_unit_price' => $pricing['effective_unit_price'],
                        ],
                    ]
                ),
            ], $appId);
            if (!is_array($updatedLine)) {
                throw new RuntimeException('POS_DRAFT_LINE_NOT_FOUND');
            }
            $recalculatedLines[] = $updatedLine;
            $subtotal += (float) ($updatedLine['line_subtotal'] ?? 0);
            $taxTotal += (float) ($updatedLine['line_tax'] ?? 0);
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

        $draft['lines'] = $recalculatedLines;

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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function repriceDraft(string $tenantId, string $draftId, ?string $appId = null, array $payload = []): array
    {
        $startedAt = microtime(true);
        $draft = $this->loadEditableDraft($tenantId, $draftId, $appId);
        $draft = $this->recalculateDraftTotals($tenantId, $draftId, $appId, $payload);
        $latencyMs = $this->latencyMs($startedAt);
        $lineId = $this->nullableString($payload['line_id'] ?? $payload['id'] ?? null);
        $updatedLine = $lineId !== null ? $this->repository->findLine($tenantId, $draftId, $lineId, $appId) : null;

        $this->auditLogger->log('pos_reprice_draft', 'sale_draft', $draftId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'pos',
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'product_id' => (string) (($updatedLine['product_id'] ?? '') ?: ''),
            'matched_product_id' => (string) (($updatedLine['product_id'] ?? '') ?: ''),
            'product_query' => $this->productQuery($payload),
            'matched_by' => (string) (($updatedLine['metadata']['resolved_product']['matched_by'] ?? '') ?: ''),
            'ambiguity_count' => 0,
            'action_name' => 'reprice_draft',
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('reprice_draft', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'reprice_draft',
            'draft_id' => $draftId,
            'session_id' => $draft['session_id'] ?? null,
            'product_id' => (string) (($updatedLine['product_id'] ?? '') ?: ''),
            'matched_product_id' => (string) (($updatedLine['product_id'] ?? '') ?: ''),
            'product_query' => $this->productQuery($payload),
            'matched_by' => (string) (($updatedLine['metadata']['resolved_product']['matched_by'] ?? '') ?: ''),
            'ambiguity_count' => 0,
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return $draft;
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
        if ($this->nullableString($payload['product_id'] ?? null) === null && $this->productQuery($payload) === '') {
            throw new RuntimeException('POS_PRODUCT_REFERENCE_REQUIRED');
        }

        $resolution = $this->resolveProductForPOS($payload + [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
        ]);
        if ((bool) ($resolution['resolved'] ?? false) && is_array($resolution['result'] ?? null)) {
            return (array) $resolution['result'];
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
     * @param array<string, mixed> $resolved
     * @return array<string, mixed>
     */
    private function hydrateResolvedProductCandidate(string $tenantId, ?string $appId, array $resolved): array
    {
        $metadata = is_array($resolved['metadata_json'] ?? null) ? (array) $resolved['metadata_json'] : [];
        if ((bool) ($metadata['pos_snapshot_ready'] ?? false)) {
            $unitPrice = $this->nullableDecimal($metadata['unit_price'] ?? null);
            $taxRate = $this->nullableDecimal($metadata['tax_rate'] ?? null);

            return array_merge($resolved, [
                'sku' => $this->nullableString($metadata['sku'] ?? null),
                'barcode' => $this->nullableString($metadata['barcode'] ?? null),
                'base_price' => $unitPrice,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'metadata' => [
                    'entity_contract' => (string) ($metadata['entity_contract'] ?? ''),
                    'table' => (string) ($metadata['table'] ?? ''),
                    'matched_by' => (string) ($resolved['matched_by'] ?? ''),
                    'source_module' => (string) ($resolved['source_module'] ?? 'pos'),
                    'raw_identifier' => (string) ($metadata['raw_identifier'] ?? ''),
                ],
            ]);
        }

        return $this->hydrateProduct($tenantId, $appId, $resolved);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0:array<int, array<string, mixed>>,1:string}
     */
    private function directProductCandidates(string $tenantId, ?string $appId, string $query, int $limit, array $payload): array
    {
        $lookupMode = $this->productLookupMode($payload, $query);
        if ($lookupMode === 'barcode') {
            return [
                $this->repository->searchProductsForPOS($tenantId, $query, ['mode' => 'barcode', 'limit' => $limit], $appId, $this->entityRegistry),
                'barcode',
            ];
        }
        if ($lookupMode === 'sku') {
            return [
                $this->repository->searchProductsForPOS($tenantId, $query, ['mode' => 'sku', 'limit' => $limit], $appId, $this->entityRegistry),
                'sku',
            ];
        }

        foreach (['barcode', 'sku', 'exact_name', 'partial'] as $mode) {
            $results = $this->repository->searchProductsForPOS($tenantId, $query, ['mode' => $mode, 'limit' => $limit], $appId, $this->entityRegistry);
            if ($results !== []) {
                return [$results, $mode];
            }
        }

        return [[], $lookupMode];
    }

    private function productLookupMode(array $payload, string $query): string
    {
        if ($this->nullableString($payload['barcode'] ?? null) !== null || $this->isBarcodeLike($query)) {
            return 'barcode';
        }
        if ($this->nullableString($payload['sku'] ?? null) !== null) {
            return 'sku';
        }

        return 'auto';
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>|null
     */
    private function selectResolvedProductCandidate(array $candidates): ?array
    {
        $top = is_array($candidates[0] ?? null) ? (array) $candidates[0] : [];
        if ($top === []) {
            return null;
        }

        $next = is_array($candidates[1] ?? null) ? (array) $candidates[1] : [];
        $matchedBy = trim((string) ($top['matched_by'] ?? ''));
        if (count($candidates) === 1) {
            return $top;
        }
        if (in_array($matchedBy, ['barcode', 'sku'], true) && trim((string) ($next['matched_by'] ?? '')) !== $matchedBy) {
            return $top;
        }
        if ($matchedBy === 'exact_name' && trim((string) ($next['matched_by'] ?? '')) !== 'exact_name') {
            return $top;
        }

        return null;
    }

    private function isBarcodeLike(string $value): bool
    {
        return preg_match('/^[0-9]{8,20}$/', trim($value)) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyDraftLineChanges(string $tenantId, string $draftId, ?string $appId, array $payload): void
    {
        $updates = [];
        if (is_array($payload['lines'] ?? null)) {
            foreach ((array) $payload['lines'] as $candidate) {
                if (is_array($candidate)) {
                    $updates[] = $candidate;
                }
            }
        }

        $hasSingleUpdate = $this->nullableString($payload['line_id'] ?? $payload['id'] ?? null) !== null
            || array_key_exists('qty', $payload)
            || array_key_exists('quantity', $payload)
            || array_key_exists('override_price', $payload)
            || array_key_exists('base_price', $payload)
            || array_key_exists('tax_rate', $payload);
        if ($hasSingleUpdate) {
            $updates[] = $payload;
        }

        foreach ($updates as $update) {
            $lineId = $this->nullableString($update['line_id'] ?? $update['id'] ?? null);
            if ($lineId === null) {
                throw new RuntimeException('POS_LINE_ID_REQUIRED');
            }

            $line = $this->repository->findLine($tenantId, $draftId, $lineId, $appId);
            if (!is_array($line)) {
                throw new RuntimeException('POS_DRAFT_LINE_NOT_FOUND');
            }

            $qty = array_key_exists('qty', $update) || array_key_exists('quantity', $update)
                ? $this->quantity($update['qty'] ?? $update['quantity'])
                : $this->quantity($line['qty'] ?? 1);
            $basePrice = array_key_exists('base_price', $update)
                ? $update['base_price']
                : ($line['base_price'] ?? $line['effective_unit_price'] ?? $line['unit_price'] ?? null);
            $overridePrice = array_key_exists('override_price', $update)
                ? $update['override_price']
                : ($line['override_price'] ?? null);
            $taxRate = array_key_exists('tax_rate', $update)
                ? $update['tax_rate']
                : ($line['tax_rate'] ?? null);
            $pricing = $this->calculateLinePricing($qty, $basePrice, $overridePrice, $taxRate);

            $this->repository->updateLine($tenantId, $draftId, $lineId, [
                'qty' => $qty,
                'base_price' => $pricing['base_price'],
                'override_price' => $pricing['override_price'],
                'effective_unit_price' => $pricing['effective_unit_price'],
                'unit_price' => $pricing['unit_price'],
                'line_subtotal' => $pricing['line_subtotal'],
                'tax_rate' => $pricing['tax_rate'],
                'line_tax' => $pricing['line_tax'],
                'line_total' => $pricing['line_total'],
                'metadata' => array_merge(
                    is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [],
                    [
                        'pricing' => [
                            'base_price' => $pricing['base_price'],
                            'override_price' => $pricing['override_price'],
                            'effective_unit_price' => $pricing['effective_unit_price'],
                        ],
                    ]
                ),
            ], $appId);
        }
    }

    /**
     * @param mixed $basePriceInput
     * @param mixed $overridePriceInput
     * @param mixed $taxRateInput
     * @return array<string, mixed>
     */
    private function calculateLinePricing(float $qty, $basePriceInput, $overridePriceInput, $taxRateInput): array
    {
        $basePrice = $this->optionalPrice($basePriceInput, 'POS_PRODUCT_PRICE_UNAVAILABLE');
        $overridePrice = $this->optionalPrice($overridePriceInput, 'POS_OVERRIDE_PRICE_INVALID');
        if ($basePrice === null) {
            $basePrice = $overridePrice;
        }
        if ($basePrice === null) {
            throw new RuntimeException('POS_PRODUCT_PRICE_UNAVAILABLE');
        }

        $effectiveUnitPrice = $overridePrice ?? $basePrice;
        $taxRate = $this->nullableDecimal($taxRateInput);
        $lineSubtotal = $this->money($qty * $effectiveUnitPrice);
        $lineTax = $taxRate !== null ? $this->money($lineSubtotal * ($taxRate / 100)) : null;

        return [
            'base_price' => $basePrice,
            'override_price' => $overridePrice,
            'effective_unit_price' => $effectiveUnitPrice,
            'unit_price' => $effectiveUnitPrice,
            'tax_rate' => $taxRate,
            'line_subtotal' => $lineSubtotal,
            'line_tax' => $lineTax,
            'line_total' => $this->money($lineSubtotal + ($lineTax ?? 0)),
        ];
    }

    /**
     * @param mixed $value
     */
    private function optionalPrice($value, string $errorCode): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException($errorCode);
        }

        return $this->money((float) $value);
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
