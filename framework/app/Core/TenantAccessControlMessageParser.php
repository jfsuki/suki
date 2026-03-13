<?php

declare(strict_types=1);

namespace App\Core;

final class TenantAccessControlMessageParser
{
    private string $message = '';

    /** @var array<string, string> */
    private array $pairs = [];

    /** @var array<string, mixed> */
    private array $context = [];

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $this->context = $context;
        $this->message = trim((string) ($context['message_text'] ?? ''));
        $this->pairs = $this->extractKeyValuePairs($this->message);

        $actorUserId = trim((string) ($context['auth_user_id'] ?? $context['user_id'] ?? '')) ?: 'system';
        $telemetry = $this->baseTelemetry($skillName, $actorUserId);
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'project_id' => ($projectId = trim((string) ($context['project_id'] ?? ''))) !== '' ? $projectId : null,
            'actor_user_id' => $actorUserId,
            'requested_by_user_id' => trim((string) ($context['user_id'] ?? '')) ?: $actorUserId,
        ];

        return match ($skillName) {
            'tenant_add_user' => $this->parseAddUser($baseCommand, $telemetry),
            'tenant_list_users' => $this->parseListUsers($baseCommand, $telemetry),
            'tenant_get_user_role' => $this->parseGetUserRole($baseCommand, $telemetry),
            'tenant_update_user_role' => $this->parseUpdateUserRole($baseCommand, $telemetry),
            'tenant_deactivate_user' => $this->parseDeactivateUser($baseCommand, $telemetry),
            'tenant_check_permission' => $this->parseCheckPermission($baseCommand, $telemetry),
            default => $this->askUser('No pude interpretar la operacion de acceso multiusuario.', $telemetry),
        };
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseAddUser(array $baseCommand, array $telemetry): array
    {
        $targetUserId = $this->targetUserId();
        $roleKey = $this->roleKey();
        if ($targetUserId === '' || $roleKey === '') {
            return $this->askUser(
                'Indica `user_id` y `role_key` para agregar el usuario al tenant. Roles: owner, admin, manager, operator, viewer.',
                $this->telemetry($telemetry, 'add_user', [
                    'target_user_id' => $targetUserId,
                    'role_key' => $roleKey,
                    'result_status' => 'needs_input',
                ])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'TenantAddUser',
            'user_id' => $targetUserId,
            'role_key' => $roleKey,
            'status' => ($status = $this->statusValue()) !== '' ? $status : 'active',
        ], $this->telemetry($telemetry, 'add_user', [
            'target_user_id' => $targetUserId,
            'role_key' => $roleKey,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListUsers(array $baseCommand, array $telemetry): array
    {
        return $this->commandResult($baseCommand + [
            'command' => 'TenantListUsers',
            'role_key' => ($roleKey = $this->roleKey()) !== '' ? $roleKey : null,
            'status' => ($status = $this->statusValue()) !== '' ? $status : null,
            'limit' => ($limit = $this->limitValue()) > 0 ? $limit : null,
        ], $this->telemetry($telemetry, 'list_users', [
            'role_key' => $roleKey ?? '',
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetUserRole(array $baseCommand, array $telemetry): array
    {
        $targetUserId = $this->targetUserId();
        if ($targetUserId === '' && preg_match('/\bmi rol\b/u', mb_strtolower($this->message, 'UTF-8')) === 1) {
            $targetUserId = trim((string) ($baseCommand['actor_user_id'] ?? ''));
        }
        if ($targetUserId === '') {
            return $this->askUser(
                'Indica `user_id` para consultar el rol dentro del tenant.',
                $this->telemetry($telemetry, 'get_user_role', ['result_status' => 'needs_input'])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'TenantGetUserRole',
            'user_id' => $targetUserId,
        ], $this->telemetry($telemetry, 'get_user_role', [
            'target_user_id' => $targetUserId,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseUpdateUserRole(array $baseCommand, array $telemetry): array
    {
        $targetUserId = $this->targetUserId();
        $roleKey = $this->roleKey();
        if ($targetUserId === '' || $roleKey === '') {
            return $this->askUser(
                'Indica `user_id` y `role_key` para actualizar el rol del usuario.',
                $this->telemetry($telemetry, 'update_user_role', [
                    'target_user_id' => $targetUserId,
                    'role_key' => $roleKey,
                    'result_status' => 'needs_input',
                ])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'TenantUpdateUserRole',
            'user_id' => $targetUserId,
            'role_key' => $roleKey,
        ], $this->telemetry($telemetry, 'update_user_role', [
            'target_user_id' => $targetUserId,
            'role_key' => $roleKey,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseDeactivateUser(array $baseCommand, array $telemetry): array
    {
        $targetUserId = $this->targetUserId();
        if ($targetUserId === '') {
            return $this->askUser(
                'Indica `user_id` para desactivar el acceso del usuario.',
                $this->telemetry($telemetry, 'deactivate_user', ['result_status' => 'needs_input'])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'TenantDeactivateUser',
            'user_id' => $targetUserId,
        ], $this->telemetry($telemetry, 'deactivate_user', [
            'target_user_id' => $targetUserId,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCheckPermission(array $baseCommand, array $telemetry): array
    {
        $targetUserId = $this->targetUserId();
        if ($targetUserId === '' && preg_match('/\b(mi permiso|mis permisos|puedo ejecutar)\b/u', mb_strtolower($this->message, 'UTF-8')) === 1) {
            $targetUserId = trim((string) ($baseCommand['actor_user_id'] ?? ''));
        }

        $permission = $this->permissionCheckedValue();
        $moduleKey = ($module = $this->firstValue($this->pairs, ['module_key', 'module', 'modulo'])) !== ''
            ? $module
            : ($permission['module_key'] ?? '');
        $actionKey = ($action = $this->firstValue($this->pairs, ['action_key', 'action', 'accion'])) !== ''
            ? $action
            : ($permission['action_key'] ?? '');
        if ($targetUserId === '' || $moduleKey === '' || $actionKey === '') {
            return $this->askUser(
                'Indica `user_id` y el permiso a revisar con `module_key` + `action_key` o `permiso=ecommerce.link_product`.',
                $this->telemetry($telemetry, 'check_permission', [
                    'target_user_id' => $targetUserId,
                    'permission_checked' => $moduleKey !== '' && $actionKey !== '' ? $moduleKey . '.' . $actionKey : '',
                    'result_status' => 'needs_input',
                ])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'TenantCheckPermission',
            'user_id' => $targetUserId,
            'module_key' => $moduleKey,
            'action_key' => $actionKey,
        ], $this->telemetry($telemetry, 'check_permission', [
            'target_user_id' => $targetUserId,
            'permission_checked' => $moduleKey . '.' . $actionKey,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseTelemetry(string $skillName, string $actorUserId): array
    {
        $action = $this->actionFromSkillName($skillName);

        return [
            'module_used' => 'access_control',
            'access_control_action' => $action,
            'skill_group' => $this->skillGroup($skillName),
            'target_user_id' => '',
            'actor_user_id' => $actorUserId,
            'role_key' => '',
            'permission_checked' => '',
            'decision' => '',
            'ambiguity_detected' => false,
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $telemetry
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function telemetry(array $telemetry, string $action, array $extra = []): array
    {
        $merged = array_merge($telemetry, [
            'module_used' => 'access_control',
            'access_control_action' => $action,
            'skill_group' => $this->skillGroupFromAction($action),
        ], $extra);

        $merged['ambiguity_detected'] = (($merged['ambiguity_detected'] ?? false) === true);
        $merged['result_status'] = trim((string) ($merged['result_status'] ?? '')) ?: 'success';

        return $merged;
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function commandResult(array $command, array $telemetry): array
    {
        return ['kind' => 'command', 'command' => $command, 'telemetry' => $telemetry];
    }

    /**
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function askUser(string $reply, array $telemetry): array
    {
        return ['kind' => 'ask_user', 'reply' => $reply, 'telemetry' => $telemetry];
    }

    private function actionFromSkillName(string $skillName): string
    {
        return match ($skillName) {
            'tenant_add_user' => 'add_user',
            'tenant_list_users' => 'list_users',
            'tenant_get_user_role' => 'get_user_role',
            'tenant_update_user_role' => 'update_user_role',
            'tenant_deactivate_user' => 'deactivate_user',
            'tenant_check_permission' => 'check_permission',
            default => 'none',
        };
    }

    private function skillGroup(string $skillName): string
    {
        return match ($skillName) {
            'tenant_check_permission' => 'authorization',
            default => 'tenant_users',
        };
    }

    private function skillGroupFromAction(string $action): string
    {
        return $action === 'check_permission' ? 'authorization' : 'tenant_users';
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+)=("([^"]*)"|\'([^\']*)\'|([^\\s]+))/u', $message, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = strtolower(trim((string) ($match[1] ?? '')));
            $value = '';
            foreach ([3, 4, 5] as $index) {
                if (isset($match[$index]) && $match[$index] !== '') {
                    $value = trim((string) $match[$index]);
                    break;
                }
            }
            if ($key !== '' && $value !== '') {
                $pairs[$key] = $value;
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, string> $pairs
     * @param array<int, string> $aliases
     */
    private function firstValue(array $pairs, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $alias = strtolower(trim($alias));
            if ($alias !== '' && array_key_exists($alias, $pairs)) {
                return trim((string) $pairs[$alias]);
            }
        }

        return '';
    }

    private function targetUserId(): string
    {
        $direct = $this->firstValue($this->pairs, ['target_user_id', 'user_id', 'usuario', 'user']);
        if ($direct !== '') {
            return $direct;
        }
        if (preg_match('/\b(?:usuario|user|miembro)\s+([a-zA-Z0-9_.@-]+)/u', $this->message, $match) === 1) {
            return trim((string) ($match[1] ?? ''));
        }

        return '';
    }

    private function roleKey(): string
    {
        $role = $this->firstValue($this->pairs, ['role_key', 'role', 'rol']);
        if ($role !== '') {
            return $this->normalizeRoleKey($role);
        }
        if (preg_match('/\b(?:como|a)\s+(owner|admin|manager|operator|viewer|propietario|administrador|gerente|operador|lector)\b/iu', $this->message, $match) === 1) {
            return $this->normalizeRoleKey((string) ($match[1] ?? ''));
        }

        return '';
    }

    private function normalizeRoleKey(string $role): string
    {
        $role = mb_strtolower(trim($role), 'UTF-8');
        return match ($role) {
            'owner', 'propietario', 'dueno', 'dueño' => 'owner',
            'admin', 'administrador' => 'admin',
            'manager', 'gerente' => 'manager',
            'operator', 'operador', 'seller', 'vendedor', 'accountant', 'contador' => 'operator',
            'viewer', 'lector', 'consulta', 'guest' => 'viewer',
            default => '',
        };
    }

    private function statusValue(): string
    {
        return $this->firstValue($this->pairs, ['status', 'estado']);
    }

    private function limitValue(): int
    {
        $value = $this->firstValue($this->pairs, ['limit', 'limite']);
        return is_numeric($value) ? max(1, (int) $value) : 0;
    }

    /**
     * @return array{module_key:string, action_key:string}
     */
    private function permissionCheckedValue(): array
    {
        $raw = $this->firstValue($this->pairs, ['permission', 'permiso']);
        if ($raw === '' && preg_match('/\b([a-z_]+)\.([a-z_*]+)\b/u', strtolower($this->message), $match) === 1) {
            $raw = trim((string) ($match[0] ?? ''));
        }
        if ($raw === '' || !str_contains($raw, '.')) {
            return ['module_key' => '', 'action_key' => ''];
        }

        [$moduleKey, $actionKey] = array_pad(explode('.', strtolower($raw), 2), 2, '');

        return [
            'module_key' => trim($moduleKey),
            'action_key' => trim($actionKey),
        ];
    }
}
