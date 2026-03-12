<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class EcommerceHubRepository
{
    private const STORE_TABLE = 'ecommerce_stores';
    private const CREDENTIAL_TABLE = 'ecommerce_credentials';
    private const SYNC_JOB_TABLE = 'ecommerce_sync_jobs';
    private const ORDER_REF_TABLE = 'ecommerce_order_refs';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'EcommerceHubRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260312_022_ecommerce_hub_architecture.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createStore(array $record): array
    {
        $id = $this->insertRecord(self::STORE_TABLE, [
            'tenant_id',
            'app_id',
            'platform',
            'store_name',
            'store_url',
            'status',
            'connection_status',
            'currency',
            'timezone',
            'metadata_json',
            'created_at',
            'updated_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'platform' => $record['platform'] ?? 'unknown',
            'store_name' => $record['store_name'] ?? '',
            'store_url' => $record['store_url'] ?? null,
            'status' => $record['status'] ?? 'active',
            'connection_status' => $record['connection_status'] ?? 'not_configured',
            'currency' => $record['currency'] ?? null,
            'timezone' => $record['timezone'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')),
        ]);

        $saved = $this->findStore((string) ($record['tenant_id'] ?? ''), $id, $this->nullableString($record['app_id'] ?? null));
        if (!is_array($saved)) {
            throw new RuntimeException('ECOMMERCE_STORE_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateStore(string $tenantId, string $storeId, array $updates, ?string $appId = null): ?array
    {
        $payload = $this->filterPayload($updates, [
            'platform',
            'store_name',
            'store_url',
            'status',
            'connection_status',
            'currency',
            'timezone',
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
            return $this->findStore($tenantId, $storeId, $appId);
        }

        $this->storeQuery($tenantId, $appId)
            ->where('id', '=', $storeId)
            ->update($payload);

        return $this->findStore($tenantId, $storeId, $appId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findStore(string $tenantId, string $storeId, ?string $appId = null): ?array
    {
        $row = $this->storeQuery($tenantId, $appId)
            ->where('id', '=', $storeId)
            ->first();

        return is_array($row) ? $this->normalizeStoreRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listStores(string $tenantId, array $filters = [], int $limit = 20): array
    {
        $qb = $this->storeQuery($tenantId, $this->nullableString($filters['app_id'] ?? null))
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)));

        foreach (['platform', 'status', 'connection_status'] as $key) {
            $value = $this->nullableString($filters[$key] ?? null);
            if ($value !== null) {
                $qb->where($key, '=', $value);
            }
        }

        return array_map([$this, 'normalizeStoreRow'], $qb->get());
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createCredential(array $record): array
    {
        $id = $this->insertRecord(self::CREDENTIAL_TABLE, [
            'tenant_id',
            'app_id',
            'store_id',
            'credential_type',
            'encrypted_payload',
            'status',
            'last_validated_at',
            'metadata_json',
            'created_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'store_id' => $record['store_id'] ?? '',
            'credential_type' => $record['credential_type'] ?? '',
            'encrypted_payload' => $record['encrypted_payload'] ?? '',
            'status' => $record['status'] ?? 'active',
            'last_validated_at' => $record['last_validated_at'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $saved = $this->findCredential((string) ($record['tenant_id'] ?? ''), $id, $this->nullableString($record['app_id'] ?? null));
        if (!is_array($saved)) {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCredential(string $tenantId, string $credentialId, ?string $appId = null): ?array
    {
        $row = $this->credentialQuery($tenantId, $appId)
            ->where('id', '=', $credentialId)
            ->first();

        return is_array($row) ? $this->normalizeCredentialRow($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCredentialsByStore(string $tenantId, string $storeId, ?string $appId = null): array
    {
        $rows = $this->credentialQuery($tenantId, $appId)
            ->where('store_id', '=', $storeId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();

        return array_map([$this, 'normalizeCredentialRow'], $rows);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createSyncJob(array $record): array
    {
        $id = $this->insertRecord(self::SYNC_JOB_TABLE, [
            'tenant_id',
            'app_id',
            'store_id',
            'sync_type',
            'status',
            'started_at',
            'finished_at',
            'result_summary',
            'metadata_json',
            'created_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'store_id' => $record['store_id'] ?? '',
            'sync_type' => $record['sync_type'] ?? '',
            'status' => $record['status'] ?? 'queued',
            'started_at' => $record['started_at'] ?? null,
            'finished_at' => $record['finished_at'] ?? null,
            'result_summary' => $record['result_summary'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $saved = $this->findSyncJob((string) ($record['tenant_id'] ?? ''), $id, $this->nullableString($record['app_id'] ?? null));
        if (!is_array($saved)) {
            throw new RuntimeException('ECOMMERCE_SYNC_JOB_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateSyncJob(string $tenantId, string $syncJobId, array $updates, ?string $appId = null): ?array
    {
        $payload = $this->filterPayload($updates, [
            'status',
            'started_at',
            'finished_at',
            'result_summary',
            'metadata_json',
        ]);
        if (array_key_exists('metadata', $updates)) {
            $payload['metadata_json'] = $this->encodeJson($updates['metadata']);
        }
        if ($payload === []) {
            return $this->findSyncJob($tenantId, $syncJobId, $appId);
        }

        $this->syncJobQuery($tenantId, $appId)
            ->where('id', '=', $syncJobId)
            ->update($payload);

        return $this->findSyncJob($tenantId, $syncJobId, $appId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSyncJob(string $tenantId, string $syncJobId, ?string $appId = null): ?array
    {
        $row = $this->syncJobQuery($tenantId, $appId)
            ->where('id', '=', $syncJobId)
            ->first();

        return is_array($row) ? $this->normalizeSyncJobRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listSyncJobs(string $tenantId, array $filters = [], int $limit = 20): array
    {
        $qb = $this->syncJobQuery($tenantId, $this->nullableString($filters['app_id'] ?? null))
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)));

        foreach (['store_id', 'sync_type', 'status'] as $key) {
            $value = $this->nullableString($filters[$key] ?? null);
            if ($value !== null) {
                $qb->where($key, '=', $value);
            }
        }

        return array_map([$this, 'normalizeSyncJobRow'], $qb->get());
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createOrderRef(array $record): array
    {
        $id = $this->insertRecord(self::ORDER_REF_TABLE, [
            'tenant_id',
            'app_id',
            'store_id',
            'external_order_id',
            'local_order_status',
            'external_status',
            'total',
            'currency',
            'metadata_json',
            'created_at',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'store_id' => $record['store_id'] ?? '',
            'external_order_id' => $record['external_order_id'] ?? '',
            'local_order_status' => $record['local_order_status'] ?? null,
            'external_status' => $record['external_status'] ?? null,
            'total' => $record['total'] ?? null,
            'currency' => $record['currency'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $saved = $this->findOrderRef((string) ($record['tenant_id'] ?? ''), $id, $this->nullableString($record['app_id'] ?? null));
        if (!is_array($saved)) {
            throw new RuntimeException('ECOMMERCE_ORDER_REF_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOrderRef(string $tenantId, string $orderRefId, ?string $appId = null): ?array
    {
        $row = $this->orderRefQuery($tenantId, $appId)
            ->where('id', '=', $orderRefId)
            ->first();

        return is_array($row) ? $this->normalizeOrderRefRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listOrderRefs(string $tenantId, array $filters = [], int $limit = 20): array
    {
        $qb = $this->orderRefQuery($tenantId, $this->nullableString($filters['app_id'] ?? null))
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)));

        foreach (['store_id', 'external_order_id', 'local_order_status', 'external_status', 'currency'] as $key) {
            $value = $this->nullableString($filters[$key] ?? null);
            if ($value !== null) {
                $qb->where($key, '=', $value);
            }
        }

        return array_map([$this, 'normalizeOrderRefRow'], $qb->get());
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
        return [
            self::STORE_TABLE,
            self::CREDENTIAL_TABLE,
            self::SYNC_JOB_TABLE,
            self::ORDER_REF_TABLE,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            self::STORE_TABLE => [
                'idx_ecommerce_stores_tenant_platform_status',
                'idx_ecommerce_stores_tenant_connection',
            ],
            self::CREDENTIAL_TABLE => [
                'idx_ecommerce_credentials_tenant_store_status',
                'idx_ecommerce_credentials_tenant_type',
            ],
            self::SYNC_JOB_TABLE => [
                'idx_ecommerce_sync_jobs_tenant_store_status',
                'idx_ecommerce_sync_jobs_tenant_type',
            ],
            self::ORDER_REF_TABLE => [
                'idx_ecommerce_order_refs_tenant_store_external',
                'idx_ecommerce_order_refs_tenant_status',
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
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::STORE_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, platform TEXT NOT NULL, store_name TEXT NOT NULL, store_url TEXT NULL, status TEXT NOT NULL, connection_status TEXT NOT NULL, currency TEXT NULL, timezone TEXT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ecommerce_stores_tenant_platform_status ON ' . self::STORE_TABLE . ' (tenant_id, app_id, platform, status, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ecommerce_stores_tenant_connection ON ' . self::STORE_TABLE . ' (tenant_id, app_id, connection_status, created_at)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::CREDENTIAL_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, store_id TEXT NOT NULL, credential_type TEXT NOT NULL, encrypted_payload TEXT NOT NULL, status TEXT NOT NULL, last_validated_at TEXT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ecommerce_credentials_tenant_store_status ON ' . self::CREDENTIAL_TABLE . ' (tenant_id, app_id, store_id, status, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ecommerce_credentials_tenant_type ON ' . self::CREDENTIAL_TABLE . ' (tenant_id, credential_type, created_at)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::SYNC_JOB_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, store_id TEXT NOT NULL, sync_type TEXT NOT NULL, status TEXT NOT NULL, started_at TEXT NULL, finished_at TEXT NULL, result_summary TEXT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ecommerce_sync_jobs_tenant_store_status ON ' . self::SYNC_JOB_TABLE . ' (tenant_id, app_id, store_id, status, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ecommerce_sync_jobs_tenant_type ON ' . self::SYNC_JOB_TABLE . ' (tenant_id, sync_type, created_at)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::ORDER_REF_TABLE . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, app_id TEXT NULL, store_id TEXT NOT NULL, external_order_id TEXT NOT NULL, local_order_status TEXT NULL, external_status TEXT NULL, total REAL NULL, currency TEXT NULL, metadata_json TEXT NULL, created_at TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ecommerce_order_refs_tenant_store_external ON ' . self::ORDER_REF_TABLE . ' (tenant_id, app_id, store_id, external_order_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ecommerce_order_refs_tenant_status ON ' . self::ORDER_REF_TABLE . ' (tenant_id, store_id, local_order_status, external_status, created_at)');
    }

    private function ensureSchemaMySql(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::STORE_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, platform VARCHAR(64) NOT NULL, store_name VARCHAR(190) NOT NULL, store_url VARCHAR(255) NULL, status VARCHAR(32) NOT NULL, connection_status VARCHAR(32) NOT NULL, currency VARCHAR(16) NULL, timezone VARCHAR(64) NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_ecommerce_stores_tenant_platform_status (tenant_id, app_id, platform, status, created_at), KEY idx_ecommerce_stores_tenant_connection (tenant_id, app_id, connection_status, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::CREDENTIAL_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, store_id VARCHAR(120) NOT NULL, credential_type VARCHAR(64) NOT NULL, encrypted_payload LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL, last_validated_at DATETIME NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_ecommerce_credentials_tenant_store_status (tenant_id, app_id, store_id, status, created_at), KEY idx_ecommerce_credentials_tenant_type (tenant_id, credential_type, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::SYNC_JOB_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, store_id VARCHAR(120) NOT NULL, sync_type VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, started_at DATETIME NULL, finished_at DATETIME NULL, result_summary TEXT NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_ecommerce_sync_jobs_tenant_store_status (tenant_id, app_id, store_id, status, created_at), KEY idx_ecommerce_sync_jobs_tenant_type (tenant_id, sync_type, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->exec("CREATE TABLE IF NOT EXISTS " . self::ORDER_REF_TABLE . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id VARCHAR(120) NOT NULL, app_id VARCHAR(120) NULL, store_id VARCHAR(120) NOT NULL, external_order_id VARCHAR(190) NOT NULL, local_order_status VARCHAR(64) NULL, external_status VARCHAR(64) NULL, total DECIMAL(18,4) NULL, currency VARCHAR(16) NULL, metadata_json JSON NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_ecommerce_order_refs_tenant_store_external (tenant_id, app_id, store_id, external_order_id, created_at), KEY idx_ecommerce_order_refs_tenant_status (tenant_id, store_id, local_order_status, external_status, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function storeQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::STORE_TABLE))
            ->setAllowedColumns([
                'id', 'tenant_id', 'app_id', 'platform', 'store_name', 'store_url', 'status',
                'connection_status', 'currency', 'timezone', 'metadata_json', 'created_at', 'updated_at',
            ])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function credentialQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::CREDENTIAL_TABLE))
            ->setAllowedColumns([
                'id', 'tenant_id', 'app_id', 'store_id', 'credential_type', 'encrypted_payload',
                'status', 'last_validated_at', 'metadata_json', 'created_at',
            ])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function syncJobQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::SYNC_JOB_TABLE))
            ->setAllowedColumns([
                'id', 'tenant_id', 'app_id', 'store_id', 'sync_type', 'status', 'started_at',
                'finished_at', 'result_summary', 'metadata_json', 'created_at',
            ])
            ->where('tenant_id', '=', $tenantId);

        if ($appId !== null && $appId !== '') {
            $qb->where('app_id', '=', $appId);
        }

        return $qb;
    }

    private function orderRefQuery(string $tenantId, ?string $appId = null): QueryBuilder
    {
        $qb = (new QueryBuilder($this->db, self::ORDER_REF_TABLE))
            ->setAllowedColumns([
                'id', 'tenant_id', 'app_id', 'store_id', 'external_order_id', 'local_order_status',
                'external_status', 'total', 'currency', 'metadata_json', 'created_at',
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
    private function normalizeStoreRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'platform' => trim((string) ($row['platform'] ?? 'unknown')) ?: 'unknown',
            'store_name' => (string) ($row['store_name'] ?? ''),
            'store_url' => $this->nullableString($row['store_url'] ?? null),
            'status' => trim((string) ($row['status'] ?? 'inactive')) ?: 'inactive',
            'connection_status' => trim((string) ($row['connection_status'] ?? 'not_configured')) ?: 'not_configured',
            'currency' => $this->nullableString($row['currency'] ?? null),
            'timezone' => $this->nullableString($row['timezone'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeCredentialRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'store_id' => (string) ($row['store_id'] ?? ''),
            'credential_type' => (string) ($row['credential_type'] ?? ''),
            'encrypted_payload' => (string) ($row['encrypted_payload'] ?? ''),
            'status' => trim((string) ($row['status'] ?? 'inactive')) ?: 'inactive',
            'last_validated_at' => $this->nullableString($row['last_validated_at'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSyncJobRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'store_id' => (string) ($row['store_id'] ?? ''),
            'sync_type' => (string) ($row['sync_type'] ?? ''),
            'status' => trim((string) ($row['status'] ?? 'queued')) ?: 'queued',
            'started_at' => $this->nullableString($row['started_at'] ?? null),
            'finished_at' => $this->nullableString($row['finished_at'] ?? null),
            'result_summary' => $this->nullableString($row['result_summary'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeOrderRefRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->nullableString($row['app_id'] ?? null),
            'store_id' => (string) ($row['store_id'] ?? ''),
            'external_order_id' => (string) ($row['external_order_id'] ?? ''),
            'local_order_status' => $this->nullableString($row['local_order_status'] ?? null),
            'external_status' => $this->nullableString($row['external_status'] ?? null),
            'total' => $row['total'] === null ? null : $this->decimal($row['total']),
            'currency' => $this->nullableString($row['currency'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
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
