-- Minimal SQL tables for memory + chat logs (multi-tenant)

CREATE TABLE IF NOT EXISTS mem_global (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(64) NOT NULL,
  key_name VARCHAR(128) NOT NULL,
  value_json JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_mem_global_cat (category, updated_at)
);

CREATE TABLE IF NOT EXISTS mem_tenant (
  tenant_id BIGINT UNSIGNED NOT NULL,
  key_name VARCHAR(128) NOT NULL,
  value_json JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, key_name)
);

CREATE TABLE IF NOT EXISTS mem_user (
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  key_name VARCHAR(128) NOT NULL,
  value_json JSON NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, user_id, key_name)
);

CREATE TABLE IF NOT EXISTS mem_action_cache (
  tenant_id BIGINT UNSIGNED NOT NULL,
  intent_hash CHAR(64) NOT NULL,
  action_json JSON NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, intent_hash),
  INDEX idx_action_cache_tenant (tenant_id, updated_at)
);

CREATE TABLE IF NOT EXISTS chat_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  session_id VARCHAR(64) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  direction ENUM('in','out') NOT NULL,
  message TEXT,
  meta_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chat_session (tenant_id, session_id, created_at),
  INDEX idx_chat_user (tenant_id, user_id, created_at)
);

-- Optional: outbox for long tasks
CREATE TABLE IF NOT EXISTS outbox_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  status ENUM('pending','running','done','error') DEFAULT 'pending',
  run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  attempts INT UNSIGNED DEFAULT 0,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outbox (tenant_id, status, run_at)
);
