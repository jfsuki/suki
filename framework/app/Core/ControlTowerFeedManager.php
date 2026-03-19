<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

final class ControlTowerFeedManager
{
    private const EVENT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/control_tower_event.schema.json';

    private ControlTowerRepository $repository;

    public function __construct(?ControlTowerRepository $repository = null)
    {
        $this->repository = $repository ?? new ControlTowerRepository();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function emit(array $payload): array
    {
        $event = $this->normalizeEvent($payload);
        $this->validateOrFail($event);
        $this->repository->appendEvent($event);
        return $event;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function emitTaskUpdate(array $task, array $payload = []): array
    {
        $eventFingerprint = json_encode([
            'task_id' => (string) ($task['task_id'] ?? ''),
            'updated_at' => (string) ($task['updated_at'] ?? date('c')),
            'status' => (string) ($task['status'] ?? ''),
            'gate_decision' => (string) ($task['gate_decision'] ?? 'unknown'),
            'execution_result' => $task['execution_result'] ?? [],
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->emit([
            'event_id' => 'ctevt_' . substr(sha1((string) $eventFingerprint), 0, 16),
            'event_type' => 'task_update',
            'tenant_id' => $task['tenant_id'] ?? 'default',
            'project_id' => $task['project_id'] ?? 'default',
            'app_id' => $task['app_id'] ?? ($task['project_id'] ?? 'default'),
            'timestamp' => $task['updated_at'] ?? date('c'),
            'source' => $task['source'] ?? 'system',
            'linked_ids' => [
                'task_id' => $task['task_id'] ?? '',
                'conversation_id' => $task['conversation_id'] ?? '',
                'beg_event_ids' => is_array($task['related_events'] ?? null) ? (array) $task['related_events'] : [],
            ],
            'payload' => array_merge([
                'intent' => (string) ($task['intent'] ?? ''),
                'status' => (string) ($task['status'] ?? ''),
                'route_path' => (string) ($task['route_path'] ?? ''),
                'gate_decision' => (string) ($task['gate_decision'] ?? 'unknown'),
            ], $payload),
        ]);
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $warning
     * @return array<string, mixed>
     */
    public function emitSystemWarning(array $task, array $warning): array
    {
        return $this->emit([
            'event_id' => 'ctevt_' . substr(sha1((string) ($task['task_id'] ?? '') . '|warning|' . json_encode($warning)), 0, 16),
            'event_type' => 'system_warning',
            'tenant_id' => $task['tenant_id'] ?? 'default',
            'project_id' => $task['project_id'] ?? 'default',
            'app_id' => $task['app_id'] ?? ($task['project_id'] ?? 'default'),
            'timestamp' => $warning['timestamp'] ?? date('c'),
            'source' => $warning['source'] ?? 'system',
            'linked_ids' => [
                'task_id' => $task['task_id'] ?? '',
                'conversation_id' => $task['conversation_id'] ?? '',
                'beg_event_ids' => is_array($task['related_events'] ?? null) ? (array) $task['related_events'] : [],
            ],
            'payload' => $warning,
        ]);
    }

    /**
     * @param array<string, mixed> $incident
     * @return array<string, mixed>
     */
    public function emitIncidentCreated(array $incident): array
    {
        return $this->emit([
            'event_id' => 'ctevt_' . substr(sha1((string) ($incident['incident_id'] ?? '') . '|incident_created'), 0, 16),
            'event_type' => 'incident_created',
            'tenant_id' => $incident['tenant_id'] ?? 'default',
            'project_id' => $incident['project_id'] ?? 'default',
            'app_id' => $incident['app_id'] ?? ($incident['project_id'] ?? 'default'),
            'timestamp' => $incident['created_at'] ?? date('c'),
            'source' => $incident['source'] ?? 'system',
            'linked_ids' => [
                'task_id' => $incident['related_task_id'] ?? '',
                'incident_id' => $incident['incident_id'] ?? '',
                'beg_event_ids' => is_array($incident['related_events'] ?? null) ? (array) $incident['related_events'] : [],
            ],
            'payload' => [
                'severity' => (string) ($incident['severity'] ?? 'warning'),
                'status' => (string) ($incident['status'] ?? 'open'),
                'description' => (string) ($incident['description'] ?? ''),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listEvents(string $tenantId, string $projectId, array $filters = [], int $limit = 50): array
    {
        return $this->repository->listEvents($tenantId, $projectId, $filters, $limit);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $payload): array
    {
        $linkedIds = is_array($payload['linked_ids'] ?? null) ? (array) $payload['linked_ids'] : [];
        $begEventIds = is_array($linkedIds['beg_event_ids'] ?? null) ? (array) $linkedIds['beg_event_ids'] : [];

        return [
            'event_id' => trim((string) ($payload['event_id'] ?? '')),
            'event_type' => trim((string) ($payload['event_type'] ?? '')),
            'tenant_id' => trim((string) ($payload['tenant_id'] ?? '')) ?: 'default',
            'project_id' => trim((string) ($payload['project_id'] ?? '')) ?: 'default',
            'app_id' => trim((string) ($payload['app_id'] ?? '')) ?: (trim((string) ($payload['project_id'] ?? '')) ?: 'default'),
            'timestamp' => trim((string) ($payload['timestamp'] ?? '')) ?: date('c'),
            'source' => trim((string) ($payload['source'] ?? '')) ?: 'system',
            'linked_ids' => [
                'task_id' => trim((string) ($linkedIds['task_id'] ?? '')),
                'conversation_id' => trim((string) ($linkedIds['conversation_id'] ?? '')),
                'incident_id' => trim((string) ($linkedIds['incident_id'] ?? '')),
                'beg_event_ids' => array_values(array_filter(array_map(
                    static fn($value): string => trim((string) $value),
                    $begEventIds
                ), static fn(string $value): bool => $value !== '')),
            ],
            'payload' => is_array($payload['payload'] ?? null) ? (array) $payload['payload'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateOrFail(array $payload): void
    {
        if (!is_file(self::EVENT_SCHEMA)) {
            throw new RuntimeException('Schema Control Tower event no existe: ' . self::EVENT_SCHEMA);
        }

        $schema = json_decode((string) file_get_contents(self::EVENT_SCHEMA));
        if (!$schema) {
            throw new RuntimeException('Schema Control Tower event invalido.');
        }

        try {
            $payloadObject = json_decode(
                json_encode(
                    $this->schemaPayload($payload),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload Control Tower event no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? (string) $error->message() : 'Payload Control Tower event invalido.';
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function schemaPayload(array $event): array
    {
        $payload = $event;
        $payload['linked_ids'] = $this->objectOrEmptyObject($payload['linked_ids'] ?? []);
        $payload['payload'] = $this->objectOrEmptyObject($payload['payload'] ?? []);

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
