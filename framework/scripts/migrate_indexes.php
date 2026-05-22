<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

$pdo = \App\Core\Database::connection();

$dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn());

/**
 * Añade un índice solo si no existe ya en la tabla.
 * Idempotente — puede ejecutarse múltiples veces.
 */
function addIndexIfMissing(\PDO $pdo, string $dbName, string $table, string $indexName, string $column): void
{
    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = '{$dbName}'
         AND TABLE_NAME = '{$table}'
         AND INDEX_NAME = '{$indexName}'"
    )->fetchColumn();

    if ($exists > 0) {
        echo "  SKIP (ya existe): {$table}.{$indexName}" . PHP_EOL;
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`{$column}`)");
    echo "  CREADO: {$table}.{$indexName}" . PHP_EOL;
}

$indexes = [
    ['p_37a8eec1ce__asiento_lineas',             'idx_p37_alineas_tid',  'tenant_id'],
    ['p_37a8eec1ce__asiento_lineas',             'idx_p37_alineas_cat',  'created_at'],
    ['p_37a8eec1ce__asientos_contables',         'idx_p37_asientos_cat', 'created_at'],
    ['p_37a8eec1ce__cuentas_contables',          'idx_p37_cuentas_cat',  'created_at'],
    ['p_37a8eec1ce__golden_clientes_1771706711s','idx_p37_golden_tid',   'tenant_id'],
    ['p_37a8eec1ce__golden_clientes_1771706711s','idx_p37_golden_cat',   'created_at'],
    ['p_37a8eec1ce__kardex',                     'idx_p37_kardex_tid',   'tenant_id'],
    ['p_37a8eec1ce__kardex',                     'idx_p37_kardex_cat',   'created_at'],
];

echo "Creando índices faltantes..." . PHP_EOL;
foreach ($indexes as [$table, $indexName, $column]) {
    try {
        // Verificar que la tabla existe antes de intentar el ALTER
        $tableExists = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}'"
        )->fetchColumn();

        if ($tableExists === 0) {
            echo "  SKIP (tabla no existe): {$table}" . PHP_EOL;
            continue;
        }

        addIndexIfMissing($pdo, $dbName, $table, $indexName, $column);
    } catch (\Throwable $e) {
        echo "  ERROR en {$table}.{$indexName}: " . $e->getMessage() . PHP_EOL;
    }
}

echo "Índices: OK" . PHP_EOL;
