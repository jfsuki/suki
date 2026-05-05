<?php
/**
 * 20260420_030_accounting_puc_schema.php
 * Extiende cuentas_contables y asientos_contables para PUC colombiano.
 * Maneja ADD COLUMN / ADD INDEX condicionalmente vía INFORMATION_SCHEMA (MySQL 8.x).
 */

declare(strict_types=1);

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function indexExists(PDO $db, string $table, string $index): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function runMigration(PDO $db): void
{
    // ─── cuentas_contables: columnas de jerarquía PUC ─────────────────────────
    if (!columnExists($db, 'cuentas_contables', 'parent_codigo')) {
        $db->exec("ALTER TABLE `cuentas_contables` ADD COLUMN `parent_codigo` VARCHAR(20) NULL AFTER `naturaleza`");
    }
    if (!columnExists($db, 'cuentas_contables', 'nivel')) {
        $db->exec("ALTER TABLE `cuentas_contables` ADD COLUMN `nivel` VARCHAR(20) NOT NULL DEFAULT 'cuenta' AFTER `parent_codigo`");
    }
    if (!columnExists($db, 'cuentas_contables', 'es_auxiliar')) {
        $db->exec("ALTER TABLE `cuentas_contables` ADD COLUMN `es_auxiliar` TINYINT(1) NOT NULL DEFAULT 0 AFTER `nivel`");
    }
    if (!columnExists($db, 'cuentas_contables', 'activa')) {
        $db->exec("ALTER TABLE `cuentas_contables` ADD COLUMN `activa` TINYINT(1) NOT NULL DEFAULT 1 AFTER `es_auxiliar`");
    }

    // ─── cuentas_contables: índices canónicos ─────────────────────────────────
    if (indexExists($db, 'cuentas_contables', 'idx_tenant')) {
        $db->exec("ALTER TABLE `cuentas_contables` DROP INDEX `idx_tenant`");
    }
    if (indexExists($db, 'cuentas_contables', 'idx_codigo')) {
        $db->exec("ALTER TABLE `cuentas_contables` DROP INDEX `idx_codigo`");
    }
    if (!indexExists($db, 'cuentas_contables', 'idx_accounts_tenant')) {
        $db->exec("ALTER TABLE `cuentas_contables` ADD INDEX `idx_accounts_tenant` (`tenant_id`, `activa`)");
    }
    if (!indexExists($db, 'cuentas_contables', 'idx_accounts_code')) {
        $db->exec("ALTER TABLE `cuentas_contables` ADD INDEX `idx_accounts_code` (`tenant_id`, `codigo`)");
    }

    // ─── asientos_contables: campos FE electrónica ────────────────────────────
    if (!columnExists($db, 'asientos_contables', 'is_electronic')) {
        $db->exec("ALTER TABLE `asientos_contables` ADD COLUMN `is_electronic` TINYINT(1) NOT NULL DEFAULT 0 AFTER `usuario_id`");
    }
    if (!columnExists($db, 'asientos_contables', 'cufe')) {
        $db->exec("ALTER TABLE `asientos_contables` ADD COLUMN `cufe` VARCHAR(200) NOT NULL DEFAULT '' AFTER `is_electronic`");
    }
    if (!columnExists($db, 'asientos_contables', 'doc_type')) {
        $db->exec("ALTER TABLE `asientos_contables` ADD COLUMN `doc_type` VARCHAR(64) NOT NULL DEFAULT 'manual' AFTER `cufe`");
    }

    // ─── asientos_contables: índices ──────────────────────────────────────────
    if (!indexExists($db, 'asientos_contables', 'idx_journal_tenant')) {
        $db->exec("ALTER TABLE `asientos_contables` ADD INDEX `idx_journal_tenant` (`tenant_id`, `fecha`)");
    }
    if (!indexExists($db, 'asientos_contables', 'idx_journal_date')) {
        $db->exec("ALTER TABLE `asientos_contables` ADD INDEX `idx_journal_date` (`tenant_id`, `is_electronic`, `fecha`)");
    }

    echo "Migration 20260420_030 applied.\n";
}

return static function (PDO $db): void {
    runMigration($db);
};
