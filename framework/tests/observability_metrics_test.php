<?php
// framework/tests/observability_metrics_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\SqlMetricsRepository;
use App\Core\TelemetryService;

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
$dbPath = $tmpDir . '/observability_metrics_test.sqlite';
if (is_file($dbPath)) {
    unlink($dbPath);
}

$repo = new SqlMetricsRepository(null, $dbPath);
$service = new TelemetryService($repo);

$tenantId = 'default';
$projectId = 'obs_project';

$service->recordIntentMetric([
    'tenant_id' => $tenantId,
    'project_id' => $projectId,
    'mode' => 'builder',
    'intent' => 'APP_CREATE',
    'action' => 'ask_user',
    'latency_ms' => 80,
    'status' => 'success',
]);
$service->recordIntentMetric([
    'tenant_id' => $tenantId,
    'project_id' => $projectId,
    'mode' => 'builder',
    'intent' => 'APP_CREATE',
    'action' => 'send_to_llm',
    'latency_ms' => 260,
    'status' => 'success',
]);

$service->recordCommandMetric([
    'tenant_id' => $tenantId,
    'project_id' => $projectId,
    'mode' => 'builder',
    'command_name' => 'CreateEntity',
    'latency_ms' => 120,
    'status' => 'success',
    'blocked' => 0,
]);
$service->recordCommandMetric([
    'tenant_id' => $tenantId,
    'project_id' => $projectId,
    'mode' => 'app',
    'command_name' => 'CreateEntity',
    'latency_ms' => 25,
    'status' => 'error',
    'blocked' => 1,
]);
$service->recordGuardrailEvent([
    'tenant_id' => $tenantId,
    'project_id' => $projectId,
    'mode' => 'app',
    'guardrail' => 'mode_guard',
    'reason' => 'Estas en modo app. Usa el chat creador para crear tablas.',
]);

$service->recordTokenUsage([
    'tenant_id' => $tenantId,
    'project_id' => $projectId,
    'provider' => 'gemini',
    'prompt_tokens' => 110,
    'completion_tokens' => 55,
    'total_tokens' => 165,
]);

$summary = $service->summary($tenantId, $projectId, 7);

$failures = [];
if ((int) ($summary['intent_metrics']['count'] ?? 0) < 2) {
    $failures[] = 'intent metrics were not persisted';
}
if ((int) ($summary['intent_metrics']['fallback_llm'] ?? 0) < 1) {
    $failures[] = 'llm fallback metric missing';
}
if ((int) ($summary['command_metrics']['count'] ?? 0) < 2) {
    $failures[] = 'command metrics were not persisted';
}
if ((int) ($summary['command_metrics']['blocked'] ?? 0) < 1) {
    $failures[] = 'blocked command metric missing';
}
if ((int) ($summary['guardrail_events']['count'] ?? 0) < 1) {
    $failures[] = 'guardrail event missing';
}
if ((int) ($summary['token_usage']['total_tokens'] ?? 0) < 165) {
    $failures[] = 'token usage metric missing';
}
if ((float) ($summary['token_usage']['estimated_cost_usd'] ?? 0.0) <= 0.0) {
    $failures[] = 'token cost was not estimated';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'db_path' => $dbPath,
    'summary' => $summary,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
