-- Optional P0 regression tables (SQLite)
-- JSON fields are stored as TEXT; semantic validation happens at application layer.

CREATE TABLE IF NOT EXISTS regression_cases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    suite_name TEXT NOT NULL,
    case_key TEXT NOT NULL,
    input_json TEXT NOT NULL,
    expected_json TEXT NOT NULL,
    severity TEXT NOT NULL DEFAULT 'medium',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_regression_cases_tenant_suite_case
    ON regression_cases (tenant_id, suite_name, case_key);
CREATE INDEX IF NOT EXISTS idx_regression_cases_tenant_created
    ON regression_cases (tenant_id, created_at);

CREATE TABLE IF NOT EXISTS regression_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    suite_name TEXT NOT NULL,
    run_key TEXT NOT NULL,
    status TEXT NOT NULL,
    summary_json TEXT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_regression_runs_tenant_run_key
    ON regression_runs (tenant_id, run_key);
CREATE INDEX IF NOT EXISTS idx_regression_runs_tenant_created
    ON regression_runs (tenant_id, created_at);
