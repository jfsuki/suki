-- Operational core schema (MySQL)
-- Scope: persistence preparation only (no runtime integration)

CREATE TABLE IF NOT EXISTS event_dedupe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    channel VARCHAR(40) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'new',
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    payload_hash CHAR(64) NULL,
    job_id VARCHAR(191) NULL,
    error_json JSON NULL,
    UNIQUE KEY uq_event_dedupe_tenant_channel_key (tenant_id, channel, idempotency_key),
    KEY idx_event_dedupe_tenant_first_seen (tenant_id, first_seen_at),
    KEY idx_event_dedupe_status_last_seen (status, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    job_type VARCHAR(120) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    locked_at DATETIME NULL,
    locked_by VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_jobs_queue_tenant_created (tenant_id, created_at),
    KEY idx_jobs_queue_status_available (status, available_at),
    KEY idx_jobs_queue_tenant_status_available (tenant_id, status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversation_checkpoint (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    user_id VARCHAR(120) NOT NULL,
    channel VARCHAR(40) NOT NULL,
    thread_id VARCHAR(191) NOT NULL,
    state_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_conversation_checkpoint_tenant_user_channel_thread (tenant_id, user_id, channel, thread_id),
    KEY idx_conversation_checkpoint_tenant_updated (tenant_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS agent_trace (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    user_id VARCHAR(120) NULL,
    channel VARCHAR(40) NULL,
    thread_id VARCHAR(191) NULL,
    request_id VARCHAR(191) NOT NULL,
    route_path VARCHAR(120) NOT NULL,
    intent VARCHAR(120) NULL,
    gate_decision VARCHAR(60) NOT NULL,
    tools_used_json JSON NULL,
    latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
    cost_estimate DECIMAL(14, 6) NOT NULL DEFAULT 0,
    versions_json JSON NULL,
    error_json JSON NULL,
    created_at DATETIME NOT NULL,
    KEY idx_agent_trace_tenant_created (tenant_id, created_at),
    KEY idx_agent_trace_tenant_request (tenant_id, request_id),
    KEY idx_agent_trace_tenant_channel_created (tenant_id, channel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS memory_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    user_id VARCHAR(120) NULL,
    channel VARCHAR(40) NULL,
    thread_id VARCHAR(191) NULL,
    source_trace_id BIGINT UNSIGNED NULL,
    candidate_type VARCHAR(80) NOT NULL,
    candidate_json JSON NOT NULL,
    hygiene_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    review_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    score DECIMAL(8, 4) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_memory_candidates_tenant_created (tenant_id, created_at),
    KEY idx_memory_candidates_tenant_review (tenant_id, review_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS memory_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,
    reviewer_id VARCHAR(120) NULL,
    review_decision VARCHAR(30) NOT NULL,
    review_notes_json JSON NULL,
    published_memory_key VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_memory_reviews_tenant_created (tenant_id, created_at),
    KEY idx_memory_reviews_tenant_candidate (tenant_id, candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
