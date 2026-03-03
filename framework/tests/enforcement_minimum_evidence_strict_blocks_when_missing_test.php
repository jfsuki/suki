<?php
// framework/tests/enforcement_minimum_evidence_strict_blocks_when_missing_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\IntentRouter;

$failures = [];
$previousMode = getenv('ENFORCEMENT_MODE');
putenv('ENFORCEMENT_MODE=strict');

$router = new IntentRouter();
$result = $router->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'necesito ayuda'],
        ],
    ],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'enf_strict_min_evidence',
    'role' => 'admin',
    'mode' => 'app',
]);

$telemetry = $result->telemetry();
if (!$result->isLocalResponse()) {
    $failures[] = 'strict: sin minimum evidence debe bloquear y responder local (ASK o safe response).';
}
if ((string) ($telemetry['gate_decision'] ?? '') !== 'blocked') {
    $failures[] = 'strict: gate_decision esperado blocked cuando falta minimum evidence.';
}
$violations = is_array($telemetry['contract_violations'] ?? null) ? (array) $telemetry['contract_violations'] : [];
$foundMissingEvidence = false;
foreach ($violations as $violation) {
    if (str_starts_with((string) $violation, 'minimum_evidence_missing:')) {
        $foundMissingEvidence = true;
        break;
    }
}
if (!$foundMissingEvidence) {
    $failures[] = 'strict: debe registrar violation minimum_evidence_missing.';
}
$evidence = is_array($telemetry['evidence_status'] ?? null) ? (array) $telemetry['evidence_status'] : [];
$missing = is_array($evidence['missing'] ?? null) ? (array) $evidence['missing'] : [];
if (!in_array('at_least_one_source_reference', $missing, true)) {
    $failures[] = 'strict: evidence_status.missing debe incluir at_least_one_source_reference.';
}

if ($previousMode === false) {
    putenv('ENFORCEMENT_MODE');
} else {
    putenv('ENFORCEMENT_MODE=' . $previousMode);
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

