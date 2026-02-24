<?php
// framework/tests/workflow_compiler_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\WorkflowCompiler;
use App\Core\WorkflowValidator;

$compiler = new WorkflowCompiler();
$proposal = $compiler->compile('quiero un flujo para generar cotizacion y salida final');

$failures = [];
if ((string) ($proposal['status'] ?? '') !== 'PROPOSAL_READY') {
    $failures[] = 'Compiler should return PROPOSAL_READY.';
}
if (!(bool) ($proposal['needs_confirmation'] ?? false)) {
    $failures[] = 'Compiler should request confirmation before apply.';
}
$changes = is_array($proposal['changes'] ?? null) ? (array) $proposal['changes'] : [];
if (count($changes) < 3) {
    $failures[] = 'Compiler should emit structural changes.';
}
$contract = is_array($proposal['proposed_contract'] ?? null) ? (array) $proposal['proposed_contract'] : [];
try {
    WorkflowValidator::validateOrFail($contract);
} catch (\Throwable $e) {
    $failures[] = 'Compiled contract must be valid: ' . $e->getMessage();
}

$ok = empty($failures);
echo json_encode(['ok' => $ok, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

