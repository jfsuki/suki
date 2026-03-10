<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AlertsCenterCommandHandler;
use App\Core\AlertsCenterContractValidator;
use App\Core\AlertsCenterRepository;
use App\Core\AlertsCenterService;
use App\Core\ChatAgent;
use App\Core\CommandBus;
use App\Core\ContractRegistry;
use App\Core\Database;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/alerts_center_module_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);

$previousAppEnv = getenv('APP_ENV');
$previousAllowSchema = getenv('ALLOW_RUNTIME_SCHEMA');
$previousRegistry = getenv('PROJECT_REGISTRY_DB_PATH');

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('PROJECT_REGISTRY_DB_PATH=' . $tmpDir . '/project_registry.sqlite');

$pdo = new PDO('sqlite:' . $tmpDir . '/alerts_center.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$repository = new AlertsCenterRepository($pdo);
$service = new AlertsCenterService($repository);

try {
    AlertsCenterContractValidator::validateAlert([
        'id' => '',
        'tenant_id' => 'tenant_schema',
        'app_id' => 'app_schema',
        'alert_type' => 'manual_alert',
        'title' => 'Factura vencida',
        'message' => 'La factura 001 esta vencida.',
        'severity' => 'high',
        'source_type' => 'manual',
        'source_ref' => null,
        'status' => 'open',
        'created_at' => '2026-03-10 08:00:00',
        'due_at' => null,
        'metadata' => [],
    ]);
    AlertsCenterContractValidator::validateTask([
        'id' => '',
        'tenant_id' => 'tenant_schema',
        'app_id' => 'app_schema',
        'task_type' => 'follow_up',
        'title' => 'Llamar cliente',
        'description' => 'Confirmar pago pendiente.',
        'assigned_to' => null,
        'priority' => 'high',
        'status' => 'pending',
        'due_at' => null,
        'related_entity_type' => null,
        'related_entity_id' => null,
        'created_at' => '2026-03-10 08:00:00',
        'metadata' => [],
    ]);
    AlertsCenterContractValidator::validateReminder([
        'id' => '',
        'tenant_id' => 'tenant_schema',
        'app_id' => 'app_schema',
        'reminder_type' => 'follow_up',
        'title' => 'Cobrar factura',
        'message' => 'Cobrar factura 001.',
        'remind_at' => '2026-03-15 09:00:00',
        'status' => 'pending',
        'related_entity_type' => null,
        'related_entity_id' => null,
        'created_at' => '2026-03-10 08:00:00',
        'metadata' => [],
    ]);
} catch (Throwable $e) {
    $failures[] = 'Los contratos base de Alerts Center deben validar: ' . $e->getMessage();
}

try {
    $taskA = $service->createTask([
        'tenant_id' => 'tenant_alpha',
        'app_id' => 'app_alpha',
        'task_type' => 'follow_up',
        'title' => 'Llamar cliente A',
        'description' => 'Confirmar pago de factura.',
        'priority' => 'high',
    ]);
    $reminderA = $service->createReminder([
        'tenant_id' => 'tenant_alpha',
        'app_id' => 'app_alpha',
        'reminder_type' => 'follow_up',
        'title' => 'Cobrar factura A',
        'message' => 'Cobrar factura A-100.',
        'remind_at' => '2026-03-15 09:00:00',
    ]);
    $alertA = $service->createAlert([
        'tenant_id' => 'tenant_alpha',
        'app_id' => 'app_alpha',
        'alert_type' => 'manual_alert',
        'title' => 'Factura vencida A',
        'message' => 'Cliente A sigue pendiente.',
        'severity' => 'high',
        'source_type' => 'manual',
    ]);
    $service->createTask([
        'tenant_id' => 'tenant_beta',
        'app_id' => 'app_beta',
        'task_type' => 'follow_up',
        'title' => 'Llamar cliente B',
        'description' => 'No debe verse en tenant A.',
        'priority' => 'medium',
    ]);

    $pendingA = $service->listPendingTasks('tenant_alpha', ['app_id' => 'app_alpha']);
    if (count($pendingA) !== 1 || (string) ($pendingA[0]['title'] ?? '') !== 'Llamar cliente A') {
        $failures[] = 'listPendingTasks debe devolver la tarea de tenant/app correctos.';
    }

    $pendingB = $service->listPendingTasks('tenant_beta', ['app_id' => 'app_beta']);
    if (count($pendingB) !== 1 || (string) ($pendingB[0]['title'] ?? '') !== 'Llamar cliente B') {
        $failures[] = 'La tarea del tenant B debe permanecer aislada.';
    }

    $updatedTask = $service->completeTask('tenant_alpha', (string) ($taskA['id'] ?? ''));
    if ((string) ($updatedTask['status'] ?? '') !== 'completed') {
        $failures[] = 'completeTask debe marcar completed.';
    }

    $updatedAlert = $service->resolveAlert('tenant_alpha', (string) ($alertA['id'] ?? ''));
    if ((string) ($updatedAlert['status'] ?? '') !== 'resolved') {
        $failures[] = 'resolveAlert debe marcar resolved.';
    }

    $updatedReminder = $service->completeReminder('tenant_alpha', (string) ($reminderA['id'] ?? ''));
    if ((string) ($updatedReminder['status'] ?? '') !== 'completed') {
        $failures[] = 'completeReminder debe marcar completed.';
    }

    $sampleAlert1 = $service->emitSampleLowStockAlert('tenant_alpha', 'app_alpha', 'SKU-LOW', 2, 5);
    $sampleAlert2 = $service->emitSampleLowStockAlert('tenant_alpha', 'app_alpha', 'SKU-LOW', 2, 5);
    if ((string) ($sampleAlert1['id'] ?? '') === '' || (string) ($sampleAlert1['id'] ?? '') !== (string) ($sampleAlert2['id'] ?? '')) {
        $failures[] = 'emitSampleLowStockAlert debe deduplicar alertas abiertas por source_ref.';
    }
} catch (Throwable $e) {
    $failures[] = 'El servicio de Alerts Center debe crear/listar/actualizar correctamente: ' . $e->getMessage();
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $registry = new SkillRegistry($catalog);
    $resolvedTask = $resolver->resolve('crear tarea titulo="Llamar cliente" prioridad=alta', $registry, []);
    $resolvedReminder = $resolver->resolve('crear recordatorio titulo="Cobrar factura" remind_at="2026-03-15 09:00"', $registry, []);
    $resolvedAlert = $resolver->resolve('crear alerta titulo="Factura vencida" severidad=alta', $registry, []);
    $resolvedList = $resolver->resolve('listar tareas pendientes', $registry, []);

    if ((string) (($resolvedTask['selected']['name'] ?? '') ?: '') !== 'create_task') {
        $failures[] = 'SkillResolver debe detectar create_task.';
    }
    if ((string) (($resolvedReminder['selected']['name'] ?? '') ?: '') !== 'create_reminder') {
        $failures[] = 'SkillResolver debe detectar create_reminder.';
    }
    if ((string) (($resolvedAlert['selected']['name'] ?? '') ?: '') !== 'create_alert') {
        $failures[] = 'SkillResolver debe detectar create_alert.';
    }
    if ((string) (($resolvedList['selected']['name'] ?? '') ?: '') !== 'list_pending_tasks') {
        $failures[] = 'SkillResolver debe detectar list_pending_tasks.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo de skills de Alerts Center debe cargar y resolver: ' . $e->getMessage();
}

try {
    $bus = new CommandBus();
    $bus->register(new AlertsCenterCommandHandler());
    $replyFn = static fn(
        string $text,
        string $channel,
        string $sessionId,
        string $userId,
        string $status = 'success',
        array $data = []
    ): array => [
        'status' => $status,
        'reply' => $text,
        'data' => array_merge([
            'reply' => $text,
            'channel' => $channel,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ], $data),
    ];

    $builderGuard = $bus->dispatch([
        'command' => 'CreateTask',
        'tenant_id' => 'tenant_guard',
        'title' => 'No crear en builder',
        'description' => 'Guard',
        'task_type' => 'follow_up',
        'priority' => 'medium',
    ], [
        'mode' => 'builder',
        'channel' => 'test',
        'session_id' => 'sess_builder_guard',
        'user_id' => 'user_builder_guard',
        'reply' => $replyFn,
        'alerts_center_service' => $service,
    ]);

    if ((string) ($builderGuard['status'] ?? '') !== 'error') {
        $failures[] = 'AlertsCenterCommandHandler debe bloquear builder mode.';
    }
} catch (Throwable $e) {
    $failures[] = 'El handler del modulo debe mantener guardias de modo: ' . $e->getMessage();
}

try {
    $agent = new ChatAgent();
    $tenantId = 'tenant_ops_chat';
    $projectId = 'alerts_center_app';
    $sessionBase = 'alerts_center_chat_' . time();

    $taskMessageId = 'alerts_task_' . time() . '_' . random_int(1000, 9999);
    $taskReply = $agent->handle([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionBase . '_task',
        'user_id' => 'operator_ops',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message_id' => $taskMessageId,
        'message' => 'crear tarea titulo="Llamar cliente" prioridad=alta',
    ]);
    $taskData = is_array($taskReply['data'] ?? null) ? (array) $taskReply['data'] : [];
    if ((string) ($taskReply['status'] ?? '') !== 'success' || (string) ($taskData['module_used'] ?? '') !== 'alerts_center' || (string) ($taskData['task_action'] ?? '') !== 'create') {
        $failures[] = 'ChatAgent debe ejecutar create_task via skills y CommandBus.';
    }

    $listMessageId = 'alerts_list_' . time() . '_' . random_int(1000, 9999);
    $listReply = $agent->handle([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionBase . '_list',
        'user_id' => 'operator_ops',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message_id' => $listMessageId,
        'message' => 'listar tareas pendientes',
    ]);
    $listData = is_array($listReply['data'] ?? null) ? (array) $listReply['data'] : [];
    if ((string) ($listReply['status'] ?? '') !== 'success' || (string) ($listData['task_action'] ?? '') !== 'list_pending' || (int) ($listData['pending_items_count'] ?? 0) < 1) {
        $failures[] = 'ChatAgent debe listar tareas pendientes via skill list_pending_tasks.';
    }

    $reminderMessageId = 'alerts_reminder_' . time() . '_' . random_int(1000, 9999);
    $reminderReply = $agent->handle([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionBase . '_reminder',
        'user_id' => 'operator_ops',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message_id' => $reminderMessageId,
        'message' => 'crear recordatorio titulo="Cobrar factura" remind_at="2026-03-15 09:00"',
    ]);
    $reminderData = is_array($reminderReply['data'] ?? null) ? (array) $reminderReply['data'] : [];
    if ((string) ($reminderReply['status'] ?? '') !== 'success' || (string) ($reminderData['reminder_action'] ?? '') !== 'create') {
        $failures[] = 'ChatAgent debe ejecutar create_reminder via skills.';
    }

    $alertMessageId = 'alerts_alert_' . time() . '_' . random_int(1000, 9999);
    $alertReply = $agent->handle([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionBase . '_alert',
        'user_id' => 'operator_ops',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message_id' => $alertMessageId,
        'message' => 'crear alerta titulo="Factura vencida" severidad=alta',
    ]);
    $alertData = is_array($alertReply['data'] ?? null) ? (array) $alertReply['data'] : [];
    if ((string) ($alertReply['status'] ?? '') !== 'success' || (string) ($alertData['alert_action'] ?? '') !== 'create') {
        $failures[] = 'ChatAgent debe ejecutar create_alert via skills.';
    }

    $fallbackMessageId = 'alerts_fallback_' . time() . '_' . random_int(1000, 9999);
    $fallbackReply = $agent->handle([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionBase . '_fallback',
        'user_id' => 'operator_ops',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message_id' => $fallbackMessageId,
        'message' => 'crear recordatorio titulo="Cobrar factura sin fecha"',
    ]);
    $fallbackText = (string) (($fallbackReply['data']['reply'] ?? $fallbackReply['reply'] ?? ''));
    if (stripos($fallbackText, 'remind_at=') === false) {
        $failures[] = 'El fallback controlado de create_reminder debe pedir remind_at.';
    }

    $telemetryEvents = readTelemetryEvents($tenantId);
    $taskEvent = findEventByMessageId($telemetryEvents, $taskMessageId);
    $fallbackEvent = findEventByMessageId($telemetryEvents, $fallbackMessageId);

    if (!is_array($taskEvent) || (string) ($taskEvent['module_used'] ?? '') !== 'alerts_center' || (string) ($taskEvent['task_action'] ?? '') !== 'create') {
        $failures[] = 'AgentOps debe emitir module_used/task_action para create_task.';
    }
    if (!is_array($taskEvent) || !array_key_exists('supervisor_status', $taskEvent)) {
        $failures[] = 'AgentOps debe seguir emitiendo supervisor_status en el modulo.';
    }
    if (!is_array($fallbackEvent) || (string) ($fallbackEvent['module_used'] ?? '') !== 'alerts_center' || (string) ($fallbackEvent['reminder_action'] ?? '') !== 'create') {
        $failures[] = 'AgentOps debe emitir markers del modulo tambien en fallback controlado.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat/skills/telemetry de Alerts Center debe pasar: ' . $e->getMessage();
}

restoreEnv('APP_ENV', $previousAppEnv);
restoreEnv('ALLOW_RUNTIME_SCHEMA', $previousAllowSchema);
restoreEnv('PROJECT_REGISTRY_DB_PATH', $previousRegistry);

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @return array<int, array<string, mixed>>
 */
function readTelemetryEvents(string $tenantId): array
{
    $safeTenant = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $tenantId) ?? $tenantId;
    $safeTenant = trim($safeTenant, '_');
    if ($safeTenant === '') {
        $safeTenant = 'default';
    }

    $file = PROJECT_ROOT . '/storage/tenants/' . $safeTenant . '/telemetry/' . date('Y-m-d') . '.log.jsonl';
    if (!is_file($file)) {
        return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $events = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }

    return $events;
}

/**
 * @param array<int, array<string, mixed>> $events
     * @return array<string, mixed>|null
 */
function findEventByMessageId(array $events, string $messageId): ?array
{
    foreach (array_reverse($events) as $event) {
        if (!is_array($event)) {
            continue;
        }
        if ((string) ($event['message_id'] ?? '') !== $messageId) {
            continue;
        }
        if ((string) ($event['event_name'] ?? '') === 'response.emitted') {
            return $event;
        }
    }

    foreach (array_reverse($events) as $event) {
        if (is_array($event) && (string) ($event['message_id'] ?? '') === $messageId) {
            return $event;
        }
    }

    return null;
}

/**
 * @param string|false $previous
 */
function restoreEnv(string $key, $previous): void
{
    if ($previous === false) {
        putenv($key);
        return;
    }

    putenv($key . '=' . $previous);
}
