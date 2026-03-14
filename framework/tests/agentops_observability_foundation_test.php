<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AgentOpsObservabilityService;
use App\Core\SqlMetricsRepository;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/agentops_observability_foundation_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');

$dbPath = $tmpDir . '/agentops_observability.sqlite';
$repo = new SqlMetricsRepository(null, $dbPath);
$service = new AgentOpsObservabilityService($repo);

$tenantAlpha = 'tenant_alpha_agentops_obs';
$tenantBeta = 'tenant_beta_agentops_obs';
$projectId = 'agentops_obs_app';

try {
    foreach ([
        ['session' => 'sess_a1', 'module' => 'pos', 'action' => 'finalize_sale', 'fallback' => false, 'ambiguity' => false, 'latency' => 140, 'status' => 'resolved'],
        ['session' => 'sess_a2', 'module' => 'ecommerce', 'action' => 'list_stores', 'fallback' => true, 'ambiguity' => false, 'latency' => 170, 'status' => 'resolved'],
        ['session' => 'sess_a3', 'module' => 'agent_tools', 'action' => 'resolve_tool_for_request', 'fallback' => true, 'ambiguity' => true, 'latency' => 220, 'status' => 'ambiguous'],
        ['session' => 'sess_a4', 'module' => 'usage_metering', 'action' => 'get_summary', 'fallback' => true, 'ambiguity' => false, 'latency' => 320, 'status' => 'resolved'],
        ['session' => 'sess_a5', 'module' => 'ecommerce', 'action' => 'validate_connection', 'fallback' => false, 'ambiguity' => false, 'latency' => 180, 'status' => 'blocked'],
        ['session' => 'sess_a6', 'module' => 'agentops_observability', 'action' => 'get_metrics_summary', 'fallback' => false, 'ambiguity' => false, 'latency' => 160, 'status' => 'success'],
    ] as $trace) {
        $service->recordDecisionTrace([
            'tenant_id' => $tenantAlpha,
            'project_id' => $projectId,
            'session_id' => $trace['session'],
            'route_path' => 'cache>rules>skills',
            'selected_module' => $trace['module'],
            'selected_action' => $trace['action'],
            'evidence_source' => $trace['fallback'] ? 'llm' : 'skills',
            'ambiguity_detected' => $trace['ambiguity'],
            'fallback_llm' => $trace['fallback'],
            'latency_ms' => $trace['latency'],
            'result_status' => $trace['status'],
            'metadata_json' => ['seed' => 'alpha'],
        ]);
    }

    foreach ([
        ['module' => 'ecommerce', 'action' => 'validate_connection', 'schema' => true, 'permission' => 'deny', 'plan' => 'disabled', 'latency' => 1500, 'success' => false, 'error' => 'module_disabled_by_plan'],
        ['module' => 'pos', 'action' => 'finalize_sale', 'schema' => true, 'permission' => 'deny', 'plan' => 'enabled', 'latency' => 130, 'success' => false, 'error' => 'permission_denied'],
        ['module' => 'agent_tools', 'action' => 'check_action_allowed', 'schema' => true, 'permission' => 'deny', 'plan' => 'enabled', 'latency' => 90, 'success' => false, 'error' => 'permission_denied'],
        ['module' => 'agentops', 'action' => 'get_metrics_summary', 'schema' => true, 'permission' => 'allow', 'plan' => 'enabled', 'latency' => 70, 'success' => true, 'error' => null],
    ] as $trace) {
        $service->recordToolExecutionTrace([
            'tenant_id' => $tenantAlpha,
            'project_id' => $projectId,
            'module_key' => $trace['module'],
            'action_key' => $trace['action'],
            'input_schema_valid' => $trace['schema'],
            'permission_check' => $trace['permission'],
            'plan_check' => $trace['plan'],
            'execution_latency' => $trace['latency'],
            'success' => $trace['success'],
            'error_code' => $trace['error'],
            'metadata_json' => ['seed' => 'alpha'],
        ]);
    }

    $service->recordDecisionTrace([
        'tenant_id' => $tenantBeta,
        'project_id' => $projectId,
        'session_id' => 'sess_beta',
        'route_path' => 'cache>rules',
        'selected_module' => 'media',
        'selected_action' => 'list',
        'evidence_source' => 'rules',
        'ambiguity_detected' => false,
        'fallback_llm' => false,
        'latency_ms' => 60,
        'result_status' => 'resolved',
    ]);
    $service->recordToolExecutionTrace([
        'tenant_id' => $tenantBeta,
        'project_id' => $projectId,
        'module_key' => 'media',
        'action_key' => 'list',
        'input_schema_valid' => true,
        'permission_check' => 'allow',
        'plan_check' => 'enabled',
        'execution_latency' => 40,
        'success' => true,
        'error_code' => null,
    ]);

    $decisions = $service->listRecentDecisions($tenantAlpha, $projectId);
    if ((int) ($decisions['result_count'] ?? 0) !== 6) {
        $failures[] = 'listRecentDecisions debe devolver las trazas del tenant activo.';
    }

    $tools = $service->listToolExecutions($tenantAlpha, $projectId);
    if ((int) ($tools['result_count'] ?? 0) !== 4) {
        $failures[] = 'listToolExecutions debe devolver las ejecuciones del tenant activo.';
    }

    $summary = $service->getMetricsSummary($tenantAlpha, $projectId, 7);
    $metricsSummary = is_array($summary['metrics_summary'] ?? null) ? (array) $summary['metrics_summary'] : [];
    $moduleUsage = is_array($metricsSummary['module_usage'] ?? null) ? (array) $metricsSummary['module_usage'] : [];
    $moduleKeys = array_map(static fn(array $item): string => (string) ($item['module_key'] ?? ''), $moduleUsage);
    if (!in_array('ecommerce', $moduleKeys, true)) {
        $failures[] = 'El resumen debe agregar uso por modulo.';
    }
    if ((float) ($metricsSummary['fallback_rate'] ?? 0.0) < 0.4) {
        $failures[] = 'El resumen debe calcular fallback_rate desde decision traces.';
    }
    if ((int) ($metricsSummary['permission_denials'] ?? 0) < 3) {
        $failures[] = 'El resumen debe calcular permission_denials desde tool traces.';
    }
    if ((float) ($metricsSummary['error_rate'] ?? 0.0) <= 0.5) {
        $failures[] = 'El resumen debe calcular error_rate desde tool traces.';
    }

    $anomalies = $service->getAnomalyFlags($tenantAlpha, $projectId, 7);
    $flagKeys = array_map(static fn(array $item): string => (string) ($item['flag_key'] ?? ''), (array) ($anomalies['anomaly_flags'] ?? []));
    foreach (['high_fallback_llm_rate', 'repeated_permission_denials', 'repeated_tool_failures', 'latency_spike'] as $flagKey) {
        if (!in_array($flagKey, $flagKeys, true)) {
            $failures[] = 'La deteccion de anomalias debe emitir ' . $flagKey . '.';
        }
    }

    $betaSummary = $service->getMetricsSummary($tenantBeta, $projectId, 7);
    if ((int) (($betaSummary['legacy_summary']['intent_metrics']['count'] ?? 0)) !== 0) {
        $failures[] = 'El resumen beta no debe mezclar intent metrics del tenant alpha.';
    }
    if ((int) (($betaSummary['metrics_summary']['permission_denials'] ?? 0)) !== 0) {
        $failures[] = 'El resumen beta no debe mezclar permission denials del tenant alpha.';
    }
} catch (Throwable $e) {
    $failures[] = 'La fundacion AgentOps debe ejecutarse sin errores: ' . $e->getMessage();
}

try {
    $service->getMetricsSummary($tenantAlpha, $projectId, 7, 'bogus_metric');
    $failures[] = 'Una metrica invalida debe devolver error explicito.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'AGENTOPS_METRIC_KEY_INVALID') {
        $failures[] = 'La metrica invalida debe usar AGENTOPS_METRIC_KEY_INVALID.';
    }
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
