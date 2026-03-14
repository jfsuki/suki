<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class TenantAccessControlRepository
{
    private const TENANT_USER_TABLE = 'tenant_users';
    private const ROLE_PERMISSION_TABLE = 'role_permissions';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'TenantAccessControlRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260313_001_tenant_access_control_foundation.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function upsertTenantUser(array $record): array
    {
        $tenantId = trim((string) ($record['tenant_id'] ?? ''));
        $userId = trim((string) ($record['user_id'] ?? ''));
        if ($tenantId === '' || $userId === '') {
            throw new RuntimeException('ACCESS_CONTROL_TENANT_USER_SCOPE_REQUIRED');
        }

        $existing = $this->findTenantUserByUserId($tenantId, $userId);
        if (is_array($existing)) {
            $payload = $this->filterPayload($record, [
                'role_key',
                'status',
                'invited_at',
                'activated_at',
                'metadata_json',
            ]);
            if ($payload !== []) {
                $this->tenantUserQuery($tenantId)
                    ->where('id', '=', (string) ($existing['id'] ?? ''))
                    ->update($payload);
            }

            $updated = $this->findTenantUserByUserId($tenantId, $userId);
            if (is_array($updated)) {
                return $updated;
            }
            throw new RuntimeException('ACCESS_CONTROL_TENANT_USER_UPDATE_FAILED');
        }

        $id = (string) ((new QueryBuilder($this->db, self::TENANT_USER_TABLE))
            ->setAllowedColumns([
                'tenant_id',
                'user_id',
                'role_key',
                'status',
                'invited_at',
                'activated_at',
                'metadata_json',
                'created_at',
            ])
            ->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_key' => $record['role_key'] ?? 'viewer',
                'status' => $record['status'] ?? 'active',
                'invited_at' => $record['invited_at'] ?? null,
                'activated_at' => $record['activated_at'] ?? null,
                'metadata_json' => $record['metadata_json'] ?? $this->encodeJson([]),
                'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            ]));

        $saved = $this->findTenantUser($tenantId, $id);
        if (is_array($saved)) {
            return $saved;
        }

        throw new RuntimeException('ACCESS_CONTROL_TENANT_USER_INSERT_FAILED');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTenantUser(string $tenantId, string $tenantUserId): ?array
    {
        $row = $this->tenantUserQuery($tenantId)
            ->where('id', '=', $tenantUserId)
            ->first();

        return is_array($row) ? $this->normalizeTenantUserRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTenantUserByUserId(string $tenantId, string $userId): ?array
    {
        $row = $this->tenantUserQuery($tenantId)
            ->where('user_id', '=', $userId)
            ->first();

        return is_array($row) ? $this->normalizeTenantUserRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTenantUsers(string $tenantId, array $filters = [], int $limit = 25): array
    {
        $qb = $this->tenantUserQuery($tenantId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)));

        foreach (['role_key', 'status', 'user_id'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $qb->where($key, '=', $value);
            }
        }

        return array_map([$this, 'normalizeTenantUserRow'], $qb->get());
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateTenantUserByUserId(string $tenantId, string $userId, array $updates): ?array
    {
        $payload = $this->filterPayload($updates, [
            'role_key',
            'status',
            'invited_at',
            'activated_at',
            'metadata_json',
        ]);
        if ($payload === []) {
            return $this->findTenantUserByUserId($tenantId, $userId);
        }

        $this->tenantUserQuery($tenantId)
            ->where('user_id', '=', $userId)
            ->update($payload);

        return $this->findTenantUserByUserId($tenantId, $userId);
    }

    public function countTenantUsers(string $tenantId, array $filters = []): int
    {
        $qb = $this->tenantUserQuery($tenantId);
        foreach (['role_key', 'status', 'user_id'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $qb->where($key, '=', $value);
            }
        }

        return $qb->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRolePermissions(?string $tenantId, string $roleKey): array
    {
        $table = $this->tableName(self::ROLE_PERMISSION_TABLE);
        $tenantId = $this->nullableString($tenantId);
        $sql = 'SELECT id, tenant_id, role_key, module_key, action_key, effect, metadata_json
            FROM ' . $table . '
            WHERE role_key = :role_key AND (tenant_id IS NULL OR tenant_id = :tenant_id)
            ORDER BY CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END ASC, module_key ASC, action_key ASC, id ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':role_key' => $roleKey,
            ':tenant_id' => $tenantId,
        ]);

        return array_map([$this, 'normalizeRolePermissionRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function seedDefaultRolePermissions(): void
    {
        $table = $this->tableName(self::ROLE_PERMISSION_TABLE);
        $count = (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        if ($count > 0) {
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO ' . $table . ' (tenant_id, role_key, module_key, action_key, effect, metadata_json)
            VALUES (:tenant_id, :role_key, :module_key, :action_key, :effect, :metadata_json)');

        foreach ($this->defaultPermissions() as $permission) {
            $stmt->execute([
                ':tenant_id' => null,
                ':role_key' => $permission['role_key'],
                ':module_key' => $permission['module_key'],
                ':action_key' => $permission['action_key'],
                ':effect' => $permission['effect'],
                ':metadata_json' => $this->encodeJson([
                    'scope' => 'global_default',
                    'seeded_at' => date('Y-m-d H:i:s'),
                ]),
            ]);
        }
    }

    private function ensureSchema(): void
    {
        $tenantUsers = $this->tableName(self::TENANT_USER_TABLE);
        $rolePermissions = $this->tableName(self::ROLE_PERMISSION_TABLE);

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $tenantUsers . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            role_key TEXT NOT NULL,
            status TEXT NOT NULL,
            invited_at TEXT NULL,
            activated_at TEXT NULL,
            metadata_json TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tenant_users_tenant_user_unique ON '
            . $tenantUsers . ' (tenant_id, user_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_users_tenant_role ON '
            . $tenantUsers . ' (tenant_id, role_key)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_users_tenant_status ON '
            . $tenantUsers . ' (tenant_id, status)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $rolePermissions . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id TEXT NULL,
            role_key TEXT NOT NULL,
            module_key TEXT NOT NULL,
            action_key TEXT NOT NULL,
            effect TEXT NOT NULL,
            metadata_json TEXT NOT NULL
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_role_permissions_scope ON '
            . $rolePermissions . ' (role_key, tenant_id, module_key, action_key)');

        $this->seedDefaultRolePermissions();
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [
            self::TENANT_USER_TABLE,
            self::ROLE_PERMISSION_TABLE,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            self::TENANT_USER_TABLE => [
                'idx_tenant_users_tenant_user_unique',
                'idx_tenant_users_tenant_role',
                'idx_tenant_users_tenant_status',
            ],
            self::ROLE_PERMISSION_TABLE => [
                'idx_role_permissions_scope',
            ],
        ];
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function tableName(string $logical): string
    {
        return TableNamespace::resolve($logical);
    }

    private function tenantUserQuery(string $tenantId): QueryBuilder
    {
        return (new QueryBuilder($this->db, self::TENANT_USER_TABLE))
            ->setAllowedColumns([
                'id',
                'tenant_id',
                'user_id',
                'role_key',
                'status',
                'invited_at',
                'activated_at',
                'metadata_json',
                'created_at',
            ])
            ->where('tenant_id', '=', $tenantId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeTenantUserRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => trim((string) ($row['tenant_id'] ?? '')),
            'user_id' => trim((string) ($row['user_id'] ?? '')),
            'role_key' => trim((string) ($row['role_key'] ?? 'viewer')) ?: 'viewer',
            'status' => trim((string) ($row['status'] ?? 'inactive')) ?: 'inactive',
            'invited_at' => $this->nullableString($row['invited_at'] ?? null),
            'activated_at' => $this->nullableString($row['activated_at'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRolePermissionRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => $this->nullableString($row['tenant_id'] ?? null),
            'role_key' => trim((string) ($row['role_key'] ?? 'viewer')) ?: 'viewer',
            'module_key' => trim((string) ($row['module_key'] ?? '')) ?: '*',
            'action_key' => trim((string) ($row['action_key'] ?? '')) ?: '*',
            'effect' => trim((string) ($row['effect'] ?? 'deny')) ?: 'deny',
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
        ];
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
     * @return array<int, array<string, string>>
     */
    private function defaultPermissions(): array
    {
        $permissions = [
            ['role_key' => 'owner', 'module_key' => '*', 'action_key' => '*', 'effect' => 'allow'],
            ['role_key' => 'admin', 'module_key' => '*', 'action_key' => '*', 'effect' => 'allow'],
        ];

        foreach (['pos', 'purchases', 'fiscal', 'ecommerce', 'media', 'reports', 'entity', 'ops', 'agent_tools', 'agentops'] as $moduleKey) {
            $permissions[] = ['role_key' => 'manager', 'module_key' => $moduleKey, 'action_key' => '*', 'effect' => 'allow'];
            $permissions[] = ['role_key' => 'operator', 'module_key' => $moduleKey, 'action_key' => '*', 'effect' => 'allow'];
        }

        foreach ([
            ['module_key' => 'reports', 'action_key' => '*'],
            ['module_key' => 'media', 'action_key' => 'list'],
            ['module_key' => 'media', 'action_key' => 'get'],
            ['module_key' => 'entity', 'action_key' => 'search'],
            ['module_key' => 'entity', 'action_key' => 'resolve'],
            ['module_key' => 'ecommerce', 'action_key' => 'list_stores'],
            ['module_key' => 'ecommerce', 'action_key' => 'get_store'],
            ['module_key' => 'ecommerce', 'action_key' => 'get_store_metadata'],
            ['module_key' => 'ecommerce', 'action_key' => 'get_platform_capabilities'],
            ['module_key' => 'ecommerce', 'action_key' => 'list_sync_jobs'],
            ['module_key' => 'ecommerce', 'action_key' => 'list_product_links'],
            ['module_key' => 'ecommerce', 'action_key' => 'get_product_link'],
            ['module_key' => 'ecommerce', 'action_key' => 'list_order_links'],
            ['module_key' => 'ecommerce', 'action_key' => 'get_order_link'],
            ['module_key' => 'ecommerce', 'action_key' => 'get_order_snapshot'],
            ['module_key' => 'agent_tools', 'action_key' => '*'],
        ] as $permission) {
            $permissions[] = ['role_key' => 'viewer'] + $permission + ['effect' => 'allow'];
        }

        return $permissions;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode(
            is_array($value) ? $value : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($encoded) ? $encoded : '{}';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
