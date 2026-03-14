<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditLogger;
use App\Core\Database;
use App\Core\EcommerceHubRepository;
use App\Core\ProjectRegistry;
use App\Core\TenantAccessControlRepository;
use App\Core\TenantAccessControlService;
use App\Core\TenantPlanRepository;
use App\Core\TenantPlanService;
use App\Core\UsageMeteringRepository;
use App\Core\UsageMeteringService;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/usage_metering_foundation_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'PROJECT_REGISTRY_DB_PATH' => getenv('PROJECT_REGISTRY_DB_PATH'),
    'DB_DRIVER' => getenv('DB_DRIVER'),
    'DB_PATH' => getenv('DB_PATH'),
    'DB_NAMESPACE_BY_PROJECT' => getenv('DB_NAMESPACE_BY_PROJECT'),
    'PROJECT_STORAGE_MODEL' => getenv('PROJECT_STORAGE_MODEL'),
    'DB_STORAGE_MODEL' => getenv('DB_STORAGE_MODEL'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('PROJECT_REGISTRY_DB_PATH=' . $tmpDir . '/project_registry.sqlite');
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $tmpDir . '/usage_metering.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/usage_metering.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha_usage';
$tenantBeta = 'tenant_beta_usage';
$appId = 'usage_metering_app';

$registry = new ProjectRegistry();
$registry->ensureProject($appId, 'Usage Metering App', 'active', 'shared', 'admin_alpha', 'legacy');

foreach ([
    ['id' => 'admin_alpha', 'label' => 'Admin Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'operator_alpha', 'label' => 'Operator Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'admin_beta', 'label' => 'Admin Beta', 'role' => 'admin', 'tenant' => $tenantBeta],
] as $user) {
    $registry->createAuthUser($appId, $user['id'], 'secret', $user['role'], $user['tenant'], $user['label']);
    $registry->touchUser($user['id'], $user['role'], 'auth', $user['tenant'], $user['label']);
    $registry->assignUserToProject($appId, $user['id'], $user['role']);
}

$accessControl = new TenantAccessControlService(
    new TenantAccessControlRepository($pdo),
    new AuditLogger($pdo),
    $registry
);
$accessControl->attachUserToTenant([
    'tenant_id' => $tenantAlpha,
    'project_id' => $appId,
    'user_id' => 'admin_alpha',
    'role_key' => 'admin',
    'actor_user_id' => 'system',
]);
$accessControl->attachUserToTenant([
    'tenant_id' => $tenantAlpha,
    'project_id' => $appId,
    'user_id' => 'operator_alpha',
    'role_key' => 'operator',
    'actor_user_id' => 'admin_alpha',
]);
$accessControl->attachUserToTenant([
    'tenant_id' => $tenantBeta,
    'project_id' => $appId,
    'user_id' => 'admin_beta',
    'role_key' => 'admin',
    'actor_user_id' => 'system',
]);

$planService = new TenantPlanService(
    new TenantPlanRepository($pdo),
    new AuditLogger($pdo),
    $accessControl,
    $registry
);
$planService->assignPlanToTenant([
    'tenant_id' => $tenantAlpha,
    'project_id' => $appId,
    'plan_key' => 'starter',
    'actor_user_id' => 'admin_alpha',
]);
$planService->setPlanLimits('starter', [[
    'limit_key' => 'storage_mb',
    'limit_value' => 1,
    'limit_type' => 'soft',
]], 'admin_alpha');

$ecommerceRepository = new EcommerceHubRepository($pdo);
$ecommerceRepository->createStore([
    'tenant_id' => $tenantAlpha,
    'app_id' => $appId,
    'platform' => 'woocommerce',
    'store_name' => 'Alpha Shop',
    'store_url' => 'https://alpha-shop.test',
    'status' => 'active',
    'connection_status' => 'valid',
    'metadata' => [],
]);

$service = new UsageMeteringService(
    new UsageMeteringRepository($pdo),
    new AuditLogger($pdo),
    $planService,
    $accessControl,
    $registry,
    $ecommerceRepository
);

try {
    $uploadEvent = $service->recordUsageEvent([
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'metric_key' => 'documents_uploaded',
        'delta_value' => 1,
        'source_module' => 'media',
        'source_action' => 'upload',
        'source_ref' => 'file_1',
    ]);
    if ((string) ($uploadEvent['metric_key'] ?? '') !== 'documents_uploaded' || (int) ($uploadEvent['usage_value'] ?? 0) !== 1) {
        $failures[] = 'recordUsageEvent debe registrar el evento y agregar el meter de documentos.';
    }

    $storageNear = $service->recordUsageEvent([
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'metric_key' => 'storage_mb',
        'delta_value' => 0.85,
        'unit' => 'mb',
        'source_module' => 'media',
        'source_action' => 'upload',
        'source_ref' => 'file_storage_1',
    ]);
    if (($storageNear['near_limit'] ?? false) !== true || ($storageNear['over_limit'] ?? false) === true) {
        $failures[] = 'recordUsageEvent debe marcar near_limit cuando el consumo se acerca al limite.';
    }

    $storageOver = $service->recordUsageEvent([
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'metric_key' => 'storage_mb',
        'delta_value' => 0.30,
        'unit' => 'mb',
        'source_module' => 'media',
        'source_action' => 'upload',
        'source_ref' => 'file_storage_2',
    ]);
    if (($storageOver['over_limit'] ?? false) !== true || (float) ($storageOver['usage_value'] ?? 0) <= 1.0) {
        $failures[] = 'recordUsageEvent debe marcar over_limit cuando el consumo supera el limite.';
    }

    $summary = $service->getTenantUsageSummary($tenantAlpha, [
        'metric_keys' => ['users', 'storage_mb', 'ecommerce_channels'],
    ], $appId);
    $items = [];
    foreach ((array) ($summary['items'] ?? []) as $item) {
        if (is_array($item) && trim((string) ($item['metric_key'] ?? '')) !== '') {
            $items[(string) $item['metric_key']] = $item;
        }
    }
    if ((int) (($items['users']['usage_value'] ?? 0)) !== 2 || (($items['users']['over_limit'] ?? false) !== true)) {
        $failures[] = 'getTenantUsageSummary debe comparar usuarios activos contra el plan actual.';
    }
    if ((int) (($items['ecommerce_channels']['usage_value'] ?? 0)) !== 1) {
        $failures[] = 'getTenantUsageSummary debe resolver ecommerce_channels desde las tiendas del tenant.';
    }

    $limitCheck = $service->checkUsageLimit($tenantAlpha, 'users', $appId);
    if (($limitCheck['over_limit'] ?? false) !== true || (int) ($limitCheck['limit_value'] ?? 0) !== 1) {
        $failures[] = 'checkUsageLimit debe comparar usage vs limite del plan.';
    }

    $history = $service->getMetricUsageHistory($tenantAlpha, 'storage_mb', ['limit' => 10], $appId);
    if ((int) ($history['result_count'] ?? 0) !== 2) {
        $failures[] = 'getMetricUsageHistory debe devolver el historial de eventos del metric_key.';
    }

    $betaSummary = $service->getTenantUsageSummary($tenantBeta, ['metric_key' => 'storage_mb'], $appId);
    $betaItem = (array) (($betaSummary['items'][0] ?? []) ?: []);
    if ((float) ($betaItem['usage_value'] ?? 0) !== 0.0) {
        $failures[] = 'La usage summary debe mantenerse aislada por tenant.';
    }

    $metrics = $service->listMetrics();
    $metricKeys = array_map(static fn(array $item): string => (string) ($item['metric_key'] ?? ''), $metrics);
    foreach (['users', 'storage_mb', 'documents_uploaded', 'sales_created'] as $requiredMetric) {
        if (!in_array($requiredMetric, $metricKeys, true)) {
            $failures[] = 'listMetrics debe incluir la metrica ' . $requiredMetric . '.';
        }
    }

    try {
        $service->recordUsageEvent([
            'tenant_id' => $tenantAlpha,
            'project_id' => $appId,
            'metric_key' => 'invalid_metric',
            'delta_value' => 1,
            'source_module' => 'tests',
        ]);
        $failures[] = 'recordUsageEvent debe bloquear metric_key invalido.';
    } catch (RuntimeException $e) {
        if ((string) $e->getMessage() !== 'USAGE_METRIC_KEY_INVALID') {
            $failures[] = 'recordUsageEvent debe fallar con USAGE_METRIC_KEY_INVALID para metric_key invalido.';
        }
    }
} catch (Throwable $e) {
    $failures[] = 'La base de usage metering debe pasar: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @param string|false $value
 */
function restoreEnvValue(string $key, $value): void
{
    if ($value === false) {
        putenv($key);
        return;
    }

    putenv($key . '=' . $value);
}
