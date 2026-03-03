-- Operational core schema (SQLite)
-- JSON fields are stored as TEXT; semantic validation happens at application layer.

CREATE TABLE IF NOT EXISTS event_dedupe (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    channel TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'new',
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    payload_hash TEXT NULL,
    job_id TEXT NULL,
    error_json TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_event_dedupe_tenant_channel_key
    ON event_dedupe (tenant_id, channel, idempotency_key);
CREATE INDEX IF NOT EXISTS idx_event_dedupe_tenant_first_seen
    ON event_dedupe (tenant_id, first_seen_at);
CREATE INDEX IF NOT EXISTS idx_event_dedupe_status_last_seen
    ON event_dedupe (status, last_seen_at);

CREATE TABLE IF NOT EXISTS jobs_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    job_type TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at TEXT NOT NULL,
    locked_at TEXT NULL,
    locked_by TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_jobs_queue_tenant_created
    ON jobs_queue (tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_jobs_queue_status_available
    ON jobs_queue (status, available_at);
CREATE INDEX IF NOT EXISTS idx_jobs_queue_tenant_status_available
    ON jobs_queue (tenant_id, status, available_at);

CREATE TABLE IF NOT EXISTS conversation_checkpoint (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    channel TEXT NOT NULL,
    thread_id TEXT NOT NULL,
    state_json TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_conversation_checkpoint_tenant_user_channel_thread
    ON conversation_checkpoint (tenant_id, user_id, channel, thread_id);
CREATE INDEX IF NOT EXISTS idx_conversation_checkpoint_tenant_updated
    ON conversation_checkpoint (tenant_id, updated_at);

CREATE TABLE IF NOT EXISTS agent_trace (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    user_id TEXT NULL,
    channel TEXT NULL,
    thread_id TEXT NULL,
    request_id TEXT NOT NULL,
    route_path TEXT NOT NULL,
    intent TEXT NULL,
    gate_decision TEXT NOT NULL,
    tools_used_json TEXT NULL,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    cost_estimate REAL NOT NULL DEFAULT 0,
    versions_json TEXT NULL,
    error_json TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_agent_trace_tenant_created
    ON agent_trace (tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_agent_trace_tenant_request
    ON agent_trace (tenant_id, request_id);
CREATE INDEX IF NOT EXISTS idx_agent_trace_tenant_channel_created
    ON agent_trace (tenant_id, channel, created_at);

CREATE TABLE IF NOT EXISTS memory_candidates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    user_id TEXT NULL,
    channel TEXT NULL,
    thread_id TEXT NULL,
    source_trace_id INTEGER NULL,
    candidate_type TEXT NOT NULL,
    candidate_json TEXT NOT NULL,
    hygiene_status TEXT NOT NULL DEFAULT 'pending',
    review_status TEXT NOT NULL DEFAULT 'pending',
    score REAL NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_memory_candidates_tenant_created
    ON memory_candidates (tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_memory_candidates_tenant_review
    ON memory_candidates (tenant_id, review_status, created_at);

CREATE TABLE IF NOT EXISTS memory_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    candidate_id INTEGER NOT NULL,
    reviewer_id TEXT NULL,
    review_decision TEXT NOT NULL,
    review_notes_json TEXT NULL,
    published_memory_key TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_memory_reviews_tenant_created
    ON memory_reviews (tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_memory_reviews_tenant_candidate
    ON memory_reviews (tenant_id, candidate_id);
