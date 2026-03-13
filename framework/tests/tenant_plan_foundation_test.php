<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditLogger;
use App\Core\Database;
use App\Core\ProjectRegistry;
use App\Core\TenantAccessControlRepository;
use App\Core\TenantAccessControlService;
use App\Core\TenantPlanRepository;
use App\Core\TenantPlanService;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/tenant_plan_foundation_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/tenant_plan.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/tenant_plan.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha_plan';
$tenantBeta = 'tenant_beta_plan';
$appId = 'tenant_plan_app';

$registry = new ProjectRegistry();
$registry->ensureProject($appId, 'Tenant Plan App', 'active', 'shared', 'owner_alpha', 'legacy');

foreach ([
    ['id' => 'owner_alpha', 'label' => 'Owner Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'operator_alpha', 'label' => 'Operator Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'owner_beta', 'label' => 'Owner Beta', 'role' => 'admin', 'tenant' => $tenantBeta],
    ['id' => 'operator_beta', 'label' => 'Operator Beta', 'role' => 'admin', 'tenant' => $tenantBeta],
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
    'user_id' => 'owner_alpha',
    'role_key' => 'owner',
    'actor_user_id' => 'system',
]);
$accessControl->attachUserToTenant([
    'tenant_id' => $tenantAlpha,
    'project_id' => $appId,
    'user_id' => 'operator_alpha',
    'role_key' => 'operator',
    'actor_user_id' => 'owner_alpha',
]);

$service = new TenantPlanService(
    new TenantPlanRepository($pdo),
    new AuditLogger($pdo),
    $accessControl,
    $registry
);

try {
    $alphaPlan = $service->assignPlanToTenant([
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'plan_key' => 'starter',
        'actor_user_id' => 'owner_alpha',
    ]);
    if ((string) ($alphaPlan['plan_key'] ?? '') !== 'starter') {
        $failures[] = 'assignPlanToTenant debe guardar el plan solicitado para el tenant.';
    }
    $alphaPricing = is_array($alphaPlan['extra_user_pricing'] ?? null) ? (array) $alphaPlan['extra_user_pricing'] : [];
    if ((int) ($alphaPricing['active_users'] ?? 0) !== 2 || (int) ($alphaPricing['extra_users'] ?? 0) !== 1) {
        $failures[] = 'assignPlanToTenant debe calcular included users vs extra users con memberships activas.';
    }
    if ((float) ($alphaPricing['extra_monthly_price'] ?? 0.0) !== 9.0) {
        $failures[] = 'assignPlanToTenant debe exponer extra_user_price multiplicado por los usuarios extra.';
    }

    $currentAlpha = $service->getCurrentTenantPlan($tenantAlpha, $appId);
    if ((int) ($currentAlpha['included_users'] ?? 0) !== 1) {
        $failures[] = 'getCurrentTenantPlan debe devolver included_users del plan asignado.';
    }
    if (!in_array('pos', (array) ($currentAlpha['enabled_modules'] ?? []), true)) {
        $failures[] = 'getCurrentTenantPlan debe resolver modulos habilitados del plan.';
    }

    $availablePlans = $service->listAvailablePlans();
    if (count($availablePlans) < 4) {
        $failures[] = 'listAvailablePlans debe devolver el catalogo base de planes.';
    }
    $availableKeys = array_map(static fn(array $item): string => (string) ($item['plan_key'] ?? ''), $availablePlans);
    foreach (['starter', 'growth', 'pro', 'custom'] as $requiredPlan) {
        if (!in_array($requiredPlan, $availableKeys, true)) {
            $failures[] = 'listAvailablePlans debe incluir el plan ' . $requiredPlan . '.';
        }
    }

    $limitUpdate = $service->setPlanLimits('starter', [
        [
            'limit_key' => 'users',
            'limit_value' => 5,
            'limit_type' => 'hard',
        ],
        [
            'limit_key' => 'module:ecommerce',
            'limit_value' => true,
            'limit_type' => 'feature',
        ],
    ], 'owner_alpha');
    if ((string) ($limitUpdate['plan_key'] ?? '') !== 'starter') {
        $failures[] = 'setPlanLimits debe devolver el plan afectado.';
    }

    $alphaWithOverrides = $service->getCurrentTenantPlan($tenantAlpha, $appId);
    if (!in_array('ecommerce', (array) ($alphaWithOverrides['enabled_modules'] ?? []), true)) {
        $failures[] = 'setPlanLimits debe permitir habilitar modulos por feature flags.';
    }

    $limitCheck = $service->checkTenantPlanLimit($tenantAlpha, 'users', null, $appId);
    if (($limitCheck['within_limit'] ?? false) !== true || (int) ($limitCheck['limit_value'] ?? 0) !== 5) {
        $failures[] = 'checkTenantPlanLimit debe resolver el override efectivo del limite.';
    }
    if ((int) ($limitCheck['usage_value'] ?? 0) !== 2) {
        $failures[] = 'checkTenantPlanLimit debe calcular usage_value desde TenantAccessControlService.';
    }

    $alphaPricingAfterOverrides = $service->calculateExtraUserPricingMetadata($tenantAlpha, $appId);
    if ((int) ($alphaPricingAfterOverrides['included_users'] ?? 0) !== 1 || (int) ($alphaPricingAfterOverrides['extra_users'] ?? 0) !== 1) {
        $failures[] = 'calculateExtraUserPricingMetadata debe mantener included vs extra users del plan.';
    }

    $alphaModules = $service->getEnabledModules($tenantAlpha, $appId);
    if (($alphaModules['module_flags']['ecommerce'] ?? false) !== true) {
        $failures[] = 'getEnabledModules debe devolver module_flags habilitados.';
    }

    $betaPlan = $service->assignPlanToTenant([
        'tenant_id' => $tenantBeta,
        'project_id' => $appId,
        'plan_key' => 'pro',
        'actor_user_id' => 'owner_beta',
    ]);
    $betaPricing = is_array($betaPlan['extra_user_pricing'] ?? null) ? (array) $betaPlan['extra_user_pricing'] : [];
    if ((int) ($betaPricing['active_users'] ?? 0) !== 2) {
        $failures[] = 'El plan debe poder contar usuarios auth del tenant cuando no hay memberships materializadas.';
    }
    if ((string) ($betaPlan['plan_key'] ?? '') !== 'pro' || (string) ($currentAlpha['plan_key'] ?? '') !== 'starter') {
        $failures[] = 'Los planes deben permanecer aislados por tenant.';
    }
    if (!in_array('fiscal', (array) ($betaPlan['enabled_modules'] ?? []), true)) {
        $failures[] = 'El tenant beta debe resolver modulos del plan pro sin filtrar desde alpha.';
    }
} catch (Throwable $e) {
    $failures[] = 'La base de SaaS plans debe pasar: ' . $e->getMessage();
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
