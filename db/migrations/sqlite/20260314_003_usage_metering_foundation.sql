CREATE TABLE IF NOT EXISTS usage_meters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    metric_key TEXT NOT NULL,
    period_key TEXT NOT NULL,
    usage_value NUMERIC NOT NULL,
    unit TEXT NOT NULL,
    metadata_json TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_usage_meters_tenant_metric_period_unique
    ON usage_meters (tenant_id, metric_key, period_key);

CREATE INDEX IF NOT EXISTS idx_usage_meters_tenant_period
    ON usage_meters (tenant_id, period_key, updated_at);

CREATE TABLE IF NOT EXISTS usage_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    metric_key TEXT NOT NULL,
    delta_value NUMERIC NOT NULL,
    unit TEXT NOT NULL,
    source_module TEXT NOT NULL,
    source_action TEXT NULL,
    source_ref TEXT NULL,
    metadata_json TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_usage_events_tenant_metric_created
    ON usage_events (tenant_id, metric_key, created_at);

CREATE INDEX IF NOT EXISTS idx_usage_events_tenant_source
    ON usage_events (tenant_id, source_module, source_action, created_at);
