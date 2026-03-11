<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function finalizeDraftSale(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $draftId = $this->requireString($payload['draft_id'] ?? $payload['sale_draft_id'] ?? null, 'draft_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $createdByUserId = $this->nullableString(
            $payload['created_by_user_id']
            ?? $payload['requested_by_user_id']
            ?? $payload['user_id']
            ?? null
        );

        $existingSale = $this->repository->findSaleByDraftId($tenantId, $draftId, $appId);
        if (is_array($existingSale)) {
            throw new RuntimeException('POS_DRAFT_ALREADY_FINALIZED');
        }

        $draft = $this->loadDraft($tenantId, $draftId, $appId);
        if ((string) ($draft['status'] ?? '') !== 'open') {
            if ($this->draftAlreadyFinalized($draft)) {
                throw new RuntimeException('POS_DRAFT_ALREADY_FINALIZED');
            }
            throw new RuntimeException('POS_DRAFT_NOT_OPEN');
        }

        $draft = $this->recalculateDraftTotals($tenantId, $draftId, $appId);
        $lines = is_array($draft['lines'] ?? null) ? (array) $draft['lines'] : [];
        if ($lines === []) {
            throw new RuntimeException('POS_DRAFT_EMPTY');
        }
        if (!$this->draftTotalsAreValid($draft, $lines)) {
            throw new RuntimeException('POS_DRAFT_TOTALS_INVALID');
        }

        $finalizedAt = date('Y-m-d H:i:s');
        $result = $this->repository->transaction(function () use (
            $tenantId,
            $draftId,
            $appId,
            $draft,
            $lines,
            $finalizedAt,
            $createdByUserId,
            $payload
        ): array {
            $currentSale = $this->repository->findSaleByDraftId($tenantId, $draftId, $appId);
            if (is_array($currentSale)) {
                throw new RuntimeException('POS_DRAFT_ALREADY_FINALIZED');
            }

            $currentDraft = $this->loadDraft($tenantId, $draftId, $appId);
            if ((string) ($currentDraft['status'] ?? '') !== 'open') {
                if ($this->draftAlreadyFinalized($currentDraft)) {
                    throw new RuntimeException('POS_DRAFT_ALREADY_FINALIZED');
                }
                throw new RuntimeException('POS_DRAFT_NOT_OPEN');
            }

            $sale = $this->repository->createSale([
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'session_id' => $currentDraft['session_id'] ?? null,
                'draft_id' => $draftId,
                'customer_id' => $currentDraft['customer_id'] ?? null,
                'sale_number' => null,
                'status' => 'completed',
                'currency' => $currentDraft['currency'] ?? null,
                'subtotal' => $currentDraft['subtotal'] ?? 0,
                'tax_total' => $currentDraft['tax_total'] ?? 0,
                'total' => $currentDraft['total'] ?? 0,
                'created_by_user_id' => $createdByUserId,
                'metadata' => $this->buildSaleMetadata($currentDraft, $payload, $finalizedAt),
                'created_at' => $finalizedAt,
                'updated_at' => $finalizedAt,
            ]);

            $saleId = $this->requireString($sale['id'] ?? null, 'sale_id');
            $saleNumber = $this->buildSaleNumber($tenantId, $saleId, $finalizedAt);
            $sale = $this->repository->updateSale($tenantId, $saleId, [
                'sale_number' => $saleNumber,
                'metadata' => array_merge(
                    is_array($sale['metadata'] ?? null) ? (array) $sale['metadata'] : [],
                    [
                        'receipt' => [
                            'status' => 'ready',
                            'contract_id' => 'ticket_pos',
                        ],
                    ]
                ),
                'updated_at' => $finalizedAt,
            ], $appId);
            if (!is_array($sale)) {
                throw new RuntimeException('POS_SALE_NOT_FOUND');
            }

            foreach ($lines as $line) {
                $this->repository->insertSaleLine([
                    'tenant_id' => $tenantId,
                    'app_id' => $appId,
                    'sale_id' => $saleId,
                    'product_id' => (string) ($line['product_id'] ?? ''),
                    'sku' => $this->nullableString($line['sku'] ?? null),
                    'barcode' => $this->nullableString($line['barcode'] ?? null),
                    'product_label' => trim((string) ($line['product_label'] ?? 'producto')) ?: 'producto',
                    'qty' => $line['qty'] ?? 0,
                    'unit_price' => $line['unit_price'] ?? $line['effective_unit_price'] ?? 0,
                    'tax_rate' => $line['tax_rate'] ?? null,
                    'line_total' => $line['line_total'] ?? 0,
                    'metadata' => $this->buildSaleLineMetadata($line),
                    'created_at' => $finalizedAt,
                    'updated_at' => $finalizedAt,
                ]);
            }

            $updatedDraft = $this->repository->updateDraft($tenantId, $draftId, [
                'status' => 'checked_out',
                'metadata' => array_merge(
                    is_array($currentDraft['metadata'] ?? null) ? (array) $currentDraft['metadata'] : [],
                    [
                        'finalized_sale_id' => $saleId,
                        'sale_number' => $saleNumber,
                        'finalized_at' => $finalizedAt,
                        'hooks' => [
                            'inventory' => 'pending',
                            'fiscal_engine' => 'pending',
                            'cash_movement' => 'pending',
                            'receipt' => 'ready',
                        ],
                    ]
                ),
                'updated_at' => $finalizedAt,
            ], $appId);
            if (!is_array($updatedDraft)) {
                throw new RuntimeException('POS_DRAFT_NOT_FOUND');
            }

            $sale = $this->repository->loadSaleAggregate($tenantId, $saleId, $appId);
            if (!is_array($sale)) {
                throw new RuntimeException('POS_SALE_NOT_FOUND');
            }

            return [
                'sale' => $sale,
                'draft' => $updatedDraft,
            ];
        });

        $sale = $this->validatedSale(is_array($result['sale'] ?? null) ? (array) $result['sale'] : []);
        $draft = $this->validatedDraft(is_array($result['draft'] ?? null) ? (array) $result['draft'] : []);
        $latencyMs = $this->latencyMs($startedAt);
        $lineCount = count((array) ($sale['lines'] ?? []));
        $saleId = (string) ($sale['id'] ?? '');
        $saleNumber = (string) ($sale['sale_number'] ?? '');

        $this->auditLogger->log('pos_finalize_sale', 'pos_sale', $saleId !== '' ? $saleId : null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'pos',
            'action_name' => 'finalize_sale',
            'draft_id' => $draftId,
            'sale_id' => $saleId,
            'sale_number' => $saleNumber,
            'session_id' => $sale['session_id'] ?? null,
            'line_count' => $lineCount,
            'total' => (float) ($sale['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('finalize_sale', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'finalize_sale',
            'draft_id' => $draftId,
            'sale_id' => $saleId,
            'sale_number' => $saleNumber,
            'session_id' => $sale['session_id'] ?? null,
            'line_count' => $lineCount,
            'total' => (float) ($sale['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return [
            'sale' => $sale,
            'draft' => $draft,
            'sale_id' => $saleId,
            'sale_number' => $saleNumber,
            'line_count' => $lineCount,
            'hooks' => is_array($sale['metadata']['hooks'] ?? null) ? (array) $sale['metadata']['hooks'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSale(string $tenantId, string $saleId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $sale = $this->loadSale($tenantId, $saleId, $appId);
        $latencyMs = $this->latencyMs($startedAt);

        $this->auditLogger->log('pos_get_sale', 'pos_sale', $saleId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'pos',
            'action_name' => 'get_sale',
            'draft_id' => $sale['draft_id'] ?? null,
            'sale_id' => $saleId,
            'sale_number' => $sale['sale_number'] ?? null,
            'session_id' => $sale['session_id'] ?? null,
            'line_count' => count((array) ($sale['lines'] ?? [])),
            'total' => (float) ($sale['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('get_sale', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'get_sale',
            'draft_id' => $sale['draft_id'] ?? null,
            'sale_id' => $saleId,
            'sale_number' => $sale['sale_number'] ?? null,
            'session_id' => $sale['session_id'] ?? null,
            'line_count' => count((array) ($sale['lines'] ?? [])),
            'total' => (float) ($sale['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return $sale;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listSales(string $tenantId, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 10)));
        $items = $this->repository->listSales($tenantId, $filters + ['app_id' => $appId], $limit);
        $sales = [];
        foreach ($items as $item) {
            $saleId = (string) ($item['id'] ?? '');
            $sale = $saleId !== '' ? $this->repository->loadSaleAggregate($tenantId, $saleId, $appId) : null;
            if (!is_array($sale)) {
                continue;
            }
            $sales[] = $this->validatedSale($sale);
        }
        $latencyMs = $this->latencyMs($startedAt);

        $this->auditLogger->log('pos_list_sales', 'pos_sale', null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'pos',
            'action_name' => 'list_sales',
            'session_id' => $this->nullableString($filters['session_id'] ?? null),
            'line_count' => array_sum(array_map(fn(array $sale): int => count((array) ($sale['lines'] ?? [])), $sales)),
            'total' => array_sum(array_map(fn(array $sale): float => (float) ($sale['total'] ?? 0), $sales)),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('list_sales', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'list_sales',
            'session_id' => $this->nullableString($filters['session_id'] ?? null),
            'result_count' => count($sales),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return $sales;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReceiptPayload(string $tenantId, string $saleId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $sale = $this->loadSale($tenantId, $saleId, $appId);
        $createdAt = $this->saleDateTime((string) ($sale['created_at'] ?? ''));
        $session = $this->nullableString($sale['session_id'] ?? null) !== null
            ? $this->repository->findSession($tenantId, (string) $sale['session_id'], $appId)
            : null;
        $customerLabel = $this->resolveSaleCustomerLabel($tenantId, $sale, $appId);
        $footerText = $this->ticketFooterText();

        $items = array_map(function (array $line): array {
            return [
                'product_id' => (string) ($line['product_id'] ?? ''),
                'sku' => $this->nullableString($line['sku'] ?? null),
                'barcode' => $this->nullableString($line['barcode'] ?? null),
                'product_label' => trim((string) ($line['product_label'] ?? 'producto')) ?: 'producto',
                'qty' => (float) ($line['qty'] ?? 0),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'line_total' => (float) ($line['line_total'] ?? 0),
                'metadata' => is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [],
            ];
        }, (array) ($sale['lines'] ?? []));

        $payload = [
            'sale_id' => (string) ($sale['id'] ?? ''),
            'sale_number' => (string) ($sale['sale_number'] ?? ''),
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'created_at' => (string) ($sale['created_at'] ?? ''),
            'currency' => $this->nullableString($sale['currency'] ?? null),
            'header' => [
                'sale_number' => (string) ($sale['sale_number'] ?? ''),
                'date' => $createdAt->format('Y-m-d'),
                'time' => $createdAt->format('H:i:s'),
                'customer_label' => $customerLabel,
                'cash_register_id' => is_array($session) ? $this->nullableString($session['cash_register_id'] ?? null) : null,
                'store_id' => is_array($session) ? $this->nullableString($session['store_id'] ?? null) : null,
            ],
            'items' => $items,
            'totals' => [
                'subtotal' => (float) ($sale['subtotal'] ?? 0),
                'tax_total' => (float) ($sale['tax_total'] ?? 0),
                'total' => (float) ($sale['total'] ?? 0),
            ],
            'footer_hooks' => [
                'contract_id' => 'ticket_pos',
                'footer_text' => $footerText,
                'printer' => 'pending',
                'fiscal_engine' => 'pending',
                'cash_movement' => 'pending',
                'inventory' => 'pending',
            ],
        ];
        $payload['printable_text'] = $this->buildPrintableReceiptText($payload);

        POSContractValidator::validateReceipt($payload);

        $latencyMs = $this->latencyMs($startedAt);
        $this->auditLogger->log('pos_build_receipt', 'pos_sale', (string) ($sale['id'] ?? ''), [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'pos',
            'action_name' => 'build_receipt',
            'draft_id' => $sale['draft_id'] ?? null,
            'sale_id' => $sale['id'] ?? null,
            'sale_number' => $sale['sale_number'] ?? null,
            'session_id' => $sale['session_id'] ?? null,
            'line_count' => count((array) ($sale['lines'] ?? [])),
            'total' => (float) ($sale['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('build_receipt', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'build_receipt',
            'draft_id' => $sale['draft_id'] ?? null,
            'sale_id' => $sale['id'] ?? null,
            'sale_number' => $sale['sale_number'] ?? null,
            'session_id' => $sale['session_id'] ?? null,
            'line_count' => count((array) ($sale['lines'] ?? [])),
            'total' => (float) ($sale['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSaleByNumber(string $tenantId, string $saleNumber, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $saleNumber = $this->requireString($saleNumber, 'sale_number');
        $sale = $this->repository->findSaleByNumber($tenantId, $saleNumber, $appId);
        if (!is_array($sale)) {
            throw new RuntimeException('POS_SALE_NOT_FOUND');
        }

        $loaded = $this->loadSale($tenantId, (string) ($sale['id'] ?? ''), $appId);
        $latencyMs = $this->latencyMs($startedAt);

        $this->auditLogger->log('pos_get_sale_by_number', 'pos_sale', (string) ($loaded['id'] ?? ''), [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'pos',
            'action_name' => 'get_sale_by_number',
            'draft_id' => $loaded['draft_id'] ?? null,
            'sale_id' => $loaded['id'] ?? null,
            'sale_number' => $loaded['sale_number'] ?? null,
            'session_id' => $loaded['session_id'] ?? null,
            'line_count' => count((array) ($loaded['lines'] ?? [])),
            'total' => (float) ($loaded['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);
        $this->eventLogger->log('get_sale_by_number', $tenantId, [
            'app_id' => $appId,
            'action_name' => 'get_sale_by_number',
            'draft_id' => $loaded['draft_id'] ?? null,
            'sale_id' => $loaded['id'] ?? null,
            'sale_number' => $loaded['sale_number'] ?? null,
            'session_id' => $loaded['session_id'] ?? null,
            'line_count' => count((array) ($loaded['lines'] ?? [])),
            'total' => (float) ($loaded['total'] ?? 0),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ]);

        return $loaded;
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
    private function loadSale(string $tenantId, string $saleId, ?string $appId): array
    {
        $sale = $this->repository->loadSaleAggregate($tenantId, $saleId, $appId);
        if (!is_array($sale)) {
            throw new RuntimeException('POS_SALE_NOT_FOUND');
        }

        return $this->validatedSale($sale);
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
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildSaleMetadata(array $draft, array $payload, string $finalizedAt): array
    {
        $customerResolution = is_array($draft['metadata']['customer_resolution'] ?? null)
            ? (array) $draft['metadata']['customer_resolution']
            : [];

        return [
            'origin' => [
                'source' => 'pos_draft',
                'draft_id' => (string) ($draft['id'] ?? ''),
                'ecommerce_origin' => $this->nullableString($payload['origin'] ?? $payload['ecommerce_origin'] ?? null),
            ],
            'customer_snapshot' => [
                'label' => $this->nullableString($customerResolution['label'] ?? null),
            ],
            'hooks' => [
                'inventory' => 'pending',
                'fiscal_engine' => 'pending',
                'cash_movement' => 'pending',
                'receipt' => 'pending',
            ],
            'finalized_at' => $finalizedAt,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function buildSaleLineMetadata(array $line): array
    {
        return array_merge(
            is_array($line['metadata'] ?? null) ? (array) $line['metadata'] : [],
            [
                'draft_line_id' => (string) ($line['id'] ?? ''),
                'pricing_snapshot' => [
                    'base_price' => $line['base_price'] ?? $line['unit_price'] ?? null,
                    'override_price' => $line['override_price'] ?? null,
                    'effective_unit_price' => $line['effective_unit_price'] ?? $line['unit_price'] ?? null,
                    'line_subtotal' => $line['line_subtotal'] ?? null,
                    'line_tax' => $line['line_tax'] ?? null,
                ],
                'hooks' => [
                    'inventory' => 'pending',
                    'receipt' => 'ready',
                    'fiscal_engine' => 'pending',
                ],
            ]
        );
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
            $subtotal += (float) ($line['line_subtotal'] ?? 0);
            $taxTotal += (float) ($line['line_tax'] ?? 0);
        }

        return abs($this->money($subtotal) - (float) ($draft['subtotal'] ?? 0)) < 0.0001
            && abs($this->money($taxTotal) - (float) ($draft['tax_total'] ?? 0)) < 0.0001
            && abs($this->money($subtotal + $taxTotal) - (float) ($draft['total'] ?? 0)) < 0.0001;
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function draftAlreadyFinalized(array $draft): bool
    {
        $metadata = is_array($draft['metadata'] ?? null) ? (array) $draft['metadata'] : [];

        return trim((string) ($draft['status'] ?? '')) === 'checked_out'
            || $this->nullableString($metadata['finalized_sale_id'] ?? null) !== null
            || $this->nullableString($metadata['sale_number'] ?? null) !== null;
    }

    private function buildSaleNumber(string $tenantId, string $saleId, string $createdAt): string
    {
        $prefix = strtoupper(substr((preg_replace('/[^A-Za-z0-9]/', '', $tenantId) ?? ''), 0, 6));
        if ($prefix === '') {
            $prefix = (string) $this->stableTenantInt($tenantId);
        }
        $date = $this->saleDateTime($createdAt)->format('Ymd');
        $serial = ctype_digit($saleId) ? str_pad($saleId, 6, '0', STR_PAD_LEFT) : strtoupper($saleId);

        return 'POS-' . $prefix . '-' . $date . '-' . $serial;
    }

    private function saleDateTime(string $value): DateTimeImmutable
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
     * @param array<string, mixed> $sale
     */
    private function resolveSaleCustomerLabel(string $tenantId, array $sale, ?string $appId): ?string
    {
        $metadata = is_array($sale['metadata'] ?? null) ? (array) $sale['metadata'] : [];
        $customerSnapshot = is_array($metadata['customer_snapshot'] ?? null) ? (array) $metadata['customer_snapshot'] : [];
        $customerLabel = $this->nullableString($customerSnapshot['label'] ?? null);
        if ($customerLabel !== null) {
            return $customerLabel;
        }

        $customerId = $this->nullableString($sale['customer_id'] ?? null);
        if ($customerId === null) {
            return null;
        }

        $result = $this->entitySearch->getByReference($tenantId, 'customer', $customerId, [], $appId);
        if (!is_array($result)) {
            return null;
        }

        return $this->nullableString($result['label'] ?? null);
    }

    private function ticketFooterText(): string
    {
        $path = FRAMEWORK_ROOT . '/contracts/forms/ticket_pos.contract.json';
        if (!is_file($path)) {
            return 'Gracias por su compra.';
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload)) {
            return 'Gracias por su compra.';
        }

        foreach ((array) ($payload['reports'] ?? []) as $report) {
            if (!is_array($report)) {
                continue;
            }
            $text = trim((string) ($report['layout']['footer']['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return 'Gracias por su compra.';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildPrintableReceiptText(array $payload): string
    {
        $lines = [];
        $header = is_array($payload['header'] ?? null) ? (array) $payload['header'] : [];
        $totals = is_array($payload['totals'] ?? null) ? (array) $payload['totals'] : [];
        $footerHooks = is_array($payload['footer_hooks'] ?? null) ? (array) $payload['footer_hooks'] : [];

        $lines[] = 'TICKET POS';
        $lines[] = 'Venta ' . (string) ($payload['sale_number'] ?? '');
        $lines[] = (string) ($header['date'] ?? '') . ' ' . (string) ($header['time'] ?? '');
        if (trim((string) ($header['customer_label'] ?? '')) !== '') {
            $lines[] = 'Cliente: ' . (string) $header['customer_label'];
        }
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lines[] = trim((string) ($item['product_label'] ?? 'producto')) ?: 'producto';
            $lines[] = '  '
                . $this->formatMoneyText((float) ($item['qty'] ?? 0), 0, false)
                . ' x '
                . $this->formatMoneyText((float) ($item['unit_price'] ?? 0))
                . ' = '
                . $this->formatMoneyText((float) ($item['line_total'] ?? 0));
        }
        $lines[] = 'Subtotal: ' . $this->formatMoneyText((float) ($totals['subtotal'] ?? 0));
        $lines[] = 'Impuesto: ' . $this->formatMoneyText((float) ($totals['tax_total'] ?? 0));
        $lines[] = 'TOTAL: ' . $this->formatMoneyText((float) ($totals['total'] ?? 0));
        if (trim((string) ($footerHooks['footer_text'] ?? '')) !== '') {
            $lines[] = (string) $footerHooks['footer_text'];
        }

        return implode("\n", $lines);
    }

    private function formatMoneyText(float $value, int $decimals = 2, bool $money = true): string
    {
        $formatted = number_format($value, $decimals, '.', '');

        return $money ? '$' . $formatted : $formatted;
    }

    private function stableTenantInt(string $tenantId): int
    {
        $hash = crc32((string) $tenantId);
        $unsigned = (int) sprintf('%u', $hash);
        $max = 2147483647;
        $value = $unsigned % $max;

        return $value > 0 ? $value : 1;
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

    /**
     * @param array<string, mixed> $sale
     * @return array<string, mixed>
     */
    private function validatedSale(array $sale): array
    {
        if (!array_key_exists('lines', $sale)) {
            $sale['lines'] = [];
        }
        if (!array_key_exists('metadata', $sale) && array_key_exists('metadata_json', $sale)) {
            $sale['metadata'] = is_array($sale['metadata_json']) ? (array) $sale['metadata_json'] : [];
        }

        POSContractValidator::validateSale($sale);

        return $sale;
    }
}
