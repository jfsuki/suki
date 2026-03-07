<?php
// framework/tests/enforcement_default_by_env_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\IntentRouter;

$failures = [];

$previousAppEnv = getenv('APP_ENV');
$previousMode = getenv('ENFORCEMENT_MODE');

/**
 * @return array<string, mixed>
 */
function enforcementRouteTelemetry(): array
{
    $router = new IntentRouter();
    $result = $router->route([
        'action' => 'respond_local',
        'reply' => 'ok',
    ]);
    return is_array($result->telemetry()) ? (array) $result->telemetry() : [];
}

// Default by env (no explicit ENFORCEMENT_MODE)
putenv('ENFORCEMENT_MODE');

putenv('APP_ENV=dev');
$devTelemetry = enforcementRouteTelemetry();
if ((string) ($devTelemetry['enforcement_mode'] ?? '') !== 'warn') {
    $failures[] = 'dev sin override debe resolver enforcement_mode=warn.';
}
if ((string) ($devTelemetry['enforcement_mode_source'] ?? '') !== 'app_env_default') {
    $failures[] = 'dev sin override debe marcar source=app_env_default.';
}

putenv('APP_ENV=staging');
$stagingTelemetry = enforcementRouteTelemetry();
if ((string) ($stagingTelemetry['enforcement_mode'] ?? '') !== 'warn') {
    $failures[] = 'staging sin override debe resolver enforcement_mode=warn.';
}
if ((string) ($stagingTelemetry['enforcement_mode_source'] ?? '') !== 'app_env_default') {
    $failures[] = 'staging sin override debe marcar source=app_env_default.';
}

putenv('APP_ENV=prod');
$prodTelemetry = enforcementRouteTelemetry();
if ((string) ($prodTelemetry['enforcement_mode'] ?? '') !== 'strict') {
    $failures[] = 'prod sin override debe resolver enforcement_mode=strict.';
}
if ((string) ($prodTelemetry['enforcement_mode_source'] ?? '') !== 'app_env_default') {
    $failures[] = 'prod sin override debe marcar source=app_env_default.';
}

// Explicit override must win
putenv('APP_ENV=prod');
putenv('ENFORCEMENT_MODE=off');
$overrideTelemetry = enforcementRouteTelemetry();
if ((string) ($overrideTelemetry['enforcement_mode'] ?? '') !== 'off') {
    $failures[] = 'override explicito ENFORCEMENT_MODE=off debe respetarse en prod.';
}
if ((string) ($overrideTelemetry['enforcement_mode_source'] ?? '') !== 'env_override') {
    $failures[] = 'override explicito debe marcar source=env_override.';
}

if ($previousMode === false) {
    putenv('ENFORCEMENT_MODE');
} else {
    putenv('ENFORCEMENT_MODE=' . $previousMode);
}
if ($previousAppEnv === false) {
    putenv('APP_ENV');
} else {
    putenv('APP_ENV=' . $previousAppEnv);
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
