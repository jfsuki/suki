CREATE TABLE IF NOT EXISTS ecommerce_stores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    platform VARCHAR(64) NOT NULL,
    store_name VARCHAR(190) NOT NULL,
    store_url VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL,
    connection_status VARCHAR(32) NOT NULL,
    currency VARCHAR(16) NULL,
    timezone VARCHAR(64) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ecommerce_stores_tenant_platform_status (tenant_id, app_id, platform, status, created_at),
    KEY idx_ecommerce_stores_tenant_connection (tenant_id, app_id, connection_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ecommerce_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    store_id VARCHAR(120) NOT NULL,
    credential_type VARCHAR(64) NOT NULL,
    encrypted_payload LONGTEXT NOT NULL,
    status VARCHAR(32) NOT NULL,
    last_validated_at DATETIME NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ecommerce_credentials_tenant_store_status (tenant_id, app_id, store_id, status, created_at),
    KEY idx_ecommerce_credentials_tenant_type (tenant_id, credential_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ecommerce_sync_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    store_id VARCHAR(120) NOT NULL,
    sync_type VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    result_summary TEXT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ecommerce_sync_jobs_tenant_store_status (tenant_id, app_id, store_id, status, created_at),
    KEY idx_ecommerce_sync_jobs_tenant_type (tenant_id, sync_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ecommerce_order_refs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    store_id VARCHAR(120) NOT NULL,
    external_order_id VARCHAR(190) NOT NULL,
    local_order_status VARCHAR(64) NULL,
    external_status VARCHAR(64) NULL,
    total DECIMAL(18,4) NULL,
    currency VARCHAR(16) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ecommerce_order_refs_tenant_store_external (tenant_id, app_id, store_id, external_order_id, created_at),
    KEY idx_ecommerce_order_refs_tenant_status (tenant_id, store_id, local_order_status, external_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
