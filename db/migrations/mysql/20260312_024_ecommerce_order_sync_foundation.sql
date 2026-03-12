CREATE TABLE IF NOT EXISTS ecommerce_order_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    store_id VARCHAR(120) NOT NULL,
    external_order_id VARCHAR(190) NOT NULL,
    local_reference_type VARCHAR(120) NULL,
    local_reference_id VARCHAR(120) NULL,
    external_status VARCHAR(64) NULL,
    local_status VARCHAR(64) NULL,
    currency VARCHAR(16) NULL,
    total DECIMAL(18,4) NULL,
    sync_status VARCHAR(32) NOT NULL,
    last_sync_at DATETIME NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ecommerce_order_links_tenant_store_external (tenant_id, app_id, store_id, external_order_id, created_at),
    KEY idx_ecommerce_order_links_tenant_status (tenant_id, app_id, sync_status, external_status, local_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ecommerce_order_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    store_id VARCHAR(120) NOT NULL,
    external_order_id VARCHAR(190) NOT NULL,
    snapshot_payload_json JSON NOT NULL,
    normalized_payload_json JSON NULL,
    captured_at DATETIME NOT NULL,
    metadata_json JSON NULL,
    PRIMARY KEY (id),
    KEY idx_ecommerce_order_snapshots_tenant_store_external (tenant_id, app_id, store_id, external_order_id, captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
