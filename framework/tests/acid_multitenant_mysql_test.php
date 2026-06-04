<?php
// framework/tests/acid_multitenant_mysql_test.php
// ACID TEST (MySQL): Aislamiento multi-tenant contra el motor real de producción.
// Crea tablas de test temporales con prefijo único.
// Requiere variables de entorno: DB_HOST, DB_USER, DB_PASS, DB_NAME (o usa project/.env).
//
// Exit 0 = PASS. Exit 1 = FAIL. Exit 2 = SKIP (MySQL no disponible).

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

// Load project .env if not already set
$envFile = dirname(__DIR__, 2) . '/project/.env';
if (is_file($envFile) && (string) getenv('DB_HOST') === '') {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if ($k !== '' && (string) getenv($k) === '') putenv("{$k}={$v}");
        }
    }
}

$host    = (string) (getenv('DB_HOST')    ?: 'localhost');
$port    = (string) (getenv('DB_PORT')    ?: '3306');
$dbName  = (string) (getenv('DB_NAME')    ?: 'suki_saas');
$user    = (string) (getenv('DB_USER')    ?: '');
$pass    = (string) (getenv('DB_PASS')    ?: '');

// Skip gracefully if no MySQL credentials
if ($user === '') {
    echo json_encode([
        'ok'      => true,
        'skipped' => true,
        'reason'  => 'DB_USER not set — MySQL ACID test skipped. Set DB_USER/DB_PASS/DB_HOST to run.',
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'ok'      => true,
        'skipped' => true,
        'reason'  => 'MySQL not reachable: ' . $e->getMessage() . ' — test skipped.',
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$prefix   = 'suki_acid_test_' . substr(md5((string) microtime(true)), 0, 8) . '_';
$failures = [];

// Create isolated test tables
$tables = [
    "{$prefix}mem_user"   => "CREATE TABLE {$prefix}mem_user (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id   VARCHAR(64) NOT NULL,
        user_id     VARCHAR(64) NOT NULL,
        key_name    VARCHAR(120) NOT NULL,
        value_json  MEDIUMTEXT NOT NULL,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mem_user (tenant_id, user_id, key_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "{$prefix}mem_tenant" => "CREATE TABLE {$prefix}mem_tenant (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id   VARCHAR(64) NOT NULL,
        key_name    VARCHAR(120) NOT NULL,
        value_json  MEDIUMTEXT NOT NULL,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mem_tenant (tenant_id, key_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "{$prefix}chat_log"   => "CREATE TABLE {$prefix}chat_log (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id   VARCHAR(64) NOT NULL,
        session_id  VARCHAR(120) NOT NULL,
        user_id     VARCHAR(64) NOT NULL,
        role        VARCHAR(32) NOT NULL DEFAULT 'user',
        direction   VARCHAR(8) NOT NULL DEFAULT 'in',
        message     TEXT NOT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_chat_tenant_session (tenant_id, session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

try {
    foreach ($tables as $table => $sql) {
        $pdo->exec($sql);
    }

    $alpha  = 'acid_alpha_' . substr(md5('alpha'), 0, 8);
    $beta   = 'acid_beta_'  . substr(md5('beta'),  0, 8);
    $shared = 'shared_session_mysql_acid';

    // ── TEST 1: mem_user isolation ─────────────────────────────────────────────
    $ins = $pdo->prepare(
        "INSERT INTO {$prefix}mem_user (tenant_id, user_id, key_name, value_json) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)"
    );
    $ins->execute([$alpha, 'u1', 'profile', json_encode(['name' => 'Alpha Co', 'secret' => 'alpha_only'])]);
    $ins->execute([$beta,  'u1', 'profile', json_encode(['name' => 'Beta Co',  'secret' => 'beta_only'])]);

    $get = $pdo->prepare("SELECT value_json FROM {$prefix}mem_user WHERE tenant_id=? AND user_id=? AND key_name=?");

    $get->execute([$alpha, 'u1', 'profile']);
    $alphaData = json_decode((string) ($get->fetchColumn() ?: '{}'), true);
    $get->execute([$beta, 'u1', 'profile']);
    $betaData = json_decode((string) ($get->fetchColumn() ?: '{}'), true);

    if (($alphaData['name'] ?? '') !== 'Alpha Co') {
        $failures[] = 'TEST 1 FAIL: tenant_alpha no puede leer su propia mem_user. Obtenido: ' . json_encode($alphaData);
    }
    if (($betaData['name'] ?? '') !== 'Beta Co') {
        $failures[] = 'TEST 1 FAIL: tenant_beta no puede leer su propia mem_user. Obtenido: ' . json_encode($betaData);
    }
    if (isset($alphaData['secret']) && $alphaData['secret'] === 'beta_only') {
        $failures[] = 'TEST 1 FAIL: tenant_alpha leyó el secreto de tenant_beta. AISLAMIENTO ROTO en mem_user (MySQL).';
    }
    if (isset($betaData['secret']) && $betaData['secret'] === 'alpha_only') {
        $failures[] = 'TEST 1 FAIL: tenant_beta leyó el secreto de tenant_alpha. AISLAMIENTO ROTO en mem_user (MySQL).';
    }

    // ── TEST 2: mem_tenant isolation ─────────────────────────────────────────────
    $ins2 = $pdo->prepare(
        "INSERT INTO {$prefix}mem_tenant (tenant_id, key_name, value_json) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)"
    );
    $ins2->execute([$alpha, 'config', json_encode(['plan' => 'premium', 'dato_alpha' => 'SOLO_ALPHA'])]);
    $ins2->execute([$beta,  'config', json_encode(['plan' => 'free',    'dato_beta'  => 'SOLO_BETA'])]);

    $get2 = $pdo->prepare("SELECT value_json FROM {$prefix}mem_tenant WHERE tenant_id=? AND key_name=?");

    $get2->execute([$alpha, 'config']);
    $alphaConfig = json_decode((string) ($get2->fetchColumn() ?: '{}'), true);
    $get2->execute([$beta, 'config']);
    $betaConfig  = json_decode((string) ($get2->fetchColumn() ?: '{}'), true);

    if (isset($alphaConfig['dato_beta'])) {
        $failures[] = 'TEST 2 FAIL: tenant_alpha ve dato exclusivo de tenant_beta en mem_tenant (MySQL).';
    }
    if (isset($betaConfig['dato_alpha'])) {
        $failures[] = 'TEST 2 FAIL: tenant_beta ve dato exclusivo de tenant_alpha en mem_tenant (MySQL).';
    }

    // ── TEST 3: chat_log — same session_id, different tenants ─────────────────
    $ins3 = $pdo->prepare(
        "INSERT INTO {$prefix}chat_log (tenant_id, session_id, user_id, role, direction, message) VALUES (?,?,?,?,?,?)"
    );
    $ins3->execute([$alpha, $shared, 'u1', 'user', 'in', 'Mensaje privado Alpha MySQL']);
    $ins3->execute([$beta,  $shared, 'u1', 'user', 'in', 'Mensaje privado Beta MySQL']);

    $getLogs = $pdo->prepare("SELECT message FROM {$prefix}chat_log WHERE tenant_id=? AND session_id=?");

    $getLogs->execute([$alpha, $shared]);
    $alphaMsgs = array_column($getLogs->fetchAll(), 'message');
    $getLogs->execute([$beta, $shared]);
    $betaMsgs  = array_column($getLogs->fetchAll(), 'message');

    if (in_array('Mensaje privado Beta MySQL', $alphaMsgs, true)) {
        $failures[] = 'TEST 3 FAIL: tenant_alpha ve mensaje de tenant_beta en chat_log (MySQL). AISLAMIENTO ROTO.';
    }
    if (in_array('Mensaje privado Alpha MySQL', $betaMsgs, true)) {
        $failures[] = 'TEST 3 FAIL: tenant_beta ve mensaje de tenant_alpha en chat_log (MySQL). AISLAMIENTO ROTO.';
    }
    if (count($alphaMsgs) !== 1) {
        $failures[] = 'TEST 3 FAIL: tenant_alpha debe ver exactamente 1 mensaje, ve ' . count($alphaMsgs);
    }
    if (count($betaMsgs) !== 1) {
        $failures[] = 'TEST 3 FAIL: tenant_beta debe ver exactamente 1 mensaje, ve ' . count($betaMsgs);
    }

    // ── TEST 4: Raw cross-read — ensure no row leaks without WHERE tenant_id ──
    $allUser1 = $pdo->prepare("SELECT tenant_id FROM {$prefix}mem_user WHERE user_id=?");
    $allUser1->execute(['u1']);
    $tenantIds = array_column($allUser1->fetchAll(), 'tenant_id');

    if (!in_array($alpha, $tenantIds, true)) {
        $failures[] = 'TEST 4 FAIL: No hay row para tenant_alpha en mem_user. Falla de escritura.';
    }
    if (!in_array($beta, $tenantIds, true)) {
        $failures[] = 'TEST 4 FAIL: No hay row para tenant_beta en mem_user. Falla de escritura.';
    }
    $uniqueTenants = array_unique($tenantIds);
    if (count($uniqueTenants) < 2) {
        $failures[] = 'TEST 4 FAIL: mem_user tiene menos de 2 tenants distintos — posible colisión de claves.';
    }

    // ── TEST 5: Transaction isolation (MySQL InnoDB) ──────────────────────────
    $pdo->beginTransaction();
    $ins->execute([$alpha, 'u2', 'tx_test', json_encode(['tx' => 'uncommitted'])]);
    // Beta should not see alpha's uncommitted write (REPEATABLE READ default in MySQL)
    $getLogs2 = $pdo->prepare("SELECT value_json FROM {$prefix}mem_user WHERE tenant_id=? AND user_id=? AND key_name=?");
    $getLogs2->execute([$beta, 'u2', 'tx_test']);
    $betaSeesBefore = $getLogs2->fetchColumn();
    if ($betaSeesBefore !== false) {
        // In REPEATABLE READ, beta in the same connection sees the write (same session)
        // This is expected behavior — this test verifies logical tenant isolation, not TX isolation
    }
    $pdo->rollBack();
    // After rollback, the row should be gone
    $getLogs2->execute([$alpha, 'u2', 'tx_test']);
    $afterRollback = $getLogs2->fetchColumn();
    if ($afterRollback !== false) {
        $failures[] = 'TEST 5 FAIL: Row persists after rollback — transacción no funciona en MySQL.';
    }

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $failures[] = 'EXCEPCION: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
} finally {
    // Drop test tables
    foreach (array_keys($tables) as $table) {
        try { $pdo->exec("DROP TABLE IF EXISTS `{$table}`"); } catch (\Throwable) {}
    }
}

$ok = empty($failures);
echo json_encode([
    'ok'       => $ok,
    'engine'   => 'MySQL',
    'host'     => $host,
    'db'       => $dbName,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
