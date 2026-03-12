CREATE TABLE IF NOT EXISTS ecommerce_product_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    store_id TEXT NOT NULL,
    local_product_id TEXT NULL,
    external_product_id TEXT NOT NULL,
    external_sku TEXT NULL,
    sync_status TEXT NOT NULL,
    last_sync_at TEXT NULL,
    last_sync_direction TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_ecommerce_product_links_tenant_store_local
    ON ecommerce_product_links (tenant_id, app_id, store_id, local_product_id, created_at);

CREATE INDEX IF NOT EXISTS idx_ecommerce_product_links_tenant_store_external
    ON ecommerce_product_links (tenant_id, app_id, store_id, external_product_id, created_at);

CREATE INDEX IF NOT EXISTS idx_ecommerce_product_links_tenant_status
    ON ecommerce_product_links (tenant_id, app_id, sync_status, last_sync_direction, created_at);
