CREATE TABLE IF NOT EXISTS fiscal_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    source_module TEXT NOT NULL,
    source_entity_type TEXT NOT NULL,
    source_entity_id TEXT NOT NULL,
    document_type TEXT NOT NULL,
    document_number TEXT NULL,
    status TEXT NOT NULL,
    issuer_party_id TEXT NULL,
    receiver_party_id TEXT NULL,
    issue_date TEXT NULL,
    currency TEXT NULL,
    subtotal REAL NULL,
    tax_total REAL NULL,
    total REAL NULL,
    external_provider TEXT NULL,
    external_reference TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_fiscal_documents_tenant_source
    ON fiscal_documents (tenant_id, app_id, source_module, source_entity_type, source_entity_id, created_at);
CREATE INDEX IF NOT EXISTS idx_fiscal_documents_tenant_type_status
    ON fiscal_documents (tenant_id, app_id, document_type, status, created_at);
CREATE INDEX IF NOT EXISTS idx_fiscal_documents_tenant_provider
    ON fiscal_documents (tenant_id, app_id, external_provider, external_reference);

CREATE TABLE IF NOT EXISTS fiscal_document_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    fiscal_document_id TEXT NOT NULL,
    product_id TEXT NULL,
    description TEXT NOT NULL,
    qty REAL NULL,
    unit_amount REAL NULL,
    tax_rate REAL NULL,
    line_total REAL NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_fiscal_document_lines_tenant_document
    ON fiscal_document_lines (tenant_id, app_id, fiscal_document_id, id);
CREATE INDEX IF NOT EXISTS idx_fiscal_document_lines_tenant_product
    ON fiscal_document_lines (tenant_id, product_id, created_at);

CREATE TABLE IF NOT EXISTS fiscal_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    fiscal_document_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    event_status TEXT NOT NULL,
    payload_json TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_fiscal_events_tenant_document
    ON fiscal_events (tenant_id, app_id, fiscal_document_id, created_at);
CREATE INDEX IF NOT EXISTS idx_fiscal_events_tenant_type
    ON fiscal_events (tenant_id, event_type, created_at);
