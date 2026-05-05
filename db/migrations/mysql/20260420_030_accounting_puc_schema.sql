-- Migration: 20260420_030_accounting_puc_schema.sql
-- Extiende el módulo contable para soportar PUC colombiano real.
-- NOTA: Ejecutar con el runner PHP: php db/migrations/run_migration.php 20260420_030
-- Las sentencias ADD COLUMN sin IF NOT EXISTS se ejecutan condicionalmente desde PHP
-- verificando INFORMATION_SCHEMA para compatibilidad con MySQL 8.x (no MariaDB).

CREATE TABLE IF NOT EXISTS `puc_nacional` (
    `id`        INT AUTO_INCREMENT PRIMARY KEY,
    `codigo`    VARCHAR(16)  NOT NULL,
    `nombre`    VARCHAR(255) NOT NULL,
    `tipo`      VARCHAR(50)  NOT NULL,
    `naturaleza` ENUM('DEBITO','CREDITO') NOT NULL,
    `nivel`     ENUM('clase','grupo','cuenta','subcuenta') NOT NULL,
    `parent`    VARCHAR(16)  NULL,
    UNIQUE KEY `uq_puc_codigo` (`codigo`),
    INDEX `idx_puc_codigo` (`codigo`),
    INDEX `idx_puc_nivel`  (`nivel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `parametros_contables_tenant` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     VARCHAR(120) NOT NULL,
    `rol`           VARCHAR(80)  NOT NULL,
    `codigo_cuenta` VARCHAR(20)  NOT NULL,
    `descripcion`   TEXT,
    `updated_at`    DATETIME,
    UNIQUE KEY `uq_tenant_rol` (`tenant_id`, `rol`),
    INDEX `idx_roles_tenant` (`tenant_id`, `rol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `formas_pago_config` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`    VARCHAR(120) NOT NULL,
    `medio_pago`   VARCHAR(80)  NOT NULL,
    `rol_contable` VARCHAR(80)  NOT NULL,
    `nombre`       VARCHAR(120) DEFAULT '',
    `updated_at`   DATETIME,
    UNIQUE KEY `uq_tenant_medio` (`tenant_id`, `medio_pago`),
    INDEX `idx_payment_tenant` (`tenant_id`, `medio_pago`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
