CREATE TABLE IF NOT EXISTS ecommerce_product_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    store_id VARCHAR(120) NOT NULL,
    local_product_id VARCHAR(120) NULL,
    external_product_id VARCHAR(190) NOT NULL,
    external_sku VARCHAR(190) NULL,
    sync_status VARCHAR(32) NOT NULL,
    last_sync_at DATETIME NULL,
    last_sync_direction VARCHAR(32) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ecommerce_product_links_tenant_store_local (tenant_id, app_id, store_id, local_product_id, created_at),
    KEY idx_ecommerce_product_links_tenant_store_external (tenant_id, app_id, store_id, external_product_id, created_at),
    KEY idx_ecommerce_product_links_tenant_status (tenant_id, app_id, sync_status, last_sync_direction, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
