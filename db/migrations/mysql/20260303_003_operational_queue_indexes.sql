-- Operational queue index backfill (MySQL)
-- Purpose: formal migration for environments where tables exist without all required indexes.

SET @db_name := DATABASE();

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'event_dedupe'
      AND index_name = 'uq_event_dedupe_tenant_channel_key'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE event_dedupe ADD UNIQUE KEY uq_event_dedupe_tenant_channel_key (tenant_id, channel, idempotency_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'event_dedupe'
      AND index_name = 'idx_event_dedupe_tenant_first_seen'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE event_dedupe ADD KEY idx_event_dedupe_tenant_first_seen (tenant_id, first_seen_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'event_dedupe'
      AND index_name = 'idx_event_dedupe_status_last_seen'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE event_dedupe ADD KEY idx_event_dedupe_status_last_seen (status, last_seen_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'jobs_queue'
      AND index_name = 'idx_jobs_queue_tenant_created'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE jobs_queue ADD KEY idx_jobs_queue_tenant_created (tenant_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'jobs_queue'
      AND index_name = 'idx_jobs_queue_status_available'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE jobs_queue ADD KEY idx_jobs_queue_status_available (status, available_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = @db_name
      AND table_name = 'jobs_queue'
      AND index_name = 'idx_jobs_queue_tenant_status_available'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE jobs_queue ADD KEY idx_jobs_queue_tenant_status_available (tenant_id, status, available_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
