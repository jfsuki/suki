<?php
// framework/tests/whatsapp_idempotency_test.php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp_webhook_common.php';

$failures = [];
$secret = 'wa-idempotency-secret';
$runToken = (string) time() . (string) random_int(1000, 9999);
$messageId = 'wamid.IDEMPOTENCY_' . $runToken;
$payload = whatsappWebhookBasePayload($messageId, '573003333333', 'hola dedupe');

$request = [
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
];

$first = runWhatsAppWebhookRoute($request);
$second = runWhatsAppWebhookRoute($request);

$firstJson = $first['json'];
$secondJson = $second['json'];

if (!is_array($firstJson) || (string) ($firstJson['status'] ?? '') !== 'success') {
    $failures[] = 'Primer webhook WhatsApp debe responder success.';
}
if (!is_array($secondJson) || (string) ($secondJson['status'] ?? '') !== 'success') {
    $failures[] = 'Segundo webhook WhatsApp (retry) debe responder success.';
}

if (is_array($secondJson)) {
    $secondEnqueued = (bool) ($secondJson['enqueued'] ?? true);
    if ($secondEnqueued) {
        $failures[] = 'Retry con mismo message_id no debe encolar de nuevo.';
    }
    $message = mb_strtolower((string) ($secondJson['message'] ?? ''), 'UTF-8');
    if (!str_contains($message, 'duplicado')) {
        $failures[] = 'Retry con mismo message_id debe marcarse como duplicado.';
    }
}

$count = whatsappQueueCountByMessageId($messageId);
if ($count !== 1) {
    $failures[] = 'Idempotencia WhatsApp esperada: 1 job en cola por message_id. Actual=' . $count;
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'queue_count' => $count,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
