<?php

declare(strict_types=1);

namespace App\Core;

/**
 * ControlTowerService
 * 
 * Centro de mando administrativo para SUKI.
 * Maneja la activación de usuarios y la seguridad de acceso "stealth".
 */
class ControlTowerService
{
    private ProjectRegistry $registry;
    private ?string $masterKey;

    public function __construct(ProjectRegistry $registry)
    {
        $this->registry = $registry;
        // La llave maestra se busca en el entorno o se usa un fallback seguro para desarrollo
        $this->masterKey = $_ENV['SUKI_ADMIN_MASTER_KEY'] ?? getenv('SUKI_ADMIN_MASTER_KEY') ?: 'SUKI_DEV_TOWER_2026';
    }

    /**
     * Verifica si la llave proporcionada es correcta.
     */
    public function verifyAccess(string $providedKey): bool
    {
        if (empty($providedKey)) {
            return false;
        }
        return hash_equals($this->masterKey, $providedKey);
    }

    /**
     * Obtiene todas las empresas/usuarios que están esperando activación.
     */
    public function getPendingRegistrations(): array
    {
        return $this->registry->getUsersByStatus(0);
    }

    /**
     * Activa una empresa para que pueda operar.
     */
    public function activateCompany(string $userId): bool
    {
        return $this->registry->updateAuthUserStatus($userId, 1);
    }

    /**
     * Desactiva una empresa.
     */
    public function deactivateCompany(string $userId): bool
    {
        return $this->registry->updateAuthUserStatus($userId, 0);
    }

    /**
     * Obtiene la lista de todos los Creadores (Usuarios de Mundo 2).
     */
    public function getCreators(): array
    {
        return $this->registry->getUsersByType('creator'); // Asume que existe este tipo en la DB
    }

    /**
     * Crea un nuevo usuario Creador manualmente desde la Torre.
     */
    public function createCreator(array $data): array
    {
        if (empty($data['nit']) || empty($data['full_name']) || empty($data['password'])) {
            return ['success' => false, 'error' => 'NIT, Nombre y Contraseña son obligatorios.'];
        }

        $hash = password_hash($data['password'], PASSWORD_BCRYPT);
        
        $userId = 'cr_' . bin2hex(random_bytes(4));
        $tenantId = 't_creator_' . bin2hex(random_bytes(3));

        $stmt = $this->registry->db()->prepare("
            INSERT INTO auth_users 
                (id, nit, full_name, user_type, password_hash, tenant_id, is_active, project_id)
            VALUES 
                (:id, :nit, :full_name, 'creator', :hash, :tenant, 1, 'framework')
        ");

        try {
            $stmt->execute([
                ':id' => $userId,
                ':nit' => $data['nit'],
                ':full_name' => $data['full_name'],
                ':hash' => $hash,
                ':tenant' => $tenantId
            ]);
            return ['success' => true, 'user_id' => $userId];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtiene la información completa de una empresa, incluyendo el JSON de responsabilidades fiscales.
     */
    public function getCompanyDetail(string $userId): ?array
    {
        $user = $this->registry->getAuthUserById($userId);
        if ($user && isset($user['tax_responsibilities'])) {
            $user['responsibilities_decoded'] = json_decode($user['tax_responsibilities'], true);
        }
        return $user;
    }
}
