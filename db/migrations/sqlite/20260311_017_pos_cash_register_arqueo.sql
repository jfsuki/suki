ALTER TABLE pos_sessions ADD COLUMN closed_by_user_id TEXT NULL;
ALTER TABLE pos_sessions ADD COLUMN opening_amount REAL NULL;
ALTER TABLE pos_sessions ADD COLUMN expected_cash_amount REAL NULL;
ALTER TABLE pos_sessions ADD COLUMN counted_cash_amount REAL NULL;
ALTER TABLE pos_sessions ADD COLUMN difference_amount REAL NULL;
ALTER TABLE pos_sessions ADD COLUMN notes TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_pos_sessions_tenant_created
    ON pos_sessions (tenant_id, app_id, created_at, id);
