<?php
// framework/tests/workflow_repository_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\WorkflowRepository;

$tmpProject = __DIR__ . '/tmp/workflow_repo_project';
@mkdir($tmpProject . '/contracts', 0775, true);
@mkdir($tmpProject . '/storage', 0775, true);
$repo = new WorkflowRepository($tmpProject);
$workflowId = 'wf_repo_test_' . time();
$contract = [
    'meta' => [
        'id' => $workflowId,
        'name' => 'Repo Test',
        'status' => 'draft',
        'revision' => 1,
    ],
    'nodes' => [
        ['id' => 'n_input', 'type' => 'input', 'title' => 'Input', 'runPolicy' => ['timeout_ms' => 1000, 'retry_max' => 0, 'token_budget' => 0]],
    ],
    'edges' => [],
    'assets' => [],
    'theme' => ['presetName' => 'clean_business'],
    'versioning' => ['revision' => 1, 'historyPointers' => []],
];

$failures = [];
try {
    $saved = $repo->save($contract, 'unit_save');
    if (((int) ($saved['revision'] ?? 0)) < 1) {
        $failures[] = 'Save should return revision >= 1.';
    }
    $loaded = $repo->load($workflowId);
    if ((string) ($loaded['meta']['id'] ?? '') !== $workflowId) {
        $failures[] = 'Loaded workflow id mismatch.';
    }

    $loaded['meta']['name'] = 'Repo Test v2';
    $loaded['nodes'][0]['title'] = 'Input v2';
    $repo->save($loaded, 'unit_save_2');
    $history = $repo->history($workflowId);
    if (count($history) < 2) {
        $failures[] = 'History should keep snapshots per revision.';
    }
    $diff = $repo->diff($workflowId, 1, 2);
    $changed = (int) (($diff['summary']['nodes_changed'] ?? 0));
    if ($changed < 1) {
        $failures[] = 'Diff should detect changed nodes between revisions.';
    }
    $restored = $repo->restore($workflowId, 1);
    if (((int) ($restored['revision'] ?? 0)) < 2) {
        $failures[] = 'Restore should create a new revision.';
    }
} catch (\Throwable $e) {
    $failures[] = $e->getMessage();
}

$ok = empty($failures);
echo json_encode(['ok' => $ok, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
