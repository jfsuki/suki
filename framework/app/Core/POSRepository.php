<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class POSRepository
{
    private const SESSION_TABLE = 'pos_sessions';
    private const DRAFT_TABLE = 'sale_drafts';
    private const LINE_TABLE = 'sale_draft_lines';

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
            'db/migrations/' . $this->driver() . '/20260310_014_pos_core_architecture.sql'
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
            'unit_price',
            'tax_rate',
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
            'unit_price' => $record['unit_price'] ?? 0,
            'tax_rate' => $record['tax_rate'] ?? null,
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
    public function findDraft(string $tenantId, string $draftId, ?string $appId = null): ?array
    {
        $row = $this->draftQuery($tenantId, $appId)
            ->where('id', '=', $draftId)
            ->first();

        return is_array($row) ? $this->normalizeDraftRow($row) : null;
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
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [self::SESSION_TABLE, self::DRAFT_TABLE, self::LINE_TABLE];
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
                unit_price REAL NOT NULL,
                tax_rate REAL NULL,
                line_total REAL NOT NULL,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sale_draft_lines_tenant_draft ON ' . self::LINE_TABLE . ' (tenant_id, app_id, sale_draft_id, id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sale_draft_lines_tenant_product ON ' . self::LINE_TABLE . ' (tenant_id, product_id, created_at)');
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
                unit_price DECIMAL(18,4) NOT NULL,
                tax_rate DECIMAL(10,4) NULL,
                line_total DECIMAL(18,4) NOT NULL,
                metadata_json JSON NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_sale_draft_lines_tenant_draft (tenant_id, app_id, sale_draft_id, id),
                KEY idx_sale_draft_lines_tenant_product (tenant_id, product_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
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
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'sale_draft_id' => (string) ($row['sale_draft_id'] ?? ''),
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
