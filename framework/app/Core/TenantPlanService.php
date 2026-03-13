<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class TenantPlanService
{
    /** @var array<int, string> */
    private const PLAN_STATUSES = ['active', 'paused', 'canceled'];

    /** @var array<int, string> */
    private const BILLING_PERIODS = ['monthly', 'yearly', 'custom'];

    /** @var array<int, string> */
    private const LIMIT_TYPES = ['hard', 'soft', 'feature'];

    private TenantPlanRepository $repository;
    private AuditLogger $auditLogger;
    private TenantAccessControlService $accessControlService;
    private ProjectRegistry $projectRegistry;

    public function __construct(
        ?TenantPlanRepository $repository = null,
        ?AuditLogger $auditLogger = null,
        ?TenantAccessControlService $accessControlService = null,
        ?ProjectRegistry $projectRegistry = null
    ) {
        $this->repository = $repository ?? new TenantPlanRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->projectRegistry = $projectRegistry ?? new ProjectRegistry();
        $this->accessControlService = $accessControlService ?? new TenantAccessControlService();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function assignPlanToTenant(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $planKey = $this->planKey($payload['plan_key'] ?? null);
        $definition = $this->defaultPlanCatalog()[$planKey];
        $status = $this->planStatus($payload['status'] ?? $definition['status'] ?? 'active');
        $projectId = $this->nullableString($payload['project_id'] ?? $payload['app_id'] ?? null);
        $actorUserId = $this->nullableString($payload['actor_user_id'] ?? null);
        $existing = $this->repository->findTenantPlanByTenant($tenantId);

        $saved = $this->repository->upsertTenantPlan([
            'tenant_id' => $tenantId,
            'plan_key' => $planKey,
            'status' => $status,
            'base_price' => $this->nullableFloat($payload['base_price'] ?? $definition['base_price'] ?? null),
            'currency' => $this->normalizeCurrency($payload['currency'] ?? $definition['currency'] ?? null),
            'included_users' => $this->nullableInt($payload['included_users'] ?? $definition['included_users'] ?? null),
            'extra_user_price' => $this->nullableFloat($payload['extra_user_price'] ?? $definition['extra_user_price'] ?? null),
            'billing_period' => $this->billingPeriod($payload['billing_period'] ?? $definition['billing_period'] ?? 'monthly'),
            'metadata_json' => json_encode($this->mergeMetadata(
                is_array($existing) ? (array) ($existing['metadata'] ?? []) : [],
                is_array($definition['metadata'] ?? null) ? (array) $definition['metadata'] : [],
                is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
                ['assigned_by' => $actorUserId, 'assigned_at' => date('Y-m-d H:i:s')]
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_at' => is_array($existing) ? ($existing['created_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s'),
        ]);

        $result = $this->decorateTenantPlan($saved, $projectId);
        $this->auditLogger->log('tenant_plan.assign', 'tenant_plan', $result['id'] ?? null, [
            'tenant_id' => $tenantId,
            'plan_key' => $planKey,
            'actor_user_id' => $actorUserId,
            'result_status' => 'success',
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrentTenantPlan(string $tenantId, ?string $projectId = null): array
    {
        $tenantPlan = $this->repository->findTenantPlanByTenant($this->requireString($tenantId, 'tenant_id'));
        if (!is_array($tenantPlan)) {
            throw new RuntimeException('TENANT_PLAN_NOT_FOUND');
        }

        return $this->decorateTenantPlan($tenantPlan, $projectId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAvailablePlans(): array
    {
        $items = [];
        foreach ($this->defaultPlanCatalog() as $planKey => $definition) {
            $effectiveLimits = $this->effectiveLimitsMap($planKey);
            $enabledModules = $this->enabledModulesFromLimits(
                $effectiveLimits,
                is_array($definition['metadata']['enabled_modules'] ?? null) ? (array) $definition['metadata']['enabled_modules'] : []
            );

            $items[] = [
                'plan_key' => $planKey,
                'status' => (string) ($definition['status'] ?? 'active'),
                'base_price' => $this->nullableFloat($definition['base_price'] ?? null),
                'currency' => $this->normalizeCurrency($definition['currency'] ?? null),
                'included_users' => $this->nullableInt($definition['included_users'] ?? null),
                'extra_user_price' => $this->nullableFloat($definition['extra_user_price'] ?? null),
                'billing_period' => (string) ($definition['billing_period'] ?? 'monthly'),
                'metadata' => $this->mergeMetadata((array) ($definition['metadata'] ?? []), ['enabled_modules' => $enabledModules]),
                'enabled_modules' => $enabledModules,
                'module_flags' => $this->moduleFlags($enabledModules),
                'limits' => array_values($effectiveLimits),
                'pricing_metadata' => [
                    'base_price' => $this->nullableFloat($definition['base_price'] ?? null),
                    'currency' => $this->normalizeCurrency($definition['currency'] ?? null),
                    'included_users' => $this->nullableInt($definition['included_users'] ?? null),
                    'extra_user_price' => $this->nullableFloat($definition['extra_user_price'] ?? null),
                    'billing_period' => (string) ($definition['billing_period'] ?? 'monthly'),
                ],
                'result_status' => 'success',
            ];
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $limits
     * @return array<string, mixed>
     */
    public function setPlanLimits(string $planKey, array $limits, ?string $actorUserId = null): array
    {
        $planKey = $this->planKey($planKey);
        if ($limits === []) {
            throw new RuntimeException('PLAN_LIMITS_REQUIRED');
        }

        $saved = [];
        foreach ($limits as $limit) {
            if (!is_array($limit)) {
                continue;
            }
            $normalized = $this->normalizeLimitInput($planKey, $limit);
            $saved[] = $this->repository->upsertPlanLimit([
                'plan_key' => $planKey,
                'limit_key' => $normalized['limit_key'],
                'limit_value' => $this->stringifyLimitValue($normalized['limit_value'], $normalized['metadata']),
                'limit_type' => $normalized['limit_type'],
                'metadata_json' => json_encode($normalized['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ]);
        }

        $result = ['plan_key' => $planKey, 'limits' => array_values($this->effectiveLimitsMap($planKey)), 'saved_limits' => $saved, 'result_status' => 'success'];
        $this->auditLogger->log('tenant_plan.set_limits', 'plan_limit', $planKey, [
            'tenant_id' => 'global',
            'plan_key' => $planKey,
            'actor_user_id' => $actorUserId,
            'limit_count' => count($saved),
            'result_status' => 'success',
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function checkTenantPlanLimit(string $tenantId, string $limitKey, mixed $usageValue = null, ?string $projectId = null): array
    {
        $tenantPlan = $this->requireTenantPlan($tenantId);
        $limitKey = strtolower(trim($this->requireString($limitKey, 'limit_key')));
        $limits = $this->effectiveLimitsMap((string) ($tenantPlan['plan_key'] ?? 'starter'));
        $limit = $limits[$limitKey] ?? null;

        if (!is_array($limit)) {
            return [
                'tenant_id' => $tenantId,
                'plan_key' => (string) ($tenantPlan['plan_key'] ?? ''),
                'limit_key' => $limitKey,
                'limit_value' => null,
                'limit_type' => 'unknown',
                'usage_value' => $usageValue,
                'within_limit' => true,
                'exceeded_by' => 0,
                'enforcement_hint' => 'limit_not_defined',
                'result_status' => 'limit_not_defined',
            ];
        }

        $limitValue = $limit['limit_value'] ?? null;
        $usage = $usageValue !== null && $usageValue !== '' ? $this->normalizeUsageValue($usageValue) : $this->resolveUsageValue($tenantPlan, $limitKey, $projectId);
        $withinLimit = true;
        $exceededBy = 0;
        $enforcementHint = 'within_limit';

        if ($limit['limit_type'] === 'feature') {
            $enabled = $limitValue === true;
            $withinLimit = $enabled;
            $enforcementHint = $enabled ? 'feature_enabled' : 'feature_disabled';
        } elseif ($this->isUnlimitedLimit($limitValue, is_array($limit['metadata'] ?? null) ? (array) $limit['metadata'] : [])) {
            $enforcementHint = 'unbounded_limit';
        } elseif (is_numeric($limitValue) && is_numeric($usage)) {
            $withinLimit = (float) $usage <= (float) $limitValue;
            $exceededBy = $withinLimit ? 0 : (int) max(0, ceil((float) $usage - (float) $limitValue));
            if (!$withinLimit) {
                $enforcementHint = ((string) ($limit['limit_type'] ?? 'hard')) === 'soft' ? 'soft_warning' : 'hard_limit_reached';
            }
        } elseif ($usage === null) {
            $enforcementHint = 'usage_not_provided';
        }

        return [
            'tenant_id' => $tenantId,
            'plan_key' => (string) ($tenantPlan['plan_key'] ?? ''),
            'limit_key' => $limitKey,
            'limit_value' => $limitValue,
            'limit_type' => (string) ($limit['limit_type'] ?? 'hard'),
            'usage_value' => $usage,
            'within_limit' => $withinLimit,
            'exceeded_by' => $exceededBy,
            'enforcement_hint' => $enforcementHint,
            'result_status' => $withinLimit ? 'within_limit' : 'limit_reached',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateExtraUserPricingMetadata(string $tenantId, ?string $projectId = null): array
    {
        return $this->extraUserPricingFromPlan($this->requireTenantPlan($tenantId), $projectId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEnabledModules(string $tenantId, ?string $projectId = null): array
    {
        $tenantPlan = $this->decorateTenantPlan($this->requireTenantPlan($tenantId), $projectId);
        $enabledModules = is_array($tenantPlan['enabled_modules'] ?? null) ? (array) $tenantPlan['enabled_modules'] : [];

        return [
            'tenant_id' => $tenantId,
            'plan_key' => (string) ($tenantPlan['plan_key'] ?? ''),
            'enabled_modules' => $enabledModules,
            'module_flags' => is_array($tenantPlan['module_flags'] ?? null) ? (array) $tenantPlan['module_flags'] : $this->moduleFlags($enabledModules),
            'active_modules_limit' => $this->limitValueFromList((array) ($tenantPlan['limits'] ?? []), 'active_modules'),
            'active_modules_used' => count($enabledModules),
            'result_status' => 'success',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultPlanCatalog(): array
    {
        return [
            'starter' => [
                'plan_key' => 'starter',
                'status' => 'active',
                'base_price' => 29.0,
                'currency' => 'USD',
                'included_users' => 1,
                'extra_user_price' => 9.0,
                'billing_period' => 'monthly',
                'metadata' => ['tier' => 'starter', 'enabled_modules' => ['pos', 'media', 'reports', 'users']],
                'limits' => [
                    $this->numericLimit('users', 1, 'hard'),
                    $this->numericLimit('stores', 1, 'hard'),
                    $this->numericLimit('pos_registers', 1, 'hard'),
                    $this->numericLimit('ecommerce_channels', 0, 'hard'),
                    $this->numericLimit('ai_requests_month', 1000, 'soft'),
                    $this->numericLimit('sync_jobs_month', 10, 'soft'),
                    $this->numericLimit('storage_mb', 1024, 'soft'),
                    $this->numericLimit('active_modules', 4, 'hard'),
                    $this->moduleLimit('pos', true),
                    $this->moduleLimit('media', true),
                    $this->moduleLimit('reports', true),
                    $this->moduleLimit('users', true),
                    $this->moduleLimit('purchases', false),
                    $this->moduleLimit('fiscal', false),
                    $this->moduleLimit('ecommerce', false),
                ],
            ],
            'growth' => [
                'plan_key' => 'growth',
                'status' => 'active',
                'base_price' => 79.0,
                'currency' => 'USD',
                'included_users' => 3,
                'extra_user_price' => 12.0,
                'billing_period' => 'monthly',
                'metadata' => ['tier' => 'growth', 'enabled_modules' => ['pos', 'purchases', 'media', 'reports', 'users', 'ecommerce']],
                'limits' => [
                    $this->numericLimit('users', 3, 'hard'),
                    $this->numericLimit('stores', 3, 'hard'),
                    $this->numericLimit('pos_registers', 3, 'hard'),
                    $this->numericLimit('ecommerce_channels', 2, 'hard'),
                    $this->numericLimit('ai_requests_month', 10000, 'soft'),
                    $this->numericLimit('sync_jobs_month', 200, 'soft'),
                    $this->numericLimit('storage_mb', 5120, 'soft'),
                    $this->numericLimit('active_modules', 6, 'hard'),
                    $this->moduleLimit('pos', true),
                    $this->moduleLimit('purchases', true),
                    $this->moduleLimit('media', true),
                    $this->moduleLimit('reports', true),
                    $this->moduleLimit('users', true),
                    $this->moduleLimit('ecommerce', true),
                    $this->moduleLimit('fiscal', false),
                ],
            ],
            'pro' => [
                'plan_key' => 'pro',
                'status' => 'active',
                'base_price' => 149.0,
                'currency' => 'USD',
                'included_users' => 10,
                'extra_user_price' => 15.0,
                'billing_period' => 'monthly',
                'metadata' => ['tier' => 'pro', 'enabled_modules' => ['pos', 'purchases', 'fiscal', 'ecommerce', 'media', 'reports', 'users']],
                'limits' => [
                    $this->numericLimit('users', 10, 'hard'),
                    $this->numericLimit('stores', 10, 'hard'),
                    $this->numericLimit('pos_registers', 10, 'hard'),
                    $this->numericLimit('ecommerce_channels', 5, 'hard'),
                    $this->numericLimit('ai_requests_month', 50000, 'soft'),
                    $this->numericLimit('sync_jobs_month', 1000, 'soft'),
                    $this->numericLimit('storage_mb', 20480, 'soft'),
                    $this->numericLimit('active_modules', 7, 'hard'),
                    $this->moduleLimit('pos', true),
                    $this->moduleLimit('purchases', true),
                    $this->moduleLimit('fiscal', true),
                    $this->moduleLimit('ecommerce', true),
                    $this->moduleLimit('media', true),
                    $this->moduleLimit('reports', true),
                    $this->moduleLimit('users', true),
                ],
            ],
            'custom' => [
                'plan_key' => 'custom',
                'status' => 'active',
                'base_price' => null,
                'currency' => 'USD',
                'included_users' => null,
                'extra_user_price' => null,
                'billing_period' => 'custom',
                'metadata' => ['tier' => 'custom', 'enabled_modules' => ['pos', 'purchases', 'fiscal', 'ecommerce', 'media', 'reports', 'users']],
                'limits' => [
                    $this->numericLimit('users', -1, 'hard', ['unbounded' => true]),
                    $this->numericLimit('stores', -1, 'hard', ['unbounded' => true]),
                    $this->numericLimit('pos_registers', -1, 'hard', ['unbounded' => true]),
                    $this->numericLimit('ecommerce_channels', -1, 'hard', ['unbounded' => true]),
                    $this->numericLimit('ai_requests_month', -1, 'soft', ['unbounded' => true]),
                    $this->numericLimit('sync_jobs_month', -1, 'soft', ['unbounded' => true]),
                    $this->numericLimit('storage_mb', -1, 'soft', ['unbounded' => true]),
                    $this->numericLimit('active_modules', -1, 'hard', ['unbounded' => true]),
                    $this->moduleLimit('pos', true),
                    $this->moduleLimit('purchases', true),
                    $this->moduleLimit('fiscal', true),
                    $this->moduleLimit('ecommerce', true),
                    $this->moduleLimit('media', true),
                    $this->moduleLimit('reports', true),
                    $this->moduleLimit('users', true),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $tenantPlan
     * @return array<string, mixed>
     */
    private function decorateTenantPlan(array $tenantPlan, ?string $projectId): array
    {
        $planKey = (string) ($tenantPlan['plan_key'] ?? 'starter');
        $definition = $this->defaultPlanCatalog()[$planKey] ?? $this->defaultPlanCatalog()['starter'];
        $effectiveLimits = $this->effectiveLimitsMap($planKey);
        $metadata = $this->mergeMetadata((array) ($definition['metadata'] ?? []), (array) ($tenantPlan['metadata'] ?? []));
        $enabledModules = $this->enabledModulesFromLimits($effectiveLimits, is_array($metadata['enabled_modules'] ?? null) ? (array) $metadata['enabled_modules'] : []);
        $metadata['enabled_modules'] = $enabledModules;

        return [
            'id' => (string) ($tenantPlan['id'] ?? ''),
            'tenant_id' => (string) ($tenantPlan['tenant_id'] ?? ''),
            'plan_key' => $planKey,
            'status' => (string) ($tenantPlan['status'] ?? 'active'),
            'base_price' => $this->nullableFloat($tenantPlan['base_price'] ?? $definition['base_price'] ?? null),
            'currency' => $this->normalizeCurrency($tenantPlan['currency'] ?? $definition['currency'] ?? null),
            'included_users' => $this->nullableInt($tenantPlan['included_users'] ?? $definition['included_users'] ?? null),
            'extra_user_price' => $this->nullableFloat($tenantPlan['extra_user_price'] ?? $definition['extra_user_price'] ?? null),
            'billing_period' => $this->billingPeriod($tenantPlan['billing_period'] ?? $definition['billing_period'] ?? 'monthly'),
            'metadata' => $metadata,
            'enabled_modules' => $enabledModules,
            'module_flags' => $this->moduleFlags($enabledModules),
            'limits' => array_values($effectiveLimits),
            'extra_user_pricing' => $this->extraUserPricingFromPlan($tenantPlan + $definition, $projectId),
            'created_at' => (string) ($tenantPlan['created_at'] ?? ''),
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $tenantPlan
     * @return array<string, mixed>
     */
    private function extraUserPricingFromPlan(array $tenantPlan, ?string $projectId): array
    {
        $includedUsers = $this->nullableInt($tenantPlan['included_users'] ?? null);
        $extraUserPrice = $this->nullableFloat($tenantPlan['extra_user_price'] ?? null);
        $activeUsers = $this->activeTenantUserCount((string) ($tenantPlan['tenant_id'] ?? ''), $projectId);

        if ($includedUsers === null || $includedUsers < 0) {
            return [
                'included_users' => $includedUsers,
                'active_users' => $activeUsers,
                'extra_users' => 0,
                'extra_user_price' => $extraUserPrice,
                'extra_monthly_price' => 0.0,
                'currency' => $this->normalizeCurrency($tenantPlan['currency'] ?? null),
                'billing_period' => $this->billingPeriod($tenantPlan['billing_period'] ?? 'monthly'),
                'result_status' => 'unbounded_users',
            ];
        }

        $extraUsers = max(0, $activeUsers - $includedUsers);

        return [
            'included_users' => $includedUsers,
            'active_users' => $activeUsers,
            'extra_users' => $extraUsers,
            'extra_user_price' => $extraUserPrice,
            'extra_monthly_price' => $extraUserPrice !== null ? (float) ($extraUsers * $extraUserPrice) : null,
            'currency' => $this->normalizeCurrency($tenantPlan['currency'] ?? null),
            'billing_period' => $this->billingPeriod($tenantPlan['billing_period'] ?? 'monthly'),
            'result_status' => $extraUsers > 0 ? 'extra_users_detected' : 'within_included_users',
        ];
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
            // Fall back to auth user metadata when tenant memberships are not yet materialized.
        }

        if ($projectId === null || $projectId === '') {
            return 0;
        }

        $count = 0;
        foreach ($this->projectRegistry->listAuthUsers($projectId) as $user) {
            if (is_array($user) && trim((string) ($user['tenant_id'] ?? '')) === $tenantId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function effectiveLimitsMap(string $planKey): array
    {
        $planKey = $this->planKey($planKey);
        $limits = [];
        foreach ((array) ($this->defaultPlanCatalog()[$planKey]['limits'] ?? []) as $limit) {
            if (!is_array($limit)) {
                continue;
            }
            $limitKey = strtolower(trim((string) ($limit['limit_key'] ?? '')));
            if ($limitKey !== '') {
                $limits[$limitKey] = $limit;
            }
        }

        foreach ($this->repository->listPlanLimits($planKey) as $override) {
            $limitKey = strtolower(trim((string) ($override['limit_key'] ?? '')));
            if ($limitKey === '') {
                continue;
            }
            $limits[$limitKey] = [
                'plan_key' => $planKey,
                'limit_key' => $limitKey,
                'limit_value' => $override['limit_value'] ?? null,
                'limit_type' => (string) ($override['limit_type'] ?? ($limits[$limitKey]['limit_type'] ?? 'hard')),
                'metadata' => $this->mergeMetadata((array) ($limits[$limitKey]['metadata'] ?? []), (array) ($override['metadata'] ?? [])),
            ];
        }

        ksort($limits);
        return $limits;
    }

    /**
     * @param array<string, mixed> $tenantPlan
     * @return bool|float|int|string|null
     */
    private function resolveUsageValue(array $tenantPlan, string $limitKey, ?string $projectId)
    {
        return match ($limitKey) {
            'users' => $this->activeTenantUserCount((string) ($tenantPlan['tenant_id'] ?? ''), $projectId),
            'active_modules' => count($this->getEnabledModules((string) ($tenantPlan['tenant_id'] ?? ''), $projectId)['enabled_modules'] ?? []),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function isUnlimitedLimit(mixed $limitValue, array $metadata): bool
    {
        return (($metadata['unbounded'] ?? false) === true) || (is_numeric($limitValue) && (float) $limitValue < 0);
    }

    /**
     * @param array<int, array<string, mixed>> $limits
     * @return bool|float|int|string|null
     */
    private function limitValueFromList(array $limits, string $limitKey)
    {
        foreach ($limits as $limit) {
            if (is_array($limit) && (string) ($limit['limit_key'] ?? '') === $limitKey) {
                return $limit['limit_value'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $limit
     * @return array<string, mixed>
     */
    private function normalizeLimitInput(string $planKey, array $limit): array
    {
        $limitKey = strtolower(trim($this->requireString($limit['limit_key'] ?? null, 'limit_key')));
        $limitType = strtolower(trim((string) ($limit['limit_type'] ?? '')));
        if ($limitType === '') {
            $limitType = str_starts_with($limitKey, 'module:') ? 'feature' : 'hard';
        }
        if (!in_array($limitType, self::LIMIT_TYPES, true)) {
            throw new RuntimeException('PLAN_LIMIT_TYPE_INVALID');
        }
        if (!array_key_exists('limit_value', $limit)) {
            throw new RuntimeException('PLAN_LIMIT_VALUE_REQUIRED');
        }

        $value = $limit['limit_value'];
        $metadata = is_array($limit['metadata'] ?? null) ? (array) $limit['metadata'] : [];
        if ($limitType === 'feature') {
            $moduleKey = trim((string) ($metadata['module_key'] ?? ''));
            if ($moduleKey === '' && str_starts_with($limitKey, 'module:')) {
                $moduleKey = substr($limitKey, 7);
            }
            $metadata = $this->mergeMetadata($metadata, ['value_kind' => 'bool', 'module_key' => $moduleKey !== '' ? $moduleKey : null]);
            $value = $this->boolValue($value);
        } elseif (is_numeric($value)) {
            $metadata = $this->mergeMetadata($metadata, ['value_kind' => str_contains((string) $value, '.') ? 'float' : 'int', 'unbounded' => (float) $value < 0 ? true : null]);
            $value = str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return ['plan_key' => $planKey, 'limit_key' => $limitKey, 'limit_value' => $value, 'limit_type' => $limitType, 'metadata' => $metadata];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function stringifyLimitValue(mixed $value, array $metadata): string
    {
        $valueKind = strtolower(trim((string) ($metadata['value_kind'] ?? '')));
        if ($valueKind === 'bool') {
            return $this->boolValue($value) ? '1' : '0';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $limits
     * @param array<int, string> $metadataModules
     * @return array<int, string>
     */
    private function enabledModulesFromLimits(array $limits, array $metadataModules): array
    {
        $enabled = [];
        foreach ($metadataModules as $moduleKey) {
            $moduleKey = strtolower(trim((string) $moduleKey));
            if ($moduleKey !== '' && !in_array($moduleKey, $enabled, true)) {
                $enabled[] = $moduleKey;
            }
        }
        foreach ($limits as $limitKey => $limit) {
            if (!is_array($limit) || !str_starts_with((string) $limitKey, 'module:')) {
                continue;
            }
            $moduleKey = strtolower(trim((string) (($limit['metadata']['module_key'] ?? '') ?: substr((string) $limitKey, 7))));
            if ($moduleKey === '') {
                continue;
            }
            if (($limit['limit_value'] ?? false) === true) {
                if (!in_array($moduleKey, $enabled, true)) {
                    $enabled[] = $moduleKey;
                }
            } else {
                $enabled = array_values(array_filter($enabled, static fn(string $value): bool => $value !== $moduleKey));
            }
        }
        sort($enabled);
        return $enabled;
    }

    /**
     * @param array<int, string> $enabledModules
     * @return array<string, bool>
     */
    private function moduleFlags(array $enabledModules): array
    {
        $flags = [];
        foreach ($enabledModules as $moduleKey) {
            $moduleKey = strtolower(trim((string) $moduleKey));
            if ($moduleKey !== '') {
                $flags[$moduleKey] = true;
            }
        }
        ksort($flags);
        return $flags;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireTenantPlan(string $tenantId): array
    {
        $tenantPlan = $this->repository->findTenantPlanByTenant($this->requireString($tenantId, 'tenant_id'));
        if (!is_array($tenantPlan)) {
            throw new RuntimeException('TENANT_PLAN_NOT_FOUND');
        }
        return $tenantPlan;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function numericLimit(string $limitKey, int|float $limitValue, string $limitType, array $extra = []): array
    {
        return ['limit_key' => $limitKey, 'limit_value' => $limitValue, 'limit_type' => $limitType, 'metadata' => $this->mergeMetadata(['value_kind' => is_float($limitValue) ? 'float' : 'int'], $extra)];
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleLimit(string $moduleKey, bool $enabled): array
    {
        $moduleKey = strtolower(trim($moduleKey));
        return ['limit_key' => 'module:' . $moduleKey, 'limit_value' => $enabled, 'limit_type' => 'feature', 'metadata' => ['value_kind' => 'bool', 'module_key' => $moduleKey]];
    }

    private function requireString(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        }
        return $value;
    }

    private function planKey(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!array_key_exists($value, $this->defaultPlanCatalog())) {
            throw new RuntimeException('TENANT_PLAN_KEY_INVALID');
        }
        return $value;
    }

    private function planStatus(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::PLAN_STATUSES, true)) {
            throw new RuntimeException('TENANT_PLAN_STATUS_INVALID');
        }
        return $value;
    }

    private function billingPeriod(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::BILLING_PERIODS, true)) {
            throw new RuntimeException('TENANT_PLAN_BILLING_PERIOD_INVALID');
        }
        return $value;
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        return $value !== '' ? $value : null;
    }

    private function normalizeUsageValue(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }
        return $raw;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'si', 'on'], true);
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
