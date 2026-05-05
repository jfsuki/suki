<?php
// framework/tests/acid_agent_memory_test.php
// ACID TEST: ConversationMemory persiste entre turnos, tenant_id correcto en mem_user,
// y aislamiento real: tenant_beta NO lee la memoria de tenant_alpha.
//
// Usa SQLite temporal — NO mocks. Instancia ConversationGateway real.
// Exit 0 = PASS. Exit 1 = FAIL con evidencia exacta.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;
use App\Core\SqlMemoryRepository;

$failures = [];
$tmpDir  = FRAMEWORK_ROOT . '/tests/tmp/acid_agent_memory_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);

$previous = [
    'APP_ENV'            => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'DB_DRIVER'          => getenv('DB_DRIVER'),
    'DB_PATH'            => getenv('DB_PATH'),
];
putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $tmpDir . '/acid_memory.sqlite');

// Crear PDO aislado para este test
$pdo = new PDO('sqlite:' . $tmpDir . '/acid_memory.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\App\Core\Database::setConnection($pdo);

$memory = new SqlMemoryRepository($pdo);

try {
    // ── TEST 1: Contexto persiste entre turnos ─────────────────────────────────
    $tenantId  = 'acid_tenant_alpha_' . time();
    $userId    = 'acid_user_alpha';
    $projectId = 'acid_proj';

    $gateway = new ConversationGateway(PROJECT_ROOT, $memory);

    // Turno 1: onboarding — establece perfil de negocio
    $gateway->handle($tenantId, $userId, 'quiero crear una app', 'builder', $projectId);
    // Turno 2: da el tipo de negocio
    $gateway->handle($tenantId, $userId, 'tengo una ferreteria', 'builder', $projectId);
    // Turno 3: segundo paso del onboarding
    $r3 = $gateway->handle($tenantId, $userId, 'mixto', 'builder', $projectId);

    // Verificar que el estado del turno 3 tiene contexto de los dos anteriores
    $stateKey = 'state::' . $projectId . '::builder';
    $state3 = $memory->getUserMemory($tenantId, $userId, $stateKey, []);

    if (empty($state3)) {
        $failures[] = 'TEST 1 FAIL: mem_user no tiene estado para tenant=' . $tenantId
            . ' user=' . $userId . ' key=' . $stateKey
            . '. ConversationMemory no persiste entre turnos.';
    }

    // El estado debe tener onboarding_step avanzado (no en el paso inicial)
    $step = (string) ($state3['onboarding_step'] ?? '');
    if ($step === '' || $step === 'business_type') {
        $failures[] = 'TEST 1 FAIL: onboarding_step="' . $step . '" — el contexto no avanzó '
            . 'entre el turno 1 y el turno 3. ConversationMemory no acumula turnos.';
    }

    // ── TEST 2: tenant_id correcto en mem_user (no 'default') ─────────────────
    // Consultar directo a SQLite para verificar la fila cruda
    $stmt = $pdo->prepare(
        'SELECT tenant_id, user_id, key_name FROM mem_user WHERE tenant_id = ? AND user_id = ? LIMIT 5'
    );
    $stmt->execute([$tenantId, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $failures[] = 'TEST 2 FAIL: No hay filas en mem_user con tenant_id=' . $tenantId
            . '. El estado se guardó con tenant_id incorrecto (posiblemente "default").';
    } else {
        foreach ($rows as $row) {
            if ((string) ($row['tenant_id'] ?? '') !== $tenantId) {
                $failures[] = 'TEST 2 FAIL: Fila en mem_user tiene tenant_id="'
                    . ($row['tenant_id'] ?? 'NULL') . '" pero se esperaba "' . $tenantId . '". '
                    . 'El aislamiento de tenant en memoria está roto.';
            }
        }
    }

    // Verificar que NO existen filas con 'default' para este usuario (fuga de tenant)
    $stmtDefault = $pdo->prepare(
        'SELECT COUNT(*) as cnt FROM mem_user WHERE tenant_id = ? AND user_id = ?'
    );
    $stmtDefault->execute(['default', $userId]);
    $defaultCount = (int) ($stmtDefault->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
    if ($defaultCount > 0) {
        $failures[] = 'TEST 2 FAIL: Hay ' . $defaultCount . ' fila(s) en mem_user con tenant_id="default" '
            . 'para user_id=' . $userId . '. El gateway usó "default" en lugar del tenant real.';
    }

    // ── TEST 3: Aislamiento cross-tenant — tenant_beta NO lee memoria de tenant_alpha ──
    $tenantBeta   = 'acid_tenant_beta_' . time();
    $userBeta     = 'acid_user_beta';

    // Guardar un secreto en tenant_alpha
    $memory->saveUserMemory($tenantId, $userId, 'secret_key', ['secret' => 'valor_secreto_alpha']);

    // Intentar leerlo desde tenant_beta (debe retornar el default vacío)
    $readFromBeta = $memory->getUserMemory($tenantBeta, $userId, 'secret_key', []);
    if (!empty($readFromBeta)) {
        $failures[] = 'TEST 3 FAIL: tenant_beta leyó memoria de tenant_alpha. '
            . 'Aislamiento cross-tenant ROTO en SqlMemoryRepository::getUserMemory(). '
            . 'Valor filtrado: ' . json_encode($readFromBeta);
    }

    // Verificar mismo session_id, distinto tenant — chat_log aislado
    $memory->appendShortTermMemory($tenantId, $userId, 'shared_session_id', 'test', 'in', 'mensaje secreto alpha');
    $memory->appendShortTermMemory($tenantBeta, $userBeta, 'shared_session_id', 'test', 'in', 'mensaje beta');

    $logsAlpha = $memory->getShortTermMemory($tenantId, 'shared_session_id', 10);
    $logsBeta  = $memory->getShortTermMemory($tenantBeta, 'shared_session_id', 10);

    // Verificar que alpha no ve mensajes de beta y viceversa
    $alphaMessages = array_column($logsAlpha, 'message');
    $betaMessages  = array_column($logsBeta, 'message');

    if (in_array('mensaje beta', $alphaMessages, true)) {
        $failures[] = 'TEST 3 FAIL: tenant_alpha ve mensajes de tenant_beta en chat_log con mismo session_id. '
            . 'getShortTermMemory() no filtra por tenant_id.';
    }
    if (in_array('mensaje secreto alpha', $betaMessages, true)) {
        $failures[] = 'TEST 3 FAIL: tenant_beta ve mensajes de tenant_alpha en chat_log con mismo session_id.';
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
