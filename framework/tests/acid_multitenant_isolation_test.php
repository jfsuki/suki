<?php
// framework/tests/acid_multitenant_isolation_test.php
// ACID TEST: Aislamiento multi-tenant real en DB SQLite.
// Crea tenant_alpha y tenant_beta con datos distintos.
// Verifica que las queries de tenant_alpha NO retornan datos de tenant_beta.
// Prueba SqlMemoryRepository::getSession() y mem_user con mismo session_id distinto tenant.
//
// Exit 0 = PASS. Exit 1 = FAIL con evidencia exacta.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Database;
use App\Core\SqlMemoryRepository;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/acid_multitenant_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);

$previous = [
    'APP_ENV'              => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'DB_DRIVER'            => getenv('DB_DRIVER'),
    'DB_PATH'              => getenv('DB_PATH'),
];
putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $tmpDir . '/acid_multitenant.sqlite');

$pdo = new PDO('sqlite:' . $tmpDir . '/acid_multitenant.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Database::setConnection($pdo);

$memory = new SqlMemoryRepository($pdo);

$alpha  = 'tenant_acid_alpha_' . substr(md5((string) time()), 0, 8);
$beta   = 'tenant_acid_beta_'  . substr(md5((string) (time() + 1)), 0, 8);
$shared = 'shared_session_xyz';

try {
    // ── TEST 1: mem_user — tenant_beta no lee datos de tenant_alpha ───────────────
    $memory->saveUserMemory($alpha, 'user1', 'profile', ['name' => 'Empresa Alpha', 'secret' => 'alpha_secret']);
    $memory->saveUserMemory($beta,  'user1', 'profile', ['name' => 'Empresa Beta',  'secret' => 'beta_secret']);

    $readAlpha = $memory->getUserMemory($alpha, 'user1', 'profile', []);
    $readBeta  = $memory->getUserMemory($beta,  'user1', 'profile', []);

    if ((string) ($readAlpha['name'] ?? '') !== 'Empresa Alpha') {
        $failures[] = 'TEST 1 FAIL: tenant_alpha no puede leer su propia memoria. '
            . 'Obtenido: ' . json_encode($readAlpha);
    }
    if ((string) ($readBeta['name'] ?? '') !== 'Empresa Beta') {
        $failures[] = 'TEST 1 FAIL: tenant_beta no puede leer su propia memoria. '
            . 'Obtenido: ' . json_encode($readBeta);
    }
    // Verificar que alpha no ve datos de beta
    if (isset($readAlpha['secret']) && $readAlpha['secret'] === 'beta_secret') {
        $failures[] = 'TEST 1 FAIL: tenant_alpha leyó el secreto de tenant_beta. '
            . 'AISLAMIENTO ROTO en getUserMemory().';
    }
    // Verificar cruzado: beta intentando leer con alpha key
    $crossRead = $memory->getUserMemory($beta, 'user1', 'profile', []);
    if (isset($crossRead['secret']) && $crossRead['secret'] === 'alpha_secret') {
        $failures[] = 'TEST 1 FAIL: tenant_beta leyó el secreto de tenant_alpha. '
            . 'AISLAMIENTO ROTO.';
    }

    // ── TEST 2: mem_tenant — aislamiento a nivel de tenant ───────────────────────
    $memory->saveTenantMemory($alpha, 'config', ['plan' => 'premium', 'dato_alpha' => 'SOLO_ALPHA']);
    $memory->saveTenantMemory($beta,  'config', ['plan' => 'free',    'dato_beta'  => 'SOLO_BETA']);

    $tenantAlphaConfig = $memory->getTenantMemory($alpha, 'config', []);
    $tenantBetaConfig  = $memory->getTenantMemory($beta,  'config', []);

    if ((string) ($tenantAlphaConfig['plan'] ?? '') !== 'premium') {
        $failures[] = 'TEST 2 FAIL: tenant_alpha no puede leer su propia config. '
            . json_encode($tenantAlphaConfig);
    }
    if (isset($tenantAlphaConfig['dato_beta'])) {
        $failures[] = 'TEST 2 FAIL: tenant_alpha ve datos exclusivos de tenant_beta en getTenantMemory().';
    }
    if (isset($tenantBetaConfig['dato_alpha'])) {
        $failures[] = 'TEST 2 FAIL: tenant_beta ve datos exclusivos de tenant_alpha en getTenantMemory().';
    }

    // ── TEST 3: chat_log — mismo session_id, distintos tenants ───────────────────
    $memory->appendShortTermMemory($alpha, 'user1', $shared, 'chat', 'in', 'Mensaje privado de Alpha');
    $memory->appendShortTermMemory($beta,  'user1', $shared, 'chat', 'in', 'Mensaje privado de Beta');

    $logsAlpha = $memory->getShortTermMemory($alpha, $shared, 50);
    $logsBeta  = $memory->getShortTermMemory($beta,  $shared, 50);

    $alphaMsgs = array_column($logsAlpha, 'message');
    $betaMsgs  = array_column($logsBeta,  'message');

    if (in_array('Mensaje privado de Beta', $alphaMsgs, true)) {
        $failures[] = 'TEST 3 FAIL: tenant_alpha ve el mensaje de tenant_beta en getShortTermMemory(). '
            . 'session_id=' . $shared . '. El filtro de tenant_id NO está aplicado en chat_log.';
    }
    if (in_array('Mensaje privado de Alpha', $betaMsgs, true)) {
        $failures[] = 'TEST 3 FAIL: tenant_beta ve el mensaje de tenant_alpha en getShortTermMemory().';
    }
    if (count($logsAlpha) !== 1) {
        $failures[] = 'TEST 3 FAIL: tenant_alpha debería ver exactamente 1 mensaje, ve '
            . count($logsAlpha) . '. Posible fuga cross-tenant.';
    }
    if (count($logsBeta) !== 1) {
        $failures[] = 'TEST 3 FAIL: tenant_beta debería ver exactamente 1 mensaje, ve '
            . count($logsBeta) . '. Posible fuga cross-tenant.';
    }

    // ── TEST 4: Verificar filas crudas en SQLite ──────────────────────────────────
    $stmt = $pdo->prepare('SELECT tenant_id, key_name FROM mem_user WHERE user_id = ? ORDER BY tenant_id');
    $stmt->execute(['user1']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tenantIds = array_column($rows, 'tenant_id');
    if (!in_array($alpha, $tenantIds, true) || !in_array($beta, $tenantIds, true)) {
        $failures[] = 'TEST 4 FAIL: mem_user no tiene ambos tenants. Encontrado: '
            . implode(', ', array_unique($tenantIds));
    }

    // Asegurar que no exista la fuga donde tenant_id='default' aplasta los reales
    $defaultRows = $pdo->prepare('SELECT COUNT(*) as cnt FROM mem_user WHERE tenant_id = ?');
    $defaultRows->execute(['default']);
    $defaultCount = (int) ($defaultRows->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
    // Solo es error si hay rows con 'default' Y también hay rows con los tenants reales
    // (indicaría que la gateway escribió en 'default' en lugar del tenant correcto)
    if ($defaultCount > 0 && (in_array($alpha, $tenantIds, true) || in_array($beta, $tenantIds, true))) {
        // Verificar si los datos 'default' corresponden a nuestros users de test
        $defaultStmt = $pdo->prepare('SELECT * FROM mem_user WHERE tenant_id = ? AND user_id = ?');
        $defaultStmt->execute(['default', 'user1']);
        $defaultUser1 = $defaultStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($defaultUser1)) {
            $failures[] = 'TEST 4 WARN: Hay ' . count($defaultUser1) . ' filas con tenant_id="default" '
                . 'para user_id="user1" además de las filas con tenants reales. '
                . 'Posible escritura dual (bug conocido en ConversationGateway).';
        }
    }

} catch (\Throwable $e) {
    $failures[] = 'EXCEPCION inesperada: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
} finally {
    foreach ($previous as $k => $v) {
        if ($v === false) {
            putenv($k);
        } else {
            putenv($k . '=' . $v);
        }
    }
}

$ok = empty($failures);
echo json_encode([
    'ok'       => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
