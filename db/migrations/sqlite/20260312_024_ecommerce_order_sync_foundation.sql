CREATE TABLE IF NOT EXISTS ecommerce_order_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    store_id TEXT NOT NULL,
    external_order_id TEXT NOT NULL,
    local_reference_type TEXT NULL,
    local_reference_id TEXT NULL,
    external_status TEXT NULL,
    local_status TEXT NULL,
    currency TEXT NULL,
    total REAL NULL,
    sync_status TEXT NOT NULL,
    last_sync_at TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_ecommerce_order_links_tenant_store_external
    ON ecommerce_order_links (tenant_id, app_id, store_id, external_order_id, created_at);

CREATE INDEX IF NOT EXISTS idx_ecommerce_order_links_tenant_status
    ON ecommerce_order_links (tenant_id, app_id, sync_status, external_status, local_status, created_at);

CREATE TABLE IF NOT EXISTS ecommerce_order_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    store_id TEXT NOT NULL,
    external_order_id TEXT NOT NULL,
    snapshot_payload_json TEXT NOT NULL,
    normalized_payload_json TEXT NULL,
    captured_at TEXT NOT NULL,
    metadata_json TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_ecommerce_order_snapshots_tenant_store_external
    ON ecommerce_order_snapshots (tenant_id, app_id, store_id, external_order_id, captured_at);
