-- Runtime infrastructure schema hardening (MySQL)
-- Scope: audit + integration + sql memory (non-queue modules)
-- Policy: runtime schema mutations must be disabled in prod; apply this migration instead.

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(32) NOT NULL,
    entity VARCHAR(128) NOT NULL,
    record_id VARCHAR(64) NULL,
    tenant_id INT NULL,
    actor_id VARCHAR(64) NULL,
    actor_label VARCHAR(128) NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_id VARCHAR(64) NOT NULL,
    provider VARCHAR(64) NOT NULL,
    type VARCHAR(32) NOT NULL,
    country VARCHAR(8) NOT NULL,
    environment VARCHAR(16) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    token_env VARCHAR(128) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_id VARCHAR(64) NOT NULL,
    token_env VARCHAR(128) NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_id VARCHAR(64) NOT NULL,
    entity VARCHAR(64) NULL,
    record_id VARCHAR(64) NULL,
    external_id VARCHAR(64) NULL,
    status VARCHAR(32) NULL,
    request_payload JSON NULL,
    response_payload JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_outbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_id VARCHAR(64) NOT NULL,
    action VARCHAR(64) NOT NULL,
    payload JSON NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    next_run_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_id VARCHAR(64) NOT NULL,
    event VARCHAR(64) NULL,
    external_id VARCHAR(64) NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mem_global (
    category VARCHAR(64) NOT NULL,
    key_name VARCHAR(128) NOT NULL,
    value_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (category, key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mem_tenant (
    tenant_id VARCHAR(120) NOT NULL,
    key_name VARCHAR(128) NOT NULL,
    value_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (tenant_id, key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mem_user (
    tenant_id VARCHAR(120) NOT NULL,
    user_id VARCHAR(190) NOT NULL,
    key_name VARCHAR(128) NOT NULL,
    value_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (tenant_id, user_id, key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    user_id VARCHAR(190) NOT NULL,
    session_id VARCHAR(190) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    direction VARCHAR(8) NOT NULL,
    message TEXT NULL,
    meta_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS agent_decision_traces (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    project_id VARCHAR(190) NOT NULL,
    session_id VARCHAR(190) NOT NULL DEFAULT '',
    route_path VARCHAR(255) NOT NULL,
    selected_module VARCHAR(120) NOT NULL,
    selected_action VARCHAR(190) NOT NULL,
    evidence_source VARCHAR(120) NOT NULL,
    ambiguity_detected TINYINT(1) NOT NULL DEFAULT 0,
    fallback_llm TINYINT(1) NOT NULL DEFAULT 0,
    latency_ms INT NOT NULL DEFAULT 0,
    result_status VARCHAR(64) NOT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tool_execution_traces (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    project_id VARCHAR(190) NOT NULL,
    module_key VARCHAR(120) NOT NULL,
    action_key VARCHAR(190) NOT NULL,
    input_schema_valid TINYINT(1) NOT NULL DEFAULT 0,
    permission_check VARCHAR(64) NOT NULL,
    plan_check VARCHAR(64) NOT NULL,
    execution_latency INT NOT NULL DEFAULT 0,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_code VARCHAR(128) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @db_name := DATABASE();

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'integration_connections' AND index_name = 'uq_integration_connections_id'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE integration_connections ADD UNIQUE KEY uq_integration_connections_id (integration_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'integration_connections' AND index_name = 'idx_integration_connections_provider_env'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE integration_connections ADD KEY idx_integration_connections_provider_env (provider, environment)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'integration_tokens' AND index_name = 'idx_integration_tokens_scope'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE integration_tokens ADD KEY idx_integration_tokens_scope (integration_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'integration_documents' AND index_name = 'idx_integration_documents_external'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE integration_documents ADD KEY idx_integration_documents_external (integration_id, external_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'integration_documents' AND index_name = 'idx_integration_documents_entity_record'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE integration_documents ADD KEY idx_integration_documents_entity_record (integration_id, entity, record_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'integration_outbox' AND index_name = 'idx_integration_outbox_status_next'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE integration_outbox ADD KEY idx_integration_outbox_status_next (status, next_run_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'integration_outbox' AND index_name = 'idx_integration_outbox_created'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE integration_outbox ADD KEY idx_integration_outbox_created (integration_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'integration_webhooks' AND index_name = 'idx_integration_webhooks_scope'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE integration_webhooks ADD KEY idx_integration_webhooks_scope (integration_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'integration_webhooks' AND index_name = 'idx_integration_webhooks_external'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE integration_webhooks ADD KEY idx_integration_webhooks_external (integration_id, external_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'mem_global' AND index_name = 'idx_mem_global_category_updated'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE mem_global ADD KEY idx_mem_global_category_updated (category, updated_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'mem_tenant' AND index_name = 'idx_mem_tenant_updated'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE mem_tenant ADD KEY idx_mem_tenant_updated (tenant_id, updated_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'mem_user' AND index_name = 'idx_mem_user_updated'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE mem_user ADD KEY idx_mem_user_updated (tenant_id, user_id, updated_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'chat_log' AND index_name = 'idx_chat_session'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE chat_log ADD KEY idx_chat_session (tenant_id, session_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'chat_log' AND index_name = 'idx_chat_user'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE chat_log ADD KEY idx_chat_user (tenant_id, user_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'agent_decision_traces' AND index_name = 'idx_agent_decision_scope'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE agent_decision_traces ADD KEY idx_agent_decision_scope (tenant_id, project_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'agent_decision_traces' AND index_name = 'idx_agent_decision_session'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE agent_decision_traces ADD KEY idx_agent_decision_session (tenant_id, project_id, session_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'agent_decision_traces' AND index_name = 'idx_agent_decision_module'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE agent_decision_traces ADD KEY idx_agent_decision_module (tenant_id, project_id, selected_module, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'tool_execution_traces' AND index_name = 'idx_tool_execution_scope'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tool_execution_traces ADD KEY idx_tool_execution_scope (tenant_id, project_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'tool_execution_traces' AND index_name = 'idx_tool_execution_module'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tool_execution_traces ADD KEY idx_tool_execution_module (tenant_id, project_id, module_key, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @db_name AND table_name = 'tool_execution_traces' AND index_name = 'idx_tool_execution_status'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tool_execution_traces ADD KEY idx_tool_execution_status (tenant_id, project_id, success, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
