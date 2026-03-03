<?php
// framework/tests/operational_queue_schema_guard_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\OperationalQueueStore;

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
$dbPath = $tmpDir . '/operational_queue_schema_guard_' . time() . '.sqlite';
if (is_file($dbPath)) {
    unlink($dbPath);
}

$failures = [];
$previousAllow = getenv('ALLOW_RUNTIME_SCHEMA');
$previousAppEnv = getenv('APP_ENV');

// Case 1: production mode must block runtime schema changes even when ALLOW_RUNTIME_SCHEMA=1.
putenv('APP_ENV=production');
putenv('ALLOW_RUNTIME_SCHEMA=1');
try {
    $pdoProd = new PDO('sqlite:' . $dbPath);
    $pdoProd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    new OperationalQueueStore($pdoProd);
    $failures[] = 'prod mode must block runtime schema bootstrap';
} catch (RuntimeException $e) {
    if (!str_contains((string) $e->getMessage(), 'runtime schema changes are disabled')) {
        $failures[] = 'prod mode error message should explain runtime schema policy';
    }
}

// Case 2: local+allow should permit schema bootstrap (dev convenience only).
putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
try {
    $pdoLocal = new PDO('sqlite:' . $dbPath);
    $pdoLocal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    new OperationalQueueStore($pdoLocal);
    if (!sqliteTableExists($pdoLocal, 'event_dedupe') || !sqliteTableExists($pdoLocal, 'jobs_queue')) {
        $failures[] = 'local+allow should create required queue tables';
    }
    if (!sqliteIndexExists($pdoLocal, 'event_dedupe', 'idx_event_dedupe_tenant_first_seen')) {
        $failures[] = 'local+allow should create queue indexes';
    }
} catch (\Throwable $e) {
    $failures[] = 'local+allow should not fail: ' . $e->getMessage();
}

// Case 3: local without allow must fail on empty DB (no silent schema changes).
$dbPathNoAllow = $tmpDir . '/operational_queue_schema_guard_noallow_' . time() . '.sqlite';
if (is_file($dbPathNoAllow)) {
    unlink($dbPathNoAllow);
}
putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=0');
try {
    $pdoNoAllow = new PDO('sqlite:' . $dbPathNoAllow);
    $pdoNoAllow->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    new OperationalQueueStore($pdoNoAllow);
    $failures[] = 'local without allow must block runtime schema bootstrap';
} catch (RuntimeException $e) {
    if (!str_contains((string) $e->getMessage(), 'missing_tables=')) {
        $failures[] = 'local without allow should report missing tables/indexes';
    }
}

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

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'db_path' => $dbPath,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);

function sqliteTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
    $stmt->execute([':name' => $table]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '';
}

function sqliteIndexExists(PDO $db, string $table, string $index): bool
{
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
    if ($safeTable === '') {
        return false;
    }
    $stmt = $db->query("PRAGMA index_list({$safeTable})");
    if (!$stmt) {
        return false;
    }
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ((string) ($row['name'] ?? '') === $index) {
            return true;
        }
    }
    return false;
}
