<?php
// framework/tests/channels_stress_worker.php

declare(strict_types=1);

$encoded = $argv[1] ?? '';
if ($encoded === '') {
    fwrite(STDERR, "Uso: php channels_stress_worker.php <config_base64>\n");
    exit(1);
}

$rawConfig = base64_decode($encoded, true);
$config = is_string($rawConfig) ? json_decode($rawConfig, true) : null;
if (!is_array($config)) {
    fwrite(STDERR, "config invalida\n");
    exit(1);
}

$iterations = max(1, (int) ($config['iterations'] ?? 10));
$workerId = (int) ($config['worker_id'] ?? 0);
$helper = __DIR__ . '/api_route_turn.php';

$latTelegram = [];
$latWhatsApp = [];
$okTelegram = 0;
$okWhatsApp = 0;
$errTelegram = 0;
$errWhatsApp = 0;
$errors = [];

for ($i = 0; $i < $iterations; $i++) {
    $nonce = (string) (time() . '_' . $workerId . '_' . $i . '_' . random_int(1000, 9999));

    $telegramReq = [
        'route' => 'channels/telegram/webhook',
        'method' => 'POST',
        'env' => [
            'APP_ENV' => 'dev',
            'ALLOW_INSECURE_WEBHOOKS' => '1',
            'TELEGRAM_WEBHOOK_DRY_RUN' => '1',
            'TELEGRAM_DEFAULT_PROJECT' => (string) ($config['project_id'] ?? 'default'),
            'TELEGRAM_DEFAULT_TENANT' => (string) ($config['tenant_id'] ?? 'default'),
        ],
        'payload' => [
            'update_id' => (int) sprintf('%u', crc32('tg_' . $nonce)),
            'message' => [
                'chat' => ['id' => 'tgstress_' . $workerId],
                'text' => 'hola',
            ],
        ],
    ];
    $telegram = callRoute($helper, $telegramReq);
    $latTelegram[] = $telegram['latency_ms'];
    if (($telegram['json']['status'] ?? '') === 'success') {
        $okTelegram++;
    } else {
        $errTelegram++;
        if (count($errors) < 8) {
            $errors[] = ['channel' => 'telegram', 'response' => $telegram['raw']];
        }
    }

    $waReq = [
        'route' => 'channels/whatsapp/webhook',
        'method' => 'POST',
        'env' => [
            'APP_ENV' => 'dev',
            'ALLOW_INSECURE_WEBHOOKS' => '1',
            'WHATSAPP_WEBHOOK_DRY_RUN' => '1',
            'WHATSAPP_DEFAULT_PROJECT' => (string) ($config['project_id'] ?? 'default'),
            'WHATSAPP_DEFAULT_TENANT' => (string) ($config['tenant_id'] ?? 'default'),
        ],
        'payload' => [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    [
                                        'id' => 'wamid.STRESS_' . $nonce,
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
    ];
    $wa = callRoute($helper, $waReq);
    $latWhatsApp[] = $wa['latency_ms'];
    if (($wa['json']['status'] ?? '') === 'success') {
        $okWhatsApp++;
    } else {
        $errWhatsApp++;
        if (count($errors) < 8) {
            $errors[] = ['channel' => 'whatsapp', 'response' => $wa['raw']];
        }
    }
}

echo json_encode([
    'ok' => true,
    'worker_id' => $workerId,
    'iterations' => $iterations,
    'telegram' => [
        'ok_count' => $okTelegram,
        'error_count' => $errTelegram,
        'latencies_ms' => $latTelegram,
    ],
    'whatsapp' => [
        'ok_count' => $okWhatsApp,
        'error_count' => $errWhatsApp,
        'latencies_ms' => $latWhatsApp,
    ],
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function callRoute(string $helper, array $request): array
{
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $start = microtime(true);
    $raw = (string) shell_exec($cmd);
    $latency = (int) round((microtime(true) - $start) * 1000);
    $json = json_decode($raw, true);
    return [
        'latency_ms' => $latency,
        'raw' => trim($raw),
        'json' => is_array($json) ? $json : [],
    ];
}
