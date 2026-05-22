-- tenant_id aditivo en todas las tablas de integración
-- DEFAULT '' para registros históricos — no rompe nada existente
-- MySQL no tiene ALTER TABLE ... ADD COLUMN IF NOT EXISTS en todas las versiones
-- El script PHP migrate_integration_tenant.php maneja la idempotencia

ALTER TABLE integration_connections ADD COLUMN
    tenant_id VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Tenant owner of this integration' AFTER id;

ALTER TABLE integration_documents ADD COLUMN
    tenant_id VARCHAR(64) NOT NULL DEFAULT '' AFTER id;

ALTER TABLE integration_webhooks ADD COLUMN
    tenant_id VARCHAR(64) NOT NULL DEFAULT '' AFTER id;

ALTER TABLE integration_tokens ADD COLUMN
    tenant_id VARCHAR(64) NOT NULL DEFAULT '' AFTER id;

-- Índices de tenant para queries rápidas
CREATE INDEX idx_int_conn_tenant ON integration_connections (tenant_id);
CREATE INDEX idx_int_docs_tenant ON integration_documents   (tenant_id);
CREATE INDEX idx_int_wh_tenant   ON integration_webhooks    (tenant_id);
CREATE INDEX idx_int_tok_tenant  ON integration_tokens      (tenant_id);
