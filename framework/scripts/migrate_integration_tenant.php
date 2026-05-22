<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

$pdo = \App\Core\Database::connection();
$dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn());

// Primero hacer que IntegrationMigrator cree las tablas si no existen
$migrator = new \App\Core\IntegrationMigrator($pdo);
$migrator->bootstrapSchemaPolicy();
echo "Tablas base de integración: verificadas" . PHP_EOL;

/**
 * Añade una columna solo si no existe ya en la tabla.
 */
function addColumnIfMissing(\PDO $pdo, string $dbName, string $table, string $column, string $def): void
{
    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'"
    )->fetchColumn();

    if ($exists > 0) {
        echo "  SKIP (ya existe): {$table}.{$column}" . PHP_EOL;
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$def}");
    echo "  COLUMNA AÑADIDA: {$table}.{$column}" . PHP_EOL;
}

/**
 * Añade un índice solo si no existe ya.
 */
function addIndexIfMissingInteg(\PDO $pdo, string $dbName, string $table, string $indexName, string $column): void
{
    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$indexName}'"
    )->fetchColumn();

    if ($exists > 0) {
        echo "  SKIP (índice ya existe): {$table}.{$indexName}" . PHP_EOL;
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`{$column}`)");
    echo "  ÍNDICE CREADO: {$table}.{$indexName}" . PHP_EOL;
}

$tables = [
    'integration_connections',
    'integration_documents',
    'integration_webhooks',
    'integration_tokens',
];

$indexMap = [
    'integration_connections' => 'idx_int_conn_tenant',
    'integration_documents'   => 'idx_int_docs_tenant',
    'integration_webhooks'    => 'idx_int_wh_tenant',
    'integration_tokens'      => 'idx_int_tok_tenant',
];

echo "Añadiendo tenant_id a tablas de integración..." . PHP_EOL;

foreach ($tables as $table) {
    $tableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}'"
    )->fetchColumn();

    if ($tableExists === 0) {
        echo "  SKIP (tabla no existe): {$table}" . PHP_EOL;
        continue;
    }

    addColumnIfMissing($pdo, $dbName, $table, 'tenant_id',
        "VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Tenant owner'");

    if (isset($indexMap[$table])) {
        addIndexIfMissingInteg($pdo, $dbName, $table, $indexMap[$table], 'tenant_id');
    }
}

// Verificación final
echo PHP_EOL . "Verificación final:" . PHP_EOL;
$check = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = 'integration_connections'
     AND COLUMN_NAME = 'tenant_id'"
)->fetchColumn();
echo "integration_connections.tenant_id: " . ($check ? 'OK' : 'FALLO') . PHP_EOL;
