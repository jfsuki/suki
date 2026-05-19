<?php
declare(strict_types=1);

// framework/scripts/migrate_user_profiles.php
// Creates user_profiles and builder_interview_state tables if not already present.
// Safe to run multiple times (IF NOT EXISTS).

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('FRAMEWORK_ROOT')) {
    define('FRAMEWORK_ROOT', APP_ROOT);
}
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(APP_ROOT) . '/project');
}

$projectEnvLoader = PROJECT_ROOT . '/config/env_loader.php';
if (is_file($projectEnvLoader)) {
    require_once $projectEnvLoader;
}

require_once __DIR__ . '/../app/autoload.php';

$pdo = App\Core\Database::connection();

$pdo->exec("CREATE TABLE IF NOT EXISTS user_profiles (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     VARCHAR(64)  NOT NULL,
    user_id       VARCHAR(128) NOT NULL,
    world         ENUM('app','builder','torre') NOT NULL DEFAULT 'app',
    display_name  VARCHAR(128) DEFAULT '',
    role_label    VARCHAR(64)  DEFAULT '',
    tech_level    ENUM('basic','intermediate','advanced') DEFAULT 'basic',
    language_tone ENUM('formal','informal','mixed') DEFAULT 'informal',
    frequent_tasks JSON        DEFAULT NULL,
    business_name VARCHAR(128) DEFAULT '',
    sector        VARCHAR(64)  DEFAULT '',
    custom_prefs  JSON        DEFAULT NULL,
    onboarding_completed_at DATETIME DEFAULT NULL,
    last_seen_at  DATETIME    DEFAULT NULL,
    created_at    DATETIME    DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_profile (tenant_id, user_id, world),
    KEY idx_tenant (tenant_id),
    KEY idx_tenant_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo 'user_profiles: OK' . PHP_EOL;

$pdo->exec("CREATE TABLE IF NOT EXISTS builder_interview_state (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     VARCHAR(64)  NOT NULL,
    session_id    VARCHAR(128) NOT NULL,
    app_id        VARCHAR(128) NOT NULL DEFAULT '',
    phase         VARCHAR(32)  NOT NULL DEFAULT 'intro',
    business_name VARCHAR(128) DEFAULT '',
    gathered_info JSON         DEFAULT NULL,
    gathered_text TEXT         DEFAULT NULL,
    dynamic_steps JSON         DEFAULT NULL,
    schema_draft  JSON         DEFAULT NULL,
    security_draft JSON        DEFAULT NULL,
    applied_mixins JSON        DEFAULT NULL,
    rounds        SMALLINT     DEFAULT 0,
    confirmed     TINYINT(1)   DEFAULT 0,
    developer_instructions TEXT DEFAULT NULL,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_interview (tenant_id, session_id),
    KEY idx_tenant (tenant_id),
    KEY idx_app (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo 'builder_interview_state: OK' . PHP_EOL;

$pdo->exec("CREATE TABLE IF NOT EXISTS app_tenant_config (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     VARCHAR(64)  NOT NULL,
    app_id        VARCHAR(128) NOT NULL,
    field_key     VARCHAR(128) NOT NULL,
    field_value   TEXT         DEFAULT NULL,
    configured_at DATETIME     DEFAULT NULL,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_config (tenant_id, app_id, field_key),
    KEY idx_tenant_app (tenant_id, app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo 'app_tenant_config: OK' . PHP_EOL;

// Check whether chat_log has user_id (CrossSessionMemory needs it)
$stmt = $pdo->query("SHOW COLUMNS FROM chat_log LIKE 'user_id'");
$hasUserId = (bool) $stmt->fetch();
echo 'chat_log.user_id present: ' . ($hasUserId ? 'YES' : 'NO') . PHP_EOL;

echo 'Migration complete.' . PHP_EOL;
