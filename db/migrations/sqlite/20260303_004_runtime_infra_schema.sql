-- Runtime infrastructure schema hardening (SQLite)
-- Scope: registry + security + observability + memory + integration + audit (non-queue modules)
-- JSON payloads are stored as TEXT and validated in application layer.

CREATE TABLE IF NOT EXISTS projects (
    id TEXT PRIMARY KEY,
    name TEXT,
    status TEXT,
    tenant_mode TEXT,
    storage_model TEXT DEFAULT 'legacy',
    owner_user_id TEXT,
    created_at TEXT,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    label TEXT,
    type TEXT,
    role TEXT,
    tenant_id TEXT,
    created_at TEXT,
    last_seen TEXT
);

CREATE TABLE IF NOT EXISTS project_users (
    project_id TEXT,
    user_id TEXT,
    role TEXT,
    created_at TEXT,
    PRIMARY KEY(project_id, user_id)
);

CREATE TABLE IF NOT EXISTS entities (
    project_id TEXT,
    entity_name TEXT,
    source TEXT,
    created_at TEXT,
    PRIMARY KEY(project_id, entity_name)
);

CREATE TABLE IF NOT EXISTS chat_sessions (
    session_id TEXT PRIMARY KEY,
    user_id TEXT,
    project_id TEXT,
    tenant_id TEXT,
    channel TEXT,
    last_message_at TEXT
);

CREATE TABLE IF NOT EXISTS auth_users (
    id TEXT,
    project_id TEXT,
    label TEXT,
    role TEXT,
    tenant_id TEXT,
    password_hash TEXT,
    created_at TEXT,
    last_login TEXT,
    PRIMARY KEY(id, project_id)
);

CREATE TABLE IF NOT EXISTS auth_codes (
    project_id TEXT,
    phone TEXT,
    code TEXT,
    created_at TEXT,
    PRIMARY KEY(project_id, phone)
);

CREATE TABLE IF NOT EXISTS deploys (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    name TEXT,
    env TEXT,
    url TEXT,
    status TEXT,
    created_at TEXT
);

CREATE TABLE IF NOT EXISTS api_rate_limits (
    bucket_key TEXT PRIMARY KEY,
    window_start INTEGER NOT NULL,
    request_count INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS webhook_replay_guard (
    channel TEXT NOT NULL,
    nonce TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    PRIMARY KEY (channel, nonce)
);
CREATE INDEX IF NOT EXISTS idx_webhook_replay_expires_at
    ON webhook_replay_guard (expires_at);

CREATE TABLE IF NOT EXISTS ops_intent_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    session_id TEXT NOT NULL DEFAULT '',
    mode TEXT NOT NULL,
    intent TEXT NOT NULL,
    action TEXT NOT NULL,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ops_intent_scope
    ON ops_intent_metrics (tenant_id, project_id, created_at);
CREATE INDEX IF NOT EXISTS idx_ops_intent_session
    ON ops_intent_metrics (tenant_id, project_id, session_id, created_at);

CREATE TABLE IF NOT EXISTS ops_command_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    session_id TEXT NOT NULL DEFAULT '',
    mode TEXT NOT NULL,
    command_name TEXT NOT NULL,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    blocked INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ops_command_scope
    ON ops_command_metrics (tenant_id, project_id, created_at);
CREATE INDEX IF NOT EXISTS idx_ops_command_session
    ON ops_command_metrics (tenant_id, project_id, session_id, created_at);

CREATE TABLE IF NOT EXISTS ops_guardrail_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    session_id TEXT NOT NULL DEFAULT '',
    mode TEXT NOT NULL,
    guardrail TEXT NOT NULL,
    reason TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ops_guardrail_scope
    ON ops_guardrail_events (tenant_id, project_id, created_at);
CREATE INDEX IF NOT EXISTS idx_ops_guardrail_session
    ON ops_guardrail_events (tenant_id, project_id, session_id, created_at);

CREATE TABLE IF NOT EXISTS ops_token_usage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    session_id TEXT NOT NULL DEFAULT '',
    provider TEXT NOT NULL,
    prompt_tokens INTEGER NOT NULL DEFAULT 0,
    completion_tokens INTEGER NOT NULL DEFAULT 0,
    total_tokens INTEGER NOT NULL DEFAULT 0,
    estimated_cost_usd REAL NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ops_tokens_scope
    ON ops_token_usage (tenant_id, project_id, created_at);
CREATE INDEX IF NOT EXISTS idx_ops_tokens_session
    ON ops_token_usage (tenant_id, project_id, session_id, created_at);

CREATE TABLE IF NOT EXISTS agent_decision_traces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    session_id TEXT NOT NULL DEFAULT '',
    route_path TEXT NOT NULL,
    selected_module TEXT NOT NULL,
    selected_action TEXT NOT NULL,
    evidence_source TEXT NOT NULL,
    ambiguity_detected INTEGER NOT NULL DEFAULT 0,
    fallback_llm INTEGER NOT NULL DEFAULT 0,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    result_status TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_agent_decision_scope
    ON agent_decision_traces (tenant_id, project_id, created_at);
CREATE INDEX IF NOT EXISTS idx_agent_decision_session
    ON agent_decision_traces (tenant_id, project_id, session_id, created_at);
CREATE INDEX IF NOT EXISTS idx_agent_decision_module
    ON agent_decision_traces (tenant_id, project_id, selected_module, created_at);

CREATE TABLE IF NOT EXISTS tool_execution_traces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    module_key TEXT NOT NULL,
    action_key TEXT NOT NULL,
    input_schema_valid INTEGER NOT NULL DEFAULT 0,
    permission_check TEXT NOT NULL,
    plan_check TEXT NOT NULL,
    execution_latency INTEGER NOT NULL DEFAULT 0,
    success INTEGER NOT NULL DEFAULT 0,
    error_code TEXT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_tool_execution_scope
    ON tool_execution_traces (tenant_id, project_id, created_at);
CREATE INDEX IF NOT EXISTS idx_tool_execution_module
    ON tool_execution_traces (tenant_id, project_id, module_key, created_at);
CREATE INDEX IF NOT EXISTS idx_tool_execution_status
    ON tool_execution_traces (tenant_id, project_id, success, created_at);

CREATE TABLE IF NOT EXISTS mem_global (
    category TEXT NOT NULL,
    key_name TEXT NOT NULL,
    value_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (category, key_name)
);
CREATE INDEX IF NOT EXISTS idx_mem_global_category_updated
    ON mem_global (category, updated_at);

CREATE TABLE IF NOT EXISTS mem_tenant (
    tenant_id TEXT NOT NULL,
    key_name TEXT NOT NULL,
    value_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (tenant_id, key_name)
);
CREATE INDEX IF NOT EXISTS idx_mem_tenant_updated
    ON mem_tenant (tenant_id, updated_at);

CREATE TABLE IF NOT EXISTS mem_user (
    tenant_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    key_name TEXT NOT NULL,
    value_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (tenant_id, user_id, key_name)
);
CREATE INDEX IF NOT EXISTS idx_mem_user_updated
    ON mem_user (tenant_id, user_id, updated_at);

CREATE TABLE IF NOT EXISTS chat_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    session_id TEXT NOT NULL,
    channel TEXT NOT NULL,
    direction TEXT NOT NULL,
    message TEXT NULL,
    meta_json TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_chat_session
    ON chat_log (tenant_id, session_id, created_at);
CREATE INDEX IF NOT EXISTS idx_chat_user
    ON chat_log (tenant_id, user_id, created_at);

CREATE TABLE IF NOT EXISTS integration_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    integration_id TEXT NOT NULL,
    provider TEXT NOT NULL,
    type TEXT NOT NULL,
    country TEXT NOT NULL,
    environment TEXT NOT NULL,
    base_url TEXT NOT NULL,
    token_env TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_integration_connections_id
    ON integration_connections (integration_id);
CREATE INDEX IF NOT EXISTS idx_integration_connections_provider_env
    ON integration_connections (provider, environment);

CREATE TABLE IF NOT EXISTS integration_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    integration_id TEXT NOT NULL,
    token_env TEXT NULL,
    expires_at TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_integration_tokens_scope
    ON integration_tokens (integration_id, created_at);

CREATE TABLE IF NOT EXISTS integration_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    integration_id TEXT NOT NULL,
    entity TEXT NULL,
    record_id TEXT NULL,
    external_id TEXT NULL,
    status TEXT NULL,
    request_payload TEXT NULL,
    response_payload TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_integration_documents_external
    ON integration_documents (integration_id, external_id);
CREATE INDEX IF NOT EXISTS idx_integration_documents_entity_record
    ON integration_documents (integration_id, entity, record_id);

CREATE TABLE IF NOT EXISTS integration_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    integration_id TEXT NOT NULL,
    action TEXT NOT NULL,
    payload TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    next_run_at TEXT NULL,
    last_error TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_integration_outbox_status_next
    ON integration_outbox (status, next_run_at);
CREATE INDEX IF NOT EXISTS idx_integration_outbox_created
    ON integration_outbox (integration_id, created_at);

CREATE TABLE IF NOT EXISTS integration_webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    integration_id TEXT NOT NULL,
    event TEXT NULL,
    external_id TEXT NULL,
    payload TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_integration_webhooks_scope
    ON integration_webhooks (integration_id, created_at);
CREATE INDEX IF NOT EXISTS idx_integration_webhooks_external
    ON integration_webhooks (integration_id, external_id);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT NOT NULL,
    entity TEXT NOT NULL,
    record_id TEXT NULL,
    tenant_id INTEGER NULL,
    actor_id TEXT NULL,
    actor_label TEXT NULL,
    payload TEXT NULL,
    created_at TEXT NOT NULL
);
