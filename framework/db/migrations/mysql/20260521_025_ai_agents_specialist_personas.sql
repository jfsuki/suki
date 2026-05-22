-- ai_agents: personas especialistas por tenant
-- source=catalog (desde app_catalog.json) | source=custom (definido por el tenant)
CREATE TABLE IF NOT EXISTS ai_agents (
    agent_id          VARCHAR(64)   NOT NULL,
    tenant_id         VARCHAR(64)   NOT NULL DEFAULT '',
    project_id        VARCHAR(64)   NOT NULL DEFAULT '',
    role              VARCHAR(64)   NULL,
    area              VARCHAR(64)   NULL,
    status            VARCHAR(32)   NOT NULL DEFAULT 'active',
    config_json       JSON          NULL,
    app_id            VARCHAR(64)   NULL,
    source            VARCHAR(32)   NOT NULL DEFAULT 'catalog',
    prompt_override   TEXT          NULL,
    requirements      TEXT          NULL,
    business_name     VARCHAR(200)  NULL,
    qdrant_collection VARCHAR(64)   NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (agent_id),
    INDEX idx_ai_agents_tenant        (tenant_id),
    INDEX idx_ai_agents_tenant_area   (tenant_id, area),
    INDEX idx_ai_agents_tenant_app    (tenant_id, app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
