<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class PurchasesRepository
{
    private const DRAFT_TABLE = 'purchase_drafts';
    private const LINE_TABLE = 'purchase_draft_lines';
    private const PURCHASE_TABLE = 'purchases';
    private const PURCHASE_LINE_TABLE = 'purchase_lines';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'PurchasesRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260311_019_purchases_module_core.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createDraft(array $record): array
    {
        $id = $this->insertRecord(self::DRAFT_TABLE, [
            'tenant_id', 'app_id', 'supplier_id', 'status', 'currency', 'subtotal',
            'tax_total', 'total', 'notes', 'metadata_json', 'created_at', 'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'supplier_id' => $record['supplier_id'] ?? null,
            'status' => $record['status'] ?? 'open',
            'currency' => $record['currency'] ?? null,
            'subtotal' => $record['subtotal'] ?? 0,
            'tax_total' => $record['tax_total'] ?? 0,
            'total' => $record['total'] ?? 0,
            'notes' => $record['notes'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->loadDraftAggregate((string) ($record['tenant_id'] ?? ''), $id, $this->nullableString($record['app_id'] ?? null));
        if (!is_array($saved)) {
            throw new RuntimeException('PURCHASE_DRAFT_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateDraft(string $tenantId, string $draftId, array $updates, ?string $appId = null): ?array
    {
        $payload = $this->filterPayload($updates, [
            'supplier_id', 'status', 'currency', 'subtotal', 'tax_total', 'total', 'notes', 'metadata_json', 'updated_at',
        ]);
        if (array_key_exists('metadata', $updates)) {
            $payload['metadata_json'] = $this->encodeJson($updates['metadata']);
        }
        if (!array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($payload === []) {
            return $this->loadDraftAggregate($tenantId, $draftId, $appId);
        }

        $this->draftQuery($tenantId, $appId)->where('id', '=', $draftId)->update($payload);

        return $this->loadDraftAggregate($tenantId, $draftId, $appId);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function insertLine(array $record): array
    {
        $id = $this->insertRecord(self::LINE_TABLE, [
            'tenant_id', 'app_id', 'purchase_draft_id', 'product_id', 'sku', 'supplier_sku',
            'product_label', 'qty', 'unit_cost', 'tax_rate', 'line_total', 'metadata_json', 'created_at', 'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'purchase_draft_id' => $record['purchase_draft_id'] ?? '',
            'product_id' => $record['product_id'] ?? null,
            'sku' => $record['sku'] ?? null,
            'supplier_sku' => $record['supplier_sku'] ?? null,
            'product_label' => $record['product_label'] ?? '',
            'qty' => $record['qty'] ?? 0,
            'unit_cost' => $record['unit_cost'] ?? 0,
            'tax_rate' => $record['tax_rate'] ?? null,
            'line_total' => $record['line_total'] ?? 0,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->findLine(
            (string) ($record['tenant_id'] ?? ''),
            (string) ($record['purchase_draft_id'] ?? ''),
            $id,
            $this->nullableString($record['app_id'] ?? null)
        );
        if (!is_array($saved)) {
            throw new RuntimeException('PURCHASE_DRAFT_LINE_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateLine(string $tenantId, string $draftId, string $lineId, array $updates, ?string $appId = null): ?array
    {
        $payload = $this->filterPayload($updates, [
            'product_id', 'sku', 'supplier_sku', 'product_label', 'qty', 'unit_cost', 'tax_rate', 'line_total', 'metadata_json', 'updated_at',
        ]);
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
            ->where('purchase_draft_id', '=', $draftId)
            ->where('id', '=', $lineId)
            ->update($payload);

        return $this->findLine($tenantId, $draftId, $lineId, $appId);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createPurchase(array $record): array
    {
        $id = $this->insertRecord(self::PURCHASE_TABLE, [
            'tenant_id', 'app_id', 'purchase_number', 'supplier_id', 'draft_id', 'status',
            'currency', 'subtotal', 'tax_total', 'total', 'notes', 'created_by_user_id', 'metadata_json', 'created_at', 'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'purchase_number' => $record['purchase_number'] ?? null,
            'supplier_id' => $record['supplier_id'] ?? null,
            'draft_id' => $record['draft_id'] ?? null,
            'status' => $record['status'] ?? 'registered',
            'currency' => $record['currency'] ?? null,
            'subtotal' => $record['subtotal'] ?? 0,
            'tax_total' => $record['tax_total'] ?? 0,
            'total' => $record['total'] ?? 0,
            'notes' => $record['notes'] ?? null,
            'created_by_user_id' => $record['created_by_user_id'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->loadPurchaseAggregate((string) ($record['tenant_id'] ?? ''), $id, $this->nullableString($record['app_id'] ?? null));
        if (!is_array($saved)) {
            throw new RuntimeException('PURCHASE_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updatePurchase(string $tenantId, string $purchaseId, array $updates, ?string $appId = null): ?array
    {
        $payload = $this->filterPayload($updates, [
            'purchase_number', 'supplier_id', 'draft_id', 'status', 'currency', 'subtotal',
            'tax_total', 'total', 'notes', 'created_by_user_id', 'metadata_json', 'updated_at',
        ]);
        if (array_key_exists('metadata', $updates)) {
            $payload['metadata_json'] = $this->encodeJson($updates['metadata']);
        }
        if (!array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($payload === []) {
            return $this->loadPurchaseAggregate($tenantId, $purchaseId, $appId);
        }

        $this->purchaseQuery($tenantId, $appId)->where('id', '=', $purchaseId)->update($payload);

        return $this->loadPurchaseAggregate($tenantId, $purchaseId, $appId);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function insertPurchaseLine(array $record): array
    {
        $id = $this->insertRecord(self::PURCHASE_LINE_TABLE, [
            'tenant_id', 'app_id', 'purchase_id', 'product_id', 'sku', 'supplier_sku',
            'product_label', 'qty', 'unit_cost', 'tax_rate', 'line_total', 'metadata_json', 'created_at', 'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'purchase_id' => $record['purchase_id'] ?? '',
            'product_id' => $record['product_id'] ?? null,
            'sku' => $record['sku'] ?? null,
            'supplier_sku' => $record['supplier_sku'] ?? null,
            'product_label' => $record['product_label'] ?? '',
            'qty' => $record['qty'] ?? 0,
            'unit_cost' => $record['unit_cost'] ?? 0,
            'tax_rate' => $record['tax_rate'] ?? null,
            'line_total' => $record['line_total'] ?? 0,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->findPurchaseLine(
            (string) ($record['tenant_id'] ?? ''),
            (string) ($record['purchase_id'] ?? ''),
            $id,
            $this->nullableString($record['app_id'] ?? null)
        );
        if (!is_array($saved)) {
            throw new RuntimeException('PURCHASE_LINE_INSERT_FETCH_FAILED');
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
    public function loadPurchaseAggregate(string $tenantId, string $purchaseId, ?string $appId = null): ?array
    {
        $purchase = $this->findPurchase($tenantId, $purchaseId, $appId);
        if (!is_array($purchase)) {
            return null;
        }

        $purchase['lines'] = $this->listPurchaseLines($tenantId, $purchaseId, $appId);

        return $purchase;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDraft(string $tenantId, string $draftId, ?string $appId = null): ?array
    {
        $row = $this->draftQuery($tenantId, $appId)->where('id', '=', $draftId)->first();

        return is_array($row) ? $this->normalizeDraftRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPurchase(string $tenantId, string $purchaseId, ?string $appId = null): ?array
    {
        $row = $this->purchaseQuery($tenantId, $appId)->where('id', '=', $purchaseId)->first();

        return is_array($row) ? $this->normalizePurchaseRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPurchaseByNumber(string $tenantId, string $purchaseNumber, ?string $appId = null): ?array
    {
        $row = $this->purchaseQuery($tenantId, $appId)->where('purchase_number', '=', $purchaseNumber)->first();

        return is_array($row) ? $this->normalizePurchaseRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPurchaseByDraftId(string $tenantId, string $draftId, ?string $appId = null): ?array
    {
        $row = $this->purchaseQuery($tenantId, $appId)
            ->where('draft_id', '=', $draftId)
            ->orderBy('id', 'DESC')
            ->first();

        return is_array($row) ? $this->normalizePurchaseRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listPurchases(string $tenantId, array $filters = [], int $limit = 20): array
    {
        $qb = $this->purchaseQuery($tenantId, $this->nullableString($filters['app_id'] ?? null))
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)));

        foreach (['status', 'supplier_id', 'draft_id', 'purchase_number'] as $key) {
            $value = $this->nullableString($filters[$key] ?? null);
            if ($value !== null) {
                $qb->where($key, '=', $value);
            }
        }
        $dateFrom = $this->nullableString($filters['date_from'] ?? null);
        if ($dateFrom !== null) {
            $qb->where('created_at', '>=', $dateFrom);
        }
        $dateTo = $this->nullableString($filters['date_to'] ?? null);
        if ($dateTo !== null) {
            $qb->where('created_at', '<=', $dateTo);
        }

        return array_map(fn(array $row): array => $this->normalizePurchaseRow($row), $qb->get());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLines(string $tenantId, string $draftId, ?string $appId = null): array
    {
        $rows = $this->lineQuery($tenantId, $appId)
            ->where('purchase_draft_id', '=', $draftId)
            ->orderBy('id', 'ASC')
            ->get();

        return array_map(fn(array $row): array => $this->normalizeLineRow($row), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPurchaseLines(string $tenantId, string $purchaseId, ?string $appId = null): array
    {
        $rows = $this->purchaseLineQuery($tenantId, $appId)
            ->where('purchase_id', '=', $purchaseId)
            ->orderBy('id', 'ASC')
            ->get();

        return array_map(fn(array $row): array => $this->normalizePurchaseLineRow($row), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLine(string $tenantId, string $draftId, string $lineId, ?string $appId = null): ?array
    {
        $row = $this->lineQuery($tenantId, $appId)
            ->where('purchase_draft_id', '=', $draftId)
            ->where('id', '=', $lineId)
            ->first();

        return is_array($row) ? $this->normalizeLineRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPurchaseLine(string $tenantId, string $purchaseId, string $lineId, ?string $appId = null): ?array
    {
        $row = $this->purchaseLineQuery($tenantId, $appId)
            ->where('purchase_id', '=', $purchaseId)
            ->where('id', '=', $lineId)
            ->first();

        return is_array($row) ? $this->normalizePurchaseLineRow($row) : null;
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
            ->where('purchase_draft_id', '=', $draftId)
            ->where('id', '=', $lineId)
            ->delete();

        return $current;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback)
    {
        $inTransaction = $this->db->inTransaction();
        if (!$inTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $callback();
            if (!$inTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if (!$inTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [self::DRAFT_TABLE, self::LINE_TABLE, self::PURCHASE_TABLE, self::PURCHASE_LINE_TABLE];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            self::DRAFT_TABLE => ['idx_purchase_drafts_tenant_app_status', 'idx_purchase_drafts_tenant_supplier'],
            self::LINE_TABLE => ['idx_purchase_draft_lines_tenant_draft', 'idx_purchase_draft_lines_tenant_product'],
            self::PURCHASE_TABLE => ['idx_purchases_tenant_app_created', 'idx_purchases_tenant_number', 'idx_purchases_tenant_draft'],
            self::PURCHASE_LINE_TABLE => ['idx_purchase_lines_tenant_purchase', 'idx_purchase_lines_tenant_product'],
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
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::DRAFT_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, supplier_id TEXT NULL, status TEXT NOT NULL, currency TEXT NULL, subtotal REAL NOT NULL DEFAULT 0, tax_total REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0, notes TEXT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_drafts_tenant_app_status ON ' . self::DRAFT_TABLE . ' (tenant_id, app_id, status, updated_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_drafts_tenant_supplier ON ' . self::DRAFT_TABLE . ' (tenant_id, supplier_id, updated_at)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::LINE_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, purchase_draft_id TEXT NOT NULL, product_id TEXT NULL, sku TEXT NULL, supplier_sku TEXT NULL, product_label TEXT NOT NULL, qty REAL NOT NULL, unit_cost REAL NOT NULL, tax_rate REAL NULL, line_total REAL NOT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_draft_lines_tenant_draft ON ' . self::LINE_TABLE . ' (tenant_id, app_id, purchase_draft_id, id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_draft_lines_tenant_product ON ' . self::LINE_TABLE . ' (tenant_id, product_id, created_at)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::PURCHASE_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, purchase_number TEXT NULL, supplier_id TEXT NULL, draft_id TEXT NULL, status TEXT NOT NULL, currency TEXT NULL, subtotal REAL NOT NULL DEFAULT 0, tax_total REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0, notes TEXT NULL, created_by_user_id TEXT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_purchases_tenant_app_created ON ' . self::PURCHASE_TABLE . ' (tenant_id, app_id, created_at, id)');
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_purchases_tenant_number ON ' . self::PURCHASE_TABLE . ' (tenant_id, app_id, purchase_number)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_purchases_tenant_draft ON ' . self::PURCHASE_TABLE . ' (tenant_id, app_id, draft_id)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::PURCHASE_LINE_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, purchase_id TEXT NOT NULL, product_id TEXT NULL, sku TEXT NULL, supplier_sku TEXT NULL, product_label TEXT NOT NULL, qty REAL NOT NULL, unit_cost REAL NOT NULL, tax_rate REAL NULL, line_total REAL NOT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_lines_tenant_purchase ON ' . self::PURCHASE_LINE_TABLE . ' (tenant_id, app_id, purchase_id, id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_purchase_lines_tenant_product ON ' . self::PURCHASE_LINE_TABLE . ' (tenant_id, product_id, created_at)');
    }

    private function ensureSchemaMySql(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::DRAFT_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, supplier_id VARCHAR(190) NULL, status VARCHAR(32) NOT NULL, currency VARCHAR(16) NULL, subtotal DECIMAL(18,4) NOT NULL DEFAULT 0, tax_total DECIMAL(18,4) NOT NULL DEFAULT 0, total DECIMAL(18,4) NOT NULL DEFAULT 0, notes TEXT NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_purchase_drafts_tenant_app_status (tenant_id, app_id, status, updated_at), KEY idx_purchase_drafts_tenant_supplier (tenant_id, supplier_id, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::LINE_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, purchase_draft_id VARCHAR(120) NOT NULL, product_id VARCHAR(190) NULL, sku VARCHAR(190) NULL, supplier_sku VARCHAR(190) NULL, product_label VARCHAR(255) NOT NULL, qty DECIMAL(18,4) NOT NULL, unit_cost DECIMAL(18,4) NOT NULL, tax_rate DECIMAL(10,4) NULL, line_total DECIMAL(18,4) NOT NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_purchase_draft_lines_tenant_draft (tenant_id, app_id, purchase_draft_id, id), KEY idx_purchase_draft_lines_tenant_product (tenant_id, product_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::PURCHASE_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, purchase_number VARCHAR(190) NULL, supplier_id VARCHAR(190) NULL, draft_id VARCHAR(120) NULL, status VARCHAR(32) NOT NULL, currency VARCHAR(16) NULL, subtotal DECIMAL(18,4) NOT NULL DEFAULT 0, tax_total DECIMAL(18,4) NOT NULL DEFAULT 0, total DECIMAL(18,4) NOT NULL DEFAULT 0, notes TEXT NULL, created_by_user_id VARCHAR(120) NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY idx_purchases_tenant_number (tenant_id, app_id, purchase_number), KEY idx_purchases_tenant_app_created (tenant_id, app_id, created_at, id), KEY idx_purchases_tenant_draft (tenant_id, app_id, draft_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::PURCHASE_LINE_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, purchase_id VARCHAR(120) NOT NULL, product_id VARCHAR(190) NULL, sku VARCHAR(190) NULL, supplier_sku VARCHAR(190) NULL, product_label VARCHAR(255) NOT NULL, qty DECIMAL(18,4) NOT NULL, unit_cost DECIMAL(18,4) NOT NULL, tax_rate DECIMAL(10,4) NULL, line_total DECIMAL(18,4) NOT NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_purchase_lines_tenant_purchase (tenant_id, app_id, purchase_id, id), KEY idx_purchase_lines_tenant_product (tenant_id, product_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function draftQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::DRAFT_TABLE))
            ->setAllowedColumns(['id', 'tenant_id', 'app_id', 'supplier_id', 'status', 'currency', 'subtotal', 'tax_total', 'total', 'notes', 'metadata_json', 'created_at', 'updated_at'])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function lineQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::LINE_TABLE))
            ->setAllowedColumns(['id', 'tenant_id', 'app_id', 'purchase_draft_id', 'product_id', 'sku', 'supplier_sku', 'product_label', 'qty', 'unit_cost', 'tax_rate', 'line_total', 'metadata_json', 'created_at', 'updated_at'])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function purchaseQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::PURCHASE_TABLE))
            ->setAllowedColumns(['id', 'tenant_id', 'app_id', 'purchase_number', 'supplier_id', 'draft_id', 'status', 'currency', 'subtotal', 'tax_total', 'total', 'notes', 'created_by_user_id', 'metadata_json', 'created_at', 'updated_at'])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function purchaseLineQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::PURCHASE_LINE_TABLE))
            ->setAllowedColumns(['id', 'tenant_id', 'app_id', 'purchase_id', 'product_id', 'sku', 'supplier_sku', 'product_label', 'qty', 'unit_cost', 'tax_rate', 'line_total', 'metadata_json', 'created_at', 'updated_at'])
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
        return (string) ((new QueryBuilder($this->db, $table))->setAllowedColumns($columns)->insert($values));
    }

    /**
     * @param array<string, mixed> $updates
     * @param array<int, string> $allowed
     * @return array<string, mixed>
     */
    private function filterPayload(array $updates, array $allowed): array
    {
        $payload = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $updates)) {
                $payload[$column] = $updates[$column];
            }
        }

        return $payload;
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
            'supplier_id' => $this->nullableString($row['supplier_id'] ?? null),
            'status' => trim((string) ($row['status'] ?? 'open')) ?: 'open',
            'currency' => $this->nullableString($row['currency'] ?? null),
            'subtotal' => $this->decimal($row['subtotal'] ?? 0),
            'tax_total' => $this->decimal($row['tax_total'] ?? 0),
            'total' => $this->decimal($row['total'] ?? 0),
            'notes' => $this->nullableString($row['notes'] ?? null),
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
            'purchase_draft_id' => (string) ($row['purchase_draft_id'] ?? ''),
            'product_id' => $this->nullableString($row['product_id'] ?? null),
            'sku' => $this->nullableString($row['sku'] ?? null),
            'supplier_sku' => $this->nullableString($row['supplier_sku'] ?? null),
            'product_label' => (string) ($row['product_label'] ?? ''),
            'qty' => $this->decimal($row['qty'] ?? 0),
            'unit_cost' => $this->decimal($row['unit_cost'] ?? 0),
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
    private function normalizePurchaseRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'purchase_number' => $this->nullableString($row['purchase_number'] ?? null),
            'supplier_id' => $this->nullableString($row['supplier_id'] ?? null),
            'draft_id' => $this->nullableString($row['draft_id'] ?? null),
            'status' => trim((string) ($row['status'] ?? 'registered')) ?: 'registered',
            'currency' => $this->nullableString($row['currency'] ?? null),
            'subtotal' => $this->decimal($row['subtotal'] ?? 0),
            'tax_total' => $this->decimal($row['tax_total'] ?? 0),
            'total' => $this->decimal($row['total'] ?? 0),
            'notes' => $this->nullableString($row['notes'] ?? null),
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
    private function normalizePurchaseLineRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'purchase_id' => (string) ($row['purchase_id'] ?? ''),
            'product_id' => $this->nullableString($row['product_id'] ?? null),
            'sku' => $this->nullableString($row['sku'] ?? null),
            'supplier_sku' => $this->nullableString($row['supplier_sku'] ?? null),
            'product_label' => (string) ($row['product_label'] ?? ''),
            'qty' => $this->decimal($row['qty'] ?? 0),
            'unit_cost' => $this->decimal($row['unit_cost'] ?? 0),
            'tax_rate' => $row['tax_rate'] === null ? null : $this->decimal($row['tax_rate']),
            'line_total' => $this->decimal($row['line_total'] ?? 0),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function decimal($value): float
    {
        return ($value === null || $value === '') ? 0.0 : round((float) $value, 4);
    }

    /**
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

    private function encodeJson($value): string
    {
        $encoded = json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '{}' : $encoded;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function driver(): string
    {
        $driver = strtolower((string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME));

        return $driver !== '' ? $driver : 'sqlite';
    }
}
