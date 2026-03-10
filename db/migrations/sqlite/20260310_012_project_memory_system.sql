CREATE TABLE IF NOT EXISTS improvement_memory (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    module TEXT NOT NULL,
    problem_type TEXT NOT NULL,
    frequency INTEGER NOT NULL DEFAULT 1,
    severity TEXT NOT NULL DEFAULT 'medium',
    evidence TEXT NOT NULL DEFAULT '',
    evidence_hash TEXT NOT NULL,
    suggestion TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'open',
    created_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_improvement_memory_fingerprint
    ON improvement_memory (tenant_id, module, problem_type, evidence_hash);

CREATE INDEX IF NOT EXISTS idx_improvement_memory_scope
    ON improvement_memory (tenant_id, module, problem_type, created_at);

CREATE TABLE IF NOT EXISTS learning_candidates (
    candidate_id TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    source_metric TEXT NOT NULL,
    module TEXT NOT NULL,
    description TEXT NOT NULL,
    frequency INTEGER NOT NULL DEFAULT 0,
    confidence REAL NOT NULL DEFAULT 0,
    review_status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_learning_candidates_scope
    ON learning_candidates (tenant_id, review_status, created_at);

CREATE INDEX IF NOT EXISTS idx_learning_candidates_source
    ON learning_candidates (tenant_id, source_metric, module);
