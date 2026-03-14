CREATE TABLE IF NOT EXISTS usage_meters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    metric_key VARCHAR(64) NOT NULL,
    period_key VARCHAR(32) NOT NULL,
    usage_value DECIMAL(18,4) NOT NULL,
    unit VARCHAR(32) NOT NULL,
    metadata_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY idx_usage_meters_tenant_metric_period_unique (tenant_id, metric_key, period_key),
    KEY idx_usage_meters_tenant_period (tenant_id, period_key, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usage_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    metric_key VARCHAR(64) NOT NULL,
    delta_value DECIMAL(18,4) NOT NULL,
    unit VARCHAR(32) NOT NULL,
    source_module VARCHAR(64) NOT NULL,
    source_action VARCHAR(64) NULL,
    source_ref VARCHAR(190) NULL,
    metadata_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_usage_events_tenant_metric_created (tenant_id, metric_key, created_at),
    KEY idx_usage_events_tenant_source (tenant_id, source_module, source_action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
