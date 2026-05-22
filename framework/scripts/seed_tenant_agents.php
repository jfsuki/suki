<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

/**
 * Seed de ai_agents para todos los tenants activos.
 * Idempotente — puede correrse múltiples veces sin duplicar.
 *
 * Fuentes de tenants:
 *   - SQLite (ProjectRegistry/auth_users): tenants con usuarios registrados
 *   - MySQL (app_tenant_config): tenants con apps instaladas
 */

$registry = new \App\Core\ProjectRegistry();
$sqliteDb = $registry->db();

echo "=== SEED AI_AGENTS PARA TENANTS ACTIVOS ===\n\n";

// 1. Tenants desde SQLite auth_users
$tenantsFromAuth = [];
try {
    $stmtAuth        = $sqliteDb->query('SELECT DISTINCT tenant_id FROM auth_users WHERE is_active = 1 LIMIT 100');
    $tenantsFromAuth = $stmtAuth ? $stmtAuth->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    echo "  [warn] auth_users query failed: {$e->getMessage()}\n";
}

// 2. Tenants con apps instaladas desde MySQL (app_tenant_config)
$tenantsFromConfig = [];
$tenantAppMap      = [];
try {
    $mysqlDb   = \App\Core\Database::connection();
    $stmtApps  = $mysqlDb->query(
        "SELECT tenant_id, field_value AS app_id FROM app_tenant_config WHERE field_key = '_installed_app_id' LIMIT 100"
    );
    if ($stmtApps) {
        foreach ($stmtApps->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tid                  = (string) $row['tenant_id'];
            $tenantsFromConfig[]  = $tid;
            $tenantAppMap[$tid]   = (string) $row['app_id'];
        }
    }
} catch (\Throwable $e) {
    echo "  [warn] MySQL app_tenant_config query failed: {$e->getMessage()}\n";
}

$allTenants = array_unique(array_filter(array_merge($tenantsFromConfig, $tenantsFromAuth)));
echo "Tenants encontrados: " . count($allTenants) . "\n\n";

if (empty($allTenants)) {
    // Ambiente de desarrollo: seed al tenant 'default'
    $allTenants = ['default'];
    echo "No hay tenants registrados. Seeding 'default' para desarrollo.\n\n";
}

$service      = new \App\Core\AppInstallService($registry);
$totalSeeded  = 0;
$totalSkipped = 0;

foreach ($allTenants as $tenantId) {
    $appId = $tenantAppMap[$tenantId] ?? 'suki_erp';

    echo "Tenant: {$tenantId} -> App: {$appId}\n";

    $result = $service->seedAgents((string) $tenantId, $appId);

    if (isset($result['error'])) {
        echo "  [WARN] {$result['error']}\n";
        continue;
    }

    $seeded  = (int) $result['seeded'];
    $skipped = (int) $result['skipped'];
    echo "  Seeded: {$seeded} | Skipped: {$skipped}\n";

    foreach ($result['agents_created'] ?? [] as $a) {
        echo "    - [{$a['area']}] {$a['agent_id']} ({$a['status']})\n";
    }

    $totalSeeded  += $seeded;
    $totalSkipped += $skipped;
}

$totalInDb = (int) $sqliteDb->query('SELECT COUNT(*) FROM ai_agents')->fetchColumn();

echo "\n=== RESUMEN ===\n";
echo "Total agentes creados:          {$totalSeeded}\n";
echo "Total agentes existentes (skip): {$totalSkipped}\n";
echo "Total en ai_agents (SQLite):     {$totalInDb}\n";
echo ($totalInDb > 0 ? "PASS\n" : "FAIL — no hay agentes en ai_agents\n");
