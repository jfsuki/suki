<?php
declare(strict_types=1);
// app/Core/Telemetry/AgentOpsTelemetryBuilder.php

namespace App\Core\Telemetry;

use App\Core\AgentOpsSupervisor;
use App\Core\AgentOpsObservabilityService;

final class AgentOpsTelemetryBuilder
{
    public function __construct(
        private readonly AgentOpsSupervisor $supervisor,
        private readonly AgentOpsObservabilityService $observabilityService
    ) {}

    public function resolveExtendedContractVersions(array $base = []): array
    {
        $versions = $base;
        $versions['gbo'] = $versions['gbo'] ?? $this->resolveVersionFromJsonArtifact(
            FRAMEWORK_ROOT . '/ontology/gbo_universal_concepts.json',
            ['ontology_version', 'schema_version']
        );
        $versions['beg'] = $versions['beg'] ?? $this->resolveVersionFromJsonArtifact(
            FRAMEWORK_ROOT . '/events/beg_event_types.json',
            ['beg_version', 'schema_version']
        );
        $versions['audit'] = $versions['audit'] ?? $this->resolveVersionFromJsonArtifact(
            FRAMEWORK_ROOT . '/audit/audit_rules.json',
            ['audit_version', 'schema_version']
        );

        return $versions;
    }

    /**
     * @param array<int, string> $keys
     */
    public function resolveVersionFromJsonArtifact(string $path, array $keys): string
    {
        if (!is_file($path)) {
            return 'unknown';
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return 'unknown';
        }

        foreach ($keys as $key) {
            $value = trim((string) ($decoded[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    public function buildAgentOpsTelemetryBase(
        array $routeTelemetry,
        string $tenantId,
        string $projectId,
        string $sessionId,
        string $messageId,
        int $latencyMs,
        string $eventName,
        array $runtimeContext = []
    ): array {
        $contractVersions = $this->resolveExtendedContractVersions(
            is_array($routeTelemetry['contract_versions'] ?? null)
                ? (array) $routeTelemetry['contract_versions']
                : []
        );
        $versions = is_array($routeTelemetry['versions'] ?? null) ? (array) $routeTelemetry['versions'] : [];
        $enforcementMode = trim((string) ($routeTelemetry['enforcement_mode'] ?? ''));
        $enforcementModeSource = trim((string) ($routeTelemetry['enforcement_mode_source'] ?? ''));
        $enforcementAppEnv = trim((string) ($routeTelemetry['enforcement_app_env'] ?? ''));
        if (empty($versions)) {
            $versions = [
                'prompt_version' => (string) (getenv('PROMPT_VERSION') ?: 'unknown'),
                'router_policy_version' => (string) ($contractVersions['router_policy'] ?? 'unknown'),
                'action_catalog_version' => (string) ($contractVersions['action_catalog'] ?? 'unknown'),
                'skills_catalog_version' => (string) ($contractVersions['skills_catalog'] ?? 'unknown'),
                'akp_version' => (string) (getenv('AKP_VERSION') ?: 'unknown'),
                'policy_pack_version' => (string) (getenv('POLICY_PACK_VERSION') ?: 'unknown'),
            ];
        }

        $runtimeEnvelope = $this->buildAgentOpsRuntimeEnvelope($routeTelemetry, $latencyMs, $runtimeContext);
        $runtimeObservability = $runtimeEnvelope['runtime'];
        $supervisor = $runtimeEnvelope['supervisor'];
        $toolUsage = is_array($runtimeObservability['tool_usage'] ?? null) ? (array) $runtimeObservability['tool_usage'] : [];
        if (trim((string) ($runtimeObservability['session_id'] ?? '')) === '') {
            $runtimeObservability['session_id'] = $sessionId;
        }
        if (trim((string) ($runtimeObservability['user_id'] ?? '')) === '') {
            $runtimeObservability['user_id'] = trim((string) ($routeTelemetry['user_id'] ?? '')) ?: 'anon';
        }
        if (($runtimeObservability['app_id'] ?? null) === null || trim((string) ($runtimeObservability['app_id'] ?? '')) === '') {
            $runtimeObservability['app_id'] = $projectId;
        }
        if (trim((string) ($runtimeObservability['tenant_id'] ?? '')) === '') {
            $runtimeObservability['tenant_id'] = $tenantId;
        }

        return [
            'event_name' => $eventName,
            'event_time' => date('c'),
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'route_path' => $runtimeObservability['route_path'],
            'gate_decision' => $runtimeObservability['gate_decision'],
            'task_id' => $runtimeObservability['task_id'],
            'conversation_id' => $runtimeObservability['conversation_id'],
            'action_contract' => $runtimeObservability['action_contract'],
            'rag_hit' => $runtimeObservability['rag_hit'],
            'source_ids' => $runtimeObservability['source_ids'],
            'evidence_ids' => $runtimeObservability['evidence_ids'],
            'evidence_used' => $runtimeObservability['evidence_used'],
            'llm_called' => $runtimeObservability['llm_called'],
            'llm_used' => $runtimeObservability['llm_used'],
            'route_reason' => $runtimeObservability['route_reason'],
            'semantic_enabled' => $runtimeObservability['semantic_enabled'],
            'rag_attempted' => $runtimeObservability['rag_attempted'],
            'rag_used' => $runtimeObservability['rag_used'],
            'rag_result_count' => $runtimeObservability['rag_result_count'],
            'evidence_gate_status' => $runtimeObservability['evidence_gate_status'],
            'fallback_reason' => $runtimeObservability['fallback_reason'],
            'skill_detected' => $runtimeObservability['skill_detected'],
            'skill_selected' => $runtimeObservability['skill_selected'],
            'skill_executed' => $runtimeObservability['skill_executed'],
            'skill_failed' => $runtimeObservability['skill_failed'],
            'skill_execution_ms' => $runtimeObservability['skill_execution_ms'],
            'skill_result_status' => $runtimeObservability['skill_result_status'],
            'skill_fallback_reason' => $runtimeObservability['skill_fallback_reason'],
            'tool_calls_count' => $runtimeObservability['tool_calls_count'],
            'retry_count' => $runtimeObservability['retry_count'],
            'loop_guard_triggered' => $runtimeObservability['loop_guard_triggered'],
            'request_mode' => $runtimeObservability['request_mode'],
            'app_id' => $runtimeObservability['app_id'],
            'user_id' => $runtimeObservability['user_id'],
            'memory_type' => $runtimeObservability['memory_type'],
            'tool_usage' => $toolUsage,
            'module_used' => $runtimeObservability['module_used'],
            'alert_action' => $runtimeObservability['alert_action'],
            'task_action' => $runtimeObservability['task_action'],
            'reminder_action' => $runtimeObservability['reminder_action'],
            'media_action' => $runtimeObservability['media_action'],
            'entity_search_action' => $runtimeObservability['entity_search_action'],
            'pos_action' => $runtimeObservability['pos_action'],
            'purchases_action' => $runtimeObservability['purchases_action'],
            'fiscal_action' => $runtimeObservability['fiscal_action'],
            'ecommerce_action' => $runtimeObservability['ecommerce_action'],
            'access_control_action' => $runtimeObservability['access_control_action'],
            'saas_plan_action' => $runtimeObservability['saas_plan_action'],
            'usage_metering_action' => $runtimeObservability['usage_metering_action'],
            'agent_tools_action' => $runtimeObservability['agent_tools_action'],
            'agentops_action' => $runtimeObservability['agentops_action'],
            'skill_group' => $runtimeObservability['skill_group'],
            'draft_id' => $runtimeObservability['draft_id'],
            'purchase_draft_id' => $runtimeObservability['purchase_draft_id'],
            'product_id' => $runtimeObservability['product_id'],
            'matched_product_id' => $runtimeObservability['matched_product_id'],
            'matched_by' => $runtimeObservability['matched_by'],
            'product_query' => $runtimeObservability['product_query'],
            'ambiguity_count' => $runtimeObservability['ambiguity_count'],
            'ambiguity_detected' => $runtimeObservability['ambiguity_detected'],
            'purchase_id' => $runtimeObservability['purchase_id'],
            'purchase_number' => $runtimeObservability['purchase_number'],
            'purchase_document_id' => $runtimeObservability['purchase_document_id'],
            'fiscal_document_id' => $runtimeObservability['fiscal_document_id'],
            'media_file_id' => $runtimeObservability['media_file_id'],
            'supplier_id' => $runtimeObservability['supplier_id'],
            'document_type' => $runtimeObservability['document_type'],
            'source_module' => $runtimeObservability['source_module'],
            'source_entity_type' => $runtimeObservability['source_entity_type'],
            'source_entity_id' => $runtimeObservability['source_entity_id'],
            'fiscal_status' => $runtimeObservability['fiscal_status'],
            'store_id' => $runtimeObservability['store_id'],
            'platform' => $runtimeObservability['platform'],
            'adapter_key' => $runtimeObservability['adapter_key'],
            'connection_status' => $runtimeObservability['connection_status'],
            'validation_result' => $runtimeObservability['validation_result'],
            'sync_job_id' => $runtimeObservability['sync_job_id'],
            'sync_type' => $runtimeObservability['sync_type'],
            'link_id' => $runtimeObservability['link_id'],
            'external_order_id' => $runtimeObservability['external_order_id'],
            'local_reference_type' => $runtimeObservability['local_reference_type'],
            'local_reference_id' => $runtimeObservability['local_reference_id'],
            'local_product_id' => $runtimeObservability['local_product_id'],
            'external_product_id' => $runtimeObservability['external_product_id'],
            'sync_status' => $runtimeObservability['sync_status'],
            'sync_direction' => $runtimeObservability['sync_direction'],
            'target_user_id' => $runtimeObservability['target_user_id'],
            'actor_user_id' => $runtimeObservability['actor_user_id'],
            'role_key' => $runtimeObservability['role_key'],
            'permission_checked' => $runtimeObservability['permission_checked'],
            'decision' => $runtimeObservability['decision'],
            'plan_key' => $runtimeObservability['plan_key'],
            'limit_key' => $runtimeObservability['limit_key'],
            'metric_key' => $runtimeObservability['metric_key'],
            'delta_value' => $runtimeObservability['delta_value'],
            'usage_value' => $runtimeObservability['usage_value'],
            'limit_value' => $runtimeObservability['limit_value'],
            'over_limit' => $runtimeObservability['over_limit'],
            'requested_module' => $runtimeObservability['requested_module'],
            'resolved_module' => $runtimeObservability['resolved_module'],
            'enabled' => $runtimeObservability['enabled'],
            'allowed' => $runtimeObservability['allowed'],
            'denial_reason' => $runtimeObservability['denial_reason'],
            'duplicate_blocked' => $runtimeObservability['duplicate_blocked'],
            'line_count' => $runtimeObservability['line_count'],
            'total' => $runtimeObservability['total'],
            'result_status' => $runtimeObservability['result_status'],
            'pending_items_count' => $runtimeObservability['pending_items_count'],
            'token_usage' => $runtimeObservability['token_usage'],
            'cost_estimate' => $runtimeObservability['cost_estimate'],
            'metrics_delta' => $runtimeObservability['metrics_delta'],
            'error_flag' => $runtimeObservability['error_flag'],
            'error_type' => $runtimeObservability['error_type'],
            'supervisor_status' => $supervisor['status'],
            'supervisor_score' => $supervisor['score'],
            'supervisor_flags' => $supervisor['flags'],
            'supervisor_reasons' => $supervisor['reasons'],
            'needs_regression_case' => $supervisor['needs_regression_case'],
            'needs_memory_hygiene' => $supervisor['needs_memory_hygiene'],
            'needs_training_gap_review' => $supervisor['needs_training_gap_review'],
            'contract_versions' => $contractVersions,
            'versions' => $versions,
            'latency_ms' => $latencyMs,
            'enforcement_mode' => $enforcementMode !== '' ? $enforcementMode : 'unknown',
            'enforcement_mode_source' => $enforcementModeSource !== '' ? $enforcementModeSource : 'unknown',
            'enforcement_app_env' => $enforcementAppEnv !== '' ? $enforcementAppEnv : 'unknown',
            'agentops_runtime' => $runtimeObservability,
        ];
    }

    public function buildAgentOpsRuntimeObservability(array $routeTelemetry, int $latencyMs, array $runtimeContext = []): array
    {
        $routePath = (string) ($routeTelemetry['route_path'] ?? '');
        $gateDecision = (string) ($routeTelemetry['gate_decision'] ?? 'unknown');
        $actionContract = trim((string) ($routeTelemetry['action_contract'] ?? ''));
        $ragHit = (bool) ($routeTelemetry['rag_hit'] ?? false);
        $sourceIds = $this->normalizeStringList($routeTelemetry['source_ids'] ?? []);
        $evidenceIds = $this->normalizeStringList($routeTelemetry['evidence_ids'] ?? []);
        $evidenceUsed = is_array($routeTelemetry['evidence_used'] ?? null)
            ? (array) $routeTelemetry['evidence_used']
            : (is_array($runtimeContext['evidence_used'] ?? null) ? (array) $runtimeContext['evidence_used'] : []);
        $llmCalled = array_key_exists('llm_called', $runtimeContext)
            ? (bool) $runtimeContext['llm_called']
            : (bool) ($routeTelemetry['llm_called'] ?? false);
        $errorType = trim((string) ($runtimeContext['error_type'] ?? $routeTelemetry['error_type'] ?? ''));
        $errorFlag = array_key_exists('error_flag', $runtimeContext)
            ? (bool) $runtimeContext['error_flag']
            : ($errorType !== '');
        if ($errorType === '') {
            $errorType = $errorFlag ? 'runtime_error' : 'none';
        }

        $tokenUsage = is_array($runtimeContext['usage'] ?? null)
            ? (array) $runtimeContext['usage']
            : (is_array($routeTelemetry['token_usage'] ?? null) ? (array) $routeTelemetry['token_usage'] : null);
        $costEstimate = $runtimeContext['cost_estimate'] ?? ($routeTelemetry['cost_estimate'] ?? null);
        if (!is_numeric($costEstimate)) {
            $costEstimate = null;
        }

        $stageLatency = [
            'router_ms' => max(0, (int) ($routeTelemetry['router_latency_ms'] ?? 0)),
            'skill_ms' => max(0, (int) ($routeTelemetry['skill_execution_ms'] ?? 0)),
            'rag_ms' => max(0, (int) (($routeTelemetry['retrieval']['retrieval_latency_ms'] ?? $routeTelemetry['retrieval_latency_ms'] ?? 0))),
        ];
        $taskId = trim((string) ($runtimeContext['task_id'] ?? $routeTelemetry['task_id'] ?? ''));
        $conversationId = trim((string) ($runtimeContext['conversation_id'] ?? $routeTelemetry['conversation_id'] ?? ''));
        $toolUsage = [
            'tool_calls_count' => max(0, (int) ($runtimeContext['tool_calls_count'] ?? $routeTelemetry['tool_calls_count'] ?? 0)),
            'module_key' => trim((string) ($runtimeContext['module_used'] ?? $routeTelemetry['module_used'] ?? '')) ?: 'none',
            'action_key' => trim((string) ($runtimeContext['task_action'] ?? $routeTelemetry['task_action'] ?? '')) ?: 'none',
            'skill_selected' => trim((string) ($routeTelemetry['skill_selected'] ?? '')) ?: 'none',
        ];

        return [
            'route_path' => $routePath !== '' ? $routePath : 'unknown',
            'gate_decision' => $gateDecision !== '' ? $gateDecision : 'unknown',
            'task_id' => $taskId,
            'conversation_id' => $conversationId,
            'action_contract' => $actionContract !== '' ? $actionContract : 'none',
            'route_reason' => trim((string) ($routeTelemetry['route_reason'] ?? '')) ?: 'unknown',
            'rag_hit' => $ragHit,
            'source_ids' => $sourceIds,
            'evidence_ids' => $evidenceIds,
            'evidence_used' => $evidenceUsed,
            'semantic_enabled' => (bool) ($routeTelemetry['semantic_enabled'] ?? false),
            'semantic_memory_status' => trim((string) ($routeTelemetry['semantic_memory_status'] ?? '')) ?: 'unknown',
            'rag_attempted' => (bool) ($routeTelemetry['rag_attempted'] ?? false),
            'rag_used' => (bool) ($routeTelemetry['rag_used'] ?? false),
            'rag_result_count' => max(0, (int) ($routeTelemetry['rag_result_count'] ?? 0)),
            'evidence_gate_status' => trim((string) ($routeTelemetry['evidence_gate_status'] ?? '')) ?: 'unknown',
            'fallback_reason' => trim((string) ($routeTelemetry['fallback_reason'] ?? '')) ?: 'none',
            'skill_detected' => (bool) ($routeTelemetry['skill_detected'] ?? false),
            'skill_selected' => trim((string) ($routeTelemetry['skill_selected'] ?? '')) ?: 'none',
            'skill_executed' => (bool) ($routeTelemetry['skill_executed'] ?? false),
            'skill_failed' => (bool) ($routeTelemetry['skill_failed'] ?? false),
            'skill_execution_ms' => max(0, (int) ($routeTelemetry['skill_execution_ms'] ?? 0)),
            'skill_result_status' => trim((string) ($routeTelemetry['skill_result_status'] ?? '')) ?: 'unknown',
            'skill_fallback_reason' => trim((string) ($routeTelemetry['skill_fallback_reason'] ?? '')) ?: 'none',
            'llm_called' => $llmCalled,
            'llm_used' => $llmCalled,
            'tool_calls_count' => max(0, (int) ($runtimeContext['tool_calls_count'] ?? $routeTelemetry['tool_calls_count'] ?? 0)),
            'retry_count' => max(0, (int) ($runtimeContext['retry_count'] ?? $routeTelemetry['retry_count'] ?? 0)),
            'llm_fallback_count' => max(0, (int) ($routeTelemetry['llm_fallback_count'] ?? 0)),
            'loop_guard_triggered' => (bool) ($routeTelemetry['loop_guard_triggered'] ?? false),
            'loop_guard_reason' => trim((string) ($routeTelemetry['loop_guard_reason'] ?? '')) ?: 'none',
            'loop_guard_stage' => trim((string) ($routeTelemetry['loop_guard_stage'] ?? '')) ?: 'none',
            'same_route_repeat_count' => max(0, (int) ($routeTelemetry['same_route_repeat_count'] ?? 0)),
            'request_mode' => trim((string) ($routeTelemetry['request_mode'] ?? 'operation')) ?: 'operation',
            'memory_type' => trim((string) ($routeTelemetry['memory_type'] ?? '')) ?: 'none',
            'module_used' => trim((string) ($runtimeContext['module_used'] ?? $routeTelemetry['module_used'] ?? '')) ?: 'none',
            'alert_action' => trim((string) ($runtimeContext['alert_action'] ?? $routeTelemetry['alert_action'] ?? '')) ?: 'none',
            'task_action' => trim((string) ($runtimeContext['task_action'] ?? $routeTelemetry['task_action'] ?? '')) ?: 'none',
            'reminder_action' => trim((string) ($runtimeContext['reminder_action'] ?? $routeTelemetry['reminder_action'] ?? '')) ?: 'none',
            'media_action' => trim((string) ($runtimeContext['media_action'] ?? $routeTelemetry['media_action'] ?? '')) ?: 'none',
            'entity_search_action' => trim((string) ($runtimeContext['entity_search_action'] ?? $routeTelemetry['entity_search_action'] ?? '')) ?: 'none',
            'pos_action' => trim((string) ($runtimeContext['pos_action'] ?? $routeTelemetry['pos_action'] ?? '')) ?: 'none',
            'purchases_action' => trim((string) ($runtimeContext['purchases_action'] ?? $routeTelemetry['purchases_action'] ?? '')) ?: 'none',
            'fiscal_action' => trim((string) ($runtimeContext['fiscal_action'] ?? $routeTelemetry['fiscal_action'] ?? '')) ?: 'none',
            'ecommerce_action' => trim((string) ($runtimeContext['ecommerce_action'] ?? $routeTelemetry['ecommerce_action'] ?? '')) ?: 'none',
            'access_control_action' => trim((string) ($runtimeContext['access_control_action'] ?? $routeTelemetry['access_control_action'] ?? '')) ?: 'none',
            'saas_plan_action' => trim((string) ($runtimeContext['saas_plan_action'] ?? $routeTelemetry['saas_plan_action'] ?? '')) ?: 'none',
            'usage_metering_action' => trim((string) ($runtimeContext['usage_metering_action'] ?? $routeTelemetry['usage_metering_action'] ?? '')) ?: 'none',
            'agent_tools_action' => trim((string) ($runtimeContext['agent_tools_action'] ?? $routeTelemetry['agent_tools_action'] ?? '')) ?: 'none',
            'agentops_action' => trim((string) ($runtimeContext['agentops_action'] ?? $routeTelemetry['agentops_action'] ?? '')) ?: 'none',
            'skill_group' => $this->preferRuntimeOrRouteString($runtimeContext, $routeTelemetry, 'skill_group', 'unknown'),
            'draft_id' => trim((string) ($runtimeContext['draft_id'] ?? $routeTelemetry['draft_id'] ?? '')),
            'purchase_draft_id' => trim((string) ($runtimeContext['purchase_draft_id'] ?? $routeTelemetry['purchase_draft_id'] ?? '')),
            'session_id' => trim((string) ($runtimeContext['session_id'] ?? $routeTelemetry['session_id'] ?? '')),
            'product_id' => trim((string) ($runtimeContext['product_id'] ?? $routeTelemetry['product_id'] ?? '')),
            'matched_product_id' => trim((string) ($runtimeContext['matched_product_id'] ?? $routeTelemetry['matched_product_id'] ?? '')),
            'matched_by' => trim((string) ($runtimeContext['matched_by'] ?? $routeTelemetry['matched_by'] ?? '')),
            'product_query' => trim((string) ($runtimeContext['product_query'] ?? $routeTelemetry['product_query'] ?? '')),
            'ambiguity_count' => is_numeric($runtimeContext['ambiguity_count'] ?? $routeTelemetry['ambiguity_count'] ?? null)
                ? max(0, (int) ($runtimeContext['ambiguity_count'] ?? $routeTelemetry['ambiguity_count']))
                : 0,
            'ambiguity_detected' => $this->preferRuntimeOrRouteBool($runtimeContext, $routeTelemetry, 'ambiguity_detected'),
            'purchase_id' => trim((string) ($runtimeContext['purchase_id'] ?? $routeTelemetry['purchase_id'] ?? '')),
            'purchase_number' => trim((string) ($runtimeContext['purchase_number'] ?? $routeTelemetry['purchase_number'] ?? '')),
            'purchase_document_id' => trim((string) ($runtimeContext['purchase_document_id'] ?? $routeTelemetry['purchase_document_id'] ?? '')),
            'fiscal_document_id' => trim((string) ($runtimeContext['fiscal_document_id'] ?? $routeTelemetry['fiscal_document_id'] ?? '')),
            'media_file_id' => trim((string) ($runtimeContext['media_file_id'] ?? $routeTelemetry['media_file_id'] ?? '')),
            'supplier_id' => trim((string) ($runtimeContext['supplier_id'] ?? $routeTelemetry['supplier_id'] ?? '')),
            'document_type' => trim((string) ($runtimeContext['document_type'] ?? $routeTelemetry['document_type'] ?? '')),
            'source_module' => trim((string) ($runtimeContext['source_module'] ?? $routeTelemetry['source_module'] ?? '')),
            'source_entity_type' => trim((string) ($runtimeContext['source_entity_type'] ?? $routeTelemetry['source_entity_type'] ?? '')),
            'source_entity_id' => trim((string) ($runtimeContext['source_entity_id'] ?? $routeTelemetry['source_entity_id'] ?? '')),
            'fiscal_status' => trim((string) ($runtimeContext['fiscal_status'] ?? $routeTelemetry['fiscal_status'] ?? '')),
            'store_id' => trim((string) ($runtimeContext['store_id'] ?? $routeTelemetry['store_id'] ?? '')),
            'platform' => trim((string) ($runtimeContext['platform'] ?? $routeTelemetry['platform'] ?? '')),
            'adapter_key' => trim((string) ($runtimeContext['adapter_key'] ?? $routeTelemetry['adapter_key'] ?? '')) ?: 'none',
            'connection_status' => trim((string) ($runtimeContext['connection_status'] ?? $routeTelemetry['connection_status'] ?? '')),
            'validation_result' => trim((string) ($runtimeContext['validation_result'] ?? $routeTelemetry['validation_result'] ?? '')) ?: 'none',
            'sync_job_id' => trim((string) ($runtimeContext['sync_job_id'] ?? $routeTelemetry['sync_job_id'] ?? '')),
            'sync_type' => trim((string) ($runtimeContext['sync_type'] ?? $routeTelemetry['sync_type'] ?? '')),
            'link_id' => trim((string) ($runtimeContext['link_id'] ?? $routeTelemetry['link_id'] ?? '')),
            'external_order_id' => trim((string) ($runtimeContext['external_order_id'] ?? $routeTelemetry['external_order_id'] ?? '')),
            'local_reference_type' => trim((string) ($runtimeContext['local_reference_type'] ?? $routeTelemetry['local_reference_type'] ?? '')),
            'local_reference_id' => trim((string) ($runtimeContext['local_reference_id'] ?? $routeTelemetry['local_reference_id'] ?? '')),
            'local_product_id' => trim((string) ($runtimeContext['local_product_id'] ?? $routeTelemetry['local_product_id'] ?? '')),
            'external_product_id' => trim((string) ($runtimeContext['external_product_id'] ?? $routeTelemetry['external_product_id'] ?? '')),
            'sync_status' => trim((string) ($runtimeContext['sync_status'] ?? $routeTelemetry['sync_status'] ?? '')),
            'sync_direction' => trim((string) ($runtimeContext['sync_direction'] ?? $routeTelemetry['sync_direction'] ?? '')),
            'target_user_id' => trim((string) ($runtimeContext['target_user_id'] ?? $routeTelemetry['target_user_id'] ?? '')),
            'actor_user_id' => trim((string) ($runtimeContext['actor_user_id'] ?? $routeTelemetry['actor_user_id'] ?? '')),
            'role_key' => trim((string) ($runtimeContext['role_key'] ?? $routeTelemetry['role_key'] ?? '')),
            'permission_checked' => trim((string) ($runtimeContext['permission_checked'] ?? $routeTelemetry['permission_checked'] ?? '')),
            'decision' => trim((string) ($runtimeContext['decision'] ?? $routeTelemetry['decision'] ?? '')),
            'plan_key' => trim((string) ($runtimeContext['plan_key'] ?? $routeTelemetry['plan_key'] ?? '')),
            'limit_key' => trim((string) ($runtimeContext['limit_key'] ?? $routeTelemetry['limit_key'] ?? '')),
            'metric_key' => trim((string) ($runtimeContext['metric_key'] ?? $routeTelemetry['metric_key'] ?? '')),
            'delta_value' => is_numeric($runtimeContext['delta_value'] ?? $routeTelemetry['delta_value'] ?? null)
                ? (float) ($runtimeContext['delta_value'] ?? $routeTelemetry['delta_value'])
                : null,
            'usage_value' => is_numeric($runtimeContext['usage_value'] ?? $routeTelemetry['usage_value'] ?? null)
                ? (float) ($runtimeContext['usage_value'] ?? $routeTelemetry['usage_value'])
                : null,
            'limit_value' => is_numeric($runtimeContext['limit_value'] ?? $routeTelemetry['limit_value'] ?? null)
                ? (float) ($runtimeContext['limit_value'] ?? $routeTelemetry['limit_value'])
                : null,
            'over_limit' => (($runtimeContext['over_limit'] ?? $routeTelemetry['over_limit'] ?? false) === true),
            'requested_module' => trim((string) ($runtimeContext['requested_module'] ?? $routeTelemetry['requested_module'] ?? '')),
            'resolved_module' => trim((string) ($runtimeContext['resolved_module'] ?? $routeTelemetry['resolved_module'] ?? '')),
            'enabled' => array_key_exists('enabled', $runtimeContext) || array_key_exists('enabled', $routeTelemetry)
                ? (($runtimeContext['enabled'] ?? $routeTelemetry['enabled'] ?? false) === true)
                : null,
            'allowed' => array_key_exists('allowed', $runtimeContext) || array_key_exists('allowed', $routeTelemetry)
                ? (($runtimeContext['allowed'] ?? $routeTelemetry['allowed'] ?? false) === true)
                : null,
            'denial_reason' => trim((string) ($runtimeContext['denial_reason'] ?? $routeTelemetry['denial_reason'] ?? '')),
            'duplicate_blocked' => (($runtimeContext['duplicate_blocked'] ?? $routeTelemetry['duplicate_blocked'] ?? false) === true),
            'line_count' => is_numeric($runtimeContext['line_count'] ?? $routeTelemetry['line_count'] ?? null)
                ? max(0, (int) ($runtimeContext['line_count'] ?? $routeTelemetry['line_count']))
                : null,
            'total' => is_numeric($runtimeContext['total'] ?? $routeTelemetry['total'] ?? null)
                ? (float) ($runtimeContext['total'] ?? $routeTelemetry['total'])
                : null,
            'result_status' => trim((string) ($runtimeContext['result_status'] ?? $routeTelemetry['result_status'] ?? '')) ?: 'unknown',
            'pending_items_count' => is_numeric($runtimeContext['pending_items_count'] ?? $routeTelemetry['pending_items_count'] ?? null)
                ? max(0, (int) ($runtimeContext['pending_items_count'] ?? $routeTelemetry['pending_items_count']))
                : null,
            'tenant_id' => trim((string) ($routeTelemetry['tenant_id'] ?? '')) ?: 'default',
            'app_id' => ($routeTelemetry['app_id'] ?? null),
            'user_id' => trim((string) ($routeTelemetry['user_id'] ?? '')) ?: 'anon',
            'query_hash' => trim((string) ($routeTelemetry['query_hash'] ?? '')),
            'runtime_budget' => is_array($routeTelemetry['runtime_budget'] ?? null) ? (array) $routeTelemetry['runtime_budget'] : [],
            'stage_latency_ms' => $stageLatency,
            'latency_ms' => $latencyMs,
            'token_usage' => $tokenUsage,
            'cost_estimate' => $costEstimate,
            'tool_usage' => $toolUsage,
            'contract_versions' => $this->resolveExtendedContractVersions(
                is_array($routeTelemetry['contract_versions'] ?? null)
                    ? (array) $routeTelemetry['contract_versions']
                    : []
            ),
            'metrics_delta' => is_array($routeTelemetry['metrics_delta'] ?? null) ? (array) $routeTelemetry['metrics_delta'] : [],
            'tenant_scope_violation_detected' => (bool) ($routeTelemetry['tenant_scope_violation_detected'] ?? false),
            'route_path_coherent' => (bool) ($routeTelemetry['route_path_coherent'] ?? true),
            'rag_error' => trim((string) ($routeTelemetry['rag_error'] ?? '')),
            'error_flag' => $errorFlag,
            'error_type' => $errorType,
        ];
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     * @return array{runtime: array<string, mixed>, supervisor: array<string, mixed>}
     */
    public function buildAgentOpsRuntimeEnvelope(array $routeTelemetry, int $latencyMs, array $runtimeContext = []): array
    {
        $runtimeObservability = $this->buildAgentOpsRuntimeObservability($routeTelemetry, $latencyMs, $runtimeContext);

        try {
            $supervisor = $this->supervisor->evaluate($runtimeObservability, $routeTelemetry, $runtimeContext);
        } catch (\Throwable $ignored) {
            $supervisor = [
                'status' => 'needs_review',
                'score' => 0,
                'flags' => [],
                'reasons' => ['AgentOps Supervisor no pudo evaluar este turno.'],
                'route_path' => $runtimeObservability['route_path'],
                'skill_selected' => $runtimeObservability['skill_selected'],
                'rag_used' => $runtimeObservability['rag_used'],
                'evidence_gate_status' => $runtimeObservability['evidence_gate_status'],
                'fallback_reason' => $runtimeObservability['fallback_reason'],
                'needs_regression_case' => true,
                'needs_memory_hygiene' => false,
                'needs_training_gap_review' => false,
            ];
        }

        $runtimeObservability['supervisor'] = $supervisor;

        return [
            'runtime' => $runtimeObservability,
            'supervisor' => $supervisor,
        ];
    }

    /**
     * @param array<string, mixed> $runtimeObservability
     */
    public function resolveDecisionSelectedModule(array $runtimeObservability): string
    {
        foreach (['resolved_module', 'module_used', 'requested_module'] as $field) {
            $value = trim((string) ($runtimeObservability[$field] ?? ''));
            if ($value !== '' && $value !== 'none') {
                return $value;
            }
        }

        return 'none';
    }

    /**
     * @param array<string, mixed> $runtimeObservability
     */
    public function resolveDecisionSelectedAction(array $runtimeObservability): string
    {
        foreach ([
            'agentops_action',
            'agent_tools_action',
            'usage_metering_action',
            'saas_plan_action',
            'access_control_action',
            'ecommerce_action',
            'fiscal_action',
            'purchases_action',
            'pos_action',
            'entity_search_action',
            'media_action',
            'alert_action',
            'task_action',
            'reminder_action',
        ] as $field) {
            $value = trim((string) ($runtimeObservability[$field] ?? ''));
            if ($value !== '' && $value !== 'none') {
                return $value;
            }
        }

        $contractAction = trim((string) ($runtimeObservability['action_contract'] ?? ''));
        if ($contractAction !== '' && $contractAction !== 'none') {
            return $contractAction;
        }

        return trim((string) ($runtimeObservability['skill_selected'] ?? '')) ?: 'none';
    }

    /**
     * @param array<string, mixed> $runtimeObservability
     */
    public function resolveDecisionEvidenceSource(array $runtimeObservability): string
    {
        if (($runtimeObservability['rag_used'] ?? false) === true) {
            return 'rag';
        }
        if (($runtimeObservability['skill_detected'] ?? false) === true) {
            return 'skills';
        }
        if (($runtimeObservability['llm_called'] ?? false) === true) {
            return 'llm';
        }
        if (str_contains((string) ($runtimeObservability['route_path'] ?? ''), 'cache')) {
            return 'cache';
        }

        return 'rules';
    }

    /**
     * @param array<string, mixed> $runtimeObservability
     */
    public function resolveDecisionResultStatus(array $runtimeObservability): string
    {
        if (($runtimeObservability['error_flag'] ?? false) === true) {
            return 'error';
        }

        return trim((string) ($runtimeObservability['result_status'] ?? '')) ?: 'unknown';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    public function recordToolExecutionTrace(
        string $tenantId,
        string $projectId,
        string $sessionId,
        array $routeTelemetry,
        array $runtimeContext = []
    ): void {
        try {
            $success = (($runtimeContext['success'] ?? false) === true);
            $this->observabilityService->recordToolExecutionTrace([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'module_key' => $this->resolveTraceModuleKey($routeTelemetry, $runtimeContext),
                'action_key' => $this->resolveTraceActionKey($routeTelemetry, $runtimeContext),
                'input_schema_valid' => $this->resolveSchemaGatePassed($routeTelemetry),
                'permission_check' => $this->resolvePermissionTraceStatus($routeTelemetry, $runtimeContext),
                'plan_check' => $this->resolvePlanTraceStatus($routeTelemetry, $runtimeContext),
                'execution_latency' => max(0, (int) ($runtimeContext['execution_latency'] ?? 0)),
                'success' => $success,
                'error_code' => $this->resolveToolTraceErrorCode($routeTelemetry, $runtimeContext),
                'metadata_json' => [
                    'session_id' => $sessionId,
                    'route_path' => (string) ($routeTelemetry['route_path'] ?? ''),
                    'gate_decision' => (string) ($routeTelemetry['gate_decision'] ?? ''),
                    'result_status' => (string) ($runtimeContext['result_status'] ?? $routeTelemetry['result_status'] ?? ''),
                    'permission_checked' => (string) ($routeTelemetry['permission_checked'] ?? ''),
                    'denial_reason' => (string) ($routeTelemetry['denial_reason'] ?? ''),
                    'requested_module' => (string) ($routeTelemetry['requested_module'] ?? ''),
                    'resolved_module' => (string) ($routeTelemetry['resolved_module'] ?? ''),
                    'command_name' => (string) ($runtimeContext['command_name'] ?? ''),
                    'task_id' => (string) ($runtimeContext['task_id'] ?? $routeTelemetry['task_id'] ?? ''),
                    'conversation_id' => (string) ($runtimeContext['conversation_id'] ?? $routeTelemetry['conversation_id'] ?? ''),
                ],
            ]);
        } catch (\Throwable $ignored) {
            // tool execution trace persistence must not block chat response
        }
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     */
    public function shouldTraceBlockedToolExecution(array $routeTelemetry): bool
    {
        $actionContract = trim((string) ($routeTelemetry['action_contract'] ?? ''));
        $gateDecision = trim((string) ($routeTelemetry['gate_decision'] ?? ''));

        return $actionContract !== '' && $actionContract !== 'none' && $gateDecision === 'blocked';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    public function resolveTraceModuleKey(array $routeTelemetry, array $runtimeContext = []): string
    {
        foreach (['resolved_module', 'requested_module', 'module_used'] as $field) {
            $value = trim((string) ($runtimeContext[$field] ?? $routeTelemetry[$field] ?? ''));
            if ($value !== '' && $value !== 'none') {
                return $value;
            }
        }

        $actionContract = trim((string) ($routeTelemetry['action_contract'] ?? ''));
        if ($actionContract !== '' && $actionContract !== 'none' && str_contains($actionContract, '.')) {
            return trim((string) strstr($actionContract, '.', true)) ?: 'none';
        }

        return 'none';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    public function resolveTraceActionKey(array $routeTelemetry, array $runtimeContext = []): string
    {
        foreach ([
            'agentops_action',
            'agent_tools_action',
            'usage_metering_action',
            'saas_plan_action',
            'access_control_action',
            'ecommerce_action',
            'fiscal_action',
            'purchases_action',
            'pos_action',
            'entity_search_action',
            'media_action',
            'alert_action',
            'task_action',
            'reminder_action',
        ] as $field) {
            $value = trim((string) ($runtimeContext[$field] ?? $routeTelemetry[$field] ?? ''));
            if ($value !== '' && $value !== 'none') {
                return $value;
            }
        }

        $actionContract = trim((string) ($routeTelemetry['action_contract'] ?? ''));
        if ($actionContract !== '' && $actionContract !== 'none' && str_contains($actionContract, '.')) {
            $parts = explode('.', $actionContract, 2);
            return trim((string) ($parts[1] ?? '')) ?: 'none';
        }

        return trim((string) ($runtimeContext['command_name'] ?? '')) ?: 'none';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     */
    public function resolveSchemaGatePassed(array $routeTelemetry): bool
    {
        $gateResult = $this->gateResultPassed($routeTelemetry, 'schema_gate');
        if ($gateResult !== null) {
            return $gateResult;
        }

        foreach ((array) ($routeTelemetry['contract_violations'] ?? []) as $violation) {
            if (str_starts_with((string) $violation, 'gate_schema_invalid:')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    public function resolvePermissionTraceStatus(array $routeTelemetry, array $runtimeContext = []): string
    {
        $explicit = trim((string) ($runtimeContext['permission_check'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $decision = strtolower(trim((string) ($routeTelemetry['decision'] ?? '')));
        if ($decision === 'allow') {
            return 'allow';
        }
        if ($decision === 'deny') {
            return 'deny';
        }

        $authGate = $this->gateResultPassed($routeTelemetry, 'auth_rbac_gate');
        if ($authGate === false) {
            return 'deny';
        }
        if ($authGate === true && trim((string) ($routeTelemetry['permission_checked'] ?? '')) !== '') {
            return 'allow';
        }

        foreach ((array) ($routeTelemetry['contract_violations'] ?? []) as $violation) {
            if (str_starts_with((string) $violation, 'gate_auth_rbac_failed:')) {
                return 'deny';
            }
        }

        return 'not_checked';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    public function resolvePlanTraceStatus(array $routeTelemetry, array $runtimeContext = []): string
    {
        $explicit = trim((string) ($runtimeContext['plan_check'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        if (($routeTelemetry['over_limit'] ?? false) === true) {
            return 'warn:over_limit';
        }

        $denialReason = trim((string) ($routeTelemetry['denial_reason'] ?? ''));
        if ($denialReason === 'module_disabled_by_plan' || $denialReason === 'plan_not_assigned') {
            return 'disabled';
        }

        if (array_key_exists('enabled', $routeTelemetry)) {
            return (($routeTelemetry['enabled'] ?? false) === true) ? 'enabled' : 'disabled';
        }

        if (trim((string) ($routeTelemetry['limit_key'] ?? '')) !== '') {
            return 'checked';
        }

        return 'not_checked';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    public function resolveToolTraceErrorCode(array $routeTelemetry, array $runtimeContext = []): ?string
    {
        $explicit = trim((string) ($runtimeContext['error_code'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        if (($runtimeContext['success'] ?? false) === true) {
            return null;
        }

        $denialReason = trim((string) ($routeTelemetry['denial_reason'] ?? ''));
        if ($denialReason !== '') {
            return $denialReason;
        }

        $errorType = trim((string) ($runtimeContext['error_type'] ?? $routeTelemetry['error_type'] ?? ''));
        if ($errorType !== '' && $errorType !== 'none') {
            return $errorType;
        }

        foreach ((array) ($routeTelemetry['contract_violations'] ?? []) as $violation) {
            $violation = (string) $violation;
            if (str_starts_with($violation, 'gate_auth_rbac_failed:')) {
                return 'permission_denied';
            }
            if (str_starts_with($violation, 'gate_tenant_scope_failed:')) {
                return 'tenant_scope_denied';
            }
            if (str_starts_with($violation, 'gate_schema_invalid:')) {
                return 'schema_invalid';
            }
            if (str_starts_with($violation, 'minimum_evidence_missing:')) {
                return 'minimum_evidence_missing';
            }
        }

        return 'execution_failed';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     */
    public function gateResultPassed(array $routeTelemetry, string $gateName): ?bool
    {
        $gateResults = is_array($routeTelemetry['gate_results'] ?? null) ? (array) $routeTelemetry['gate_results'] : [];
        foreach ($gateResults as $gateResult) {
            if (!is_array($gateResult)) {
                continue;
            }
            if ((string) ($gateResult['gate'] ?? '') !== $gateName) {
                continue;
            }

            return (($gateResult['passed'] ?? false) === true);
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public function normalizeStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $candidate = trim((string) $item);
            if ($candidate === '') {
                continue;
            }
            $normalized[] = $candidate;
        }

        if (empty($normalized)) {
            return [];
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function extractOperationalTelemetryMarkers(array $payload): array
    {
        $pendingItemsCount = $payload['pending_items_count'] ?? null;
        if (!is_numeric($pendingItemsCount)) {
            $pendingItemsCount = null;
        }

        return [
            'task_id' => trim((string) ($payload['task_id'] ?? '')) ?: '',
            'conversation_id' => trim((string) ($payload['conversation_id'] ?? '')) ?: '',
            'module_used' => trim((string) ($payload['module_used'] ?? '')) ?: 'none',
            'alert_action' => trim((string) ($payload['alert_action'] ?? '')) ?: 'none',
            'task_action' => trim((string) ($payload['task_action'] ?? '')) ?: 'none',
            'reminder_action' => trim((string) ($payload['reminder_action'] ?? '')) ?: 'none',
            'media_action' => trim((string) ($payload['media_action'] ?? '')) ?: 'none',
            'entity_search_action' => trim((string) ($payload['entity_search_action'] ?? '')) ?: 'none',
            'pos_action' => trim((string) ($payload['pos_action'] ?? '')) ?: 'none',
            'purchases_action' => trim((string) ($payload['purchases_action'] ?? '')) ?: 'none',
            'fiscal_action' => trim((string) ($payload['fiscal_action'] ?? '')) ?: 'none',
            'ecommerce_action' => trim((string) ($payload['ecommerce_action'] ?? '')) ?: 'none',
            'access_control_action' => trim((string) ($payload['access_control_action'] ?? '')) ?: 'none',
            'saas_plan_action' => trim((string) ($payload['saas_plan_action'] ?? '')) ?: 'none',
            'usage_metering_action' => trim((string) ($payload['usage_metering_action'] ?? '')) ?: 'none',
            'agent_tools_action' => trim((string) ($payload['agent_tools_action'] ?? '')) ?: 'none',
            'agentops_action' => trim((string) ($payload['agentops_action'] ?? '')) ?: 'none',
            'skill_group' => trim((string) ($payload['skill_group'] ?? '')) ?: '',
            'draft_id' => trim((string) ($payload['draft_id'] ?? '')) ?: '',
            'purchase_draft_id' => trim((string) ($payload['purchase_draft_id'] ?? '')) ?: '',
            'session_id' => trim((string) ($payload['session_id'] ?? '')) ?: '',
            'product_id' => trim((string) ($payload['product_id'] ?? '')) ?: '',
            'matched_product_id' => trim((string) ($payload['matched_product_id'] ?? '')) ?: '',
            'matched_by' => trim((string) ($payload['matched_by'] ?? '')) ?: '',
            'product_query' => trim((string) ($payload['product_query'] ?? '')) ?: '',
            'ambiguity_count' => is_numeric($payload['ambiguity_count'] ?? null)
                ? max(0, (int) $payload['ambiguity_count'])
                : 0,
            'ambiguity_detected' => (($payload['ambiguity_detected'] ?? false) === true),
            'purchase_id' => trim((string) ($payload['purchase_id'] ?? '')) ?: '',
            'purchase_number' => trim((string) ($payload['purchase_number'] ?? '')) ?: '',
            'purchase_document_id' => trim((string) ($payload['purchase_document_id'] ?? '')) ?: '',
            'fiscal_document_id' => trim((string) ($payload['fiscal_document_id'] ?? '')) ?: '',
            'media_file_id' => trim((string) ($payload['media_file_id'] ?? '')) ?: '',
            'supplier_id' => trim((string) ($payload['supplier_id'] ?? '')) ?: '',
            'document_type' => trim((string) ($payload['document_type'] ?? '')) ?: '',
            'source_module' => trim((string) ($payload['source_module'] ?? '')) ?: '',
            'source_entity_type' => trim((string) ($payload['source_entity_type'] ?? '')) ?: '',
            'source_entity_id' => trim((string) ($payload['source_entity_id'] ?? '')) ?: '',
            'fiscal_status' => trim((string) ($payload['fiscal_status'] ?? '')) ?: '',
            'store_id' => trim((string) ($payload['store_id'] ?? '')) ?: '',
            'platform' => trim((string) ($payload['platform'] ?? '')) ?: '',
            'adapter_key' => trim((string) ($payload['adapter_key'] ?? '')) ?: '',
            'connection_status' => trim((string) ($payload['connection_status'] ?? '')) ?: '',
            'validation_result' => trim((string) ($payload['validation_result'] ?? '')) ?: '',
            'sync_job_id' => trim((string) ($payload['sync_job_id'] ?? '')) ?: '',
            'sync_type' => trim((string) ($payload['sync_type'] ?? '')) ?: '',
            'link_id' => trim((string) ($payload['link_id'] ?? '')) ?: '',
            'external_order_id' => trim((string) ($payload['external_order_id'] ?? '')) ?: '',
            'local_reference_type' => trim((string) ($payload['local_reference_type'] ?? '')) ?: '',
            'local_reference_id' => trim((string) ($payload['local_reference_id'] ?? '')) ?: '',
            'local_product_id' => trim((string) ($payload['local_product_id'] ?? '')) ?: '',
            'external_product_id' => trim((string) ($payload['external_product_id'] ?? '')) ?: '',
            'sync_status' => trim((string) ($payload['sync_status'] ?? '')) ?: '',
            'sync_direction' => trim((string) ($payload['sync_direction'] ?? '')) ?: '',
            'target_user_id' => trim((string) ($payload['target_user_id'] ?? '')) ?: '',
            'actor_user_id' => trim((string) ($payload['actor_user_id'] ?? '')) ?: '',
            'role_key' => trim((string) ($payload['role_key'] ?? '')) ?: '',
            'permission_checked' => trim((string) ($payload['permission_checked'] ?? '')) ?: '',
            'decision' => trim((string) ($payload['decision'] ?? '')) ?: '',
            'plan_key' => trim((string) ($payload['plan_key'] ?? '')) ?: '',
            'limit_key' => trim((string) ($payload['limit_key'] ?? '')) ?: '',
            'metric_key' => trim((string) ($payload['metric_key'] ?? '')) ?: '',
            'delta_value' => is_numeric($payload['delta_value'] ?? null)
                ? (float) $payload['delta_value']
                : null,
            'usage_value' => is_numeric($payload['usage_value'] ?? null)
                ? (float) $payload['usage_value']
                : null,
            'limit_value' => is_numeric($payload['limit_value'] ?? null)
                ? (float) $payload['limit_value']
                : null,
            'over_limit' => (($payload['over_limit'] ?? false) === true),
            'requested_module' => trim((string) ($payload['requested_module'] ?? '')) ?: '',
            'resolved_module' => trim((string) ($payload['resolved_module'] ?? '')) ?: '',
            'enabled' => array_key_exists('enabled', $payload)
                ? (($payload['enabled'] ?? false) === true)
                : null,
            'allowed' => array_key_exists('allowed', $payload)
                ? (($payload['allowed'] ?? false) === true)
                : null,
            'denial_reason' => trim((string) ($payload['denial_reason'] ?? '')) ?: '',
            'duplicate_blocked' => (($payload['duplicate_blocked'] ?? false) === true),
            'line_count' => is_numeric($payload['line_count'] ?? null)
                ? max(0, (int) $payload['line_count'])
                : null,
            'total' => is_numeric($payload['total'] ?? null)
                ? (float) $payload['total']
                : null,
            'result_status' => trim((string) ($payload['result_status'] ?? '')) ?: '',
            'result_count' => is_numeric($payload['result_count'] ?? null)
                ? max(0, (int) $payload['result_count'])
                : null,
            'resolved' => array_key_exists('resolved', $payload)
                ? (bool) $payload['resolved']
                : null,
            'needs_clarification' => array_key_exists('needs_clarification', $payload)
                ? (bool) $payload['needs_clarification']
                : null,
            'pending_items_count' => $pendingItemsCount !== null ? max(0, (int) $pendingItemsCount) : null,
        ];
    }

    public function preferRuntimeOrRouteString(array $runtimeContext, array $routeTelemetry, string $key, string $default = ''): string
    {
        $runtimeValue = trim((string) ($runtimeContext[$key] ?? ''));
        if ($runtimeValue !== '') {
            return $runtimeValue;
        }

        $routeValue = trim((string) ($routeTelemetry[$key] ?? ''));
        if ($routeValue !== '') {
            return $routeValue;
        }

        return $default;
    }

    public function preferRuntimeOrRouteBool(array $runtimeContext, array $routeTelemetry, string $key): bool
    {
        if (array_key_exists($key, $runtimeContext)) {
            return $runtimeContext[$key] === true;
        }

        return ($routeTelemetry[$key] ?? false) === true;
    }
}
