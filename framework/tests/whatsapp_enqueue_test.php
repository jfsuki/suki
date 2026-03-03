<?php
// framework/tests/whatsapp_enqueue_test.php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp_webhook_common.php';

$failures = [];
$secret = 'wa-enqueue-secret';
$runToken = (string) time();
$messageId = 'wamid.ENQUEUE_' . $runToken;
$payload = whatsappWebhookBasePayload($messageId, '573002222222', 'hola enqueue');

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

$json = $result['json'];
if (!is_array($json)) {
    $failures[] = 'WhatsApp enqueue debe responder JSON.';
} else {
    if ((string) ($json['status'] ?? '') !== 'success') {
        $failures[] = 'WhatsApp enqueue debe responder success.';
    }
    if (!(bool) ($json['enqueued'] ?? false)) {
        $failures[] = 'WhatsApp enqueue debe crear un job nuevo (enqueued=true).';
    }
    if (trim((string) ($json['queue_id'] ?? '')) === '') {
        $failures[] = 'WhatsApp enqueue debe devolver queue_id.';
    }
}

$row = whatsappLatestQueueRowByMessageId($messageId);
if (!is_array($row)) {
    $failures[] = 'No se encontro row en jobs_queue para mensaje WhatsApp encolado.';
} else {
    if ((string) ($row['job_type'] ?? '') !== 'whatsapp.inbound') {
        $failures[] = 'job_type esperado whatsapp.inbound.';
    }
}

$count = whatsappQueueCountByMessageId($messageId);
if ($count < 1) {
    $failures[] = 'jobs_queue debe contener al menos 1 job para el message_id encolado.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'queue_count' => $count,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

