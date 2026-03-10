ALTER TABLE learning_candidates ADD COLUMN problem_type TEXT NOT NULL DEFAULT '';
ALTER TABLE learning_candidates ADD COLUMN severity TEXT NOT NULL DEFAULT 'medium';
ALTER TABLE learning_candidates ADD COLUMN evidence TEXT NULL;
ALTER TABLE learning_candidates ADD COLUMN processed_at TEXT NULL;
ALTER TABLE learning_candidates ADD COLUMN proposal_id TEXT NULL;

CREATE TABLE IF NOT EXISTS improvement_proposals (
    id TEXT PRIMARY KEY,
    tenant_id TEXT NULL,
    candidate_id TEXT NOT NULL,
    proposal_type TEXT NOT NULL,
    module TEXT NOT NULL,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    evidence TEXT NOT NULL DEFAULT '',
    frequency INTEGER NOT NULL DEFAULT 0,
    confidence REAL NOT NULL DEFAULT 0,
    priority TEXT NOT NULL DEFAULT 'medium',
    status TEXT NOT NULL DEFAULT 'open',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_improvement_proposals_scope
    ON improvement_proposals (tenant_id, module, proposal_type, status, created_at);

CREATE INDEX IF NOT EXISTS idx_improvement_proposals_candidate
    ON improvement_proposals (candidate_id, status);
