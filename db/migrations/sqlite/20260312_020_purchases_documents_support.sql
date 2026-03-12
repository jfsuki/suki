CREATE TABLE IF NOT EXISTS purchase_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    purchase_id TEXT NULL,
    purchase_draft_id TEXT NULL,
    media_file_id TEXT NOT NULL,
    document_type TEXT NOT NULL,
    document_number TEXT NULL,
    supplier_id TEXT NULL,
    issue_date TEXT NULL,
    total_amount REAL NULL,
    currency TEXT NULL,
    notes TEXT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_purchase_documents_tenant_draft
    ON purchase_documents (tenant_id, app_id, purchase_draft_id, created_at);
CREATE INDEX IF NOT EXISTS idx_purchase_documents_tenant_purchase
    ON purchase_documents (tenant_id, app_id, purchase_id, created_at);
CREATE INDEX IF NOT EXISTS idx_purchase_documents_tenant_media
    ON purchase_documents (tenant_id, media_file_id, created_at);
CREATE INDEX IF NOT EXISTS idx_purchase_documents_tenant_type
    ON purchase_documents (tenant_id, document_type, created_at);
