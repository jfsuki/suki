CREATE TABLE IF NOT EXISTS pos_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    store_id TEXT NULL,
    cash_register_id TEXT NULL,
    opened_by_user_id TEXT NULL,
    status TEXT NOT NULL,
    opened_at TEXT NULL,
    closed_at TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pos_sessions_tenant_app_status
    ON pos_sessions (tenant_id, app_id, status, opened_at);

CREATE INDEX IF NOT EXISTS idx_pos_sessions_tenant_register
    ON pos_sessions (tenant_id, cash_register_id, status);

CREATE TABLE IF NOT EXISTS sale_drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    session_id TEXT NULL,
    status TEXT NOT NULL,
    customer_id TEXT NULL,
    currency TEXT NULL,
    subtotal REAL NOT NULL DEFAULT 0,
    tax_total REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sale_drafts_tenant_app_status
    ON sale_drafts (tenant_id, app_id, status, updated_at);

CREATE INDEX IF NOT EXISTS idx_sale_drafts_tenant_session_status
    ON sale_drafts (tenant_id, session_id, status);

CREATE INDEX IF NOT EXISTS idx_sale_drafts_tenant_customer
    ON sale_drafts (tenant_id, customer_id, updated_at);

CREATE TABLE IF NOT EXISTS sale_draft_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    sale_draft_id TEXT NOT NULL,
    product_id TEXT NOT NULL,
    sku TEXT NULL,
    barcode TEXT NULL,
    product_label TEXT NOT NULL,
    qty REAL NOT NULL,
    unit_price REAL NOT NULL,
    tax_rate REAL NULL,
    line_total REAL NOT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sale_draft_lines_tenant_draft
    ON sale_draft_lines (tenant_id, app_id, sale_draft_id, id);

CREATE INDEX IF NOT EXISTS idx_sale_draft_lines_tenant_product
    ON sale_draft_lines (tenant_id, product_id, created_at);
