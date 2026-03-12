<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class FiscalEngineRepository
{
    private const DOCUMENT_TABLE = 'fiscal_documents';
    private const LINE_TABLE = 'fiscal_document_lines';
    private const EVENT_TABLE = 'fiscal_events';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'FiscalEngineRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260312_021_fiscal_engine_architecture.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createDocument(array $record): array
    {
        $id = $this->insertRecord(self::DOCUMENT_TABLE, [
            'tenant_id',
            'app_id',
            'source_module',
            'source_entity_type',
            'source_entity_id',
            'document_type',
            'document_number',
            'status',
            'issuer_party_id',
            'receiver_party_id',
            'issue_date',
            'currency',
            'subtotal',
            'tax_total',
            'total',
            'external_provider',
            'external_reference',
            'metadata_json',
            'created_at',
            'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'source_module' => $record['source_module'] ?? '',
            'source_entity_type' => $record['source_entity_type'] ?? '',
            'source_entity_id' => $record['source_entity_id'] ?? '',
            'document_type' => $record['document_type'] ?? 'sales_invoice',
            'document_number' => $record['document_number'] ?? null,
            'status' => $record['status'] ?? 'draft',
            'issuer_party_id' => $record['issuer_party_id'] ?? null,
            'receiver_party_id' => $record['receiver_party_id'] ?? null,
            'issue_date' => $record['issue_date'] ?? null,
            'currency' => $record['currency'] ?? null,
            'subtotal' => $record['subtotal'] ?? null,
            'tax_total' => $record['tax_total'] ?? null,
            'total' => $record['total'] ?? null,
            'external_provider' => $record['external_provider'] ?? null,
            'external_reference' => $record['external_reference'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->loadDocumentAggregate((string) ($record['tenant_id'] ?? ''), $id, $this->nullableString($record['app_id'] ?? null));
        if (!is_array($saved)) {
            throw new RuntimeException('FISCAL_DOCUMENT_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateDocument(string $tenantId, string $documentId, array $updates, ?string $appId = null): ?array
    {
        $payload = $this->filterPayload($updates, [
            'document_number',
            'status',
            'issuer_party_id',
            'receiver_party_id',
            'issue_date',
            'currency',
            'subtotal',
            'tax_total',
            'total',
            'external_provider',
            'external_reference',
            'metadata_json',
            'updated_at',
        ]);
        if (array_key_exists('metadata', $updates)) {
            $payload['metadata_json'] = $this->encodeJson($updates['metadata']);
        }
        if (!array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($payload === []) {
            return $this->loadDocumentAggregate($tenantId, $documentId, $appId);
        }

        $this->documentQuery($tenantId, $appId)
            ->where('id', '=', $documentId)
            ->update($payload);

        return $this->loadDocumentAggregate($tenantId, $documentId, $appId);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    public function replaceLines(string $tenantId, string $documentId, array $lines, ?string $appId = null): array
    {
        $this->lineQuery($tenantId, $appId)
            ->where('fiscal_document_id', '=', $documentId)
            ->delete();

        $saved = [];
        foreach ($lines as $line) {
            $saved[] = $this->insertLine($line + [
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'fiscal_document_id' => $documentId,
            ]);
        }

        return $saved;
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
            'fiscal_document_id',
            'product_id',
            'description',
            'qty',
            'unit_amount',
            'tax_rate',
            'line_total',
            'metadata_json',
            'created_at',
            'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'fiscal_document_id' => $record['fiscal_document_id'] ?? '',
            'product_id' => $record['product_id'] ?? null,
            'description' => $record['description'] ?? '',
            'qty' => $record['qty'] ?? null,
            'unit_amount' => $record['unit_amount'] ?? null,
            'tax_rate' => $record['tax_rate'] ?? null,
            'line_total' => $record['line_total'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->findLine(
            (string) ($record['tenant_id'] ?? ''),
            (string) ($record['fiscal_document_id'] ?? ''),
            $id,
            $this->nullableString($record['app_id'] ?? null)
        );
        if (!is_array($saved)) {
            throw new RuntimeException('FISCAL_DOCUMENT_LINE_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createEvent(array $record): array
    {
        $id = $this->insertRecord(self::EVENT_TABLE, [
            'tenant_id',
            'app_id',
            'fiscal_document_id',
            'event_type',
            'event_status',
            'payload_json',
            'created_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'fiscal_document_id' => $record['fiscal_document_id'] ?? '',
            'event_type' => $record['event_type'] ?? '',
            'event_status' => $record['event_status'] ?? 'recorded',
            'payload_json' => $this->encodeJson($record['payload'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $saved = $this->findEvent(
            (string) ($record['tenant_id'] ?? ''),
            (string) ($record['fiscal_document_id'] ?? ''),
            $id,
            $this->nullableString($record['app_id'] ?? null)
        );
        if (!is_array($saved)) {
            throw new RuntimeException('FISCAL_EVENT_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDocument(string $tenantId, string $documentId, ?string $appId = null): ?array
    {
        $row = $this->documentQuery($tenantId, $appId)
            ->where('id', '=', $documentId)
            ->first();

        return is_array($row) ? $this->normalizeDocumentRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadDocumentAggregate(string $tenantId, string $documentId, ?string $appId = null): ?array
    {
        $document = $this->findDocument($tenantId, $documentId, $appId);
        if (!is_array($document)) {
            return null;
        }

        $document['lines'] = $this->listLines($tenantId, $documentId, $appId);
        $document['events'] = $this->listEvents($tenantId, $documentId, $appId);

        return $document;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listDocuments(string $tenantId, array $filters = [], int $limit = 20): array
    {
        $qb = $this->documentQuery($tenantId, $this->nullableString($filters['app_id'] ?? null))
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)));

        foreach ([
            'source_module',
            'source_entity_type',
            'source_entity_id',
            'document_type',
            'status',
            'document_number',
            'external_provider',
            'external_reference',
        ] as $key) {
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

        return array_map([$this, 'normalizeDocumentRow'], $qb->get());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findBySource(
        string $tenantId,
        string $sourceModule,
        string $sourceEntityType,
        string $sourceEntityId,
        ?string $appId = null,
        ?string $documentType = null,
        int $limit = 10
    ): array {
        $qb = $this->documentQuery($tenantId, $appId)
            ->where('source_module', '=', $sourceModule)
            ->where('source_entity_type', '=', $sourceEntityType)
            ->where('source_entity_id', '=', $sourceEntityId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(50, $limit)));

        if ($documentType !== null && $documentType !== '') {
            $qb->where('document_type', '=', $documentType);
        }

        return array_map([$this, 'normalizeDocumentRow'], $qb->get());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLines(string $tenantId, string $documentId, ?string $appId = null): array
    {
        $rows = $this->lineQuery($tenantId, $appId)
            ->where('fiscal_document_id', '=', $documentId)
            ->orderBy('id', 'ASC')
            ->get();

        return array_map([$this, 'normalizeLineRow'], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEvents(string $tenantId, string $documentId, ?string $appId = null, int $limit = 50): array
    {
        $rows = $this->eventQuery($tenantId, $appId)
            ->where('fiscal_document_id', '=', $documentId)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(max(1, min(100, $limit)))
            ->get();

        return array_map([$this, 'normalizeEventRow'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLine(string $tenantId, string $documentId, string $lineId, ?string $appId = null): ?array
    {
        $row = $this->lineQuery($tenantId, $appId)
            ->where('fiscal_document_id', '=', $documentId)
            ->where('id', '=', $lineId)
            ->first();

        return is_array($row) ? $this->normalizeLineRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEvent(string $tenantId, string $documentId, string $eventId, ?string $appId = null): ?array
    {
        $row = $this->eventQuery($tenantId, $appId)
            ->where('fiscal_document_id', '=', $documentId)
            ->where('id', '=', $eventId)
            ->first();

        return is_array($row) ? $this->normalizeEventRow($row) : null;
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
        return [self::DOCUMENT_TABLE, self::LINE_TABLE, self::EVENT_TABLE];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            self::DOCUMENT_TABLE => [
                'idx_fiscal_documents_tenant_source',
                'idx_fiscal_documents_tenant_type_status',
                'idx_fiscal_documents_tenant_provider',
            ],
            self::LINE_TABLE => [
                'idx_fiscal_document_lines_tenant_document',
                'idx_fiscal_document_lines_tenant_product',
            ],
            self::EVENT_TABLE => [
                'idx_fiscal_events_tenant_document',
                'idx_fiscal_events_tenant_type',
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
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::DOCUMENT_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, source_module TEXT NOT NULL, source_entity_type TEXT NOT NULL, source_entity_id TEXT NOT NULL, document_type TEXT NOT NULL, document_number TEXT NULL, status TEXT NOT NULL, issuer_party_id TEXT NULL, receiver_party_id TEXT NULL, issue_date TEXT NULL, currency TEXT NULL, subtotal REAL NULL, tax_total REAL NULL, total REAL NULL, external_provider TEXT NULL, external_reference TEXT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fiscal_documents_tenant_source ON ' . self::DOCUMENT_TABLE . ' (tenant_id, app_id, source_module, source_entity_type, source_entity_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fiscal_documents_tenant_type_status ON ' . self::DOCUMENT_TABLE . ' (tenant_id, app_id, document_type, status, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fiscal_documents_tenant_provider ON ' . self::DOCUMENT_TABLE . ' (tenant_id, app_id, external_provider, external_reference)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::LINE_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, fiscal_document_id TEXT NOT NULL, product_id TEXT NULL, description TEXT NOT NULL, qty REAL NULL, unit_amount REAL NULL, tax_rate REAL NULL, line_total REAL NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fiscal_document_lines_tenant_document ON ' . self::LINE_TABLE . ' (tenant_id, app_id, fiscal_document_id, id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fiscal_document_lines_tenant_product ON ' . self::LINE_TABLE . ' (tenant_id, product_id, created_at)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::EVENT_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, fiscal_document_id TEXT NOT NULL, event_type TEXT NOT NULL, event_status TEXT NOT NULL, payload_json TEXT NULL, created_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fiscal_events_tenant_document ON ' . self::EVENT_TABLE . ' (tenant_id, app_id, fiscal_document_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fiscal_events_tenant_type ON ' . self::EVENT_TABLE . ' (tenant_id, event_type, created_at)');
    }

    private function ensureSchemaMySql(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::DOCUMENT_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, source_module VARCHAR(64) NOT NULL, source_entity_type VARCHAR(64) NOT NULL, source_entity_id VARCHAR(190) NOT NULL, document_type VARCHAR(64) NOT NULL, document_number VARCHAR(190) NULL, status VARCHAR(32) NOT NULL, issuer_party_id VARCHAR(190) NULL, receiver_party_id VARCHAR(190) NULL, issue_date DATETIME NULL, currency VARCHAR(16) NULL, subtotal DECIMAL(18,4) NULL, tax_total DECIMAL(18,4) NULL, total DECIMAL(18,4) NULL, external_provider VARCHAR(120) NULL, external_reference VARCHAR(190) NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_fiscal_documents_tenant_source (tenant_id, app_id, source_module, source_entity_type, source_entity_id, created_at), KEY idx_fiscal_documents_tenant_type_status (tenant_id, app_id, document_type, status, created_at), KEY idx_fiscal_documents_tenant_provider (tenant_id, app_id, external_provider, external_reference)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::LINE_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, fiscal_document_id VARCHAR(120) NOT NULL, product_id VARCHAR(190) NULL, description VARCHAR(255) NOT NULL, qty DECIMAL(18,4) NULL, unit_amount DECIMAL(18,4) NULL, tax_rate DECIMAL(10,4) NULL, line_total DECIMAL(18,4) NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_fiscal_document_lines_tenant_document (tenant_id, app_id, fiscal_document_id, id), KEY idx_fiscal_document_lines_tenant_product (tenant_id, product_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::EVENT_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, fiscal_document_id VARCHAR(120) NOT NULL, event_type VARCHAR(64) NOT NULL, event_status VARCHAR(64) NOT NULL, payload_json JSON NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_fiscal_events_tenant_document (tenant_id, app_id, fiscal_document_id, created_at), KEY idx_fiscal_events_tenant_type (tenant_id, event_type, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function documentQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::DOCUMENT_TABLE))
            ->setAllowedColumns([
                'id', 'tenant_id', 'app_id', 'source_module', 'source_entity_type', 'source_entity_id',
                'document_type', 'document_number', 'status', 'issuer_party_id', 'receiver_party_id',
                'issue_date', 'currency', 'subtotal', 'tax_total', 'total', 'external_provider',
                'external_reference', 'metadata_json', 'created_at', 'updated_at',
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
                'id', 'tenant_id', 'app_id', 'fiscal_document_id', 'product_id', 'description',
                'qty', 'unit_amount', 'tax_rate', 'line_total', 'metadata_json', 'created_at', 'updated_at',
            ])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function eventQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::EVENT_TABLE))
            ->setAllowedColumns([
                'id', 'tenant_id', 'app_id', 'fiscal_document_id', 'event_type', 'event_status',
                'payload_json', 'created_at',
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
    private function normalizeDocumentRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'source_module' => trim((string) ($row['source_module'] ?? '')),
            'source_entity_type' => trim((string) ($row['source_entity_type'] ?? '')),
            'source_entity_id' => trim((string) ($row['source_entity_id'] ?? '')),
            'document_type' => trim((string) ($row['document_type'] ?? 'sales_invoice')) ?: 'sales_invoice',
            'document_number' => $this->nullableString($row['document_number'] ?? null),
            'status' => trim((string) ($row['status'] ?? 'draft')) ?: 'draft',
            'issuer_party_id' => $this->nullableString($row['issuer_party_id'] ?? null),
            'receiver_party_id' => $this->nullableString($row['receiver_party_id'] ?? null),
            'issue_date' => $this->nullableString($row['issue_date'] ?? null),
            'currency' => $this->nullableString($row['currency'] ?? null),
            'subtotal' => $row['subtotal'] === null ? null : $this->decimal($row['subtotal']),
            'tax_total' => $row['tax_total'] === null ? null : $this->decimal($row['tax_total']),
            'total' => $row['total'] === null ? null : $this->decimal($row['total']),
            'external_provider' => $this->nullableString($row['external_provider'] ?? null),
            'external_reference' => $this->nullableString($row['external_reference'] ?? null),
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
            'fiscal_document_id' => (string) ($row['fiscal_document_id'] ?? ''),
            'product_id' => $this->nullableString($row['product_id'] ?? null),
            'description' => (string) ($row['description'] ?? ''),
            'qty' => $row['qty'] === null ? null : $this->decimal($row['qty']),
            'unit_amount' => $row['unit_amount'] === null ? null : $this->decimal($row['unit_amount']),
            'tax_rate' => $row['tax_rate'] === null ? null : $this->decimal($row['tax_rate']),
            'line_total' => $row['line_total'] === null ? null : $this->decimal($row['line_total']),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeEventRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'fiscal_document_id' => (string) ($row['fiscal_document_id'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'event_status' => (string) ($row['event_status'] ?? ''),
            'payload' => $this->decodeJson($row['payload_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
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
