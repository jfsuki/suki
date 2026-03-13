<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditLogger;
use App\Core\ContractRegistry;
use App\Core\Database;
use App\Core\ProjectRegistry;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;
use App\Core\TenantAccessControlRepository;
use App\Core\TenantAccessControlService;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/tenant_plan_skills_' . time() . '_' . random_int(1000, 9999);
$tmpProjectRoot = $tmpDir . '/project_root';
@mkdir($tmpProjectRoot . '/contracts/entities', 0777, true);
@mkdir($tmpProjectRoot . '/storage/cache', 0777, true);
@mkdir($tmpProjectRoot . '/storage/meta', 0777, true);

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
putenv('DB_PATH=' . $tmpDir . '/tenant_plan_skills.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/tenant_plan_skills.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha_plan_skills';
$tenantBeta = 'tenant_beta_plan_skills';
$appId = 'tenant_plan_skills_app';

$registry = new ProjectRegistry();
$registry->ensureProject($appId, 'Tenant Plan Skills App', 'active', 'shared', 'admin_alpha', 'legacy');
foreach ([
    ['id' => 'admin_alpha', 'label' => 'Admin Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'admin_beta', 'label' => 'Admin Beta', 'role' => 'admin', 'tenant' => $tenantBeta],
    ['id' => 'operator_alpha', 'label' => 'Operator Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
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

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $resolver = new SkillResolver();

    $cases = [
        'asigna plan starter al tenant' => 'tenant_assign_plan',
        'cual es mi plan actual' => 'tenant_get_plan',
        'lista planes disponibles' => 'tenant_list_plans',
        'revisa el limite del plan limit_key=users' => 'tenant_check_plan_limit',
        'que modulos habilita mi plan' => 'tenant_get_enabled_modules',
    ];
    foreach ($cases as $message => $expectedSkill) {
        $resolved = $resolver->resolve($message, $skillRegistry, []);
        if ((string) (($resolved['selected']['name'] ?? '') ?: '') !== $expectedSkill) {
            $failures[] = 'SkillResolver no detecto ' . $expectedSkill . ' para: ' . $message;
        }
    }

    $nonSaas = $resolver->resolve('lista usuarios del tenant', $skillRegistry, []);
    if (str_starts_with((string) (($nonSaas['selected']['name'] ?? '') ?: ''), 'tenant_')
        && in_array((string) (($nonSaas['selected']['name'] ?? '') ?: ''), [
            'tenant_assign_plan',
            'tenant_get_plan',
            'tenant_list_plans',
            'tenant_set_plan_limits',
            'tenant_check_plan_limit',
            'tenant_get_enabled_modules',
        ], true)) {
        $failures[] = 'Una consulta de access control no debe colisionar con las skills de planes SaaS.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las skills de SaaS plans deben resolver rutas naturales: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/tenant_plan_skills.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $alphaPayload = [
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'user_id' => 'admin_alpha',
        'auth_user_id' => 'admin_alpha',
        'auth_tenant_id' => $tenantAlpha,
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
    ];

    $assignPlan = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'tenant_plan_assign_' . time(),
            'message' => 'asigna plan starter al tenant',
        ],
    ]);
    if ((string) ($assignPlan['status'] ?? '') !== 'success' || (string) (($assignPlan['data']['saas_plan_action'] ?? '') ?: '') !== 'assign_plan') {
        $failures[] = 'El agente debe asignar el plan del tenant via skill.';
    }
    if ((string) (($assignPlan['data']['module_used'] ?? '') ?: '') !== 'saas_plan' || (string) (($assignPlan['data']['plan_key'] ?? '') ?: '') !== 'starter') {
        $failures[] = 'La asignacion de plan debe marcar module_used=saas_plan y plan_key.';
    }

    $getPlan = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'tenant_plan_get_' . time(),
            'message' => 'cual es mi plan actual',
        ],
    ]);
    if ((string) ($getPlan['status'] ?? '') !== 'success' || (string) (($getPlan['data']['saas_plan_action'] ?? '') ?: '') !== 'get_plan') {
        $failures[] = 'El agente debe consultar el plan actual del tenant.';
    }
    if ((string) (($getPlan['data']['tenant_plan']['plan_key'] ?? '') ?: '') !== 'starter') {
        $failures[] = 'La consulta del plan debe devolver starter para el tenant alpha.';
    }

    $setUserLimit = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'tenant_plan_limit_users_' . time(),
            'message' => 'actualiza limite del plan plan_key=starter limit_key=users limit_value=5',
        ],
    ]);
    if ((string) ($setUserLimit['status'] ?? '') !== 'success' || (string) (($setUserLimit['data']['saas_plan_action'] ?? '') ?: '') !== 'set_plan_limits') {
        $failures[] = 'El agente debe actualizar limites del plan via skill.';
    }

    $setModuleLimit = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'tenant_plan_limit_module_' . time(),
            'message' => 'actualiza limite del plan plan_key=starter limit_key=module:ecommerce limit_value=true limit_type=feature',
        ],
    ]);
    if ((string) ($setModuleLimit['status'] ?? '') !== 'success') {
        $failures[] = 'El agente debe poder habilitar modulos por feature flag del plan.';
    }

    $checkLimit = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'tenant_plan_check_' . time(),
            'message' => 'revisa el limite del plan limit_key=users usage=2',
        ],
    ]);
    if ((string) ($checkLimit['status'] ?? '') !== 'success' || (string) (($checkLimit['data']['saas_plan_action'] ?? '') ?: '') !== 'check_plan_limit') {
        $failures[] = 'El agente debe revisar limites del plan via skill.';
    }
    if ((string) (($checkLimit['data']['limit_key'] ?? '') ?: '') !== 'users') {
        $failures[] = 'La revision del limite debe exponer limit_key en observabilidad.';
    }

    $listPlans = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'tenant_plan_list_' . time(),
            'message' => 'lista planes disponibles',
        ],
    ]);
    if ((string) ($listPlans['status'] ?? '') !== 'success' || (string) (($listPlans['data']['saas_plan_action'] ?? '') ?: '') !== 'list_plans') {
        $failures[] = 'El agente debe listar planes disponibles.';
    }
    if ((int) (($listPlans['data']['result_count'] ?? 0)) < 4) {
        $failures[] = 'La lista de planes debe incluir el catalogo base.';
    }

    $enabledModules = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'tenant_plan_modules_' . time(),
            'message' => 'que modulos habilita mi plan',
        ],
    ]);
    if ((string) ($enabledModules['status'] ?? '') !== 'success' || (string) (($enabledModules['data']['saas_plan_action'] ?? '') ?: '') !== 'get_enabled_modules') {
        $failures[] = 'El agente debe devolver modulos habilitados del plan.';
    }
    if (!in_array('ecommerce', (array) ($enabledModules['data']['enabled_modules'] ?? []), true)) {
        $failures[] = 'La resolucion de modulos habilitados debe reflejar el override de ecommerce.';
    }

    $betaPayload = [
        'tenant_id' => $tenantBeta,
        'project_id' => $appId,
        'user_id' => 'admin_beta',
        'auth_user_id' => 'admin_beta',
        'auth_tenant_id' => $tenantBeta,
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
    ];
    $betaAssign = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $betaPayload + [
            'session_id' => 'tenant_plan_beta_assign_' . time(),
            'message' => 'asigna plan pro al tenant',
        ],
    ]);
    $betaGet = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $betaPayload + [
            'session_id' => 'tenant_plan_beta_get_' . time(),
            'message' => 'cual es mi plan actual',
        ],
    ]);
    if ((string) ($betaAssign['status'] ?? '') !== 'success' || (string) (($betaGet['data']['tenant_plan']['plan_key'] ?? '') ?: '') !== 'pro') {
        $failures[] = 'Los planes SaaS deben mantenerse aislados por tenant en el chat.';
    }
    if ((string) (($getPlan['data']['tenant_plan']['plan_key'] ?? '') ?: '') !== 'starter') {
        $failures[] = 'El tenant alpha no debe perder su plan por operaciones sobre tenant beta.';
    }
} catch (Throwable $e) {
    $failures[] = 'La integracion chat de SaaS plans debe pasar: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

function runChatTurn(array $request): array
{
    $helper = __DIR__ . '/entity_search_chat_turn.php';
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded));
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Respuesta invalida del helper de chat: ' . $raw);
    }

    return $json;
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
