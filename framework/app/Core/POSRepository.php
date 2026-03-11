<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;

final class POSRepository
{
    private const SESSION_TABLE = 'pos_sessions';
    private const DRAFT_TABLE = 'sale_drafts';
    private const LINE_TABLE = 'sale_draft_lines';
    private const SALE_TABLE = 'pos_sales';
    private const SALE_LINE_TABLE = 'pos_sale_lines';

    /** @var array<int, string> */
    private const PRODUCT_LABEL_FIELDS = ['nombre', 'name', 'descripcion', 'description', 'titulo', 'title'];

    /** @var array<int, string> */
    private const PRODUCT_SKU_FIELDS = ['sku', 'codigo', 'code', 'referencia', 'reference'];

    /** @var array<int, string> */
    private const PRODUCT_BARCODE_FIELDS = ['barcode', 'codigo_barras', 'ean', 'upc'];

    /** @var array<int, string> */
    private const PRODUCT_PRICE_FIELDS = ['precio_venta', 'sale_price', 'price', 'precio', 'unit_price', 'valor'];

    /** @var array<int, string> */
    private const PRODUCT_TAX_FIELDS = ['tax_rate', 'iva_rate', 'iva', 'impuesto', 'tax_percent'];

    /** @var array<int, string> */
    private const PRODUCT_ACTIVE_FIELDS = ['activo', 'active', 'is_active', 'habilitado', 'enabled'];

    /** @var array<int, string> */
    private const PRODUCT_DATE_FIELDS = ['updated_at', 'created_at'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'POSRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260310_016_pos_sales_flow_receipt.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createDraft(array $record): array
    {
        $id = $this->insertRecord(self::DRAFT_TABLE, [
            'tenant_id',
            'app_id',
            'session_id',
            'status',
            'customer_id',
            'currency',
            'subtotal',
            'tax_total',
            'total',
            'metadata_json',
            'created_at',
            'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'session_id' => $record['session_id'] ?? null,
            'status' => $record['status'] ?? 'open',
            'customer_id' => $record['customer_id'] ?? null,
            'currency' => $record['currency'] ?? null,
            'subtotal' => $record['subtotal'] ?? 0,
            'tax_total' => $record['tax_total'] ?? 0,
            'total' => $record['total'] ?? 0,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->loadDraftAggregate((string) ($record['tenant_id'] ?? ''), $id, isset($record['app_id']) ? (string) $record['app_id'] : null);
        if (!is_array($saved)) {
            throw new RuntimeException('POS_DRAFT_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateDraft(string $tenantId, string $draftId, array $updates, ?string $appId = null): ?array
    {
        $allowed = [
            'session_id',
            'status',
            'customer_id',
            'currency',
            'subtotal',
            'tax_total',
            'total',
            'metadata_json',
            'updated_at',
        ];
        $payload = [];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $updates)) {
                continue;
            }
            $payload[$column] = $updates[$column];
        }
        if (array_key_exists('metadata', $updates)) {
            $payload['metadata_json'] = $this->encodeJson($updates['metadata']);
        }
        if (!array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($payload === []) {
            return $this->loadDraftAggregate($tenantId, $draftId, $appId);
        }

        $qb = $this->draftQuery($tenantId, $appId)
            ->where('id', '=', $draftId);
        $qb->update($payload);

        return $this->loadDraftAggregate($tenantId, $draftId, $appId);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function insertLine(array $record): array
    {
        $id = $this->insertRecord(self::LINE_TABLE, [
            'tenant_id',
            'app_id',
            'sale_draft_id',
            'product_id',
            'sku',
            'barcode',
            'product_label',
            'qty',
            'base_price',
            'override_price',
            'effective_unit_price',
            'unit_price',
            'line_subtotal',
            'tax_rate',
            'line_tax',
            'line_total',
            'metadata_json',
            'created_at',
            'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'sale_draft_id' => $record['sale_draft_id'] ?? '',
            'product_id' => $record['product_id'] ?? '',
            'sku' => $record['sku'] ?? null,
            'barcode' => $record['barcode'] ?? null,
            'product_label' => $record['product_label'] ?? '',
            'qty' => $record['qty'] ?? 0,
            'base_price' => $record['base_price'] ?? ($record['unit_price'] ?? 0),
            'override_price' => $record['override_price'] ?? null,
            'effective_unit_price' => $record['effective_unit_price'] ?? ($record['unit_price'] ?? 0),
            'unit_price' => $record['unit_price'] ?? 0,
            'line_subtotal' => $record['line_subtotal'] ?? ($record['line_total'] ?? 0),
            'tax_rate' => $record['tax_rate'] ?? null,
            'line_tax' => $record['line_tax'] ?? null,
            'line_total' => $record['line_total'] ?? 0,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->findLine(
            (string) ($record['tenant_id'] ?? ''),
            (string) ($record['sale_draft_id'] ?? ''),
            $id,
            isset($record['app_id']) ? (string) $record['app_id'] : null
        );
        if (!is_array($saved)) {
            throw new RuntimeException('POS_DRAFT_LINE_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateLine(string $tenantId, string $draftId, string $lineId, array $updates, ?string $appId = null): ?array
    {
        $allowed = [
            'qty',
            'base_price',
            'override_price',
            'effective_unit_price',
            'unit_price',
            'tax_rate',
            'line_subtotal',
            'line_tax',
            'line_total',
            'metadata_json',
            'updated_at',
        ];
        $payload = [];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $updates)) {
                continue;
            }
            $payload[$column] = $updates[$column];
        }
        if (array_key_exists('metadata', $updates)) {
            $payload['metadata_json'] = $this->encodeJson($updates['metadata']);
        }
        if (!array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($payload === []) {
            return $this->findLine($tenantId, $draftId, $lineId, $appId);
        }

        $this->lineQuery($tenantId, $appId)
            ->where('sale_draft_id', '=', $draftId)
            ->where('id', '=', $lineId)
            ->update($payload);

        return $this->findLine($tenantId, $draftId, $lineId, $appId);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createSale(array $record): array
    {
        $id = $this->insertRecord(self::SALE_TABLE, [
            'tenant_id',
            'app_id',
            'session_id',
            'draft_id',
            'customer_id',
            'sale_number',
            'status',
            'currency',
            'subtotal',
            'tax_total',
            'total',
            'created_by_user_id',
            'metadata_json',
            'created_at',
            'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'session_id' => $record['session_id'] ?? null,
            'draft_id' => $record['draft_id'] ?? null,
            'customer_id' => $record['customer_id'] ?? null,
            'sale_number' => $record['sale_number'] ?? null,
            'status' => $record['status'] ?? 'completed',
            'currency' => $record['currency'] ?? null,
            'subtotal' => $record['subtotal'] ?? 0,
            'tax_total' => $record['tax_total'] ?? 0,
            'total' => $record['total'] ?? 0,
            'created_by_user_id' => $record['created_by_user_id'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->loadSaleAggregate((string) ($record['tenant_id'] ?? ''), $id, isset($record['app_id']) ? (string) $record['app_id'] : null);
        if (!is_array($saved)) {
            throw new RuntimeException('POS_SALE_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateSale(string $tenantId, string $saleId, array $updates, ?string $appId = null): ?array
    {
        $allowed = [
            'session_id',
            'draft_id',
            'customer_id',
            'sale_number',
            'status',
            'currency',
            'subtotal',
            'tax_total',
            'total',
            'created_by_user_id',
            'metadata_json',
            'updated_at',
        ];
        $payload = [];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $updates)) {
                continue;
            }
            $payload[$column] = $updates[$column];
        }
        if (array_key_exists('metadata', $updates)) {
            $payload['metadata_json'] = $this->encodeJson($updates['metadata']);
        }
        if (!array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($payload === []) {
            return $this->loadSaleAggregate($tenantId, $saleId, $appId);
        }

        $this->saleQuery($tenantId, $appId)
            ->where('id', '=', $saleId)
            ->update($payload);

        return $this->loadSaleAggregate($tenantId, $saleId, $appId);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function insertSaleLine(array $record): array
    {
        $id = $this->insertRecord(self::SALE_LINE_TABLE, [
            'tenant_id',
            'app_id',
            'sale_id',
            'product_id',
            'sku',
            'barcode',
            'product_label',
            'qty',
            'unit_price',
            'tax_rate',
            'line_total',
            'metadata_json',
            'created_at',
            'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'sale_id' => $record['sale_id'] ?? '',
            'product_id' => $record['product_id'] ?? '',
            'sku' => $record['sku'] ?? null,
            'barcode' => $record['barcode'] ?? null,
            'product_label' => $record['product_label'] ?? '',
            'qty' => $record['qty'] ?? 0,
            'unit_price' => $record['unit_price'] ?? 0,
            'tax_rate' => $record['tax_rate'] ?? null,
            'line_total' => $record['line_total'] ?? 0,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->findSaleLine(
            (string) ($record['tenant_id'] ?? ''),
            (string) ($record['sale_id'] ?? ''),
            $id,
            isset($record['app_id']) ? (string) $record['app_id'] : null
        );
        if (!is_array($saved)) {
            throw new RuntimeException('POS_SALE_LINE_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadDraftAggregate(string $tenantId, string $draftId, ?string $appId = null): ?array
    {
        $draft = $this->findDraft($tenantId, $draftId, $appId);
        if (!is_array($draft)) {
            return null;
        }

        $draft['lines'] = $this->listLines($tenantId, $draftId, $appId);

        return $draft;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadSaleAggregate(string $tenantId, string $saleId, ?string $appId = null): ?array
    {
        $sale = $this->findSale($tenantId, $saleId, $appId);
        if (!is_array($sale)) {
            return null;
        }

        $sale['lines'] = $this->listSaleLines($tenantId, $saleId, $appId);

        return $sale;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDraft(string $tenantId, string $draftId, ?string $appId = null): ?array
    {
        $row = $this->draftQuery($tenantId, $appId)
            ->where('id', '=', $draftId)
            ->first();

        return is_array($row) ? $this->normalizeDraftRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSale(string $tenantId, string $saleId, ?string $appId = null): ?array
    {
        $row = $this->saleQuery($tenantId, $appId)
            ->where('id', '=', $saleId)
            ->first();

        return is_array($row) ? $this->normalizeSaleRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSaleByNumber(string $tenantId, string $saleNumber, ?string $appId = null): ?array
    {
        $row = $this->saleQuery($tenantId, $appId)
            ->where('sale_number', '=', $saleNumber)
            ->first();

        return is_array($row) ? $this->normalizeSaleRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSaleByDraftId(string $tenantId, string $draftId, ?string $appId = null): ?array
    {
        $row = $this->saleQuery($tenantId, $appId)
            ->where('draft_id', '=', $draftId)
            ->orderBy('id', 'DESC')
            ->first();

        return is_array($row) ? $this->normalizeSaleRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listDrafts(string $tenantId, array $filters = [], int $limit = 20): array
    {
        $qb = $this->draftQuery($tenantId, $this->nullableString($filters['app_id'] ?? null))
            ->orderBy('updated_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)));

        foreach (['status', 'session_id', 'customer_id'] as $key) {
            $value = $this->nullableString($filters[$key] ?? null);
            if ($value === null) {
                continue;
            }
            $qb->where($key, '=', $value);
        }

        $rows = $qb->get();

        return array_map(fn(array $row): array => $this->normalizeDraftRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listSales(string $tenantId, array $filters = [], int $limit = 20): array
    {
        $qb = $this->saleQuery($tenantId, $this->nullableString($filters['app_id'] ?? null))
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)));

        foreach (['status', 'session_id', 'customer_id', 'draft_id', 'sale_number'] as $key) {
            $value = $this->nullableString($filters[$key] ?? null);
            if ($value === null) {
                continue;
            }
            $qb->where($key, '=', $value);
        }

        $dateFrom = $this->nullableString($filters['date_from'] ?? null);
        if ($dateFrom !== null) {
            $qb->where('created_at', '>=', $dateFrom);
        }
        $dateTo = $this->nullableString($filters['date_to'] ?? null);
        if ($dateTo !== null) {
            $qb->where('created_at', '<=', $dateTo);
        }

        $rows = $qb->get();

        return array_map(fn(array $row): array => $this->normalizeSaleRow($row), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLines(string $tenantId, string $draftId, ?string $appId = null): array
    {
        $rows = $this->lineQuery($tenantId, $appId)
            ->where('sale_draft_id', '=', $draftId)
            ->orderBy('id', 'ASC')
            ->get();

        return array_map(fn(array $row): array => $this->normalizeLineRow($row), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSaleLines(string $tenantId, string $saleId, ?string $appId = null): array
    {
        $rows = $this->saleLineQuery($tenantId, $appId)
            ->where('sale_id', '=', $saleId)
            ->orderBy('id', 'ASC')
            ->get();

        return array_map(fn(array $row): array => $this->normalizeSaleLineRow($row), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLine(string $tenantId, string $draftId, string $lineId, ?string $appId = null): ?array
    {
        $row = $this->lineQuery($tenantId, $appId)
            ->where('sale_draft_id', '=', $draftId)
            ->where('id', '=', $lineId)
            ->first();

        return is_array($row) ? $this->normalizeLineRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSaleLine(string $tenantId, string $saleId, string $lineId, ?string $appId = null): ?array
    {
        $row = $this->saleLineQuery($tenantId, $appId)
            ->where('sale_id', '=', $saleId)
            ->where('id', '=', $lineId)
            ->first();

        return is_array($row) ? $this->normalizeSaleLineRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deleteLine(string $tenantId, string $draftId, string $lineId, ?string $appId = null): ?array
    {
        $current = $this->findLine($tenantId, $draftId, $lineId, $appId);
        if (!is_array($current)) {
            return null;
        }

        $this->lineQuery($tenantId, $appId)
            ->where('sale_draft_id', '=', $draftId)
            ->where('id', '=', $lineId)
            ->delete();

        return $current;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSession(string $tenantId, string $sessionId, ?string $appId = null): ?array
    {
        $row = $this->sessionQuery($tenantId, $appId)
            ->where('id', '=', $sessionId)
            ->first();

        return is_array($row) ? $this->normalizeSessionRow($row) : null;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback)
    {
        $started = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $started = true;
        }

        try {
            $result = $callback();
            if ($started) {
                $this->db->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($started && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function searchProductsForPOS(string $tenantId, string $query, array $options = [], ?string $appId = null, ?EntityRegistry $registry = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $mode = strtolower(trim((string) ($options['mode'] ?? 'partial')));
        if (!in_array($mode, ['barcode', 'sku', 'exact_name', 'partial'], true)) {
            $mode = 'partial';
        }

        $registry = $registry ?? new EntityRegistry();
        $results = [];
        foreach ($this->productContracts($registry) as $contract) {
            $results = array_merge($results, $this->searchProductContract($tenantId, $contract, $query, $mode, $appId));
        }

        $results = $this->dedupeProductResults($results);

        return array_slice($results, 0, $this->intFilter($options['limit'] ?? null, 5, 1, 20));
    }

    /**
     * @param array<string, mixed> $resolvedEntity
     * @return array<string, mixed>|null
     */
    public function loadDynamicEntitySnapshot(array $resolvedEntity, string $tenantId, ?string $appId = null, ?EntityRegistry $registry = null): ?array
    {
        $metadata = is_array($resolvedEntity['metadata_json'] ?? null) ? (array) $resolvedEntity['metadata_json'] : [];
        $contractName = trim((string) ($metadata['entity_contract'] ?? ''));
        $table = trim((string) ($metadata['table'] ?? ''));
        $entityId = trim((string) ($resolvedEntity['entity_id'] ?? ''));
        if ($contractName === '' || $table === '' || $entityId === '') {
            return null;
        }

        $registry = $registry ?? new EntityRegistry();
        try {
            $contract = $registry->get($contractName);
        } catch (\Throwable $e) {
            return null;
        }

        $primaryKey = strtolower(trim((string) ($contract['table']['primaryKey'] ?? 'id')));
        if ($primaryKey === '') {
            $primaryKey = 'id';
        }

        $allowedColumns = $this->contractColumns($contract);
        $qb = (new QueryBuilder($this->db, $table))
            ->setAllowedColumns($allowedColumns)
            ->where($primaryKey, '=', ctype_digit($entityId) ? (int) $entityId : $entityId);

        if (in_array('tenant_id', $allowedColumns, true)) {
            $qb->where('tenant_id', '=', $this->stableTenantInt($tenantId));
        }
        if ($appId !== null && $appId !== '' && in_array('app_id', $allowedColumns, true) && $this->columnExists($table, 'app_id')) {
            $qb->where('app_id', '=', $appId);
        }

        $row = $qb->first();
        if (!is_array($row)) {
            return null;
        }

        return [
            'entity_type' => (string) ($resolvedEntity['entity_type'] ?? ''),
            'entity_id' => $entityId,
            'label' => $this->firstNonEmpty($row, self::PRODUCT_LABEL_FIELDS) ?: trim((string) ($resolvedEntity['label'] ?? '')),
            'sku' => $this->firstNonEmpty($row, self::PRODUCT_SKU_FIELDS),
            'barcode' => $this->firstNonEmpty($row, self::PRODUCT_BARCODE_FIELDS),
            'unit_price' => $this->firstNumeric($row, self::PRODUCT_PRICE_FIELDS),
            'tax_rate' => $this->firstNumeric($row, self::PRODUCT_TAX_FIELDS, true),
            'metadata' => [
                'entity_contract' => $contractName,
                'table' => $table,
                'matched_by' => (string) ($resolvedEntity['matched_by'] ?? ''),
                'source_module' => (string) ($resolvedEntity['source_module'] ?? ''),
                'raw_identifier' => (string) ($metadata['raw_identifier'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<int, string>
     */
    private function contractColumns(array $contract): array
    {
        $columns = [];
        foreach ((array) ($contract['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = strtolower(trim((string) ($field['name'] ?? '')));
            if ($name !== '') {
                $columns[] = $name;
            }
        }

        foreach (['id', 'tenant_id', 'app_id', 'created_at', 'updated_at'] as $column) {
            $columns[] = $column;
        }

        $primaryKey = strtolower(trim((string) ($contract['table']['primaryKey'] ?? 'id')));
        if ($primaryKey !== '') {
            $columns[] = $primaryKey;
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function dedupeProductResults(array $results): array
    {
        $deduped = [];
        foreach ($results as $result) {
            $metadata = is_array($result['metadata_json'] ?? null) ? (array) $result['metadata_json'] : [];
            $key = implode('|', [
                (string) ($metadata['entity_contract'] ?? ''),
                (string) ($metadata['table'] ?? ''),
                (string) ($result['entity_id'] ?? ''),
            ]);
            if ($key === '||') {
                continue;
            }
            if (!isset($deduped[$key]) || (float) ($deduped[$key]['score'] ?? 0) < (float) ($result['score'] ?? 0)) {
                $deduped[$key] = $result;
            }
        }

        $results = array_values($deduped);
        usort($results, function (array $left, array $right): int {
            $scoreCompare = ((float) ($right['score'] ?? 0)) <=> ((float) ($left['score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            $leftActive = (bool) (($left['metadata_json']['active'] ?? false));
            $rightActive = (bool) (($right['metadata_json']['active'] ?? false));
            if ($leftActive !== $rightActive) {
                return $rightActive <=> $leftActive;
            }

            $leftDate = (string) (($left['metadata_json']['sort_date'] ?? '') ?: '');
            $rightDate = (string) (($right['metadata_json']['sort_date'] ?? '') ?: '');
            $dateCompare = strcmp($rightDate, $leftDate);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return $results;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function buildProductCandidate(
        array $row,
        array $contract,
        string $table,
        string $primaryKey,
        array $labelFields,
        array $skuFields,
        array $barcodeFields,
        ?string $dateField,
        ?string $activeField,
        string $matchedBy,
        string $matchedStrategy,
        int $baseScore,
        string $matchedField,
        string $query
    ): array {
        $entityId = trim((string) ($row[$primaryKey] ?? ''));
        if ($entityId === '') {
            $entityId = trim((string) ($row['id'] ?? ''));
        }

        $label = $this->firstNonEmpty($row, array_merge($labelFields, $skuFields, $barcodeFields, [$primaryKey]));
        if ($label === '') {
            $label = (string) ($contract['label'] ?? $contract['name'] ?? 'producto') . ' #' . $entityId;
        }

        $subtitleParts = [];
        foreach ([$this->firstNonEmpty($row, $skuFields), $this->firstNonEmpty($row, $barcodeFields)] as $value) {
            if ($value === '' || $value === trim((string) ($row[$matchedField] ?? ''))) {
                continue;
            }
            $subtitleParts[] = $value;
        }
        $unitPrice = $this->firstNumeric($row, self::PRODUCT_PRICE_FIELDS, true);
        if ($unitPrice !== null) {
            $subtitleParts[] = 'precio=' . $unitPrice;
        }
        $subtitle = implode(' | ', array_slice(array_values(array_unique(array_filter($subtitleParts, fn($part): bool => trim((string) $part) !== ''))), 0, 3));

        $metadata = [
            'entity_contract' => (string) ($contract['name'] ?? ''),
            'table' => $table,
            'matched_field' => $matchedField,
            'matched_strategy' => $matchedStrategy,
            'sort_date' => (string) ($row[$dateField ?? ''] ?? ''),
            'raw_identifier' => $this->firstNonEmpty($row, array_merge($barcodeFields, $skuFields)),
            'sku' => $this->firstNonEmpty($row, $skuFields),
            'barcode' => $this->firstNonEmpty($row, $barcodeFields),
            'unit_price' => $unitPrice,
            'tax_rate' => $this->firstNumeric($row, self::PRODUCT_TAX_FIELDS, true),
            'active' => $this->isActiveRow($row, $activeField),
            'pos_snapshot_ready' => true,
        ];
        $score = $baseScore + $this->activeBonus($row, $activeField) + $this->recencyBonus((string) ($row[$dateField ?? ''] ?? ''));
        if ($query !== '' && EntitySearchSupport::normalizeText((string) ($row[$matchedField] ?? '')) === EntitySearchSupport::normalizeText($query)) {
            $score += 20;
        }

        $candidate = [
            'entity_type' => 'product',
            'entity_id' => $entityId,
            'label' => $label,
            'subtitle' => $subtitle,
            'score' => $score,
            'source_module' => (string) ($contract['name'] ?? 'pos_product'),
            'matched_by' => $matchedBy,
            'metadata_json' => $metadata,
        ];
        EntitySearchContractValidator::validateResult($candidate);

        return $candidate;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, array<string, mixed>>
     */
    private function fieldMap(array $contract): array
    {
        $fields = [];
        foreach ((array) ($contract['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = strtolower(trim((string) ($field['name'] ?? '')));
            if ($name !== '') {
                $fields[$name] = $field;
            }
        }

        if ((bool) ($contract['table']['timestamps'] ?? false)) {
            $fields['created_at'] = ['name' => 'created_at'];
            $fields['updated_at'] = ['name' => 'updated_at'];
        }

        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    private function existingFields(array $fields, array $candidates): array
    {
        $result = [];
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate !== '' && array_key_exists($candidate, $fields)) {
                $result[] = $candidate;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<int, string> $candidates
     */
    private function firstExistingField(array $fields, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate !== '' && array_key_exists($candidate, $fields)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function inferContractType(array $contract): ?string
    {
        $signals = EntitySearchSupport::normalizeText(implode(' ', [
            (string) ($contract['name'] ?? ''),
            (string) ($contract['label'] ?? ''),
            (string) ($contract['table']['name'] ?? ''),
        ]));

        foreach (EntitySearchSupport::aliasesForType('product') as $alias) {
            if (preg_match('/(?:^|\\b)' . preg_quote($alias, '/') . '(?:$|\\b)/u', $signals) === 1) {
                return 'product';
            }
        }

        $fields = $this->fieldMap($contract);
        if ($this->firstExistingField($fields, array_merge(self::PRODUCT_SKU_FIELDS, self::PRODUCT_BARCODE_FIELDS)) !== null) {
            return 'product';
        }

        return null;
    }

    private function intFilter($value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function isActiveRow(array $row, ?string $activeField): bool
    {
        if ($activeField === null) {
            return false;
        }

        $value = strtolower(trim((string) ($row[$activeField] ?? '')));

        return in_array($value, ['1', 'true', 'si', 'yes', 'active', 'activo'], true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function activeBonus(array $row, ?string $activeField): int
    {
        return $this->isActiveRow($row, $activeField) ? 15 : 0;
    }

    private function recencyBonus(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        try {
            $date = new \DateTimeImmutable(str_replace('T', ' ', $value));
        } catch (\Throwable $e) {
            return 0;
        }

        $days = abs((int) ((new \DateTimeImmutable('now'))->diff($date)->format('%a')));
        if ($days <= 1) {
            return 20;
        }
        if ($days <= 7) {
            return 10;
        }
        if ($days <= 30) {
            return 5;
        }

        return 0;
    }

    private function normalizeMatchValue(string $value): string
    {
        return EntitySearchSupport::normalizeText($value);
    }

    private function primaryKey(array $contract): string
    {
        $primaryKey = strtolower(trim((string) ($contract['table']['primaryKey'] ?? 'id')));

        return preg_match('/^[a-zA-Z0-9_]+$/', $primaryKey) === 1 ? $primaryKey : 'id';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productContracts(EntityRegistry $registry): array
    {
        $contracts = [];
        foreach ($registry->all() as $contract) {
            if (!is_array($contract) || ($contract['type'] ?? '') !== 'entity') {
                continue;
            }
            if ($this->inferContractType($contract) !== 'product') {
                continue;
            }
            $contracts[] = $contract;
        }

        return $contracts;
    }

    private function resolvedContractTable(array $contract, ?string $appId = null): string
    {
        $logicalTable = trim((string) ($contract['table']['name'] ?? ''));
        if ($logicalTable === '') {
            throw new RuntimeException('POS_PRODUCT_TABLE_REQUIRED');
        }

        return TableNamespace::resolve($logicalTable, $appId);
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<int, array<string, mixed>>
     */
    private function searchProductContract(string $tenantId, array $contract, string $query, string $mode, ?string $appId = null): array
    {
        $table = $this->resolvedContractTable($contract, $appId);
        if (!$this->tableExists($table)) {
            return [];
        }

        $fields = $this->fieldMap($contract);
        $primaryKey = $this->primaryKey($contract);
        $labelFields = $this->existingFields($fields, self::PRODUCT_LABEL_FIELDS);
        $skuFields = $this->existingFields($fields, self::PRODUCT_SKU_FIELDS);
        $barcodeFields = $this->existingFields($fields, self::PRODUCT_BARCODE_FIELDS);
        $dateField = $this->firstExistingField($fields, self::PRODUCT_DATE_FIELDS);
        $activeField = $this->firstExistingField($fields, self::PRODUCT_ACTIVE_FIELDS);

        $results = [];
        $normalized = $this->normalizeMatchValue($query);
        if ($normalized === '') {
            return [];
        }

        if ($mode === 'barcode') {
            foreach ($barcodeFields as $field) {
                foreach ($this->fetchProductRows($table, $tenantId, $appId, 'LOWER(' . $field . ') = :match_exact', [':match_exact' => $normalized], $dateField, 2) as $row) {
                    $results[] = $this->buildProductCandidate($row, $contract, $table, $primaryKey, $labelFields, $skuFields, $barcodeFields, $dateField, $activeField, 'barcode', 'exact', 1200, $field, $query);
                }
            }
            return $results;
        }

        if ($mode === 'sku') {
            foreach ($skuFields as $field) {
                foreach ($this->fetchProductRows($table, $tenantId, $appId, 'LOWER(' . $field . ') = :match_exact', [':match_exact' => $normalized], $dateField, 2) as $row) {
                    $results[] = $this->buildProductCandidate($row, $contract, $table, $primaryKey, $labelFields, $skuFields, $barcodeFields, $dateField, $activeField, 'sku', 'exact', 1100, $field, $query);
                }
            }
            return $results;
        }

        if ($mode === 'exact_name') {
            foreach ($labelFields as $field) {
                foreach ($this->fetchProductRows($table, $tenantId, $appId, 'LOWER(' . $field . ') = :label_exact', [':label_exact' => $normalized], $dateField, 3) as $row) {
                    $results[] = $this->buildProductCandidate($row, $contract, $table, $primaryKey, $labelFields, $skuFields, $barcodeFields, $dateField, $activeField, 'exact_name', 'exact', 980, $field, $query);
                }
            }
            return $results;
        }

        foreach ($labelFields as $field) {
            foreach ($this->fetchProductRows($table, $tenantId, $appId, 'LOWER(' . $field . ') LIKE :label_prefix', [':label_prefix' => $normalized . '%'], $dateField, 5) as $row) {
                $results[] = $this->buildProductCandidate($row, $contract, $table, $primaryKey, $labelFields, $skuFields, $barcodeFields, $dateField, $activeField, 'partial', 'prefix', 760, $field, $query);
            }
            if (mb_strlen($normalized, 'UTF-8') >= 3) {
                foreach ($this->fetchProductRows($table, $tenantId, $appId, 'LOWER(' . $field . ') LIKE :label_contains', [':label_contains' => '%' . $normalized . '%'], $dateField, 5) as $row) {
                    $results[] = $this->buildProductCandidate($row, $contract, $table, $primaryKey, $labelFields, $skuFields, $barcodeFields, $dateField, $activeField, 'partial', 'contains', 680, $field, $query);
                }
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductRows(
        string $table,
        string $tenantId,
        ?string $appId,
        string $extraWhere,
        array $bindings,
        ?string $dateField,
        int $limit
    ): array {
        $where = ['tenant_id = :tenant_scope_id'];
        $bindings = [':tenant_scope_id' => $this->stableTenantInt($tenantId)] + $bindings;

        if ($appId !== null && $appId !== '' && $this->columnExists($table, 'app_id')) {
            $where[] = 'app_id = :app_id';
            $bindings[':app_id'] = $appId;
        }
        if (trim($extraWhere) !== '') {
            $where[] = $extraWhere;
        }

        $orderField = $dateField !== null && $this->columnExists($table, $dateField)
            ? $dateField
            : ($this->columnExists($table, 'created_at') ? 'created_at' : 'id');
        $secondaryOrderField = $this->columnExists($table, 'id') ? 'id' : $orderField;
        $sql = 'SELECT * FROM ' . $this->sanitizeIdentifier($table)
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $this->sanitizeIdentifier($orderField) . ' DESC, ' . $this->sanitizeIdentifier($secondaryOrderField) . ' DESC LIMIT ' . max(1, $limit);

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $bindings);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [self::SESSION_TABLE, self::DRAFT_TABLE, self::LINE_TABLE, self::SALE_TABLE, self::SALE_LINE_TABLE];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            self::SESSION_TABLE => [
                'idx_pos_sessions_tenant_app_status',
                'idx_pos_sessions_tenant_register',
            ],
            self::DRAFT_TABLE => [
                'idx_sale_drafts_tenant_app_status',
                'idx_sale_drafts_tenant_session_status',
                'idx_sale_drafts_tenant_customer',
            ],
            self::LINE_TABLE => [
                'idx_sale_draft_lines_tenant_draft',
                'idx_sale_draft_lines_tenant_product',
            ],
            self::SALE_TABLE => [
                'idx_pos_sales_tenant_app_created',
                'idx_pos_sales_tenant_number',
                'idx_pos_sales_tenant_draft',
            ],
            self::SALE_LINE_TABLE => [
                'idx_pos_sale_lines_tenant_sale',
                'idx_pos_sale_lines_tenant_product',
            ],
        ];
    }

    private function ensureSchema(): void
    {
        if ($this->driver() === 'mysql') {
            $this->ensureSchemaMySql();
            return;
        }

        $this->ensureSchemaSqlite();
    }

    private function ensureSchemaSqlite(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::SESSION_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                store_id TEXT NULL,
                cash_register_id TEXT NULL,
                opened_by_user_id TEXT NULL,
                status TEXT NOT NULL,
                opened_at TEXT NULL,
                closed_at TEXT NULL,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_pos_sessions_tenant_app_status ON ' . self::SESSION_TABLE . ' (tenant_id, app_id, status, opened_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_pos_sessions_tenant_register ON ' . self::SESSION_TABLE . ' (tenant_id, cash_register_id, status)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::DRAFT_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                session_id TEXT NULL,
                status TEXT NOT NULL,
                customer_id TEXT NULL,
                currency TEXT NULL,
                subtotal REAL NOT NULL DEFAULT 0,
                tax_total REAL NOT NULL DEFAULT 0,
                total REAL NOT NULL DEFAULT 0,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sale_drafts_tenant_app_status ON ' . self::DRAFT_TABLE . ' (tenant_id, app_id, status, updated_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sale_drafts_tenant_session_status ON ' . self::DRAFT_TABLE . ' (tenant_id, session_id, status)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sale_drafts_tenant_customer ON ' . self::DRAFT_TABLE . ' (tenant_id, customer_id, updated_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::LINE_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                sale_draft_id TEXT NOT NULL,
                product_id TEXT NOT NULL,
                sku TEXT NULL,
                barcode TEXT NULL,
                product_label TEXT NOT NULL,
                qty REAL NOT NULL,
                base_price REAL NOT NULL DEFAULT 0,
                override_price REAL NULL,
                effective_unit_price REAL NOT NULL DEFAULT 0,
                unit_price REAL NOT NULL,
                line_subtotal REAL NOT NULL DEFAULT 0,
                tax_rate REAL NULL,
                line_tax REAL NULL,
                line_total REAL NOT NULL,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sale_draft_lines_tenant_draft ON ' . self::LINE_TABLE . ' (tenant_id, app_id, sale_draft_id, id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sale_draft_lines_tenant_product ON ' . self::LINE_TABLE . ' (tenant_id, product_id, created_at)');
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::SALE_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                session_id TEXT NULL,
                draft_id TEXT NULL,
                customer_id TEXT NULL,
                sale_number TEXT NULL,
                status TEXT NOT NULL,
                currency TEXT NULL,
                subtotal REAL NOT NULL DEFAULT 0,
                tax_total REAL NOT NULL DEFAULT 0,
                total REAL NOT NULL DEFAULT 0,
                created_by_user_id TEXT NULL,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_pos_sales_tenant_app_created ON ' . self::SALE_TABLE . ' (tenant_id, app_id, created_at, id)');
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pos_sales_tenant_number ON ' . self::SALE_TABLE . ' (tenant_id, app_id, sale_number)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_pos_sales_tenant_draft ON ' . self::SALE_TABLE . ' (tenant_id, app_id, draft_id)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::SALE_LINE_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                sale_id TEXT NOT NULL,
                product_id TEXT NOT NULL,
                sku TEXT NULL,
                barcode TEXT NULL,
                product_label TEXT NOT NULL,
                qty REAL NOT NULL,
                unit_price REAL NOT NULL,
                tax_rate REAL NULL,
                line_total REAL NOT NULL,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_pos_sale_lines_tenant_sale ON ' . self::SALE_LINE_TABLE . ' (tenant_id, app_id, sale_id, id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_pos_sale_lines_tenant_product ON ' . self::SALE_LINE_TABLE . ' (tenant_id, product_id, created_at)');
        $this->ensureLineColumnsSqlite();
    }

    private function ensureSchemaMySql(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::SESSION_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                store_id VARCHAR(120) NULL,
                cash_register_id VARCHAR(120) NULL,
                opened_by_user_id VARCHAR(120) NULL,
                status VARCHAR(32) NOT NULL,
                opened_at DATETIME NULL,
                closed_at DATETIME NULL,
                metadata_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_pos_sessions_tenant_app_status (tenant_id, app_id, status, opened_at),
                KEY idx_pos_sessions_tenant_register (tenant_id, cash_register_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::DRAFT_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                session_id VARCHAR(120) NULL,
                status VARCHAR(32) NOT NULL,
                customer_id VARCHAR(190) NULL,
                currency VARCHAR(16) NULL,
                subtotal DECIMAL(18,4) NOT NULL DEFAULT 0,
                tax_total DECIMAL(18,4) NOT NULL DEFAULT 0,
                total DECIMAL(18,4) NOT NULL DEFAULT 0,
                metadata_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sale_drafts_tenant_app_status (tenant_id, app_id, status, updated_at),
                KEY idx_sale_drafts_tenant_session_status (tenant_id, session_id, status),
                KEY idx_sale_drafts_tenant_customer (tenant_id, customer_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::LINE_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                sale_draft_id VARCHAR(120) NOT NULL,
                product_id VARCHAR(190) NOT NULL,
                sku VARCHAR(190) NULL,
                barcode VARCHAR(190) NULL,
                product_label VARCHAR(255) NOT NULL,
                qty DECIMAL(18,4) NOT NULL,
                base_price DECIMAL(18,4) NOT NULL DEFAULT 0,
                override_price DECIMAL(18,4) NULL,
                effective_unit_price DECIMAL(18,4) NOT NULL DEFAULT 0,
                unit_price DECIMAL(18,4) NOT NULL,
                line_subtotal DECIMAL(18,4) NOT NULL DEFAULT 0,
                tax_rate DECIMAL(10,4) NULL,
                line_tax DECIMAL(18,4) NULL,
                line_total DECIMAL(18,4) NOT NULL,
                metadata_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sale_draft_lines_tenant_draft (tenant_id, app_id, sale_draft_id, id),
                KEY idx_sale_draft_lines_tenant_product (tenant_id, product_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::SALE_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                session_id VARCHAR(120) NULL,
                draft_id VARCHAR(120) NULL,
                customer_id VARCHAR(190) NULL,
                sale_number VARCHAR(190) NULL,
                status VARCHAR(32) NOT NULL,
                currency VARCHAR(16) NULL,
                subtotal DECIMAL(18,4) NOT NULL DEFAULT 0,
                tax_total DECIMAL(18,4) NOT NULL DEFAULT 0,
                total DECIMAL(18,4) NOT NULL DEFAULT 0,
                created_by_user_id VARCHAR(120) NULL,
                metadata_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY idx_pos_sales_tenant_number (tenant_id, app_id, sale_number),
                KEY idx_pos_sales_tenant_app_created (tenant_id, app_id, created_at, id),
                KEY idx_pos_sales_tenant_draft (tenant_id, app_id, draft_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::SALE_LINE_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                sale_id VARCHAR(120) NOT NULL,
                product_id VARCHAR(190) NOT NULL,
                sku VARCHAR(190) NULL,
                barcode VARCHAR(190) NULL,
                product_label VARCHAR(255) NOT NULL,
                qty DECIMAL(18,4) NOT NULL,
                unit_price DECIMAL(18,4) NOT NULL,
                tax_rate DECIMAL(10,4) NULL,
                line_total DECIMAL(18,4) NOT NULL,
                metadata_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_pos_sale_lines_tenant_sale (tenant_id, app_id, sale_id, id),
                KEY idx_pos_sale_lines_tenant_product (tenant_id, product_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->ensureLineColumnsMySql();
    }

    private function ensureLineColumnsSqlite(): void
    {
        $columns = [
            'base_price' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN base_price REAL NOT NULL DEFAULT 0',
            'override_price' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN override_price REAL NULL',
            'effective_unit_price' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN effective_unit_price REAL NOT NULL DEFAULT 0',
            'line_subtotal' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN line_subtotal REAL NOT NULL DEFAULT 0',
            'line_tax' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN line_tax REAL NULL',
        ];

        foreach ($columns as $column => $sql) {
            if ($this->columnExists(self::LINE_TABLE, $column)) {
                continue;
            }
            $this->db->exec($sql);
        }
    }

    private function ensureLineColumnsMySql(): void
    {
        $columns = [
            'base_price' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN base_price DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty',
            'override_price' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN override_price DECIMAL(18,4) NULL AFTER base_price',
            'effective_unit_price' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN effective_unit_price DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER override_price',
            'line_subtotal' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN line_subtotal DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER unit_price',
            'line_tax' => 'ALTER TABLE ' . self::LINE_TABLE . ' ADD COLUMN line_tax DECIMAL(18,4) NULL AFTER tax_rate',
        ];

        foreach ($columns as $column => $sql) {
            if ($this->columnExists(self::LINE_TABLE, $column)) {
                continue;
            }
            $this->db->exec($sql);
        }
    }

    private function draftQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::DRAFT_TABLE))
            ->setAllowedColumns([
                'id',
                'tenant_id',
                'app_id',
                'session_id',
                'status',
                'customer_id',
                'currency',
                'subtotal',
                'tax_total',
                'total',
                'metadata_json',
                'created_at',
                'updated_at',
            ])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function lineQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::LINE_TABLE))
            ->setAllowedColumns([
                'id',
                'tenant_id',
                'app_id',
                'sale_draft_id',
                'product_id',
                'sku',
                'barcode',
                'product_label',
                'qty',
                'base_price',
                'override_price',
                'effective_unit_price',
                'unit_price',
                'line_subtotal',
                'tax_rate',
                'line_tax',
                'line_total',
                'metadata_json',
                'created_at',
                'updated_at',
            ])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function saleQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::SALE_TABLE))
            ->setAllowedColumns([
                'id',
                'tenant_id',
                'app_id',
                'session_id',
                'draft_id',
                'customer_id',
                'sale_number',
                'status',
                'currency',
                'subtotal',
                'tax_total',
                'total',
                'created_by_user_id',
                'metadata_json',
                'created_at',
                'updated_at',
            ])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function saleLineQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::SALE_LINE_TABLE))
            ->setAllowedColumns([
                'id',
                'tenant_id',
                'app_id',
                'sale_id',
                'product_id',
                'sku',
                'barcode',
                'product_label',
                'qty',
                'unit_price',
                'tax_rate',
                'line_total',
                'metadata_json',
                'created_at',
                'updated_at',
            ])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function sessionQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::SESSION_TABLE))
            ->setAllowedColumns([
                'id',
                'tenant_id',
                'app_id',
                'store_id',
                'cash_register_id',
                'opened_by_user_id',
                'status',
                'opened_at',
                'closed_at',
                'metadata_json',
                'created_at',
                'updated_at',
            ])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, mixed> $values
     */
    private function insertRecord(string $table, array $columns, array $values): string
    {
        $qb = (new QueryBuilder($this->db, $table))->setAllowedColumns($columns);
        $id = $qb->insert($values);

        return (string) $id;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeDraftRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'session_id' => $this->nullableString($row['session_id'] ?? null),
            'status' => trim((string) ($row['status'] ?? 'open')) ?: 'open',
            'customer_id' => $this->nullableString($row['customer_id'] ?? null),
            'currency' => $this->nullableString($row['currency'] ?? null),
            'subtotal' => $this->decimal($row['subtotal'] ?? 0),
            'tax_total' => $this->decimal($row['tax_total'] ?? 0),
            'total' => $this->decimal($row['total'] ?? 0),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeLineRow(array $row): array
    {
        $qty = $this->decimal($row['qty'] ?? 0);
        $basePrice = array_key_exists('base_price', $row) && $row['base_price'] !== null && $row['base_price'] !== ''
            ? $this->decimal($row['base_price'])
            : $this->decimal($row['unit_price'] ?? 0);
        $overridePrice = array_key_exists('override_price', $row) && $row['override_price'] !== null && $row['override_price'] !== ''
            ? $this->decimal($row['override_price'])
            : null;
        $effectiveUnitPrice = array_key_exists('effective_unit_price', $row) && $row['effective_unit_price'] !== null && $row['effective_unit_price'] !== ''
            ? $this->decimal($row['effective_unit_price'])
            : ($overridePrice ?? $basePrice);
        $lineSubtotal = array_key_exists('line_subtotal', $row) && $row['line_subtotal'] !== null && $row['line_subtotal'] !== ''
            ? $this->decimal($row['line_subtotal'])
            : $this->decimal($qty * $effectiveUnitPrice);
        $taxRate = $row['tax_rate'] === null ? null : $this->decimal($row['tax_rate']);
        $lineTax = array_key_exists('line_tax', $row) && $row['line_tax'] !== null && $row['line_tax'] !== ''
            ? $this->decimal($row['line_tax'])
            : ($taxRate !== null ? $this->decimal($lineSubtotal * ($taxRate / 100)) : null);
        $lineTotal = array_key_exists('line_subtotal', $row) && $row['line_subtotal'] !== null && $row['line_subtotal'] !== ''
            ? $this->decimal($row['line_total'] ?? ($lineSubtotal + ($lineTax ?? 0)))
            : $this->decimal($lineSubtotal + ($lineTax ?? 0));

        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'sale_draft_id' => (string) ($row['sale_draft_id'] ?? ''),
            'product_id' => (string) ($row['product_id'] ?? ''),
            'sku' => $this->nullableString($row['sku'] ?? null),
            'barcode' => $this->nullableString($row['barcode'] ?? null),
            'product_label' => (string) ($row['product_label'] ?? ''),
            'qty' => $qty,
            'base_price' => $basePrice,
            'override_price' => $overridePrice,
            'effective_unit_price' => $effectiveUnitPrice,
            'unit_price' => $effectiveUnitPrice,
            'line_subtotal' => $lineSubtotal,
            'tax_rate' => $taxRate,
            'line_tax' => $lineTax,
            'line_total' => $lineTotal,
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSaleRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'session_id' => $this->nullableString($row['session_id'] ?? null),
            'draft_id' => $this->nullableString($row['draft_id'] ?? null),
            'customer_id' => $this->nullableString($row['customer_id'] ?? null),
            'sale_number' => $this->nullableString($row['sale_number'] ?? null),
            'status' => trim((string) ($row['status'] ?? 'completed')) ?: 'completed',
            'currency' => $this->nullableString($row['currency'] ?? null),
            'subtotal' => $this->decimal($row['subtotal'] ?? 0),
            'tax_total' => $this->decimal($row['tax_total'] ?? 0),
            'total' => $this->decimal($row['total'] ?? 0),
            'created_by_user_id' => $this->nullableString($row['created_by_user_id'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSaleLineRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'sale_id' => (string) ($row['sale_id'] ?? ''),
            'product_id' => (string) ($row['product_id'] ?? ''),
            'sku' => $this->nullableString($row['sku'] ?? null),
            'barcode' => $this->nullableString($row['barcode'] ?? null),
            'product_label' => (string) ($row['product_label'] ?? ''),
            'qty' => $this->decimal($row['qty'] ?? 0),
            'unit_price' => $this->decimal($row['unit_price'] ?? 0),
            'tax_rate' => $row['tax_rate'] === null ? null : $this->decimal($row['tax_rate']),
            'line_total' => $this->decimal($row['line_total'] ?? 0),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSessionRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'store_id' => $this->nullableString($row['store_id'] ?? null),
            'cash_register_id' => $this->nullableString($row['cash_register_id'] ?? null),
            'opened_by_user_id' => $this->nullableString($row['opened_by_user_id'] ?? null),
            'status' => trim((string) ($row['status'] ?? 'closed')) ?: 'closed',
            'opened_at' => $this->nullableString($row['opened_at'] ?? null),
            'closed_at' => $this->nullableString($row['closed_at'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $fields
     */
    private function firstNonEmpty(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $candidate = trim((string) ($row[$field] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $fields
     */
    private function firstNumeric(array $row, array $fields, bool $nullable = false): ?float
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row) || !is_numeric($row[$field])) {
                continue;
            }

            return $this->decimal($row[$field]);
        }

        return $nullable ? null : 0.0;
    }

    /**
     * @param mixed $value
     */
    private function decimal($value): float
    {
        return round((float) $value, 4);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function decodeJson($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): string
    {
        if (!is_array($value)) {
            $value = [];
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('POS_METADATA_JSON_FAILED');
        }

        return $encoded;
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

    private function stableTenantInt(string $tenantId): int
    {
        $hash = crc32((string) $tenantId);
        $unsigned = (int) sprintf('%u', $hash);
        $max = 2147483647;
        $value = $unsigned % $max;

        return $value > 0 ? $value : 1;
    }

    private function driver(): string
    {
        return strtolower((string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function bindAll(PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $name => $value) {
            if (is_int($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($name, $value);
        }
    }

    private function sanitizeIdentifier(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $value) !== 1) {
            throw new RuntimeException('Identificador invalido para POS.');
        }

        return $value;
    }

    private function tableExists(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        if ($this->driver() === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
            );
            $stmt->execute([':table_name' => $table]);

            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table_name LIMIT 1");
        $stmt->execute([':table_name' => $table]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '';
    }

    private function columnExists(string $table, string $column): bool
    {
        $table = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '') {
            return false;
        }

        if ($this->driver() === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
            );
            $stmt->execute([
                ':table_name' => $table,
                ':column_name' => $column,
            ]);

            return (int) $stmt->fetchColumn() > 0;
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        if ($safeTable === '') {
            return false;
        }

        $stmt = $this->db->query('PRAGMA table_info(' . $safeTable . ')');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }
}
