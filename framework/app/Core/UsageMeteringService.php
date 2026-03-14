<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class UsageMeteringService
{
    private UsageMeteringRepository $repository;
    private AuditLogger $auditLogger;
    private TenantPlanService $tenantPlanService;
    private TenantAccessControlService $accessControlService;
    private ProjectRegistry $projectRegistry;
    private EcommerceHubRepository $ecommerceRepository;

    public function __construct(
        ?UsageMeteringRepository $repository = null,
        ?AuditLogger $auditLogger = null,
        ?TenantPlanService $tenantPlanService = null,
        ?TenantAccessControlService $accessControlService = null,
        ?ProjectRegistry $projectRegistry = null,
        ?EcommerceHubRepository $ecommerceRepository = null
    ) {
        $this->repository = $repository ?? new UsageMeteringRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->tenantPlanService = $tenantPlanService ?? new TenantPlanService();
        $this->accessControlService = $accessControlService ?? new TenantAccessControlService();
        $this->projectRegistry = $projectRegistry ?? new ProjectRegistry();
        $this->ecommerceRepository = $ecommerceRepository ?? new EcommerceHubRepository();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordUsageEvent(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $metricKey = $this->metricKey($payload['metric_key'] ?? null);
        $definition = $this->metricDefinition($metricKey);
        $projectId = $this->nullableString($payload['project_id'] ?? $payload['app_id'] ?? null);
        $periodKey = $this->periodKey($metricKey, $payload['period_key'] ?? null);
        $deltaValue = $this->deltaValue($payload['delta_value'] ?? null);
        $unit = $this->unit($payload['unit'] ?? null, (string) ($definition['unit'] ?? 'count'));
        $createdAt = trim((string) ($payload['created_at'] ?? date('Y-m-d H:i:s')));
        $eventMetadata = $this->mergeMetadata(
            is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
            [
                'period_key' => $periodKey,
                'project_id' => $projectId,
                'recorded_at' => $createdAt,
            ]
        );

        $event = $this->repository->createUsageEvent([
            'tenant_id' => $tenantId,
            'metric_key' => $metricKey,
            'delta_value' => $deltaValue,
            'unit' => $unit,
            'source_module' => $this->requireString($payload['source_module'] ?? null, 'source_module'),
            'source_action' => $this->nullableString($payload['source_action'] ?? null),
            'source_ref' => $this->nullableString($payload['source_ref'] ?? null),
            'metadata_json' => $this->encodeJson($eventMetadata),
            'created_at' => $createdAt,
        ]);

        $meter = $this->aggregateMeterFromEvent($tenantId, $metricKey, $periodKey, $deltaValue, $unit, $projectId, $definition);
        $usageItem = $this->buildUsageItem($tenantId, $metricKey, $periodKey, $projectId, $meter, $definition);

        $this->auditLogger->log('usage_metering.record_event', 'usage_event', $event['id'] ?? null, [
            'tenant_id' => $tenantId,
            'metric_key' => $metricKey,
            'delta_value' => $deltaValue,
            'source_module' => $event['source_module'] ?? 'unknown',
            'result_status' => 'success',
        ]);

        return [
            'tenant_id' => $tenantId,
            'metric_key' => $metricKey,
            'period_key' => $periodKey,
            'event' => $event,
            'meter' => $usageItem['meter'],
            'usage_value' => $usageItem['usage_value'],
            'limit_key' => $usageItem['limit_key'],
            'limit_value' => $usageItem['limit_value'],
            'limit_type' => $usageItem['limit_type'],
            'plan_key' => $usageItem['plan_key'],
            'within_limit' => $usageItem['within_limit'],
            'near_limit' => $usageItem['near_limit'],
            'over_limit' => $usageItem['over_limit'],
            'result_status' => $usageItem['result_status'],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getTenantUsageSummary(string $tenantId, array $filters = [], ?string $projectId = null): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $metricKeys = $this->resolveMetricKeys($filters);
        $items = [];
        $overLimitMetrics = [];
        $nearLimitMetrics = [];

        foreach ($metricKeys as $metricKey) {
            $definition = $this->metricDefinition($metricKey);
            $periodKey = $this->periodKey($metricKey, $filters['period_key'] ?? null);
            $item = $this->buildUsageItem($tenantId, $metricKey, $periodKey, $projectId, null, $definition);
            $items[] = $item;

            if (($item['over_limit'] ?? false) === true) {
                $overLimitMetrics[] = $metricKey;
            } elseif (($item['near_limit'] ?? false) === true) {
                $nearLimitMetrics[] = $metricKey;
            }
        }

        return [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'items' => $items,
            'result_count' => count($items),
            'over_limit_metrics' => $overLimitMetrics,
            'near_limit_metrics' => $nearLimitMetrics,
            'generated_at' => date('c'),
            'result_status' => 'success',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkUsageLimit(string $tenantId, string $metricKey, ?string $projectId = null, ?string $periodKey = null): array
    {
        $metricKey = $this->metricKey($metricKey);

        return $this->buildUsageItem(
            $this->requireString($tenantId, 'tenant_id'),
            $metricKey,
            $this->periodKey($metricKey, $periodKey),
            $projectId,
            null,
            $this->metricDefinition($metricKey)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMetrics(): array
    {
        $items = [];
        foreach ($this->metricCatalog() as $metricKey => $definition) {
            $items[] = [
                'metric_key' => $metricKey,
                'label' => (string) ($definition['label'] ?? $metricKey),
                'unit' => (string) ($definition['unit'] ?? 'count'),
                'period_strategy' => (string) ($definition['period_strategy'] ?? 'current'),
                'limit_key' => (string) ($definition['limit_key'] ?? ''),
                'aggregation_strategy' => (string) ($definition['aggregation_strategy'] ?? 'event'),
                'source_modules' => is_array($definition['source_modules'] ?? null) ? array_values((array) $definition['source_modules']) : [],
                'result_status' => 'success',
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getMetricUsageHistory(string $tenantId, string $metricKey, array $filters = [], ?string $projectId = null): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $metricKey = $this->metricKey($metricKey);
        $periodKey = $this->periodKey($metricKey, $filters['period_key'] ?? null);
        $usageItem = $this->buildUsageItem($tenantId, $metricKey, $periodKey, $projectId, null, $this->metricDefinition($metricKey));
        $range = $this->eventRangeForMetric($metricKey, $periodKey);
        $events = $this->repository->listUsageEvents($tenantId, array_filter([
            'metric_key' => $metricKey,
            'source_module' => $filters['source_module'] ?? null,
            'source_action' => $filters['source_action'] ?? null,
            'source_ref' => $filters['source_ref'] ?? null,
            'date_from' => $range['date_from'] ?? null,
            'date_to' => $range['date_to'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== ''), max(1, (int) ($filters['limit'] ?? 50)));

        return [
            'tenant_id' => $tenantId,
            'metric_key' => $metricKey,
            'period_key' => $periodKey,
            'meter' => $usageItem['meter'],
            'usage_value' => $usageItem['usage_value'],
            'events' => $events,
            'result_count' => count($events),
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function aggregateMeterFromEvent(
        string $tenantId,
        string $metricKey,
        string $periodKey,
        int|float $deltaValue,
        string $unit,
        ?string $projectId,
        array $definition
    ): array {
        $existing = $this->repository->findUsageMeter($tenantId, $metricKey, $periodKey);
        $usageValue = $this->numericOutput(($existing['usage_value'] ?? 0) + $deltaValue, $unit);

        if (($definition['aggregation_strategy'] ?? 'event') === 'snapshot') {
            $usageValue = $this->resolveUsageValue($tenantId, $metricKey, $periodKey, $projectId, $definition);
        }

        if (is_numeric($usageValue) && (float) $usageValue < 0) {
            $usageValue = 0;
        }

        return $this->upsertMeter($tenantId, $metricKey, $periodKey, $usageValue, $unit, $definition, $existing);
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function upsertMeter(
        string $tenantId,
        string $metricKey,
        string $periodKey,
        int|float $usageValue,
        string $unit,
        array $definition,
        ?array $existing = null
    ): array {
        $existing = is_array($existing) ? $existing : $this->repository->findUsageMeter($tenantId, $metricKey, $periodKey);

        return $this->repository->upsertUsageMeter([
            'tenant_id' => $tenantId,
            'metric_key' => $metricKey,
            'period_key' => $periodKey,
            'usage_value' => $usageValue,
            'unit' => $unit,
            'metadata_json' => $this->encodeJson($this->mergeMetadata(
                is_array($existing['metadata'] ?? null) ? (array) $existing['metadata'] : [],
                [
                    'aggregation_strategy' => (string) ($definition['aggregation_strategy'] ?? 'event'),
                    'limit_key' => (string) ($definition['limit_key'] ?? ''),
                    'period_strategy' => (string) ($definition['period_strategy'] ?? 'current'),
                    'refreshed_at' => date('c'),
                ]
            )),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed>|null $meter
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function buildUsageItem(
        string $tenantId,
        string $metricKey,
        string $periodKey,
        ?string $projectId,
        ?array $meter,
        array $definition
    ): array {
        $usageValue = $this->resolveUsageValue($tenantId, $metricKey, $periodKey, $projectId, $definition);
        $meter = $this->upsertMeter(
            $tenantId,
            $metricKey,
            $periodKey,
            $usageValue,
            (string) ($definition['unit'] ?? 'count'),
            $definition,
            $meter
        );

        $comparison = $this->compareAgainstPlan(
            $tenantId,
            (string) ($definition['limit_key'] ?? ''),
            $meter['usage_value'] ?? $usageValue,
            $projectId
        );

        $limitValue = $comparison['limit_value'] ?? null;
        $withinLimit = ($comparison['within_limit'] ?? true) === true;
        $utilizationRatio = $this->utilizationRatio($meter['usage_value'] ?? $usageValue, $limitValue, (string) ($comparison['limit_type'] ?? 'unknown'));
        $nearLimit = $withinLimit && $utilizationRatio !== null && $utilizationRatio >= 0.8;
        $overLimit = !$withinLimit;
        $resultStatus = (string) ($comparison['result_status'] ?? 'success');
        if ($nearLimit && !$overLimit && $resultStatus === 'within_limit') {
            $resultStatus = 'near_limit';
        }

        return [
            'tenant_id' => $tenantId,
            'metric_key' => $metricKey,
            'period_key' => $periodKey,
            'unit' => (string) ($definition['unit'] ?? 'count'),
            'usage_value' => $meter['usage_value'] ?? $usageValue,
            'meter' => $meter,
            'limit_key' => (string) ($definition['limit_key'] ?? ''),
            'limit_value' => $limitValue,
            'limit_type' => (string) ($comparison['limit_type'] ?? 'unknown'),
            'plan_key' => (string) ($comparison['plan_key'] ?? ''),
            'within_limit' => $withinLimit,
            'near_limit' => $nearLimit,
            'over_limit' => $overLimit,
            'utilization_ratio' => $utilizationRatio,
            'enforcement_hint' => (string) ($comparison['enforcement_hint'] ?? 'limit_not_defined'),
            'aggregation_strategy' => (string) ($definition['aggregation_strategy'] ?? 'event'),
            'result_status' => $resultStatus,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function resolveUsageValue(string $tenantId, string $metricKey, string $periodKey, ?string $projectId, array $definition): int|float
    {
        return match ((string) ($definition['aggregation_strategy'] ?? 'event')) {
            'snapshot' => $this->snapshotUsageValue($tenantId, $metricKey, $projectId),
            default => $this->eventUsageValue($tenantId, $metricKey, $periodKey, $projectId),
        };
    }

    private function snapshotUsageValue(string $tenantId, string $metricKey, ?string $projectId): int|float
    {
        return match ($metricKey) {
            'users' => $this->activeTenantUserCount($tenantId, $projectId),
            'ecommerce_channels' => $this->ecommerceRepository->countStores($tenantId, array_filter([
                'app_id' => $projectId,
            ], static fn($value): bool => $value !== null && $value !== '')),
            'active_stores' => $this->ecommerceRepository->countStores($tenantId, array_filter([
                'app_id' => $projectId,
                'status' => 'active',
            ], static fn($value): bool => $value !== null && $value !== '')),
            default => 0,
        };
    }

    private function eventUsageValue(string $tenantId, string $metricKey, string $periodKey, ?string $projectId): int|float
    {
        $range = $this->eventRangeForMetric($metricKey, $periodKey);
        $definition = $this->metricDefinition($metricKey);
        $sum = $this->repository->sumUsageEvents($tenantId, $metricKey, array_filter([
            'date_from' => $range['date_from'] ?? null,
            'date_to' => $range['date_to'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== ''));

        if (in_array($metricKey, ['users', 'ecommerce_channels', 'active_stores'], true)) {
            return $this->snapshotUsageValue($tenantId, $metricKey, $projectId);
        }

        return $this->numericOutput($sum, (string) ($definition['unit'] ?? 'count'));
    }

    /**
     * @return array<string, mixed>
     */
    private function compareAgainstPlan(string $tenantId, string $limitKey, int|float $usageValue, ?string $projectId): array
    {
        $limitKey = strtolower(trim($limitKey));
        if ($limitKey === '') {
            return [
                'tenant_id' => $tenantId,
                'plan_key' => '',
                'limit_key' => '',
                'limit_value' => null,
                'limit_type' => 'unknown',
                'usage_value' => $usageValue,
                'within_limit' => true,
                'exceeded_by' => 0,
                'enforcement_hint' => 'limit_not_defined',
                'result_status' => 'limit_not_defined',
            ];
        }

        try {
            return $this->tenantPlanService->checkTenantPlanLimit($tenantId, $limitKey, $usageValue, $projectId);
        } catch (RuntimeException $e) {
            if ((string) $e->getMessage() === 'TENANT_PLAN_NOT_FOUND') {
                return [
                    'tenant_id' => $tenantId,
                    'plan_key' => '',
                    'limit_key' => $limitKey,
                    'limit_value' => null,
                    'limit_type' => 'unknown',
                    'usage_value' => $usageValue,
                    'within_limit' => true,
                    'exceeded_by' => 0,
                    'enforcement_hint' => 'plan_not_assigned',
                    'result_status' => 'plan_not_assigned',
                ];
            }

            throw $e;
        }
    }

    private function activeTenantUserCount(string $tenantId, ?string $projectId): int
    {
        try {
            $membershipCount = $this->accessControlService->countTenantUsers($tenantId);
            $activeCount = $this->accessControlService->countTenantUsers($tenantId, ['status' => 'active']);
            if ($membershipCount > 0 || $projectId === null || $projectId === '') {
                return $activeCount;
            }
        } catch (\Throwable $e) {
            // Fall back to auth users when tenant memberships are not materialized yet.
        }

        if ($projectId === null || $projectId === '') {
            return 0;
        }

        $count = 0;
        foreach ($this->projectRegistry->listAuthUsers($projectId) as $user) {
            if (!is_array($user)) {
                continue;
            }
            if (trim((string) ($user['tenant_id'] ?? '')) === $tenantId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, string>
     */
    private function resolveMetricKeys(array $filters): array
    {
        if (is_array($filters['metric_keys'] ?? null)) {
            $items = [];
            foreach ((array) $filters['metric_keys'] as $metricKey) {
                $items[] = $this->metricKey($metricKey);
            }

            return array_values(array_unique($items));
        }

        if (array_key_exists('metric_key', $filters) && trim((string) ($filters['metric_key'] ?? '')) !== '') {
            return [$this->metricKey($filters['metric_key'])];
        }

        return array_keys($this->metricCatalog());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function metricCatalog(): array
    {
        return [
            'users' => [
                'label' => 'Active tenant users',
                'unit' => 'count',
                'period_strategy' => 'current',
                'limit_key' => 'users',
                'aggregation_strategy' => 'snapshot',
                'source_modules' => ['access_control', 'project_registry'],
                'aliases' => ['users', 'usuarios', 'usuarios_activos', 'active_users'],
            ],
            'ai_requests_month' => [
                'label' => 'AI requests this month',
                'unit' => 'count',
                'period_strategy' => 'monthly',
                'limit_key' => 'ai_requests_month',
                'aggregation_strategy' => 'event',
                'source_modules' => ['agentops'],
                'aliases' => ['ai_requests_month', 'ai_requests', 'solicitudes_ia', 'peticiones_ia'],
            ],
            'storage_mb' => [
                'label' => 'Storage consumed in megabytes',
                'unit' => 'mb',
                'period_strategy' => 'current',
                'limit_key' => 'storage_mb',
                'aggregation_strategy' => 'event',
                'source_modules' => ['media'],
                'aliases' => ['storage_mb', 'storage', 'almacenamiento', 'almacenamiento_mb'],
            ],
            'ecommerce_channels' => [
                'label' => 'Configured ecommerce channels',
                'unit' => 'count',
                'period_strategy' => 'current',
                'limit_key' => 'ecommerce_channels',
                'aggregation_strategy' => 'snapshot',
                'source_modules' => ['ecommerce'],
                'aliases' => ['ecommerce_channels', 'canales_ecommerce', 'tiendas_ecommerce'],
            ],
            'sync_jobs_month' => [
                'label' => 'Ecommerce sync jobs this month',
                'unit' => 'count',
                'period_strategy' => 'monthly',
                'limit_key' => 'sync_jobs_month',
                'aggregation_strategy' => 'event',
                'source_modules' => ['ecommerce'],
                'aliases' => ['sync_jobs_month', 'sync_jobs', 'sincronizaciones'],
            ],
            'pos_registers' => [
                'label' => 'POS registers',
                'unit' => 'count',
                'period_strategy' => 'current',
                'limit_key' => 'pos_registers',
                'aggregation_strategy' => 'event',
                'source_modules' => ['pos'],
                'aliases' => ['pos_registers', 'cajas_pos'],
            ],
            'active_stores' => [
                'label' => 'Active stores',
                'unit' => 'count',
                'period_strategy' => 'current',
                'limit_key' => 'stores',
                'aggregation_strategy' => 'snapshot',
                'source_modules' => ['ecommerce'],
                'aliases' => ['active_stores', 'tiendas_activas'],
            ],
            'documents_uploaded' => [
                'label' => 'Uploaded documents',
                'unit' => 'count',
                'period_strategy' => 'current',
                'limit_key' => '',
                'aggregation_strategy' => 'event',
                'source_modules' => ['media'],
                'aliases' => ['documents_uploaded', 'documentos_subidos', 'documentos'],
            ],
            'sales_created' => [
                'label' => 'Created sales',
                'unit' => 'count',
                'period_strategy' => 'current',
                'limit_key' => '',
                'aggregation_strategy' => 'event',
                'source_modules' => ['pos'],
                'aliases' => ['sales_created', 'ventas_creadas', 'ventas'],
            ],
            'purchases_created' => [
                'label' => 'Created purchases',
                'unit' => 'count',
                'period_strategy' => 'current',
                'limit_key' => '',
                'aggregation_strategy' => 'event',
                'source_modules' => ['purchases'],
                'aliases' => ['purchases_created', 'compras_creadas', 'compras'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metricDefinition(string $metricKey): array
    {
        return $this->metricCatalog()[$metricKey];
    }

    private function metricKey(mixed $value): string
    {
        $raw = strtolower(trim((string) $value));
        foreach ($this->metricCatalog() as $metricKey => $definition) {
            $aliases = is_array($definition['aliases'] ?? null) ? (array) $definition['aliases'] : [];
            if ($raw === $metricKey || in_array($raw, $aliases, true)) {
                return $metricKey;
            }
        }

        throw new RuntimeException('USAGE_METRIC_KEY_INVALID');
    }

    private function periodKey(string $metricKey, mixed $value): string
    {
        $strategy = (string) ($this->metricDefinition($metricKey)['period_strategy'] ?? 'current');
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $strategy === 'monthly' ? date('Y-m') : 'current';
        }

        if ($strategy === 'monthly') {
            if ($raw === 'current') {
                return date('Y-m');
            }
            if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
                return $raw;
            }
            throw new RuntimeException('USAGE_PERIOD_KEY_INVALID');
        }

        if ($raw !== 'current') {
            throw new RuntimeException('USAGE_PERIOD_KEY_INVALID');
        }

        return 'current';
    }

    private function requireString(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        }

        return $value;
    }

    private function deltaValue(mixed $value): int|float
    {
        if ($value === null || $value === '') {
            throw new RuntimeException('USAGE_DELTA_VALUE_REQUIRED');
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('USAGE_DELTA_VALUE_INVALID');
        }

        return $this->numericOutput((float) $value, 'count');
    }

    private function unit(mixed $value, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $fallback;
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function eventRangeForMetric(string $metricKey, string $periodKey): array
    {
        $strategy = (string) ($this->metricDefinition($metricKey)['period_strategy'] ?? 'current');
        if ($strategy !== 'monthly') {
            return [];
        }

        $start = $periodKey . '-01 00:00:00';
        $next = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));

        return ['date_from' => $start, 'date_to' => $next];
    }

    private function utilizationRatio(mixed $usageValue, mixed $limitValue, string $limitType): ?float
    {
        if ($limitType === 'feature' || !is_numeric($usageValue) || !is_numeric($limitValue)) {
            return null;
        }
        $limit = (float) $limitValue;
        if ($limit <= 0) {
            return null;
        }

        return round(((float) $usageValue) / $limit, 4);
    }

    /**
     * @param array<string, mixed> ...$bags
     * @return array<string, mixed>
     */
    private function mergeMetadata(array ...$bags): array
    {
        $merged = [];
        foreach ($bags as $bag) {
            foreach ($bag as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode(
            is_array($value) ? $value : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($encoded) ? $encoded : '{}';
    }

    private function numericOutput(float|int $value, string $unit): int|float
    {
        $float = round((float) $value, 4);
        if ($unit === 'count' && abs($float - round($float)) < 0.0001) {
            return (int) round($float);
        }

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
