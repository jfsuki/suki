-- Optional P0 regression tables (MySQL)
-- Scope: regression case storage and run tracking

CREATE TABLE IF NOT EXISTS regression_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    suite_name VARCHAR(120) NOT NULL,
    case_key VARCHAR(191) NOT NULL,
    input_json JSON NOT NULL,
    expected_json JSON NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'medium',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_regression_cases_tenant_suite_case (tenant_id, suite_name, case_key),
    KEY idx_regression_cases_tenant_created (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS regression_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    suite_name VARCHAR(120) NOT NULL,
    run_key VARCHAR(191) NOT NULL,
    status VARCHAR(30) NOT NULL,
    summary_json JSON NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_regression_runs_tenant_run_key (tenant_id, run_key),
    KEY idx_regression_runs_tenant_created (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
