<?php
// framework/tests/mode_guard_policy_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ModeGuardPolicy;

$policy = new ModeGuardPolicy();
$failures = [];

$buildGuard = $policy->evaluate('app', true, false, false);
if (!is_array($buildGuard) || (string) ($buildGuard['telemetry'] ?? '') !== 'build_guard') {
    $failures[] = 'Expected build_guard in app mode with build signals.';
}

$useGuard = $policy->evaluate('builder', false, true, false);
if (!is_array($useGuard) || (string) ($useGuard['telemetry'] ?? '') !== 'use_guard') {
    $failures[] = 'Expected use_guard in builder mode with runtime CRUD signals.';
}

$playbookBypass = $policy->evaluate('builder', false, true, true);
if ($playbookBypass !== null) {
    $failures[] = 'Expected null when builder runtime CRUD is a playbook request.';
}

$ok = empty($failures);
$report = [
    'ok' => $ok,
    'tests' => [
        'app_build_guard' => $buildGuard,
        'builder_use_guard' => $useGuard,
        'builder_playbook_bypass' => $playbookBypass,
    ],
    'failures' => $failures,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

