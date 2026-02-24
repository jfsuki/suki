<?php
// framework/tests/workflow_executor_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\WorkflowExecutor;

$workflow = [
    'meta' => [
        'id' => 'wf_exec_test',
        'name' => 'Executor test',
        'status' => 'draft',
        'revision' => 1,
    ],
    'nodes' => [
        [
            'id' => 'n_input',
            'type' => 'input',
            'title' => 'Input',
            'runPolicy' => ['timeout_ms' => 10000, 'retry_max' => 0, 'token_budget' => 0],
        ],
        [
            'id' => 'n_generate',
            'type' => 'generate',
            'title' => 'Generate',
            'promptTemplate' => 'Cliente {{input.cliente}} total {{input.total}}',
            'runPolicy' => ['timeout_ms' => 10000, 'retry_max' => 0, 'token_budget' => 0],
        ],
        [
            'id' => 'n_output',
            'type' => 'output',
            'title' => 'Output',
            'runPolicy' => ['timeout_ms' => 10000, 'retry_max' => 0, 'token_budget' => 0],
        ],
    ],
    'edges' => [
        ['from' => 'n_input', 'to' => 'n_generate', 'mapping' => ['cliente' => 'output.cliente', 'total' => 'output.total']],
        ['from' => 'n_generate', 'to' => 'n_output', 'mapping' => ['text' => 'output.text']],
    ],
    'assets' => [],
    'theme' => ['presetName' => 'clean_business'],
    'versioning' => ['revision' => 1, 'historyPointers' => []],
];

$executor = new WorkflowExecutor();
$result = $executor->execute($workflow, ['cliente' => 'Ana', 'total' => 120000]);

$failures = [];
if (!(bool) ($result['ok'] ?? false)) {
    $failures[] = 'Workflow executor should return ok=true.';
}
$final = is_array($result['final_output'] ?? null) ? (array) $result['final_output'] : [];
$text = (string) ($final['text'] ?? '');
if (!str_contains($text, 'Ana') || !str_contains($text, '120000')) {
    $failures[] = 'Final output text must include mapped input values.';
}
$traces = is_array($result['traces'] ?? null) ? (array) $result['traces'] : [];
if (count($traces) !== 3) {
    $failures[] = 'Workflow executor should produce one trace per node.';
}
$levels = is_array($result['levels'] ?? null) ? (array) $result['levels'] : [];
if (count($levels) < 3) {
    $failures[] = 'Workflow executor should expose topological levels.';
}

$ok = empty($failures);
echo json_encode(['ok' => $ok, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

