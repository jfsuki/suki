<?php
// framework/tests/whatsapp_fast_ack_test.php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp_webhook_common.php';

$failures = [];
$secret = 'wa-fast-ack-secret';
$runToken = (string) time();
$messageId = 'wamid.FAST_ACK_' . $runToken;
$payload = whatsappWebhookBasePayload($messageId, '573001111111', 'hola fast ack');

$started = microtime(true);
$result = runWhatsAppWebhookRoute([
    'route' => 'channels/whatsapp/webhook',
    'method' => 'POST',
    'env' => [
        'WHATSAPP_APP_SECRET' => $secret,
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
    ],
    'headers' => [
        'X-Hub-Signature-256' => whatsappTestSignature($secret),
    ],
    'payload' => $payload,
]);
$elapsedMs = (int) round((microtime(true) - $started) * 1000);

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'WhatsApp webhook debe responder JSON en fast-ack.';
} else {
    if ((string) ($json['status'] ?? '') !== 'success') {
        $failures[] = 'WhatsApp webhook fast-ack debe responder success.';
    }
    if (!(bool) ($json['ok'] ?? false)) {
        $failures[] = 'WhatsApp webhook fast-ack debe devolver ok=true.';
    }
    if (!array_key_exists('enqueued', $json)) {
        $failures[] = 'WhatsApp webhook fast-ack debe incluir bandera enqueued.';
    }
}

if ($elapsedMs > 2500) {
    $failures[] = 'WhatsApp fast-ack lento: ' . $elapsedMs . 'ms (esperado <= 2500ms).';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'elapsed_ms' => $elapsedMs,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

