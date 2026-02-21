<?php
// framework/tests/chat_api_single_demo.php
// Simula 1 conversacion real (usuario no tecnico) usando las APIs del proyecto.

declare(strict_types=1);

$php = PHP_BINARY ?: 'php';
$turnScript = __DIR__ . '/chat_api_turn.php';

if (!is_file($turnScript)) {
    fwrite(STDERR, "No existe helper de turno API: {$turnScript}\n");
    exit(1);
}

$runId = (string) time();
$tenantId = 'default';
$userId = 'demo_notecnico_' . $runId;
$sessionId = 'sess_demo_notecnico_' . $runId;
$mode = 'builder';
$entityName = 'demo_clientes_' . $runId;

$conversation = [
    [
        'user' => 'hola',
        'expect_contains' => 'Hola, soy Cami',
    ],
    [
        'user' => 'no se nada de sistemas, ayudame paso a paso',
        'expect_contains_any' => ['Puedo ayudarte', 'Paso 1', 'crear tabla', 'Responde una opcion', 'tipo de negocio'],
    ],
    [
        'user' => 'quiero crear una tabla ' . $entityName,
        'expect_contains_any' => ['crearemos la tabla', 'Quieres que la cree'],
    ],
    [
        'user' => 'que datos debe llevar?',
        'expect_contains_any' => ['Se guardara esta informacion', 'Quieres que la cree', 'Voy a crear la tabla'],
    ],
    [
        'user' => 'si',
        'expect_contains' => 'Tabla creada:',
        'capture_entity_from_reply' => true,
    ],
];

$results = [];
$createdEntity = '';
$allOk = true;

$send = static function (array $payload) use ($php, $turnScript): array {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'No se pudo serializar payload.'];
    }
    $b64 = base64_encode($json);
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($turnScript) . ' ' . escapeshellarg($b64);
    $raw = shell_exec($cmd);
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'error' => 'Sin respuesta del endpoint.'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Respuesta no JSON: ' . trim($raw)];
    }
    return ['ok' => true, 'json' => $decoded, 'raw' => $raw];
};

foreach ($conversation as $step) {
    $payload = [
        'message' => $step['user'],
        'mode' => $mode,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'session_id' => $sessionId,
        'channel' => 'local',
        'project_id' => 'default',
    ];
    $resp = $send($payload);
    if (!$resp['ok']) {
        $allOk = false;
        $results[] = [
            'user' => $step['user'],
            'assistant' => '[ERROR] ' . ($resp['error'] ?? 'error'),
            'ok' => false,
        ];
        continue;
    }

    $json = $resp['json'];
    $status = (string) ($json['status'] ?? '');
    $reply = (string) ($json['data']['reply'] ?? $json['message'] ?? '');
    $ok = $status !== 'error';

    if (!empty($step['expect_contains'])) {
        $ok = $ok && stripos($reply, (string) $step['expect_contains']) !== false;
    }
    if (!empty($step['expect_contains_any']) && is_array($step['expect_contains_any'])) {
        $hit = false;
        foreach ($step['expect_contains_any'] as $needle) {
            if (stripos($reply, (string) $needle) !== false) {
                $hit = true;
                break;
            }
        }
        $ok = $ok && $hit;
    }

    if (!empty($step['capture_entity_from_reply'])) {
        if (preg_match('/Tabla creada:\s*([a-zA-Z0-9_]+)/u', $reply, $m) === 1) {
            $createdEntity = (string) $m[1];
        }
    }

    if (!$ok) {
        $allOk = false;
    }

    $results[] = [
        'user' => $step['user'],
        'assistant' => $reply,
        'status' => $status,
        'ok' => $ok,
    ];
}

if ($createdEntity !== '') {
    $payload = [
        'message' => 'crear formulario ' . $createdEntity,
        'mode' => $mode,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'session_id' => $sessionId,
        'channel' => 'local',
        'project_id' => 'default',
    ];
    $resp = $send($payload);
    if (!$resp['ok']) {
        $allOk = false;
        $results[] = [
            'user' => $payload['message'],
            'assistant' => '[ERROR] ' . ($resp['error'] ?? 'error'),
            'ok' => false,
        ];
    } else {
        $json = $resp['json'];
        $reply = (string) ($json['data']['reply'] ?? $json['message'] ?? '');
        $status = (string) ($json['status'] ?? '');
        $ok = $status !== 'error' && stripos($reply, 'Formulario creado') !== false;
        if (!$ok) {
            $allOk = false;
        }
        $results[] = [
            'user' => $payload['message'],
            'assistant' => $reply,
            'status' => $status,
            'ok' => $ok,
        ];
    }
}

$statusPayload = [
    'message' => 'dame estado del proyecto',
    'mode' => $mode,
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'session_id' => $sessionId,
    'channel' => 'local',
    'project_id' => 'default',
];
$statusResp = $send($statusPayload);
if (!$statusResp['ok']) {
    $allOk = false;
    $results[] = [
        'user' => $statusPayload['message'],
        'assistant' => '[ERROR] ' . ($statusResp['error'] ?? 'error'),
        'ok' => false,
    ];
} else {
    $json = $statusResp['json'];
    $reply = (string) ($json['data']['reply'] ?? $json['message'] ?? '');
    $status = (string) ($json['status'] ?? '');
    $ok = $status !== 'error' && stripos($reply, 'Estado del proyecto') !== false;
    if (!$ok) {
        $allOk = false;
    }
    $results[] = [
        'user' => $statusPayload['message'],
        'assistant' => $reply,
        'status' => $status,
        'ok' => $ok,
    ];
}

$report = [
    'summary' => [
        'ok' => $allOk,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'session_id' => $sessionId,
        'requested_entity' => $entityName,
        'created_entity' => $createdEntity,
        'total_turns' => count($results),
        'passed_turns' => count(array_filter($results, static fn(array $r): bool => !empty($r['ok']))),
        'failed_turns' => count(array_filter($results, static fn(array $r): bool => empty($r['ok']))),
        'ran_at' => date('Y-m-d H:i:s'),
    ],
    'conversation' => $results,
];

$outPath = __DIR__ . '/chat_api_single_demo_result.json';
file_put_contents(
    $outPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

// Limpieza opcional de artefactos creados durante la demo (para no contaminar pruebas siguientes).
$keepArtifacts = (string) getenv('KEEP_DEMO_ARTIFACTS') === '1';
if (!$keepArtifacts && $createdEntity !== '') {
    $entityPath = dirname(__DIR__, 2) . '/project/contracts/entities/' . $createdEntity . '.entity.json';
    $formPath = dirname(__DIR__, 2) . '/project/contracts/forms/' . $createdEntity . '.form.json';
    $viewPath = dirname(__DIR__, 2) . '/project/views/' . $createdEntity . '.php';
    foreach ([$entityPath, $formPath, $viewPath] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    try {
        require_once dirname(__DIR__) . '/app/autoload.php';
        $pdo = \App\Core\Database::connection();
        foreach ([$createdEntity, $createdEntity . 's'] as $table) {
            $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: '';
            if ($safe === '') {
                continue;
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . $safe . '`');
        }
    } catch (\Throwable $e) {
        // ignore cleanup DB errors in demo script
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
