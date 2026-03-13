<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class TenantAccessControlCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'TenantAddUser',
        'TenantListUsers',
        'TenantGetUserRole',
        'TenantUpdateUserRole',
        'TenantDeactivateUser',
        'TenantCheckPermission',
    ];

    public function supports(string $commandName): bool
    {
        return in_array($commandName, self::SUPPORTED, true);
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = strtolower(trim((string) ($context['mode'] ?? 'app')));
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');
        $actorUserId = trim((string) ($command['actor_user_id'] ?? $context['auth_user_id'] ?? $userId));
        $tenantId = trim((string) ($command['tenant_id'] ?? $context['tenant_id'] ?? ''));
        $projectId = trim((string) ($command['project_id'] ?? $context['project_id'] ?? ''));

        if ($mode === 'builder') {
            return $this->withReplyText($reply(
                'Estas en modo creador. Usa el chat de la app para operar acceso multiusuario.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData(['result_status' => 'error'])
            ));
        }

        $service = $context['tenant_access_control_service'] ?? null;
        if (!$service instanceof TenantAccessControlService) {
            $service = new TenantAccessControlService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'TenantAddUser' => $this->respondTenantUser(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    'Usuario agregado al tenant.',
                    'add_user',
                    $service->attachUserToTenant($command + [
                        'tenant_id' => $tenantId,
                        'project_id' => $projectId !== '' ? $projectId : null,
                    ])
                ),
                'TenantListUsers' => $this->respondTenantUserList(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->listTenantUsers($tenantId, array_filter([
                        'role_key' => $command['role_key'] ?? null,
                        'status' => $command['status'] ?? null,
                        'user_id' => $command['user_id'] ?? null,
                        'limit' => $command['limit'] ?? null,
                    ], static fn($value): bool => $value !== null && $value !== ''))
                ),
                'TenantGetUserRole' => $this->respondUserRole(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->getUserRoleInTenant($tenantId, trim((string) ($command['user_id'] ?? '')))
                ),
                'TenantUpdateUserRole' => $this->respondTenantUser(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    'Rol de usuario actualizado.',
                    'update_user_role',
                    $service->assignUserRole(
                        $tenantId,
                        trim((string) ($command['user_id'] ?? '')),
                        trim((string) ($command['role_key'] ?? '')),
                        [],
                        $projectId !== '' ? $projectId : null
                    )
                ),
                'TenantDeactivateUser' => $this->respondTenantUser(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    'Usuario desactivado en el tenant.',
                    'deactivate_user',
                    $service->deactivateTenantUser($tenantId, trim((string) ($command['user_id'] ?? '')))
                ),
                'TenantCheckPermission' => $this->respondPermissionCheck(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->checkPermission(
                        $tenantId,
                        trim((string) ($command['user_id'] ?? '')),
                        trim((string) ($command['module_key'] ?? '')),
                        trim((string) ($command['action_key'] ?? ''))
                    )
                ),
                default => throw new RuntimeException('COMMAND_NOT_SUPPORTED'),
            };
        } catch (Throwable $e) {
            return $this->withReplyText($reply(
                $this->humanizeError((string) $e->getMessage()),
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData(['result_status' => 'error'])
            ));
        }
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $tenantUser
     * @return array<string, mixed>
     */
    private function respondTenantUser(callable $reply, string $channel, string $sessionId, string $actorUserId, string $text, string $action, array $tenantUser): array
    {
        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'access_control_action' => $action,
            'tenant_user' => $tenantUser,
            'item' => $tenantUser,
            'target_user_id' => (string) ($tenantUser['user_id'] ?? ''),
            'actor_user_id' => $actorUserId,
            'role_key' => (string) ($tenantUser['role_key'] ?? ''),
            'decision' => $action === 'deactivate_user' ? 'deny' : 'allow',
            'result_status' => 'success',
        ])));
    }

    /**
     * @param callable $reply
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function respondTenantUserList(callable $reply, string $channel, string $sessionId, string $actorUserId, array $items): array
    {
        return $this->withReplyText($reply('Usuarios del tenant cargados.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'access_control_action' => 'list_users',
            'items' => $items,
            'result_count' => count($items),
            'actor_user_id' => $actorUserId,
            'decision' => 'allow',
            'result_status' => 'success',
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondUserRole(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        return $this->withReplyText($reply('Rol del usuario cargado.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'access_control_action' => 'get_user_role',
            'user_role' => $result,
            'item' => $result,
            'target_user_id' => (string) ($result['target_user_id'] ?? ''),
            'actor_user_id' => $actorUserId,
            'role_key' => (string) ($result['role_key'] ?? ''),
            'decision' => 'allow',
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondPermissionCheck(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        $text = ($result['allowed'] ?? false) === true
            ? 'El permiso solicitado esta permitido para ese usuario en el tenant.'
            : 'El permiso solicitado esta denegado para ese usuario en el tenant.';

        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'access_control_action' => 'check_permission',
            'permission_check' => $result,
            'item' => $result,
            'target_user_id' => (string) ($result['target_user_id'] ?? ''),
            'actor_user_id' => $actorUserId,
            'role_key' => (string) ($result['role_key'] ?? ''),
            'permission_checked' => (string) ($result['permission_checked'] ?? ''),
            'decision' => (string) ($result['decision'] ?? ''),
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function moduleData(array $extra = []): array
    {
        $action = trim((string) ($extra['access_control_action'] ?? ''));

        return $extra + [
            'module_used' => 'access_control',
            'access_control_action' => $action !== '' ? $action : 'none',
            'skill_group' => $action === 'check_permission' ? 'authorization' : 'tenant_users',
            'target_user_id' => '',
            'actor_user_id' => '',
            'role_key' => '',
            'permission_checked' => '',
            'decision' => '',
            'ambiguity_detected' => false,
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withReplyText(array $payload): array
    {
        if (!is_array($payload['data'] ?? null)) {
            $payload['data'] = [];
        }
        if (!array_key_exists('reply', $payload['data'])) {
            $payload['data']['reply'] = (string) ($payload['message'] ?? '');
        }

        return $payload;
    }

    private function humanizeError(string $error): string
    {
        return match ($error) {
            'TENANT_ID_REQUIRED' => 'Falta tenant_id para ejecutar la operacion de acceso.',
            'USER_ID_REQUIRED' => 'Falta user_id para ejecutar la operacion de acceso.',
            'ROLE_KEY_REQUIRED' => 'Falta role_key para ejecutar la operacion de acceso.',
            'MODULE_KEY_INVALID' => 'module_key no es valido para revisar permisos.',
            'ACTION_KEY_INVALID' => 'action_key no es valido para revisar permisos.',
            'ACCESS_CONTROL_INVALID_ROLE_KEY' => 'El rol no es valido. Usa owner, admin, manager, operator o viewer.',
            'ACCESS_CONTROL_INVALID_STATUS' => 'El estado del usuario no es valido. Usa invited, active o inactive.',
            'ACCESS_CONTROL_USER_NOT_FOUND' => 'No encontre ese usuario en el registry actual. Crea o autentica primero la cuenta.',
            'ACCESS_CONTROL_TENANT_USER_NOT_FOUND' => 'No encontre ese usuario dentro del tenant actual.',
            default => $error !== '' ? $error : 'No pude ejecutar la operacion de acceso.',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function replyCallable(array $context): callable
    {
        $reply = $context['reply'] ?? null;
        if (is_callable($reply)) {
            return $reply;
        }

        return static function (string $text, string $channel, string $sessionId, string $userId, string $status = 'success', array $data = []): array {
            return [
                'status' => $status,
                'message' => $status === 'success' ? 'OK' : $text,
                'data' => array_merge([
                    'reply' => $text,
                    'channel' => $channel,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                ], $data),
            ];
        };
    }
}
