<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class TenantAccessControlService
{
    /** @var array<int, string> */
    private const ROLE_KEYS = [
        'owner',
        'admin',
        'manager',
        'operator',
        'viewer',
    ];

    /** @var array<int, string> */
    private const USER_STATUSES = [
        'invited',
        'active',
        'inactive',
    ];

    private TenantAccessControlRepository $repository;
    private AuditLogger $auditLogger;
    private ProjectRegistry $projectRegistry;

    public function __construct(
        ?TenantAccessControlRepository $repository = null,
        ?AuditLogger $auditLogger = null,
        ?ProjectRegistry $projectRegistry = null
    ) {
        $this->repository = $repository ?? new TenantAccessControlRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->projectRegistry = $projectRegistry ?? new ProjectRegistry();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function attachUserToTenant(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $userId = $this->requireString($payload['user_id'] ?? $payload['target_user_id'] ?? null, 'user_id');
        $roleKey = $this->roleKey($payload['role_key'] ?? $payload['role'] ?? null);
        $status = $this->userStatus($payload['status'] ?? 'active');
        $metadata = is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [];
        $existingUser = $this->projectRegistry->getUser($userId);
        if (!is_array($existingUser)) {
            throw new RuntimeException('ACCESS_CONTROL_USER_NOT_FOUND');
        }

        $existing = $this->repository->findTenantUserByUserId($tenantId, $userId);
        $saved = $this->repository->upsertTenantUser([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_key' => $roleKey,
            'status' => $status,
            'invited_at' => $this->resolveInvitedAt($status, is_array($existing) ? $existing : null, $payload),
            'activated_at' => $this->resolveActivatedAt($status, is_array($existing) ? $existing : null, $payload),
            'metadata_json' => json_encode($this->mergeMetadata(
                is_array($existing) ? (array) ($existing['metadata'] ?? []) : [],
                $metadata,
                ['attached_by' => $this->nullableString($payload['actor_user_id'] ?? null)]
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_at' => is_array($existing) ? ($existing['created_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s'),
        ]);

        $projectId = $this->nullableString($payload['project_id'] ?? $payload['app_id'] ?? null);
        if ($projectId !== null) {
            $this->syncProjectRegistryAccess($projectId, $userId, $roleKey, $tenantId);
        }

        $result = $this->decorateTenantUser($saved);
        $this->auditLogger->log('tenant_user.attach', 'tenant_user', $result['id'] ?? null, [
            'tenant_id' => $tenantId,
            'target_user_id' => $userId,
            'role_key' => $roleKey,
            'status' => $status,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTenantUsers(string $tenantId, array $filters = []): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $limit = is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 25;
        $rows = $this->repository->listTenantUsers($tenantId, [
            'role_key' => $this->nullableString($filters['role_key'] ?? null),
            'status' => $this->nullableString($filters['status'] ?? null),
            'user_id' => $this->nullableString($filters['user_id'] ?? null),
        ], $limit);

        return array_map([$this, 'decorateTenantUser'], $rows);
    }

    public function countTenantUsers(string $tenantId, array $filters = []): int
    {
        return $this->repository->countTenantUsers($this->requireString($tenantId, 'tenant_id'), [
            'role_key' => $this->nullableString($filters['role_key'] ?? null),
            'status' => $this->nullableString($filters['status'] ?? null),
            'user_id' => $this->nullableString($filters['user_id'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserRoleInTenant(string $tenantId, string $userId): array
    {
        $tenantUser = $this->requireTenantUser($tenantId, $userId);
        $roleKey = (string) ($tenantUser['role_key'] ?? 'viewer');

        return [
            'tenant_id' => $tenantId,
            'target_user_id' => $userId,
            'role_key' => $roleKey,
            'status' => (string) ($tenantUser['status'] ?? 'inactive'),
            'effective_permissions' => $this->resolveEffectivePermissions($tenantId, $roleKey),
            'tenant_user' => $this->decorateTenantUser($tenantUser),
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function assignUserRole(string $tenantId, string $userId, string $roleKey, array $metadata = [], ?string $projectId = null): array
    {
        $tenantUser = $this->requireTenantUser($tenantId, $userId);
        $updated = $this->repository->updateTenantUserByUserId($tenantId, $userId, [
            'role_key' => $this->roleKey($roleKey),
            'metadata_json' => json_encode($this->mergeMetadata(
                is_array($tenantUser['metadata'] ?? null) ? (array) $tenantUser['metadata'] : [],
                $metadata,
                ['updated_at' => date('Y-m-d H:i:s')]
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);
        if (!is_array($updated)) {
            throw new RuntimeException('ACCESS_CONTROL_TENANT_USER_NOT_FOUND');
        }

        if ($projectId !== null && $projectId !== '') {
            $this->syncProjectRegistryAccess($projectId, $userId, (string) ($updated['role_key'] ?? 'viewer'), $tenantId);
        }

        $result = $this->decorateTenantUser($updated);
        $this->auditLogger->log('tenant_user.update_role', 'tenant_user', $result['id'] ?? null, [
            'tenant_id' => $tenantId,
            'target_user_id' => $userId,
            'role_key' => $result['role_key'] ?? 'viewer',
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function deactivateTenantUser(string $tenantId, string $userId, array $metadata = []): array
    {
        $tenantUser = $this->requireTenantUser($tenantId, $userId);
        $updated = $this->repository->updateTenantUserByUserId($tenantId, $userId, [
            'status' => 'inactive',
            'metadata_json' => json_encode($this->mergeMetadata(
                is_array($tenantUser['metadata'] ?? null) ? (array) $tenantUser['metadata'] : [],
                $metadata,
                ['deactivated_at' => date('Y-m-d H:i:s')]
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);
        if (!is_array($updated)) {
            throw new RuntimeException('ACCESS_CONTROL_TENANT_USER_NOT_FOUND');
        }

        $result = $this->decorateTenantUser($updated);
        $this->auditLogger->log('tenant_user.deactivate', 'tenant_user', $result['id'] ?? null, [
            'tenant_id' => $tenantId,
            'target_user_id' => $userId,
            'role_key' => $result['role_key'] ?? 'viewer',
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function checkPermission(
        string $tenantId,
        string $userId,
        string $moduleKey,
        string $actionKey,
        array $options = []
    ): array {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $userId = $this->requireString($userId, 'user_id');
        $moduleKey = $this->permissionToken($moduleKey, 'module_key');
        $actionKey = $this->permissionToken($actionKey, 'action_key');
        $requiredRole = $this->nullableString($options['required_role'] ?? null);
        $fallbackRole = $this->nullableString($options['fallback_role'] ?? null);
        $allowLegacyFallback = (($options['allow_legacy_fallback'] ?? false) === true);

        $tenantUser = $this->repository->findTenantUserByUserId($tenantId, $userId);
        if (!is_array($tenantUser)) {
            if ($allowLegacyFallback && $fallbackRole !== null) {
                $allowed = $requiredRole !== null
                    ? $this->roleSatisfies($fallbackRole, $requiredRole)
                    : false;

                return $this->permissionDecisionPayload(
                    $tenantId,
                    $userId,
                    $this->normalizeRoleForComparison($fallbackRole),
                    $moduleKey,
                    $actionKey,
                    $allowed,
                    'legacy_role_fallback',
                    null,
                    $allowed ? 'allowed' : 'denied'
                );
            }

            return $this->permissionDecisionPayload(
                $tenantId,
                $userId,
                '',
                $moduleKey,
                $actionKey,
                false,
                'membership_missing',
                null,
                'denied'
            );
        }

        $roleKey = (string) ($tenantUser['role_key'] ?? 'viewer');
        $status = (string) ($tenantUser['status'] ?? 'inactive');
        if ($status !== 'active') {
            return $this->permissionDecisionPayload(
                $tenantId,
                $userId,
                $roleKey,
                $moduleKey,
                $actionKey,
                false,
                'tenant_user_inactive',
                null,
                'denied',
                $tenantUser
            );
        }

        if ($requiredRole !== null && !$this->roleSatisfies($roleKey, $requiredRole)) {
            return $this->permissionDecisionPayload(
                $tenantId,
                $userId,
                $roleKey,
                $moduleKey,
                $actionKey,
                false,
                'required_role_not_met',
                null,
                'denied',
                $tenantUser
            );
        }

        $matching = $this->matchingPermissions($tenantId, $roleKey, $moduleKey, $actionKey);
        foreach ($matching as $permission) {
            if ((string) ($permission['effect'] ?? '') === 'deny') {
                return $this->permissionDecisionPayload(
                    $tenantId,
                    $userId,
                    $roleKey,
                    $moduleKey,
                    $actionKey,
                    false,
                    'role_permission',
                    $permission,
                    'denied',
                    $tenantUser
                );
            }
        }
        foreach ($matching as $permission) {
            if ((string) ($permission['effect'] ?? '') === 'allow') {
                return $this->permissionDecisionPayload(
                    $tenantId,
                    $userId,
                    $roleKey,
                    $moduleKey,
                    $actionKey,
                    true,
                    'role_permission',
                    $permission,
                    'allowed',
                    $tenantUser
                );
            }
        }

        return $this->permissionDecisionPayload(
            $tenantId,
            $userId,
            $roleKey,
            $moduleKey,
            $actionKey,
            false,
            'permission_missing',
            null,
            'denied',
            $tenantUser
        );
    }

    /**
     * @return array<int, string>
     */
    public function resolveEffectivePermissions(string $tenantId, string $roleKey): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $roleKey = $this->roleKey($roleKey);
        $permissions = $this->repository->listRolePermissions($tenantId, $roleKey);
        $resolved = [];
        foreach ($permissions as $permission) {
            $resolved[] = (string) ($permission['module_key'] ?? '*')
                . '.'
                . (string) ($permission['action_key'] ?? '*')
                . ':'
                . (string) ($permission['effect'] ?? 'deny');
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveTenantUser(string $tenantId, string $userId): ?array
    {
        $tenantUser = $this->repository->findTenantUserByUserId($tenantId, $userId);
        return is_array($tenantUser) ? $this->decorateTenantUser($tenantUser) : null;
    }

    /**
     * @param array<string, mixed> $tenantUser
     * @return array<string, mixed>
     */
    private function decorateTenantUser(array $tenantUser): array
    {
        $userId = (string) ($tenantUser['user_id'] ?? '');
        $knownUser = $userId !== '' ? $this->projectRegistry->getUser($userId) : null;

        return [
            'id' => (string) ($tenantUser['id'] ?? ''),
            'tenant_id' => (string) ($tenantUser['tenant_id'] ?? ''),
            'user_id' => $userId,
            'user_label' => is_array($knownUser) ? (string) ($knownUser['label'] ?? $userId) : $userId,
            'role_key' => (string) ($tenantUser['role_key'] ?? 'viewer'),
            'status' => (string) ($tenantUser['status'] ?? 'inactive'),
            'invited_at' => $this->nullableString($tenantUser['invited_at'] ?? null),
            'activated_at' => $this->nullableString($tenantUser['activated_at'] ?? null),
            'metadata' => is_array($tenantUser['metadata'] ?? null) ? (array) $tenantUser['metadata'] : [],
            'created_at' => (string) ($tenantUser['created_at'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionDecisionPayload(
        string $tenantId,
        string $userId,
        string $roleKey,
        string $moduleKey,
        string $actionKey,
        bool $allowed,
        string $source,
        ?array $matchedPermission,
        string $resultStatus,
        ?array $tenantUser = null
    ): array {
        return [
            'tenant_id' => $tenantId,
            'target_user_id' => $userId,
            'role_key' => $roleKey,
            'module_key' => $moduleKey,
            'action_key' => $actionKey,
            'permission_checked' => $moduleKey . '.' . $actionKey,
            'allowed' => $allowed,
            'decision' => $allowed ? 'allow' : 'deny',
            'permission_source' => $source,
            'matched_permission' => $matchedPermission,
            'tenant_user' => is_array($tenantUser) ? $this->decorateTenantUser($tenantUser) : null,
            'effective_permissions' => $roleKey !== '' ? $this->resolveEffectivePermissions($tenantId, $roleKey) : [],
            'result_status' => $resultStatus,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function matchingPermissions(string $tenantId, string $roleKey, string $moduleKey, string $actionKey): array
    {
        $permissions = $this->repository->listRolePermissions($tenantId, $roleKey);
        $matched = [];
        foreach ($permissions as $permission) {
            if ($this->permissionMatches(
                (string) ($permission['module_key'] ?? '*'),
                (string) ($permission['action_key'] ?? '*'),
                $moduleKey,
                $actionKey
            )) {
                $matched[] = $permission;
            }
        }

        usort($matched, static function (array $left, array $right): int {
            $leftTenantScoped = ($left['tenant_id'] ?? null) !== null;
            $rightTenantScoped = ($right['tenant_id'] ?? null) !== null;
            if ($leftTenantScoped !== $rightTenantScoped) {
                return $leftTenantScoped ? -1 : 1;
            }
            $leftWildcardCount = (((string) ($left['module_key'] ?? '')) === '*' ? 1 : 0)
                + (((string) ($left['action_key'] ?? '')) === '*' ? 1 : 0);
            $rightWildcardCount = (((string) ($right['module_key'] ?? '')) === '*' ? 1 : 0)
                + (((string) ($right['action_key'] ?? '')) === '*' ? 1 : 0);
            return $leftWildcardCount <=> $rightWildcardCount;
        });

        return $matched;
    }

    private function permissionMatches(string $permissionModule, string $permissionAction, string $moduleKey, string $actionKey): bool
    {
        $permissionModule = trim($permissionModule) !== '' ? trim($permissionModule) : '*';
        $permissionAction = trim($permissionAction) !== '' ? trim($permissionAction) : '*';

        $moduleMatches = $permissionModule === '*' || $permissionModule === $moduleKey;
        $actionMatches = $permissionAction === '*' || $permissionAction === $actionKey;

        return $moduleMatches && $actionMatches;
    }

    private function syncProjectRegistryAccess(string $projectId, string $userId, string $roleKey, string $tenantId): void
    {
        $knownUser = $this->projectRegistry->getUser($userId);
        $label = is_array($knownUser) ? ((string) ($knownUser['label'] ?? $userId)) : $userId;
        $type = is_array($knownUser) ? ((string) ($knownUser['type'] ?? 'auth')) : 'auth';

        $this->projectRegistry->touchUser($userId, $roleKey, $type, $tenantId, $label);
        $this->projectRegistry->assignUserToProject($projectId, $userId, $roleKey);
        $this->projectRegistry->syncAuthUserContext($projectId, $userId, $roleKey, $tenantId);
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $payload
     */
    private function resolveInvitedAt(string $status, ?array $existing, array $payload): ?string
    {
        if (array_key_exists('invited_at', $payload)) {
            return $this->nullableString($payload['invited_at']);
        }
        $existingValue = is_array($existing) ? $this->nullableString($existing['invited_at'] ?? null) : null;
        if ($existingValue !== null) {
            return $existingValue;
        }
        if ($status === 'invited' || $status === 'active') {
            return date('Y-m-d H:i:s');
        }
        return null;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $payload
     */
    private function resolveActivatedAt(string $status, ?array $existing, array $payload): ?string
    {
        if (array_key_exists('activated_at', $payload)) {
            return $this->nullableString($payload['activated_at']);
        }
        $existingValue = is_array($existing) ? $this->nullableString($existing['activated_at'] ?? null) : null;
        if ($status === 'active') {
            return $existingValue ?? date('Y-m-d H:i:s');
        }
        return $existingValue;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function mergeMetadata(array $base, array $incoming, array $extra = []): array
    {
        return array_filter(array_merge($base, $incoming, $extra), static fn($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireTenantUser(string $tenantId, string $userId): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $userId = $this->requireString($userId, 'user_id');
        $tenantUser = $this->repository->findTenantUserByUserId($tenantId, $userId);
        if (!is_array($tenantUser)) {
            throw new RuntimeException('ACCESS_CONTROL_TENANT_USER_NOT_FOUND');
        }

        return $tenantUser;
    }

    private function requireString(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        }
        return $value;
    }

    private function roleKey(mixed $value): string
    {
        $normalized = $this->normalizeRoleForComparison((string) $value);
        if (!in_array($normalized, self::ROLE_KEYS, true)) {
            throw new RuntimeException('ACCESS_CONTROL_INVALID_ROLE_KEY');
        }

        return $normalized;
    }

    private function normalizeRoleForComparison(string $role): string
    {
        $role = strtolower(trim($role));
        $map = [
            'owner' => 'owner',
            'dueno' => 'owner',
            'dueño' => 'owner',
            'propietario' => 'owner',
            'admin' => 'admin',
            'administrador' => 'admin',
            'manager' => 'manager',
            'gerente' => 'manager',
            'operator' => 'operator',
            'operador' => 'operator',
            'seller' => 'operator',
            'vendedor' => 'operator',
            'vendedora' => 'operator',
            'accountant' => 'operator',
            'contador' => 'operator',
            'contadora' => 'operator',
            'viewer' => 'viewer',
            'lector' => 'viewer',
            'consulta' => 'viewer',
            'guest' => 'viewer',
        ];

        return $map[$role] ?? $role;
    }

    private function userStatus(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::USER_STATUSES, true)) {
            throw new RuntimeException('ACCESS_CONTROL_INVALID_STATUS');
        }

        return $value;
    }

    private function permissionToken(string $value, string $field): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || preg_match('/^[a-z0-9_*]+$/', $normalized) !== 1) {
            throw new RuntimeException(strtoupper($field) . '_INVALID');
        }

        return $normalized;
    }

    private function roleSatisfies(string $actualRole, string $requiredRole): bool
    {
        $actual = $this->normalizeRoleForComparison($actualRole);
        $required = $this->normalizeRoleForComparison($requiredRole);
        $order = [
            'viewer' => 1,
            'operator' => 2,
            'manager' => 3,
            'admin' => 4,
            'owner' => 5,
        ];

        if (!isset($order[$actual]) || !isset($order[$required])) {
            return $actual === $required;
        }

        return $order[$actual] >= $order[$required];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
