CREATE TABLE IF NOT EXISTS media_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    file_type TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL DEFAULT 0,
    uploaded_by_user_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    metadata_json TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_media_files_tenant_entity_created
    ON media_files (tenant_id, entity_type, entity_id, created_at);

CREATE INDEX IF NOT EXISTS idx_media_files_tenant_app_entity
    ON media_files (tenant_id, app_id, entity_type, entity_id);

CREATE INDEX IF NOT EXISTS idx_media_files_tenant_user_created
    ON media_files (tenant_id, uploaded_by_user_id, created_at);

CREATE INDEX IF NOT EXISTS idx_media_files_tenant_file_type
    ON media_files (tenant_id, file_type, created_at);

CREATE TABLE IF NOT EXISTS media_folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    app_id TEXT NULL,
    name TEXT NOT NULL,
    parent_folder_id INTEGER NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_media_folders_tenant_parent_name
    ON media_folders (tenant_id, parent_folder_id, name);

CREATE INDEX IF NOT EXISTS idx_media_folders_tenant_app_parent
    ON media_folders (tenant_id, app_id, parent_folder_id);
