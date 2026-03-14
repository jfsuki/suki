<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class AgentToolsIntegrationCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'AgentListToolGroups',
        'AgentGetModuleCapabilities',
        'AgentResolveToolForRequest',
        'AgentCheckModuleEnabled',
        'AgentCheckActionAllowed',
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
                'Estas en modo creador. Usa el chat de la app para descubrir herramientas operativas del agente.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData(['result_status' => 'error'])
            ));
        }

        $service = $context['agent_tools_integration_service'] ?? null;
        if (!$service instanceof AgentToolsIntegrationService) {
            $service = new AgentToolsIntegrationService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'AgentListToolGroups' => $this->respondListToolGroups(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->listToolGroups(
                        $tenantId,
                        $actorUserId,
                        $projectId !== '' ? $projectId : null,
                        ['fallback_role' => $command['fallback_role'] ?? null]
                    )
                ),
                'AgentGetModuleCapabilities' => $this->respondModuleCapabilities(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->getModuleCapabilities(
                        $tenantId,
                        $actorUserId,
                        trim((string) ($command['module_key'] ?? '')),
                        $projectId !== '' ? $projectId : null,
                        ['fallback_role' => $command['fallback_role'] ?? null]
                    )
                ),
                'AgentResolveToolForRequest' => $this->respondResolveRequest(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->resolveToolForRequest($command + [
                        'tenant_id' => $tenantId,
                        'user_id' => $actorUserId,
                        'project_id' => $projectId !== '' ? $projectId : null,
                    ])
                ),
                'AgentCheckModuleEnabled' => $this->respondCheckModuleEnabled(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->checkModuleEnabled(
                        $tenantId,
                        trim((string) ($command['module_key'] ?? '')),
                        $projectId !== '' ? $projectId : null
                    )
                ),
                'AgentCheckActionAllowed' => $this->respondCheckActionAllowed(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->checkActionAllowed(
                        $tenantId,
                        $actorUserId,
                        trim((string) ($command['module_key'] ?? '')),
                        trim((string) ($command['action_key'] ?? '')),
                        $projectId !== '' ? $projectId : null,
                        ['fallback_role' => $command['fallback_role'] ?? null]
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
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondListToolGroups(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        return $this->withReplyText($reply('Herramientas del agente cargadas.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'agent_tools_action' => 'list_tool_groups',
            'items' => $result['tool_groups'] ?? [],
            'result_count' => (int) ($result['result_count'] ?? 0),
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondModuleCapabilities(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        $text = (($result['enabled'] ?? false) === true && ($result['allowed'] ?? false) === true)
            ? 'Capacidades del modulo cargadas.'
            : ((($result['enabled'] ?? false) !== true)
                ? 'El modulo consultado no esta habilitado para este tenant.'
                : 'El modulo consultado existe, pero no esta permitido para este usuario.');

        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'agent_tools_action' => 'get_module_capabilities',
            'requested_module' => (string) ($result['module_key'] ?? ''),
            'resolved_module' => (string) ($result['module_key'] ?? ''),
            'enabled' => $result['enabled'] ?? null,
            'allowed' => $result['allowed'] ?? null,
            'tool_group' => (string) ($result['tool_group'] ?? ''),
            'item' => $result,
            'actions' => $result['actions'] ?? [],
            'result_count' => (int) ($result['action_count'] ?? 0),
            'actor_user_id' => $actorUserId,
            'denial_reason' => (string) ($result['denial_reason'] ?? ''),
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondResolveRequest(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        $text = trim((string) ($result['reply_hint'] ?? ''));
        if ($text === '') {
            $text = match ((string) ($result['result_status'] ?? 'resolved')) {
                'ambiguous' => 'Necesito que aclares el modulo correcto antes de continuar.',
                'needs_input' => 'Falta un dato critico para continuar con la herramienta correcta.',
                'module_disabled' => 'Identifique el modulo correcto, pero no esta habilitado por el plan actual.',
                'permission_denied' => 'Identifique el modulo correcto, pero no esta permitido para este usuario.',
                'unresolved' => 'No detecte una herramienta segura para esta solicitud.',
                default => 'Herramienta resuelta para esta solicitud.',
            };
        }

        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'agent_tools_action' => 'resolve_tool_for_request',
            'requested_module' => (string) ($result['requested_module'] ?? ''),
            'resolved_module' => (string) ($result['resolved_module'] ?? ''),
            'enabled' => $result['enabled'] ?? null,
            'allowed' => $result['allowed'] ?? null,
            'tool_group' => (string) ($result['tool_group'] ?? ''),
            'ambiguity_detected' => (($result['ambiguity_detected'] ?? false) === true),
            'denial_reason' => (string) ($result['denial_reason'] ?? ''),
            'item' => $result,
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondCheckModuleEnabled(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        $text = (($result['enabled'] ?? false) === true)
            ? 'El modulo consultado esta habilitado.'
            : 'El modulo consultado no esta habilitado para este tenant.';

        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'agent_tools_action' => 'check_module_enabled',
            'requested_module' => (string) ($result['module_key'] ?? ''),
            'resolved_module' => (string) ($result['module_key'] ?? ''),
            'enabled' => $result['enabled'] ?? null,
            'allowed' => $result['allowed'] ?? null,
            'tool_group' => (string) ($result['tool_group'] ?? ''),
            'denial_reason' => (string) ($result['denial_reason'] ?? ''),
            'item' => $result,
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondCheckActionAllowed(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        $text = (($result['allowed'] ?? false) === true)
            ? 'La accion consultada esta permitida.'
            : 'La accion consultada no esta permitida para este usuario.';

        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'agent_tools_action' => 'check_action_allowed',
            'requested_module' => (string) ($result['module_key'] ?? ''),
            'resolved_module' => (string) ($result['module_key'] ?? ''),
            'enabled' => $result['enabled'] ?? null,
            'allowed' => $result['allowed'] ?? null,
            'tool_group' => (string) ($result['tool_group'] ?? ''),
            'permission_checked' => (string) ($result['permission_checked'] ?? ''),
            'decision' => (string) ($result['decision'] ?? ''),
            'denial_reason' => (string) ($result['denial_reason'] ?? ''),
            'item' => $result,
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function moduleData(array $extra = []): array
    {
        return $extra + [
            'module_used' => 'agent_tools_integration',
            'agent_tools_action' => trim((string) ($extra['agent_tools_action'] ?? '')) ?: 'none',
            'skill_group' => in_array((string) ($extra['agent_tools_action'] ?? ''), ['resolve_tool_for_request', 'check_action_allowed'], true)
                ? 'orchestration'
                : 'capability_discovery',
            'requested_module' => '',
            'resolved_module' => '',
            'tool_group' => '',
            'enabled' => null,
            'allowed' => null,
            'denial_reason' => '',
            'permission_checked' => '',
            'decision' => '',
            'actor_user_id' => '',
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
            'TENANT_ID_REQUIRED' => 'Falta tenant_id para usar las herramientas del agente.',
            'USER_ID_REQUIRED' => 'Falta user_id para usar las herramientas del agente.',
            'AGENT_TOOLS_MODULE_KEY_INVALID' => 'El modulo solicitado no es valido para la integracion de herramientas.',
            'AGENT_TOOLS_REQUEST_TEXT_REQUIRED' => 'Falta la solicitud que quieres resolver con herramientas del agente.',
            default => $error !== '' ? $error : 'No pude ejecutar la integracion de herramientas del agente.',
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
