<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class MediaRepository
{
    private const FILE_TABLE = 'media_files';
    private const FOLDER_TABLE = 'media_folders';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'MediaRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260310_011_media_storage_module.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function insertFile(array $record): array
    {
        $id = $this->insertRecord(self::FILE_TABLE, [
            'tenant_id',
            'app_id',
            'entity_type',
            'entity_id',
            'file_type',
            'storage_path',
            'mime_type',
            'file_size',
            'uploaded_by_user_id',
            'created_at',
            'updated_at',
            'metadata_json',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'entity_type' => $record['entity_type'] ?? '',
            'entity_id' => $record['entity_id'] ?? '',
            'file_type' => $record['file_type'] ?? '',
            'storage_path' => $record['storage_path'] ?? '',
            'mime_type' => $record['mime_type'] ?? '',
            'file_size' => $record['file_size'] ?? 0,
            'uploaded_by_user_id' => $record['uploaded_by_user_id'] ?? '',
            'created_at' => $record['created_at'] ?? '',
            'updated_at' => $record['updated_at'] ?? ($record['created_at'] ?? ''),
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
        ]);

        $saved = $this->findFile((string) ($record['tenant_id'] ?? ''), $id);
        if (!is_array($saved)) {
            throw new RuntimeException('MEDIA_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateFile(string $tenantId, string $id, array $updates): ?array
    {
        $fields = [];
        $bindings = [
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ];

        $allowed = [
            'storage_path',
            'mime_type',
            'file_size',
            'metadata_json',
            'updated_at',
        ];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $updates)) {
                continue;
            }
            $fields[] = $column . ' = :' . $column;
            $bindings[':' . $column] = $updates[$column];
        }

        if (array_key_exists('metadata', $updates)) {
            $fields[] = 'metadata_json = :metadata_json';
            $bindings[':metadata_json'] = $this->encodeJson($updates['metadata']);
        }

        if ($fields === []) {
            return $this->findFile($tenantId, $id);
        }

        if (!array_key_exists(':updated_at', $bindings)) {
            $fields[] = 'updated_at = :updated_at';
            $bindings[':updated_at'] = date('Y-m-d H:i:s');
        }

        $sql = 'UPDATE ' . self::FILE_TABLE . '
            SET ' . implode(', ', array_values(array_unique($fields))) . '
            WHERE tenant_id = :tenant_id AND id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);

        return $this->findFile($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listFiles(string $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where = ['tenant_id = :tenant_id'];
        $bindings = [
            ':tenant_id' => $tenantId,
            ':limit' => $limit,
            ':offset' => $offset,
        ];

        foreach ([
            'app_id',
            'entity_type',
            'entity_id',
            'file_type',
            'uploaded_by_user_id',
        ] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
                continue;
            }
            $where[] = $key . ' = :' . $key;
            $bindings[':' . $key] = (string) $filters[$key];
        }

        $sql = 'SELECT * FROM ' . self::FILE_TABLE
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $row): array => $this->normalizeFileRow($row), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFile(string $tenantId, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::FILE_TABLE . ' WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeFileRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deleteFile(string $tenantId, string $id): ?array
    {
        $current = $this->findFile($tenantId, $id);
        if (!is_array($current)) {
            return null;
        }

        $stmt = $this->db->prepare('DELETE FROM ' . self::FILE_TABLE . ' WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);

        return $current;
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [self::FILE_TABLE, self::FOLDER_TABLE];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            self::FILE_TABLE => [
                'idx_media_files_tenant_entity_created',
                'idx_media_files_tenant_app_entity',
                'idx_media_files_tenant_user_created',
                'idx_media_files_tenant_file_type',
            ],
            self::FOLDER_TABLE => [
                'idx_media_folders_tenant_parent_name',
                'idx_media_folders_tenant_app_parent',
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

    private function ensureSchemaMySql(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::FILE_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                entity_type VARCHAR(32) NOT NULL,
                entity_id VARCHAR(190) NOT NULL,
                file_type VARCHAR(32) NOT NULL,
                storage_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(190) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uploaded_by_user_id VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                metadata_json JSON NULL,
                PRIMARY KEY (id),
                KEY idx_media_files_tenant_entity_created (tenant_id, entity_type, entity_id, created_at),
                KEY idx_media_files_tenant_app_entity (tenant_id, app_id, entity_type, entity_id),
                KEY idx_media_files_tenant_user_created (tenant_id, uploaded_by_user_id, created_at),
                KEY idx_media_files_tenant_file_type (tenant_id, file_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::FOLDER_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                name VARCHAR(190) NOT NULL,
                parent_folder_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_media_folders_tenant_parent_name (tenant_id, parent_folder_id, name),
                KEY idx_media_folders_tenant_app_parent (tenant_id, app_id, parent_folder_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function ensureSchemaSqlite(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::FILE_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                file_type TEXT NOT NULL,
                storage_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                file_size INTEGER NOT NULL DEFAULT 0,
                uploaded_by_user_id TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                metadata_json TEXT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_media_files_tenant_entity_created ON ' . self::FILE_TABLE . ' (tenant_id, entity_type, entity_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_media_files_tenant_app_entity ON ' . self::FILE_TABLE . ' (tenant_id, app_id, entity_type, entity_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_media_files_tenant_user_created ON ' . self::FILE_TABLE . ' (tenant_id, uploaded_by_user_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_media_files_tenant_file_type ON ' . self::FILE_TABLE . ' (tenant_id, file_type, created_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::FOLDER_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                name TEXT NOT NULL,
                parent_folder_id INTEGER NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_media_folders_tenant_parent_name ON ' . self::FOLDER_TABLE . ' (tenant_id, parent_folder_id, name)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_media_folders_tenant_app_parent ON ' . self::FOLDER_TABLE . ' (tenant_id, app_id, parent_folder_id)');
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, mixed> $values
     */
    private function insertRecord(string $table, array $columns, array $values): string
    {
        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns));
        $sql = 'INSERT INTO ' . $table . ' (' . $columnList . ') VALUES (' . $placeholders . ')';
        $stmt = $this->db->prepare($sql);

        foreach ($columns as $column) {
            $stmt->bindValue(':' . $column, $values[$column] ?? null);
        }

        $stmt->execute();
        return (string) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeFileRow(array $row): array
    {
        $metadata = [];
        $rawMetadata = $row['metadata_json'] ?? null;
        if (is_string($rawMetadata) && trim($rawMetadata) !== '') {
            $decoded = json_decode($rawMetadata, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => isset($row['app_id']) && $row['app_id'] !== null ? (string) $row['app_id'] : null,
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => (string) ($row['entity_id'] ?? ''),
            'file_type' => (string) ($row['file_type'] ?? ''),
            'storage_path' => (string) ($row['storage_path'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'file_size' => max(0, (int) ($row['file_size'] ?? 0)),
            'uploaded_by_user_id' => (string) ($row['uploaded_by_user_id'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'metadata' => $metadata,
        ];
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
            throw new RuntimeException('MEDIA_METADATA_JSON_FAILED');
        }

        return $encoded;
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
