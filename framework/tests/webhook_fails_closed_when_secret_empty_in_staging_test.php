<?php
// framework/tests/webhook_fails_closed_when_secret_empty_in_staging_test.php

declare(strict_types=1);

require_once __DIR__ . '/webhook_security_common.php';

$failures = [];
$baseEnv = [
    'APP_ENV' => 'staging',
    'ALLOW_INSECURE_WEBHOOKS' => '1',
];

$telegram = runWebhookSecurityRoute([
    'route' => 'channels/telegram/webhook',
    'method' => 'POST',
    'env' => $baseEnv + [
        'TELEGRAM_WEBHOOK_SECRET' => '',
        'ALLOW_RUNTIME_SCHEMA' => '1',
    ],
    'payload' => [
        'update_id' => (int) sprintf('%u', crc32('staging_tg_' . time())),
        'message' => [
            'chat' => ['id' => 'staging_tg_chat'],
            'text' => 'hola',
        ],
    ],
]);

$telegramJson = $telegram['json'];
if (!is_array($telegramJson) || (string) ($telegramJson['status'] ?? '') !== 'error' || !str_contains((string) ($telegramJson['message'] ?? ''), 'secret requerido')) {
    $failures[] = 'Telegram debe fail-closed en staging cuando TELEGRAM_WEBHOOK_SECRET esta vacio.';
}

$whatsApp = runWebhookSecurityRoute([
    'route' => 'channels/whatsapp/webhook',
    'method' => 'POST',
    'env' => $baseEnv + [
        'WHATSAPP_APP_SECRET' => '',
        'ALLOW_RUNTIME_SCHEMA' => '1',
    ],
    'payload' => [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'messages' => [
                                [
                                    'id' => 'wamid.STAGING_FAIL_CLOSED',
                                    'from' => '573000000000',
                                    'text' => ['body' => 'hola'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);

$whatsAppJson = $whatsApp['json'];
if (!is_array($whatsAppJson) || (string) ($whatsAppJson['status'] ?? '') !== 'error' || !str_contains((string) ($whatsAppJson['message'] ?? ''), 'secret requerido')) {
    $failures[] = 'WhatsApp debe fail-closed en staging cuando WHATSAPP_APP_SECRET esta vacio.';
}

$alanube = runWebhookSecurityRoute([
    'route' => 'integrations/alanube/webhook',
    'method' => 'POST',
    'env' => $baseEnv + [
        'ALANUBE_WEBHOOK_SECRET' => '',
    ],
    'payload' => [
        'integration_id' => 'alanube_main',
        'event' => 'document.updated',
        'id' => 'doc-staging-fail-closed',
    ],
]);

$alanubeJson = $alanube['json'];
if (!is_array($alanubeJson) || (string) ($alanubeJson['status'] ?? '') !== 'error' || !str_contains((string) ($alanubeJson['message'] ?? ''), 'secret requerido')) {
    $failures[] = 'Alanube debe fail-closed en staging cuando ALANUBE_WEBHOOK_SECRET esta vacio.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
