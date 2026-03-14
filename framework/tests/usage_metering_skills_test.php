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
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/usage_metering_skills_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/usage_metering_skills.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/usage_metering_skills.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha_usage_skills';
$tenantBeta = 'tenant_beta_usage_skills';
$appId = 'usage_metering_skills_app';

$registry = new ProjectRegistry();
$registry->ensureProject($appId, 'Usage Metering Skills App', 'active', 'shared', 'admin_alpha', 'legacy');
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

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $resolver = new SkillResolver();

    $cases = [
        'registra evento de uso metric_key=documents_uploaded delta_value=1' => 'usage_record_event',
        'muestra resumen de uso del tenant' => 'usage_get_summary',
        'revisa limite de uso metric_key=users' => 'usage_check_limit',
        'lista metricas de uso' => 'usage_list_metrics',
        'historial de uso metric_key=documents_uploaded' => 'usage_get_history',
    ];
    foreach ($cases as $message => $expectedSkill) {
        $resolved = $resolver->resolve($message, $skillRegistry, []);
        if ((string) (($resolved['selected']['name'] ?? '') ?: '') !== $expectedSkill) {
            $failures[] = 'SkillResolver no detecto ' . $expectedSkill . ' para: ' . $message;
        }
    }

    $nonUsage = $resolver->resolve('lista usuarios del tenant', $skillRegistry, []);
    if (str_starts_with((string) (($nonUsage['selected']['name'] ?? '') ?: ''), 'usage_')) {
        $failures[] = 'Una consulta multiusuario no debe colisionar con las skills de usage metering.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las skills de usage metering deben resolver rutas naturales: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/usage_metering_skills.sqlite',
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

    $recordEvent = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'usage_record_' . time(),
            'message' => 'registra evento de uso metric_key=documents_uploaded delta_value=1 source_module=media',
        ],
    ]);
    if ((string) ($recordEvent['status'] ?? '') !== 'success' || (string) (($recordEvent['data']['usage_metering_action'] ?? '') ?: '') !== 'record_event') {
        $failures[] = 'El agente debe registrar eventos de uso via skill.';
    }
    if ((string) (($recordEvent['data']['metric_key'] ?? '') ?: '') !== 'documents_uploaded') {
        $failures[] = 'El registro de evento debe exponer metric_key en observabilidad.';
    }

    $summary = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'usage_summary_' . time(),
            'message' => 'muestra resumen de uso del tenant',
        ],
    ]);
    if ((string) ($summary['status'] ?? '') !== 'success' || (string) (($summary['data']['usage_metering_action'] ?? '') ?: '') !== 'get_summary') {
        $failures[] = 'El agente debe devolver el resumen de uso del tenant.';
    }
    if ((string) (($summary['data']['module_used'] ?? '') ?: '') !== 'usage_metering') {
        $failures[] = 'El resumen de uso debe marcar module_used=usage_metering.';
    }

    $checkLimit = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'usage_check_' . time(),
            'message' => 'revisa limite de uso metric_key=users',
        ],
    ]);
    if ((string) ($checkLimit['status'] ?? '') !== 'success' || (string) (($checkLimit['data']['usage_metering_action'] ?? '') ?: '') !== 'check_limit') {
        $failures[] = 'El agente debe revisar limites de uso via skill.';
    }
    if (($checkLimit['data']['over_limit'] ?? false) !== true) {
        $failures[] = 'El check de uso debe reportar over_limit cuando el tenant supera el plan.';
    }

    $listMetrics = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'usage_metrics_' . time(),
            'message' => 'lista metricas de uso',
        ],
    ]);
    if ((string) ($listMetrics['status'] ?? '') !== 'success' || (string) (($listMetrics['data']['usage_metering_action'] ?? '') ?: '') !== 'list_metrics') {
        $failures[] = 'El agente debe listar metricas de uso.';
    }
    if ((int) ($listMetrics['data']['result_count'] ?? 0) < 5) {
        $failures[] = 'La lista de metricas debe devolver el catalogo base de usage metering.';
    }

    $history = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaPayload + [
            'session_id' => 'usage_history_' . time(),
            'message' => 'historial de uso metric_key=documents_uploaded',
        ],
    ]);
    if ((string) ($history['status'] ?? '') !== 'success' || (string) (($history['data']['usage_metering_action'] ?? '') ?: '') !== 'get_history') {
        $failures[] = 'El agente debe devolver historial de uso via skill.';
    }
    if ((int) ($history['data']['result_count'] ?? 0) !== 1) {
        $failures[] = 'El historial de uso debe devolver el evento registrado previamente.';
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
    $betaSummary = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $betaPayload + [
            'session_id' => 'usage_beta_summary_' . time(),
            'message' => 'muestra resumen de uso metric_key=documents_uploaded',
        ],
    ]);
    if ((string) ($betaSummary['status'] ?? '') !== 'success') {
        $failures[] = 'El tenant beta debe poder consultar su propio resumen de uso.';
    }
    if ((float) (($betaSummary['data']['usage_value'] ?? 0)) !== 0.0) {
        $failures[] = 'Los eventos de uso deben mantenerse aislados por tenant en chat.';
    }
} catch (Throwable $e) {
    $failures[] = 'La integracion chat de usage metering debe pasar: ' . $e->getMessage();
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
