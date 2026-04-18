<?php
declare(strict_types=1);

namespace App\Core\Agents;

use App\Core\ProjectRegistry;
use App\Core\TenantAccessControlService;
use App\Core\StorageModel;
use App\Core\TableNamespace;
use App\Core\RoleContext;

final class IdentityResolver
{
    /**
     * Resolves identity, role, tenant, project, and session binding for a chat request.
     * Side effects: putenv, define(TENANT_ID), RoleContext static setters.
     * Returns ['error' => string] on session/tenant conflict, otherwise full context.
     */
    public function resolve(
        array $payload,
        string $tenantId,
        string $userId,
        string $sessionId,
        string $channel,
        bool $isAuthenticated,
        bool $testMode,
        string $role,
        string $mode,
        string $authUserId,
        string $authTenantId,
        string $authProjectId
    ): array {
        // 1. Torre de Control detection (portal channel → architect)
        if ($channel === 'portal' && $role === '') {
            $role = 'architect';
        }

        if ($role === '') {
            $role = $isAuthenticated ? (string) (getenv('DEFAULT_ROLE') ?: 'admin') : 'developer';
        }

        if ($isAuthenticated) {
            if ($authUserId === '') { $authUserId = $userId; }
            if ($authTenantId === '') { $authTenantId = $tenantId; }
        }
        $role = $this->normalizeRole($role);
        $mode = strtolower((string) ($payload['mode'] ?? 'app'));
        $projectId = (string) ($payload['project_id'] ?? '');

        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();

        if ($projectId === '') { $projectId = $manifest['id'] ?? 'default'; }
        if ($isAuthenticated && $authProjectId === '') { $authProjectId = $projectId; }

        if (!defined('TENANT_ID')) {
            if (is_numeric($tenantId)) {
                define('TENANT_ID', (int) $tenantId);
                putenv('TENANT_ID=' . $tenantId);
            } else {
                $hash = $this->stableTenantInt($tenantId);
                define('TENANT_ID', $hash);
                putenv('TENANT_ID=' . $hash);
                putenv('TENANT_KEY=' . $tenantId);
            }
        }
        $resolvedTenantId = (string) (defined('TENANT_ID') ? TENANT_ID : $tenantId);

        $sessionBinding = $registry->getSession($sessionId);

        if ($isAuthenticated && !is_array($sessionBinding)) {
            $registry->touchSession($sessionId, $userId, $projectId, $tenantId, $channel);
        }

        if (is_array($sessionBinding)) {
            $boundUser    = trim((string) ($sessionBinding['user_id'] ?? ''));
            $boundProject = trim((string) ($sessionBinding['project_id'] ?? ''));
            $boundTenant  = trim((string) ($sessionBinding['tenant_id'] ?? ''));

            if ($boundUser !== '' && $boundUser !== $userId) {
                return $this->error('Esta sesion pertenece a otro usuario.', $resolvedTenantId, $userId, $authUserId, $authTenantId, $projectId, $role, $mode);
            }
            if ($boundProject !== '' && $projectId !== '' && $boundProject !== $projectId) {
                return $this->error('Esta sesion ya esta enlazada a otro proyecto.', $resolvedTenantId, $userId, $authUserId, $authTenantId, $projectId, $role, $mode);
            }
            if ($boundTenant !== '' && $boundTenant !== $tenantId && $boundTenant !== $resolvedTenantId) {
                return $this->error('Esta sesion pertenece a otro tenant.', $resolvedTenantId, $userId, $authUserId, $authTenantId, $projectId, $role, $mode);
            }
            if ($boundProject !== '') { $projectId = $boundProject; }
        }

        $registry->ensureProject(
            $projectId,
            $manifest['name'] ?? 'Proyecto',
            $manifest['status'] ?? 'draft',
            $manifest['tenant_mode'] ?? 'shared',
            $userId,
            (string) ($manifest['storage_model'] ?? '')
        );
        $projectMeta  = $registry->getProject($projectId) ?? [];
        $storageModel = StorageModel::normalize((string) ($projectMeta['storage_model'] ?? StorageModel::LEGACY));
        putenv('PROJECT_STORAGE_MODEL=' . $storageModel);
        putenv('DB_STORAGE_MODEL=' . $storageModel);
        putenv('PROJECT_ID=' . $projectId);
        StorageModel::clearCache();
        TableNamespace::clearCache();
        $registry->touchSession($sessionId, $userId, $projectId, $tenantId, $channel);

        $roleBeforeAccessControl = $role;
        if (!$testMode && $isAuthenticated && $authUserId !== '' && $authTenantId !== '') {
            try {
                $accessControl = new TenantAccessControlService();
                $tenantUser = $accessControl->resolveTenantUser($authTenantId, $authUserId);
                if (is_array($tenantUser)) {
                    $tenantStatus = trim((string) ($tenantUser['status'] ?? ''));
                    $tenantRole   = trim((string) ($tenantUser['role_key'] ?? ''));
                    if ($tenantStatus === 'active' && $tenantRole !== '') {
                        $role = $tenantRole;
                    } elseif ($tenantStatus === 'inactive') {
                        $role = 'guest';
                    }
                }
            } catch (\Throwable $e) {
                // Keep legacy role fallback
            }
        }
        if ($role !== $roleBeforeAccessControl) {
            $registry->touchUser($userId, $role, $mode === 'builder' ? 'creator' : 'app', $tenantId);
            $registry->assignUserToProject($projectId, $userId, $role);
        }

        RoleContext::setRole($role);
        RoleContext::setUserId($userId);
        RoleContext::setUserLabel((string) ($payload['user_label'] ?? ''));

        return [
            'tenant_id'      => $resolvedTenantId,
            'user_id'        => $userId,
            'auth_user_id'   => $authUserId,
            'auth_tenant_id' => $authTenantId,
            'project_id'     => $projectId,
            'role'           => $role,
            'mode'           => $mode,
            'storage_model'  => $storageModel,
            'error'          => null,
        ];
    }

    private function error(string $message, string $tenantId, string $userId, string $authUserId, string $authTenantId, string $projectId, string $role, string $mode): array
    {
        return [
            'tenant_id'      => $tenantId,
            'user_id'        => $userId,
            'auth_user_id'   => $authUserId,
            'auth_tenant_id' => $authTenantId,
            'project_id'     => $projectId,
            'role'           => $role,
            'mode'           => $mode,
            'storage_model'  => '',
            'error'          => $message,
        ];
    }

    private function normalizeRole(string $role): string
    {
        $role = mb_strtolower(trim($role), 'UTF-8');
        $map = [
            'administrador' => 'admin',
            'admin'         => 'admin',
            'dueno'         => 'admin',
            'dueño'         => 'admin',
            'owner'         => 'admin',
            'vendedora'     => 'seller',
            'vendedor'      => 'seller',
            'seller'        => 'seller',
            'contadora'     => 'accountant',
            'contador'      => 'accountant',
            'accountant'    => 'accountant',
            'guest'         => 'guest',
        ];
        return $map[$role] ?? ($role !== '' ? $role : 'guest');
    }

    private function stableTenantInt(string $tenantId): int
    {
        $hash = crc32((string) $tenantId);
        $unsigned = (int) sprintf('%u', $hash);
        $max = 2147483647;
        $value = $unsigned % $max;
        return $value > 0 ? $value : 1;
    }
}
