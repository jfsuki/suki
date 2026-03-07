<?php
// framework/tests/whatsapp_rejects_put_test.php

declare(strict_types=1);

require_once __DIR__ . '/webhook_security_common.php';

$failures = [];

$result = runWebhookSecurityRoute([
    'route' => 'channels/whatsapp/webhook',
    'method' => 'PUT',
    'env' => [
        'APP_ENV' => 'dev',
        'ALLOW_INSECURE_WEBHOOKS' => '1',
    ],
    'payload' => [
        'entry' => [],
    ],
]);

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'WhatsApp webhook PUT debe responder JSON de error.';
} else {
    if ((string) ($json['status'] ?? '') !== 'error') {
        $failures[] = 'WhatsApp webhook PUT debe bloquearse.';
    }
    if (!str_contains((string) ($json['message'] ?? ''), 'Metodo no permitido')) {
        $failures[] = 'WhatsApp webhook PUT debe devolver 405 metodo no permitido.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
