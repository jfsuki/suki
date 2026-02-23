<?php
// framework/tests/chat_golden.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;

$agent = new ChatAgent();
$runId = (string) time();
$entity = 'golden_clientes_' . $runId;
$base = [
    'channel' => 'local',
    'tenant_id' => 'default',
    'project_id' => 'suki_erp',
    'user_id' => 'golden_user_' . $runId,
    'session_id' => 'golden_session_' . $runId,
];

$steps = [
    ['mode' => 'builder', 'message' => 'hola', 'contains' => 'Cami'],
    ['mode' => 'builder', 'message' => 'quiero crear una app para inventario', 'contains' => 'Paso'],
    ['mode' => 'builder', 'message' => 'quiero crear una tabla ' . $entity, 'contains' => 'Quieres que la cree'],
    ['mode' => 'builder', 'message' => 'si', 'contains' => 'Tabla creada'],
    ['mode' => 'builder', 'message' => 'no', 'contains' => 'seguimos sin campos calculados'],
    ['mode' => 'builder', 'message' => 'crear formulario ' . $entity, 'contains' => 'Formulario creado'],
    ['mode' => 'app', 'message' => 'crear ' . $entity . ' nombre=Ana', 'contains' => 'Registro creado'],
    ['mode' => 'app', 'message' => 'quiero crear una tabla productos', 'contains' => 'Creador de apps'],
    ['mode' => 'builder', 'message' => 'crear cliente nombre=Ana', 'contains' => 'chat de la app'],
];

$results = [];
$ok = 0;

foreach ($steps as $idx => $step) {
    $payload = array_merge($base, [
        'mode' => $step['mode'],
        'message' => $step['message'],
    ]);
    $out = $agent->handle($payload);
    $reply = (string) ($out['data']['reply'] ?? '');
    $pass = stripos($reply, (string) $step['contains']) !== false;
    if ($pass) {
        $ok++;
    }
    $results[] = [
        'step' => $idx + 1,
        'mode' => $step['mode'],
        'message' => $step['message'],
        'reply' => $reply,
        'expected_contains' => $step['contains'],
        'ok' => $pass,
    ];
}

$report = [
    'summary' => [
        'ok' => $ok === count($steps),
        'passed' => $ok,
        'failed' => count($steps) - $ok,
        'total' => count($steps),
        'entity' => $entity,
        'run_id' => $runId,
        'ran_at' => date('Y-m-d H:i:s'),
    ],
    'results' => $results,
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json !== false) {
    $tmpDir = __DIR__ . '/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0777, true);
    }
    file_put_contents($tmpDir . '/chat_golden_result.json', $json);
}

echo $json . PHP_EOL;

