<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use Throwable;

final class AlertsCenterMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $metadata = [
            'origin' => 'skill',
            'skill_name' => $skillName,
            'channel' => trim((string) ($context['channel'] ?? 'local')) ?: 'local',
            'message_id' => trim((string) ($context['message_id'] ?? '')),
        ];
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'app_id' => trim((string) ($context['project_id'] ?? '')) ?: null,
            'metadata' => $metadata,
        ];

        return match ($skillName) {
            'create_task' => $this->parseCreateTask($message, $pairs, $baseCommand),
            'list_pending_tasks' => $this->parseListPendingTasks($message, $pairs, $baseCommand),
            'create_reminder' => $this->parseCreateReminder($message, $pairs, $baseCommand),
            'list_reminders' => $this->parseListReminders($message, $pairs, $baseCommand),
            'create_alert' => $this->parseCreateAlert($message, $pairs, $baseCommand),
            'list_alerts' => $this->parseListAlerts($message, $pairs, $baseCommand),
            default => [
                'kind' => 'ask_user',
                'reply' => 'No pude interpretar la operacion de Alerts Center.',
                'telemetry' => [
                    'module_used' => 'alerts_center',
                    'task_action' => 'none',
                    'reminder_action' => 'none',
                    'alert_action' => 'none',
                ],
            ],
        };
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @return array<string, mixed>
     */
    private function parseCreateTask(string $message, array $pairs, array $baseCommand): array
    {
        $title = $this->firstValue($pairs, ['titulo', 'title', 'nombre', 'asunto']);
        if ($title === '') {
            $title = $this->deriveTitle($message, ['crear', 'crea', 'agregar', 'agrega', 'registrar', 'registra', 'programar', 'programa', 'tarea', 'task']);
        }
        if ($title === '') {
            return $this->askUser('Necesito el titulo de la tarea para registrarla.', 'create', 'none', 'none');
        }

        $description = $this->firstValue($pairs, ['descripcion', 'description', 'detalle', 'mensaje', 'message']);
        $command = $baseCommand + [
            'command' => 'CreateTask',
            'task_type' => $this->firstValue($pairs, ['task_type', 'tipo']) ?: 'follow_up',
            'title' => $title,
            'description' => $description !== '' ? $description : $title,
            'assigned_to' => $this->firstValue($pairs, ['assigned_to', 'asignado', 'responsable', 'usuario']) ?: null,
            'priority' => $this->normalizePriority($this->firstValue($pairs, ['priority', 'prioridad'])),
            'status' => 'pending',
            'due_at' => $this->normalizeDateTime($this->firstValue($pairs, ['due_at', 'vence', 'fecha_limite', 'fecha'])),
            'related_entity_type' => $this->firstValue($pairs, ['related_entity_type', 'entidad_tipo']) ?: null,
            'related_entity_id' => $this->firstValue($pairs, ['related_entity_id', 'entidad_id']) ?: null,
        ];

        return $this->commandResult($command, 'create', 'none', 'none');
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @return array<string, mixed>
     */
    private function parseListPendingTasks(string $message, array $pairs, array $baseCommand): array
    {
        $statuses = ['pending', 'in_progress'];
        if (str_contains($this->normalizeText($message), 'complet')) {
            $statuses = ['completed'];
        } elseif (str_contains($this->normalizeText($message), 'cancel')) {
            $statuses = ['cancelled'];
        }

        $statusOverride = $this->firstValue($pairs, ['status', 'estado']);
        if ($statusOverride !== '') {
            $statuses = [$statusOverride];
        }

        $command = $baseCommand + [
            'command' => 'ListPendingTasks',
            'statuses' => $statuses,
            'assigned_to' => $this->firstValue($pairs, ['assigned_to', 'asignado', 'responsable', 'usuario']) ?: null,
        ];

        return $this->commandResult($command, 'list_pending', 'none', 'none');
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @return array<string, mixed>
     */
    private function parseCreateReminder(string $message, array $pairs, array $baseCommand): array
    {
        $title = $this->firstValue($pairs, ['titulo', 'title', 'nombre', 'asunto']);
        if ($title === '') {
            $title = $this->deriveTitle($message, ['crear', 'crea', 'agregar', 'agrega', 'programar', 'programa', 'recordatorio', 'reminder', 'recordarme']);
        }
        $remindAt = $this->normalizeDateTime($this->firstValue($pairs, ['remind_at', 'recordar', 'fecha', 'fecha_hora', 'cuando']));
        if ($remindAt === null) {
            return $this->askUser('Indica `remind_at=YYYY-MM-DD HH:MM` para crear el recordatorio.', 'none', 'create', 'none');
        }
        if ($title === '') {
            return $this->askUser('Necesito el titulo del recordatorio para guardarlo.', 'none', 'create', 'none');
        }

        $messageText = $this->firstValue($pairs, ['mensaje', 'message', 'descripcion', 'description']);
        $command = $baseCommand + [
            'command' => 'CreateReminder',
            'reminder_type' => $this->firstValue($pairs, ['reminder_type', 'tipo']) ?: 'follow_up',
            'title' => $title,
            'message' => $messageText !== '' ? $messageText : $title,
            'remind_at' => $remindAt,
            'status' => 'pending',
            'related_entity_type' => $this->firstValue($pairs, ['related_entity_type', 'entidad_tipo']) ?: null,
            'related_entity_id' => $this->firstValue($pairs, ['related_entity_id', 'entidad_id']) ?: null,
        ];

        return $this->commandResult($command, 'none', 'create', 'none');
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @return array<string, mixed>
     */
    private function parseListReminders(string $message, array $pairs, array $baseCommand): array
    {
        $statuses = ['pending'];
        if (str_contains($this->normalizeText($message), 'complet')) {
            $statuses = ['completed'];
        } elseif (str_contains($this->normalizeText($message), 'cancel')) {
            $statuses = ['cancelled'];
        }

        $statusOverride = $this->firstValue($pairs, ['status', 'estado']);
        if ($statusOverride !== '') {
            $statuses = [$statusOverride];
        }

        $command = $baseCommand + [
            'command' => 'ListReminders',
            'statuses' => $statuses,
        ];

        return $this->commandResult($command, 'none', 'list', 'none');
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @return array<string, mixed>
     */
    private function parseCreateAlert(string $message, array $pairs, array $baseCommand): array
    {
        $title = $this->firstValue($pairs, ['titulo', 'title', 'nombre', 'asunto']);
        if ($title === '') {
            $title = $this->deriveTitle($message, ['crear', 'crea', 'generar', 'genera', 'registrar', 'registra', 'agregar', 'agrega', 'alerta', 'alert']);
        }
        if ($title === '') {
            return $this->askUser('Necesito el titulo de la alerta para registrarla.', 'none', 'none', 'create');
        }

        $messageText = $this->firstValue($pairs, ['mensaje', 'message', 'descripcion', 'description']);
        $command = $baseCommand + [
            'command' => 'CreateAlert',
            'alert_type' => $this->firstValue($pairs, ['alert_type', 'tipo']) ?: 'manual_alert',
            'title' => $title,
            'message' => $messageText !== '' ? $messageText : $title,
            'severity' => $this->normalizeSeverity($this->firstValue($pairs, ['severity', 'severidad', 'prioridad'])),
            'source_type' => $this->firstValue($pairs, ['source_type', 'origen']) ?: 'manual',
            'source_ref' => $this->firstValue($pairs, ['source_ref', 'origen_ref']) ?: null,
            'status' => 'open',
            'due_at' => $this->normalizeDateTime($this->firstValue($pairs, ['due_at', 'vence', 'fecha'])),
        ];

        return $this->commandResult($command, 'none', 'none', 'create');
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @return array<string, mixed>
     */
    private function parseListAlerts(string $message, array $pairs, array $baseCommand): array
    {
        $statuses = ['open', 'acknowledged'];
        if (str_contains($this->normalizeText($message), 'resuelta') || str_contains($this->normalizeText($message), 'resueltas')) {
            $statuses = ['resolved'];
        } elseif (str_contains($this->normalizeText($message), 'cerrad') || str_contains($this->normalizeText($message), 'dismiss')) {
            $statuses = ['dismissed'];
        }

        $statusOverride = $this->firstValue($pairs, ['status', 'estado']);
        if ($statusOverride !== '') {
            $statuses = [$statusOverride];
        }

        $severity = $this->normalizeSeverity($this->firstValue($pairs, ['severity', 'severidad']));
        $command = $baseCommand + [
            'command' => 'ListAlerts',
            'statuses' => $statuses,
            'severity' => $severity !== '' ? $severity : null,
        ];

        return $this->commandResult($command, 'none', 'none', 'list');
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+)=("([^"]*)"|\'([^\']*)\'|([^\\s]+))/u', $message, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = strtolower(trim((string) ($match[1] ?? '')));
            $value = '';
            foreach ([3, 4, 5] as $index) {
                if (isset($match[$index]) && $match[$index] !== '') {
                    $value = trim((string) $match[$index]);
                    break;
                }
            }
            if ($key !== '' && $value !== '') {
                $pairs[$key] = $value;
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, string> $pairs
     * @param array<int, string> $aliases
     */
    private function firstValue(array $pairs, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $alias = strtolower(trim($alias));
            if ($alias !== '' && array_key_exists($alias, $pairs)) {
                return trim((string) $pairs[$alias]);
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $removeTokens
     */
    private function deriveTitle(string $message, array $removeTokens): string
    {
        $title = $this->normalizeText($message);
        $title = preg_replace('/([a-zA-Z_]+)=("([^"]*)"|\'([^\']*)\'|([^\\s]+))/u', ' ', $title) ?? $title;
        foreach ($removeTokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $title = preg_replace('/\b' . preg_quote($token, '/') . '\b/u', ' ', $title) ?? $title;
        }
        $title = preg_replace('/\s+/u', ' ', trim($title)) ?? trim($title);
        return $title !== '' ? mb_substr($title, 0, 160, 'UTF-8') : '';
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function normalizePriority(string $value): string
    {
        $value = $this->normalizeText($value);
        $map = [
            'baja' => 'low',
            'low' => 'low',
            'media' => 'medium',
            'normal' => 'medium',
            'medium' => 'medium',
            'alta' => 'high',
            'high' => 'high',
            'critica' => 'critical',
            'critical' => 'critical',
        ];

        return $map[$value] ?? 'medium';
    }

    private function normalizeSeverity(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return $this->normalizePriority($value);
    }

    private function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace('T', ' ', $value);
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function commandResult(array $command, string $taskAction, string $reminderAction, string $alertAction): array
    {
        return [
            'kind' => 'command',
            'command' => $command,
            'telemetry' => [
                'module_used' => 'alerts_center',
                'task_action' => $taskAction,
                'reminder_action' => $reminderAction,
                'alert_action' => $alertAction,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function askUser(string $reply, string $taskAction, string $reminderAction, string $alertAction): array
    {
        return [
            'kind' => 'ask_user',
            'reply' => $reply,
            'telemetry' => [
                'module_used' => 'alerts_center',
                'task_action' => $taskAction,
                'reminder_action' => $reminderAction,
                'alert_action' => $alertAction,
            ],
        ];
    }
}
