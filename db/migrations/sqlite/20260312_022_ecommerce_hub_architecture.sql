CREATE TABLE IF NOT EXISTS ecommerce_stores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    platform TEXT NOT NULL,
    store_name TEXT NOT NULL,
    store_url TEXT NULL,
    status TEXT NOT NULL,
    connection_status TEXT NOT NULL,
    currency TEXT NULL,
    timezone TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_ecommerce_stores_tenant_platform_status
    ON ecommerce_stores (tenant_id, app_id, platform, status, created_at);

CREATE INDEX IF NOT EXISTS idx_ecommerce_stores_tenant_connection
    ON ecommerce_stores (tenant_id, app_id, connection_status, created_at);

CREATE TABLE IF NOT EXISTS ecommerce_credentials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    store_id TEXT NOT NULL,
    credential_type TEXT NOT NULL,
    encrypted_payload TEXT NOT NULL,
    status TEXT NOT NULL,
    last_validated_at TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_ecommerce_credentials_tenant_store_status
    ON ecommerce_credentials (tenant_id, app_id, store_id, status, created_at);

CREATE INDEX IF NOT EXISTS idx_ecommerce_credentials_tenant_type
    ON ecommerce_credentials (tenant_id, credential_type, created_at);

CREATE TABLE IF NOT EXISTS ecommerce_sync_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    store_id TEXT NOT NULL,
    sync_type TEXT NOT NULL,
    status TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    result_summary TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_ecommerce_sync_jobs_tenant_store_status
    ON ecommerce_sync_jobs (tenant_id, app_id, store_id, status, created_at);

CREATE INDEX IF NOT EXISTS idx_ecommerce_sync_jobs_tenant_type
    ON ecommerce_sync_jobs (tenant_id, sync_type, created_at);

CREATE TABLE IF NOT EXISTS ecommerce_order_refs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    store_id TEXT NOT NULL,
    external_order_id TEXT NOT NULL,
    local_order_status TEXT NULL,
    external_status TEXT NULL,
    total REAL NULL,
    currency TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_ecommerce_order_refs_tenant_store_external
    ON ecommerce_order_refs (tenant_id, app_id, store_id, external_order_id, created_at);

CREATE INDEX IF NOT EXISTS idx_ecommerce_order_refs_tenant_status
    ON ecommerce_order_refs (tenant_id, store_id, local_order_status, external_status, created_at);
