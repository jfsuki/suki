<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ControlTowerFeedManager;
use App\Core\ControlTowerRepository;
use App\Core\IncidentManager;
use App\Core\TaskExecutionManager;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/incident_flow_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);
$dbPath = $tmpDir . '/incident_flow.sqlite';

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');

try {
    $repo = new ControlTowerRepository(null, $dbPath);
    $feed = new ControlTowerFeedManager($repo);
    $tasks = new TaskExecutionManager($repo, $feed);
    $incidents = new IncidentManager($repo, $feed);

    $task = $tasks->createTask([
        'tenant_id' => 'tenant_incident_alpha',
        'project_id' => 'ct_incident_app',
        'app_id' => 'ct_incident_app',
        'conversation_id' => 'conv_incident_001',
        'session_id' => 'sess_incident_001',
        'user_id' => 'user_incident_001',
        'message_id' => 'msg_incident_001',
        'intent' => 'finalize_sale',
        'source' => 'chat',
        'related_entities' => [],
        'related_events' => ['sale_evt_001'],
        'idempotency_key' => 'msg_incident_001',
    ]);
    $task = $tasks->updateTask('tenant_incident_alpha', (string) $task['task_id'], ['status' => 'running']);
    $task = $tasks->updateTask('tenant_incident_alpha', (string) $task['task_id'], ['status' => 'failed']);

    $incident = $incidents->createFromTaskFailure($task, [
        'error_type' => 'command_failed',
        'description' => 'Sale execution failed for missing inventory evidence.',
        'created_at' => date('c'),
    ]);

    if ((string) ($incident['related_task_id'] ?? '') !== (string) ($task['task_id'] ?? '')) {
        $failures[] = 'IncidentManager debe enlazar related_task_id con la tarea fallida.';
    }
    if ((string) ($incident['severity'] ?? '') !== 'warning') {
        $failures[] = 'IncidentManager debe asignar severidad warning para command_failed.';
    }

    $events = $feed->listEvents('tenant_incident_alpha', 'ct_incident_app', ['event_type' => 'incident_created'], 10);
    if (count($events) < 1) {
        $failures[] = 'Control Tower feed debe emitir incident_created.';
    }

    $otherTenantIncidents = $incidents->listIncidents('tenant_incident_beta', 'ct_incident_app', [], 10);
    if ($otherTenantIncidents !== []) {
        $failures[] = 'IncidentManager no debe mezclar incidentes entre tenants.';
    }

    try {
        $incidents->createIncident([
            'tenant_id' => 'tenant_incident_beta',
            'project_id' => 'ct_incident_app',
            'app_id' => 'ct_incident_app',
            'severity' => 'warning',
            'source' => 'system',
            'related_task_id' => (string) ($task['task_id'] ?? ''),
            'related_events' => [],
            'status' => 'open',
            'description' => 'Cross tenant link attempt.',
            'created_at' => date('c'),
        ]);
        $failures[] = 'IncidentManager debe bloquear enlaces cross-tenant.';
    } catch (Throwable $e) {
        if (!in_array((string) $e->getMessage(), ['CONTROL_TOWER_INCIDENT_TASK_SCOPE_INVALID', 'CONTROL_TOWER_INCIDENT_CROSS_TENANT_LINK'], true)) {
            $failures[] = 'Cross-tenant incident debe fallar con error controlado.';
        }
    }
} catch (Throwable $e) {
    $failures[] = 'Incident flow test no debe lanzar excepciones: ' . $e->getMessage();
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
