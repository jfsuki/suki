-- 20260403_026_support_tickets_auto_learning.sql
-- Adds support_tickets table to capture user frustration and closed feedback loops.

CREATE TABLE IF NOT EXISTS support_tickets (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    project_id TEXT NOT NULL,
    session_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    subject TEXT NOT NULL,
    message TEXT NOT NULL,
    sentiment TEXT NOT NULL, -- e.g., 'frustration', 'complaint', 'suggestion'
    status TEXT NOT NULL DEFAULT 'open', -- 'open', 'closed', 'training_pending', 'trained'
    created_at TEXT NOT NULL,
    closed_at TEXT NULL,
    metadata_json TEXT DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_support_tickets_scope 
    ON support_tickets (tenant_id, project_id, status);

CREATE INDEX IF NOT EXISTS idx_support_tickets_user 
    ON support_tickets (user_id, created_at);
