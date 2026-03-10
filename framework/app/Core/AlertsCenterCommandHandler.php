<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class AlertsCenterCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'CreateTask',
        'ListPendingTasks',
        'UpdateTaskStatus',
        'CreateReminder',
        'ListReminders',
        'UpdateReminderStatus',
        'CreateAlert',
        'ListAlerts',
        'UpdateAlertStatus',
        'FetchPendingOperationalItems',
    ];

    public function supports(string $commandName): bool
    {
        return in_array($commandName, self::SUPPORTED, true);
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = strtolower(trim((string) ($context['mode'] ?? 'app')));
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');
        $tenantId = trim((string) ($command['tenant_id'] ?? $context['tenant_id'] ?? ''));
        $appId = trim((string) ($command['app_id'] ?? $context['project_id'] ?? ''));

        if ($mode === 'builder') {
            return $this->withReplyText($reply(
                'Estas en modo creador. Usa el chat de la app para operar alertas, tareas y recordatorios.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }

        $service = $context['alerts_center_service'] ?? null;
        if (!$service instanceof AlertsCenterService) {
            $service = new AlertsCenterService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'CreateTask' => $this->handleCreateTask($service, $command, $reply, $channel, $sessionId, $userId),
                'ListPendingTasks' => $this->handleListPendingTasks($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'UpdateTaskStatus' => $this->handleUpdateTaskStatus($service, $tenantId, $command, $reply, $channel, $sessionId, $userId),
                'CreateReminder' => $this->handleCreateReminder($service, $command, $reply, $channel, $sessionId, $userId),
                'ListReminders' => $this->handleListReminders($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'UpdateReminderStatus' => $this->handleUpdateReminderStatus($service, $tenantId, $command, $reply, $channel, $sessionId, $userId),
                'CreateAlert' => $this->handleCreateAlert($service, $command, $reply, $channel, $sessionId, $userId),
                'ListAlerts' => $this->handleListAlerts($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'UpdateAlertStatus' => $this->handleUpdateAlertStatus($service, $tenantId, $command, $reply, $channel, $sessionId, $userId),
                'FetchPendingOperationalItems' => $this->handleFetchPendingItems($service, $tenantId, $appId, $reply, $channel, $sessionId, $userId),
                default => throw new RuntimeException('COMMAND_NOT_SUPPORTED'),
            };
        } catch (Throwable $e) {
            return $this->withReplyText($reply(
                $this->humanizeError($e->getMessage()),
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleCreateTask(
        AlertsCenterService $service,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $task = $service->createTask($command);
        return $this->withReplyText($reply(
            'Tarea creada: ' . (string) ($task['title'] ?? '') . '. Estado pendiente.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'task_action' => 'create',
                'item' => $task,
                'task' => $task,
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleListPendingTasks(
        AlertsCenterService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $filters = [
            'app_id' => $appId !== '' ? $appId : null,
            'statuses' => is_array($command['statuses'] ?? null) ? (array) $command['statuses'] : ['pending', 'in_progress'],
        ];
        if (trim((string) ($command['assigned_to'] ?? '')) !== '') {
            $filters['assigned_to'] = (string) $command['assigned_to'];
        }

        $items = $service->listPendingTasks($tenantId, $filters);
        $text = $items === []
            ? 'No hay tareas pendientes.'
            : "Tareas pendientes:\n" . implode("\n", array_map([$this, 'formatTaskLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'task_action' => 'list_pending',
                'items' => $items,
                'pending_items_count' => count($items),
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleUpdateTaskStatus(
        AlertsCenterService $service,
        string $tenantId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $task = $service->updateTaskStatus($tenantId, (string) ($command['id'] ?? ''), (string) ($command['status'] ?? ''));
        return $this->withReplyText($reply(
            'Tarea actualizada a ' . (string) ($task['status'] ?? '') . ': ' . (string) ($task['title'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'task_action' => 'update_status',
                'item' => $task,
                'task' => $task,
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleCreateReminder(
        AlertsCenterService $service,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $reminder = $service->createReminder($command);
        return $this->withReplyText($reply(
            'Recordatorio creado: ' . (string) ($reminder['title'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'reminder_action' => 'create',
                'item' => $reminder,
                'reminder' => $reminder,
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleListReminders(
        AlertsCenterService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $filters = [
            'app_id' => $appId !== '' ? $appId : null,
            'statuses' => is_array($command['statuses'] ?? null) ? (array) $command['statuses'] : ['pending'],
        ];
        $items = $service->listReminders($tenantId, $filters);
        $text = $items === []
            ? 'No hay recordatorios en ese estado.'
            : "Recordatorios:\n" . implode("\n", array_map([$this, 'formatReminderLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'reminder_action' => 'list',
                'items' => $items,
                'pending_items_count' => count($items),
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleUpdateReminderStatus(
        AlertsCenterService $service,
        string $tenantId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $reminder = $service->updateReminderStatus($tenantId, (string) ($command['id'] ?? ''), (string) ($command['status'] ?? ''));
        return $this->withReplyText($reply(
            'Recordatorio actualizado a ' . (string) ($reminder['status'] ?? '') . ': ' . (string) ($reminder['title'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'reminder_action' => 'update_status',
                'item' => $reminder,
                'reminder' => $reminder,
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleCreateAlert(
        AlertsCenterService $service,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $alert = $service->createAlert($command);
        return $this->withReplyText($reply(
            'Alerta creada: ' . (string) ($alert['title'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'alert_action' => 'create',
                'item' => $alert,
                'alert' => $alert,
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleListAlerts(
        AlertsCenterService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $filters = [
            'app_id' => $appId !== '' ? $appId : null,
            'statuses' => is_array($command['statuses'] ?? null) ? (array) $command['statuses'] : ['open', 'acknowledged'],
        ];
        if (trim((string) ($command['severity'] ?? '')) !== '') {
            $filters['severity'] = (string) $command['severity'];
        }

        $items = $service->listAlerts($tenantId, $filters);
        $text = $items === []
            ? 'No hay alertas en ese estado.'
            : "Alertas:\n" . implode("\n", array_map([$this, 'formatAlertLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'alert_action' => 'list',
                'items' => $items,
                'pending_items_count' => count($items),
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleUpdateAlertStatus(
        AlertsCenterService $service,
        string $tenantId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $alert = $service->updateAlertStatus($tenantId, (string) ($command['id'] ?? ''), (string) ($command['status'] ?? ''));
        return $this->withReplyText($reply(
            'Alerta actualizada a ' . (string) ($alert['status'] ?? '') . ': ' . (string) ($alert['title'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'alert_action' => 'update_status',
                'item' => $alert,
                'alert' => $alert,
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleFetchPendingItems(
        AlertsCenterService $service,
        string $tenantId,
        string $appId,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $summary = $service->fetchPendingItemsByTenant($tenantId, $appId !== '' ? $appId : null);
        $count = (int) ($summary['pending_items_count'] ?? 0);
        return $this->withReplyText($reply(
            'Centro operativo pendiente: ' . $count . ' items.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pending_items_count' => $count,
                'items' => $summary,
            ])
        ));
    }

    private function replyCallable(array $context): callable
    {
        $callable = $context['reply'] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function moduleData(array $overrides = []): array
    {
        return array_merge([
            'module_used' => 'alerts_center',
            'task_action' => 'none',
            'reminder_action' => 'none',
            'alert_action' => 'none',
            'pending_items_count' => null,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function withReplyText(array $response): array
    {
        if (!array_key_exists('reply', $response)) {
            $response['reply'] = (string) (($response['data']['reply'] ?? $response['message'] ?? ''));
        }
        return $response;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function formatTaskLine(array $task): string
    {
        $parts = ['- ' . (string) ($task['title'] ?? 'Tarea')];
        if (trim((string) ($task['priority'] ?? '')) !== '') {
            $parts[] = '[' . (string) $task['priority'] . ']';
        }
        if (trim((string) ($task['status'] ?? '')) !== '') {
            $parts[] = '(' . (string) $task['status'] . ')';
        }
        if (trim((string) ($task['due_at'] ?? '')) !== '') {
            $parts[] = 'vence ' . (string) $task['due_at'];
        }
        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $reminder
     */
    private function formatReminderLine(array $reminder): string
    {
        return '- ' . (string) ($reminder['title'] ?? 'Recordatorio') . ' @ ' . (string) ($reminder['remind_at'] ?? '');
    }

    /**
     * @param array<string, mixed> $alert
     */
    private function formatAlertLine(array $alert): string
    {
        return '- ' . (string) ($alert['title'] ?? 'Alerta') . ' [' . (string) ($alert['severity'] ?? 'medium') . '] (' . (string) ($alert['status'] ?? 'open') . ')';
    }

    private function humanizeError(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'No pude procesar la operacion del Centro de Alertas.';
        }
        return $message;
    }
}
