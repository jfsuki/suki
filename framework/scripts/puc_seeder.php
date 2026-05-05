<?php
/**
 * puc_seeder.php — Activa cuentas PUC colombianas y roles contables para un tenant.
 *
 * Uso: php framework/scripts/puc_seeder.php <tenant_id>
 *
 * Los códigos a activar se leen del catálogo PUC nacional (puc_colombia_base.json)
 * y los roles por defecto de accounting_roles_defaults.json — sin hardcode aquí.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/autoload.php';

use App\Core\AccountingRepository;

$tenantId = $argv[1] ?? '';
if ($tenantId === '') {
    fwrite(STDERR, "Uso: php framework/scripts/puc_seeder.php <tenant_id>\n");
    exit(1);
}

$repo = new AccountingRepository();

echo "Seeding PUC colombiano para tenant: {$tenantId}\n";

// seedDefaultRolesForTenant activa las cuentas requeridas por cada rol + inserta la config de roles.
// Los códigos vienen de framework/data/accounting_roles_defaults.json — no hardcodeados aquí.
$insertedRoles = $repo->seedDefaultRolesForTenant($tenantId);
echo "Roles contables insertados/verificados: {$insertedRoles}\n";
echo "Done.\n";
