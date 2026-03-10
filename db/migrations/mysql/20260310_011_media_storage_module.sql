CREATE TABLE IF NOT EXISTS media_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id VARCHAR(190) NOT NULL,
    file_type VARCHAR(32) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(190) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by_user_id VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    metadata_json JSON NULL,
    PRIMARY KEY (id),
    KEY idx_media_files_tenant_entity_created (tenant_id, entity_type, entity_id, created_at),
    KEY idx_media_files_tenant_app_entity (tenant_id, app_id, entity_type, entity_id),
    KEY idx_media_files_tenant_user_created (tenant_id, uploaded_by_user_id, created_at),
    KEY idx_media_files_tenant_file_type (tenant_id, file_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS media_folders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(120) NOT NULL,
    app_id VARCHAR(120) NULL,
    name VARCHAR(190) NOT NULL,
    parent_folder_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_media_folders_tenant_parent_name (tenant_id, parent_folder_id, name),
    KEY idx_media_folders_tenant_app_parent (tenant_id, app_id, parent_folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
