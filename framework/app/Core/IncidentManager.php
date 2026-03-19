<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

final class IncidentManager
{
    private const INCIDENT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/incident.schema.json';

    private ControlTowerRepository $repository;
    private ControlTowerFeedManager $feed;

    public function __construct(
        ?ControlTowerRepository $repository = null,
        ?ControlTowerFeedManager $feed = null
    ) {
        $this->repository = $repository ?? new ControlTowerRepository();
        $this->feed = $feed ?? new ControlTowerFeedManager($this->repository);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createIncident(array $payload): array
    {
        $incident = $this->normalizeIncident($payload);
        $this->assertTaskScopeOrFail($incident);
        $this->validateIncidentOrFail($incident);
        $this->repository->upsertIncident($incident);
        $this->feed->emitIncidentCreated($incident);
        return $incident;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $failure
     * @return array<string, mixed>
     */
    public function createFromTaskFailure(array $task, array $failure): array
    {
        $tenantId = trim((string) ($task['tenant_id'] ?? '')) ?: 'default';
        $projectId = trim((string) ($task['project_id'] ?? '')) ?: 'default';
        $appId = trim((string) ($task['app_id'] ?? '')) ?: $projectId;
        $taskId = trim((string) ($task['task_id'] ?? ''));
        $errorType = trim((string) ($failure['error_type'] ?? 'task_failed')) ?: 'task_failed';
        $incidentId = 'incident_' . substr(sha1($taskId . '|' . $errorType), 0, 16);

        return $this->createIncident([
            'incident_id' => $incidentId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'app_id' => $appId,
            'severity' => $this->resolveSeverity($errorType),
            'source' => 'system',
            'related_task_id' => $taskId,
            'related_events' => is_array($task['related_events'] ?? null) ? (array) $task['related_events'] : [],
            'status' => 'open',
            'description' => trim((string) ($failure['description'] ?? '')) ?: 'Task execution failed: ' . $taskId,
            'metadata' => [
                'conversation_id' => (string) ($task['conversation_id'] ?? ''),
                'route_path' => (string) ($task['route_path'] ?? ''),
                'gate_decision' => (string) ($task['gate_decision'] ?? 'unknown'),
                'error_type' => $errorType,
            ],
            'created_at' => trim((string) ($failure['created_at'] ?? '')) ?: date('c'),
        ]);
    }

    /**
     * @param array<string, mixed> $auditAlert
     * @return array<string, mixed>
     */
    public function createFromAuditAlert(array $auditAlert): array
    {
        $tenantId = trim((string) ($auditAlert['tenant_id'] ?? '')) ?: 'default';
        $projectId = trim((string) ($auditAlert['app_id'] ?? $auditAlert['project_id'] ?? '')) ?: 'default';
        $relatedEvents = [];
        if (is_array($auditAlert['related_events'] ?? null)) {
            foreach ((array) $auditAlert['related_events'] as $eventRef) {
                if (!is_array($eventRef)) {
                    continue;
                }
                $eventId = trim((string) ($eventRef['event_id'] ?? ''));
                if ($eventId !== '') {
                    $relatedEvents[] = $eventId;
                }
            }
        }

        return $this->createIncident([
            'incident_id' => 'incident_' . substr(sha1(json_encode($auditAlert)), 0, 16),
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'app_id' => $projectId,
            'severity' => trim((string) ($auditAlert['severity'] ?? 'warning')) ?: 'warning',
            'source' => 'audit',
            'related_task_id' => trim((string) ($auditAlert['related_task_id'] ?? '')) ?: 'audit_alert',
            'related_events' => $relatedEvents,
            'status' => 'open',
            'description' => trim((string) ($auditAlert['description'] ?? '')) ?: 'Audit alert escalated to incident.',
            'metadata' => [
                'alert_id' => (string) ($auditAlert['alert_id'] ?? ''),
                'anomaly_type' => (string) ($auditAlert['anomaly_type'] ?? ''),
            ],
            'created_at' => trim((string) ($auditAlert['created_at'] ?? '')) ?: date('c'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getIncident(string $tenantId, string $incidentId): ?array
    {
        return $this->repository->getIncident($tenantId, $incidentId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listIncidents(string $tenantId, string $projectId, array $filters = [], int $limit = 25): array
    {
        return $this->repository->listIncidents($tenantId, $projectId, $filters, $limit);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeIncident(array $payload): array
    {
        $tenantId = trim((string) ($payload['tenant_id'] ?? '')) ?: 'default';
        $projectId = trim((string) ($payload['project_id'] ?? '')) ?: 'default';
        $appId = trim((string) ($payload['app_id'] ?? '')) ?: $projectId;
        $incidentId = trim((string) ($payload['incident_id'] ?? ''));
        if ($incidentId === '') {
            $incidentId = 'incident_' . substr(sha1($tenantId . '|' . $projectId . '|' . microtime(true)), 0, 16);
        }

        return [
            'incident_id' => $incidentId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'app_id' => $appId,
            'severity' => $this->normalizeSeverity((string) ($payload['severity'] ?? 'warning')),
            'source' => $this->normalizeSource((string) ($payload['source'] ?? 'system')),
            'related_task_id' => trim((string) ($payload['related_task_id'] ?? '')),
            'related_events' => $this->normalizeStringList($payload['related_events'] ?? []),
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? 'open')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'metadata' => is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
            'created_at' => trim((string) ($payload['created_at'] ?? '')) ?: date('c'),
        ];
    }

    /**
     * @param array<string, mixed> $incident
     */
    private function assertTaskScopeOrFail(array $incident): void
    {
        $relatedTaskId = trim((string) ($incident['related_task_id'] ?? ''));
        if ($relatedTaskId === '' || $relatedTaskId === 'audit_alert') {
            return;
        }

        $task = $this->repository->getTask((string) $incident['tenant_id'], $relatedTaskId);
        if (!is_array($task)) {
            throw new RuntimeException('CONTROL_TOWER_INCIDENT_TASK_SCOPE_INVALID');
        }
        if ((string) ($task['tenant_id'] ?? '') !== (string) ($incident['tenant_id'] ?? '')) {
            throw new RuntimeException('CONTROL_TOWER_INCIDENT_CROSS_TENANT_LINK');
        }
    }

    /**
     * @param array<string, mixed> $incident
     */
    private function validateIncidentOrFail(array $incident): void
    {
        if (!is_file(self::INCIDENT_SCHEMA)) {
            throw new RuntimeException('Schema incident no existe: ' . self::INCIDENT_SCHEMA);
        }

        $schema = json_decode((string) file_get_contents(self::INCIDENT_SCHEMA));
        if (!$schema) {
            throw new RuntimeException('Schema incident invalido.');
        }

        try {
            $payloadObject = json_decode(
                json_encode(
                    $this->schemaPayload($incident),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload incident no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? (string) $error->message() : 'Payload incident invalido.';
            throw new RuntimeException($message);
        }
    }

    private function resolveSeverity(string $errorType): string
    {
        $errorType = strtolower(trim($errorType));
        if (in_array($errorType, ['llm_unavailable', 'route_error'], true)) {
            return 'critical';
        }
        if (in_array($errorType, ['security_block', 'quality_gate_block'], true)) {
            return 'warning';
        }

        return 'warning';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn($item): string => trim((string) $item),
            $value
        ), static fn(string $item): bool => $item !== '')));
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'warning';
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        return in_array($source, ['audit', 'system', 'user'], true) ? $source : 'system';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['open', 'investigating', 'resolved'], true) ? $status : 'open';
    }

    /**
     * @param array<string, mixed> $incident
     * @return array<string, mixed>
     */
    private function schemaPayload(array $incident): array
    {
        $payload = $incident;
        $payload['metadata'] = $this->objectOrEmptyObject($payload['metadata'] ?? []);

        return $payload;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function objectOrEmptyObject($value)
    {
        if (is_array($value) && $value === []) {
            return new stdClass();
        }

        return $value;
    }
}
