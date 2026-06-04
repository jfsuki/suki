-- OTP tokens con expiración para producción
-- Separado de auth_users (SQLite) para que las OTPs no persistan en el registry
CREATE TABLE IF NOT EXISTS otp_tokens (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    identifier    VARCHAR(120)      NOT NULL COMMENT 'email o telefono del solicitante',
    purpose       VARCHAR(32)       NOT NULL DEFAULT 'register' COMMENT 'register|login|reset',
    code_hash     VARCHAR(255)      NOT NULL COMMENT 'bcrypt del codigo de 6 digitos',
    expires_at    DATETIME          NOT NULL,
    used_at       DATETIME          NULL,
    attempt_count INT               NOT NULL DEFAULT 0 COMMENT 'Intentos fallidos de verificacion',
    blocked_at    DATETIME          NULL COMMENT 'Momento en que se bloqueo por exceso de intentos',
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_otp_identifier (identifier),
    INDEX idx_otp_expires    (expires_at),
    INDEX idx_otp_attempts   (identifier, attempt_count, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos temporales de registro pendiente de verificacion
CREATE TABLE IF NOT EXISTS otp_pending_registrations (
    tenant_id     VARCHAR(64)  NOT NULL,
    email         VARCHAR(200) NOT NULL,
    phone         VARCHAR(30)  NULL,
    nit           VARCHAR(20)  NOT NULL,
    business_name VARCHAR(200) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    app_id        VARCHAR(64)  NOT NULL DEFAULT 'suki_erp',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NULL,
    PRIMARY KEY (tenant_id),
    INDEX idx_otp_pending_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
