<?php

declare(strict_types=1);

namespace App\Core;

final class ImprovementMemoryService
{
    /** @var array<int, string> */
    private const ALLOWED_PROBLEM_TYPES = [
        'intent_not_understood',
        'missing_skill',
        'fallback_llm',
        'entity_not_found',
        'slow_query',
        'tool_failure',
        'ambiguous_request',
    ];

    private ImprovementMemoryRepository $repository;

    public function __construct(?ImprovementMemoryRepository $repository = null)
    {
        $this->repository = $repository ?? new ImprovementMemoryRepository();
    }

    /**
     * @param array<string, mixed>|string $evidence
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function recordEvent(string $problemType, string $module, $evidence = [], array $context = []): array
    {
        $problemType = $this->normalizeProblemType($problemType);
        $module = $this->normalizeModule($module);
        $evidencePayload = $this->normalizeEvidence($evidence, $context, $problemType, $module);
        $tenantId = trim((string) ($evidencePayload['tenant_id'] ?? $context['tenant_id'] ?? 'default'));
        if ($tenantId === '') {
            $tenantId = 'default';
        }

        $record = $this->repository->recordImprovement([
            'tenant_id' => $tenantId,
            'module' => $module,
            'problem_type' => $problemType,
            'severity' => $this->resolveSeverity($problemType, $evidencePayload),
            'evidence' => $evidencePayload,
            'suggestion' => $this->buildSuggestion($problemType, $module, $evidencePayload),
            'status' => 'open',
        ]);

        $candidate = $this->maybeCreateLearningCandidate($record, $evidencePayload);

        return [
            'improvement' => $record,
            'candidate' => $candidate,
            'created_candidate' => is_array($candidate),
        ];
    }

    /**
     * @param array<string, mixed>|string $evidence
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function record_event(string $problemType, string $module, $evidence = [], array $context = []): array
    {
        return $this->recordEvent($problemType, $module, $evidence, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function aggregateMetrics(string $tenantId, int $days = 30): array
    {
        return $this->repository->aggregate($tenantId, $days);
    }

    /**
     * @return array<string, mixed>
     */
    public function aggregate_metrics(string $tenantId, int $days = 30): array
    {
        return $this->aggregateMetrics($tenantId, $days);
    }

    /**
     * @return array<string, mixed>
     */
    public function suggestImprovements(string $tenantId, int $limit = 10): array
    {
        $topIssues = $this->repository->listTopImprovements($tenantId, $limit, 30);
        $pendingCandidates = $this->repository->listLearningCandidates($tenantId, 'pending', $limit);
        $approvedCandidates = $this->repository->listLearningCandidates($tenantId, 'approved', $limit);

        return [
            'tenant_id' => $tenantId !== '' ? $tenantId : 'default',
            'top_issues' => $topIssues,
            'pending_candidates' => $pendingCandidates,
            'approved_candidates' => $approvedCandidates,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function suggest_improvements(string $tenantId, int $limit = 10): array
    {
        return $this->suggestImprovements($tenantId, $limit);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function ingestAgentOpsLogEvent(string $tenantId, array $payload): array
    {
        $scopedTenantId = trim((string) ($payload['tenant_id'] ?? $tenantId));
        if ($scopedTenantId === '') {
            $scopedTenantId = 'default';
        }
        if ($tenantId !== '' && $scopedTenantId !== $tenantId) {
            return [
                'tenant_id' => $tenantId,
                'recorded' => [],
            ];
        }

        $module = $this->normalizeModule((string) ($payload['module_used'] ?? 'router'));
        $runtime = is_array($payload['agentops_runtime'] ?? null) ? (array) $payload['agentops_runtime'] : [];
        $fallbackReason = trim((string) ($payload['fallback_reason'] ?? $runtime['fallback_reason'] ?? 'none'));
        $skillFallbackReason = trim((string) ($payload['skill_fallback_reason'] ?? $runtime['skill_fallback_reason'] ?? 'none'));
        $supervisorFlags = $this->normalizeStringList($payload['supervisor_flags'] ?? []);
        $status = trim((string) ($payload['status'] ?? 'success'));
        $latencyMs = max(0, (int) ($payload['latency_ms'] ?? $runtime['latency_ms'] ?? 0));
        $toolCallsCount = max(0, (int) ($payload['tool_calls_count'] ?? $runtime['tool_calls_count'] ?? 0));
        $resolved = (bool) ($payload['resolved'] ?? false);
        $resultCount = max(0, (int) ($payload['result_count'] ?? 0));
        $needsClarification = (bool) ($payload['needs_clarification'] ?? false);

        $events = [];
        $baseEvidence = [
            'tenant_id' => $scopedTenantId,
            'project_id' => (string) ($payload['project_id'] ?? ''),
            'event_name' => (string) ($payload['event_name'] ?? ''),
            'query' => (string) ($payload['message'] ?? ''),
            'route_path' => (string) ($payload['route_path'] ?? ''),
            'module_used' => $module,
            'fallback_reason' => $fallbackReason,
            'skill_fallback_reason' => $skillFallbackReason,
            'status' => $status,
            'latency_ms' => $latencyMs,
        ];

        $llmUsed = (bool) ($payload['llm_used'] ?? $runtime['llm_used'] ?? false);
        $llmFallbackCount = max(0, (int) ($payload['llm_fallback_count'] ?? $runtime['llm_fallback_count'] ?? 0));
        if ($llmUsed || $llmFallbackCount > 0 || str_contains((string) ($payload['route_path'] ?? ''), 'llm')) {
            $events[] = $this->recordEvent('fallback_llm', $module, $baseEvidence + [
                'source_metric' => 'fallback_llm',
            ]);
        }

        if (
            in_array($skillFallbackReason, ['no_skill_match', 'no_selected_skill', 'missing_operational_payload', 'missing_media_payload', 'missing_entity_reference'], true)
            || in_array('skill_execution_failed', $supervisorFlags, true)
        ) {
            $events[] = $this->recordEvent('missing_skill', $module, $baseEvidence + [
                'source_metric' => 'tool_errors',
                'skill_fallback_reason' => $skillFallbackReason,
            ]);
        }

        if (
            in_array('weak_safe_fallback', $supervisorFlags, true)
            || in_array('fallback_overuse', $supervisorFlags, true)
            || ($status === 'error' && $toolCallsCount === 0 && $fallbackReason !== 'security_block')
        ) {
            $events[] = $this->recordEvent('intent_not_understood', $module, $baseEvidence + [
                'source_metric' => 'unresolved_intent',
            ]);
        }

        if ($module === 'entity_search' && in_array((string) ($payload['entity_search_action'] ?? 'none'), ['search', 'resolve'], true)) {
            if ($resultCount === 0) {
                $events[] = $this->recordEvent('entity_not_found', $module, $baseEvidence + [
                    'source_metric' => 'entity_search_fail',
                    'entity_search_action' => (string) ($payload['entity_search_action'] ?? ''),
                    'result_count' => $resultCount,
                ]);
            } elseif (!$resolved && ($needsClarification || $resultCount > 1)) {
                $events[] = $this->recordEvent('ambiguous_request', $module, $baseEvidence + [
                    'source_metric' => 'entity_search_fail',
                    'entity_search_action' => (string) ($payload['entity_search_action'] ?? ''),
                    'result_count' => $resultCount,
                ]);
            }
        }

        $errorFlag = (bool) ($payload['error_flag'] ?? $runtime['error_flag'] ?? false);
        if ($toolCallsCount > 0 && ($status !== 'success' || $errorFlag)) {
            $events[] = $this->recordEvent('tool_failure', $module, $baseEvidence + [
                'source_metric' => 'tool_errors',
                'error_type' => (string) ($payload['error_type'] ?? $runtime['error_type'] ?? 'runtime_error'),
            ]);
        }

        $latencyThreshold = max(250, (int) (getenv('AGENTOPS_LATENCY_ANOMALY_MS') ?: 1500));
        if ($latencyMs >= $latencyThreshold) {
            $events[] = $this->recordEvent('slow_query', $module, $baseEvidence + [
                'source_metric' => 'latency_anomalies',
                'threshold_ms' => $latencyThreshold,
            ]);
        }

        return [
            'tenant_id' => $scopedTenantId,
            'recorded' => $events,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>|null
     */
    private function maybeCreateLearningCandidate(array $record, array $evidence): ?array
    {
        $frequency = (int) ($record['frequency'] ?? 0);
        if ($frequency < 2) {
            return null;
        }

        $severity = (string) ($record['severity'] ?? 'medium');
        $candidate = $this->repository->upsertLearningCandidate([
            'tenant_id' => (string) ($record['tenant_id'] ?? 'default'),
            'source_metric' => trim((string) ($evidence['source_metric'] ?? $record['problem_type'] ?? 'unknown')),
            'module' => (string) ($record['module'] ?? 'router'),
            'description' => (string) ($record['suggestion'] ?? ''),
            'frequency' => $frequency,
            'confidence' => $this->resolveConfidence($frequency, $severity),
            'review_status' => 'pending',
        ]);

        return $candidate;
    }

    /**
     * @param array<string, mixed>|string $evidence
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function normalizeEvidence($evidence, array $context, string $problemType, string $module): array
    {
        $payload = is_array($evidence) ? $evidence : ['summary' => trim((string) $evidence)];
        $payload['tenant_id'] = trim((string) ($payload['tenant_id'] ?? $context['tenant_id'] ?? 'default'));
        $payload['module'] = $module;
        $payload['problem_type'] = $problemType;

        $query = trim((string) ($payload['query'] ?? ''));
        if ($query !== '') {
            $payload['query_hash'] = substr(sha1(mb_strtolower($query, 'UTF-8')), 0, 16);
            unset($payload['query']);
        }

        if (isset($payload['message'])) {
            $message = trim((string) $payload['message']);
            if ($message !== '') {
                $payload['message_hash'] = substr(sha1(mb_strtolower($message, 'UTF-8')), 0, 16);
            }
            unset($payload['message']);
        }

        return $payload;
    }

    private function normalizeProblemType(string $problemType): string
    {
        $map = [
            'unresolved_intent' => 'intent_not_understood',
            'entity_search_fail' => 'entity_not_found',
            'tool_errors' => 'tool_failure',
            'latency_anomalies' => 'slow_query',
        ];
        $problemType = strtolower(trim($problemType));
        $problemType = $map[$problemType] ?? $problemType;
        if (!in_array($problemType, self::ALLOWED_PROBLEM_TYPES, true)) {
            return 'tool_failure';
        }
        return $problemType;
    }

    private function normalizeModule(string $module): string
    {
        $module = trim($module);
        return $module !== '' && $module !== 'none' ? $module : 'router';
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function resolveSeverity(string $problemType, array $evidence): string
    {
        $latencyMs = max(0, (int) ($evidence['latency_ms'] ?? 0));
        if ($problemType === 'slow_query' && $latencyMs >= 3000) {
            return 'high';
        }
        if (in_array($problemType, ['tool_failure', 'fallback_llm'], true)) {
            return 'high';
        }
        if (in_array($problemType, ['missing_skill', 'intent_not_understood'], true)) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function buildSuggestion(string $problemType, string $module, array $evidence): string
    {
        switch ($problemType) {
            case 'fallback_llm':
                return 'Reducir fallback LLM en ' . $module . ' reforzando reglas, skills o evidencia recuperable.';
            case 'missing_skill':
                return 'Evaluar nueva skill o payload deterministico para ' . $module . '.';
            case 'intent_not_understood':
                return 'Agregar ejemplos, reglas o clarificacion guiada para intents ambiguos en ' . $module . '.';
            case 'entity_not_found':
                return 'Revisar indices y aliases de busqueda para evitar entity not found en ' . $module . '.';
            case 'slow_query':
                return 'Investigar latencia de ' . $module . ' y agregar indices o cache segun el cuello de botella.';
            case 'ambiguous_request':
                return 'Agregar ranking o clarificacion deterministica para reducir ambiguedad en ' . $module . '.';
            case 'tool_failure':
            default:
                return 'Revisar errores recurrentes del tool o handler en ' . $module . ' y cubrirlos con test.';
        }
    }

    private function resolveConfidence(int $frequency, string $severity): float
    {
        $severityBoost = [
            'low' => 0.05,
            'medium' => 0.10,
            'high' => 0.20,
            'critical' => 0.30,
        ];
        $confidence = 0.30 + min(0.50, $frequency * 0.12) + ($severityBoost[$severity] ?? 0.10);
        return round(min(0.99, $confidence), 4);
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

        $normalized = [];
        foreach ($value as $item) {
            $candidate = trim((string) $item);
            if ($candidate === '') {
                continue;
            }
            $normalized[] = $candidate;
        }

        if ($normalized === []) {
            return [];
        }

        return array_values(array_unique($normalized));
    }
}
