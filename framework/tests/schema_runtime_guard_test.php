<?php
// framework/tests/schema_runtime_guard_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditLogger;
use App\Core\IntegrationStore;
use App\Core\ProjectRegistry;
use App\Core\SecurityStateRepository;
use App\Core\SqlMemoryRepository;
use App\Core\SqlMetricsRepository;

$tmpDir = __DIR__ . '/tmp/schema_runtime_guard_' . time() . random_int(1000, 9999);
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}

$failures = [];
$previousAllow = getenv('ALLOW_RUNTIME_SCHEMA');
$previousAppEnv = getenv('APP_ENV');
$previousRegistryPath = getenv('PROJECT_REGISTRY_DB_PATH');
$previousSecurityPath = getenv('SECURITY_STATE_DB_PATH');

try {
    // Case 1: production mode blocks runtime schema mutations even with ALLOW=1.
    putenv('APP_ENV=production');
    putenv('ALLOW_RUNTIME_SCHEMA=1');
    expectBlocked(
        fn() => new ProjectRegistry($tmpDir . '/prod_registry.sqlite'),
        'ProjectRegistry',
        $failures
    );
    expectBlocked(
        fn() => new SqlMetricsRepository(null, $tmpDir . '/prod_metrics.sqlite'),
        'SqlMetricsRepository',
        $failures
    );
    expectBlocked(
        fn() => new SecurityStateRepository($tmpDir . '/prod_security.sqlite'),
        'SecurityStateRepository',
        $failures
    );
    expectBlocked(
        fn() => new SqlMemoryRepository(new PDO('sqlite::memory:')),
        'SqlMemoryRepository',
        $failures
    );
    expectBlocked(
        fn() => new IntegrationStore(new PDO('sqlite::memory:')),
        'IntegrationStore',
        $failures
    );
    expectBlocked(
        function (): void {
            $audit = new AuditLogger(new PDO('sqlite::memory:'));
            $audit->log('schema_guard_probe', 'audit_log', '1', ['ok' => true]);
        },
        'AuditLogger',
        $failures
    );

    // Case 2: local + ALLOW=1 permits runtime schema bootstrap (dev convenience).
    putenv('APP_ENV=local');
    putenv('ALLOW_RUNTIME_SCHEMA=1');
    $registryPath = $tmpDir . '/local_registry.sqlite';
    $registry = new ProjectRegistry($registryPath);
    $registry->ensureProject('schema_guard_local', 'Schema Guard Local');
    if (!sqliteTableExists(new PDO('sqlite:' . $registryPath), 'projects')) {
        $failures[] = 'local+allow should create projects table.';
    }

    $metricsPath = $tmpDir . '/local_metrics.sqlite';
    $metrics = new SqlMetricsRepository(null, $metricsPath);
    $metrics->saveIntentMetric([
        'tenant_id' => 'default',
        'project_id' => 'schema_guard',
        'session_id' => 'sess_local',
        'mode' => 'app',
        'intent' => 'PING',
        'action' => 'respond_local',
        'latency_ms' => 10,
        'status' => 'success',
    ]);
    $summary = $metrics->summary('default', 'schema_guard', 1);
    if ((int) ($summary['intent_metrics']['count'] ?? 0) < 1) {
        $failures[] = 'local+allow should persist metrics.';
    }

    $securityPath = $tmpDir . '/local_security.sqlite';
    $security = new SecurityStateRepository($securityPath);
    $rate = $security->consumeRateLimit('schema_guard::chat/message', 1, 60);
    if (!(bool) ($rate['ok'] ?? false)) {
        $failures[] = 'local+allow should create security schema.';
    }

    $memory = new SqlMemoryRepository(new PDO('sqlite::memory:'));
    $memory->saveTenantMemory('default', 'schema_guard', ['ok' => true]);
    $tenantMemory = $memory->getTenantMemory('default', 'schema_guard', []);
    if (($tenantMemory['ok'] ?? false) !== true) {
        $failures[] = 'local+allow should persist SQL memory.';
    }

    $integrationDb = new PDO('sqlite::memory:');
    $integrationStore = new IntegrationStore($integrationDb);
    $integrationStore->logWebhook('schema_guard', 'ping', 'ext-1', ['ok' => true]);
    if (!sqliteTableExists($integrationDb, 'integration_webhooks')) {
        $failures[] = 'local+allow should create integration tables.';
    }

    $auditDb = new PDO('sqlite::memory:');
    $audit = new AuditLogger($auditDb);
    $audit->log('schema_guard_probe', 'audit_log', '1', ['ok' => true]);
    if (!sqliteTableExists($auditDb, 'audit_log')) {
        $failures[] = 'local+allow should create audit_log table.';
    }

    // Case 3: local + ALLOW=0 must block silent schema bootstrap.
    putenv('APP_ENV=local');
    putenv('ALLOW_RUNTIME_SCHEMA=0');
    expectBlocked(
        fn() => new ProjectRegistry($tmpDir . '/noallow_registry.sqlite'),
        'ProjectRegistry no-allow',
        $failures
    );
    expectBlocked(
        fn() => new SqlMetricsRepository(null, $tmpDir . '/noallow_metrics.sqlite'),
        'SqlMetricsRepository no-allow',
        $failures
    );
    expectBlocked(
        fn() => new SecurityStateRepository($tmpDir . '/noallow_security.sqlite'),
        'SecurityStateRepository no-allow',
        $failures
    );
    expectBlocked(
        fn() => new SqlMemoryRepository(new PDO('sqlite::memory:')),
        'SqlMemoryRepository no-allow',
        $failures
    );
    expectBlocked(
        fn() => new IntegrationStore(new PDO('sqlite::memory:')),
        'IntegrationStore no-allow',
        $failures
    );
    expectBlocked(
        function (): void {
            $audit = new AuditLogger(new PDO('sqlite::memory:'));
            $audit->log('schema_guard_probe', 'audit_log', '1', ['ok' => true]);
        },
        'AuditLogger no-allow',
        $failures
    );
} finally {
    if ($previousAllow === false) {
        putenv('ALLOW_RUNTIME_SCHEMA');
    } else {
        putenv('ALLOW_RUNTIME_SCHEMA=' . $previousAllow);
    }
    if ($previousAppEnv === false) {
        putenv('APP_ENV');
    } else {
        putenv('APP_ENV=' . $previousAppEnv);
    }
    if ($previousRegistryPath === false) {
        putenv('PROJECT_REGISTRY_DB_PATH');
    } else {
        putenv('PROJECT_REGISTRY_DB_PATH=' . $previousRegistryPath);
    }
    if ($previousSecurityPath === false) {
        putenv('SECURITY_STATE_DB_PATH');
    } else {
        putenv('SECURITY_STATE_DB_PATH=' . $previousSecurityPath);
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'tmp_dir' => $tmpDir,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);

/**
 * @param array<int, string> $failures
 */
function expectBlocked(callable $action, string $label, array &$failures): void
{
    try {
        $action();
        $failures[] = $label . ' should block runtime schema mutation.';
    } catch (RuntimeException $e) {
        if (!str_contains((string) $e->getMessage(), 'runtime schema changes are disabled')) {
            $failures[] = $label . ' should return explicit runtime schema policy error.';
        }
    } catch (Throwable $e) {
        $failures[] = $label . ' raised unexpected exception type: ' . $e->getMessage();
    }
}

function sqliteTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
    $stmt->execute([':name' => $table]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '';
}
