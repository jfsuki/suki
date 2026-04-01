<?php

namespace App\Core;

use RuntimeException;

/**
 * AuthService handles user authentication, registration, and session linking.
 * It abstracts the ProjectRegistry for auth-related operations.
 */
class AuthService
{
    private ProjectRegistry $registry;

    public function __construct(?ProjectRegistry $registry = null)
    {
        $this->registry = $registry ?: new ProjectRegistry();
    }

    /**
     * Attempts to log in a user.
     * Returns the user record on success, or null on failure.
     */
    public function login(string $projectId, string $userId, string $password): ?array
    {
        if ($userId === '' || $password === '') {
            return null;
        }

        $user = $this->registry->verifyAuthUser($projectId, $userId, $password);
        if ($user) {
            // Update the general user registry to reflect the authentic identity
            $this->registry->touchUser(
                $userId, 
                $user['role'] ?? 'admin', 
                'auth', 
                $user['tenant_id'] ?? 'default', 
                $user['label'] ?? $userId
            );
        }

        return $user;
    }

    /**
     * Registers a new user.
     */
    public function register(string $projectId, string $userId, string $password, string $role = 'admin', string $tenantId = 'default', ?string $label = null): void
    {
        if ($userId === '' || $password === '') {
            throw new RuntimeException('Usuario y contraseña son requeridos.');
        }

        // Check if user already exists in auth_users
        if ($this->registry->getAuthUser($projectId, $userId)) {
            throw new RuntimeException("El usuario '{$userId}' ya existe en este proyecto.");
        }

        $this->registry->createAuthUser($projectId, $userId, $password, $role, $tenantId, $label);
        
        // Ensure they exist in the general users table too
        $this->registry->touchUser($userId, $role, 'auth', $tenantId, $label ?: $userId);
        $this->registry->assignUserToProject($projectId, $userId, $role);
    }

    /**
     * Checks if a user exists.
     */
    public function exists(string $projectId, string $userId): bool
    {
        return $this->registry->getAuthUser($projectId, $userId) !== null;
    }

    /**
     * Associates a chat session with an authenticated user.
     */
    public function linkSession(string $sessionId, string $userId, string $projectId, string $tenantId, string $channel = 'chat'): void
    {
        $this->registry->touchSession($sessionId, $userId, $projectId, $tenantId, $channel);
    }
}
