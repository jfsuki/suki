CREATE TABLE IF NOT EXISTS pos_returns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    sale_id TEXT NOT NULL,
    return_number TEXT NULL,
    status TEXT NOT NULL,
    subtotal REAL NOT NULL DEFAULT 0,
    tax_total REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    reason TEXT NULL,
    created_by_user_id TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_pos_returns_tenant_number
    ON pos_returns (tenant_id, app_id, return_number);

CREATE INDEX IF NOT EXISTS idx_pos_returns_tenant_sale
    ON pos_returns (tenant_id, app_id, sale_id, id);

CREATE INDEX IF NOT EXISTS idx_pos_returns_tenant_created
    ON pos_returns (tenant_id, app_id, created_at, id);

CREATE TABLE IF NOT EXISTS pos_return_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    return_id TEXT NOT NULL,
    sale_line_id TEXT NULL,
    product_id TEXT NOT NULL,
    sku TEXT NULL,
    barcode TEXT NULL,
    product_label TEXT NOT NULL,
    qty REAL NOT NULL,
    unit_price REAL NOT NULL,
    line_total REAL NOT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_pos_return_lines_tenant_return
    ON pos_return_lines (tenant_id, app_id, return_id, id);

CREATE INDEX IF NOT EXISTS idx_pos_return_lines_tenant_sale_line
    ON pos_return_lines (tenant_id, app_id, sale_line_id);
