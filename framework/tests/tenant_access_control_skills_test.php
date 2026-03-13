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
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/tenant_access_control_skills_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/tenant_access_control_skills.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/tenant_access_control_skills.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha_access_skills';
$tenantBeta = 'tenant_beta_access_skills';
$appId = 'access_skills_app';

$registry = new ProjectRegistry();
$registry->ensureProject($appId, 'Access Skills App', 'active', 'shared', 'admin_alpha', 'legacy');
foreach ([
    ['id' => 'admin_alpha', 'label' => 'Admin Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'operator_alpha', 'label' => 'Operator Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'viewer_alpha', 'label' => 'Viewer Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'beta_user', 'label' => 'Beta User', 'role' => 'admin', 'tenant' => $tenantBeta],
] as $user) {
    $registry->createAuthUser($appId, $user['id'], 'secret', $user['role'], $user['tenant'], $user['label']);
    $registry->touchUser($user['id'], $user['role'], 'auth', $user['tenant'], $user['label']);
    $registry->assignUserToProject($appId, $user['id'], $user['role']);
}

$service = new TenantAccessControlService(
    new TenantAccessControlRepository($pdo),
    new AuditLogger($pdo),
    $registry
);
$service->attachUserToTenant([
    'tenant_id' => $tenantAlpha,
    'project_id' => $appId,
    'user_id' => 'admin_alpha',
    'role_key' => 'admin',
    'actor_user_id' => 'system',
]);
$service->attachUserToTenant([
    'tenant_id' => $tenantAlpha,
    'project_id' => $appId,
    'user_id' => 'operator_alpha',
    'role_key' => 'operator',
    'actor_user_id' => 'admin_alpha',
]);
$service->attachUserToTenant([
    'tenant_id' => $tenantAlpha,
    'project_id' => $appId,
    'user_id' => 'viewer_alpha',
    'role_key' => 'viewer',
    'actor_user_id' => 'admin_alpha',
]);
$service->attachUserToTenant([
    'tenant_id' => $tenantBeta,
    'project_id' => $appId,
    'user_id' => 'beta_user',
    'role_key' => 'operator',
    'actor_user_id' => 'system',
]);

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $resolver = new SkillResolver();

    $cases = [
        'lista usuarios del tenant' => 'tenant_list_users',
        'que rol tiene user_id=operator_alpha' => 'tenant_get_user_role',
        'agrega user_id=viewer_alpha role_key=manager al tenant' => 'tenant_add_user',
        'puede ejecutar user_id=operator_alpha permiso=ecommerce.link_product' => 'tenant_check_permission',
    ];
    foreach ($cases as $message => $expectedSkill) {
        $resolved = $resolver->resolve($message, $skillRegistry, []);
        if ((string) (($resolved['selected']['name'] ?? '') ?: '') !== $expectedSkill) {
            $failures[] = 'SkillResolver no detecto ' . $expectedSkill . ' para: ' . $message;
        }
    }

    $nonAccess = $resolver->resolve('lista mis tiendas', $skillRegistry, []);
    if (str_starts_with((string) (($nonAccess['selected']['name'] ?? '') ?: ''), 'tenant_')) {
        $failures[] = 'Una consulta ecommerce no debe colisionar con skills de access control.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las skills de tenant access control deben resolver rutas naturales: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/tenant_access_control_skills.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $basePayload = [
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

    $listUsers = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $basePayload + [
            'session_id' => 'tenant_access_list_' . time(),
            'message' => 'lista usuarios del tenant',
        ],
    ]);
    if ((string) ($listUsers['status'] ?? '') !== 'success' || (string) (($listUsers['data']['access_control_action'] ?? '') ?: '') !== 'list_users') {
        $failures[] = 'El agente debe listar usuarios del tenant via skill.';
    }
    if ((int) (($listUsers['data']['result_count'] ?? 0)) !== 3) {
        $failures[] = 'La lista de usuarios del tenant debe respetar tenant isolation.';
    }
    $listUsersJson = json_encode($listUsers['data']['items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if (str_contains($listUsersJson, 'beta_user')) {
        $failures[] = 'La lista de usuarios no debe incluir miembros de otro tenant.';
    }

    $getRole = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $basePayload + [
            'session_id' => 'tenant_access_role_' . time(),
            'message' => 'que rol tiene user_id=operator_alpha',
        ],
    ]);
    if ((string) ($getRole['status'] ?? '') !== 'success' || (string) (($getRole['data']['access_control_action'] ?? '') ?: '') !== 'get_user_role') {
        $failures[] = 'El agente debe consultar roles tenant-aware.';
    }
    if ((string) (($getRole['data']['user_role']['role_key'] ?? '') ?: '') !== 'operator') {
        $failures[] = 'La consulta de rol debe devolver el role_key correcto.';
    }

    $checkPermission = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $basePayload + [
            'session_id' => 'tenant_access_perm_' . time(),
            'message' => 'puede ejecutar user_id=viewer_alpha permiso=ecommerce.link_product',
        ],
    ]);
    if ((string) ($checkPermission['status'] ?? '') !== 'success' || (string) (($checkPermission['data']['access_control_action'] ?? '') ?: '') !== 'check_permission') {
        $failures[] = 'El agente debe revisar permisos tenant-aware.';
    }
    if ((string) (($checkPermission['data']['decision'] ?? '') ?: '') !== 'deny' || (string) (($checkPermission['data']['permission_checked'] ?? '') ?: '') !== 'ecommerce.link_product') {
        $failures[] = 'La revision de permisos debe devolver decision y permiso evaluado.';
    }
    if ((string) (($checkPermission['data']['module_used'] ?? '') ?: '') !== 'access_control') {
        $failures[] = 'Las respuestas de access control deben marcar module_used=access_control.';
    }
} catch (Throwable $e) {
    $failures[] = 'La integracion chat de tenant access control debe pasar: ' . $e->getMessage();
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
