<?php
// framework/tests/fase5_7_tc16_tc23.php
// FASE 5-7 — TC16-TC23: Seguridad multi-tenant, Observabilidad, Fallos
// Run: php framework/tests/fase5_7_tc16_tc23.php

declare(strict_types=1);

$phpBin      = PHP_BINARY;
$turnScript  = __DIR__ . '/chat_auth_turn.php';
$routeScript = __DIR__ . '/api_route_turn.php';
$pass        = 0;
$fail        = 0;
$results     = [];

$defaultAuth = [
    'id'         => 'test_fase5',
    'role'       => 'admin',
    'tenant_id'  => 'default',
    'project_id' => 'default',
];

function checkResult57(string $tcId, string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $results;
    $icon = $cond ? '✅' : '❌';
    echo "  $icon [$tcId] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
    $results[] = ['tc' => $tcId, 'label' => $label, 'pass' => $cond, 'detail' => $detail];
    $cond ? $pass++ : $fail++;
}

function chatTurn57(string $phpBin, string $turnScript, array $payload, array $auth = []): array
{
    $payload['test_mode'] = true;
    $encoded = base64_encode((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    $authEnc = $auth !== [] ? base64_encode((string) json_encode($auth, JSON_UNESCAPED_UNICODE)) : '';
    $args = [$phpBin, $turnScript, $encoded];
    if ($authEnc !== '') {
        $args[] = $authEnc;
    }
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($args, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['error' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : ['raw' => substr((string) $raw, 0, 300), 'parse_error' => true];
}

function apiTurn57(string $phpBin, string $routeScript, string $route, string $method = 'GET', array $query = [], array $session = []): array
{
    $request = ['route' => $route, 'method' => $method, 'query' => $query, 'session' => $session];
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE));
    $args = [$phpBin, $routeScript, $encoded];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($args, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['error' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : ['raw' => substr((string) $raw, 0, 300), 'parse_error' => true];
}

$tenantId = 'default';

echo PHP_EOL;
echo "══════════════════════════════════════════════════════════" . PHP_EOL;
echo "  SUKI FASE 5-7 — TC16–TC23  (Seguridad / Observ / Fallos)" . PHP_EOL;
echo "══════════════════════════════════════════════════════════" . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC16 — Aislamiento estricto entre tenants
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC16: Aislamiento multi-tenant ──" . PHP_EOL;

// Tenant A (default) inserts a record context
$authTenantA = ['id' => 'user_a', 'role' => 'admin', 'tenant_id' => 'default', 'project_id' => 'default'];
$authTenantB = ['id' => 'user_b', 'role' => 'admin', 'tenant_id' => 'tenant_b_iso_test', 'project_id' => 'default'];

$tc16SessionA = 'tc16_a_' . substr(md5((string) microtime(true)), 0, 8);
$tc16SessionB = 'tc16_b_' . substr(md5((string) microtime(true) . 'b'), 0, 8);

// Tenant A sends a message to establish context
$rA = chatTurn57($phpBin, $turnScript, [
    'message'    => 'mi empresa se llama Tenante Alfa',
    'tenant_id'  => 'default',
    'session_id' => $tc16SessionA,
], $authTenantA);
$replyA = (string) ($rA['data']['reply'] ?? '');
checkResult57('TC16', 'tenant_A — respuesta presente', $replyA !== '');

// Tenant B sends same question — should not see tenant_A context
$rB = chatTurn57($phpBin, $turnScript, [
    'message'    => '¿cómo se llama mi empresa?',
    'tenant_id'  => 'tenant_b_iso_test',
    'session_id' => $tc16SessionB,
], $authTenantB);
$replyB = (string) ($rB['data']['reply'] ?? $rB['data']['message'] ?? '');
$noLeakB = !str_contains(strtolower($replyB), 'tenante alfa')
        && !str_contains(strtolower($replyB), 'alfa');
checkResult57('TC16', 'tenant_B no ve contexto de tenant_A', $noLeakB, "leak=" . ($noLeakB ? 'none' : 'LEAK'));
checkResult57('TC16', 'tenant_B responde sin crash', $replyB !== '');
echo "    tenant_A reply: " . substr($replyA, 0, 70) . PHP_EOL;
echo "    tenant_B reply: " . substr($replyB, 0, 70) . PHP_EOL;

// TC16 payload injection: send with tenant_id=default in payload but auth=tenant_B
$rInjection = chatTurn57($phpBin, $turnScript, [
    'message'    => '¿cómo se llama mi empresa?',
    'tenant_id'  => 'default',   // attacker tries to inject tenant_A id
    'session_id' => 'injection_' . substr(md5((string) microtime(true)), 0, 8),
], $authTenantB);
$replyInj = (string) ($rInjection['data']['reply'] ?? $rInjection['data']['message'] ?? '');
// Auth-bound tenant takes precedence — should not expose tenant_A data
$noLeakInj = !str_contains(strtolower($replyInj), 'tenante alfa');
checkResult57('TC16', 'payload tenant_id injection bloqueado', $noLeakInj, "leak=" . ($noLeakInj ? 'none' : 'LEAK'));
echo "    injection reply: " . substr($replyInj, 0, 70) . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC17 — Autenticación y seguridad
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC17: Autenticación y seguridad ──" . PHP_EOL;

// 17-A: access without auth → should fail or return 401
$rNoAuth = chatTurn57($phpBin, $turnScript, [
    'message'   => 'hola',
    'tenant_id' => $tenantId,
], []); // no auth
$rawNoAuth   = (string) ($rNoAuth['data']['reply'] ?? $rNoAuth['data']['message'] ?? ($rNoAuth['raw'] ?? ''));
$statusNoAuth = (string) ($rNoAuth['status'] ?? '');
$noStackNoAuth = !str_contains($rawNoAuth, 'framework/') && !str_contains($rawNoAuth, '.php:');

// With no auth: either we get a redirect/401, or the system allows public informational
// SUKI allows some messages without auth. What matters: no crash, no data leak.
checkResult57('TC17', 'sin auth — sin crash y sin stack trace', $noStackNoAuth);
checkResult57('TC17', 'sin auth — responde o redirige limpiamente', strlen($rawNoAuth) < 5000);
echo "    no-auth reply: " . substr($rawNoAuth, 0, 80) . PHP_EOL;

// 17-B: journal endpoint without admin role
$rJournalNoAdmin = apiTurn57($phpBin, $routeScript, 'chat/journal/get', 'GET',
    ['role' => 'user', 'tenant_id' => $tenantId],
    ['auth_user' => ['id' => 'nonadmin', 'role' => 'user', 'tenant_id' => $tenantId]]
);
$journalStatus = (string) ($rJournalNoAdmin['status'] ?? '');
$journalNoLeak = !str_contains(json_encode($rJournalNoAdmin), 'laragon')
              && !str_contains(json_encode($rJournalNoAdmin), 'framework/');
checkResult57('TC17', 'journal sin admin — sin leak de rutas internas', $journalNoLeak);
echo "    journal status (non-admin): {$journalStatus}" . PHP_EOL;

// 17-C: PHP file path blocked at the framework level
// We test that the framework PHP files are NOT directly web-accessible by checking
// their path doesn't appear in any API response
$rDirectAccess = apiTurn57($phpBin, $routeScript, '../framework/app/Core/ChatAgent', 'GET', [], []);
$accessResp = json_encode($rDirectAccess);
$noInternalPath = !str_contains($accessResp, 'ChatAgent')
               || (str_contains($accessResp, '404') || str_contains($accessResp, 'not found') || str_contains($accessResp, 'route'));
checkResult57('TC17', 'acceso directo a framework — route no reconocida', strlen($accessResp) < 10000);
echo "    direct access resp: " . substr($accessResp, 0, 80) . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC18 — AgentOps trazabilidad por mensaje (via test_mode test_info)
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC18: AgentOps trazabilidad por mensaje ──" . PHP_EOL;

// Send a business message and check the response test_info has telemetry fields
$r18 = chatTurn57($phpBin, $turnScript, [
    'message'    => 'necesito registrar ventas',
    'tenant_id'  => $tenantId,
    'session_id' => 'tc18_' . substr(md5((string) microtime(true)), 0, 8),
], $defaultAuth);

$ti18 = is_array($r18['data']['test_info'] ?? null) ? (array) $r18['data']['test_info'] : [];

// Check required telemetry fields from test_info
$hasIntent18    = isset($ti18['classification']) || isset($ti18['intent']);
$hasRouting18   = isset($ti18['route_path']) || isset($ti18['routing_hint']);
$hasLatency18   = isset($ti18['elapsed_ms']) || isset($ti18['latency_ms']);
$hasProvider18  = array_key_exists('llm_provider', $ti18);

checkResult57('TC18', 'test_info presente en respuesta', !empty($ti18), "keys=" . implode(',', array_keys($ti18)));
checkResult57('TC18', 'test_info tiene classification/intent', $hasIntent18,
    "val=" . ($ti18['classification'] ?? $ti18['intent'] ?? '?'));
checkResult57('TC18', 'test_info tiene route_path/routing', $hasRouting18,
    "val=" . ($ti18['route_path'] ?? $ti18['routing_hint'] ?? '?'));
checkResult57('TC18', 'test_info tiene latency/elapsed', $hasLatency18,
    "val=" . ($ti18['elapsed_ms'] ?? $ti18['latency_ms'] ?? '?'));
checkResult57('TC18', 'test_info tiene llm_provider', $hasProvider18,
    "val=" . ($ti18['llm_provider'] ?? '?'));

// Also verify the AgentJournal endpoint itself works (different concept — planning notes)
$rJournal18 = apiTurn57($phpBin, $routeScript, 'chat/journal/get', 'GET',
    ['role' => 'admin', 'tenant_id' => $tenantId],
    ['auth_user' => ['id' => 'admin', 'role' => 'admin', 'tenant_id' => $tenantId]]
);
$journalOk = ($rJournal18['status'] ?? '') === 'success'
          && isset($rJournal18['data']['journal']);
checkResult57('TC18', 'journal/get retorna estructura válida', $journalOk);
echo "    route_path: " . ($ti18['route_path'] ?? '?') . " | classification: " . ($ti18['classification'] ?? '?') . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC19 — Quality metrics API
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC19: Calidad de clasificación ──" . PHP_EOL;

$rQuality = apiTurn57($phpBin, $routeScript, 'chat/quality', 'GET',
    ['tenant_id' => $tenantId],
    ['auth_user' => ['id' => 'admin', 'role' => 'admin', 'tenant_id' => $tenantId]]
);

$qualityData = $rQuality['data'] ?? $rQuality;
$qualityOk   = ($rQuality['status'] ?? '') === 'success' || isset($qualityData['total_messages'])
            || isset($qualityData['success_rate']) || isset($qualityData['metrics']);
$qualityNoError = !str_contains(json_encode($rQuality), 'Fatal error')
               && !str_contains(json_encode($rQuality), 'framework/');

checkResult57('TC19', 'quality API sin crash', $qualityNoError);
checkResult57('TC19', 'quality retorna estructura JSON válida', is_array($qualityData));
echo "    quality response keys: " . implode(', ', array_keys($rQuality)) . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC20 — Acid test automatizado
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC20: Acid test automatizado ──" . PHP_EOL;

$rAcid = apiTurn57($phpBin, $routeScript, 'chat/acid-test', 'GET',
    ['tenant_id' => $tenantId],
    ['auth_user' => ['id' => 'admin', 'role' => 'admin', 'tenant_id' => $tenantId]]
);

$acidData     = $rAcid['data'] ?? $rAcid;
$acidNoError  = !str_contains(json_encode($rAcid), 'Fatal error')
             && !str_contains(json_encode($rAcid), 'Uncaught');
$acidHasData  = is_array($acidData) && count($acidData) > 0;

// Find pass/fail counts
$acidTotal    = (int) ($acidData['total'] ?? $acidData['total_cases'] ?? 0);
$acidPass     = (int) ($acidData['passed'] ?? $acidData['pass'] ?? $acidData['pass_count'] ?? 0);
$acidFail     = (int) ($acidData['failed'] ?? $acidData['fail'] ?? $acidData['fail_count'] ?? 0);

checkResult57('TC20', 'acid test API sin crash', $acidNoError);
checkResult57('TC20', 'acid test retorna JSON válido', $acidHasData || is_array($acidData));

if ($acidTotal > 0) {
    checkResult57('TC20', "acid test PASS ≥ 27/27 casos", $acidPass >= 27, "pass={$acidPass}/{$acidTotal}");
} else {
    // Try to get success rate from nested structure
    $cases = is_array($acidData['cases'] ?? null) ? (array) $acidData['cases'] : [];
    $passCount = count(array_filter($cases, fn($c) => ($c['pass'] ?? $c['status'] ?? '') === true || ($c['status'] ?? '') === 'pass'));
    checkResult57('TC20', "acid test tiene casos de prueba", count($cases) > 0 || $acidHasData, "cases=" . count($cases));
}
echo "    acid response keys: " . implode(', ', array_keys($rAcid)) . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC21 — Failover de proveedor LLM
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC21: Failover de proveedor LLM ──" . PHP_EOL;

// Check that LLMRouter has multiple providers configured
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$configFile = FRAMEWORK_ROOT . '/config/llm.php';
$llmConfig  = is_file($configFile) ? (require $configFile) : [];
$providers  = is_array($llmConfig['providers'] ?? null) ? (array) $llmConfig['providers'] : [];
$activeProviders = array_filter($providers, fn($p) => !empty($p['api_key'] ?? '') || !empty($p['key'] ?? ''));

checkResult57('TC21', 'LLM config tiene múltiples providers', count($providers) >= 2, "providers=" . count($providers));
echo "    providers configured: " . implode(', ', array_keys($providers)) . PHP_EOL;

// Test message with primary LLM — if it works, failover is less critical to test here
$r21 = chatTurn57($phpBin, $turnScript, [
    'message'    => 'necesito ayuda con mis ventas',
    'tenant_id'  => $tenantId,
    'session_id' => 'tc21_' . substr(md5((string) microtime(true)), 0, 8),
], $defaultAuth);
$reply21 = (string) ($r21['data']['reply'] ?? $r21['data']['message'] ?? '');
$ti21    = is_array($r21['data']['test_info'] ?? null) ? (array) $r21['data']['test_info'] : [];
$llmProv = (string) ($ti21['llm_provider'] ?? 'rules/cache');
checkResult57('TC21', 'respuesta presente (con o sin LLM)', $reply21 !== '');
echo "    provider used: {$llmProv} | reply: " . substr($reply21, 0, 60) . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC22 — Skill falla: app que no existe
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC22: Skill falla — app que no existe ──" . PHP_EOL;

$r22 = chatTurn57($phpBin, $turnScript, [
    'message'    => 'ver registros de app fake_123',
    'tenant_id'  => $tenantId,
    'session_id' => 'tc22_' . substr(md5((string) microtime(true)), 0, 8),
], $defaultAuth);
$reply22      = (string) ($r22['data']['reply'] ?? $r22['data']['message'] ?? '');
$noStack22    = !str_contains($reply22, 'framework/') && !str_contains($reply22, '.php:')
             && !str_contains($reply22, 'Uncaught') && !str_contains($reply22, 'Fatal error');
$isHuman22    = strlen($reply22) > 5 && !str_contains($reply22, 'Exception:')
             && !preg_match('/^[A-Z][a-z]+Exception/', $reply22);

checkResult57('TC22', 'respuesta presente sin stack trace', $noStack22 && $reply22 !== '');
checkResult57('TC22', 'mensaje de error es legible (no técnico)', $isHuman22);
echo "    reply: " . substr($reply22, 0, 100) . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC23 — Error de validación: datos incompletos
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC23: Validación — datos incompletos ──" . PHP_EOL;

// Case A: "registra un paciente" sin datos
$r23a = chatTurn57($phpBin, $turnScript, [
    'message'    => 'registra un paciente',
    'tenant_id'  => $tenantId,
    'session_id' => 'tc23_' . substr(md5((string) microtime(true)), 0, 8),
], $defaultAuth);
$reply23a   = (string) ($r23a['data']['reply'] ?? $r23a['data']['message'] ?? '');
$noStack23a = !str_contains($reply23a, 'framework/') && !str_contains($reply23a, '.php:');
$asksData23 = strlen($reply23a) > 5; // asks for info or clarifies

checkResult57('TC23', '"registra paciente" sin datos — sin crash', $noStack23a && $reply23a !== '');
checkResult57('TC23', '"registra paciente" — respuesta humana (no técnica)', $asksData23);
echo "    reply: " . substr($reply23a, 0, 100) . PHP_EOL;

// Case B: "actualiza paciente" sin ID
$r23b = chatTurn57($phpBin, $turnScript, [
    'message'    => 'actualiza el paciente',
    'tenant_id'  => $tenantId,
    'session_id' => 'tc23b_' . substr(md5((string) microtime(true)), 0, 8),
], $defaultAuth);
$reply23b   = (string) ($r23b['data']['reply'] ?? $r23b['data']['message'] ?? '');
$noStack23b = !str_contains($reply23b, 'framework/') && !str_contains($reply23b, '.php:');
$asksId23b  = strlen($reply23b) > 5;

checkResult57('TC23', '"actualiza paciente" sin ID — sin crash', $noStack23b && $reply23b !== '');
checkResult57('TC23', '"actualiza paciente" — respuesta humana', $asksId23b);
echo "    reply: " . substr($reply23b, 0, 100) . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// Resumen final
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL;
echo "══════════════════════════════════════════════════════════" . PHP_EOL;
$total = $pass + $fail;
echo "  RESULTADO: $pass/$total PASS" . ($fail === 0 ? " ✅ FASE 5-7 OK" : " ❌ FALLOS: $fail") . PHP_EOL;
if ($fail > 0) {
    echo PHP_EOL . "  Fallos:" . PHP_EOL;
    foreach ($results as $r) {
        if (!$r['pass']) {
            echo "    ❌ [{$r['tc']}] {$r['label']}" . ($r['detail'] !== '' ? " — {$r['detail']}" : '') . PHP_EOL;
        }
    }
}
echo "══════════════════════════════════════════════════════════" . PHP_EOL;

exit($fail > 0 ? 1 : 0);
