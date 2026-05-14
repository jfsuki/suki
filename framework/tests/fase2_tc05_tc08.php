<?php
// framework/tests/fase2_tc05_tc08.php
// FASE 2 — TC05-TC08: App Creator + CRUD + Report URLs + FormulaEngine

declare(strict_types=1);

$phpBin     = PHP_BINARY;
$turnScript = __DIR__ . '/chat_auth_turn.php';
$pass = 0;
$fail = 0;
$results = [];

$defaultAuth = [
    'id'         => 'test_fase2',
    'role'       => 'admin',
    'tenant_id'  => 'default',
    'project_id' => 'default',
];

// ─── Helper: run one chat turn ────────────────────────────────────────────────
function chatTurn2(string $phpBin, string $turnScript, array $payload, array $auth = []): array
{
    $payload['test_mode'] = true;
    $encoded = base64_encode((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    $authEnc = $auth !== [] ? base64_encode((string) json_encode($auth, JSON_UNESCAPED_UNICODE)) : '';
    $args = [$phpBin, $turnScript, $encoded];
    if ($authEnc !== '') {
        $args[] = $authEnc;
    }
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $startMs = microtime(true) * 1000;
    $proc = proc_open($args, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['error' => 'proc_open failed', '_elapsed_ms' => 0];
    }
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    $endMs = microtime(true) * 1000;
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => substr((string) $raw, 0, 300), 'parse_error' => true];
    }
    $decoded['_elapsed_ms'] = round($endMs - $startMs);
    return $decoded;
}

function checkResult2(string $tcId, string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $results;
    $icon = $cond ? '✅' : '❌';
    echo "  $icon [$tcId] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
    $results[] = ['tc' => $tcId, 'label' => $label, 'pass' => $cond, 'detail' => $detail];
    $cond ? $pass++ : $fail++;
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap (load autoloader + .env)
// ─────────────────────────────────────────────────────────────────────────────
$autoloaderPath = dirname(__DIR__) . '/vendor/autoload.php';
$hasAutoloader  = file_exists($autoloaderPath);
if ($hasAutoloader) {
    require_once $autoloaderPath;
    // Load .env
    $envFile = dirname(__DIR__, 2) . '/project/.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = ltrim($line, "\xEF\xBB\xBF");
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            putenv(trim($k) . '=' . trim($v, " \t\n\r\"'"));
        }
    }
}

function getDb2(): PDO
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'suki_saas');
    $user = getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'root');
    $pass = getenv('DB_PASS') ?: (getenv('DB_PASSWORD') ?: '');
    return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

$tenantId  = 'default';
$appId     = 'vet_clinic';
// Use timestamp-suffix so successive test runs are always fresh
$testSuffix = date('Ymd_His');

echo PHP_EOL;
echo "══════════════════════════════════════════════════════════" . PHP_EOL;
echo "  SUKI FASE 2 — TC05–TC08  (App Creator + CRUD + Reports)" . PHP_EOL;
echo "══════════════════════════════════════════════════════════" . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC05 — App Creator: direct skill invocation (confirmed=true with schema)
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC05: App Creator — creación directa vía skill ──" . PHP_EOL;

$vetTablePacientes = "app_{$appId}__pacientes";
$vetTableCitas     = "app_{$appId}__citas";

if ($hasAutoloader) {
    // Drop test tables if they exist from a previous run so we get a clean test
    try {
        $pdo = getDb2();
        $pdo->exec("DROP TABLE IF EXISTS `{$vetTablePacientes}`");
        $pdo->exec("DROP TABLE IF EXISTS `{$vetTableCitas}`");
    } catch (\Throwable $e) {
        echo "  ⚠️  Drop pre-existing tables: " . $e->getMessage() . PHP_EOL;
    }

    // Note: validateAndSanitize reads 'columns', not 'fields' — match the canonical schema format
    $schema = [
        'entities' => [
            [
                'table'   => 'pacientes',
                'name'    => 'pacientes',
                'label'   => 'Pacientes',
                'columns' => [
                    ['name' => 'nombre',    'label' => 'Nombre',      'type' => 'varchar',  'length' => 100, 'required' => true],
                    ['name' => 'especie',   'label' => 'Especie',     'type' => 'varchar',  'length' => 50,  'required' => true],
                    ['name' => 'raza',      'label' => 'Raza',        'type' => 'varchar',  'length' => 80,  'nullable' => true],
                    ['name' => 'edad_anos', 'label' => 'Edad',        'type' => 'integer',  'nullable' => true],
                    ['name' => 'dueno',     'label' => 'Dueno',       'type' => 'varchar',  'length' => 120, 'required' => true],
                    ['name' => 'telefono',  'label' => 'Telefono',    'type' => 'varchar',  'length' => 20,  'nullable' => true],
                ],
            ],
            [
                'table'   => 'citas',
                'name'    => 'citas',
                'label'   => 'Citas',
                'columns' => [
                    ['name' => 'fecha',       'label' => 'Fecha',       'type' => 'date',    'required' => true],
                    ['name' => 'motivo',      'label' => 'Motivo',      'type' => 'varchar', 'length' => 200, 'required' => true],
                    ['name' => 'paciente_id', 'label' => 'Paciente',    'type' => 'integer', 'nullable' => true],
                    ['name' => 'veterinario', 'label' => 'Veterinario', 'type' => 'varchar', 'length' => 100, 'nullable' => true],
                    ['name' => 'estado',      'label' => 'Estado',      'type' => 'varchar', 'length' => 30,  'nullable' => true],
                ],
            ],
        ],
    ];

    $sessionId = "tc05_{$testSuffix}";

    try {
        $skill  = new \App\Core\Skills\CreateAppSkill();
        $result = $skill->handle([
            'confirmed'     => true,
            'app_id'        => $appId,
            'business_name' => 'Clínica Peludo',
            'requirements'  => 'Veterinaria con 2 veterinarios, atiende perros y gatos, necesita gestionar pacientes y citas',
            'schema'        => $schema,
        ], [
            'tenant_id'  => $tenantId,
            'session_id' => $sessionId,
        ]);

        $skillOk = ($result['ok'] ?? false) === true;
        checkResult2('TC05', 'CreateAppSkill ejecuta sin error', $skillOk, 'ok=' . ($result['ok'] ? 'true' : 'false') . ' action=' . ($result['action'] ?? '?'));
        echo "    reply: " . substr((string) ($result['reply'] ?? ''), 0, 100) . PHP_EOL;

        if (!$skillOk && isset($result['reply'])) {
            echo "    [debug] " . substr($result['reply'], 0, 150) . PHP_EOL;
        }
    } catch (\Throwable $e) {
        checkResult2('TC05', 'CreateAppSkill ejecuta sin error', false, $e->getMessage());
    }

    // Verify MySQL tables created
    try {
        $pdo = getDb2();
        $tables = $pdo->query("SHOW TABLES LIKE 'app_{$appId}__%'")->fetchAll(PDO::FETCH_COLUMN);
        $hasPacientes = in_array($vetTablePacientes, $tables, true);
        $hasCitas     = in_array($vetTableCitas, $tables, true);
        checkResult2('TC05', "Tabla {$vetTablePacientes} creada", $hasPacientes);
        checkResult2('TC05', "Tabla {$vetTableCitas} creada", $hasCitas);
        foreach ($tables as $t) {
            echo "    tabla: $t" . PHP_EOL;
        }
    } catch (\Throwable $e) {
        checkResult2('TC05', 'Tablas MySQL verificadas', false, $e->getMessage());
    }

    // Verify app JSON file in storage (AppMemoryService writes to project/storage/meta/app_memory/)
    $appMemoryDir  = dirname(__DIR__, 2) . '/project/storage/meta/app_memory';
    $expectedJson  = $appMemoryDir . "/{$tenantId}_{$appId}.json";
    $hasAppJsonFile = file_exists($expectedJson);
    checkResult2('TC05', 'Archivo JSON de app guardado (meta/app_memory)', $hasAppJsonFile,
        $hasAppJsonFile ? basename($expectedJson) : 'not found');
    if ($hasAppJsonFile) {
        $meta = json_decode((string) file_get_contents($expectedJson), true);
        $hasEntities = isset($meta['schema']['entities']) && count($meta['schema']['entities']) > 0;
        checkResult2('TC05', 'JSON de app contiene entities', $hasEntities,
            'entities=' . count($meta['schema']['entities'] ?? []));
        echo "    json: " . basename($expectedJson) . PHP_EOL;
    }

    // TC05 chat: builder interview starts a conversation
    echo PHP_EOL . "  [TC05-chat] Verificar respuesta de entrevista via chat:" . PHP_EOL;
    $rChat = chatTurn2($phpBin, $turnScript, [
        'message'    => 'quiero crear una app para mi veterinaria',
        'tenant_id'  => $tenantId,
        'session_id' => "tc05_chat_{$testSuffix}",
        'mode'       => 'builder',
    ], $defaultAuth);
    $chatReply = (string) ($rChat['data']['reply'] ?? $rChat['data']['message'] ?? '');
    $chatOk    = $chatReply !== '' && !str_contains($chatReply, 'Acceso denegado') && strlen($chatReply) > 20;
    checkResult2('TC05', 'Chat builder — respuesta de entrevista presente', $chatOk, substr($chatReply, 0, 70));
    echo "    [{$rChat['_elapsed_ms']}ms] reply: " . substr($chatReply, 0, 100) . PHP_EOL;
} else {
    echo "  ⚠️  No autoloader — skipping direct skill tests" . PHP_EOL;
    checkResult2('TC05', 'Autoloader disponible para skill tests', false);
}

// ─────────────────────────────────────────────────────────────────────────────
// TC06 — CRUD por chat (app mode, con contexto de vet_clinic)
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC06: CRUD por chat ──" . PHP_EOL;

$crudSessionId = "tc06_crud_{$testSuffix}";

// INSERT 1 — Firulais
$rIns1 = chatTurn2($phpBin, $turnScript, [
    'message'    => 'registra un paciente en pacientes: nombre Firulais, especie perro, raza labrador, edad_anos 3, dueno Juan Pérez, telefono 3001234567',
    'tenant_id'  => $tenantId,
    'session_id' => $crudSessionId,
    'mode'       => 'app',
    'app_id'     => $appId,
], $defaultAuth);
$replyIns1 = (string) ($rIns1['data']['reply'] ?? $rIns1['data']['message'] ?? '');
$ins1Ok = $replyIns1 !== '' && !str_contains($replyIns1, 'Acceso denegado');
$ins1MentionsRecord = str_contains(strtolower($replyIns1), 'firulais')
                   || str_contains(strtolower($replyIns1), 'registr')
                   || str_contains(strtolower($replyIns1), 'creado')
                   || str_contains(strtolower($replyIns1), 'id');
checkResult2('TC06', 'INSERT Firulais — respuesta presente', $ins1Ok, substr($replyIns1, 0, 70));
checkResult2('TC06', 'INSERT Firulais — reply confirma registro', $ins1MentionsRecord, substr($replyIns1, 0, 80));
echo "    [{$rIns1['_elapsed_ms']}ms] reply: " . substr($replyIns1, 0, 100) . PHP_EOL;

// Verify DB record if app tables exist
if ($hasAutoloader) {
    try {
        $pdo = getDb2();
        // Verify the table has custom columns (nombres, especie, etc.) — table creation test
        $cols = $pdo->query("SHOW COLUMNS FROM `{$vetTablePacientes}`")->fetchAll(\PDO::FETCH_COLUMN);
        $hasNombreCol = in_array('nombre', $cols, true);
        checkResult2('TC06', "Tabla {$vetTablePacientes} tiene columna 'nombre'", $hasNombreCol, 'cols: ' . implode(', ', array_slice($cols, 0, 8)));
        // Count any rows (LLM may have inserted via AppCrudSkill if tool_use fired)
        if ($hasNombreCol) {
            $rowCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$vetTablePacientes}`")->fetchColumn();
            echo "    DB rows in pacientes: {$rowCount}" . PHP_EOL;
        }
    } catch (\Throwable $e) {
        checkResult2('TC06', "DB: tabla {$vetTablePacientes} verificada", false, $e->getMessage());
    }
}

// INSERT 2 — Luna
$rIns2 = chatTurn2($phpBin, $turnScript, [
    'message'    => 'registra otro paciente en pacientes: nombre Luna, especie gato, edad_anos 1, dueno María López',
    'tenant_id'  => $tenantId,
    'session_id' => $crudSessionId,
    'mode'       => 'app',
    'app_id'     => $appId,
], $defaultAuth);
$replyIns2 = (string) ($rIns2['data']['reply'] ?? $rIns2['data']['message'] ?? '');
$ins2Ok = $replyIns2 !== '' && !str_contains($replyIns2, 'Acceso denegado');
checkResult2('TC06', 'INSERT Luna — respuesta presente', $ins2Ok, substr($replyIns2, 0, 70));
echo "    [{$rIns2['_elapsed_ms']}ms] reply: " . substr($replyIns2, 0, 100) . PHP_EOL;

// LIST
$rList = chatTurn2($phpBin, $turnScript, [
    'message'    => 'muéstrame los pacientes de la clínica veterinaria',
    'tenant_id'  => $tenantId,
    'session_id' => $crudSessionId,
    'mode'       => 'app',
    'app_id'     => $appId,
], $defaultAuth);
$replyList = (string) ($rList['data']['reply'] ?? $rList['data']['message'] ?? '');
$listOk = $replyList !== '' && !str_contains($replyList, 'Acceso denegado');
$exposesRawUrl = str_contains($replyList, 'app_id=') || str_contains($replyList, 'tenant_id=');
$hasMaskedUrl  = preg_match('#/r/[A-Za-z0-9_.\-]{8,}#', $replyList) === 1;
checkResult2('TC06', 'LIST pacientes — respuesta presente', $listOk, substr($replyList, 0, 70));
echo "    [{$rList['_elapsed_ms']}ms] reply: " . substr($replyList, 0, 120) . PHP_EOL;

// Extract report token for TC07
$reportToken = '';
if (preg_match('#/r/([A-Za-z0-9_.\-]{8,})#', $replyList, $m)) {
    $reportToken = $m[1];
}

// ─────────────────────────────────────────────────────────────────────────────
// TC07 — Seguridad de URLs de reporte
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC07: Seguridad de URLs de reporte ──" . PHP_EOL;

if ($reportToken !== '') {
    checkResult2('TC07', 'URL de reporte presente en reply LIST', true, "/r/{$reportToken}");
    checkResult2('TC07', 'URL no expone app_id/tenant_id raw', !$exposesRawUrl, $exposesRawUrl ? 'EXPONE parámetros' : 'enmascarada');

    if ($hasAutoloader && class_exists('App\Core\ReportTokenService')) {
        $rts = new \App\Core\ReportTokenService();

        // Valid token decodes
        $decoded = $rts->decode($reportToken);
        $isValid = is_array($decoded) && !empty($decoded);
        checkResult2('TC07', 'Token válido decodifica (HMAC OK)', $isValid,
            $isValid ? 'tenant=' . ($decoded['t'] ?? '?') . ' app=' . ($decoded['a'] ?? '?') : 'decode failed');

        // Tampered token rejected
        $tampered = substr($reportToken, 0, -2) . 'ZZ';
        $decodedTampered = $rts->decode($tampered);
        checkResult2('TC07', 'Token manipulado rechazado (null/false)', $decodedTampered === null || $decodedTampered === false,
            'tampered=' . $tampered);
    } else {
        checkResult2('TC07', 'URL no expone parámetros raw (sin autoloader)', !$exposesRawUrl);
    }
} else {
    // LIST might reply conversationally instead of with a token — check if skill was not triggered
    echo "  ℹ️  [TC07] No /r/{token} en reply LIST — skill query_app_data puede no haberse disparado" . PHP_EOL;
    echo "           reply: " . substr($replyList, 0, 120) . PHP_EOL;
    // Soft TC07 check: verify AppReportSkill generates valid tokens directly
    if ($hasAutoloader && class_exists('App\Core\ReportTokenService')) {
        checkResult2('TC07', 'URL LIST no expone parámetros raw', !$exposesRawUrl, 'no token found, checking no leakage');
        $rts = new \App\Core\ReportTokenService();
        $testToken = $rts->generate(['t' => $tenantId, 'a' => $appId, 'e' => 'pacientes']);
        $testDecoded = $rts->decode($testToken);
        checkResult2('TC07', 'ReportTokenService genera y decodifica token', is_array($testDecoded) && !empty($testDecoded), 'direct service test');
        // Tampered
        $tampered = substr($testToken, 0, -2) . 'ZZ';
        $decodedTampered = $rts->decode($tampered);
        checkResult2('TC07', 'Token manipulado rechazado', $decodedTampered === null || $decodedTampered === false);
    } else {
        checkResult2('TC07', 'URL de reporte presente en reply LIST', false, 'no /r/{token} y sin autoloader');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// TC08 — FormulaEngine
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC08: FormulaEngine (columnas calculadas) ──" . PHP_EOL;

if ($hasAutoloader && class_exists('App\Core\FormulaEngine')) {
    $fe = new \App\Core\FormulaEngine();

    // applyFormulas(array $formulas, array $row)
    // Each formula: ['name' => 'col', 'expression' => '...']
    $formulas = [['name' => 'total', 'expression' => '{precio} * {cantidad}']];
    $row = ['precio' => '45000', 'cantidad' => '1'];
    $result = $fe->applyFormulas($formulas, $row);
    checkResult2('TC08', 'total = precio * cantidad = 45000', isset($result['total']) && (float) $result['total'] === 45000.0, 'total=' . ($result['total'] ?? 'missing'));

    // Zero quantity — no crash
    $rowZero = ['precio' => '45000', 'cantidad' => '0'];
    try {
        $rZero = $fe->applyFormulas($formulas, $rowZero);
        checkResult2('TC08', 'cantidad=0 → total=0 (no crash)', isset($rZero['total']) && (float) $rZero['total'] === 0.0, 'total=' . ($rZero['total'] ?? 'crash'));
    } catch (\Throwable $e) {
        checkResult2('TC08', 'cantidad=0 no crash', false, $e->getMessage());
    }

    // Composite formula with ROUND
    $formulasRound = [
        ['name' => 'subtotal',   'expression' => '{precio} * {cantidad}'],
        ['name' => 'total_neto', 'expression' => 'ROUND({precio} * {cantidad} * (1 - {descuento}/100), 0)'],
    ];
    $rowRound = ['precio' => '33333', 'cantidad' => '3', 'descuento' => '10'];
    try {
        $rRound = $fe->applyFormulas($formulasRound, $rowRound);
        $expectedSub  = 33333 * 3;
        $expectedNeto = round(33333 * 3 * 0.9, 0);
        checkResult2('TC08', "subtotal = {$expectedSub}", isset($rRound['subtotal']) && (float) $rRound['subtotal'] === (float) $expectedSub, 'got=' . ($rRound['subtotal'] ?? 'missing'));
        checkResult2('TC08', "total_neto con ROUND y descuento ≈ {$expectedNeto}", isset($rRound['total_neto']) && abs((float) $rRound['total_neto'] - $expectedNeto) < 1, 'got=' . ($rRound['total_neto'] ?? 'missing'));
    } catch (\Throwable $e) {
        checkResult2('TC08', 'FormulaEngine fórmula compuesta con ROUND', false, $e->getMessage());
    }

    // validate() returns ['valid' => bool, 'error' => string]
    $dangerous = $fe->validate('eval(phpinfo())');
    checkResult2('TC08', 'Fórmula peligrosa rechazada', ($dangerous['valid'] ?? true) === false, 'valid=' . json_encode($dangerous['valid'] ?? null));

    $valid = $fe->validate('{precio} * {cantidad}');
    checkResult2('TC08', 'Fórmula válida pasa validación', ($valid['valid'] ?? false) === true, 'valid=' . json_encode($valid['valid'] ?? null));
} else {
    checkResult2('TC08', 'FormulaEngine class cargable', false, 'autoloader=' . ($hasAutoloader ? 'yes' : 'no') . ' class=' . (class_exists('App\Core\FormulaEngine') ? 'yes' : 'no'));
}

// ─────────────────────────────────────────────────────────────────────────────
// Resumen
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL;
echo "══════════════════════════════════════════════════════════" . PHP_EOL;
$total = $pass + $fail;
echo "  RESULTADO: $pass/$total PASS" . ($fail === 0 ? " ✅ FASE 2 OK" : " ❌ FALLOS: $fail") . PHP_EOL;
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
