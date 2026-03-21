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

$basePayload = [
    'message' => 'me ayudas a crear una app',
    'mode' => 'builder',
    'tenant_id' => 'default',
    'user_id' => 'test_mode_user_' . time(),
    'session_id' => 'test_mode_session_' . time(),
    'channel' => 'local',
    'project_id' => 'default',
];

if ($failures === []) {
    $normal = $send($basePayload);
    if (!($normal['ok'] ?? false)) {
        $failures[] = (string) ($normal['error'] ?? 'El endpoint sin test_mode fallo.');
    } else {
        $normalJson = is_array($normal['json'] ?? null) ? (array) $normal['json'] : [];
        $normalData = is_array($normalJson['data'] ?? null) ? (array) $normalJson['data'] : [];
        if (array_key_exists('test_info', $normalData)) {
            $failures[] = 'El endpoint no debe exponer test_info sin test_mode.';
        }
    }

    $testMode = $send($basePayload + ['test_mode' => true]);
    if (!($testMode['ok'] ?? false)) {
        $failures[] = (string) ($testMode['error'] ?? 'El endpoint con test_mode fallo.');
    } else {
        $testJson = is_array($testMode['json'] ?? null) ? (array) $testMode['json'] : [];
        $testData = is_array($testJson['data'] ?? null) ? (array) $testJson['data'] : [];
        $info = is_array($testData['test_info'] ?? null) ? (array) $testData['test_info'] : [];
        if ($info === []) {
            $failures[] = 'test_mode debe exponer data.test_info.';
        } else {
            $requiredKeys = [
                'route_path',
                'classification',
                'action',
                'resolved_locally',
                'llm_provider',
                'llm_error',
                'provider_statuses',
                'embedding_model',
                'embeddings_used',
                'vector_store',
                'collection',
                'hits',
                'evidence_count',
                'llm_model',
                'semantic_fallback_used',
                'agents_used',
            ];
            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $info)) {
                    $failures[] = 'Falta key en test_info: ' . $key;
                }
            }
            if ((string) ($info['route_path'] ?? '') === '') {
                $failures[] = 'test_info.route_path debe venir no vacio.';
            }
            if ((string) ($info['classification'] ?? '') === '') {
                $failures[] = 'test_info.classification debe venir no vacio.';
            }
            if ((string) ($info['action'] ?? '') === '') {
                $failures[] = 'test_info.action debe venir no vacio.';
            }
            if (($info['resolved_locally'] ?? null) !== true) {
                $failures[] = 'El caso builder local debe marcar resolved_locally=true en test_info.';
            }
            if (!is_array($info['agents_used'] ?? null)) {
                $failures[] = 'test_info.agents_used debe ser arreglo.';
            }
        }
    }
}

$projectRoot = dirname(__DIR__, 2) . '/project';
$builderHtml = @file_get_contents($projectRoot . '/public/chat_builder.html');
$appHtml = @file_get_contents($projectRoot . '/public/chat_app.html');
foreach ([
    'chat_builder.html' => $builderHtml,
    'chat_app.html' => $appHtml,
] as $file => $contents) {
    if (!is_string($contents) || $contents === '') {
        $failures[] = 'No se pudo leer ' . $file;
        continue;
    }
    if (strpos($contents, 'id="testMode"') === false) {
        $failures[] = $file . ' debe incluir toggle testMode.';
    }
    if (strpos($contents, 'test_info') === false) {
        $failures[] = $file . ' debe leer test_info del backend.';
    }
    if (strpos($contents, 'id="testInspector"') === false) {
        $failures[] = $file . ' debe incluir el inspector tecnico separado.';
    }
    if (strpos($contents, 'updateTestInspector') === false) {
        $failures[] = $file . ' debe actualizar el inspector tecnico en modo test.';
    }
    if (strpos($contents, 'card.appendChild(renderTestInfo') !== false) {
        $failures[] = $file . ' no debe mezclar test info dentro del flujo conversacional.';
    }
    foreach (['provider_statuses', 'llm_error', 'semantic_fallback_used'] as $needle) {
        if (strpos($contents, $needle) === false) {
            $failures[] = $file . ' debe mostrar ' . $needle . ' en el inspector tecnico.';
        }
    }
}

$ok = $failures === [];
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
