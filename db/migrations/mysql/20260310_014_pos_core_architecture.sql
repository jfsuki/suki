CREATE TABLE IF NOT EXISTS pos_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    store_id VARCHAR(120) NULL,
    cash_register_id VARCHAR(120) NULL,
    opened_by_user_id VARCHAR(120) NULL,
    status VARCHAR(32) NOT NULL,
    opened_at DATETIME NULL,
    closed_at DATETIME NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pos_sessions_tenant_app_status (tenant_id, app_id, status, opened_at),
    KEY idx_pos_sessions_tenant_register (tenant_id, cash_register_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    session_id VARCHAR(120) NULL,
    status VARCHAR(32) NOT NULL,
    customer_id VARCHAR(190) NULL,
    currency VARCHAR(16) NULL,
    subtotal DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_total DECIMAL(18,4) NOT NULL DEFAULT 0,
    total DECIMAL(18,4) NOT NULL DEFAULT 0,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sale_drafts_tenant_app_status (tenant_id, app_id, status, updated_at),
    KEY idx_sale_drafts_tenant_session_status (tenant_id, session_id, status),
    KEY idx_sale_drafts_tenant_customer (tenant_id, customer_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_draft_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    sale_draft_id VARCHAR(120) NOT NULL,
    product_id VARCHAR(190) NOT NULL,
    sku VARCHAR(190) NULL,
    barcode VARCHAR(190) NULL,
    product_label VARCHAR(255) NOT NULL,
    qty DECIMAL(18,4) NOT NULL,
    unit_price DECIMAL(18,4) NOT NULL,
    tax_rate DECIMAL(10,4) NULL,
    line_total DECIMAL(18,4) NOT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sale_draft_lines_tenant_draft (tenant_id, app_id, sale_draft_id, id),
    KEY idx_sale_draft_lines_tenant_product (tenant_id, product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
