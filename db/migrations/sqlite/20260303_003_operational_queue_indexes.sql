-- Operational queue index backfill (SQLite)
-- Purpose: formal migration for environments where tables exist without all required indexes.

CREATE UNIQUE INDEX IF NOT EXISTS uq_event_dedupe_tenant_channel_key
    ON event_dedupe (tenant_id, channel, idempotency_key);

CREATE INDEX IF NOT EXISTS idx_event_dedupe_tenant_first_seen
    ON event_dedupe (tenant_id, first_seen_at);

CREATE INDEX IF NOT EXISTS idx_event_dedupe_status_last_seen
    ON event_dedupe (status, last_seen_at);

CREATE INDEX IF NOT EXISTS idx_jobs_queue_tenant_created
    ON jobs_queue (tenant_id, created_at);

CREATE INDEX IF NOT EXISTS idx_jobs_queue_status_available
    ON jobs_queue (status, available_at);

CREATE INDEX IF NOT EXISTS idx_jobs_queue_tenant_status_available
    ON jobs_queue (tenant_id, status, available_at);
