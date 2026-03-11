CREATE TABLE IF NOT EXISTS purchase_drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    supplier_id TEXT NULL,
    status TEXT NOT NULL,
    currency TEXT NULL,
    subtotal REAL NOT NULL DEFAULT 0,
    tax_total REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    notes TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_purchase_drafts_tenant_app_status
    ON purchase_drafts (tenant_id, app_id, status, updated_at);
CREATE INDEX IF NOT EXISTS idx_purchase_drafts_tenant_supplier
    ON purchase_drafts (tenant_id, supplier_id, updated_at);

CREATE TABLE IF NOT EXISTS purchase_draft_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    purchase_draft_id TEXT NOT NULL,
    product_id TEXT NULL,
    sku TEXT NULL,
    supplier_sku TEXT NULL,
    product_label TEXT NOT NULL,
    qty REAL NOT NULL,
    unit_cost REAL NOT NULL,
    tax_rate REAL NULL,
    line_total REAL NOT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_purchase_draft_lines_tenant_draft
    ON purchase_draft_lines (tenant_id, app_id, purchase_draft_id, id);
CREATE INDEX IF NOT EXISTS idx_purchase_draft_lines_tenant_product
    ON purchase_draft_lines (tenant_id, product_id, created_at);

CREATE TABLE IF NOT EXISTS purchases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    purchase_number TEXT NULL,
    supplier_id TEXT NULL,
    draft_id TEXT NULL,
    status TEXT NOT NULL,
    currency TEXT NULL,
    subtotal REAL NOT NULL DEFAULT 0,
    tax_total REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_by_user_id TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_purchases_tenant_app_created
    ON purchases (tenant_id, app_id, created_at, id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_purchases_tenant_number
    ON purchases (tenant_id, app_id, purchase_number);
CREATE INDEX IF NOT EXISTS idx_purchases_tenant_draft
    ON purchases (tenant_id, app_id, draft_id);

CREATE TABLE IF NOT EXISTS purchase_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    purchase_id TEXT NOT NULL,
    product_id TEXT NULL,
    sku TEXT NULL,
    supplier_sku TEXT NULL,
    product_label TEXT NOT NULL,
    qty REAL NOT NULL,
    unit_cost REAL NOT NULL,
    tax_rate REAL NULL,
    line_total REAL NOT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_purchase_lines_tenant_purchase
    ON purchase_lines (tenant_id, app_id, purchase_id, id);
CREATE INDEX IF NOT EXISTS idx_purchase_lines_tenant_product
    ON purchase_lines (tenant_id, product_id, created_at);
