<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ImprovementMemoryRepository;
use App\Core\LearningPromotionService;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/learning_promotion_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'PROJECT_REGISTRY_DB_PATH' => getenv('PROJECT_REGISTRY_DB_PATH'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('PROJECT_REGISTRY_DB_PATH=' . $tmpDir . '/project_registry.sqlite');

$repository = new ImprovementMemoryRepository(null, $tmpDir . '/project_registry.sqlite');
$service = new LearningPromotionService($repository);

$approvedSkill = $repository->upsertLearningCandidate([
    'candidate_id' => 'cand_skill',
    'tenant_id' => 'tenant_alpha',
    'source_metric' => 'tool_errors',
    'module' => 'pos',
    'problem_type' => 'missing_skill',
    'severity' => 'high',
    'evidence' => ['tenant_id' => 'tenant_alpha', 'query_hash' => 'abc123'],
    'description' => 'Recurring missing skill for short POS product references',
    'frequency' => 6,
    'confidence' => 0.91,
    'review_status' => 'approved',
]);

$promotionFirst = $service->promoteApprovedCandidates('tenant_alpha', 10);
$createdFirst = (array) ($promotionFirst['created'] ?? []);
$firstProposal = is_array($createdFirst[0] ?? null) ? (array) $createdFirst[0] : [];
if (count($createdFirst) !== 1) {
    $failures[] = 'Approved candidate should create exactly one proposal on first promotion.';
}
if ((string) ($firstProposal['proposal_type'] ?? '') !== 'skill_proposal') {
    $failures[] = 'missing_skill should map to skill_proposal.';
}
if ((string) ($firstProposal['status'] ?? '') !== 'open') {
    $failures[] = 'New proposal should start in open status.';
}

$skillCandidateAfter = $repository->findLearningCandidate('tenant_alpha', 'cand_skill');
if (!is_array($skillCandidateAfter) || empty($skillCandidateAfter['processed_at']) || (string) ($skillCandidateAfter['proposal_id'] ?? '') === '') {
    $failures[] = 'Promoted candidate should be marked as processed with proposal_id.';
}

$repository->upsertLearningCandidate([
    'candidate_id' => 'cand_rejected',
    'tenant_id' => 'tenant_alpha',
    'source_metric' => 'unresolved_intent',
    'module' => 'router',
    'problem_type' => 'intent_not_understood',
    'severity' => 'medium',
    'evidence' => ['tenant_id' => 'tenant_alpha'],
    'description' => 'Rejected training gap candidate',
    'frequency' => 5,
    'confidence' => 0.72,
    'review_status' => 'rejected',
]);

$promotionRejected = $service->promoteApprovedCandidates('tenant_alpha', 10);
if ((int) ($promotionRejected['scanned'] ?? 0) !== 0) {
    $failures[] = 'Rejected candidates must be ignored by promotion scan.';
}

$repository->upsertLearningCandidate([
    'candidate_id' => 'cand_dup_a',
    'tenant_id' => 'tenant_alpha',
    'source_metric' => 'entity_search_fail',
    'module' => 'entity_search',
    'problem_type' => 'ambiguous_request',
    'severity' => 'medium',
    'evidence' => ['tenant_id' => 'tenant_alpha', 'query_hash' => 'dup001'],
    'description' => 'High frequency ambiguous product references in POS',
    'frequency' => 4,
    'confidence' => 0.78,
    'review_status' => 'approved',
]);
$promotionDupFirst = $service->promoteApprovedCandidates('tenant_alpha', 10);
if (count((array) ($promotionDupFirst['created'] ?? [])) !== 1) {
    $failures[] = 'First duplicate candidate should still create the initial proposal.';
}

$repository->upsertLearningCandidate([
    'candidate_id' => 'cand_dup_b',
    'tenant_id' => 'tenant_alpha',
    'source_metric' => 'entity_search_fail',
    'module' => 'entity_search',
    'problem_type' => 'ambiguous_request',
    'severity' => 'medium',
    'evidence' => ['tenant_id' => 'tenant_alpha', 'query_hash' => 'dup002'],
    'description' => 'High frequency ambiguous product references in POS',
    'frequency' => 5,
    'confidence' => 0.81,
    'review_status' => 'approved',
]);
$promotionDupSecond = $service->promoteApprovedCandidates('tenant_alpha', 10);
if (count((array) ($promotionDupSecond['duplicates'] ?? [])) !== 1) {
    $failures[] = 'Second similar candidate should be deduped instead of creating another proposal.';
}

$proposals = $repository->listImprovementProposals([
    'tenant_id' => 'tenant_alpha',
], 20);
if (count($proposals) !== 2) {
    $failures[] = 'Proposal backlog should contain two unique proposals after duplicate blocking.';
}

$dupCandidateAfter = $repository->findLearningCandidate('tenant_alpha', 'cand_dup_b');
if (!is_array($dupCandidateAfter) || (string) ($dupCandidateAfter['proposal_id'] ?? '') === '') {
    $failures[] = 'Duplicate candidate should be linked to the existing proposal.';
}

$priorityCandidate = [
    'candidate_id' => 'cand_perf',
    'tenant_id' => 'tenant_alpha',
    'source_metric' => 'latency_anomalies',
    'module' => 'router',
    'problem_type' => 'slow_query',
    'severity' => 'high',
    'evidence' => ['tenant_id' => 'tenant_alpha', 'latency_ms' => 4200],
    'description' => 'Repeated latency spike in router',
    'frequency' => 9,
    'confidence' => 0.96,
    'review_status' => 'approved',
];
$priority = $service->computePriority($priorityCandidate);
if ($priority !== 'critical') {
    $failures[] = 'High severity/high frequency/high confidence shared-module candidate should be critical.';
}

$datasetProposal = $service->buildProposalFromCandidate([
    'candidate_id' => 'cand_dataset',
    'tenant_id' => 'tenant_alpha',
    'source_metric' => 'entity_search_fail',
    'module' => 'entity_search',
    'problem_type' => 'entity_not_found',
    'severity' => 'medium',
    'evidence' => ['tenant_id' => 'tenant_alpha'],
    'description' => 'Missing aliases for products',
    'frequency' => 4,
    'confidence' => 0.70,
]);
if ((string) ($datasetProposal['proposal_type'] ?? '') !== 'dataset_proposal') {
    $failures[] = 'entity_not_found should map to dataset_proposal.';
}

$uiProposal = $service->buildProposalFromCandidate([
    'candidate_id' => 'cand_ui',
    'tenant_id' => 'tenant_alpha',
    'source_metric' => 'entity_search_fail',
    'module' => 'entity_search',
    'problem_type' => 'ambiguous_request',
    'severity' => 'medium',
    'evidence' => ['tenant_id' => 'tenant_alpha'],
    'description' => 'Repeated ambiguous references',
    'frequency' => 6,
    'confidence' => 0.83,
]);
if ((string) ($uiProposal['proposal_type'] ?? '') !== 'ui_improvement_proposal') {
    $failures[] = 'Repeated ambiguous_request should map to ui_improvement_proposal.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'promotion_first' => $promotionFirst,
    'promotion_duplicate_second' => $promotionDupSecond,
    'proposals_count' => count($proposals),
    'priority' => $priority,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

foreach ($previous as $key => $value) {
    if ($value === false) {
        putenv($key);
        continue;
    }
    putenv($key . '=' . $value);
}

exit($ok ? 0 : 1);
