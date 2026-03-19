CREATE TABLE IF NOT EXISTS control_tower_tasks (
    task_id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    app_id TEXT NOT NULL,
    conversation_id TEXT NOT NULL,
    session_id TEXT NOT NULL DEFAULT '',
    user_id TEXT NOT NULL DEFAULT '',
    message_id TEXT NOT NULL DEFAULT '',
    intent TEXT NOT NULL,
    status TEXT NOT NULL,
    source TEXT NOT NULL,
    route_path TEXT NOT NULL DEFAULT '',
    gate_decision TEXT NOT NULL DEFAULT 'unknown',
    related_entities_json TEXT NOT NULL DEFAULT '[]',
    related_events_json TEXT NOT NULL DEFAULT '[]',
    execution_result_json TEXT NOT NULL DEFAULT '{}',
    idempotency_key TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_control_tower_tasks_scope
    ON control_tower_tasks (tenant_id, project_id, updated_at);

CREATE INDEX IF NOT EXISTS idx_control_tower_tasks_conversation
    ON control_tower_tasks (tenant_id, conversation_id, updated_at);

CREATE INDEX IF NOT EXISTS idx_control_tower_tasks_status
    ON control_tower_tasks (tenant_id, project_id, status, updated_at);

CREATE INDEX IF NOT EXISTS idx_control_tower_tasks_idempotency
    ON control_tower_tasks (tenant_id, conversation_id, idempotency_key);

CREATE TABLE IF NOT EXISTS control_tower_incidents (
    incident_id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    app_id TEXT NOT NULL,
    severity TEXT NOT NULL,
    source TEXT NOT NULL,
    related_task_id TEXT NOT NULL,
    related_events_json TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_control_tower_incidents_scope
    ON control_tower_incidents (tenant_id, project_id, created_at);

CREATE INDEX IF NOT EXISTS idx_control_tower_incidents_task
    ON control_tower_incidents (tenant_id, related_task_id, created_at);

CREATE TABLE IF NOT EXISTS control_tower_events (
    event_id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    app_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    source TEXT NOT NULL,
    linked_ids_json TEXT NOT NULL DEFAULT '{}',
    payload_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_control_tower_events_scope
    ON control_tower_events (tenant_id, project_id, created_at);

CREATE INDEX IF NOT EXISTS idx_control_tower_events_type
    ON control_tower_events (tenant_id, project_id, event_type, created_at);
