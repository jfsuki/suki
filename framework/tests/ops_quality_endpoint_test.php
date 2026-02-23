<?php
// framework/tests/ops_quality_endpoint_test.php

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
$dbPath = $tmpDir . '/ops_quality_endpoint.sqlite';
if (is_file($dbPath)) {
    unlink($dbPath);
}

putenv('PROJECT_REGISTRY_DB_PATH=' . $dbPath);

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE IF NOT EXISTS projects (
    id TEXT PRIMARY KEY,
    name TEXT,
    status TEXT,
    tenant_mode TEXT,
    storage_model TEXT,
    owner_user_id TEXT,
    created_at TEXT,
    updated_at TEXT
)');
$db->exec('CREATE TABLE IF NOT EXISTS ops_intent_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    mode TEXT NOT NULL,
    intent TEXT NOT NULL,
    action TEXT NOT NULL,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL
)');
$db->exec('CREATE TABLE IF NOT EXISTS ops_command_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    mode TEXT NOT NULL,
    command_name TEXT NOT NULL,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    blocked INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
)');
$db->exec('CREATE TABLE IF NOT EXISTS ops_guardrail_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    mode TEXT NOT NULL,
    guardrail TEXT NOT NULL,
    reason TEXT NOT NULL,
    created_at TEXT NOT NULL
)');
$db->exec('CREATE TABLE IF NOT EXISTS ops_token_usage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    provider TEXT NOT NULL,
    prompt_tokens INTEGER NOT NULL DEFAULT 0,
    completion_tokens INTEGER NOT NULL DEFAULT 0,
    total_tokens INTEGER NOT NULL DEFAULT 0,
    estimated_cost_usd REAL NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
)');
$stmt = $db->prepare('INSERT INTO projects (id, name, status, tenant_mode, storage_model, owner_user_id, created_at, updated_at)
    VALUES (:id, :name, :status, :tenant_mode, :storage_model, :owner, :created, :updated)');
$now = date('Y-m-d H:i:s');
$stmt->execute([
    ':id' => 'ops_api_proj',
    ':name' => 'Ops API Project',
    ':status' => 'draft',
    ':tenant_mode' => 'shared',
    ':storage_model' => 'legacy',
    ':owner' => 'tester',
    ':created' => $now,
    ':updated' => $now,
]);
$stmt = $db->prepare('INSERT INTO ops_intent_metrics (tenant_id, project_id, mode, intent, action, latency_ms, status, created_at)
    VALUES (:tenant_id, :project_id, :mode, :intent, :action, :latency_ms, :status, :created_at)');
$stmt->execute([
    ':tenant_id' => 'default',
    ':project_id' => 'ops_api_proj',
    ':mode' => 'builder',
    ':intent' => 'APP_CREATE',
    ':action' => 'ask_user',
    ':latency_ms' => 65,
    ':status' => 'success',
    ':created_at' => $now,
]);

$_GET['route'] = 'chat/ops-quality';
$_GET['tenant_id'] = 'default';
$_GET['project_id'] = 'ops_api_proj';
$_GET['days'] = '7';
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require dirname(__DIR__, 2) . '/project/public/api.php';
$raw = (string) ob_get_clean();
$json = json_decode($raw, true);

$summary = is_array($json['data']['ops_summary'] ?? null) ? $json['data']['ops_summary'] : [];
$intentCount = (int) (($summary['intent_metrics']['count'] ?? 0));

$failures = [];
if (($json['status'] ?? '') !== 'success') {
    $failures[] = 'api status should be success';
}
if ($intentCount < 1) {
    $failures[] = 'ops summary intent count should be >= 1';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'status' => $json['status'] ?? null,
    'intent_count' => $intentCount,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
