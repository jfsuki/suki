<?php
// app/Core/OperationalQueueStore.php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class OperationalQueueStore
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->bootstrapSchemaPolicy();
    }

    /**
     * @return array{enqueued:bool,job_id:?string,status:string,idempotency_key:string}
     */
    public function enqueueIfNotExists(
        string $tenantId,
        string $channel,
        string $idempotencyKey,
        string $jobType,
        array $payload,
        ?string $payloadHash = null
    ): array {
        $tenantId = $this->normTenant($tenantId);
        $channel = $this->normText($channel, 'telegram');
        $idempotencyKey = $this->normText($idempotencyKey, '');
        if ($idempotencyKey === '') {
            throw new RuntimeException('idempotency_key requerido');
        }
        $jobType = $this->normText($jobType, 'generic');
        $payloadHash = $payloadHash !== null && $payloadHash !== '' ? $payloadHash : hash('sha256', $this->jsonEncode($payload));
        $now = $this->now();

        $this->db->beginTransaction();
        try {
            $insertDedupe = $this->db->prepare(
                'INSERT INTO event_dedupe (
                    tenant_id, channel, idempotency_key, status, first_seen_at, last_seen_at, payload_hash, job_id, error_json
                 ) VALUES (
                    :tenant_id, :channel, :idempotency_key, :status, :first_seen_at, :last_seen_at, :payload_hash, :job_id, :error_json
                 )'
            );
            $insertDedupe->execute([
                ':tenant_id' => $tenantId,
                ':channel' => $channel,
                ':idempotency_key' => $idempotencyKey,
                ':status' => 'received',
                ':first_seen_at' => $now,
                ':last_seen_at' => $now,
                ':payload_hash' => $payloadHash,
                ':job_id' => null,
                ':error_json' => null,
            ]);
        } catch (PDOException $e) {
            if (!$this->isUniqueViolation($e)) {
                $this->db->rollBack();
                throw $e;
            }
            $this->db->rollBack();
            $existing = $this->fetchDedupe($tenantId, $channel, $idempotencyKey);
            $this->touchDedupeLastSeen($tenantId, $channel, $idempotencyKey, $now);
            return [
                'enqueued' => false,
                'job_id' => (string) ($existing['job_id'] ?? ''),
                'status' => (string) ($existing['status'] ?? 'duplicate'),
                'idempotency_key' => $idempotencyKey,
            ];
        }

        $insertJob = $this->db->prepare(
            'INSERT INTO jobs_queue (
                tenant_id, job_type, payload_json, status, attempts, available_at, locked_at, locked_by, created_at, updated_at
             ) VALUES (
                :tenant_id, :job_type, :payload_json, :status, :attempts, :available_at, :locked_at, :locked_by, :created_at, :updated_at
             )'
        );
        $insertJob->execute([
            ':tenant_id' => $tenantId,
            ':job_type' => $jobType,
            ':payload_json' => $this->jsonEncode($payload),
            ':status' => 'pending',
            ':attempts' => 0,
            ':available_at' => $now,
            ':locked_at' => null,
            ':locked_by' => null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $jobId = (string) $this->db->lastInsertId();

        $updateDedupe = $this->db->prepare(
            'UPDATE event_dedupe
             SET status = :status, last_seen_at = :last_seen_at, job_id = :job_id
             WHERE tenant_id = :tenant_id AND channel = :channel AND idempotency_key = :idempotency_key'
        );
        $updateDedupe->execute([
            ':status' => 'queued',
            ':last_seen_at' => $now,
            ':job_id' => $jobId,
            ':tenant_id' => $tenantId,
            ':channel' => $channel,
            ':idempotency_key' => $idempotencyKey,
        ]);

        $this->db->commit();
        return [
            'enqueued' => true,
            'job_id' => $jobId,
            'status' => 'queued',
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockNextJob(string $workerId): ?array
    {
        $workerId = $this->normText($workerId, 'worker');
        $now = $this->now();
        $driver = $this->driver();

        $this->db->beginTransaction();
        try {
            $selectSql = 'SELECT id, tenant_id, job_type, payload_json, status, attempts, available_at, locked_at, locked_by, created_at, updated_at
                FROM jobs_queue
                WHERE status = :status AND available_at <= :available_at
                ORDER BY available_at ASC, id ASC
                LIMIT 1';
            if ($driver === 'mysql') {
                $selectSql .= ' FOR UPDATE';
            }
            $select = $this->db->prepare($selectSql);
            $select->execute([
                ':status' => 'pending',
                ':available_at' => $now,
            ]);
            $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!is_array($row)) {
                $this->db->commit();
                return null;
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                $this->db->commit();
                return null;
            }

            $update = $this->db->prepare(
                'UPDATE jobs_queue
                 SET status = :running, attempts = attempts + 1, locked_at = :locked_at, locked_by = :locked_by, updated_at = :updated_at
                 WHERE id = :id AND status = :pending'
            );
            $update->execute([
                ':running' => 'running',
                ':locked_at' => $now,
                ':locked_by' => $workerId,
                ':updated_at' => $now,
                ':id' => $id,
                ':pending' => 'pending',
            ]);
            if ((int) $update->rowCount() !== 1) {
                $this->db->commit();
                return null;
            }

            $reload = $this->db->prepare(
                'SELECT id, tenant_id, job_type, payload_json, status, attempts, available_at, locked_at, locked_by, created_at, updated_at
                 FROM jobs_queue WHERE id = :id LIMIT 1'
            );
            $reload->execute([':id' => $id]);
            $locked = $reload->fetch(PDO::FETCH_ASSOC) ?: null;
            $this->db->commit();
            if (!is_array($locked)) {
                return null;
            }
            $locked['payload'] = $this->jsonDecode((string) ($locked['payload_json'] ?? '{}'));
            return $locked;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function ackJob(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }
        $now = $this->now();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE jobs_queue
                 SET status = :status, locked_at = NULL, locked_by = NULL, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status' => 'done',
                ':updated_at' => $now,
                ':id' => $jobId,
            ]);

            $dedupe = $this->db->prepare(
                'UPDATE event_dedupe
                 SET status = :status, last_seen_at = :last_seen_at
                 WHERE job_id = :job_id'
            );
            $dedupe->execute([
                ':status' => 'processed',
                ':last_seen_at' => $now,
                ':job_id' => (string) $jobId,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $error
     */
    public function failJob(int $jobId, array $error = []): void
    {
        if ($jobId <= 0) {
            return;
        }
        $now = $this->now();
        $errorJson = $this->jsonEncode([
            'error' => $error['error'] ?? 'job_failed',
            'code' => $error['code'] ?? null,
            'at' => $error['at'] ?? $now,
        ]);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE jobs_queue
                 SET status = :status, locked_at = NULL, locked_by = NULL, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status' => 'failed',
                ':updated_at' => $now,
                ':id' => $jobId,
            ]);

            $dedupe = $this->db->prepare(
                'UPDATE event_dedupe
                 SET status = :status, last_seen_at = :last_seen_at, error_json = :error_json
                 WHERE job_id = :job_id'
            );
            $dedupe->execute([
                ':status' => 'error',
                ':last_seen_at' => $now,
                ':error_json' => $errorJson,
                ':job_id' => (string) $jobId,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function ensureSchema(): void
    {
        if ($this->driver() === 'mysql') {
            $this->ensureSchemaMySql();
            return;
        }
        $this->ensureSchemaSqlite();
    }

    private function bootstrapSchemaPolicy(): void
    {
        if ($this->runtimeSchemaEnabled()) {
            $this->ensureSchema();
            return;
        }

        $issues = $this->collectSchemaIssues();
        $missingTables = is_array($issues['missing_tables'] ?? null) ? (array) $issues['missing_tables'] : [];
        $missingIndexes = is_array($issues['missing_indexes'] ?? null) ? (array) $issues['missing_indexes'] : [];
        if (empty($missingTables) && empty($missingIndexes)) {
            return;
        }

        $driver = $this->driver();
        $migrationHint = 'Aplica migraciones formales en db/migrations/' . $driver . '/';

        if (!empty($missingIndexes)) {
            error_log(
                '[OperationalQueueStore] runtime schema changes are disabled; missing_indexes='
                . implode(',', $missingIndexes)
                . '. '
                . $migrationHint
            );
        }

        if (empty($missingTables)) {
            return;
        }

        $details = ['missing_tables=' . implode(',', $missingTables)];
        if (!empty($missingIndexes)) {
            $details[] = 'missing_indexes=' . implode(',', $missingIndexes);
        }
        throw new RuntimeException(
            'OperationalQueueStore: runtime schema changes are disabled. '
            . implode(' | ', $details)
            . '. '
            . $migrationHint
            . ' Habilita ALLOW_RUNTIME_SCHEMA=1 solo en local dev.'
        );
    }

    private function runtimeSchemaEnabled(): bool
    {
        if ((string) (getenv('ALLOW_RUNTIME_SCHEMA') ?: '0') !== '1') {
            return false;
        }
        if ($this->isProductionEnvironment()) {
            return false;
        }
        return $this->isLocalEnvironment();
    }

    private function isProductionEnvironment(): bool
    {
        $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('SUKI_ENV') ?: '')));
        return in_array($appEnv, ['production', 'prod'], true);
    }

    private function isLocalEnvironment(): bool
    {
        $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('SUKI_ENV') ?: '')));
        if (in_array($appEnv, ['local', 'development', 'dev', 'testing', 'test'], true)) {
            return true;
        }

        $appUrl = trim((string) (getenv('APP_URL') ?: ''));
        if ($appUrl !== '') {
            $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
            if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{missing_tables: array<int, string>, missing_indexes: array<int, string>}
     */
    private function collectSchemaIssues(): array
    {
        $missingTables = [];
        foreach ($this->requiredTables() as $table) {
            if (!$this->tableExists($table)) {
                $missingTables[] = $table;
            }
        }

        $missingIndexes = [];
        foreach ($this->requiredIndexes() as $table => $indexes) {
            if (!$this->tableExists($table)) {
                continue;
            }
            foreach ($indexes as $index) {
                if (!$this->indexExists($table, $index)) {
                    $missingIndexes[] = $table . '.' . $index;
                }
            }
        }

        return [
            'missing_tables' => $missingTables,
            'missing_indexes' => $missingIndexes,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return ['event_dedupe', 'jobs_queue'];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            'event_dedupe' => [
                'uq_event_dedupe_tenant_channel_key',
                'idx_event_dedupe_tenant_first_seen',
                'idx_event_dedupe_status_last_seen',
            ],
            'jobs_queue' => [
                'idx_jobs_queue_tenant_created',
                'idx_jobs_queue_status_available',
                'idx_jobs_queue_tenant_status_available',
            ],
        ];
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

    private function indexExists(string $table, string $index): bool
    {
        $table = trim($table);
        $index = trim($index);
        if ($table === '' || $index === '') {
            return false;
        }

        if ($this->driver() === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name'
            );
            $stmt->execute([
                ':table_name' => $table,
                ':index_name' => $index,
            ]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        if ($safeTable === '') {
            return false;
        }
        $stmt = $this->db->query("PRAGMA index_list({$safeTable})");
        if (!$stmt) {
            return false;
        }
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ((string) ($row['name'] ?? '') === $index) {
                return true;
            }
        }
        return false;
    }

    private function ensureSchemaMySql(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS event_dedupe (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(120) NOT NULL,
                channel VARCHAR(40) NOT NULL,
                idempotency_key VARCHAR(191) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT \'new\',
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                payload_hash CHAR(64) NULL,
                job_id VARCHAR(191) NULL,
                error_json JSON NULL,
                UNIQUE KEY uq_event_dedupe_tenant_channel_key (tenant_id, channel, idempotency_key),
                KEY idx_event_dedupe_tenant_first_seen (tenant_id, first_seen_at),
                KEY idx_event_dedupe_status_last_seen (status, last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS jobs_queue (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(120) NOT NULL,
                job_type VARCHAR(120) NOT NULL,
                payload_json JSON NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT \'pending\',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                available_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                locked_by VARCHAR(120) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_jobs_queue_tenant_created (tenant_id, created_at),
                KEY idx_jobs_queue_status_available (status, available_at),
                KEY idx_jobs_queue_tenant_status_available (tenant_id, status, available_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function ensureSchemaSqlite(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS event_dedupe (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                channel TEXT NOT NULL,
                idempotency_key TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'new\',
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                payload_hash TEXT NULL,
                job_id TEXT NULL,
                error_json TEXT NULL
            )'
        );
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_event_dedupe_tenant_channel_key ON event_dedupe (tenant_id, channel, idempotency_key)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_event_dedupe_tenant_first_seen ON event_dedupe (tenant_id, first_seen_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_event_dedupe_status_last_seen ON event_dedupe (status, last_seen_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS jobs_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                job_type TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at TEXT NOT NULL,
                locked_at TEXT NULL,
                locked_by TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_queue_tenant_created ON jobs_queue (tenant_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_queue_status_available ON jobs_queue (status, available_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_queue_tenant_status_available ON jobs_queue (tenant_id, status, available_at)');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDedupe(string $tenantId, string $channel, string $idempotencyKey): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, channel, idempotency_key, status, payload_hash, job_id
             FROM event_dedupe
             WHERE tenant_id = :tenant_id AND channel = :channel AND idempotency_key = :idempotency_key
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':channel' => $channel,
            ':idempotency_key' => $idempotencyKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    private function touchDedupeLastSeen(string $tenantId, string $channel, string $idempotencyKey, string $now): void
    {
        $stmt = $this->db->prepare(
            'UPDATE event_dedupe
             SET last_seen_at = :last_seen_at
             WHERE tenant_id = :tenant_id AND channel = :channel AND idempotency_key = :idempotency_key'
        );
        $stmt->execute([
            ':last_seen_at' => $now,
            ':tenant_id' => $tenantId,
            ':channel' => $channel,
            ':idempotency_key' => $idempotencyKey,
        ]);
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        $sqlState = (string) $e->getCode();
        if ($sqlState === '23000' || $sqlState === '19') {
            return true;
        }
        $message = strtolower((string) $e->getMessage());
        return str_contains($message, 'duplicate') || str_contains($message, 'unique');
    }

    private function jsonEncode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar JSON');
        }
        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonDecode(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normTenant(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : 'default';
    }

    private function normText(string $value, string $fallback): string
    {
        $value = trim($value);
        return $value !== '' ? $value : $fallback;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
