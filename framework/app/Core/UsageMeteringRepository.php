<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class UsageMeteringRepository
{
    private const USAGE_METER_TABLE = 'usage_meters';
    private const USAGE_EVENT_TABLE = 'usage_events';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'UsageMeteringRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            [],
            'db/migrations/' . $this->driver() . '/20260314_003_usage_metering_foundation.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createUsageEvent(array $record): array
    {
        $tenantId = trim((string) ($record['tenant_id'] ?? ''));
        $metricKey = strtolower(trim((string) ($record['metric_key'] ?? '')));
        if ($tenantId === '' || $metricKey === '') {
            throw new RuntimeException('USAGE_EVENT_SCOPE_REQUIRED');
        }

        $id = (string) ((new QueryBuilder($this->db, self::USAGE_EVENT_TABLE))
            ->setAllowedColumns([
                'tenant_id',
                'metric_key',
                'delta_value',
                'unit',
                'source_module',
                'source_action',
                'source_ref',
                'metadata_json',
                'created_at',
            ])
            ->insert([
                'tenant_id' => $tenantId,
                'metric_key' => $metricKey,
                'delta_value' => $record['delta_value'] ?? 0,
                'unit' => $record['unit'] ?? 'count',
                'source_module' => $record['source_module'] ?? 'unknown',
                'source_action' => $record['source_action'] ?? null,
                'source_ref' => $record['source_ref'] ?? null,
                'metadata_json' => $record['metadata_json'] ?? $this->encodeJson([]),
                'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            ]));

        $saved = $this->findUsageEvent($tenantId, $id);
        if (is_array($saved)) {
            return $saved;
        }

        throw new RuntimeException('USAGE_EVENT_INSERT_FAILED');
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function upsertUsageMeter(array $record): array
    {
        $tenantId = trim((string) ($record['tenant_id'] ?? ''));
        $metricKey = strtolower(trim((string) ($record['metric_key'] ?? '')));
        $periodKey = trim((string) ($record['period_key'] ?? ''));
        if ($tenantId === '' || $metricKey === '' || $periodKey === '') {
            throw new RuntimeException('USAGE_METER_SCOPE_REQUIRED');
        }

        $existing = $this->findUsageMeter($tenantId, $metricKey, $periodKey);
        if (is_array($existing)) {
            $payload = $this->filterPayload($record, [
                'usage_value',
                'unit',
                'metadata_json',
                'updated_at',
            ]);
            if ($payload === []) {
                return $existing;
            }

            $this->usageMeterQuery($tenantId)
                ->where('id', '=', (string) ($existing['id'] ?? ''))
                ->update($payload);

            $updated = $this->findUsageMeter($tenantId, $metricKey, $periodKey);
            if (is_array($updated)) {
                return $updated;
            }

            throw new RuntimeException('USAGE_METER_UPDATE_FAILED');
        }

        (new QueryBuilder($this->db, self::USAGE_METER_TABLE))
            ->setAllowedColumns([
                'tenant_id',
                'metric_key',
                'period_key',
                'usage_value',
                'unit',
                'metadata_json',
                'updated_at',
            ])
            ->insert([
                'tenant_id' => $tenantId,
                'metric_key' => $metricKey,
                'period_key' => $periodKey,
                'usage_value' => $record['usage_value'] ?? 0,
                'unit' => $record['unit'] ?? 'count',
                'metadata_json' => $record['metadata_json'] ?? $this->encodeJson([]),
                'updated_at' => $record['updated_at'] ?? date('Y-m-d H:i:s'),
            ]);

        $saved = $this->findUsageMeter($tenantId, $metricKey, $periodKey);
        if (is_array($saved)) {
            return $saved;
        }

        throw new RuntimeException('USAGE_METER_INSERT_FAILED');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUsageMeter(string $tenantId, string $metricKey, string $periodKey): ?array
    {
        $row = $this->usageMeterQuery($tenantId)
            ->where('metric_key', '=', strtolower(trim($metricKey)))
            ->where('period_key', '=', trim($periodKey))
            ->first();

        return is_array($row) ? $this->normalizeUsageMeterRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUsageEvent(string $tenantId, string $usageEventId): ?array
    {
        $row = $this->usageEventQuery($tenantId)
            ->where('id', '=', $usageEventId)
            ->first();

        return is_array($row) ? $this->normalizeUsageEventRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listUsageMeters(string $tenantId, array $filters = [], int $limit = 100): array
    {
        $qb = $this->usageMeterQuery($tenantId)
            ->orderBy('updated_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(250, $limit)));

        foreach (['metric_key', 'period_key', 'unit'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $qb->where($key, '=', strtolower($value));
            }
        }

        return array_map([$this, 'normalizeUsageMeterRow'], $qb->get());
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listUsageEvents(string $tenantId, array $filters = [], int $limit = 50): array
    {
        $qb = $this->usageEventQuery($tenantId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(500, $limit)));

        foreach (['metric_key', 'source_module', 'source_action', 'source_ref', 'unit'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $qb->where($key, '=', $key === 'source_ref' ? $value : strtolower($value));
            }
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $qb->where('created_at', '>=', $dateFrom);
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $qb->where('created_at', '<', $dateTo);
        }

        return array_map([$this, 'normalizeUsageEventRow'], $qb->get());
    }

    public function sumUsageEvents(string $tenantId, string $metricKey, array $filters = []): float
    {
        $table = $this->tableName(self::USAGE_EVENT_TABLE);
        $conditions = ['tenant_id = :tenant_id', 'metric_key = :metric_key'];
        $bindings = [
            ':tenant_id' => $tenantId,
            ':metric_key' => strtolower(trim($metricKey)),
        ];

        foreach (['source_module', 'source_action', 'source_ref', 'unit'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $conditions[] = $key . ' = :' . $key;
            $bindings[':' . $key] = $key === 'source_ref' ? $value : strtolower($value);
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $conditions[] = 'created_at >= :date_from';
            $bindings[':date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $conditions[] = 'created_at < :date_to';
            $bindings[':date_to'] = $dateTo;
        }

        $sql = 'SELECT COALESCE(SUM(delta_value), 0) AS aggregate_usage FROM ' . $table
            . ' WHERE ' . implode(' AND ', $conditions);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);
        $value = $stmt->fetchColumn();

        return round((float) ($value ?? 0), 4);
    }

    private function ensureSchema(): void
    {
        $usageMeters = $this->tableName(self::USAGE_METER_TABLE);
        $usageEvents = $this->tableName(self::USAGE_EVENT_TABLE);

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $usageMeters . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id TEXT NOT NULL,
            metric_key TEXT NOT NULL,
            period_key TEXT NOT NULL,
            usage_value NUMERIC NOT NULL,
            unit TEXT NOT NULL,
            metadata_json TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_usage_meters_tenant_metric_period_unique ON '
            . $usageMeters . ' (tenant_id, metric_key, period_key)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_usage_meters_tenant_period ON '
            . $usageMeters . ' (tenant_id, period_key, updated_at)');

        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $usageEvents . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id TEXT NOT NULL,
            metric_key TEXT NOT NULL,
            delta_value NUMERIC NOT NULL,
            unit TEXT NOT NULL,
            source_module TEXT NOT NULL,
            source_action TEXT NULL,
            source_ref TEXT NULL,
            metadata_json TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_usage_events_tenant_metric_created ON '
            . $usageEvents . ' (tenant_id, metric_key, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_usage_events_tenant_source ON '
            . $usageEvents . ' (tenant_id, source_module, source_action, created_at)');
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [
            self::USAGE_METER_TABLE,
            self::USAGE_EVENT_TABLE,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            self::USAGE_METER_TABLE => [
                'idx_usage_meters_tenant_metric_period_unique',
                'idx_usage_meters_tenant_period',
            ],
            self::USAGE_EVENT_TABLE => [
                'idx_usage_events_tenant_metric_created',
                'idx_usage_events_tenant_source',
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

    private function usageMeterQuery(string $tenantId): QueryBuilder
    {
        return (new QueryBuilder($this->db, self::USAGE_METER_TABLE))
            ->setAllowedColumns([
                'id',
                'tenant_id',
                'metric_key',
                'period_key',
                'usage_value',
                'unit',
                'metadata_json',
                'updated_at',
            ])
            ->where('tenant_id', '=', $tenantId);
    }

    private function usageEventQuery(string $tenantId): QueryBuilder
    {
        return (new QueryBuilder($this->db, self::USAGE_EVENT_TABLE))
            ->setAllowedColumns([
                'id',
                'tenant_id',
                'metric_key',
                'delta_value',
                'unit',
                'source_module',
                'source_action',
                'source_ref',
                'metadata_json',
                'created_at',
            ])
            ->where('tenant_id', '=', $tenantId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeUsageMeterRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => trim((string) ($row['tenant_id'] ?? '')),
            'metric_key' => strtolower(trim((string) ($row['metric_key'] ?? ''))),
            'period_key' => trim((string) ($row['period_key'] ?? '')),
            'usage_value' => $this->numericValue($row['usage_value'] ?? 0),
            'unit' => trim((string) ($row['unit'] ?? 'count')) ?: 'count',
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeUsageEventRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => trim((string) ($row['tenant_id'] ?? '')),
            'metric_key' => strtolower(trim((string) ($row['metric_key'] ?? ''))),
            'delta_value' => $this->numericValue($row['delta_value'] ?? 0),
            'unit' => trim((string) ($row['unit'] ?? 'count')) ?: 'count',
            'source_module' => trim((string) ($row['source_module'] ?? 'unknown')) ?: 'unknown',
            'source_action' => $this->nullableString($row['source_action'] ?? null),
            'source_ref' => $this->nullableString($row['source_ref'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
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

    private function numericValue(mixed $value): int|float
    {
        $float = round((float) ($value ?? 0), 4);
        if (abs($float - round($float)) < 0.0001) {
            return (int) round($float);
        }

        return $float;
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
