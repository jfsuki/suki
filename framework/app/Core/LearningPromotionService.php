<?php

declare(strict_types=1);

namespace App\Core;

final class LearningPromotionService
{
    private ImprovementMemoryRepository $repository;

    public function __construct(?ImprovementMemoryRepository $repository = null)
    {
        $this->repository = $repository ?? new ImprovementMemoryRepository();
    }

    /**
     * @return array<string, mixed>
     */
    public function promoteApprovedCandidates(?string $tenantId = null, int $limit = 25): array
    {
        $candidates = $this->repository->listApprovedCandidatesForPromotion($tenantId, $limit);
        $created = [];
        $duplicates = [];
        $ignored = [];

        foreach ($candidates as $candidate) {
            $proposal = $this->buildProposalFromCandidate($candidate);
            if ($proposal === []) {
                $ignored[] = [
                    'candidate_id' => (string) ($candidate['candidate_id'] ?? ''),
                    'reason' => 'no_promotion_mapping',
                ];
                continue;
            }

            $duplicate = $this->detectDuplicateProposal($proposal);
            if (is_array($duplicate)) {
                $this->markCandidateProcessed(
                    (string) ($candidate['candidate_id'] ?? ''),
                    (string) ($candidate['tenant_id'] ?? 'default'),
                    (string) ($duplicate['id'] ?? '')
                );
                $duplicates[] = [
                    'candidate_id' => (string) ($candidate['candidate_id'] ?? ''),
                    'proposal_id' => (string) ($duplicate['id'] ?? ''),
                ];
                continue;
            }

            $saved = $this->repository->insertImprovementProposal($proposal);
            $this->markCandidateProcessed(
                (string) ($candidate['candidate_id'] ?? ''),
                (string) ($candidate['tenant_id'] ?? 'default'),
                (string) ($saved['id'] ?? '')
            );
            $created[] = $saved;
        }

        return [
            'tenant_id' => $tenantId,
            'scanned' => count($candidates),
            'created' => $created,
            'duplicates' => $duplicates,
            'ignored' => $ignored,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public function buildProposalFromCandidate(array $candidate): array
    {
        $problemType = $this->candidateProblemType($candidate);
        $proposalType = $this->classifyProposalType($candidate, $problemType);
        if ($proposalType === '') {
            return [];
        }

        $module = trim((string) ($candidate['module'] ?? 'router'));
        $moduleLabel = $this->moduleLabel($module);
        $focus = $this->focusLabel($problemType);
        $title = $this->buildTitle($proposalType, $moduleLabel, $focus);
        $description = $this->buildDescription($candidate, $moduleLabel, $focus);

        return [
            'tenant_id' => trim((string) ($candidate['tenant_id'] ?? '')) ?: null,
            'candidate_id' => (string) ($candidate['candidate_id'] ?? ''),
            'proposal_type' => $proposalType,
            'module' => $module !== '' ? $module : 'router',
            'title' => $title,
            'description' => $description,
            'evidence' => $this->proposalEvidence($candidate, $problemType),
            'frequency' => max(0, (int) ($candidate['frequency'] ?? 0)),
            'confidence' => max(0.0, min(1.0, (float) ($candidate['confidence'] ?? 0.0))),
            'priority' => $this->computePriority($candidate),
            'status' => 'open',
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    public function computePriority(array $candidate): string
    {
        $severityScore = match (strtolower(trim((string) ($candidate['severity'] ?? 'medium')))) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
        $frequency = max(0, (int) ($candidate['frequency'] ?? 0));
        $frequencyScore = match (true) {
            $frequency >= 8 => 4,
            $frequency >= 5 => 3,
            $frequency >= 3 => 2,
            default => 1,
        };
        $confidence = max(0.0, min(1.0, (float) ($candidate['confidence'] ?? 0.0)));
        $confidenceScore = match (true) {
            $confidence >= 0.90 => 4,
            $confidence >= 0.75 => 3,
            $confidence >= 0.55 => 2,
            default => 1,
        };
        $moduleScore = $this->moduleCriticalityScore((string) ($candidate['module'] ?? 'router'));
        $total = $severityScore + $frequencyScore + $confidenceScore + $moduleScore;

        return match (true) {
            $total >= 14 => 'critical',
            $total >= 11 => 'high',
            $total >= 8 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>|null
     */
    public function detectDuplicateProposal(array $proposal): ?array
    {
        $existing = $this->repository->listImprovementProposals([
            'tenant_id' => $proposal['tenant_id'] ?? null,
            'module' => (string) ($proposal['module'] ?? ''),
            'proposal_type' => (string) ($proposal['proposal_type'] ?? ''),
            'statuses' => ['open', 'accepted'],
        ], 25);

        $title = $this->normalizeText((string) ($proposal['title'] ?? ''));
        $description = $this->normalizeText((string) ($proposal['description'] ?? ''));

        foreach ($existing as $row) {
            $existingTitle = $this->normalizeText((string) ($row['title'] ?? ''));
            $existingDescription = $this->normalizeText((string) ($row['description'] ?? ''));
            if ($title !== '' && $title === $existingTitle) {
                return $row;
            }
            if ($this->similarityScore($title, $existingTitle) >= 0.82) {
                return $row;
            }
            if ($description !== '' && $this->similarityScore($description, $existingDescription) >= 0.70) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function markCandidateProcessed(string $candidateId, ?string $tenantId = null, ?string $proposalId = null): ?array
    {
        $tenantId = trim((string) $tenantId);
        if ($tenantId === '') {
            $tenantId = 'default';
        }

        return $this->repository->markLearningCandidateProcessed($tenantId, $candidateId, $proposalId);
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function classifyProposalType(array $candidate, string $problemType): string
    {
        $frequency = max(0, (int) ($candidate['frequency'] ?? 0));
        $confidence = max(0.0, min(1.0, (float) ($candidate['confidence'] ?? 0.0)));
        $severity = strtolower(trim((string) ($candidate['severity'] ?? 'medium')));
        $sourceMetric = strtolower(trim((string) ($candidate['source_metric'] ?? '')));

        return match ($problemType) {
            'missing_skill' => 'skill_proposal',
            'intent_not_understood' => 'dataset_proposal',
            'fallback_llm' => ($frequency >= 5 || $confidence >= 0.80) ? 'rule_proposal' : 'skill_proposal',
            'entity_not_found' => 'dataset_proposal',
            'slow_query' => 'performance_fix_proposal',
            'ambiguous_request' => ($frequency >= 4 || $sourceMetric === 'entity_search_fail')
                ? 'ui_improvement_proposal'
                : 'dataset_proposal',
            'tool_failure' => ($severity === 'high' || $severity === 'critical' || $confidence >= 0.80)
                ? 'performance_fix_proposal'
                : 'rule_proposal',
            default => $sourceMetric === 'latency_anomalies' ? 'performance_fix_proposal' : 'dataset_proposal',
        };
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateProblemType(array $candidate): string
    {
        $problemType = strtolower(trim((string) ($candidate['problem_type'] ?? '')));
        if ($problemType !== '') {
            return $problemType;
        }

        return match (strtolower(trim((string) ($candidate['source_metric'] ?? '')))) {
            'unresolved_intent' => 'intent_not_understood',
            'entity_search_fail' => 'entity_not_found',
            'tool_errors' => 'tool_failure',
            'latency_anomalies' => 'slow_query',
            default => 'fallback_llm',
        };
    }

    private function moduleLabel(string $module): string
    {
        return match (strtolower(trim($module))) {
            'pos' => 'POS',
            'entity_search' => 'Entity Search',
            'media_storage' => 'Media Storage',
            'fiscal_engine' => 'Fiscal Engine',
            'ecommerce_hub' => 'Ecommerce Hub',
            'router' => 'Router',
            default => ucwords(str_replace('_', ' ', trim($module) !== '' ? $module : 'router')),
        };
    }

    private function focusLabel(string $problemType): string
    {
        return match ($problemType) {
            'missing_skill' => 'missing skill handling',
            'intent_not_understood' => 'unresolved intents',
            'fallback_llm' => 'LLM fallback reduction',
            'entity_not_found' => 'entity resolution failures',
            'slow_query' => 'slow queries',
            'ambiguous_request' => 'ambiguous references',
            'tool_failure' => 'tool failures',
            default => 'recurrent issues',
        };
    }

    private function buildTitle(string $proposalType, string $moduleLabel, string $focus): string
    {
        return match ($proposalType) {
            'skill_proposal' => 'Create ' . $moduleLabel . ' ' . $focus . ' skill',
            'rule_proposal' => 'Add ' . $moduleLabel . ' rule for ' . $focus,
            'dataset_proposal' => 'Expand ' . $moduleLabel . ' dataset for ' . $focus,
            'ui_improvement_proposal' => 'Improve ' . $moduleLabel . ' clarification flow for ' . $focus,
            'performance_fix_proposal' => 'Optimize ' . $moduleLabel . ' performance for ' . $focus,
            default => 'Review ' . $moduleLabel . ' backlog item for ' . $focus,
        };
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function buildDescription(array $candidate, string $moduleLabel, string $focus): string
    {
        $frequency = max(0, (int) ($candidate['frequency'] ?? 0));
        $confidence = number_format(max(0.0, min(1.0, (float) ($candidate['confidence'] ?? 0.0))), 2, '.', '');
        $summary = trim((string) ($candidate['description'] ?? ''));
        $summary = rtrim($summary, '.');
        $parts = [];
        $parts[] = 'Recurring ' . $focus . ' issue in ' . $moduleLabel . '.';
        $parts[] = 'Frequency ' . $frequency . ', confidence ' . $confidence . '.';
        if ($summary !== '') {
            $parts[] = $summary . '.';
        }
        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function proposalEvidence(array $candidate, string $problemType): array
    {
        $evidence = is_array($candidate['evidence'] ?? null) ? (array) $candidate['evidence'] : [];
        $evidence['problem_type'] = $problemType;
        $evidence['source_metric'] = trim((string) ($candidate['source_metric'] ?? ''));
        $evidence['frequency'] = max(0, (int) ($candidate['frequency'] ?? 0));
        $evidence['confidence'] = max(0.0, min(1.0, (float) ($candidate['confidence'] ?? 0.0)));
        return $evidence;
    }

    private function moduleCriticalityScore(string $module): int
    {
        return match (strtolower(trim($module))) {
            'router', 'pos', 'fiscal_engine' => 4,
            'entity_search', 'media_storage', 'purchases' => 3,
            'ecommerce_hub', 'semantic_memory', 'agentops' => 2,
            default => 2,
        };
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        return trim($text);
    }

    private function similarityScore(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }
        if ($left === $right) {
            return 1.0;
        }

        $leftTokens = array_values(array_filter(explode(' ', $left), static fn(string $item): bool => $item !== ''));
        $rightTokens = array_values(array_filter(explode(' ', $right), static fn(string $item): bool => $item !== ''));
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $leftMap = array_fill_keys($leftTokens, true);
        $rightMap = array_fill_keys($rightTokens, true);
        $intersection = array_intersect_key($leftMap, $rightMap);
        $union = $leftMap + $rightMap;

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }
}
