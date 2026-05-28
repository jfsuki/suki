-- Agrega attempt_count a otp_tokens para rate limiting a nivel DB.
-- Incrementar con cada verificacion fallida; bloquear cuando attempt_count >= 5.
ALTER TABLE otp_tokens
    ADD COLUMN IF NOT EXISTS attempt_count INT NOT NULL DEFAULT 0 COMMENT 'Intentos fallidos de verificacion',
    ADD COLUMN IF NOT EXISTS blocked_at    DATETIME NULL COMMENT 'Momento en que se bloqueo por exceso de intentos';

-- Indice para optimizar busquedas de tokens no expirados con intentos bajos
CREATE INDEX IF NOT EXISTS idx_otp_tokens_attempts ON otp_tokens (identifier, attempt_count, expires_at);
