<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class AlertsCenterService
{
    private AlertsCenterRepository $repository;

    public function __construct(?AlertsCenterRepository $repository = null)
    {
        $this->repository = $repository ?? new AlertsCenterRepository();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createAlert(array $payload): array
    {
        $record = [
            'id' => '',
            'tenant_id' => $this->requireTenantId($payload['tenant_id'] ?? null),
            'app_id' => $this->normalizeNullableString($payload['app_id'] ?? null),
            'alert_type' => $this->normalizeRequiredString($payload['alert_type'] ?? 'manual_alert', 'alert_type'),
            'title' => $this->normalizeRequiredString($payload['title'] ?? '', 'title'),
            'message' => $this->normalizeRequiredString($payload['message'] ?? ($payload['title'] ?? ''), 'message'),
            'severity' => $this->normalizeEnum($payload['severity'] ?? 'medium', ['low', 'medium', 'high', 'critical'], 'severity'),
            'source_type' => $this->normalizeRequiredString($payload['source_type'] ?? 'manual', 'source_type'),
            'source_ref' => $this->normalizeNullableString($payload['source_ref'] ?? null),
            'status' => $this->normalizeEnum($payload['status'] ?? 'open', ['open', 'acknowledged', 'resolved', 'dismissed'], 'status'),
            'created_at' => $this->normalizeTimestamp($payload['created_at'] ?? null, true),
            'due_at' => $this->normalizeTimestamp($payload['due_at'] ?? null, false),
            'metadata' => $this->normalizeMetadata($payload['metadata'] ?? []),
        ];

        AlertsCenterContractValidator::validateAlert($record);
        return $this->repository->insertAlert($record);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTask(array $payload): array
    {
        $title = $this->normalizeRequiredString($payload['title'] ?? '', 'title');
        $record = [
            'id' => '',
            'tenant_id' => $this->requireTenantId($payload['tenant_id'] ?? null),
            'app_id' => $this->normalizeNullableString($payload['app_id'] ?? null),
            'task_type' => $this->normalizeRequiredString($payload['task_type'] ?? 'follow_up', 'task_type'),
            'title' => $title,
            'description' => $this->normalizeRequiredString($payload['description'] ?? $title, 'description'),
            'assigned_to' => $this->normalizeNullableString($payload['assigned_to'] ?? null),
            'priority' => $this->normalizeEnum($payload['priority'] ?? 'medium', ['low', 'medium', 'high', 'critical'], 'priority'),
            'status' => $this->normalizeEnum($payload['status'] ?? 'pending', ['pending', 'in_progress', 'completed', 'cancelled'], 'status'),
            'due_at' => $this->normalizeTimestamp($payload['due_at'] ?? null, false),
            'related_entity_type' => $this->normalizeNullableString($payload['related_entity_type'] ?? null),
            'related_entity_id' => $this->normalizeNullableString($payload['related_entity_id'] ?? null),
            'created_at' => $this->normalizeTimestamp($payload['created_at'] ?? null, true),
            'metadata' => $this->normalizeMetadata($payload['metadata'] ?? []),
        ];

        AlertsCenterContractValidator::validateTask($record);
        return $this->repository->insertTask($record);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createReminder(array $payload): array
    {
        $title = $this->normalizeRequiredString($payload['title'] ?? '', 'title');
        $record = [
            'id' => '',
            'tenant_id' => $this->requireTenantId($payload['tenant_id'] ?? null),
            'app_id' => $this->normalizeNullableString($payload['app_id'] ?? null),
            'reminder_type' => $this->normalizeRequiredString($payload['reminder_type'] ?? 'follow_up', 'reminder_type'),
            'title' => $title,
            'message' => $this->normalizeRequiredString($payload['message'] ?? $title, 'message'),
            'remind_at' => $this->normalizeTimestamp($payload['remind_at'] ?? null, true),
            'status' => $this->normalizeEnum($payload['status'] ?? 'pending', ['pending', 'completed', 'cancelled'], 'status'),
            'related_entity_type' => $this->normalizeNullableString($payload['related_entity_type'] ?? null),
            'related_entity_id' => $this->normalizeNullableString($payload['related_entity_id'] ?? null),
            'created_at' => $this->normalizeTimestamp($payload['created_at'] ?? null, true),
            'metadata' => $this->normalizeMetadata($payload['metadata'] ?? []),
        ];

        AlertsCenterContractValidator::validateReminder($record);
        return $this->repository->insertReminder($record);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listAlerts(string $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->repository->listAlerts($this->requireTenantId($tenantId), $this->normalizeFilters($filters), $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTasks(string $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->repository->listTasks($this->requireTenantId($tenantId), $this->normalizeFilters($filters), $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listPendingTasks(string $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $filters = $this->normalizeFilters($filters);
        if (empty($filters['statuses'])) {
            $filters['statuses'] = ['pending', 'in_progress'];
        }

        return $this->repository->listTasks($this->requireTenantId($tenantId), $filters, $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listReminders(string $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->repository->listReminders($this->requireTenantId($tenantId), $this->normalizeFilters($filters), $limit, $offset);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateAlertStatus(string $tenantId, string $id, string $status): array
    {
        $alert = $this->repository->updateAlertStatus(
            $this->requireTenantId($tenantId),
            $this->normalizeRequiredString($id, 'id'),
            $this->normalizeEnum($status, ['open', 'acknowledged', 'resolved', 'dismissed'], 'status')
        );
        if (!is_array($alert)) {
            throw new RuntimeException('La alerta no existe o no pertenece al tenant.');
        }
        AlertsCenterContractValidator::validateAlert($alert);
        return $alert;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveAlert(string $tenantId, string $id): array
    {
        return $this->updateAlertStatus($tenantId, $id, 'resolved');
    }

    /**
     * @return array<string, mixed>
     */
    public function updateTaskStatus(string $tenantId, string $id, string $status): array
    {
        $task = $this->repository->updateTaskStatus(
            $this->requireTenantId($tenantId),
            $this->normalizeRequiredString($id, 'id'),
            $this->normalizeEnum($status, ['pending', 'in_progress', 'completed', 'cancelled'], 'status')
        );
        if (!is_array($task)) {
            throw new RuntimeException('La tarea no existe o no pertenece al tenant.');
        }
        AlertsCenterContractValidator::validateTask($task);
        return $task;
    }

    /**
     * @return array<string, mixed>
     */
    public function completeTask(string $tenantId, string $id): array
    {
        return $this->updateTaskStatus($tenantId, $id, 'completed');
    }

    /**
     * @return array<string, mixed>
     */
    public function updateReminderStatus(string $tenantId, string $id, string $status): array
    {
        $reminder = $this->repository->updateReminderStatus(
            $this->requireTenantId($tenantId),
            $this->normalizeRequiredString($id, 'id'),
            $this->normalizeEnum($status, ['pending', 'completed', 'cancelled'], 'status')
        );
        if (!is_array($reminder)) {
            throw new RuntimeException('El recordatorio no existe o no pertenece al tenant.');
        }
        AlertsCenterContractValidator::validateReminder($reminder);
        return $reminder;
    }

    /**
     * @return array<string, mixed>
     */
    public function completeReminder(string $tenantId, string $id): array
    {
        return $this->updateReminderStatus($tenantId, $id, 'completed');
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchPendingItemsByTenant(string $tenantId, ?string $appId = null, int $limitPerType = 20): array
    {
        $tenantId = $this->requireTenantId($tenantId);
        $filters = [];
        $normalizedAppId = $this->normalizeNullableString($appId);
        if ($normalizedAppId !== null) {
            $filters['app_id'] = $normalizedAppId;
        }

        $alerts = $this->listAlerts($tenantId, $filters + ['statuses' => ['open', 'acknowledged']], $limitPerType);
        $tasks = $this->listPendingTasks($tenantId, $filters, $limitPerType);
        $reminders = $this->listReminders($tenantId, $filters + ['statuses' => ['pending']], $limitPerType);

        return [
            'alerts' => $alerts,
            'tasks' => $tasks,
            'reminders' => $reminders,
            'pending_items_count' => count($alerts) + count($tasks) + count($reminders),
        ];
    }

    /**
     * Deterministic sample rule for low-stock operational alerts.
     *
     * @return array<string, mixed>
     */
    public function emitSampleLowStockAlert(
        string $tenantId,
        ?string $appId,
        string $sku,
        int $availableUnits,
        int $threshold = 5
    ): array {
        $tenantId = $this->requireTenantId($tenantId);
        $normalizedSku = $this->normalizeRequiredString($sku, 'sku');
        $sourceRef = 'low_stock:' . strtolower($normalizedSku);
        $normalizedAppId = $this->normalizeNullableString($appId);

        $existing = $this->repository->findOpenAlertBySource($tenantId, 'inventory_rule', $sourceRef, $normalizedAppId);
        if (is_array($existing)) {
            return $existing;
        }

        return $this->createAlert([
            'tenant_id' => $tenantId,
            'app_id' => $normalizedAppId,
            'alert_type' => 'inventory_low_stock',
            'title' => 'Stock bajo: ' . $normalizedSku,
            'message' => 'El producto ' . $normalizedSku . ' quedo con ' . $availableUnits . ' unidades. Revisa reposicion.',
            'severity' => $availableUnits <= max(1, (int) floor($threshold / 2)) ? 'high' : 'medium',
            'source_type' => 'inventory_rule',
            'source_ref' => $sourceRef,
            'status' => 'open',
            'metadata' => [
                'rule_key' => 'sample_low_stock',
                'sku' => $normalizedSku,
                'available_units' => $availableUnits,
                'threshold' => $threshold,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];
        foreach ($filters as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($key === 'statuses' && is_array($value)) {
                $statuses = [];
                foreach ($value as $status) {
                    $candidate = trim((string) $status);
                    if ($candidate !== '') {
                        $statuses[] = $candidate;
                    }
                }
                if ($statuses !== []) {
                    $normalized['statuses'] = array_values(array_unique($statuses));
                }
                continue;
            }

            if (in_array($key, ['due_before', 'due_after', 'remind_before', 'remind_after'], true)) {
                $timestamp = $this->normalizeTimestamp($value, false);
                if ($timestamp !== null) {
                    $normalized[$key] = $timestamp;
                }
                continue;
            }

            if ($key === 'app_id') {
                $appId = $this->normalizeNullableString($value);
                if ($appId !== null) {
                    $normalized[$key] = $appId;
                }
                continue;
            }

            $candidate = trim((string) $value);
            if ($candidate !== '') {
                $normalized[$key] = $candidate;
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private function requireTenantId($value): string
    {
        return $this->normalizeRequiredString($value, 'tenant_id');
    }

    /**
     * @param mixed $value
     */
    private function normalizeRequiredString($value, string $field): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new RuntimeException('Campo requerido faltante: ' . $field . '.');
        }
        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $allowed
     */
    private function normalizeEnum($value, array $allowed, string $field): string
    {
        $normalized = strtolower(trim((string) $value));
        if (!in_array($normalized, $allowed, true)) {
            throw new RuntimeException('Valor invalido para ' . $field . '.');
        }
        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private function normalizeTimestamp($value, bool $required): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return $required ? date('Y-m-d H:i:s') : null;
        }

        $candidate = trim((string) $value);
        $candidate = str_replace('T', ' ', $candidate);
        try {
            $date = new DateTimeImmutable($candidate);
        } catch (Throwable $e) {
            throw new RuntimeException('Fecha invalida para Alerts Center.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeMetadata($value): array
    {
        return is_array($value) ? $value : [];
    }
}
