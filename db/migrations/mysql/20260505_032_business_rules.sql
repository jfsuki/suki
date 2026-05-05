-- Migration: 20260505_032_business_rules.sql
-- Multi-scope business rules table.
-- Replaces static business_rules.json as the authoritative rules store.
--
-- scope = 'universal'  → aplica a todos los tenants y apps (creada desde Torre)
-- scope = 'app'        → aplica a todas las empresas que usen esa app (creada desde Builder)
-- scope = 'tenant'     → aplica solo a esa empresa (creada desde el chat de esa empresa)
--
-- Prioridad de evaluación: tenant > app > universal (mismo id = más específico gana)
--
-- tenant_id y app_id usan '' (vacío) en lugar de NULL para que el UNIQUE KEY funcione.

CREATE TABLE IF NOT EXISTS business_rules (
    rule_pk     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id          VARCHAR(64)     NOT NULL DEFAULT '',
    scope       ENUM('universal', 'app', 'tenant') NOT NULL DEFAULT 'universal',
    tenant_id   VARCHAR(64)     NOT NULL DEFAULT '',
    app_id      VARCHAR(64)     NOT NULL DEFAULT '',
    event_type  VARCHAR(64)     NOT NULL,
    rule_type   VARCHAR(64)     NOT NULL DEFAULT 'legacy',
    params_json JSON            DEFAULT NULL,
    enabled     TINYINT(1)      NOT NULL DEFAULT 1,
    description VARCHAR(255)    DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rule_pk),
    UNIQUE  KEY uk_br_identity (id, scope, tenant_id, app_id),
    INDEX idx_br_lookup (scope, tenant_id, app_id, enabled),
    INDEX idx_br_event  (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
