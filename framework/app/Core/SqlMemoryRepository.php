<?php
// app/Core/SqlMemoryRepository.php

namespace App\Core;

use PDO;
use RuntimeException;

final class SqlMemoryRepository implements MemoryRepositoryInterface
{
    private PDO $db;
    private bool $tablesEnsured = false;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'SqlMemoryRepository',
            fn() => $this->ensureTables(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260303_004_runtime_infra_schema.sql'
        );
    }

    public function getGlobalMemory(string $category, string $key, array $default = []): array
    {
        $stmt = $this->db->prepare('SELECT value_json FROM mem_global WHERE category = :category AND key_name = :key_name LIMIT 1');
        $stmt->execute([
            ':category' => $category,
            ':key_name' => $key,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->decodeValue($row['value_json'] ?? null, $default);
    }

    public function saveGlobalMemory(string $category, string $key, array $value): void
    {
        $this->upsertGlobal($category, $key, $value);
    }

    public function getTenantMemory(string $tenantId, string $key, array $default = []): array
    {
        $stmt = $this->db->prepare('SELECT value_json FROM mem_tenant WHERE tenant_id = :tenant_id AND key_name = :key_name LIMIT 1');
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':key_name' => $key,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->decodeValue($row['value_json'] ?? null, $default);
    }

    public function saveTenantMemory(string $tenantId, string $key, array $value): void
    {
        $this->upsertTenant($tenantId, $key, $value);
    }

    public function getUserMemory(string $tenantId, string $userId, string $key, array $default = []): array
    {
        $stmt = $this->db->prepare('SELECT value_json FROM mem_user WHERE tenant_id = :tenant_id AND user_id = :user_id AND key_name = :key_name LIMIT 1');
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':user_id' => $userId,
            ':key_name' => $key,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->decodeValue($row['value_json'] ?? null, $default);
    }

    public function saveUserMemory(string $tenantId, string $userId, string $key, array $value): void
    {
        $this->upsertUser($tenantId, $userId, $key, $value);
    }

    public function appendShortTermMemory(
        string $tenantId,
        string $userId,
        string $sessionId,
        string $channel,
        string $direction,
        string $message,
        array $meta = []
    ): void {
        $direction = strtolower(trim($direction));
        if ($direction !== 'in' && $direction !== 'out') {
            $direction = 'in';
        }
        $stmt = $this->db->prepare(
            'INSERT INTO chat_log (tenant_id, user_id, session_id, channel, direction, message, meta_json, created_at)
             VALUES (:tenant_id, :user_id, :session_id, :channel, :direction, :message, :meta_json, :created_at)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':user_id' => $userId,
            ':session_id' => $sessionId,
            ':channel' => $channel !== '' ? $channel : 'chat',
            ':direction' => $direction,
            ':message' => $message,
            ':meta_json' => $this->encodeValue($meta),
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getShortTermMemory(string $tenantId, string $sessionId, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            'SELECT user_id, session_id, channel, direction, message, meta_json, created_at
             FROM chat_log
             WHERE tenant_id = :tenant_id AND session_id = :session_id
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':session_id', $sessionId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = array_reverse($rows);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'user_id' => (string) ($row['user_id'] ?? ''),
                'session_id' => (string) ($row['session_id'] ?? ''),
                'channel' => (string) ($row['channel'] ?? ''),
                'direction' => (string) ($row['direction'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'meta' => $this->decodeValue($row['meta_json'] ?? null, []),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Standardized load() method for Neuron IA.
     * Expects $threadId as "tenant_id:session_id".
     * Maps direction 'in' to 'user' and 'out' to 'assistant'.
     * Returns array of ['role' => string, 'content' => string]
     */
    public function load(string $threadId, int $limit = 20): array
    {
        $parts = explode(':', $threadId, 2);
        $tenantId = $parts[0] ?? 'default';
        $sessionId = $parts[1] ?? 'default';

        $rows = $this->getShortTermMemory($tenantId, $sessionId, $limit);
        error_log("SQLMEMORY_LOAD: Found " . count($rows) . " rows for thread $threadId (tenant: $tenantId, session: $sessionId)");
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'role' => (string) ($row['direction'] === 'out' ? 'assistant' : 'user'),
                'content' => (string) ($row['message'] ?? ''),
                'meta' => $row['meta'] ?? [],
                'created_at' => $row['created_at'] ?? '',
            ];
        }

        return $out;
    }

    public function getSession(string $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT data_json FROM mem_sessions WHERE session_id = :session_id LIMIT 1');
        $stmt->execute([':session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->decodeValue($row['data_json'] ?? null, []);
    }

    public function saveSession(string $sessionId, array $data): void
    {
        $updatedAt = date('Y-m-d H:i:s');
        $driver = $this->driver();
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare(
                'INSERT INTO mem_sessions (session_id, data_json, updated_at)
                 VALUES (:session_id, :data_json, :updated_at)
                 ON CONFLICT(session_id)
                 DO UPDATE SET data_json = excluded.data_json, updated_at = excluded.updated_at'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO mem_sessions (session_id, data_json, updated_at)
                 VALUES (:session_id, :data_json, :updated_at)
                 ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = VALUES(updated_at)'
            );
        }
        $stmt->execute([
            ':session_id' => $sessionId,
            ':data_json' => $this->encodeValue($data),
            ':updated_at' => $updatedAt,
        ]);
    }

    /**
     * Legacy KV support for ConversationGatewayStubsTrait.
     * Maps to mem_global using category 'legacy_kv'.
     */
    public function get(string $key, array $default = []): array
    {
        return $this->getGlobalMemory('legacy_kv', $key, $default);
    }

    public function save(string $key, array $value): void
    {
        $this->saveGlobalMemory('legacy_kv', $key, $value);
    }

    private function ensureTables(): void
    {
        if ($this->tablesEnsured) {
            return;
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $this->ensureTablesSqlite();
        } else {
            $this->ensureTablesMysql();
        }
        $this->tablesEnsured = true;
    }

    private function ensureTablesMysql(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS mem_global (
                category VARCHAR(64) NOT NULL,
                key_name VARCHAR(128) NOT NULL,
                value_json JSON NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (category, key_name),
                KEY idx_mem_global_category_updated (category, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS mem_tenant (
                tenant_id VARCHAR(120) NOT NULL,
                key_name VARCHAR(128) NOT NULL,
                value_json JSON NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (tenant_id, key_name),
                KEY idx_mem_tenant_updated (tenant_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS mem_user (
                tenant_id VARCHAR(120) NOT NULL,
                user_id VARCHAR(190) NOT NULL,
                key_name VARCHAR(128) NOT NULL,
                value_json JSON NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (tenant_id, user_id, key_name),
                KEY idx_mem_user_updated (tenant_id, user_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS chat_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                user_id VARCHAR(190) NOT NULL,
                session_id VARCHAR(190) NOT NULL,
                channel VARCHAR(32) NOT NULL,
                direction VARCHAR(8) NOT NULL,
                message TEXT NULL,
                meta_json JSON NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_chat_session (tenant_id, session_id, created_at),
                KEY idx_chat_user (tenant_id, user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS mem_sessions (
                session_id VARCHAR(190) NOT NULL,
                data_json JSON NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (session_id),
                KEY idx_mem_sessions_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function ensureTablesSqlite(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS mem_global (
                category TEXT NOT NULL,
                key_name TEXT NOT NULL,
                value_json TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (category, key_name)
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_mem_global_category_updated ON mem_global (category, updated_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS mem_tenant (
                tenant_id TEXT NOT NULL,
                key_name TEXT NOT NULL,
                value_json TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (tenant_id, key_name)
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_mem_tenant_updated ON mem_tenant (tenant_id, updated_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS mem_user (
                tenant_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                key_name TEXT NOT NULL,
                value_json TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (tenant_id, user_id, key_name)
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_mem_user_updated ON mem_user (tenant_id, user_id, updated_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS chat_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                session_id TEXT NOT NULL,
                channel TEXT NOT NULL,
                direction TEXT NOT NULL,
                message TEXT NULL,
                meta_json TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_chat_user ON chat_log (tenant_id, user_id, created_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS mem_sessions (
                session_id TEXT NOT NULL,
                data_json TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (session_id)
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_mem_sessions_updated ON mem_sessions (updated_at)');
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return ['mem_global', 'mem_tenant', 'mem_user', 'chat_log', 'mem_sessions'];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            'mem_global' => ['idx_mem_global_category_updated'],
            'mem_tenant' => ['idx_mem_tenant_updated'],
            'mem_user' => ['idx_mem_user_updated'],
            'chat_log' => ['idx_chat_session', 'idx_chat_user'],
            'mem_sessions' => ['idx_mem_sessions_updated'],
        ];
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function upsertGlobal(string $category, string $key, array $value): void
    {
        $updatedAt = date('Y-m-d H:i:s');
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare(
                'INSERT INTO mem_global (category, key_name, value_json, updated_at)
                 VALUES (:category, :key_name, :value_json, :updated_at)
                 ON CONFLICT(category, key_name)
                 DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO mem_global (category, key_name, value_json, updated_at)
                 VALUES (:category, :key_name, :value_json, :updated_at)
                 ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)'
            );
        }
        $stmt->execute([
            ':category' => $category,
            ':key_name' => $key,
            ':value_json' => $this->encodeValue($value),
            ':updated_at' => $updatedAt,
        ]);
    }

    private function upsertTenant(string $tenantId, string $key, array $value): void
    {
        $updatedAt = date('Y-m-d H:i:s');
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare(
                'INSERT INTO mem_tenant (tenant_id, key_name, value_json, updated_at)
                 VALUES (:tenant_id, :key_name, :value_json, :updated_at)
                 ON CONFLICT(tenant_id, key_name)
                 DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO mem_tenant (tenant_id, key_name, value_json, updated_at)
                 VALUES (:tenant_id, :key_name, :value_json, :updated_at)
                 ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)'
            );
        }
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':key_name' => $key,
            ':value_json' => $this->encodeValue($value),
            ':updated_at' => $updatedAt,
        ]);
    }

    private function upsertUser(string $tenantId, string $userId, string $key, array $value): void
    {
        $updatedAt = date('Y-m-d H:i:s');
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare(
                'INSERT INTO mem_user (tenant_id, user_id, key_name, value_json, updated_at)
                 VALUES (:tenant_id, :user_id, :key_name, :value_json, :updated_at)
                 ON CONFLICT(tenant_id, user_id, key_name)
                 DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO mem_user (tenant_id, user_id, key_name, value_json, updated_at)
                 VALUES (:tenant_id, :user_id, :key_name, :value_json, :updated_at)
                 ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)'
            );
        }
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':user_id' => $userId,
            ':key_name' => $key,
            ':value_json' => $this->encodeValue($value),
            ':updated_at' => $updatedAt,
        ]);
    }

    private function encodeValue(array $value): string
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new RuntimeException('No se pudo codificar memoria a JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo codificar memoria a JSON.');
        }
        return $json;
    }

    private function decodeValue(?string $value, array $default): array
    {
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $default;
        }
        return is_array($decoded) ? $decoded : $default;
    }
}
