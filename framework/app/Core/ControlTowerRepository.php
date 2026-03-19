<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class ControlTowerRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null, ?string $dbPath = null)
    {
        if ($db instanceof PDO) {
            $this->db = $db;
        } else {
            $path = $dbPath ?: $this->defaultPath();
            $this->db = new PDO('sqlite:' . $path);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'ControlTowerRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/sqlite/20260319_001_control_tower_operational_schema.sql'
        );
    }

    /**
     * @param array<string, mixed> $task
     */
    public function upsertTask(array $task): void
    {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO control_tower_tasks (
                task_id, tenant_id, project_id, app_id, conversation_id, session_id, user_id, message_id,
                intent, status, source, route_path, gate_decision, related_entities_json, related_events_json,
                execution_result_json, idempotency_key, metadata_json, created_at, updated_at
            ) VALUES (
                :task_id, :tenant_id, :project_id, :app_id, :conversation_id, :session_id, :user_id, :message_id,
                :intent, :status, :source, :route_path, :gate_decision, :related_entities_json, :related_events_json,
                :execution_result_json, :idempotency_key, :metadata_json, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            ':task_id' => $this->text($task['task_id'] ?? ''),
            ':tenant_id' => $this->tenant($task['tenant_id'] ?? ''),
            ':project_id' => $this->project($task['project_id'] ?? ''),
            ':app_id' => $this->project($task['app_id'] ?? ''),
            ':conversation_id' => $this->text($task['conversation_id'] ?? ''),
            ':session_id' => $this->text($task['session_id'] ?? ''),
            ':user_id' => $this->text($task['user_id'] ?? ''),
            ':message_id' => $this->text($task['message_id'] ?? ''),
            ':intent' => $this->text($task['intent'] ?? 'unknown'),
            ':status' => $this->text($task['status'] ?? 'pending'),
            ':source' => $this->text($task['source'] ?? 'chat'),
            ':route_path' => $this->text($task['route_path'] ?? ''),
            ':gate_decision' => $this->text($task['gate_decision'] ?? 'unknown'),
            ':related_entities_json' => $this->encodeJson($task['related_entities'] ?? []),
            ':related_events_json' => $this->encodeJson($task['related_events'] ?? []),
            ':execution_result_json' => $this->encodeJsonObject($task['execution_result'] ?? []),
            ':idempotency_key' => $this->text($task['idempotency_key'] ?? ''),
            ':metadata_json' => $this->encodeJsonObject($task['metadata'] ?? []),
            ':created_at' => $this->timestamp($task['created_at'] ?? null),
            ':updated_at' => $this->timestamp($task['updated_at'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $incident
     */
    public function upsertIncident(array $incident): void
    {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO control_tower_incidents (
                incident_id, tenant_id, project_id, app_id, severity, source, related_task_id, related_events_json,
                status, description, metadata_json, created_at
            ) VALUES (
                :incident_id, :tenant_id, :project_id, :app_id, :severity, :source, :related_task_id, :related_events_json,
                :status, :description, :metadata_json, :created_at
            )'
        );
        $stmt->execute([
            ':incident_id' => $this->text($incident['incident_id'] ?? ''),
            ':tenant_id' => $this->tenant($incident['tenant_id'] ?? ''),
            ':project_id' => $this->project($incident['project_id'] ?? ''),
            ':app_id' => $this->project($incident['app_id'] ?? ''),
            ':severity' => $this->text($incident['severity'] ?? 'warning'),
            ':source' => $this->text($incident['source'] ?? 'system'),
            ':related_task_id' => $this->text($incident['related_task_id'] ?? ''),
            ':related_events_json' => $this->encodeJson($incident['related_events'] ?? []),
            ':status' => $this->text($incident['status'] ?? 'open'),
            ':description' => $this->text($incident['description'] ?? ''),
            ':metadata_json' => $this->encodeJsonObject($incident['metadata'] ?? []),
            ':created_at' => $this->timestamp($incident['created_at'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $event
     */
    public function appendEvent(array $event): void
    {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO control_tower_events (
                event_id, tenant_id, project_id, app_id, event_type, source, linked_ids_json, payload_json, created_at
            ) VALUES (
                :event_id, :tenant_id, :project_id, :app_id, :event_type, :source, :linked_ids_json, :payload_json, :created_at
            )'
        );
        $stmt->execute([
            ':event_id' => $this->text($event['event_id'] ?? ''),
            ':tenant_id' => $this->tenant($event['tenant_id'] ?? ''),
            ':project_id' => $this->project($event['project_id'] ?? ''),
            ':app_id' => $this->project($event['app_id'] ?? ''),
            ':event_type' => $this->text($event['event_type'] ?? ''),
            ':source' => $this->text($event['source'] ?? 'system'),
            ':linked_ids_json' => $this->encodeJsonObject($event['linked_ids'] ?? []),
            ':payload_json' => $this->encodeJsonObject($event['payload'] ?? []),
            ':created_at' => $this->timestamp($event['timestamp'] ?? $event['created_at'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTaskByIdempotency(string $tenantId, string $conversationId, string $idempotencyKey): ?array
    {
        if (trim($idempotencyKey) === '') {
            return null;
        }

        $rows = $this->selectRows(
            'SELECT * FROM control_tower_tasks
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id AND idempotency_key = :idempotency_key
             ORDER BY updated_at DESC LIMIT 1',
            [
                ':tenant_id' => $this->tenant($tenantId),
                ':conversation_id' => $this->text($conversationId),
                ':idempotency_key' => $this->text($idempotencyKey),
            ]
        );

        if ($rows === []) {
            return null;
        }

        return $this->normalizeTaskRow($rows[0]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTask(string $tenantId, string $taskId): ?array
    {
        $rows = $this->selectRows(
            'SELECT * FROM control_tower_tasks WHERE tenant_id = :tenant_id AND task_id = :task_id LIMIT 1',
            [
                ':tenant_id' => $this->tenant($tenantId),
                ':task_id' => $this->text($taskId),
            ]
        );

        if ($rows === []) {
            return null;
        }

        return $this->normalizeTaskRow($rows[0]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getIncident(string $tenantId, string $incidentId): ?array
    {
        $rows = $this->selectRows(
            'SELECT * FROM control_tower_incidents WHERE tenant_id = :tenant_id AND incident_id = :incident_id LIMIT 1',
            [
                ':tenant_id' => $this->tenant($tenantId),
                ':incident_id' => $this->text($incidentId),
            ]
        );

        if ($rows === []) {
            return null;
        }

        return $this->normalizeIncidentRow($rows[0]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTasks(string $tenantId, string $projectId, array $filters = [], int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT * FROM control_tower_tasks WHERE tenant_id = :tenant_id AND project_id = :project_id';
        $params = [
            ':tenant_id' => $this->tenant($tenantId),
            ':project_id' => $this->project($projectId),
        ];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $conversationId = trim((string) ($filters['conversation_id'] ?? ''));
        if ($conversationId !== '') {
            $sql .= ' AND conversation_id = :conversation_id';
            $params[':conversation_id'] = $conversationId;
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT ' . $limit;
        return array_map([$this, 'normalizeTaskRow'], $this->selectRows($sql, $params));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listIncidents(string $tenantId, string $projectId, array $filters = [], int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT * FROM control_tower_incidents WHERE tenant_id = :tenant_id AND project_id = :project_id';
        $params = [
            ':tenant_id' => $this->tenant($tenantId),
            ':project_id' => $this->project($projectId),
        ];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $relatedTaskId = trim((string) ($filters['related_task_id'] ?? ''));
        if ($relatedTaskId !== '') {
            $sql .= ' AND related_task_id = :related_task_id';
            $params[':related_task_id'] = $relatedTaskId;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
        return array_map([$this, 'normalizeIncidentRow'], $this->selectRows($sql, $params));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listEvents(string $tenantId, string $projectId, array $filters = [], int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM control_tower_events WHERE tenant_id = :tenant_id AND project_id = :project_id';
        $params = [
            ':tenant_id' => $this->tenant($tenantId),
            ':project_id' => $this->project($projectId),
        ];

        $eventType = trim((string) ($filters['event_type'] ?? ''));
        if ($eventType !== '') {
            $sql .= ' AND event_type = :event_type';
            $params[':event_type'] = $eventType;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;
        return array_map([$this, 'normalizeEventRow'], $this->selectRows($sql, $params));
    }

    private function ensureSchema(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS control_tower_tasks (
                task_id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                app_id TEXT NOT NULL,
                conversation_id TEXT NOT NULL,
                session_id TEXT NOT NULL DEFAULT \'\',
                user_id TEXT NOT NULL DEFAULT \'\',
                message_id TEXT NOT NULL DEFAULT \'\',
                intent TEXT NOT NULL,
                status TEXT NOT NULL,
                source TEXT NOT NULL,
                route_path TEXT NOT NULL DEFAULT \'\',
                gate_decision TEXT NOT NULL DEFAULT \'unknown\',
                related_entities_json TEXT NOT NULL DEFAULT \'[]\',
                related_events_json TEXT NOT NULL DEFAULT \'[]\',
                execution_result_json TEXT NOT NULL DEFAULT \'{}\',
                idempotency_key TEXT NOT NULL,
                metadata_json TEXT NOT NULL DEFAULT \'{}\',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_control_tower_tasks_scope ON control_tower_tasks (tenant_id, project_id, updated_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_control_tower_tasks_conversation ON control_tower_tasks (tenant_id, conversation_id, updated_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_control_tower_tasks_status ON control_tower_tasks (tenant_id, project_id, status, updated_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_control_tower_tasks_idempotency ON control_tower_tasks (tenant_id, conversation_id, idempotency_key)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS control_tower_incidents (
                incident_id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                app_id TEXT NOT NULL,
                severity TEXT NOT NULL,
                source TEXT NOT NULL,
                related_task_id TEXT NOT NULL,
                related_events_json TEXT NOT NULL DEFAULT \'[]\',
                status TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT \'\',
                metadata_json TEXT NOT NULL DEFAULT \'{}\',
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_control_tower_incidents_scope ON control_tower_incidents (tenant_id, project_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_control_tower_incidents_task ON control_tower_incidents (tenant_id, related_task_id, created_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS control_tower_events (
                event_id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                app_id TEXT NOT NULL,
                event_type TEXT NOT NULL,
                source TEXT NOT NULL,
                linked_ids_json TEXT NOT NULL DEFAULT \'{}\',
                payload_json TEXT NOT NULL DEFAULT \'{}\',
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_control_tower_events_scope ON control_tower_events (tenant_id, project_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_control_tower_events_type ON control_tower_events (tenant_id, project_id, event_type, created_at)');
    }

    private function defaultPath(): string
    {
        $override = trim((string) (getenv('PROJECT_REGISTRY_DB_PATH') ?: ''));
        if ($override !== '') {
            $dir = dirname($override);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            return $override;
        }

        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project';
        $dir = $projectRoot . '/storage/meta';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . '/project_registry.sqlite';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeTaskRow(array $row): array
    {
        return [
            'task_id' => $this->text($row['task_id'] ?? ''),
            'tenant_id' => $this->tenant($row['tenant_id'] ?? ''),
            'project_id' => $this->project($row['project_id'] ?? ''),
            'app_id' => $this->project($row['app_id'] ?? ''),
            'conversation_id' => $this->text($row['conversation_id'] ?? ''),
            'session_id' => $this->text($row['session_id'] ?? ''),
            'user_id' => $this->text($row['user_id'] ?? ''),
            'message_id' => $this->text($row['message_id'] ?? ''),
            'intent' => $this->text($row['intent'] ?? 'unknown'),
            'status' => $this->text($row['status'] ?? 'pending'),
            'source' => $this->text($row['source'] ?? 'chat'),
            'route_path' => $this->text($row['route_path'] ?? ''),
            'gate_decision' => $this->text($row['gate_decision'] ?? 'unknown'),
            'related_entities' => $this->decodeJson($row['related_entities_json'] ?? null, []),
            'related_events' => $this->decodeJson($row['related_events_json'] ?? null, []),
            'execution_result' => $this->decodeJson($row['execution_result_json'] ?? null, []),
            'idempotency_key' => $this->text($row['idempotency_key'] ?? ''),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null, []),
            'created_at' => $this->timestamp($row['created_at'] ?? null),
            'updated_at' => $this->timestamp($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeIncidentRow(array $row): array
    {
        return [
            'incident_id' => $this->text($row['incident_id'] ?? ''),
            'tenant_id' => $this->tenant($row['tenant_id'] ?? ''),
            'project_id' => $this->project($row['project_id'] ?? ''),
            'app_id' => $this->project($row['app_id'] ?? ''),
            'severity' => $this->text($row['severity'] ?? 'warning'),
            'source' => $this->text($row['source'] ?? 'system'),
            'related_task_id' => $this->text($row['related_task_id'] ?? ''),
            'related_events' => $this->decodeJson($row['related_events_json'] ?? null, []),
            'status' => $this->text($row['status'] ?? 'open'),
            'description' => $this->text($row['description'] ?? ''),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null, []),
            'created_at' => $this->timestamp($row['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeEventRow(array $row): array
    {
        return [
            'event_id' => $this->text($row['event_id'] ?? ''),
            'tenant_id' => $this->tenant($row['tenant_id'] ?? ''),
            'project_id' => $this->project($row['project_id'] ?? ''),
            'app_id' => $this->project($row['app_id'] ?? ''),
            'event_type' => $this->text($row['event_type'] ?? ''),
            'source' => $this->text($row['source'] ?? 'system'),
            'linked_ids' => $this->decodeJson($row['linked_ids_json'] ?? null, []),
            'payload' => $this->decodeJson($row['payload_json'] ?? null, []),
            'timestamp' => $this->timestamp($row['created_at'] ?? null),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [
            'control_tower_tasks',
            'control_tower_incidents',
            'control_tower_events',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            'control_tower_tasks' => [
                'idx_control_tower_tasks_scope',
                'idx_control_tower_tasks_conversation',
                'idx_control_tower_tasks_status',
                'idx_control_tower_tasks_idempotency',
            ],
            'control_tower_incidents' => [
                'idx_control_tower_incidents_scope',
                'idx_control_tower_incidents_task',
            ],
            'control_tower_events' => [
                'idx_control_tower_events_scope',
                'idx_control_tower_events_type',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function selectRows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function tenant($value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : 'default';
    }

    private function project($value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : 'default';
    }

    private function text($value): string
    {
        return trim((string) $value);
    }

    private function timestamp($value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : date('c');
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): string
    {
        $encoded = json_encode(
            is_array($value) ? $value : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @param mixed $value
     */
    private function encodeJsonObject($value): string
    {
        if (is_array($value) && $value === []) {
            $encoded = json_encode((object) [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : '{}';
        }

        return $this->encodeJson($value);
    }

    /**
     * @param mixed $value
     * @param array<string, mixed>|array<int, mixed> $default
     * @return array<string, mixed>|array<int, mixed>
     */
    private function decodeJson($value, array $default = []): array
    {
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }
}
