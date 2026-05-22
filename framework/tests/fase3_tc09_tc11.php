<?php
// framework/tests/fase3_tc09_tc11.php
// FASE 3 — TC09-TC11: POS cycle, Purchases cycle, Accounting
// Run: php framework/tests/fase3_tc09_tc11.php

declare(strict_types=1);

// ─── Bootstrap ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';
// app/autoload.php defines FRAMEWORK_ROOT + PROJECT_ROOT and loads project/.env

// Override namespace so existing tables work without prefix
putenv('DB_NAMESPACE_BY_PROJECT=0');
\App\Core\TableNamespace::clearCache();

// ─── Helpers ─────────────────────────────────────────────────────────────────
$phpBin     = PHP_BINARY;
$turnScript = __DIR__ . '/chat_auth_turn.php';
$pass       = 0;
$fail       = 0;
$results    = [];

$defaultAuth = [
    'id'         => 'test_fase3',
    'role'       => 'admin',
    'tenant_id'  => 'default',
    'project_id' => 'default',
];

function checkResult3(string $tcId, string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $results;
    $icon = $cond ? '✅' : '❌';
    echo "  $icon [$tcId] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
    $results[] = ['tc' => $tcId, 'label' => $label, 'pass' => $cond, 'detail' => $detail];
    $cond ? $pass++ : $fail++;
}

function chatTurn3(string $phpBin, string $turnScript, array $payload, array $auth = []): array
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
    if (!is_array($decoded)) {
        $decoded = ['raw' => substr((string) $raw, 0, 300), 'parse_error' => true];
    }
    return $decoded;
}

$tenantId = 'default';
$appId    = 'default';

echo PHP_EOL;
echo "══════════════════════════════════════════════════════════" . PHP_EOL;
echo "  SUKI FASE 3 — TC09–TC11  (POS / Purchases / Accounting)" . PHP_EOL;
echo "══════════════════════════════════════════════════════════" . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// TC09 — POS: createDraft → direct line insert → finalizeDraftSale
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC09: POS cycle ──" . PHP_EOL;

$tc09DraftId = null;
$tc09SaleId  = null;

try {
    $pos   = new \App\Core\POSService();
    $draft = $pos->createDraft([
        'tenant_id'  => $tenantId,
        'project_id' => $appId,
        'user_id'    => 'test_tc09',
    ]);

    $tc09DraftId = (string) ($draft['id'] ?? '');
    checkResult3('TC09', 'createDraft returns id', $tc09DraftId !== '', "id=$tc09DraftId");
    checkResult3('TC09', 'createDraft status=open', ($draft['status'] ?? '') === 'open');

    if ($tc09DraftId !== '') {
        // Insert line directly — addLineToDraft requires EntitySearch product resolution
        $pdo  = \App\Core\Database::connection();
        $now  = date('Y-m-d H:i:s');
        $qty  = 2;
        $unit = 25000;
        $sub  = $qty * $unit;
        $pdo->prepare(
            'INSERT INTO sale_draft_lines
             (tenant_id, app_id, sale_draft_id, product_id, product_label,
              qty, base_price, effective_unit_price, unit_price,
              line_subtotal, line_tax, line_total, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $tenantId, $appId, $tc09DraftId, 'prod_tc09_test', 'Artículo TC09',
            $qty, $unit, $unit, $unit,
            $sub, 0, $sub, $now, $now,
        ]);
        checkResult3('TC09', 'line inserted into sale_draft_lines', true, "qty={$qty} unit={$unit}");

        // finalizeDraftSale: recalculates totals internally then creates pos_sale
        $result = $pos->finalizeDraftSale([
            'tenant_id'  => $tenantId,
            'project_id' => $appId,
            'draft_id'   => $tc09DraftId,
            'user_id'    => 'test_tc09',
        ]);

        $sale       = is_array($result['sale'] ?? null) ? (array) $result['sale'] : [];
        $tc09SaleId = (string) ($sale['id'] ?? '');
        $saleNumber = (string) ($sale['sale_number'] ?? '');
        $status     = (string) ($sale['status'] ?? '');

        checkResult3('TC09', 'finalizeDraftSale returns sale id', $tc09SaleId !== '', "id=$tc09SaleId");
        checkResult3('TC09', 'sale_number assigned', $saleNumber !== '', "number=$saleNumber");
        checkResult3('TC09', 'sale status=completed', $status === 'completed', "status=$status");
        checkResult3('TC09', 'draft status=checked_out',
            ((string) ($result['draft']['status'] ?? '')) === 'checked_out');

        // Confirm pos_sales row in DB
        $row = $pdo->prepare('SELECT id, status, sale_number FROM pos_sales WHERE id = ? AND tenant_id = ?');
        $row->execute([$tc09SaleId, $tenantId]);
        $dbRow = $row->fetch();
        checkResult3('TC09', 'pos_sales row persisted in DB',
            is_array($dbRow) && $dbRow !== false,
            "id=$tc09SaleId");

        echo "    sale_number={$saleNumber} | total=" . ($sale['total'] ?? '?') . PHP_EOL;
    }
} catch (Throwable $e) {
    checkResult3('TC09', 'TC09 no exception', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
// TC10 — Purchases: createDraft → addLineToDraft → finalizeDraft
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC10: Purchases cycle ──" . PHP_EOL;

// Purchases tables may not exist yet — RuntimeSchemaPolicy requires both flags
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('APP_ENV=local');

try {
    $purchases = new \App\Core\PurchasesService();
    $draft = $purchases->createDraft([
        'tenant_id'  => $tenantId,
        'project_id' => $appId,
    ]);

    $tc10DraftId = (string) ($draft['id'] ?? '');
    checkResult3('TC10', 'createDraft returns id', $tc10DraftId !== '', "id=$tc10DraftId");
    checkResult3('TC10', 'createDraft status=open', ($draft['status'] ?? '') === 'open');

    if ($tc10DraftId !== '') {
        // product_id and query both absent → resolveProductIfProvided returns null
        // product_label required (throws PURCHASE_PRODUCT_LABEL_REQUIRED if empty)
        $draft = $purchases->addLineToDraft([
            'tenant_id'     => $tenantId,
            'project_id'    => $appId,
            'draft_id'      => $tc10DraftId,
            'product_label' => 'Resma papel A4',
            'qty'           => 5,
            'unit_cost'     => 12000,
            'tax_rate'      => 0.19,
        ]);

        $lineCount   = count((array) ($draft['lines'] ?? []));
        $draftTotal  = (float) ($draft['total'] ?? 0);
        checkResult3('TC10', 'addLineToDraft adds line', $lineCount > 0, "lines=$lineCount");
        checkResult3('TC10', 'draft total > 0 after line', $draftTotal > 0, "total=$draftTotal");

        $result = $purchases->finalizeDraft([
            'tenant_id'  => $tenantId,
            'project_id' => $appId,
            'draft_id'   => $tc10DraftId,
            'user_id'    => 'test_tc10',
        ]);

        $purchaseId     = (string) ($result['purchase_id'] ?? '');
        $purchaseNumber = (string) ($result['purchase_number'] ?? '');
        $purchase       = is_array($result['purchase'] ?? null) ? (array) $result['purchase'] : [];
        $status         = (string) ($purchase['status'] ?? '');

        checkResult3('TC10', 'finalizeDraft returns purchase_id', $purchaseId !== '', "id=$purchaseId");
        checkResult3('TC10', 'purchase_number assigned', $purchaseNumber !== '', "number=$purchaseNumber");
        checkResult3('TC10', 'purchase status=registered', $status === 'registered', "status=$status");
        checkResult3('TC10', 'line_count >= 1', (int) ($result['line_count'] ?? 0) >= 1,
            "lines=" . ($result['line_count'] ?? 0));

        echo "    purchase_number={$purchaseNumber} | total=" . ($purchase['total'] ?? '?') . PHP_EOL;
    }
} catch (Throwable $e) {
    checkResult3('TC10', 'TC10 no exception', false, get_class($e) . ': ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
// TC11 — Accounting: recordNonElectronicSale + listEntries + getBalanceSheet
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL . "── TC11: Accounting ──" . PHP_EOL;

// 11A: Direct service test
try {
    // Ensure accounting tables exist, then seed minimal roles + accounts for 'default' tenant.
    // puc_nacional may be empty in local dev, so insert directly into cuentas_contables.
    $accounting = new \App\Core\AccountingService();
    $acctPdo = \App\Core\Database::connection();
    $acctNow = date('Y-m-d H:i:s');
    $acctPdo->exec("INSERT IGNORE INTO cuentas_contables (tenant_id, codigo, nombre, tipo, naturaleza, nivel, es_auxiliar, activa) VALUES ('default', '1105', 'Caja', 'activo', 'debito', 'cuenta', 0, 1)");
    $acctPdo->exec("INSERT IGNORE INTO cuentas_contables (tenant_id, codigo, nombre, tipo, naturaleza, nivel, es_auxiliar, activa) VALUES ('default', '4135', 'Ingresos actividades comerciales', 'ingreso', 'credito', 'cuenta', 0, 1)");
    $acctPdo->exec("INSERT IGNORE INTO parametros_contables_tenant (tenant_id, rol, codigo_cuenta, descripcion, updated_at) VALUES ('default', 'recaudo_efectivo', '1105', 'Caja efectivo', '{$acctNow}')");
    $acctPdo->exec("INSERT IGNORE INTO parametros_contables_tenant (tenant_id, rol, codigo_cuenta, descripcion, updated_at) VALUES ('default', 'ingresos_ventas', '4135', 'Ingresos ventas', '{$acctNow}');");

    $entry = $accounting->recordNonElectronicSale(
        $tenantId,
        150000.0,
        'TC11-REF-001',
        'ticket_pos',
        'test_tc11'
    );

    $entryId   = (string) ($entry['id'] ?? '');
    $entryOk   = (string) ($entry['status'] ?? '') === 'SUCCESS';
    $notElec   = ($entry['is_electronic'] ?? true) === false;

    checkResult3('TC11', 'recordNonElectronicSale returns id', $entryId !== '', "id=$entryId");
    checkResult3('TC11', 'entry status=SUCCESS', $entryOk, "status=" . ($entry['status'] ?? '?'));
    checkResult3('TC11', 'is_electronic=false', $notElec);

    $entries = $accounting->listEntries($tenantId, 'all', 20);
    $found   = false;
    foreach ($entries as $e) {
        if ((string) ($e['id'] ?? '') === $entryId
            || (string) ($e['referencia'] ?? '') === 'TC11-REF-001') {
            $found = true;
            break;
        }
    }
    checkResult3('TC11', 'listEntries returns rows', count($entries) > 0, "count=" . count($entries));
    checkResult3('TC11', 'new entry present in listEntries', $found, "entry_id=$entryId");

    $balanceSheet = $accounting->getBalanceSheet($tenantId);
    checkResult3('TC11', 'getBalanceSheet returns accounts',
        is_array($balanceSheet) && count($balanceSheet) > 0,
        "accounts=" . count($balanceSheet));
} catch (Throwable $e) {
    checkResult3('TC11', 'TC11 accounting no exception', false, get_class($e) . ': ' . $e->getMessage());
}

// 11B: Chat turn — accounting query
echo PHP_EOL . "  Chat turn: 'cuánto vendí este mes'" . PHP_EOL;
$r11 = chatTurn3($phpBin, $turnScript, [
    'message'    => 'cuánto vendí este mes',
    'tenant_id'  => $tenantId,
    'session_id' => 'tc11_session_' . substr(md5((string) microtime(true)), 0, 8),
], $defaultAuth);
$reply11       = (string) ($r11['data']['reply'] ?? $r11['data']['message'] ?? '');
$noStackTrace  = !str_contains($reply11, 'framework/') && !str_contains($reply11, '.php:');
$hasReply      = $reply11 !== '' && strlen($reply11) > 10;
checkResult3('TC11', 'chat "cuánto vendí" — respuesta presente', $hasReply);
checkResult3('TC11', 'chat — sin stack trace en respuesta', $noStackTrace);
echo "    reply: " . substr($reply11, 0, 120) . PHP_EOL;

// ─────────────────────────────────────────────────────────────────────────────
// Resumen final
// ─────────────────────────────────────────────────────────────────────────────
echo PHP_EOL;
echo "══════════════════════════════════════════════════════════" . PHP_EOL;
$total = $pass + $fail;
echo "  RESULTADO: $pass/$total PASS" . ($fail === 0 ? " ✅ FASE 3 OK" : " ❌ FALLOS: $fail") . PHP_EOL;
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
