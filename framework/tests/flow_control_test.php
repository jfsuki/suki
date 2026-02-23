<?php
// framework/tests/flow_control_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;

$gateway = new ConversationGateway();
$user = 'flow_test_' . time();
$project = 'flow_test_project';
$tenant = 'default';

$cases = [];
$ok = true;

$start = $gateway->handle($tenant, $user, 'quiero crear una app', 'builder', $project);
$cases[] = [
    'step' => 'start',
    'ok' => stripos((string) ($start['reply'] ?? ''), 'Paso 1') !== false,
    'reply' => (string) ($start['reply'] ?? ''),
];

$cancel = $gateway->handle($tenant, $user, 'cancelar', 'builder', $project);
$cases[] = [
    'step' => 'cancel',
    'ok' => (string) ($cancel['action'] ?? '') === 'respond_local' && ($cancel['state']['active_task'] ?? null) === null,
    'reply' => (string) ($cancel['reply'] ?? ''),
];

$resume = $gateway->handle($tenant, $user, 'retomar', 'builder', $project);
$cases[] = [
    'step' => 'resume',
    'ok' => (string) ($resume['action'] ?? '') === 'ask_user' && stripos((string) ($resume['reply'] ?? ''), 'Retomamos') !== false,
    'reply' => (string) ($resume['reply'] ?? ''),
];

$restart = $gateway->handle($tenant, $user, 'reiniciar', 'builder', $project);
$cases[] = [
    'step' => 'restart',
    'ok' => (string) ($restart['state']['onboarding_step'] ?? '') === 'business_type',
    'reply' => (string) ($restart['reply'] ?? ''),
];

foreach ($cases as $case) {
    if (empty($case['ok'])) {
        $ok = false;
        break;
    }
}

echo json_encode([
    'ok' => $ok,
    'cases' => $cases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
