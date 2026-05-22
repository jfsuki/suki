<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Genera y valida OTPs de 6 dígitos con expiración en MySQL.
 * NO almacena el código en texto plano — usa bcrypt.
 */
final class OtpService
{
    private const EXPIRY_MINUTES = 15;

    public function __construct(private \PDO $db) {}

    public function generate(string $identifier, string $purpose = 'register'): string
    {
        // Invalidar OTPs anteriores del mismo identificador/propósito
        $stmt = $this->db->prepare(
            'DELETE FROM otp_tokens WHERE identifier = ? AND purpose = ? AND used_at IS NULL'
        );
        $stmt->execute([$identifier, $purpose]);

        $code    = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $hash    = password_hash($code, PASSWORD_BCRYPT);
        $expires = date('Y-m-d H:i:s', time() + self::EXPIRY_MINUTES * 60);

        $stmt = $this->db->prepare(
            'INSERT INTO otp_tokens (identifier, purpose, code_hash, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$identifier, $purpose, $hash, $expires]);

        return $code; // Solo se devuelve aquí para enviarlo por email — NUNCA al cliente HTTP
    }

    public function verify(string $identifier, string $code, string $purpose = 'register'): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id, code_hash FROM otp_tokens
             WHERE identifier = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$identifier, $purpose]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !password_verify($code, $row['code_hash'])) {
            return false;
        }

        // Marcar como usado (no eliminar — audit trail)
        $stmt = $this->db->prepare(
            'UPDATE otp_tokens SET used_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$row['id']]);

        return true;
    }

    public function purgeExpired(): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM otp_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
