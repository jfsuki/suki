<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

final class TaskExecutionManager
{
    private const TASK_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/task_execution.schema.json';

    private ControlTowerRepository $repository;
    private ControlTowerFeedManager $feed;
    private ContractRegistry $contracts;

    public function __construct(
        ?ControlTowerRepository $repository = null,
        ?ControlTowerFeedManager $feed = null,
        ?ContractRegistry $contracts = null
    ) {
        $this->repository = $repository ?? new ControlTowerRepository();
        $this->feed = $feed ?? new ControlTowerFeedManager($this->repository);
        $this->contracts = $contracts ?? new ContractRegistry();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTask(array $payload): array
    {
        $task = $this->normalizeTask($payload);
        $existing = $this->repository->findTaskByIdempotency(
            (string) $task['tenant_id'],
            (string) $task['conversation_id'],
            (string) $task['idempotency_key']
        );
        if (is_array($existing)) {
            return $existing;
        }

        $this->validateTaskOrFail($task);
        $this->repository->upsertTask($task);
        $this->feed->emitTaskUpdate($task, ['reason' => 'created']);
        return $task;
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function updateTask(string $tenantId, string $taskId, array $patch): array
    {
        $task = $this->repository->getTask($tenantId, $taskId);
        if (!is_array($task)) {
            throw new RuntimeException('CONTROL_TOWER_TASK_NOT_FOUND');
        }

        $statusBefore = (string) ($task['status'] ?? 'pending');
        $statusAfter = array_key_exists('status', $patch)
            ? trim((string) ($patch['status'] ?? ''))
            : $statusBefore;
        if ($statusAfter !== '' && !$this->isValidStatusTransition($statusBefore, $statusAfter)) {
            throw new RuntimeException('CONTROL_TOWER_TASK_STATUS_TRANSITION_INVALID');
        }

        $merged = array_merge($task, $patch);
        $merged['updated_at'] = trim((string) ($patch['updated_at'] ?? '')) ?: date('c');
        $merged = $this->normalizeTask($merged);
        $this->validateTaskOrFail($merged);
        $this->repository->upsertTask($merged);
        $this->feed->emitTaskUpdate($merged, ['reason' => 'updated']);
        return $merged;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function attachExecutionResult(string $tenantId, string $taskId, array $result): array
    {
        $task = $this->repository->getTask($tenantId, $taskId);
        if (!is_array($task)) {
            throw new RuntimeException('CONTROL_TOWER_TASK_NOT_FOUND');
        }

        $existing = is_array($task['execution_result'] ?? null) ? (array) $task['execution_result'] : [];
        return $this->updateTask($tenantId, $taskId, [
            'execution_result' => array_merge($existing, $result),
        ]);
    }

    /**
     * @param array<string, mixed> $trace
     * @return array<string, mixed>
     */
    public function recordRouteTrace(string $tenantId, string $taskId, array $trace): array
    {
        $task = $this->repository->getTask($tenantId, $taskId);
        if (!is_array($task)) {
            throw new RuntimeException('CONTROL_TOWER_TASK_NOT_FOUND');
        }

        $executionResult = is_array($task['execution_result'] ?? null) ? (array) $task['execution_result'] : [];
        $executionResult['route_reason'] = trim((string) ($trace['route_reason'] ?? ''));
        $executionResult['evidence_used'] = is_array($trace['evidence_used'] ?? null) ? (array) $trace['evidence_used'] : [];
        $executionResult['evidence_status'] = is_array($trace['evidence_status'] ?? null) ? (array) $trace['evidence_status'] : [];
        $executionResult['latency_ms'] = is_numeric($trace['latency_ms'] ?? null) ? max(0, (int) $trace['latency_ms']) : 0;

        return $this->updateTask($tenantId, $taskId, [
            'intent' => trim((string) ($trace['intent'] ?? $task['intent'] ?? '')) ?: (string) ($task['intent'] ?? 'unknown'),
            'route_path' => trim((string) ($trace['route_path'] ?? '')),
            'gate_decision' => trim((string) ($trace['gate_decision'] ?? '')) ?: 'unknown',
            'execution_result' => $executionResult,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function evaluateQualityGates(array $context): array
    {
        $errors = [];
        $warnings = [];
        $routeTelemetry = is_array($context['route_telemetry'] ?? null) ? (array) $context['route_telemetry'] : [];
        $action = trim((string) ($context['action'] ?? ''));
        $tenantId = trim((string) ($context['tenant_id'] ?? '')) ?: 'default';
        $authTenantId = trim((string) ($context['auth_tenant_id'] ?? ''));

        if ($authTenantId !== '' && $authTenantId !== $tenantId) {
            $errors[] = [
                'code' => 'tenant_isolation_failed',
                'message' => 'auth_tenant_id no coincide con tenant_id.',
            ];
        }

        $command = is_array($context['command'] ?? null) ? (array) $context['command'] : [];
        if ($action === 'execute_command') {
            [$schemaValid, $schemaReason] = $this->validateCommandPayload($command);
            if (!$schemaValid) {
                $errors[] = [
                    'code' => 'schema_validation_failed',
                    'message' => $schemaReason,
                ];
            }

            $actionCatalog = $this->actionCatalogIndex();
            $actionContract = trim((string) ($context['action_contract'] ?? $routeTelemetry['action_contract'] ?? ''));
            if ($actionContract === '' || $actionContract === 'none' || !isset($actionCatalog[$actionContract])) {
                $errors[] = [
                    'code' => 'action_not_allowed',
                    'message' => 'La accion no esta permitida por action_catalog.json.',
                ];
            }

            $evidenceStatus = is_array($routeTelemetry['evidence_status'] ?? null)
                ? (array) $routeTelemetry['evidence_status']
                : [];
            $missingEvidence = is_array($evidenceStatus['missing'] ?? null) ? (array) $evidenceStatus['missing'] : [];
            if ($missingEvidence !== []) {
                $errors[] = [
                    'code' => 'evidence_missing',
                    'message' => 'Falta evidencia minima para ejecutar la accion.',
                ];
            }
        } else {
            $warnings[] = [
                'code' => 'action_gate_not_applicable',
                'message' => 'Quality gates estrictos no aplican a una respuesta no ejecutable.',
            ];
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'checked' => [
                'tenant_isolation',
                'schema_validation',
                'allowed_action',
                'evidence_check',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $warning
     * @return array<string, mixed>
     */
    public function blockExecution(string $tenantId, string $taskId, array $warning): array
    {
        $task = $this->updateTask($tenantId, $taskId, [
            'status' => 'failed',
            'gate_decision' => 'blocked',
        ]);
        $task = $this->attachExecutionResult($tenantId, $taskId, [
            'warning' => $warning,
        ]);
        $this->feed->emitSystemWarning($task, $warning);
        return $task;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTask(string $tenantId, string $taskId): ?array
    {
        return $this->repository->getTask($tenantId, $taskId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTasks(string $tenantId, string $projectId, array $filters = [], int $limit = 25): array
    {
        return $this->repository->listTasks($tenantId, $projectId, $filters, $limit);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeTask(array $payload): array
    {
        $tenantId = trim((string) ($payload['tenant_id'] ?? '')) ?: 'default';
        $projectId = trim((string) ($payload['project_id'] ?? '')) ?: 'default';
        $appId = trim((string) ($payload['app_id'] ?? '')) ?: $projectId;
        $conversationId = trim((string) ($payload['conversation_id'] ?? ''))
            ?: (trim((string) ($payload['session_id'] ?? '')) ?: 'conversation_default');
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''))
            ?: (trim((string) ($payload['message_id'] ?? '')) ?: $conversationId);
        $taskId = trim((string) ($payload['task_id'] ?? ''));
        if ($taskId === '') {
            $taskId = 'task_' . substr(sha1($tenantId . '|' . $conversationId . '|' . $idempotencyKey), 0, 16);
        }

        return [
            'task_id' => $taskId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'app_id' => $appId,
            'conversation_id' => $conversationId,
            'session_id' => trim((string) ($payload['session_id'] ?? '')),
            'user_id' => trim((string) ($payload['user_id'] ?? '')),
            'message_id' => trim((string) ($payload['message_id'] ?? '')),
            'intent' => trim((string) ($payload['intent'] ?? '')) ?: 'unknown',
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? 'pending')),
            'source' => $this->normalizeSource((string) ($payload['source'] ?? 'chat')),
            'route_path' => trim((string) ($payload['route_path'] ?? '')),
            'gate_decision' => $this->normalizeGateDecision((string) ($payload['gate_decision'] ?? 'unknown')),
            'related_entities' => $this->normalizeRelatedEntities($payload['related_entities'] ?? []),
            'related_events' => $this->normalizeStringList($payload['related_events'] ?? []),
            'execution_result' => is_array($payload['execution_result'] ?? null) ? (array) $payload['execution_result'] : [],
            'idempotency_key' => $idempotencyKey,
            'metadata' => is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
            'created_at' => trim((string) ($payload['created_at'] ?? '')) ?: date('c'),
            'updated_at' => trim((string) ($payload['updated_at'] ?? '')) ?: date('c'),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, string>>
     */
    private function normalizeRelatedEntities($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = [
                'entity_type' => trim((string) ($item['entity_type'] ?? '')),
                'entity_id' => trim((string) ($item['entity_id'] ?? '')),
                'tenant_id' => trim((string) ($item['tenant_id'] ?? '')),
                'app_id' => trim((string) ($item['app_id'] ?? '')),
            ];
            if ($normalized['entity_type'] === '' && $normalized['entity_id'] === '') {
                continue;
            }
            $items[] = $normalized;
        }

        return $items;
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

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['pending', 'running', 'completed', 'failed'], true) ? $status : 'pending';
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        return in_array($source, ['chat', 'system', 'audit'], true) ? $source : 'chat';
    }

    private function normalizeGateDecision(string $gateDecision): string
    {
        $gateDecision = strtolower(trim($gateDecision));
        return in_array($gateDecision, ['allow', 'warn', 'blocked', 'off', 'unknown'], true) ? $gateDecision : 'unknown';
    }

    private function isValidStatusTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $map = [
            'pending' => ['running', 'completed', 'failed'],
            'running' => ['completed', 'failed'],
            'completed' => [],
            'failed' => [],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function validateTaskOrFail(array $task): void
    {
        if (!is_file(self::TASK_SCHEMA)) {
            throw new RuntimeException('Schema task_execution no existe: ' . self::TASK_SCHEMA);
        }

        $schema = json_decode((string) file_get_contents(self::TASK_SCHEMA));
        if (!$schema) {
            throw new RuntimeException('Schema task_execution invalido.');
        }

        try {
            $payloadObject = json_decode(
                json_encode(
                    $this->schemaPayload($task),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload task_execution no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? (string) $error->message() : 'Payload task_execution invalido.';
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $command
     * @return array{0: bool, 1: string}
     */
    private function validateCommandPayload(array $command): array
    {
        $commandName = trim((string) ($command['command'] ?? ''));
        if ($commandName === '') {
            return [false, 'command.command es obligatorio.'];
        }
        if (isset($command['data']) && !is_array($command['data'])) {
            return [false, 'command.data debe ser objeto/arreglo.'];
        }
        if (isset($command['filters']) && !is_array($command['filters'])) {
            return [false, 'command.filters debe ser objeto/arreglo.'];
        }

        return [true, 'ok'];
    }

    /**
     * @return array<string, bool>
     */
    private function actionCatalogIndex(): array
    {
        $catalog = $this->contracts->getActionCatalog();
        $entries = is_array($catalog['catalog'] ?? null) ? (array) $catalog['catalog'] : [];
        $index = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $index[$name] = true;
            }
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function schemaPayload(array $task): array
    {
        $payload = $task;
        $payload['execution_result'] = $this->objectOrEmptyObject($payload['execution_result'] ?? []);
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
