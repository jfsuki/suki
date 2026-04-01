<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use PDO;

/**
 * AuthService handles secure user authentication, registration, and session management.
 * Includes rate limiting and IP-based security controls.
 */
class AuthService
{
    private ProjectRegistry $registry;
    private PDO $db;

    public function __construct(?ProjectRegistry $registry = null)
    {
        $this->registry = $registry ?: new ProjectRegistry();
        $this->db = $this->registry->db();
    }

    /**
     * Intenta iniciar sesión con protección contra fuerza bruta.
     */
    public function login(string $projectId, string $identifier, string $password, string $ipAddress): array
    {
        // 1. Verificar Rate Limit
        if ($this->isRateLimited($identifier, $ipAddress)) {
            return [
                'success' => false, 
                'error' => 'Demasiados intentos. Por favor, intente de nuevo en 15 minutos.',
                'code' => 'RATE_LIMIT_EXCEEDED'
            ];
        }

        // 2. Verificar usuario (ProjectRegistry ya usa password_verify)
        $user = $this->registry->verifyAuthUser($projectId, $identifier, $password);

        if (!$user) {
            $this->recordFailedAttempt($identifier, $ipAddress);
            return [
                'success' => false, 
                'error' => 'Credenciales inválidas.', // Mensaje genérico por seguridad
                'code' => 'INVALID_CREDENTIALS'
            ];
        }

        // 3. Verificar si está activo
        if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
            return [
                'success' => false, 
                'error' => 'Su cuenta está pendiente de aprobación por un asesor.',
                'code' => 'ACCOUNT_INACTIVE'
            ];
        }

        // 4. Login existoso: Limpiar intentos, regenerar sesión
        $this->clearAttempts($identifier, $ipAddress);
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['tenant_id'] = $user['tenant_id'];
            $_SESSION['project_id'] = $projectId;
            $_SESSION['last_ip'] = $ipAddress;
        }

        // Actualizar registro general de usuarios
        $this->registry->touchUser(
            $user['id'], 
            $user['role'] ?? 'admin', 
            'auth', 
            $user['tenant_id'] ?? 'default', 
            $user['label'] ?? $identifier
        );

        return ['success' => true, 'user' => $user];
    }

    private function isRateLimited(string $identifier, string $ip): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM login_attempts 
            WHERE (identifier = :id OR ip_address = :ip)
              AND attempted_at > DATETIME('now', '-15 minutes')
        ");
        $stmt->execute([':id' => $identifier, ':ip' => $ip]);
        return (int)$stmt->fetchColumn() >= 5;
    }

    private function recordFailedAttempt(string $identifier, string $ip): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO login_attempts (identifier, ip_address) 
            VALUES (:id, :ip)
        ");
        $stmt->execute([':id' => $identifier, ':ip' => $ip]);
    }

    private function clearAttempts(string $identifier, string $ip): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM login_attempts 
            WHERE identifier = :id OR ip_address = :ip
        ");
        $stmt->execute([':id' => $identifier, ':ip' => $ip]);
    }

    public function register(string $projectId, string $userId, string $password, string $role = 'admin', string $tenantId = 'default', ?string $label = null): void
    {
        if ($this->registry->getAuthUser($projectId, $userId)) {
            throw new RuntimeException("El usuario '{$userId}' ya existe.");
        }

        $this->registry->createAuthUser($projectId, $userId, $password, $role, $tenantId, $label);
        $this->registry->touchUser($userId, $role, 'auth', $tenantId, $label ?: $userId);
        $this->registry->assignUserToProject($projectId, $userId, $role);
    }

    public function linkSession(string $sessionId, string $userId, string $projectId, string $tenantId, string $channel = 'chat'): void
    {
        $this->registry->touchSession($sessionId, $userId, $projectId, $tenantId, $channel);
    }
}
