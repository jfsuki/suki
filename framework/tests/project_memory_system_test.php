<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\Telemetry;
use App\Core\ImprovementMemoryRepository;
use App\Core\ImprovementMemoryService;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/project_memory_system_' . time() . '_' . random_int(1000, 9999);
$tmpProjectRoot = $tmpDir . '/project_root';
@mkdir($tmpProjectRoot . '/storage/meta', 0777, true);
@mkdir($tmpProjectRoot . '/storage/tenants', 0777, true);

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'PROJECT_REGISTRY_DB_PATH' => getenv('PROJECT_REGISTRY_DB_PATH'),
    'AGENTOPS_LATENCY_ANOMALY_MS' => getenv('AGENTOPS_LATENCY_ANOMALY_MS'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('PROJECT_REGISTRY_DB_PATH=' . $tmpDir . '/project_registry.sqlite');
putenv('AGENTOPS_LATENCY_ANOMALY_MS=900');

$repository = new ImprovementMemoryRepository(null, $tmpDir . '/project_registry.sqlite');
$service = new ImprovementMemoryService($repository);
$telemetry = new Telemetry($tmpProjectRoot);

for ($i = 0; $i < 2; $i++) {
    $telemetry->record('tenant_alpha', [
        'tenant_id' => 'tenant_alpha',
        'project_id' => 'memory_app',
        'event_name' => 'response.emitted',
        'message' => 'busca el arroz grande',
        'route_path' => 'cache>rules>rag>tools',
        'module_used' => 'entity_search',
        'entity_search_action' => 'resolve',
        'result_count' => 3,
        'resolved' => false,
        'needs_clarification' => true,
        'llm_used' => false,
        'tool_calls_count' => 1,
        'status' => 'success',
        'latency_ms' => 240,
        'fallback_reason' => 'deterministic_route_resolved',
        'skill_fallback_reason' => 'none',
        'supervisor_flags' => [],
        'error_flag' => false,
    ]);
}

for ($i = 0; $i < 3; $i++) {
    $telemetry->record('tenant_alpha', [
        'tenant_id' => 'tenant_alpha',
        'project_id' => 'memory_app',
        'event_name' => 'response.emitted',
        'message' => 'mira esa venta',
        'route_path' => 'cache>rules>rag>llm',
        'module_used' => 'router',
        'llm_used' => true,
        'llm_fallback_count' => 1,
        'tool_calls_count' => 0,
        'status' => 'success',
        'latency_ms' => 1250,
        'fallback_reason' => 'llm_last_resort_after_rag',
        'skill_fallback_reason' => 'no_skill_match',
        'supervisor_flags' => ['fallback_overuse'],
        'error_flag' => false,
    ]);
}

$service->recordEvent('tool_failure', 'media_storage', [
    'tenant_id' => 'tenant_alpha',
    'project_id' => 'memory_app',
    'error_type' => 'upload_failed',
    'status' => 'error',
]);

$aggregate = $service->aggregateMetrics('tenant_alpha', 30);
$suggestions = $service->suggestImprovements('tenant_alpha', 10);

if ((int) ($aggregate['totals']['improvements'] ?? 0) < 3) {
    $failures[] = 'Debe crear improvement memory para eventos recurrentes.';
}
if ((int) ($aggregate['totals']['pending_candidates'] ?? 0) < 2) {
    $failures[] = 'Debe generar learning candidates pendientes desde eventos recurrentes.';
}

$problemTypes = array_column((array) ($aggregate['by_problem_type'] ?? []), 'problem_type');
if (!in_array('fallback_llm', $problemTypes, true)) {
    $failures[] = 'aggregate_metrics debe incluir fallback_llm.';
}
if (!in_array('ambiguous_request', $problemTypes, true)) {
    $failures[] = 'aggregate_metrics debe incluir ambiguous_request.';
}

$pendingCandidates = (array) ($suggestions['pending_candidates'] ?? []);
$candidateSources = array_column($pendingCandidates, 'source_metric');
if (!in_array('fallback_llm', $candidateSources, true)) {
    $failures[] = 'suggest_improvements debe incluir candidate derivado de fallback_llm.';
}

$moduleRegistryPath = dirname(__DIR__, 2) . '/docs/MODULE_REGISTRY.md';
$architectureIndexPath = dirname(__DIR__, 2) . '/docs/ARCHITECTURE_INDEX.md';

if (!is_file($moduleRegistryPath)) {
    $failures[] = 'MODULE_REGISTRY.md debe existir.';
} else {
    $moduleRegistry = (string) file_get_contents($moduleRegistryPath);
    if (!str_contains($moduleRegistry, 'Semantic Memory')) {
        $failures[] = 'MODULE_REGISTRY.md debe listar Semantic Memory.';
    }
}

if (!is_file($architectureIndexPath)) {
    $failures[] = 'ARCHITECTURE_INDEX.md debe existir.';
} else {
    $architectureIndex = (string) file_get_contents($architectureIndexPath);
    if (!str_contains($architectureIndex, '## Core services')) {
        $failures[] = 'ARCHITECTURE_INDEX.md debe ser accesible y contener Core services.';
    }
}

$isolation = $service->aggregateMetrics('tenant_beta', 30);
if ((int) ($isolation['totals']['improvements'] ?? 0) !== 0) {
    $failures[] = 'Improvement memory debe respetar tenant isolation.';
}

$telemetryLog = $tmpProjectRoot . '/storage/tenants/tenant_alpha/telemetry/' . date('Y-m-d') . '.log.jsonl';
if (!is_file($telemetryLog)) {
    $failures[] = 'Telemetry debe seguir escribiendo log JSONL.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'aggregate' => $aggregate,
    'suggestions' => $suggestions,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

foreach ($previous as $key => $value) {
    if ($value === false) {
        putenv($key);
        continue;
    }
    putenv($key . '=' . $value);
}

exit($ok ? 0 : 1);
