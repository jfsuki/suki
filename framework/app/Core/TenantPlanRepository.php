<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class TenantPlanRepository
{
    private const TENANT_PLAN_TABLE = 'tenant_plans';
    private const PLAN_LIMIT_TABLE = 'plan_limits';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'TenantPlanRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260313_002_tenant_plan_foundation.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function upsertTenantPlan(array $record): array
    {
        $tenantId = trim((string) ($record['tenant_id'] ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('TENANT_ID_REQUIRED');
        }

        $existing = $this->findTenantPlanByTenant($tenantId);
        if (is_array($existing)) {
            $payload = $this->filterPayload($record, [
                'plan_key',
                'status',
                'base_price',
                'currency',
                'included_users',
                'extra_user_price',
                'billing_period',
                'metadata_json',
            ]);
            if ($payload !== []) {
                $this->tenantPlanQuery($tenantId)
                    ->where('id', '=', (string) ($existing['id'] ?? ''))
                    ->update($payload);
            }

            $updated = $this->findTenantPlanByTenant($tenantId);
            if (is_array($updated)) {
                return $updated;
            }

            throw new RuntimeException('TENANT_PLAN_UPDATE_FAILED');
        }

        $id = (string) ((new QueryBuilder($this->db, self::TENANT_PLAN_TABLE))
            ->setAllowedColumns([
                'tenant_id',
                'plan_key',
                'status',
                'base_price',
                'currency',
                'included_users',
                'extra_user_price',
                'billing_period',
                'metadata_json',
                'created_at',
            ])
            ->insert([
                'tenant_id' => $tenantId,
                'plan_key' => $record['plan_key'] ?? 'starter',
                'status' => $record['status'] ?? 'active',
                'base_price' => $record['base_price'] ?? null,
                'currency' => $record['currency'] ?? null,
                'included_users' => $record['included_users'] ?? null,
                'extra_user_price' => $record['extra_user_price'] ?? null,
                'billing_period' => $record['billing_period'] ?? null,
                'metadata_json' => $record['metadata_json'] ?? $this->encodeJson([]),
                'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            ]));

        $saved = $this->findTenantPlan($tenantId, $id);
        if (is_array($saved)) {
            return $saved;
        }

        throw new RuntimeException('TENANT_PLAN_INSERT_FAILED');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTenantPlan(string $tenantId, string $tenantPlanId): ?array
    {
        $row = $this->tenantPlanQuery($tenantId)
            ->where('id', '=', $tenantPlanId)
            ->first();

        return is_array($row) ? $this->normalizeTenantPlanRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTenantPlanByTenant(string $tenantId): ?array
    {
        $row = $this->tenantPlanQuery($tenantId)->first();

        return is_array($row) ? $this->normalizeTenantPlanRow($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPlanLimits(string $planKey): array
    {
        $rows = (new QueryBuilder($this->db, self::PLAN_LIMIT_TABLE))
            ->setAllowedColumns([
                'id',
                'plan_key',
                'limit_key',
                'limit_value',
                'limit_type',
                'metadata_json',
            ])
            ->where('plan_key', '=', strtolower(trim($planKey)))
            ->orderBy('limit_key', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return array_map([$this, 'normalizePlanLimitRow'], $rows);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function upsertPlanLimit(array $record): array
    {
        $planKey = strtolower(trim((string) ($record['plan_key'] ?? '')));
        $limitKey = strtolower(trim((string) ($record['limit_key'] ?? '')));
        if ($planKey === '' || $limitKey === '') {
            throw new RuntimeException('PLAN_LIMIT_SCOPE_REQUIRED');
        }

        $existing = $this->findPlanLimit($planKey, $limitKey);
        if (is_array($existing)) {
            $payload = $this->filterPayload($record, [
                'limit_value',
                'limit_type',
                'metadata_json',
            ]);
            if ($payload !== []) {
                $this->planLimitQuery($planKey)
                    ->where('id', '=', (string) ($existing['id'] ?? ''))
                    ->update($payload);
            }

            $updated = $this->findPlanLimit($planKey, $limitKey);
            if (is_array($updated)) {
                return $updated;
            }

            throw new RuntimeException('PLAN_LIMIT_UPDATE_FAILED');
        }

        (new QueryBuilder($this->db, self::PLAN_LIMIT_TABLE))
            ->setAllowedColumns([
                'plan_key',
                'limit_key',
                'limit_value',
                'limit_type',
                'metadata_json',
            ])
            ->insert([
                'plan_key' => $planKey,
                'limit_key' => $limitKey,
                'limit_value' => (string) ($record['limit_value'] ?? ''),
                'limit_type' => $record['limit_type'] ?? 'hard',
                'metadata_json' => $record['metadata_json'] ?? $this->encodeJson([]),
            ]);

        $saved = $this->findPlanLimit($planKey, $limitKey);
        if (is_array($saved)) {
            return $saved;
        }

        throw new RuntimeException('PLAN_LIMIT_INSERT_FAILED');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlanLimit(string $planKey, string $limitKey): ?array
    {
        $row = $this->planLimitQuery($planKey)
            ->where('limit_key', '=', strtolower(trim($limitKey)))
            ->first();

        return is_array($row) ? $this->normalizePlanLimitRow($row) : null;
    }

    private function ensureSchema(): void
    {
        $tenantPlans = $this->tableName(self::TENANT_PLAN_TABLE);
        $planLimits = $this->tableName(self::PLAN_LIMIT_TABLE);

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $tenantPlans . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id TEXT NOT NULL,
            plan_key TEXT NOT NULL,
            status TEXT NOT NULL,
            base_price NUMERIC NULL,
            currency TEXT NULL,
            included_users INTEGER NULL,
            extra_user_price NUMERIC NULL,
            billing_period TEXT NULL,
            metadata_json TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tenant_plans_tenant_unique ON '
            . $tenantPlans . ' (tenant_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_plans_plan_key ON '
            . $tenantPlans . ' (plan_key)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $planLimits . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            plan_key TEXT NOT NULL,
            limit_key TEXT NOT NULL,
            limit_value TEXT NOT NULL,
            limit_type TEXT NOT NULL,
            metadata_json TEXT NOT NULL
        )');
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_plan_limits_plan_limit_unique ON '
            . $planLimits . ' (plan_key, limit_key)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_plan_limits_plan_type ON '
            . $planLimits . ' (plan_key, limit_type)');
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [
            self::TENANT_PLAN_TABLE,
            self::PLAN_LIMIT_TABLE,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            self::TENANT_PLAN_TABLE => [
                'idx_tenant_plans_tenant_unique',
                'idx_tenant_plans_plan_key',
            ],
            self::PLAN_LIMIT_TABLE => [
                'idx_plan_limits_plan_limit_unique',
                'idx_plan_limits_plan_type',
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

    private function tenantPlanQuery(string $tenantId): QueryBuilder
    {
        return (new QueryBuilder($this->db, self::TENANT_PLAN_TABLE))
            ->setAllowedColumns([
                'id',
                'tenant_id',
                'plan_key',
                'status',
                'base_price',
                'currency',
                'included_users',
                'extra_user_price',
                'billing_period',
                'metadata_json',
                'created_at',
            ])
            ->where('tenant_id', '=', $tenantId);
    }

    private function planLimitQuery(string $planKey): QueryBuilder
    {
        return (new QueryBuilder($this->db, self::PLAN_LIMIT_TABLE))
            ->setAllowedColumns([
                'id',
                'plan_key',
                'limit_key',
                'limit_value',
                'limit_type',
                'metadata_json',
            ])
            ->where('plan_key', '=', strtolower(trim($planKey)));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeTenantPlanRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => trim((string) ($row['tenant_id'] ?? '')),
            'plan_key' => strtolower(trim((string) ($row['plan_key'] ?? 'starter'))) ?: 'starter',
            'status' => trim((string) ($row['status'] ?? 'active')) ?: 'active',
            'base_price' => $this->nullableFloat($row['base_price'] ?? null),
            'currency' => $this->nullableString($row['currency'] ?? null),
            'included_users' => $this->nullableInt($row['included_users'] ?? null),
            'extra_user_price' => $this->nullableFloat($row['extra_user_price'] ?? null),
            'billing_period' => $this->nullableString($row['billing_period'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizePlanLimitRow(array $row): array
    {
        $metadata = $this->decodeJson($row['metadata_json'] ?? null);

        return [
            'id' => (string) ($row['id'] ?? ''),
            'plan_key' => strtolower(trim((string) ($row['plan_key'] ?? ''))),
            'limit_key' => strtolower(trim((string) ($row['limit_key'] ?? ''))),
            'limit_value' => $this->normalizeLimitValue($row['limit_value'] ?? null, $metadata, (string) ($row['limit_type'] ?? 'hard')),
            'limit_type' => strtolower(trim((string) ($row['limit_type'] ?? 'hard'))) ?: 'hard',
            'metadata' => $metadata,
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
     * @param array<string, mixed> $metadata
     * @return bool|float|int|string|null
     */
    private function normalizeLimitValue(mixed $value, array $metadata, string $limitType)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $valueKind = strtolower(trim((string) ($metadata['value_kind'] ?? '')));
        if ($valueKind === 'bool' || $limitType === 'feature') {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'si', 'on'], true);
        }
        if ($valueKind === 'int') {
            return (int) $raw;
        }
        if ($valueKind === 'float') {
            return (float) $raw;
        }
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
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

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float) $value : null;
    }
}
