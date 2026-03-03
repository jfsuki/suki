<?php
// framework/tests/enforcement_warn_allows_but_logs_when_missing_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\IntentRouter;

$failures = [];
$previousMode = getenv('ENFORCEMENT_MODE');
putenv('ENFORCEMENT_MODE=warn');

$router = new IntentRouter();
$result = $router->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'quiero continuar'],
        ],
    ],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'enf_warn_min_evidence',
    'role' => 'admin',
    'mode' => 'app',
]);

$telemetry = $result->telemetry();
if ((string) ($telemetry['gate_decision'] ?? '') !== 'warn') {
    $failures[] = 'warn: gate_decision esperado warn cuando falta minimum evidence.';
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
    $failures[] = 'warn: debe registrar violation minimum_evidence_missing.';
}

if (!$result->isLocalResponse() && !$result->isLlmRequest()) {
    $failures[] = 'warn: debe permitir flujo (LLM) o degradar a ASK; no debe quedar en estado invalido.';
}

if ($result->isLocalResponse()) {
    $reply = mb_strtolower($result->reply(), 'UTF-8');
    if (!str_contains($reply, 'evidencia minima')) {
        $failures[] = 'warn: cuando degrada a ASK, reply debe explicar evidencia minima faltante.';
    }
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

