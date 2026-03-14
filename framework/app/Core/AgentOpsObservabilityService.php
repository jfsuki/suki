<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AgentOpsObservabilityService
{
    private MetricsRepositoryInterface $metrics;

    public function __construct(?MetricsRepositoryInterface $metrics = null)
    {
        $this->metrics = $metrics ?? new SqlMetricsRepository();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordDecisionTrace(array $payload): array
    {
        $record = [
            'tenant_id' => $this->requireString($payload['tenant_id'] ?? null, 'tenant_id'),
            'project_id' => $this->normalizeProjectId($payload['project_id'] ?? null),
            'session_id' => trim((string) ($payload['session_id'] ?? '')),
            'route_path' => trim((string) ($payload['route_path'] ?? '')) ?: 'unknown',
            'selected_module' => trim((string) ($payload['selected_module'] ?? '')) ?: 'none',
            'selected_action' => trim((string) ($payload['selected_action'] ?? '')) ?: 'none',
            'evidence_source' => trim((string) ($payload['evidence_source'] ?? '')) ?: 'none',
            'ambiguity_detected' => (($payload['ambiguity_detected'] ?? false) === true),
            'fallback_llm' => (($payload['fallback_llm'] ?? false) === true),
            'latency_ms' => $this->intValue($payload['latency_ms'] ?? 0),
            'result_status' => trim((string) ($payload['result_status'] ?? '')) ?: 'unknown',
            'metadata_json' => is_array($payload['metadata_json'] ?? null)
                ? (array) $payload['metadata_json']
                : (is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : []),
            'created_at' => trim((string) ($payload['created_at'] ?? '')) ?: date('Y-m-d H:i:s'),
        ];

        $this->metrics->saveDecisionTrace($record);

        return $record;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordToolExecutionTrace(array $payload): array
    {
        $record = [
            'tenant_id' => $this->requireString($payload['tenant_id'] ?? null, 'tenant_id'),
            'project_id' => $this->normalizeProjectId($payload['project_id'] ?? null),
            'module_key' => trim((string) ($payload['module_key'] ?? '')) ?: 'none',
            'action_key' => trim((string) ($payload['action_key'] ?? '')) ?: 'none',
            'input_schema_valid' => (($payload['input_schema_valid'] ?? false) === true),
            'permission_check' => trim((string) ($payload['permission_check'] ?? '')) ?: 'not_checked',
            'plan_check' => trim((string) ($payload['plan_check'] ?? '')) ?: 'not_checked',
            'execution_latency' => $this->intValue($payload['execution_latency'] ?? 0),
            'success' => (($payload['success'] ?? false) === true),
            'error_code' => $this->nullableString($payload['error_code'] ?? null),
            'metadata_json' => is_array($payload['metadata_json'] ?? null)
                ? (array) $payload['metadata_json']
                : (is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : []),
            'created_at' => trim((string) ($payload['created_at'] ?? '')) ?: date('Y-m-d H:i:s'),
        ];

        $this->metrics->saveToolExecutionTrace($record);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetricsSummary(string $tenantId, ?string $projectId = null, int $days = 7, ?string $metricKey = null): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $projectId = $this->normalizeProjectId($projectId);
        $days = max(1, min(90, $days));
        $metricKey = $this->nullableString($metricKey);

        $legacySummary = $this->metrics->summary($tenantId, $projectId, $days);
        $observability = $this->metrics->observabilitySummary($tenantId, $projectId, $days);
        $metricsSummary = [
            'module_usage' => $observability['decision_traces']['module_usage'] ?? [],
            'avg_latency' => $observability['decision_traces']['average_latency_ms'] ?? 0.0,
            'fallback_rate' => $observability['decision_traces']['fallback_llm_rate'] ?? 0.0,
            'ambiguity_rate' => $observability['decision_traces']['ambiguity_rate'] ?? 0.0,
            'error_rate' => $observability['tool_execution']['error_rate'] ?? 0.0,
            'permission_denials' => $observability['tool_execution']['permission_denials'] ?? 0,
            'plan_limit_warnings' => $observability['tool_execution']['plan_limit_warnings'] ?? 0,
            'errors_by_module' => $observability['tool_execution']['errors_by_module'] ?? [],
        ];

        if ($metricKey !== null && !array_key_exists($metricKey, $metricsSummary)) {
            throw new RuntimeException('AGENTOPS_METRIC_KEY_INVALID');
        }

        return [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'days' => $days,
            'metric_key' => $metricKey,
            'metric_value' => $metricKey !== null ? $metricsSummary[$metricKey] : null,
            'metrics_summary' => $metricsSummary,
            'legacy_summary' => $legacySummary,
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listRecentDecisions(string $tenantId, ?string $projectId = null, array $filters = [], int $limit = 25): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $projectId = $this->normalizeProjectId($projectId);
        $items = $this->metrics->listDecisionTraces($tenantId, $projectId, $filters, $limit);

        return [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'decision_traces' => $items,
            'result_count' => count($items),
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listToolExecutions(string $tenantId, ?string $projectId = null, array $filters = [], int $limit = 25): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $projectId = $this->normalizeProjectId($projectId);
        $items = $this->metrics->listToolExecutionTraces($tenantId, $projectId, $filters, $limit);

        return [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'tool_executions' => $items,
            'result_count' => count($items),
            'result_status' => 'success',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnomalyFlags(string $tenantId, ?string $projectId = null, int $days = 7): array
    {
        $summary = $this->getMetricsSummary($tenantId, $projectId, $days);
        $metricsSummary = is_array($summary['metrics_summary'] ?? null) ? (array) $summary['metrics_summary'] : [];
        $observabilitySummary = $this->metrics->observabilitySummary(
            (string) $summary['tenant_id'],
            (string) $summary['project_id'],
            (int) $summary['days']
        );

        $flags = [];
        $fallbackRate = (float) ($metricsSummary['fallback_rate'] ?? 0.0);
        $ambiguityRate = (float) ($metricsSummary['ambiguity_rate'] ?? 0.0);
        $permissionDenials = (int) ($metricsSummary['permission_denials'] ?? 0);
        $planLimitWarnings = (int) ($metricsSummary['plan_limit_warnings'] ?? 0);
        $errorRate = (float) ($metricsSummary['error_rate'] ?? 0.0);
        $avgLatency = (float) ($metricsSummary['avg_latency'] ?? 0.0);
        $decisionCount = (int) (($observabilitySummary['decision_traces']['count'] ?? 0));
        $toolLatencyP95 = (int) (($observabilitySummary['tool_execution']['p95_latency_ms'] ?? 0));

        if ($decisionCount >= 5 && $fallbackRate >= 0.35) {
            $flags[] = $this->flag('high_fallback_llm_rate', 'warn', $fallbackRate, 0.35);
        }
        if ($permissionDenials >= 3) {
            $flags[] = $this->flag('repeated_permission_denials', 'warn', $permissionDenials, 3);
        }
        if ($errorRate >= 0.30) {
            $flags[] = $this->flag('repeated_tool_failures', 'warn', $errorRate, 0.30);
        }
        if ($toolLatencyP95 >= 1200 || $avgLatency >= 1000.0) {
            $flags[] = $this->flag('latency_spike', 'warn', max($toolLatencyP95, (int) round($avgLatency)), 1200);
        }
        if ($ambiguityRate >= 0.25) {
            $flags[] = $this->flag('high_ambiguity_rate', 'info', $ambiguityRate, 0.25);
        }
        if ($planLimitWarnings >= 3) {
            $flags[] = $this->flag('plan_limit_warning_cluster', 'info', $planLimitWarnings, 3);
        }

        return [
            'tenant_id' => (string) $summary['tenant_id'],
            'project_id' => (string) $summary['project_id'],
            'days' => (int) $summary['days'],
            'anomaly_flags' => $flags,
            'result_count' => count($flags),
            'result_status' => $flags === [] ? 'clear' : 'flagged',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flag(string $flagKey, string $severity, float|int $currentValue, float|int $threshold): array
    {
        return [
            'flag_key' => $flagKey,
            'severity' => $severity,
            'current_value' => $currentValue,
            'threshold' => $threshold,
            'detected' => true,
        ];
    }

    private function requireString(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        }

        return $value;
    }

    private function normalizeProjectId(mixed $value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : 'default';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function intValue(mixed $value): int
    {
        return max(0, (int) $value);
    }
}
