<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AlertsCenterRepository
{
    private const ALERT_TABLE = 'alerts_center_alerts';
    private const TASK_TABLE = 'alerts_center_tasks';
    private const REMINDER_TABLE = 'alerts_center_reminders';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'AlertsCenterRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260310_010_alerts_center_module.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function insertAlert(array $record): array
    {
        $id = $this->insertRecord(self::ALERT_TABLE, [
            'tenant_id',
            'app_id',
            'alert_type',
            'title',
            'message',
            'severity',
            'source_type',
            'source_ref',
            'status',
            'created_at',
            'due_at',
            'metadata_json',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'alert_type' => $record['alert_type'] ?? '',
            'title' => $record['title'] ?? '',
            'message' => $record['message'] ?? '',
            'severity' => $record['severity'] ?? 'medium',
            'source_type' => $record['source_type'] ?? 'manual',
            'source_ref' => $record['source_ref'] ?? null,
            'status' => $record['status'] ?? 'open',
            'created_at' => $record['created_at'] ?? '',
            'due_at' => $record['due_at'] ?? null,
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
        ]);

        return $this->findAlert((string) ($record['tenant_id'] ?? ''), $id) ?? [];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function insertTask(array $record): array
    {
        $id = $this->insertRecord(self::TASK_TABLE, [
            'tenant_id',
            'app_id',
            'task_type',
            'title',
            'description',
            'assigned_to',
            'priority',
            'status',
            'due_at',
            'related_entity_type',
            'related_entity_id',
            'created_at',
            'metadata_json',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'task_type' => $record['task_type'] ?? '',
            'title' => $record['title'] ?? '',
            'description' => $record['description'] ?? '',
            'assigned_to' => $record['assigned_to'] ?? null,
            'priority' => $record['priority'] ?? 'medium',
            'status' => $record['status'] ?? 'pending',
            'due_at' => $record['due_at'] ?? null,
            'related_entity_type' => $record['related_entity_type'] ?? null,
            'related_entity_id' => $record['related_entity_id'] ?? null,
            'created_at' => $record['created_at'] ?? '',
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
        ]);

        return $this->findTask((string) ($record['tenant_id'] ?? ''), $id) ?? [];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function insertReminder(array $record): array
    {
        $id = $this->insertRecord(self::REMINDER_TABLE, [
            'tenant_id',
            'app_id',
            'reminder_type',
            'title',
            'message',
            'remind_at',
            'status',
            'related_entity_type',
            'related_entity_id',
            'created_at',
            'metadata_json',
        ], [
            'tenant_id' => $record['tenant_id'] ?? '',
            'app_id' => $record['app_id'] ?? null,
            'reminder_type' => $record['reminder_type'] ?? '',
            'title' => $record['title'] ?? '',
            'message' => $record['message'] ?? '',
            'remind_at' => $record['remind_at'] ?? '',
            'status' => $record['status'] ?? 'pending',
            'related_entity_type' => $record['related_entity_type'] ?? null,
            'related_entity_id' => $record['related_entity_id'] ?? null,
            'created_at' => $record['created_at'] ?? '',
            'metadata_json' => $this->encodeJson($record['metadata'] ?? []),
        ]);

        return $this->findReminder((string) ($record['tenant_id'] ?? ''), $id) ?? [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listAlerts(string $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->listRecords(self::ALERT_TABLE, $tenantId, $filters, $limit, $offset, 'created_at DESC, id DESC', 'alert');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTasks(string $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->listRecords(self::TASK_TABLE, $tenantId, $filters, $limit, $offset, 'created_at DESC, id DESC', 'task');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listReminders(string $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->listRecords(self::REMINDER_TABLE, $tenantId, $filters, $limit, $offset, 'remind_at ASC, id ASC', 'reminder');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateAlertStatus(string $tenantId, string $id, string $status): ?array
    {
        $this->updateStatus(self::ALERT_TABLE, $tenantId, $id, $status);
        return $this->findAlert($tenantId, $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateTaskStatus(string $tenantId, string $id, string $status): ?array
    {
        $this->updateStatus(self::TASK_TABLE, $tenantId, $id, $status);
        return $this->findTask($tenantId, $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateReminderStatus(string $tenantId, string $id, string $status): ?array
    {
        $this->updateStatus(self::REMINDER_TABLE, $tenantId, $id, $status);
        return $this->findReminder($tenantId, $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOpenAlertBySource(string $tenantId, string $sourceType, string $sourceRef, ?string $appId = null): ?array
    {
        $sql = 'SELECT * FROM ' . self::ALERT_TABLE . '
            WHERE tenant_id = :tenant_id
              AND source_type = :source_type
              AND source_ref = :source_ref
              AND status IN (:status_open, :status_ack)';
        if ($appId !== null) {
            $sql .= ' AND app_id = :app_id';
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':source_type', $sourceType);
        $stmt->bindValue(':source_ref', $sourceRef);
        $stmt->bindValue(':status_open', 'open');
        $stmt->bindValue(':status_ack', 'acknowledged');
        if ($appId !== null) {
            $stmt->bindValue(':app_id', $appId);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->normalizeRow($row, 'alert') : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAlert(string $tenantId, string $id): ?array
    {
        return $this->findById(self::ALERT_TABLE, $tenantId, $id, 'alert');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTask(string $tenantId, string $id): ?array
    {
        return $this->findById(self::TASK_TABLE, $tenantId, $id, 'task');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findReminder(string $tenantId, string $id): ?array
    {
        return $this->findById(self::REMINDER_TABLE, $tenantId, $id, 'reminder');
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [self::ALERT_TABLE, self::TASK_TABLE, self::REMINDER_TABLE];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            self::ALERT_TABLE => [
                'idx_alerts_center_alerts_tenant_status_due',
                'idx_alerts_center_alerts_tenant_app_status',
                'idx_alerts_center_alerts_source_ref',
            ],
            self::TASK_TABLE => [
                'idx_alerts_center_tasks_tenant_status_due',
                'idx_alerts_center_tasks_tenant_app_status',
                'idx_alerts_center_tasks_assigned_to',
            ],
            self::REMINDER_TABLE => [
                'idx_alerts_center_reminders_tenant_status_remind',
                'idx_alerts_center_reminders_tenant_app_status',
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
            "CREATE TABLE IF NOT EXISTS " . self::ALERT_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                alert_type VARCHAR(80) NOT NULL,
                title VARCHAR(190) NOT NULL,
                message TEXT NOT NULL,
                severity VARCHAR(16) NOT NULL,
                source_type VARCHAR(64) NOT NULL,
                source_ref VARCHAR(190) NULL,
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL,
                due_at DATETIME NULL,
                metadata_json JSON NULL,
                PRIMARY KEY (id),
                KEY idx_alerts_center_alerts_tenant_status_due (tenant_id, status, due_at),
                KEY idx_alerts_center_alerts_tenant_app_status (tenant_id, app_id, status),
                KEY idx_alerts_center_alerts_source_ref (tenant_id, source_type, source_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::TASK_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                task_type VARCHAR(80) NOT NULL,
                title VARCHAR(190) NOT NULL,
                description TEXT NOT NULL,
                assigned_to VARCHAR(190) NULL,
                priority VARCHAR(16) NOT NULL,
                status VARCHAR(32) NOT NULL,
                due_at DATETIME NULL,
                related_entity_type VARCHAR(120) NULL,
                related_entity_id VARCHAR(190) NULL,
                created_at DATETIME NOT NULL,
                metadata_json JSON NULL,
                PRIMARY KEY (id),
                KEY idx_alerts_center_tasks_tenant_status_due (tenant_id, status, due_at),
                KEY idx_alerts_center_tasks_tenant_app_status (tenant_id, app_id, status),
                KEY idx_alerts_center_tasks_assigned_to (tenant_id, assigned_to, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::REMINDER_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                app_id VARCHAR(120) NULL,
                reminder_type VARCHAR(80) NOT NULL,
                title VARCHAR(190) NOT NULL,
                message TEXT NOT NULL,
                remind_at DATETIME NOT NULL,
                status VARCHAR(32) NOT NULL,
                related_entity_type VARCHAR(120) NULL,
                related_entity_id VARCHAR(190) NULL,
                created_at DATETIME NOT NULL,
                metadata_json JSON NULL,
                PRIMARY KEY (id),
                KEY idx_alerts_center_reminders_tenant_status_remind (tenant_id, status, remind_at),
                KEY idx_alerts_center_reminders_tenant_app_status (tenant_id, app_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function ensureSchemaSqlite(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::ALERT_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                alert_type TEXT NOT NULL,
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                severity TEXT NOT NULL,
                source_type TEXT NOT NULL,
                source_ref TEXT NULL,
                status TEXT NOT NULL,
                created_at TEXT NOT NULL,
                due_at TEXT NULL,
                metadata_json TEXT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_alerts_center_alerts_tenant_status_due ON ' . self::ALERT_TABLE . ' (tenant_id, status, due_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_alerts_center_alerts_tenant_app_status ON ' . self::ALERT_TABLE . ' (tenant_id, app_id, status)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_alerts_center_alerts_source_ref ON ' . self::ALERT_TABLE . ' (tenant_id, source_type, source_ref)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TASK_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                task_type TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                assigned_to TEXT NULL,
                priority TEXT NOT NULL,
                status TEXT NOT NULL,
                due_at TEXT NULL,
                related_entity_type TEXT NULL,
                related_entity_id TEXT NULL,
                created_at TEXT NOT NULL,
                metadata_json TEXT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_alerts_center_tasks_tenant_status_due ON ' . self::TASK_TABLE . ' (tenant_id, status, due_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_alerts_center_tasks_tenant_app_status ON ' . self::TASK_TABLE . ' (tenant_id, app_id, status)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_alerts_center_tasks_assigned_to ON ' . self::TASK_TABLE . ' (tenant_id, assigned_to, status)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::REMINDER_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NULL,
                reminder_type TEXT NOT NULL,
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                remind_at TEXT NOT NULL,
                status TEXT NOT NULL,
                related_entity_type TEXT NULL,
                related_entity_id TEXT NULL,
                created_at TEXT NOT NULL,
                metadata_json TEXT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_alerts_center_reminders_tenant_status_remind ON ' . self::REMINDER_TABLE . ' (tenant_id, status, remind_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_alerts_center_reminders_tenant_app_status ON ' . self::REMINDER_TABLE . ' (tenant_id, app_id, status)');
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, mixed> $values
     */
    private function insertRecord(string $table, array $columns, array $values): string
    {
        $table = $this->safeTable($table);
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
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function listRecords(
        string $table,
        string $tenantId,
        array $filters,
        int $limit,
        int $offset,
        string $orderBy,
        string $kind
    ): array {
        $table = $this->safeTable($table);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $bindings = [
            ':tenant_id' => $tenantId,
            ':limit' => $limit,
            ':offset' => $offset,
        ];
        $where = ['tenant_id = :tenant_id'];

        if (array_key_exists('app_id', $filters) && $filters['app_id'] !== null && $filters['app_id'] !== '') {
            $where[] = 'app_id = :app_id';
            $bindings[':app_id'] = (string) $filters['app_id'];
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'status = :status';
            $bindings[':status'] = (string) $filters['status'];
        }

        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $statusParams = [];
            foreach (array_values($filters['statuses']) as $index => $status) {
                $param = ':status_' . $index;
                $statusParams[] = $param;
                $bindings[$param] = (string) $status;
            }
            if ($statusParams !== []) {
                $where[] = 'status IN (' . implode(', ', $statusParams) . ')';
            }
        }

        foreach ([
            'alert_type',
            'task_type',
            'reminder_type',
            'source_type',
            'source_ref',
            'assigned_to',
            'related_entity_type',
            'related_entity_id',
        ] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
                continue;
            }
            $where[] = $key . ' = :' . $key;
            $bindings[':' . $key] = (string) $filters[$key];
        }

        foreach ([
            'due_before' => ['column' => 'due_at', 'operator' => '<='],
            'due_after' => ['column' => 'due_at', 'operator' => '>='],
            'remind_before' => ['column' => 'remind_at', 'operator' => '<='],
            'remind_after' => ['column' => 'remind_at', 'operator' => '>='],
        ] as $filterKey => $rule) {
            if (!array_key_exists($filterKey, $filters) || $filters[$filterKey] === null || $filters[$filterKey] === '') {
                continue;
            }
            $where[] = $rule['column'] . ' ' . $rule['operator'] . ' :' . $filterKey;
            $bindings[':' . $filterKey] = (string) $filters[$filterKey];
        }

        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT :limit OFFSET :offset';
        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $key => $value) {
            $type = in_array($key, [':limit', ':offset'], true) ? PDO::PARAM_INT : PDO::PARAM_STR;
            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
                continue;
            }
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->normalizeRow($row, $kind);
        }

        return $result;
    }

    private function updateStatus(string $table, string $tenantId, string $id, string $status): void
    {
        $table = $this->safeTable($table);
        $sql = 'UPDATE ' . $table . ' SET status = :status WHERE tenant_id = :tenant_id AND id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findById(string $table, string $tenantId, string $id, string $kind): ?array
    {
        $table = $this->safeTable($table);
        $stmt = $this->db->prepare('SELECT * FROM ' . $table . ' WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->normalizeRow($row, $kind) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, string $kind): array
    {
        $base = [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'app_id' => $this->normalizeNullableString($row['app_id'] ?? null),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
        ];

        if ($kind === 'alert') {
            return $base + [
                'alert_type' => (string) ($row['alert_type'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'severity' => (string) ($row['severity'] ?? ''),
                'source_type' => (string) ($row['source_type'] ?? ''),
                'source_ref' => $this->normalizeNullableString($row['source_ref'] ?? null),
                'status' => (string) ($row['status'] ?? ''),
                'due_at' => $this->normalizeNullableString($row['due_at'] ?? null),
            ];
        }

        if ($kind === 'task') {
            return $base + [
                'task_type' => (string) ($row['task_type'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'assigned_to' => $this->normalizeNullableString($row['assigned_to'] ?? null),
                'priority' => (string) ($row['priority'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'due_at' => $this->normalizeNullableString($row['due_at'] ?? null),
                'related_entity_type' => $this->normalizeNullableString($row['related_entity_type'] ?? null),
                'related_entity_id' => $this->normalizeNullableString($row['related_entity_id'] ?? null),
            ];
        }

        return $base + [
            'reminder_type' => (string) ($row['reminder_type'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'remind_at' => (string) ($row['remind_at'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'related_entity_type' => $this->normalizeNullableString($row['related_entity_type'] ?? null),
            'related_entity_id' => $this->normalizeNullableString($row['related_entity_id'] ?? null),
        ];
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
    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): string
    {
        $encoded = json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('No se pudo serializar metadata de Alerts Center.');
        }
        return $encoded;
    }

    private function safeTable(string $table): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new RuntimeException('Tabla invalida para Alerts Center.');
        }
        return $table;
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
