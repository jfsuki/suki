<?php
// framework/tests/router_contract_enforcement_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ContractRegistry;
use App\Core\IntentRouter;

$workspaceRoot = dirname(__DIR__, 2);
$sourceDir = $workspaceRoot . '/docs/contracts';
$tmpDir = __DIR__ . '/tmp/router_contract_enforcement_' . time();

if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "No se pudo crear tmp dir: {$tmpDir}\n");
    exit(1);
}

$routerPolicy = readJson($sourceDir . '/router_policy.json');
$routeOrder = [];
foreach ((array) ($routerPolicy['route_order'] ?? []) as $step) {
    $normalized = strtolower(trim((string) $step));
    if ($normalized !== '' && $normalized !== 'llm') {
        $routeOrder[] = $normalized;
    }
}
if (empty($routeOrder)) {
    $routeOrder = ['cache', 'rules', 'skills', 'rag'];
}
$routerPolicy['route_order'] = $routeOrder;
writeJson($tmpDir . '/router_policy.json', $routerPolicy);
copy($sourceDir . '/action_catalog.json', $tmpDir . '/action_catalog.json');
copy($sourceDir . '/agentops_metrics_contract.json', $tmpDir . '/agentops_metrics_contract.json');
copy($sourceDir . '/skills_catalog.json', $tmpDir . '/skills_catalog.json');

$registry = new ContractRegistry($tmpDir);
$payload = [
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'Como configuro Qdrant para tenant y app?'],
        ],
        'user_message' => 'Como configuro Qdrant para tenant y app?',
    ],
];

$failures = [];
$previousMode = getenv('ENFORCEMENT_MODE');

putenv('ENFORCEMENT_MODE=warn');
$warnRouter = new IntentRouter($registry);
$warnResult = $warnRouter->route($payload);
$warnTelemetry = $warnResult->telemetry();
if (!$warnResult->isLocalResponse()) {
    $failures[] = 'warn mode debe degradar send_to_llm a respuesta local cuando hay violation de policy';
}
if ((string) ($warnTelemetry['gate_decision'] ?? '') !== 'warn') {
    $failures[] = 'warn mode debe marcar gate_decision=warn';
}
if ((string) ($warnTelemetry['route_path'] ?? '') === '') {
    $failures[] = 'warn mode debe reportar route_path';
}
if ((string) ($warnTelemetry['evidence_gate_status'] ?? '') === '') {
    $failures[] = 'warn mode debe reportar evidence_gate_status.';
}
if (stripos($warnResult->reply(), 'evidencia minima') === false) {
    $failures[] = 'warn mode debe explicar falta de evidencia para no invocar LLM.';
}

putenv('ENFORCEMENT_MODE=strict');
$strictRouter = new IntentRouter($registry);
$strictResult = $strictRouter->route($payload);
$strictTelemetry = $strictResult->telemetry();
if (!$strictResult->isLocalResponse()) {
    $failures[] = 'strict mode debe bloquear send_to_llm fuera de contrato';
}
if ((string) ($strictTelemetry['gate_decision'] ?? '') !== 'blocked') {
    $failures[] = 'strict mode debe marcar gate_decision=blocked';
}
if (stripos($strictResult->reply(), 'Bloqueado por contrato') === false) {
    $failures[] = 'strict mode debe responder bloqueo explicito';
}

if ($previousMode === false) {
    putenv('ENFORCEMENT_MODE');
} else {
    putenv('ENFORCEMENT_MODE=' . $previousMode);
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'tmp_dir' => $tmpDir,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);

function readJson(string $path): array
{
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('JSON vacio: ' . $path);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON invalido: ' . $path);
    }
    return $decoded;
}

function writeJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('No se pudo escribir JSON: ' . $path);
    }
}
