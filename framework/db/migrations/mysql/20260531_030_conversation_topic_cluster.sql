-- SCML Fase 4: persiste topic_cluster por conversación para context-aware grounding cross-request.
-- Usada por ChatAgent::loadTopicCluster() y saveTopicCluster().
-- PK compuesta (thread_id, tenant_id) garantiza aislamiento multi-tenant.

CREATE TABLE IF NOT EXISTS conversation_topic_cluster (
    thread_id  VARCHAR(255) NOT NULL COMMENT 'Formato tenantId:sessionId',
    tenant_id  VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Redundancia para queries por tenant',
    cluster    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Valor: accounting|inventory|crm|memory|...',
    updated_at VARCHAR(20)  NOT NULL DEFAULT '',
    PRIMARY KEY (thread_id, tenant_id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SCML Fase 4 — topic cluster persistence';
