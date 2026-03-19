<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;
use App\Core\ControlTowerFeedManager;
use App\Core\ControlTowerRepository;
use App\Core\SqlMemoryRepository;
use App\Core\TaskExecutionManager;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/conversation_gateway_trace_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);
$dbPath = $tmpDir . '/gateway_trace.sqlite';

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $memory = new SqlMemoryRepository($pdo);
    $repo = new ControlTowerRepository($pdo);
    $feed = new ControlTowerFeedManager($repo);
    $tasks = new TaskExecutionManager($repo, $feed);
    $gateway = new ConversationGateway(PROJECT_ROOT, $memory);

    $validation = $gateway->validateIngressEnvelope([
        'tenant_id' => 'tenant_gateway_alpha',
        'user_id' => 'user_gateway_alpha',
        'project_id' => 'ct_gateway_app',
        'mode' => 'app',
        'message' => 'hola control tower',
        'is_authenticated' => true,
        'auth_tenant_id' => 'tenant_gateway_alpha',
    ]);
    if (($validation['ok'] ?? false) !== true) {
        $failures[] = 'ConversationGateway debe validar un envelope correcto.';
    }

    $task = $tasks->createTask([
        'tenant_id' => 'tenant_gateway_alpha',
        'project_id' => 'ct_gateway_app',
        'app_id' => 'ct_gateway_app',
        'conversation_id' => 'conv_gateway_001',
        'session_id' => 'sess_gateway_001',
        'user_id' => 'user_gateway_alpha',
        'message_id' => 'msg_gateway_001',
        'intent' => 'greeting',
        'source' => 'chat',
        'related_entities' => [],
        'related_events' => [],
        'idempotency_key' => 'msg_gateway_001',
    ]);
    $gateway->linkTaskExecution('tenant_gateway_alpha', 'user_gateway_alpha', 'ct_gateway_app', 'app', $task);

    $result = $gateway->handle('tenant_gateway_alpha', 'user_gateway_alpha', 'hola control tower', 'app', 'ct_gateway_app');
    if (!is_array($result) || trim((string) ($result['action'] ?? '')) === '') {
        $failures[] = 'ConversationGateway.handle debe devolver una accion valida.';
    }

    $gateway->rememberAgentOpsTrace('tenant_gateway_alpha', 'user_gateway_alpha', 'ct_gateway_app', 'app', [
        'route_path' => 'cache>rules',
        'gate_decision' => 'allow',
        'route_reason' => 'deterministic_route_resolved',
        'request_mode' => 'operation',
        'evidence_gate_status' => 'skipped_by_rule',
        'evidence_used' => ['sources_used' => ['rules'], 'source_ids' => ['route_policy']],
        'fallback_reason' => 'none',
        'module_used' => 'control_tower',
        'task_action' => 'trace',
        'latency_ms' => 42,
        'task_id' => (string) ($task['task_id'] ?? ''),
        'conversation_id' => 'conv_gateway_001',
        'result_status' => 'success',
    ]);

    $state = $memory->getUserMemory('tenant_gateway_alpha', 'user_gateway_alpha', 'state::ct_gateway_app::app', []);
    $lastTrace = is_array($state['agentops_last_trace'] ?? null) ? (array) $state['agentops_last_trace'] : [];
    $lastTask = is_array($state['control_tower_last_task'] ?? null) ? (array) $state['control_tower_last_task'] : [];

    if ((string) ($lastTrace['route_path'] ?? '') !== 'cache>rules') {
        $failures[] = 'ConversationGateway debe guardar route_path en agentops_last_trace.';
    }
    if ((string) ($lastTrace['gate_decision'] ?? '') !== 'allow') {
        $failures[] = 'ConversationGateway debe guardar gate_decision en agentops_last_trace.';
    }
    if ((int) ($lastTrace['latency_ms'] ?? -1) !== 42) {
        $failures[] = 'ConversationGateway debe guardar latency_ms en agentops_last_trace.';
    }
    if ((string) ($lastTrace['task_id'] ?? '') !== (string) ($task['task_id'] ?? '')) {
        $failures[] = 'ConversationGateway debe enlazar task_id en agentops_last_trace.';
    }
    if ((string) ($lastTrace['conversation_id'] ?? '') !== 'conv_gateway_001') {
        $failures[] = 'ConversationGateway debe enlazar conversation_id en agentops_last_trace.';
    }
    if ((string) ($lastTask['task_id'] ?? '') !== (string) ($task['task_id'] ?? '')) {
        $failures[] = 'ConversationGateway debe guardar control_tower_last_task.';
    }
} catch (Throwable $e) {
    $failures[] = 'Conversation gateway trace test no debe lanzar excepciones: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    if ($value === false) {
        putenv($key);
    } else {
        putenv($key . '=' . $value);
    }
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
