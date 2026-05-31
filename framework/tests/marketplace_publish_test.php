<?php

declare(strict_types=1);

/**
 * Tests del flujo Builder → Marketplace.
 *
 * Verifica el ciclo completo:
 *   proposeApp() → status:draft (invisible en marketplace)
 *   publishApp()  → status:available (visible en marketplace)
 *   getDraftsByTenant() → solo el creador ve sus borradores
 *   Ownership: otro tenant NO puede publicar app ajena
 */

require_once dirname(__DIR__) . '/app/autoload.php';

use App\Core\AppCatalogManager;
use App\Core\Skills\CreateAppSkill;

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}\n";
        $failed++;
    }
}

echo "\n=== Marketplace Publish Flow Tests ===\n\n";

// ─── Setup: catálogo temporal en archivo temp ────────────────────────────────

$tmpCatalog = sys_get_temp_dir() . '/suki_test_catalog_' . uniqid() . '.json';
$baseCatalog = [
    'schema_version' => '2.0',
    'mixins' => [],
    'apps' => [
        [
            'id'     => 'suki_erp',
            'name'   => 'SUKI ERP Core',
            'status' => 'available',   // App base de SUKI — ya publicada, sin _proposed_by_tenant
        ],
    ],
];
file_put_contents($tmpCatalog, json_encode($baseCatalog, JSON_PRETTY_PRINT));

$manager = new AppCatalogManager($tmpCatalog);

// ─── M01-M03: App base del catálogo SUKI permanece 'available' ───────────────

$apps = $manager->listApps();
ok('M01 catálogo base tiene apps existentes', count($apps) === 1);
ok('M02 app base suki_erp está available', ($apps[0]['status'] ?? '') === 'available');

// ─── M04-M08: proposeApp() crea con status:draft ─────────────────────────────

$result = $manager->proposeApp([
    'id'             => 'ferreteria_pro',
    'name'           => 'Ferretería Pro',
    'category'       => 'Comercio',
    'sector'         => 'ferreteria',
    'description'    => 'App para ferretería con POS e inventario',
    'enabled_agents' => ['SALES', 'INVENTORY'],
    'modules'        => ['pos', 'inventario'],
    'config_fields'  => ['nit', 'razon_social'],
], 'tenant_builder_1');

ok('M04 proposeApp() retorna ok=true', $result['ok'] === true);
ok('M05 app propuesta tiene status:draft', ($result['app']['status'] ?? '') === 'draft');
ok('M06 app propuesta tiene _proposed_by_tenant', ($result['app']['_proposed_by_tenant'] ?? '') === 'tenant_builder_1');
ok('M07 app draft NO aparece en marketplace (solo available)', count(array_filter($manager->listApps(), fn($a) => ($a['status'] ?? '') === 'available' && $a['id'] === 'ferreteria_pro')) === 0);
ok('M08 app draft SÍ existe en el catálogo completo', $manager->findApp('ferreteria_pro') !== null);

// ─── M09-M12: getDraftsByTenant() aislamiento ────────────────────────────────

$drafts = $manager->getDraftsByTenant('tenant_builder_1');
ok('M09 getDraftsByTenant devuelve la app del builder', count($drafts) === 1);
ok('M10 draft tiene id correcto', ($drafts[0]['id'] ?? '') === 'ferreteria_pro');

$otherDrafts = $manager->getDraftsByTenant('otro_tenant');
ok('M11 otro tenant NO ve los drafts ajenos', count($otherDrafts) === 0);

$allDrafts = $manager->getDraftsByTenant('tenant_builder_1');
ok('M12 _proposed_at registrado en el draft', !empty($drafts[0]['_proposed_at'] ?? ''));

// ─── M13-M18: publishApp() — flujo correcto ──────────────────────────────────

$pubResult = $manager->publishApp('ferreteria_pro', 'tenant_builder_1');
ok('M13 publishApp() retorna ok=true', $pubResult['ok'] === true);
ok('M14 app publicada tiene status:available', ($pubResult['app']['status'] ?? '') === 'available');
ok('M15 app publicada tiene _published_at', !empty($pubResult['app']['_published_at'] ?? ''));

// Ahora sí debe aparecer en marketplace
$availableApps = array_filter($manager->listApps(), fn($a) => ($a['status'] ?? '') === 'available');
ok('M16 tras publicar, app aparece en marketplace', in_array('ferreteria_pro', array_column(array_values($availableApps), 'id'), true));

// Intentar publicar dos veces
$dupPub = $manager->publishApp('ferreteria_pro', 'tenant_builder_1');
ok('M17 publicar dos veces retorna error (ya publicada)', $dupPub['ok'] === false);

// Draft eliminado del listado de drafts del builder
$draftsPostPublish = $manager->getDraftsByTenant('tenant_builder_1');
ok('M18 tras publicar, ya no aparece en mis borradores', count($draftsPostPublish) === 0);

// ─── M19-M21: Ownership — otro tenant NO puede publicar app ajena ─────────────

// Proponer una segunda app
$manager->proposeApp([
    'id' => 'agencia_laser', 'name' => 'Agencia Láser',
    'category' => 'Servicios', 'sector' => 'servicios',
    'description' => 'Gestión de corte láser',
    'enabled_agents' => ['CRM'], 'modules' => ['crm'], 'config_fields' => ['nit'],
], 'tenant_builder_1');

$stolen = $manager->publishApp('agencia_laser', 'tenant_intruso');
ok('M19 tenant intruso NO puede publicar app ajena (owner check)', $stolen['ok'] === false);
ok('M20 mensaje de error menciona permiso', str_contains(strtolower($stolen['error'] ?? ''), 'permiso'));

// El dueño sí puede publicarla
$ownPublish = $manager->publishApp('agencia_laser', 'tenant_builder_1');
ok('M21 el creador correcto SÍ puede publicar', $ownPublish['ok'] === true);

// ─── M22-M24: App inexistente ─────────────────────────────────────────────────

$notFound = $manager->publishApp('app_que_no_existe', 'tenant_builder_1');
ok('M22 publishApp de app inexistente → ok=false', $notFound['ok'] === false);

// ─── M25-M27: CreateAppSkill — acción publish_to_marketplace ─────────────────

$skill   = new CreateAppSkill($manager);
$context = ['tenant_id' => 'tenant_builder_1', 'session_id' => 'test'];

// Proponer nueva app via skill
$proposeResult = $skill->handle([
    'action' => 'propose_new_type',
    'definition' => [
        'id' => 'centro_medico', 'name' => 'Centro Médico', 'category' => 'Salud',
        'sector' => 'salud', 'description' => 'Gestión de clínica',
        'enabled_agents' => ['CRM'], 'modules' => ['crm'], 'config_fields' => ['nit'],
    ],
], $context);

ok('M25 CreateAppSkill propose_new_type retorna ok', ($proposeResult['ok'] ?? false) === true);
ok('M26 respuesta menciona "borrador" o "draft" o "privado"', str_contains($proposeResult['reply'] ?? '', 'borrador') || str_contains($proposeResult['reply'] ?? '', 'privado'));

// Publicar via skill
$publishResult = $skill->handle([
    'action' => 'publish_to_marketplace',
    'app_id' => 'centro_medico',
], $context);

ok('M27 CreateAppSkill publish_to_marketplace funciona', ($publishResult['ok'] ?? false) === true);

// ─── M28: proposeApp sin tenantId — app base SUKI (sin owner) puede publicarse ─

$manager2 = new AppCatalogManager($tmpCatalog);
$noOwnerResult = $manager2->publishApp('centro_medico', '');  // sin tenant = permitido
// Ya está published, debería dar error "ya publicada"
ok('M28 app ya published → error al volver a publicar', $noOwnerResult['ok'] === false);

// ─── Limpieza ─────────────────────────────────────────────────────────────────

@unlink($tmpCatalog);

// ─── Resultado ───────────────────────────────────────────────────────────────

$total = $passed + $failed;
echo "\n--- Resultado: {$passed}/{$total} PASS";
if ($failed > 0) {
    echo " ({$failed} FAIL)";
}
echo " ---\n";
exit($failed > 0 ? 1 : 0);
