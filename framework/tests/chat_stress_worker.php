<?php
// framework/tests/chat_stress_worker.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$encoded = $argv[1] ?? '';
if ($encoded === '') {
    fwrite(STDERR, "Uso: php chat_stress_worker.php <config_base64>\n");
    exit(1);
}

$rawConfig = base64_decode($encoded, true);
$config = is_string($rawConfig) ? json_decode($rawConfig, true) : null;
if (!is_array($config)) {
    fwrite(STDERR, "config invalida\n");
    exit(1);
}

$iterations = max(1, (int) ($config['iterations'] ?? 10));
$tenantId = (string) ($config['tenant_id'] ?? 'default');
$projectId = (string) ($config['project_id'] ?? 'default');
$mode = (string) ($config['mode'] ?? 'app');
$workerId = (int) ($config['worker_id'] ?? 0);
$sessionBase = (string) ($config['session_base'] ?? 'stress_chat');
$userBase = (string) ($config['user_base'] ?? 'stress_user');

$messages = [
    'hola',
    'que puedes hacer',
    'estado del proyecto',
    'que tablas hay',
    'quiero crear una app',
    'mi negocio es una ferreteria',
    'mixto',
    'inventario, facturacion y pagos',
    'factura, cotizacion',
];

$agent = new \App\Core\ChatAgent();
$latencies = [];
$okCount = 0;
$errorCount = 0;
$samples = [];

$sessionId = $sessionBase . '_' . $workerId;
$userId = $userBase . '_' . $workerId;

for ($i = 0; $i < $iterations; $i++) {
    $message = $messages[$i % count($messages)];
    $payload = [
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'mode' => $mode,
        'session_id' => $sessionId,
        'user_id' => $userId,
        'message' => $message,
    ];

    $start = microtime(true);
    try {
        $result = $agent->handle($payload);
        $status = (string) ($result['status'] ?? 'error');
    } catch (\Throwable $e) {
        $status = 'error';
    }
    $elapsed = (int) round((microtime(true) - $start) * 1000);
    $latencies[] = $elapsed;
    if ($status === 'success') {
        $okCount++;
    } else {
        $errorCount++;
        if (count($samples) < 5) {
            $samples[] = [
                'iteration' => $i + 1,
                'message' => $message,
                'status' => $status,
                'latency_ms' => $elapsed,
            ];
        }
    }
}

echo json_encode([
    'ok' => true,
    'worker_id' => $workerId,
    'iterations' => $iterations,
    'ok_count' => $okCount,
    'error_count' => $errorCount,
    'latencies_ms' => $latencies,
    'error_samples' => $samples,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

