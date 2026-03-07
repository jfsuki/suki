<?php
// framework/tests/telegram_rejects_get_test.php

declare(strict_types=1);

require_once __DIR__ . '/webhook_security_common.php';

$failures = [];

$result = runWebhookSecurityRoute([
    'route' => 'channels/telegram/webhook',
    'method' => 'GET',
    'env' => [
        'APP_ENV' => 'dev',
        'ALLOW_INSECURE_WEBHOOKS' => '1',
    ],
]);

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'Telegram webhook GET debe responder JSON de error.';
} else {
    if ((string) ($json['status'] ?? '') !== 'error') {
        $failures[] = 'Telegram webhook GET debe bloquearse.';
    }
    if (!str_contains((string) ($json['message'] ?? ''), 'Metodo no permitido')) {
        $failures[] = 'Telegram webhook GET debe devolver 405 metodo no permitido.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
