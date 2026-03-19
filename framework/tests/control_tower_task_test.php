<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ControlTowerFeedManager;
use App\Core\ControlTowerRepository;
use App\Core\TaskExecutionManager;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/control_tower_task_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);
$dbPath = $tmpDir . '/control_tower.sqlite';

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');

try {
    $repo = new ControlTowerRepository(null, $dbPath);
    $feed = new ControlTowerFeedManager($repo);
    $manager = new TaskExecutionManager($repo, $feed);

    $task = $manager->createTask([
        'tenant_id' => 'tenant_ct_alpha',
        'project_id' => 'ct_app',
        'app_id' => 'ct_app',
        'conversation_id' => 'conv_ct_001',
        'session_id' => 'sess_ct_001',
        'user_id' => 'user_ct_001',
        'message_id' => 'msg_ct_001',
        'intent' => 'create_invoice',
        'source' => 'chat',
        'related_entities' => [
            ['entity_type' => 'customer', 'entity_id' => 'cust_001', 'tenant_id' => 'tenant_ct_alpha', 'app_id' => 'ct_app'],
        ],
        'related_events' => ['sale_evt_001'],
        'idempotency_key' => 'msg_ct_001',
    ]);

    $duplicate = $manager->createTask([
        'tenant_id' => 'tenant_ct_alpha',
        'project_id' => 'ct_app',
        'app_id' => 'ct_app',
        'conversation_id' => 'conv_ct_001',
        'session_id' => 'sess_ct_001',
        'user_id' => 'user_ct_001',
        'message_id' => 'msg_ct_001',
        'intent' => 'create_invoice',
        'source' => 'chat',
        'related_entities' => [],
        'related_events' => [],
        'idempotency_key' => 'msg_ct_001',
    ]);

    if ((string) ($task['task_id'] ?? '') === '' || (string) ($task['task_id'] ?? '') !== (string) ($duplicate['task_id'] ?? '')) {
        $failures[] = 'TaskExecutionManager debe aplicar idempotencia por message_id/idempotency_key.';
    }

    $running = $manager->updateTask('tenant_ct_alpha', (string) $task['task_id'], ['status' => 'running']);
    $completed = $manager->attachExecutionResult('tenant_ct_alpha', (string) $task['task_id'], [
        'result_status' => 'success',
        'response_kind' => 'execute_command',
    ]);
    $completed = $manager->updateTask('tenant_ct_alpha', (string) $task['task_id'], ['status' => 'completed']);

    if ((string) ($running['status'] ?? '') !== 'running') {
        $failures[] = 'TaskExecutionManager debe permitir transicion pending->running.';
    }
    if ((string) ($completed['status'] ?? '') !== 'completed') {
        $failures[] = 'TaskExecutionManager debe permitir transicion running->completed.';
    }

    $qualityGate = $manager->evaluateQualityGates([
        'tenant_id' => 'tenant_ct_alpha',
        'auth_tenant_id' => 'tenant_ct_alpha',
        'action' => 'execute_command',
        'command' => ['command' => 'CreateRecord', 'data' => ['name' => 'Ana']],
        'action_contract' => 'crud.create',
        'route_telemetry' => [
            'action_contract' => 'crud.create',
            'evidence_status' => ['missing' => []],
        ],
    ]);
    if (($qualityGate['ok'] ?? false) !== true) {
        $failures[] = 'Quality gates validos no deben bloquear una accion permitida.';
    }

    $invalidQualityGate = $manager->evaluateQualityGates([
        'tenant_id' => 'tenant_ct_alpha',
        'auth_tenant_id' => 'tenant_other',
        'action' => 'execute_command',
        'command' => ['command' => ''],
        'action_contract' => 'unknown.action',
        'route_telemetry' => [
            'action_contract' => 'unknown.action',
            'evidence_status' => ['missing' => ['sql_ref']],
        ],
    ]);
    if (($invalidQualityGate['ok'] ?? true) !== false) {
        $failures[] = 'Quality gates invalidos deben bloquear la ejecucion.';
    }

    $otherTenantTask = $manager->getTask('tenant_ct_beta', (string) $task['task_id']);
    if ($otherTenantTask !== null) {
        $failures[] = 'TaskExecutionManager no debe mezclar tareas entre tenants.';
    }

    $events = $feed->listEvents('tenant_ct_alpha', 'ct_app', ['event_type' => 'task_update'], 10);
    if (count($events) < 3) {
        $failures[] = 'Control Tower feed debe registrar task_update en create/update/complete.';
    }
} catch (Throwable $e) {
    $failures[] = 'Control Tower task test no debe lanzar excepciones: ' . $e->getMessage();
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
