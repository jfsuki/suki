<?php
// framework/tests/workflow_contract_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Contracts\ContractRepository;
use App\Core\WorkflowValidator;

$failures = [];

$validWorkflow = [
    'meta' => [
        'id' => 'wf_builder_bootstrap_v1',
        'name' => 'Builder Bootstrap',
        'status' => 'draft',
        'revision' => 1,
    ],
    'nodes' => [
        [
            'id' => 'n_input',
            'type' => 'input',
            'title' => 'Capture request',
            'inputsSchema' => ['type' => 'object'],
            'outputsSchema' => ['type' => 'object'],
            'runPolicy' => [
                'timeout_ms' => 15000,
                'retry_max' => 0,
                'token_budget' => 0,
            ],
        ],
        [
            'id' => 'n_output',
            'type' => 'output',
            'title' => 'Render answer',
            'runPolicy' => [
                'timeout_ms' => 15000,
                'retry_max' => 0,
                'token_budget' => 0,
            ],
        ],
    ],
    'edges' => [
        [
            'from' => 'n_input',
            'to' => 'n_output',
            'mapping' => [
                'user_text' => 'output.text',
            ],
        ],
    ],
    'assets' => [],
    'theme' => [
        'presetName' => 'clean_business',
    ],
    'versioning' => [
        'revision' => 1,
        'historyPointers' => [],
    ],
];

try {
    WorkflowValidator::validateOrFail($validWorkflow);
} catch (Throwable $e) {
    $failures[] = 'valid workflow should pass: ' . $e->getMessage();
}

$invalidWorkflow = $validWorkflow;
$invalidWorkflow['nodes'][0]['runPolicy']['timeout_ms'] = 0;
try {
    WorkflowValidator::validateOrFail($invalidWorkflow);
    $failures[] = 'invalid workflow should fail on timeout_ms.';
} catch (Throwable $e) {
    // expected
}

try {
    $repo = new ContractRepository();
    $schema = $repo->getSchema('workflow.schema');
    if (($schema['title'] ?? '') !== 'WorkflowContract') {
        $failures[] = 'workflow schema title mismatch.';
    }
    $formSchema = $repo->getSchema('form.contract.schema');
    if (!is_array($formSchema) || empty($formSchema)) {
        $failures[] = 'form schema lookup failed after workflow schema add.';
    }
} catch (Throwable $e) {
    $failures[] = 'schema repository check failed: ' . $e->getMessage();
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
