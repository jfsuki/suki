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
use App\Core\TenantPlanRepository;
use App\Core\TenantPlanService;
$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/agent_tools_integration_skills_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/agent_tools_integration_skills.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/agent_tools_integration_skills.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha_agent_tools_skills';
$tenantBeta = 'tenant_beta_agent_tools_skills';
$appId = 'agent_tools_skills_app';

$registry = new ProjectRegistry();
$registry->ensureProject($appId, 'Agent Tools Skills App', 'active', 'shared', 'admin_alpha', 'legacy');
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

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $resolver = new SkillResolver();

    $cases = [
        'que herramientas puede usar este tenant' => 'agent_list_tool_groups',
        'que herramientas hay para ecommerce' => 'agent_get_module_capabilities',
        'que modulo uso para lista mis tiendas' => 'agent_resolve_tool_for_request',
        'esta habilitado ecommerce para este tenant' => 'agent_check_module_enabled',
        'module_key=pos action_key=finalize_sale puedo ejecutar esto' => 'agent_check_action_allowed',
    ];
    foreach ($cases as $message => $expectedSkill) {
        $resolved = $resolver->resolve($message, $skillRegistry, []);
        if ((string) (($resolved['selected']['name'] ?? '') ?: '') !== $expectedSkill) {
            $failures[] = 'SkillResolver no detecto ' . $expectedSkill . ' para: ' . $message;
        }
    }

    $nonAgent = $resolver->resolve('lista mis tiendas', $skillRegistry, []);
    if (str_starts_with((string) (($nonAgent['selected']['name'] ?? '') ?: ''), 'agent_')) {
        $failures[] = 'Una solicitud ecommerce directa no debe colisionar con agent tools integration.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las skills de agent tools integration deben resolver rutas naturales: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/agent_tools_integration_skills.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $alphaAdminPayload = [
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

    $listGroups = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaAdminPayload + [
            'session_id' => 'agent_tools_groups_' . time(),
            'message' => 'que herramientas puede usar este tenant',
        ],
    ]);
    if ((string) ($listGroups['status'] ?? '') !== 'success' || (string) (($listGroups['data']['agent_tools_action'] ?? '') ?: '') !== 'list_tool_groups') {
        $failures[] = 'El agente debe listar grupos de herramientas por skill.';
    }
    if ((int) (($listGroups['data']['result_count'] ?? 0)) !== 9) {
        $failures[] = 'La respuesta de grupos debe devolver el catalogo canonico.';
    }

    $alphaEcommerce = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaAdminPayload + [
            'session_id' => 'agent_tools_alpha_caps_' . time(),
            'message' => 'que herramientas hay para ecommerce',
        ],
    ]);
    if ((string) ($alphaEcommerce['status'] ?? '') !== 'success' || (string) (($alphaEcommerce['data']['agent_tools_action'] ?? '') ?: '') !== 'get_module_capabilities') {
        $failures[] = 'El agente debe devolver capacidades del modulo solicitado.';
    }
    if (($alphaEcommerce['data']['enabled'] ?? true) !== false || (int) (($alphaEcommerce['data']['result_count'] ?? -1)) !== 0) {
        $failures[] = 'El modulo disabled por plan no debe exponer acciones en chat.';
    }

    $betaAdminPayload = [
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

    $resolveClear = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $betaAdminPayload + [
            'session_id' => 'agent_tools_resolve_' . time(),
            'message' => 'que modulo uso para lista mis tiendas',
        ],
    ]);
    if ((string) ($resolveClear['status'] ?? '') !== 'success' || (string) (($resolveClear['data']['resolved_module'] ?? '') ?: '') !== 'ecommerce') {
        $failures[] = 'La resolucion clara debe devolver ecommerce como modulo correcto.';
    }
    if (($resolveClear['data']['allowed'] ?? false) !== true || (string) (($resolveClear['data']['result_status'] ?? '') ?: '') !== 'resolved') {
        $failures[] = 'La resolucion clara debe marcar enabled+allowed para el tenant correcto.';
    }

    $ambiguous = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaAdminPayload + [
            'session_id' => 'agent_tools_ambiguous_' . time(),
            'message' => 'resuelve herramienta para multiusuario y pricing',
        ],
    ]);
    $ambiguousReply = (string) (($ambiguous['data']['reply'] ?? '') ?: '');
    if (($ambiguous['data']['ambiguity_detected'] ?? false) !== true || !str_contains($ambiguousReply, 'module_key=access_control') || !str_contains($ambiguousReply, 'module_key=saas_plan')) {
        $failures[] = 'La ruta ambigua debe pedir aclaracion con candidatos seguros.';
    }

    $moduleEnabled = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaAdminPayload + [
            'session_id' => 'agent_tools_enabled_' . time(),
            'message' => 'esta habilitado ecommerce para este tenant',
        ],
    ]);
    if ((string) ($moduleEnabled['status'] ?? '') !== 'success' || (string) (($moduleEnabled['data']['agent_tools_action'] ?? '') ?: '') !== 'check_module_enabled') {
        $failures[] = 'La skill debe revisar si un modulo esta habilitado.';
    }
    if (($moduleEnabled['data']['enabled'] ?? true) !== false) {
        $failures[] = 'El check de modulo habilitado debe reflejar el plan del tenant.';
    }

    $viewerPayload = [
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'user_id' => 'viewer_alpha',
        'auth_user_id' => 'viewer_alpha',
        'auth_tenant_id' => $tenantAlpha,
        'role' => 'viewer',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
    ];
    $actionAllowed = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $viewerPayload + [
            'session_id' => 'agent_tools_allowed_' . time(),
            'message' => 'module_key=pos action_key=finalize_sale puedo ejecutar esto',
        ],
    ]);
    if ((string) ($actionAllowed['status'] ?? '') !== 'success' || (string) (($actionAllowed['data']['agent_tools_action'] ?? '') ?: '') !== 'check_action_allowed') {
        $failures[] = 'La skill debe revisar permisos por accion.';
    }
    if (($actionAllowed['data']['allowed'] ?? true) !== false || (string) (($actionAllowed['data']['decision'] ?? '') ?: '') !== 'deny') {
        $failures[] = 'La revision de accion debe negar permisos insuficientes.';
    }

    $betaEcommerce = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $betaAdminPayload + [
            'session_id' => 'agent_tools_beta_caps_' . time(),
            'message' => 'que herramientas hay para ecommerce',
        ],
    ]);
    if (($betaEcommerce['data']['enabled'] ?? false) !== true || (int) (($betaEcommerce['data']['result_count'] ?? 0)) <= 0) {
        $failures[] = 'Los capabilities deben mantenerse aislados por tenant y plan.';
    }

    $chatJson = json_encode($alphaEcommerce['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if (str_contains($chatJson, 'secret') || str_contains($chatJson, 'token')) {
        $failures[] = 'Las respuestas de agent tools no deben exponer secretos ni tokens.';
    }
} catch (Throwable $e) {
    $failures[] = 'La integracion chat de agent tools debe pasar: ' . $e->getMessage();
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
