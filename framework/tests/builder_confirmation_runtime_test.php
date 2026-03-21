<?php

declare(strict_types=1);

$failures = [];

$php = PHP_BINARY ?: 'php';
$turnScript = __DIR__ . '/chat_api_turn.php';
if (!is_file($turnScript)) {
    $failures[] = 'No existe chat_api_turn.php.';
}

$send = static function (array $payload) use ($php, $turnScript): array {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return ['ok' => false, 'error' => 'No se pudo serializar payload.'];
    }

    $encoded = base64_encode($json);
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($turnScript) . ' ' . escapeshellarg($encoded);
    $raw = shell_exec($cmd);
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'error' => 'Sin respuesta del endpoint real.'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Respuesta no JSON.'];
    }

    return ['ok' => true, 'json' => $decoded];
};

$sessionId = 'builder_confirmation_runtime_' . time();
$userId = 'builder_confirmation_user_' . time();
$basePayload = [
    'mode' => 'builder',
    'tenant_id' => 'default',
    'user_id' => $userId,
    'session_id' => $sessionId,
    'channel' => 'local',
    'project_id' => 'default',
    'test_mode' => true,
];

$results = [];
foreach (['si eso ferreteria', 'aja si'] as $message) {
    $response = $send($basePayload + ['message' => $message]);
    if (!($response['ok'] ?? false)) {
        $failures[] = (string) ($response['error'] ?? 'El endpoint builder fallo.');
        continue;
    }

    $json = is_array($response['json'] ?? null) ? (array) $response['json'] : [];
    $data = is_array($json['data'] ?? null) ? (array) $json['data'] : [];
    $info = is_array($data['test_info'] ?? null) ? (array) $data['test_info'] : [];
    $reply = trim((string) ($data['reply'] ?? ''));
    $record = [
        'message' => $message,
        'reply' => $reply,
        'action' => (string) ($info['action'] ?? ''),
        'classification' => (string) ($info['classification'] ?? ''),
        'route_reason' => (string) ($info['route_reason'] ?? ''),
        'route_path' => (string) ($info['route_path'] ?? ''),
    ];
    $results[] = $record;

    if ($record['action'] !== 'send_to_llm') {
        $failures[] = 'La confirmacion corta debe seguir la ruta LLM y no quedar localmente bloqueada para "' . $message . '".';
    }
    if ($record['classification'] !== 'llm') {
        $failures[] = 'La confirmacion corta debe quedar en clasificacion llm para "' . $message . '".';
    }
    if ($record['route_path'] !== 'cache>rules>skills>rag>llm') {
        $failures[] = 'route_path inesperado para "' . $message . '".';
    }
    if ($record['route_reason'] === 'loop_guard_blocked_before_llm') {
        $failures[] = 'No debe dispararse loop_guard_blocked_before_llm para "' . $message . '".';
    }
    if (str_contains($reply, 'Detuve esta ruta porque excede el tiempo maximo permitido para este modo.')) {
        $failures[] = 'La respuesta de budget guard no debe aparecer para "' . $message . '".';
    }
    if (str_contains($reply, 'IA no disponible. Usa comandos simples.')) {
        $failures[] = 'La respuesta no debe exponer fallback interno LLM para "' . $message . '".';
    }
}

$builderHtml = @file_get_contents(dirname(__DIR__, 2) . '/project/public/chat_builder.html');
if (!is_string($builderHtml) || $builderHtml === '') {
    $failures[] = 'No se pudo leer chat_builder.html.';
} else {
    foreach ([
        'class="panel conversation-panel"',
        'class="conversation-shell"',
        'class="composer"',
        'position: sticky;',
        'overflow-y: auto;',
    ] as $needle) {
        if (strpos($builderHtml, $needle) === false) {
            $failures[] = 'chat_builder.html debe contener: ' . $needle;
        }
    }
}

$ok = $failures === [];
echo json_encode([
    'ok' => $ok,
    'results' => $results,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
