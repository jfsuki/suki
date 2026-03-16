<?php
// framework/tests/control_tower_contracts_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ControlTowerArtifactValidator;
use App\Core\Contracts\ContractRepository;

$failures = [];
$scope = [
    'tenant_id' => 'default',
    'project_id' => 'suki_control_tower',
    'app_id' => 'suki_control_tower',
    'run_id' => 'ct_run_001',
    'sprint_id' => 'ct_sprint_001',
];

$artifacts = [
    'control_tower_run' => [
        'artifact_type' => 'control_tower_run',
        'schema_version' => '1.0.0',
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'app_id' => $scope['app_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'task_title' => 'Formalize SUKI Control Tower',
        'task_description' => 'Create canon, contracts, schemas, validator and test.',
        'priority' => 'high',
        'impact_level' => 'medium',
        'status' => 'validated',
        'assigned_agents' => ['TowerSupervisor', 'SprintManager', 'ReviewerAgent'],
        'detected_canon_drift' => ['router_stage_drift', 'project_app_scope_drift'],
        'architectural_assessment' => [
            'llm_boundary_ok' => true,
            'multitenant_ok' => true,
            'router_monitoring_required' => true,
            'schema_governance_ok' => true,
            'blocked_reasons' => [],
        ],
        'created_at' => date('c'),
    ],
    'sprint_ticket' => [
        'artifact_type' => 'sprint_ticket',
        'schema_version' => '1.0.0',
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'app_id' => $scope['app_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'ticket_id' => 'ticket_001',
        'title' => 'Add Control Tower schemas',
        'objective' => 'Create machine-readable artifacts for supervision outputs.',
        'target_files' => [
            'framework/contracts/schemas/control_tower_run.schema.json',
            'framework/contracts/schemas/sprint_status_summary.schema.json',
        ],
        'acceptance_criteria' => [
            'Schemas validate representative payloads.',
            'Artifacts remain additive and multitenant-aware.',
        ],
        'risks' => [
            'router drift must be documented instead of silently resolved'
        ],
        'status' => 'pending',
        'dependencies' => ['ticket_000'],
    ],
    'coder_proposal' => [
        'artifact_type' => 'coder_proposal',
        'schema_version' => '1.0.0',
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'app_id' => $scope['app_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'ticket_id' => 'ticket_001',
        'proposal_id' => 'proposal_001',
        'coder_id' => 'Coder_OpenAI',
        'summary' => 'Add canon, schemas, validator and dedicated test.',
        'target_files' => ['docs/canon/SUKI_CONTROL_TOWER.md', 'framework/app/Core/ControlTowerArtifactValidator.php'],
        'change_strategy' => 'Additive governance-first implementation.',
        'risks' => ['Do not create a second execution engine.'],
        'requires_review' => true,
        'result_status' => 'proposed',
        'artifact_refs' => ['docs/contracts/control_tower_contract.json'],
        'created_at' => date('c'),
    ],
    'review_decision' => [
        'artifact_type' => 'review_decision',
        'schema_version' => '1.0.0',
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'app_id' => $scope['app_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'ticket_id' => 'ticket_001',
        'review_id' => 'review_001',
        'reviewed_proposals' => ['proposal_001', 'proposal_002'],
        'decision' => 'approve',
        'compatibility_checks' => [
            'incremental_compatibility' => true,
            'json_contracts_respected' => true,
            'suki_canon_respected' => true,
            'no_destructive_rewrite' => true,
            'multitenant_respected' => true,
            'router_policy_respected' => true,
        ],
        'reasons' => ['All changes are additive and governance-aligned.'],
        'created_at' => date('c'),
    ],
    'file_registry' => [
        'artifact_type' => 'file_registry',
        'schema_version' => '1.0.0',
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'app_id' => $scope['app_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'registry_id' => 'registry_001',
        'files_created' => [
            ['path' => 'docs/canon/SUKI_CONTROL_TOWER.md', 'category' => 'doc', 'status' => 'created'],
        ],
        'files_modified' => [
            ['path' => 'framework/docs/INDEX.md', 'category' => 'doc', 'status' => 'modified'],
        ],
        'backups' => [
            ['path' => 'project/storage/backups/20260316_004033', 'run_id' => '20260316_004033', 'created_at' => date('c')],
        ],
        'temporaries' => [
            [
                'path' => 'framework/tests/tmp/control_tower_review_report.json',
                'category' => 'test_report',
                'ttl_seconds' => 86400,
                'created_at' => date('c'),
                'expires_at' => date('c', time() + 86400),
                'status' => 'active',
            ],
        ],
        'snapshots' => [
            ['path' => '_snapshot_review_20260314_124515', 'reason' => 'manual pre-change snapshot', 'created_at' => date('c')],
        ],
        'created_at' => date('c'),
    ],
    'agentops_incident' => [
        'artifact_type' => 'agentops_incident',
        'schema_version' => '1.0.0',
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'app_id' => $scope['app_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'incident_id' => 'incident_001',
        'source_component' => 'AgentOpsMonitor',
        'severity' => 'medium',
        'category' => 'router_drift',
        'route_path' => 'cache>rules>skills>rag>llm',
        'gate_decision' => 'allow',
        'tool_calls_count' => 1,
        'latency_ms' => 210,
        'token_cost_estimate' => 0.0021,
        'symptoms' => ['Active runtime route includes skills while canonical router doc omits it.'],
        'recommended_actions' => ['Record drift explicitly in Control Tower artifacts.'],
        'status' => 'open',
        'detected_at' => date('c'),
    ],
    'production_critic_finding' => [
        'artifact_type' => 'production_critic_finding',
        'schema_version' => '1.0.0',
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'app_id' => $scope['app_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'finding_id' => 'finding_001',
        'severity' => 'high',
        'category' => 'improvement',
        'evidence_refs' => ['ops_token_usage:2026-03-15', 'agentops_incident:incident_001'],
        'recurrence_count' => 3,
        'recommendation' => 'Tighten route drift monitoring before new router addenda.',
        'status' => 'triaged',
        'detected_at' => date('c'),
    ],
    'checkpoint_state' => [
        'artifact_type' => 'checkpoint_state',
        'schema_version' => '1.0.0',
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'app_id' => $scope['app_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'ticket_id' => 'ticket_001',
        'checkpoint_id' => 'checkpoint_001',
        'stage' => 'review',
        'status' => 'paused',
        'resume_token' => 'resume_ticket_001_review',
        'state_payload' => ['review_id' => 'review_001', 'pending_actor' => 'ReviewerAgent'],
        'decision_trace' => ['ticket planned', 'proposal collected', 'review paused'],
        'artifacts' => ['proposal_001', 'review_001'],
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ],
    'sprint_decision' => [
        'artifact_type' => 'sprint_decision',
        'schema_version' => '1.0.0',
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'app_id' => $scope['app_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'decision_id' => 'decision_001',
        'status' => 'CONTINUE',
        'rationale' => ['Schemas and validator are ready; next step is runtime adoption when requested.'],
        'blocking_items' => [],
        'next_steps' => ['Run QA gates', 'Publish canon references'],
        'decided_at' => date('c'),
    ],
];

foreach ($artifacts as $artifactType => $payload) {
    try {
        ControlTowerArtifactValidator::validateOrFail($artifactType, $payload);
    } catch (Throwable $e) {
        $failures[] = $artifactType . ' should validate: ' . $e->getMessage();
    }
}

try {
    $summary = ControlTowerArtifactValidator::buildSprintStatusSummary([
        'tenant_id' => $scope['tenant_id'],
        'project_id' => $scope['project_id'],
        'run_id' => $scope['run_id'],
        'sprint_id' => $scope['sprint_id'],
        'sprint_status' => 'continue',
        'files_modified' => ['framework/docs/INDEX.md', 'framework/docs/INDEX.md', 'docs/canon/SUKI_CONTROL_TOWER.md'],
        'risks_detected' => ['router drift', 'app_id/project_id compatibility'],
        'temporaries_created' => ['framework/tests/tmp/control_tower_review_report.json'],
        'backups_created' => ['project/storage/backups/20260316_004033'],
        'incidents_detected' => ['incident_001'],
        'next_steps' => ['Run QA gates', 'Publish docs'],
    ]);
    if (($summary['app_id'] ?? '') !== $scope['project_id']) {
        $failures[] = 'summary builder should fallback app_id to project_id.';
    }
    $counts = is_array($summary['counts'] ?? null) ? (array) $summary['counts'] : [];
    if ((int) ($counts['files_modified'] ?? -1) !== 2) {
        $failures[] = 'summary builder should dedupe files_modified.';
    }
} catch (Throwable $e) {
    $failures[] = 'summary builder should validate: ' . $e->getMessage();
}

$invalidDecision = $artifacts['review_decision'];
$invalidDecision['decision'] = 'ship_now';
try {
    ControlTowerArtifactValidator::validateOrFail('review_decision', $invalidDecision);
    $failures[] = 'invalid review decision should fail.';
} catch (Throwable $e) {
    // expected
}

try {
    $repo = new ContractRepository();
    $runSchema = $repo->getSchema('control_tower_run.schema');
    $summarySchema = $repo->getSchema('sprint_status_summary.schema');
    if (($runSchema['contract_id'] ?? '') !== 'control_tower_run') {
        $failures[] = 'control_tower_run schema lookup failed.';
    }
    if (($summarySchema['contract_id'] ?? '') !== 'sprint_status_summary') {
        $failures[] = 'sprint_status_summary schema lookup failed.';
    }
} catch (Throwable $e) {
    $failures[] = 'schema repository should resolve Control Tower schemas: ' . $e->getMessage();
}

$contractPath = dirname(__DIR__, 2) . '/docs/contracts/control_tower_contract.json';
$contractRaw = file_get_contents($contractPath);
$contract = is_string($contractRaw) ? json_decode($contractRaw, true) : null;
if (!is_array($contract)) {
    $failures[] = 'control_tower_contract.json should be valid JSON.';
} else {
    if (($contract['contract_id'] ?? '') !== 'control_tower_contract') {
        $failures[] = 'control_tower contract_id mismatch.';
    }
    $requiredScope = is_array($contract['required_scope_fields'] ?? null) ? (array) $contract['required_scope_fields'] : [];
    if (!in_array('app_id', $requiredScope, true)) {
        $failures[] = 'control_tower contract should require app_id.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
