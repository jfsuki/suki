<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AgentOpsObservabilityService;
use App\Core\AuditLogger;
use App\Core\ContractRegistry;
use App\Core\Database;
use App\Core\ProjectRegistry;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;
use App\Core\SqlMetricsRepository;
use App\Core\TenantAccessControlRepository;
use App\Core\TenantAccessControlService;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/agentops_observability_skills_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/agentops_observability_skills.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/agentops_observability_skills.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha_agentops_skills';
$tenantBeta = 'tenant_beta_agentops_skills';
$appId = 'agentops_skills_app';

$registry = new ProjectRegistry();
$registry->ensureProject($appId, 'AgentOps Skills App', 'active', 'shared', 'manager_alpha', 'legacy');
foreach ([
    ['id' => 'manager_alpha', 'label' => 'Manager Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'viewer_alpha', 'label' => 'Viewer Alpha', 'role' => 'admin', 'tenant' => $tenantAlpha],
    ['id' => 'manager_beta', 'label' => 'Manager Beta', 'role' => 'admin', 'tenant' => $tenantBeta],
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
    'user_id' => 'manager_alpha',
    'role_key' => 'manager',
    'actor_user_id' => 'system',
]);
$accessControl->attachUserToTenant([
    'tenant_id' => $tenantAlpha,
    'project_id' => $appId,
    'user_id' => 'viewer_alpha',
    'role_key' => 'viewer',
    'actor_user_id' => 'manager_alpha',
]);
$accessControl->attachUserToTenant([
    'tenant_id' => $tenantBeta,
    'project_id' => $appId,
    'user_id' => 'manager_beta',
    'role_key' => 'manager',
    'actor_user_id' => 'system',
]);

$metricsRepo = new SqlMetricsRepository(null, $tmpDir . '/project_registry.sqlite');
$observability = new AgentOpsObservabilityService($metricsRepo);

foreach ([
    ['tenant' => $tenantAlpha, 'session' => 'sess_alpha_1', 'module' => 'ecommerce', 'action' => 'validate_connection', 'fallback' => true, 'status' => 'resolved', 'latency' => 180],
    ['tenant' => $tenantAlpha, 'session' => 'sess_alpha_2', 'module' => 'agentops_observability', 'action' => 'get_metrics_summary', 'fallback' => false, 'status' => 'success', 'latency' => 120],
    ['tenant' => $tenantAlpha, 'session' => 'sess_alpha_3', 'module' => 'pos', 'action' => 'finalize_sale', 'fallback' => true, 'status' => 'blocked', 'latency' => 210],
    ['tenant' => $tenantAlpha, 'session' => 'sess_alpha_4', 'module' => 'agent_tools', 'action' => 'resolve_tool_for_request', 'fallback' => true, 'status' => 'ambiguous', 'latency' => 240],
    ['tenant' => $tenantAlpha, 'session' => 'sess_alpha_5', 'module' => 'usage_metering', 'action' => 'get_summary', 'fallback' => false, 'status' => 'resolved', 'latency' => 100],
    ['tenant' => $tenantBeta, 'session' => 'sess_beta_1', 'module' => 'media', 'action' => 'list', 'fallback' => false, 'status' => 'resolved', 'latency' => 70],
] as $trace) {
    $observability->recordDecisionTrace([
        'tenant_id' => $trace['tenant'],
        'project_id' => $appId,
        'session_id' => $trace['session'],
        'route_path' => 'cache>rules>skills',
        'selected_module' => $trace['module'],
        'selected_action' => $trace['action'],
        'evidence_source' => $trace['fallback'] ? 'llm' : 'skills',
        'ambiguity_detected' => $trace['status'] === 'ambiguous',
        'fallback_llm' => $trace['fallback'],
        'latency_ms' => $trace['latency'],
        'result_status' => $trace['status'],
    ]);
}

foreach ([
    ['tenant' => $tenantAlpha, 'module' => 'ecommerce', 'action' => 'validate_connection', 'permission' => 'deny', 'plan' => 'disabled', 'latency' => 1400, 'success' => false, 'error' => 'module_disabled_by_plan'],
    ['tenant' => $tenantAlpha, 'module' => 'pos', 'action' => 'finalize_sale', 'permission' => 'deny', 'plan' => 'enabled', 'latency' => 100, 'success' => false, 'error' => 'permission_denied'],
    ['tenant' => $tenantAlpha, 'module' => 'agentops', 'action' => 'get_metrics_summary', 'permission' => 'allow', 'plan' => 'enabled', 'latency' => 60, 'success' => true, 'error' => null],
    ['tenant' => $tenantBeta, 'module' => 'media', 'action' => 'list', 'permission' => 'allow', 'plan' => 'enabled', 'latency' => 40, 'success' => true, 'error' => null],
] as $trace) {
    $observability->recordToolExecutionTrace([
        'tenant_id' => $trace['tenant'],
        'project_id' => $appId,
        'module_key' => $trace['module'],
        'action_key' => $trace['action'],
        'input_schema_valid' => true,
        'permission_check' => $trace['permission'],
        'plan_check' => $trace['plan'],
        'execution_latency' => $trace['latency'],
        'success' => $trace['success'],
        'error_code' => $trace['error'],
    ]);
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $resolver = new SkillResolver();

    $cases = [
        'resumen agentops' => 'agentops_get_metrics_summary',
        'decisiones recientes agentops' => 'agentops_list_recent_decisions',
        'ejecuciones de herramientas agentops' => 'agentops_list_tool_executions',
        'anomalias agentops' => 'agentops_get_anomaly_flags',
    ];
    foreach ($cases as $message => $expectedSkill) {
        $resolved = $resolver->resolve($message, $skillRegistry, []);
        if ((string) (($resolved['selected']['name'] ?? '') ?: '') !== $expectedSkill) {
            $failures[] = 'SkillResolver no detecto ' . $expectedSkill . ' para: ' . $message;
        }
    }
} catch (Throwable $e) {
    $failures[] = 'Las skills AgentOps deben resolver rutas naturales: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/agentops_observability_skills.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $alphaManagerPayload = [
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'user_id' => 'manager_alpha',
        'auth_user_id' => 'manager_alpha',
        'auth_tenant_id' => $tenantAlpha,
        'role' => 'manager',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
    ];

    $summaryReply = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaManagerPayload + [
            'session_id' => 'agentops_summary_' . time(),
            'message' => 'resumen agentops',
        ],
    ]);
    if ((string) ($summaryReply['status'] ?? '') !== 'success' || (string) (($summaryReply['data']['agentops_action'] ?? '') ?: '') !== 'get_metrics_summary') {
        $failures[] = 'La skill AgentOps debe devolver el resumen de metricas.';
    }
    if ((float) (($summaryReply['data']['item']['metrics_summary']['fallback_rate'] ?? 0.0)) <= 0.3) {
        $failures[] = 'El resumen chat AgentOps debe incluir fallback_rate agregado.';
    }

    $decisionsReply = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaManagerPayload + [
            'session_id' => 'agentops_decisions_' . time(),
            'message' => 'decisiones recientes agentops limit=2',
        ],
    ]);
    if ((string) (($decisionsReply['data']['agentops_action'] ?? '') ?: '') !== 'list_recent_decisions' || (int) (($decisionsReply['data']['result_count'] ?? 0)) !== 2) {
        $failures[] = 'La skill AgentOps debe listar decisiones recientes con limite.';
    }

    $toolsReply = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaManagerPayload + [
            'session_id' => 'agentops_tools_' . time(),
            'message' => 'ejecuciones de herramientas agentops module_key=agentops',
        ],
    ]);
    if ((string) (($toolsReply['data']['agentops_action'] ?? '') ?: '') !== 'list_tool_executions') {
        $failures[] = 'La skill AgentOps debe listar ejecuciones de herramientas.';
    }
    if ((int) (($toolsReply['data']['result_count'] ?? 0)) !== 1) {
        $failures[] = 'El filtro module_key debe limitar las ejecuciones retornadas.';
    }

    $anomaliesReply = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $alphaManagerPayload + [
            'session_id' => 'agentops_anomalies_' . time(),
            'message' => 'anomalias agentops',
        ],
    ]);
    if ((string) (($anomaliesReply['data']['agentops_action'] ?? '') ?: '') !== 'get_anomaly_flags' || (int) (($anomaliesReply['data']['result_count'] ?? 0)) < 2) {
        $failures[] = 'La skill AgentOps debe exponer banderas de anomalia.';
    }

    $betaManagerPayload = [
        'tenant_id' => $tenantBeta,
        'project_id' => $appId,
        'user_id' => 'manager_beta',
        'auth_user_id' => 'manager_beta',
        'auth_tenant_id' => $tenantBeta,
        'role' => 'manager',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
    ];
    $betaSummary = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $betaManagerPayload + [
            'session_id' => 'agentops_beta_summary_' . time(),
            'message' => 'resumen agentops',
        ],
    ]);
    if ((float) (($betaSummary['data']['item']['metrics_summary']['fallback_rate'] ?? 1.0)) !== 0.0) {
        $failures[] = 'La consulta beta no debe heredar fallback_rate del tenant alpha.';
    }
    if ((int) (($betaSummary['data']['item']['metrics_summary']['permission_denials'] ?? 1)) !== 0) {
        $failures[] = 'La consulta beta no debe heredar permission_denials del tenant alpha.';
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
    $viewerSummary = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $viewerPayload + [
            'session_id' => 'agentops_viewer_' . time(),
            'message' => 'resumen agentops',
        ],
    ]);
    $viewerReply = mb_strtolower((string) (($viewerSummary['data']['reply'] ?? $viewerSummary['message'] ?? '')), 'UTF-8');
    if (!str_contains($viewerReply, 'acceso') && !str_contains($viewerReply, 'bloqueado') && !str_contains($viewerReply, 'no autorizado')) {
        $failures[] = 'Un viewer no debe poder consultar AgentOps managerial.';
    }
} catch (Throwable $e) {
    $failures[] = 'La integracion chat AgentOps debe pasar: ' . $e->getMessage();
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
