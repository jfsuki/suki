<?php
// framework/tests/builder_onboarding_flow_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\BuilderOnboardingFlow;

$flow = new BuilderOnboardingFlow();
$failures = [];

$baseOps = [
    'parseInstallPlaybookRequest' => fn(string $text): array => ['matched' => false],
    'classifyWithPlaybookIntents' => fn(string $text, array $profile): array => [],
    'isBuilderOnboardingTrigger' => fn(string $text): bool => str_contains($text, 'crear una app'),
    'detectBusinessType' => fn(string $text): string => preg_match('/ferreteria|herramientas/u', $text) === 1 ? 'ferreteria' : '',
    'isFormListQuestion' => fn(string $text): bool => str_contains($text, 'formularios'),
    'buildFormList' => fn(): string => 'Aun no hay formularios. Quieres crear uno?',
    'isEntityListQuestion' => fn(string $text): bool => str_contains($text, 'tablas'),
    'buildEntityList' => fn(): string => 'Tablas creadas: clientes.',
    'isBuilderProgressQuestion' => fn(string $text): bool => str_contains($text, 'estado del proyecto'),
    'buildProjectStatus' => fn(): string => 'Estado del proyecto: listo.',
];

$delegated = 0;
$coreHandler = function (
    string $text,
    array $state,
    array $profile,
    string $tenantId,
    string $userId,
    bool $isOnboarding,
    bool $trigger,
    bool $businessHint
) use (&$delegated): ?array {
    $delegated++;
    return [
        'action' => 'ask_user',
        'reply' => 'delegated',
        'state' => $state,
        'meta' => [
            'is_onboarding' => $isOnboarding,
            'trigger' => $trigger,
            'business_hint' => $businessHint,
        ],
    ];
};

$listResult = $flow->handle(
    'que formularios?',
    ['active_task' => 'builder_onboarding'],
    [],
    'default',
    'test',
    $baseOps,
    $coreHandler
);
if (!is_array($listResult) || (string) ($listResult['action'] ?? '') !== 'respond_local') {
    $failures[] = 'Expected respond_local for form list question.';
}

$playbookOps = $baseOps;
$playbookOps['classifyWithPlaybookIntents'] = fn(string $text, array $profile): array => [
    'action' => 'APPLY_PLAYBOOK_FERRETERIA',
    'confidence' => 0.95,
];
$playbookResult = $flow->handle(
    'tengo una ferreteria y pierdo plata',
    ['active_task' => 'builder_onboarding'],
    [],
    'default',
    'test',
    $playbookOps,
    $coreHandler
);
if ($playbookResult !== null) {
    $failures[] = 'Expected null when playbook intent should bypass onboarding.';
}

$businessHintOnlyResult = $flow->handle(
    'tengo una ferreteria',
    [],
    [],
    'default',
    'test',
    $baseOps,
    $coreHandler
);
if ($businessHintOnlyResult !== null) {
    $failures[] = 'Business hint alone should not enter builder onboarding.';
}

$delegatedResult = $flow->handle(
    'quiero crear una app',
    ['active_task' => 'builder_onboarding'],
    [],
    'default',
    'test',
    $baseOps,
    $coreHandler
);
if (!is_array($delegatedResult) || (string) ($delegatedResult['reply'] ?? '') !== 'delegated') {
    $failures[] = 'Expected delegated handler execution for onboarding trigger.';
}

$ok = empty($failures);
$report = [
    'ok' => $ok,
    'delegated_calls' => $delegated,
    'failures' => $failures,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
