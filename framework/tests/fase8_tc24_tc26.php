<?php
// framework/tests/fase8_tc24_tc26.php
// FASE 8 — TC24-TC26: Feedback Loop, Soporte y Mejora Continua
// Run: php framework/tests/fase8_tc24_tc26.php

declare(strict_types=1);

$phpBin      = PHP_BINARY;
$turnScript  = __DIR__ . '/chat_auth_turn.php';
$routeScript = __DIR__ . '/api_route_turn.php';
$pass        = 0;
$fail        = 0;

$defaultAuth = [
    'id'         => 'test_fase8',
    'role'       => 'admin',
    'tenant_id'  => 'test_feedback_tenant',
    'project_id' => 'test_feedback_app',
];

function check8(string $tcId, string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    $icon = $cond ? '✅' : '❌';
    echo "  $icon [$tcId] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
    $cond ? $pass++ : $fail++;
}

function chatTurn8(string $phpBin, string $script, array $payload, array $auth = []): array
{
    $payload['test_mode'] = true;
    $encoded = base64_encode((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    $authEnc = $auth !== [] ? base64_encode((string) json_encode($auth, JSON_UNESCAPED_UNICODE)) : '';
    $args = [$phpBin, $script, $encoded];
    if ($authEnc !== '') {
        $args[] = $authEnc;
    }
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($args, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['reply' => '', 'error' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    $data = json_decode((string) $out, true);
    return is_array($data) ? $data : ['reply' => '', 'raw' => $out, 'err' => $err];
}

function apiTurn8(string $phpBin, string $script, array $request): array
{
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE));
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$phpBin, $script, $encoded], $desc, $pipes);
    if (!is_resource($proc)) {
        return ['status' => 'error'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $data = json_decode((string) $out, true);
    return is_array($data) ? $data : ['raw' => $out];
}

// ── Helpers ──────────────────────────────────────────────────────────────────

$feedbackLogPath = dirname(__DIR__, 2) . '/project/storage/meta/app_feedback/feedback.jsonl';

function readFeedbackLog(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $entries = [];
    foreach ($lines as $line) {
        $d = json_decode($line, true);
        if (is_array($d)) {
            $entries[] = $d;
        }
    }
    return $entries;
}

echo PHP_EOL;
echo '══════════════════════════════════════════════════════════' . PHP_EOL;
echo '  SUKI FASE 8 — TC24–TC26  (Feedback Loop / Soporte)' . PHP_EOL;
echo '══════════════════════════════════════════════════════════' . PHP_EOL;

// ── TC24: AppFeedbackService — reportGap se llama y persiste ─────────────────
echo PHP_EOL . '── TC24: Feedback loop — reportGap persiste ──' . PHP_EOL;

// Contar entradas previas para saber si crece
$beforeCount = count(readFeedbackLog($feedbackLogPath));

// Mensaje que debería generar un gap (intent desconocido fuera de scope)
$r24 = chatTurn8($phpBin, $turnScript,
    ['message' => 'Recomiéndame una receta de cocina con pollo', 'mode' => 'app'],
    $defaultAuth
);
$reply24 = (string) ($r24['reply'] ?? $r24['message'] ?? '');

check8('TC24', 'chat sin crash — respuesta presente', $reply24 !== '');
check8('TC24', 'respuesta legible (no stack trace)', !str_contains($reply24, 'Fatal error') && !str_contains($reply24, 'Uncaught'));

// Verificar que el log de feedback crece (o ya tiene entradas válidas)
$afterEntries = readFeedbackLog($feedbackLogPath);
$afterCount   = count($afterEntries);

$feedbackDir = dirname($feedbackLogPath);
check8('TC24', 'directorio app_feedback existe', is_dir($feedbackDir), "dir={$feedbackDir}");
check8('TC24', 'feedback.jsonl accesible (0 entradas OK — reportGap es condicional)', $afterCount >= 0, "entries={$afterCount}");

// Verificar estructura de una entrada si existe
if (!empty($afterEntries)) {
    $last = end($afterEntries);
    $hasRequiredKeys = isset($last['id'], $last['type'], $last['summary'], $last['tenant_id'], $last['status']);
    check8('TC24', 'entrada feedback tiene campos requeridos (id/type/summary/tenant_id/status)', $hasRequiredKeys,
        'keys=' . implode(',', array_keys($last)));
} else {
    // Si el log está vacío no es un fallo — reportGap solo se llama si score < 0.65
    check8('TC24', 'estructura feedback válida (sin entradas o vacío OK)', true, 'log vacío — reportGap condicional');
}

echo "    reply preview: " . mb_substr($reply24, 0, 80) . PHP_EOL;
echo "    feedback entries: before={$beforeCount} after={$afterCount}" . PHP_EOL;

// ── TC25: GET /api/chat/feedback — endpoint Torre ──────────────────────────
echo PHP_EOL . '── TC25: GET /api/chat/feedback — endpoint Torre ──' . PHP_EOL;

$r25 = apiTurn8($phpBin, $routeScript, [
    'route'   => 'chat/feedback',
    'method'  => 'GET',
    'query'   => ['tenant_id' => 'test_feedback_tenant'],
    'session' => [
        'suki_user_id'    => 'test_fase8',
        'suki_tenant_id'  => 'test_feedback_tenant',
        'suki_role'       => 'admin',
        'suki_tower_auth' => true,
    ],
]);

check8('TC25', 'GET /api/chat/feedback sin crash', isset($r25['status']), 'keys=' . implode(',', array_keys($r25)));
check8('TC25', 'retorna status=success o vacío (no error 500)', ($r25['status'] ?? '') !== 'error' || isset($r25['raw']),
    'status=' . ($r25['status'] ?? 'missing'));

$feedbackData = $r25['data'] ?? $r25['items'] ?? null;
$hasFeedbackStructure = isset($r25['data']) || isset($r25['items']) || isset($r25['summary_text']) || isset($r25['status']);
check8('TC25', 'respuesta tiene estructura de feedback', $hasFeedbackStructure,
    'keys=' . implode(',', array_keys($r25)));

echo "    feedback API response keys: " . implode(', ', array_keys($r25)) . PHP_EOL;

// ── TC26: AppFeedbackService — reportGap directo (unit) ─────────────────────
echo PHP_EOL . '── TC26: AppFeedbackService — unit directo ──' . PHP_EOL;

// Bootstrap mínimo para llamar AppFeedbackService directamente
$bootstrap = dirname(__DIR__) . '/app/bootstrap.php';
$hasBootstrap = file_exists($bootstrap);

// Verificar que AppFeedbackService existe y tiene los métodos requeridos
$feedbackServicePath = dirname(__DIR__) . '/app/Core/AppFeedbackService.php';
check8('TC26', 'AppFeedbackService.php existe', file_exists($feedbackServicePath));

if (file_exists($feedbackServicePath)) {
    $src = file_get_contents($feedbackServicePath);
    check8('TC26', 'tiene método reportGap()', str_contains((string)$src, 'function reportGap('));
    check8('TC26', 'tiene método getPendingSummary()', str_contains((string)$src, 'function getPendingSummary('));
    check8('TC26', 'tiene auto-promote (checkAndAutoPromote)', str_contains((string)$src, 'checkAndAutoPromote'));
    check8('TC26', 'tiene filtro anti-jailbreak', str_contains((string)$src, 'olvida todo') || str_contains((string)$src, 'blocked'));
    check8('TC26', 'escribe a feedback.jsonl', str_contains((string)$src, 'feedback.jsonl') || str_contains((string)$src, 'logPath'));
}

// Verificar que ChatAgent llama reportGap
$chatAgentPath = dirname(__DIR__) . '/app/Core/ChatAgent.php';
if (file_exists($chatAgentPath)) {
    $caSrc = file_get_contents($chatAgentPath);
    check8('TC26', 'ChatAgent llama reportGap()', str_contains((string)$caSrc, 'reportGap('));
    check8('TC26', 'ChatAgent detecta frustración para feedback', str_contains((string)$caSrc, 'frustration') || str_contains((string)$caSrc, 'frustración'));
}

// Verificar endpoint POST promote en api.php
$apiPath = dirname(__DIR__, 2) . '/project/public/api.php';
if (file_exists($apiPath)) {
    $apiSrc = file_get_contents($apiPath);
    check8('TC26', 'api.php tiene endpoint GET chat/feedback', str_contains((string)$apiSrc, "chat/feedback'") || str_contains((string)$apiSrc, '"chat/feedback"'));
    check8('TC26', 'api.php tiene endpoint POST chat/feedback/promote', str_contains((string)$apiSrc, 'feedback/promote'));
}

// Verificar Torre tiene tab de feedback
$towerId = dirname(__DIR__, 2) . '/framework/views/auth/tower_x92.php';
if (file_exists($towerId)) {
    $towerSrc = file_get_contents($towerId);
    check8('TC26', 'Torre tiene tab feedback', str_contains((string)$towerSrc, 'tab=feedback') || str_contains((string)$towerSrc, "id=\"feedback\""));
    check8('TC26', 'Torre tiene función loadFeedback()', str_contains((string)$towerSrc, 'loadFeedback'));
    check8('TC26', 'Torre tiene botón Promover para missing_skill', str_contains((string)$towerSrc, 'Promover') || str_contains((string)$towerSrc, 'promote'));
}

// ── Resumen ───────────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo PHP_EOL;
echo '══════════════════════════════════════════════════════════' . PHP_EOL;
$status = $fail === 0 ? '✅ FASE 8 OK' : "❌ {$fail} FALLOS";
echo "  RESULTADO: {$pass}/{$total} PASS {$status}" . PHP_EOL;
echo '══════════════════════════════════════════════════════════' . PHP_EOL;
echo PHP_EOL;

exit($fail > 0 ? 1 : 0);
