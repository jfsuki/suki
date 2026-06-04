<?php

declare(strict_types=1);

// framework/scripts/migrate_rls_columns.php
// Additive migration: adds created_by + visibility_roles to sensitive tables.
// Only ADD COLUMN — never DROP or MODIFY.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

$pdo = \App\Core\Database::connection();

/**
 * @param list<string> $rows
 */
function columnExists(\PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $stmt !== false && $stmt->rowCount() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

function tableExists(\PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 0");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

$tables = ['journal_entries', 'app_tenant_config', 'ai_agents'];

foreach ($tables as $table) {
    if (!tableExists($pdo, $table)) {
        echo "[SKIP] Tabla `{$table}` no existe — se omite." . PHP_EOL;
        continue;
    }

    if (!columnExists($pdo, $table, 'created_by')) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `created_by` VARCHAR(64) DEFAULT NULL");
        echo "[OK]   `{$table}`.created_by agregada." . PHP_EOL;
    } else {
        echo "[SKIP] `{$table}`.created_by ya existe." . PHP_EOL;
    }

    if (!columnExists($pdo, $table, 'visibility_roles')) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `visibility_roles` JSON DEFAULT NULL");
        echo "[OK]   `{$table}`.visibility_roles agregada." . PHP_EOL;
    } else {
        echo "[SKIP] `{$table}`.visibility_roles ya existe." . PHP_EOL;
    }
}

echo PHP_EOL . "Migración RLS completada." . PHP_EOL;
