<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Agents\ConversationGateway;

final class ControlTowerTaskCoordinator
{
    public function __construct(
        private readonly ?TaskExecutionManager $taskManager,
        private readonly ?IncidentManager $incidentManager,
    ) {}

    public function createTask(array $payload): array
    {
        if (!$this->taskManager) {
            return [];
        }
        try {
            return $this->taskManager->createTask($payload) ?? [];
        } catch (\Throwable $e) {
            error_log('[ControlTower] create task failed: ' . $e->getMessage());
            return [];
        }
    }

    public function linkTask(
        ConversationGateway $gateway,
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        ?array $task
    ): void {
        if (!is_array($task)) {
            return;
        }
        try {
            $gateway->linkTaskExecution($tenantId, $userId, $projectId, $mode, $task);
        } catch (\Throwable $e) {
            error_log('[ControlTower] link task failed: ' . $e->getMessage());
        }
    }

    public function attachTelemetry(array $telemetry, ?array $task, string $conversationId): array
    {
        if (is_array($task)) {
            $telemetry['task_id'] = (string) ($task['task_id'] ?? '');
            $telemetry['conversation_id'] = (string) ($task['conversation_id'] ?? $conversationId);
        } else {
            $telemetry['task_id'] = '';
            $telemetry['conversation_id'] = $conversationId;
        }
        return $telemetry;
    }

    public function recordRoute(?array $task, array $telemetry, string $intent): ?array
    {
        if (!is_array($task) || empty($task['task_id'])) {
            return $task;
        }
        if (!$this->taskManager) {
            return $task;
        }
        try {
            return $this->taskManager->recordRouteTrace((string) $task['tenant_id'], (string) $task['task_id'], [
                'intent' => $intent,
                'route_path' => (string) ($telemetry['route_path'] ?? ''),
                'gate_decision' => (string) ($telemetry['gate_decision'] ?? 'unknown'),
                'route_reason' => (string) ($telemetry['route_reason'] ?? ''),
                'evidence_used' => is_array($telemetry['evidence_used'] ?? null) ? (array) $telemetry['evidence_used'] : [],
                'evidence_status' => is_array($telemetry['evidence_status'] ?? null) ? (array) $telemetry['evidence_status'] : [],
                'latency_ms' => is_numeric($telemetry['router_latency_ms'] ?? null) ? (int) $telemetry['router_latency_ms'] : 0,
            ]);
        } catch (\Throwable $e) {
            error_log('[ControlTower] record route failed: ' . $e->getMessage());
            return $task;
        }
    }

    public function markRunning(?array $task, array $context = []): ?array
    {
        if (!is_array($task)) {
            return $task;
        }
        if (!$this->taskManager) {
            return $task;
        }
        try {
            return $this->taskManager->updateTask((string) $task['tenant_id'], (string) $task['task_id'], array_merge($context, [
                'status' => 'running',
            ]));
        } catch (\Throwable $e) {
            error_log('[ControlTower] mark running failed: ' . $e->getMessage());
            return $task;
        }
    }

    public function completeTask(?array $task, array $result = []): ?array
    {
        if (!is_array($task) || empty($task['task_id'])) {
            return $task;
        }
        if (!$this->taskManager) {
            return $task;
        }
        try {
            $task = $this->taskManager->attachExecutionResult((string) $task['tenant_id'], (string) $task['task_id'], $result);
            return $this->taskManager->updateTask((string) $task['tenant_id'], (string) $task['task_id'], [
                'status' => 'completed',
            ]);
        } catch (\Throwable $e) {
            error_log('[ControlTower] complete task failed: ' . $e->getMessage());
            return $task;
        }
    }

    public function failTask(?array $task, array $failure = []): array
    {
        if (!is_array($task)) {
            return ['task' => $task, 'incident' => null];
        }
        if (!$this->taskManager) {
            return ['task' => $task, 'incident' => null];
        }
        try {
            $task = $this->taskManager->attachExecutionResult((string) $task['tenant_id'], (string) $task['task_id'], [
                'failure' => $failure,
            ]);
            $task = $this->taskManager->updateTask((string) $task['tenant_id'], (string) $task['task_id'], [
                'status' => 'failed',
                'gate_decision' => (string) ($failure['gate_decision'] ?? ($task['gate_decision'] ?? 'unknown')),
            ]);
        } catch (\Throwable $e) {
            error_log('[ControlTower] fail task failed: ' . $e->getMessage());
        }

        $incident = null;
        if ($this->incidentManager) {
            try {
                $incident = $this->incidentManager->createFromTaskFailure($task, $failure);
            } catch (\Throwable $e) {
                error_log('[ControlTower] incident create failed: ' . $e->getMessage());
            }
        }
        return ['task' => $task, 'incident' => $incident];
    }

    public function annotateReply(array $reply, ?array $task, ?array $incident = null): array
    {
        $data = is_array($reply['data'] ?? null) ? (array) $reply['data'] : [];
        if (is_array($task)) {
            $data['task_id'] = (string) ($task['task_id'] ?? '');
            $data['conversation_id'] = (string) ($task['conversation_id'] ?? '');
            $data['task_status'] = (string) ($task['status'] ?? '');
        }
        if (is_array($incident)) {
            $data['incident_id'] = (string) ($incident['incident_id'] ?? '');
        }
        $reply['data'] = $data;
        return $reply;
    }

    public function buildLocalUtilityTelemetry(
        string $commandName,
        string $tenantId,
        string $projectId,
        string $sessionId,
        string $userId,
        ?array $task,
        string $conversationId,
        array $contractVersions
    ): array {
        return $this->attachTelemetry([
            'route_path' => 'cache>rules',
            'gate_decision' => 'allow',
            'route_reason' => 'control_tower_local_utility',
            'action_contract' => 'none',
            'rag_hit' => false,
            'source_ids' => [],
            'evidence_ids' => [],
            'evidence_used' => [],
            'llm_called' => false,
            'llm_used' => false,
            'semantic_enabled' => false,
            'rag_attempted' => false,
            'rag_used' => false,
            'rag_result_count' => 0,
            'evidence_gate_status' => 'skipped_by_rule',
            'fallback_reason' => 'none',
            'skill_detected' => false,
            'skill_selected' => 'none',
            'skill_executed' => false,
            'skill_failed' => false,
            'skill_execution_ms' => 0,
            'skill_result_status' => 'not_applicable',
            'skill_fallback_reason' => 'none',
            'tool_calls_count' => 0,
            'retry_count' => 0,
            'loop_guard_triggered' => false,
            'request_mode' => 'operation',
            'module_used' => 'control_tower',
            'task_action' => 'local_utility',
            'validation_result' => 'passed',
            'result_status' => 'success',
            'tenant_id' => $tenantId,
            'app_id' => $projectId,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'tool_usage' => [
                'tool_calls_count' => 0,
                'module_key' => 'control_tower',
                'action_key' => $commandName,
                'skill_selected' => 'none',
            ],
            'contract_versions' => $contractVersions,
        ], $task, $conversationId);
    }
}
