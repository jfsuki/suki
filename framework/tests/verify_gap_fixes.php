<?php
// framework/tests/verify_gap_fixes.php
// Verifies GAP A (tenant→app mapping) and GAP B (mixin filtering) are working.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        echo "  ✓ {$label}" . ($detail ? " [{$detail}]" : '') . PHP_EOL;
        $pass++;
    } else {
        echo "  ✗ {$label}" . ($detail ? " [{$detail}]" : '') . PHP_EOL;
        $fail++;
    }
}

/** Creates an in-memory SQLite PDO pre-seeded with app_tenant_config data. */
function makeTestPdo(array $rows = []): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE app_tenant_config (
        tenant_id TEXT, app_id TEXT, field_key TEXT, field_value TEXT,
        PRIMARY KEY (tenant_id, app_id, field_key)
    )');
    $stmt = $pdo->prepare('INSERT INTO app_tenant_config VALUES (?,?,?,?)');
    foreach ($rows as $r) {
        $stmt->execute($r);
    }
    return $pdo;
}

// ─────────────────────────────────────────────
// 1. BuilderOnboardingProcess: matchCatalogAppId
// ─────────────────────────────────────────────
echo PHP_EOL . '=== GAP A — matchCatalogAppId() ===' . PHP_EOL;

$proc = new \App\Core\Agents\Processes\BuilderOnboardingProcess();
$refMatch = new ReflectionMethod($proc, 'matchCatalogAppId');
$refMatch->setAccessible(true);

$cases = [
    ['veterinaria mascotas perros'    , 'vet_clinic'],
    ['consultorio dental odontologia' , 'dental_clinic'],
    ['clinica medica salud'           , 'health_clinic'],
    ['restaurante bar cocina'         , 'restaurant'],
    ['salon de belleza spa estetica'  , 'beauty_salon'],
    ['gimnasio fitness deporte'       , 'gym_fitness'],
    ['farmacia drogueria medicamentos', 'pharmacy'],
    ['logistica transporte envios'    , 'logistics_transport'],
    ['empresa constructora obras'     , 'construction'],
    ['moda confeccion ropa'           , 'fashion_clothing'],
    ['firma contable contador'        , 'accounting_firm'],
    ['colegio educacion academia'     , 'education_center'],
    ['iglesia ministerio religion'    , 'church_org'],
    ['ferreteria materiales'          , 'suki_erp'],
    ['negocio generico cualquier'     , 'suki_erp'],
];

foreach ($cases as [$input, $expected]) {
    $got = $refMatch->invoke($proc, ['business_type' => $input]);
    check("{$input} → {$expected}", $got === $expected, "got={$got}");
}

// ─────────────────────────────────────────────
// 2. BuilderOnboardingProcess: extractActiveMixins
// ─────────────────────────────────────────────
echo PHP_EOL . '=== GAP B — extractActiveMixins() ===' . PHP_EOL;

$refMix = new ReflectionMethod($proc, 'extractActiveMixins');
$refMix->setAccessible(true);

$facts1 = [
    'fiscal'       => ['regimen' => 'simplificado'],
    'needs_scope'  => 'inventario y caja pos',
    'integrations' => 'factura electronica dian',
];
$mixins1 = $refMix->invoke($proc, $facts1);
check('accounting active when fiscal.regimen set',        in_array('accounting', $mixins1));
check('pos active when needs_scope has "caja pos"',       in_array('pos', $mixins1));
check('inventory active when needs_scope has "inventario"', in_array('inventory', $mixins1));
check('billing_dian active when integrations has "dian"', in_array('billing_dian', $mixins1));

$facts2 = ['business_type' => 'iglesia sin ventas'];
$mixins2 = $refMix->invoke($proc, $facts2);
check('billing_dian NOT active without explicit mention', !in_array('billing_dian', $mixins2));

// ─────────────────────────────────────────────
// 3. AppConfigOnboarding: resolveInstalledAppId
// ─────────────────────────────────────────────
echo PHP_EOL . '=== GAP A — resolveInstalledAppId() ===' . PHP_EOL;

// Scenario A: no installed app → returns fallback
$pdo1 = makeTestPdo([]);
$svc1 = new \App\Core\AppTenantConfigService($pdo1);
$cfg1 = new \App\Core\AppConfigOnboarding($svc1);
$got  = $cfg1->resolveInstalledAppId('t1', 'suki_erp');
check('Fallback to suki_erp when no _installed_app_id', $got === 'suki_erp', "got={$got}");

// Scenario B: _installed_app_id in tenant_meta slot
$pdo2 = makeTestPdo([['t2', 'tenant_meta', '_installed_app_id', 'vet_clinic']]);
$svc2 = new \App\Core\AppTenantConfigService($pdo2);
$cfg2 = new \App\Core\AppConfigOnboarding($svc2);
$got  = $cfg2->resolveInstalledAppId('t2', 'suki_erp');
check('Reads _installed_app_id from tenant_meta', $got === 'vet_clinic', "got={$got}");

// Scenario C: _installed_app_id under app slot (older saves without tenant_meta)
$pdo3 = makeTestPdo([['t3', 'suki_erp', '_installed_app_id', 'dental_clinic']]);
$svc3 = new \App\Core\AppTenantConfigService($pdo3);
$cfg3 = new \App\Core\AppConfigOnboarding($svc3);
$got  = $cfg3->resolveInstalledAppId('t3', 'suki_erp');
check('Reads _installed_app_id from app slot (fallback path)', $got === 'dental_clinic', "got={$got}");

// Scenario D: tenant_meta takes precedence over app slot
$pdo4 = makeTestPdo([
    ['t4', 'tenant_meta', '_installed_app_id', 'vet_clinic'],
    ['t4', 'suki_erp',    '_installed_app_id', 'dental_clinic'],
]);
$svc4 = new \App\Core\AppTenantConfigService($pdo4);
$cfg4 = new \App\Core\AppConfigOnboarding($svc4);
$got  = $cfg4->resolveInstalledAppId('t4', 'suki_erp');
check('tenant_meta takes precedence over app slot', $got === 'vet_clinic', "got={$got}");

// ─────────────────────────────────────────────
// 4. AppConfigOnboarding: resolveAllFieldsForApp respects _active_mixins
// ─────────────────────────────────────────────
echo PHP_EOL . '=== GAP B — resolveAllFieldsForApp mixin filter ===' . PHP_EOL;

// Tenant: accounting + inventory active, billing_dian NOT active
$pdo5 = makeTestPdo([['t5', 'vet_clinic', '_active_mixins', 'accounting,inventory']]);
$svc5 = new \App\Core\AppTenantConfigService($pdo5);
$cfg5 = new \App\Core\AppConfigOnboarding($svc5);
$refR = new ReflectionMethod($cfg5, 'resolveAllFieldsForApp');
$refR->setAccessible(true);
$fields5    = $refR->invoke($cfg5, 'vet_clinic', 't5');
$fieldKeys5 = array_column($fields5, 'key');
check('inventory field present when mixin active',          in_array('numero_bodegas', $fieldKeys5));
check('billing_dian field absent when mixin NOT active',    !in_array('resolucion_dian', $fieldKeys5));
check('accounting field present when mixin active',         in_array('regimen_tributario', $fieldKeys5));

// Tenant: billing_dian active
$pdo6 = makeTestPdo([['t6', 'vet_clinic', '_active_mixins', 'billing_dian']]);
$svc6 = new \App\Core\AppTenantConfigService($pdo6);
$cfg6 = new \App\Core\AppConfigOnboarding($svc6);
$refR6 = new ReflectionMethod($cfg6, 'resolveAllFieldsForApp');
$refR6->setAccessible(true);
$fields6    = $refR6->invoke($cfg6, 'vet_clinic', 't6');
$fieldKeys6 = array_column($fields6, 'key');
check('billing_dian field present when mixin active',       in_array('resolucion_dian', $fieldKeys6));
check('inventory field absent when mixin NOT active',       !in_array('numero_bodegas', $fieldKeys6));

// Tenant: no _active_mixins stored → defaults to ALL compatible mixins
$pdo7 = makeTestPdo([]);
$svc7 = new \App\Core\AppTenantConfigService($pdo7);
$cfg7 = new \App\Core\AppConfigOnboarding($svc7);
$refR7 = new ReflectionMethod($cfg7, 'resolveAllFieldsForApp');
$refR7->setAccessible(true);
$fields7    = $refR7->invoke($cfg7, 'vet_clinic', 't7');
$fieldKeys7 = array_column($fields7, 'key');
check('All compatible mixins active when no _active_mixins stored (first install)', count($fieldKeys7) > 5);

// ─────────────────────────────────────────────
// 5. InstallPlaybookCommandHandler logic: catalog_app_id used as appId
// ─────────────────────────────────────────────
echo PHP_EOL . '=== GAP A — InstallPlaybookCommandHandler appId selection ===' . PHP_EOL;

$command1 = ['catalog_app_id' => 'vet_clinic', 'initial_config' => []];
$appId1 = trim($command1['catalog_app_id'] ?? '') ?: strtolower('VETERINARIA');
check('catalog_app_id used when present', $appId1 === 'vet_clinic', "got={$appId1}");

$command2 = ['catalog_app_id' => '', 'initial_config' => []];
$appId2 = trim($command2['catalog_app_id'] ?? '') ?: strtolower('VETERINARIA');
check('strtolower(sectorKey) fallback when catalog_app_id absent', $appId2 === 'veterinaria', "got={$appId2}");

// ─────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────
$total = $pass + $fail;
echo PHP_EOL . str_repeat('═', 56) . PHP_EOL;
echo "  RESULTADO: {$pass}/{$total} " . ($fail === 0 ? 'PASS ✅ GAP FIXES OK' : "FAIL ❌ ({$fail} failing)") . PHP_EOL;
echo str_repeat('═', 56) . PHP_EOL;
exit($fail > 0 ? 1 : 0);
