<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditLogger;
use App\Core\Database;
use App\Core\IntentRouter;
use App\Core\ProjectRegistry;
use App\Core\TenantAccessControlRepository;
use App\Core\TenantAccessControlService;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/tenant_access_control_foundation_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/tenant_access_control.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/tenant_access_control.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha_access';
$tenantBeta = 'tenant_beta_access';
$appId = 'access_app';

$registry = new ProjectRegistry();
$registry->ensureProject($appId, 'Access App', 'active', 'shared', 'owner_alpha', 'legacy');

foreach ([
    ['id' => 'owner_alpha', 'label' => 'Owner Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'manager_alpha', 'label' => 'Manager Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'viewer_alpha', 'label' => 'Viewer Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'operator_beta', 'label' => 'Operator Beta', 'role' => 'admin', 'tenant' => $tenantBeta],
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

try {
    $owner = $service->attachUserToTenant([
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'user_id' => 'owner_alpha',
        'role_key' => 'owner',
        'actor_user_id' => 'system',
    ]);
    if ((string) ($owner['role_key'] ?? '') !== 'owner' || (string) ($owner['status'] ?? '') !== 'active') {
        $failures[] = 'attachUserToTenant debe crear owner activo.';
    }

    $manager = $service->attachUserToTenant([
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'user_id' => 'manager_alpha',
        'role_key' => 'manager',
        'actor_user_id' => 'owner_alpha',
    ]);
    $viewer = $service->attachUserToTenant([
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'user_id' => 'viewer_alpha',
        'role_key' => 'viewer',
        'actor_user_id' => 'owner_alpha',
    ]);
    $beta = $service->attachUserToTenant([
        'tenant_id' => $tenantBeta,
        'project_id' => $appId,
        'user_id' => 'operator_beta',
        'role_key' => 'operator',
        'actor_user_id' => 'system',
    ]);

    $listed = $service->listTenantUsers($tenantAlpha);
    if (count($listed) !== 3) {
        $failures[] = 'listTenantUsers debe devolver solo usuarios del tenant actual.';
    }
    $listedJson = json_encode($listed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if (str_contains($listedJson, 'operator_beta')) {
        $failures[] = 'listTenantUsers no puede filtrar usuarios de otro tenant.';
    }

    $roleInfo = $service->getUserRoleInTenant($tenantAlpha, 'manager_alpha');
    if ((string) ($roleInfo['role_key'] ?? '') !== 'manager') {
        $failures[] = 'getUserRoleInTenant debe devolver el rol actual del usuario.';
    }
    if (!in_array('ecommerce.*:allow', (array) ($roleInfo['effective_permissions'] ?? []), true)) {
        $failures[] = 'resolveEffectivePermissions debe incluir permisos del rol manager.';
    }

    $updated = $service->assignUserRole($tenantAlpha, 'manager_alpha', 'operator', [], $appId);
    if ((string) ($updated['role_key'] ?? '') !== 'operator') {
        $failures[] = 'assignUserRole debe actualizar el rol del usuario.';
    }

    $deactivated = $service->deactivateTenantUser($tenantAlpha, 'viewer_alpha');
    if ((string) ($deactivated['status'] ?? '') !== 'inactive') {
        $failures[] = 'deactivateTenantUser debe marcar status=inactive.';
    }

    $allowed = $service->checkPermission($tenantAlpha, 'manager_alpha', 'ecommerce', 'link_product');
    if (($allowed['allowed'] ?? false) !== true || (string) ($allowed['decision'] ?? '') !== 'allow') {
        $failures[] = 'checkPermission debe permitir un rol valido.';
    }

    $denied = $service->checkPermission($tenantAlpha, 'viewer_alpha', 'users', 'add_user');
    if (($denied['allowed'] ?? true) !== false || (string) ($denied['decision'] ?? '') !== 'deny') {
        $failures[] = 'checkPermission debe negar un rol invalido o inactivo.';
    }

    try {
        $service->assignUserRole($tenantBeta, 'manager_alpha', 'admin', [], $appId);
        $failures[] = 'assignUserRole debe bloquear manipulacion cross-tenant.';
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'ACCESS_CONTROL_TENANT_USER_NOT_FOUND') {
            $failures[] = 'assignUserRole cross-tenant debe fallar por not_found tenant-scoped.';
        }
    }

    $router = new IntentRouter(null, 'strict');
    $blocked = $router->route([
        'action' => 'execute_command',
        'command' => [
            'command' => 'LinkEcommerceProduct',
            'tenant_id' => $tenantAlpha,
            'store_id' => 'store_1',
            'local_product_id' => '1',
            'external_product_id' => 'wc-10',
        ],
    ], [
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'session_id' => 'tenant_access_router_block',
        'mode' => 'app',
        'role' => 'admin',
        'is_authenticated' => true,
        'auth_user_id' => 'viewer_alpha',
        'auth_tenant_id' => $tenantAlpha,
    ]);
    if (!$blocked->isLocalResponse()) {
        $failures[] = 'RouterPolicyEvaluator debe bloquear acciones cuando el tenant user no tiene permiso.';
    }
    $blockedTelemetry = $blocked->telemetry();
    if ((string) ($blockedTelemetry['gate_decision'] ?? '') !== 'blocked') {
        $failures[] = 'Bloqueo RBAC tenant-aware debe marcar gate_decision=blocked.';
    }
    $gateResults = is_array($blockedTelemetry['gate_results'] ?? null) ? (array) $blockedTelemetry['gate_results'] : [];
    $authGateFailed = false;
    foreach ($gateResults as $gate) {
        if (!is_array($gate)) {
            continue;
        }
        if ((string) ($gate['name'] ?? '') !== 'auth_rbac_gate') {
            continue;
        }
        if (((bool) ($gate['required'] ?? false)) && !((bool) ($gate['passed'] ?? true))) {
            $authGateFailed = true;
            break;
        }
    }
    if (!$authGateFailed) {
        $failures[] = 'Bloqueo RBAC tenant-aware debe fallar en auth_rbac_gate.';
    }

    $authUser = $registry->getAuthUser($appId, 'manager_alpha');
    if ((string) ($authUser['tenant_id'] ?? '') !== $tenantAlpha || (string) ($authUser['role'] ?? '') !== 'operator') {
        $failures[] = 'attach/update role debe sincronizar auth_users con tenant y rol efectivos.';
    }
} catch (Throwable $e) {
    $failures[] = 'La base de tenant access control debe pasar: ' . $e->getMessage();
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
