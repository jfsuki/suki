CREATE TABLE IF NOT EXISTS pos_sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    session_id TEXT NULL,
    draft_id TEXT NULL,
    customer_id TEXT NULL,
    sale_number TEXT NULL,
    status TEXT NOT NULL,
    currency TEXT NULL,
    subtotal REAL NOT NULL DEFAULT 0,
    tax_total REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    created_by_user_id TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pos_sales_tenant_app_created
    ON pos_sales (tenant_id, app_id, created_at, id);

CREATE UNIQUE INDEX IF NOT EXISTS idx_pos_sales_tenant_number
    ON pos_sales (tenant_id, app_id, sale_number);

CREATE INDEX IF NOT EXISTS idx_pos_sales_tenant_draft
    ON pos_sales (tenant_id, app_id, draft_id);

CREATE TABLE IF NOT EXISTS pos_sale_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    sale_id TEXT NOT NULL,
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

CREATE INDEX IF NOT EXISTS idx_pos_sale_lines_tenant_sale
    ON pos_sale_lines (tenant_id, app_id, sale_id, id);

CREATE INDEX IF NOT EXISTS idx_pos_sale_lines_tenant_product
    ON pos_sale_lines (tenant_id, product_id, created_at);
