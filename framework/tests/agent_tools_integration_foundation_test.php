<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AgentToolsIntegrationService;
use App\Core\AuditLogger;
use App\Core\ContractRegistry;
use App\Core\Database;
use App\Core\ProjectRegistry;
use App\Core\SkillResolver;
use App\Core\TenantAccessControlRepository;
use App\Core\TenantAccessControlService;
use App\Core\TenantPlanRepository;
use App\Core\TenantPlanService;
$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/agent_tools_integration_foundation_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/agent_tools_integration.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/agent_tools_integration.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha_agent_tools';
$tenantBeta = 'tenant_beta_agent_tools';
$appId = 'agent_tools_app';

$registry = new ProjectRegistry();
$registry->ensureProject($appId, 'Agent Tools App', 'active', 'shared', 'admin_alpha', 'legacy');

foreach ([
    ['id' => 'admin_alpha', 'label' => 'Admin Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'viewer_alpha', 'label' => 'Viewer Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
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
    'user_id' => 'viewer_alpha',
    'role_key' => 'viewer',
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
$planService->assignPlanToTenant([
    'tenant_id' => $tenantBeta,
    'project_id' => $appId,
    'plan_key' => 'pro',
    'actor_user_id' => 'admin_beta',
]);

$service = new AgentToolsIntegrationService(
    new ContractRegistry(),
    $planService,
    $accessControl,
    $registry,
    new SkillResolver()
);

try {
    $alphaGroups = $service->listToolGroups($tenantAlpha, 'admin_alpha', $appId);
    $alphaItems = indexByModuleKey((array) ($alphaGroups['tool_groups'] ?? []));
    if ((int) ($alphaGroups['result_count'] ?? 0) !== 9) {
        $failures[] = 'listToolGroups debe devolver los 9 grupos canonicos.';
    }
    if (($alphaItems['ecommerce']['enabled'] ?? true) !== false) {
        $failures[] = 'El tenant starter debe ver ecommerce como disabled por plan.';
    }
    if (($alphaItems['usage_metering']['enabled'] ?? false) !== true) {
        $failures[] = 'Usage metering debe aparecer como modulo core habilitado.';
    }

    $betaGroups = $service->listToolGroups($tenantBeta, 'admin_beta', $appId);
    $betaItems = indexByModuleKey((array) ($betaGroups['tool_groups'] ?? []));
    if (($betaItems['ecommerce']['enabled'] ?? false) !== true) {
        $failures[] = 'El tenant pro debe resolver ecommerce como enabled.';
    }

    $disabledCapabilities = $service->getModuleCapabilities($tenantAlpha, 'admin_alpha', 'ecommerce', $appId);
    if (($disabledCapabilities['enabled'] ?? true) !== false || (int) ($disabledCapabilities['action_count'] ?? -1) !== 0) {
        $failures[] = 'getModuleCapabilities debe ocultar acciones de modulos disabled.';
    }
    if ((string) ($disabledCapabilities['denial_reason'] ?? '') !== 'module_disabled_by_plan') {
        $failures[] = 'El disabled state debe indicar module_disabled_by_plan.';
    }

    $enabledCapabilities = $service->getModuleCapabilities($tenantBeta, 'admin_beta', 'ecommerce', $appId);
    if (($enabledCapabilities['enabled'] ?? false) !== true || ($enabledCapabilities['allowed'] ?? false) !== true) {
        $failures[] = 'El tenant pro admin debe poder ver capacidades ecommerce.';
    }
    $enabledActions = array_map(
        static fn(array $item): string => (string) ($item['action_key'] ?? ''),
        (array) ($enabledCapabilities['actions'] ?? [])
    );
    if (!in_array('list_stores', $enabledActions, true)) {
        $failures[] = 'Las capacidades ecommerce deben exponer acciones registradas del catalogo.';
    }
    $capabilitiesJson = json_encode($enabledCapabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if (str_contains($capabilitiesJson, 'secret') || str_contains($capabilitiesJson, 'token')) {
        $failures[] = 'La capa de capacidades no debe exponer secretos ni tokens.';
    }

    $moduleCheck = $service->checkModuleEnabled($tenantAlpha, 'ecommerce', $appId);
    if (($moduleCheck['enabled'] ?? true) !== false || (string) ($moduleCheck['result_status'] ?? '') !== 'disabled') {
        $failures[] = 'checkModuleEnabled debe reflejar el estado real del plan.';
    }

    $deniedAction = $service->checkActionAllowed($tenantAlpha, 'viewer_alpha', 'pos', 'finalize_sale', $appId);
    if (($deniedAction['allowed'] ?? true) !== false || (string) ($deniedAction['decision'] ?? '') !== 'deny') {
        $failures[] = 'checkActionAllowed debe negar acciones no permitidas para viewer.';
    }

    $resolved = $service->resolveToolForRequest([
        'tenant_id' => $tenantBeta,
        'user_id' => 'admin_beta',
        'project_id' => $appId,
        'message_text' => 'lista mis tiendas',
    ]);
    if ((string) ($resolved['resolved_module'] ?? '') !== 'ecommerce' || ($resolved['allowed'] ?? false) !== true) {
        $failures[] = 'resolveToolForRequest debe resolver ecommerce para solicitudes claras de tiendas.';
    }

    $ambiguous = $service->resolveToolForRequest([
        'tenant_id' => $tenantBeta,
        'user_id' => 'admin_beta',
        'project_id' => $appId,
        'message_text' => 'ayudame con multiusuario y pricing',
    ]);
    $candidateModules = array_map(
        static fn(array $item): string => (string) ($item['module_key'] ?? ''),
        (array) ($ambiguous['candidate_modules'] ?? [])
    );
    if (($ambiguous['ambiguity_detected'] ?? false) !== true || (string) ($ambiguous['result_status'] ?? '') !== 'ambiguous') {
        $failures[] = 'Las solicitudes ambiguas deben devolver aclaracion segura.';
    }
    if (!in_array('access_control', $candidateModules, true) || !in_array('saas_plan', $candidateModules, true)) {
        $failures[] = 'La aclaracion debe devolver candidatos relevantes sin inventar modulos.';
    }
} catch (Throwable $e) {
    $failures[] = 'La base de agent tools integration debe pasar: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @param array<int, array<string, mixed>> $items
 * @return array<string, array<string, mixed>>
 */
function indexByModuleKey(array $items): array
{
    $indexed = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $moduleKey = trim((string) ($item['module_key'] ?? ''));
        if ($moduleKey !== '') {
            $indexed[$moduleKey] = $item;
        }
    }

    return $indexed;
}

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
