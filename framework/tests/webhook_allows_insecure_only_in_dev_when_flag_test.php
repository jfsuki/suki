<?php
// framework/tests/webhook_allows_insecure_only_in_dev_when_flag_test.php

declare(strict_types=1);

require_once __DIR__ . '/webhook_security_common.php';

$failures = [];
$basePayload = [
    'message' => [
        'chat' => ['id' => 'tg_insecure_policy_chat'],
        'text' => 'hola',
    ],
];

$devAllowed = runWebhookSecurityRoute([
    'route' => 'channels/telegram/webhook',
    'method' => 'POST',
    'env' => [
        'APP_ENV' => 'dev',
        'ALLOW_INSECURE_WEBHOOKS' => '1',
        'TELEGRAM_WEBHOOK_SECRET' => '',
        'ALLOW_RUNTIME_SCHEMA' => '1',
    ],
    'payload' => $basePayload + ['update_id' => (int) sprintf('%u', crc32('tg_dev_allow_' . time()))],
]);
$devAllowedJson = $devAllowed['json'];
if (!is_array($devAllowedJson) || (string) ($devAllowedJson['status'] ?? '') !== 'success') {
    $failures[] = 'Dev con ALLOW_INSECURE_WEBHOOKS=1 debe permitir Telegram sin secret.';
}

$devDenied = runWebhookSecurityRoute([
    'route' => 'channels/telegram/webhook',
    'method' => 'POST',
    'env' => [
        'APP_ENV' => 'dev',
        'ALLOW_INSECURE_WEBHOOKS' => '0',
        'TELEGRAM_WEBHOOK_SECRET' => '',
        'ALLOW_RUNTIME_SCHEMA' => '1',
    ],
    'payload' => $basePayload + ['update_id' => (int) sprintf('%u', crc32('tg_dev_deny_' . time()))],
]);
$devDeniedJson = $devDenied['json'];
if (!is_array($devDeniedJson) || (string) ($devDeniedJson['status'] ?? '') !== 'error' || !str_contains((string) ($devDeniedJson['message'] ?? ''), 'secret requerido')) {
    $failures[] = 'Dev con ALLOW_INSECURE_WEBHOOKS=0 debe bloquear Telegram sin secret.';
}

$stagingDenied = runWebhookSecurityRoute([
    'route' => 'channels/telegram/webhook',
    'method' => 'POST',
    'env' => [
        'APP_ENV' => 'staging',
        'ALLOW_INSECURE_WEBHOOKS' => '1',
        'TELEGRAM_WEBHOOK_SECRET' => '',
        'ALLOW_RUNTIME_SCHEMA' => '1',
    ],
    'payload' => $basePayload + ['update_id' => (int) sprintf('%u', crc32('tg_staging_deny_' . time()))],
]);
$stagingDeniedJson = $stagingDenied['json'];
if (!is_array($stagingDeniedJson) || (string) ($stagingDeniedJson['status'] ?? '') !== 'error' || !str_contains((string) ($stagingDeniedJson['message'] ?? ''), 'secret requerido')) {
    $failures[] = 'Staging debe bloquear Telegram sin secret aunque ALLOW_INSECURE_WEBHOOKS=1.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
