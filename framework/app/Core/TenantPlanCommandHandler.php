<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class TenantPlanCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'TenantAssignPlan',
        'TenantGetPlan',
        'TenantListPlans',
        'TenantSetPlanLimits',
        'TenantCheckPlanLimit',
        'TenantGetEnabledModules',
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
                'Estas en modo creador. Usa el chat de la app para operar planes SaaS del tenant.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData(['result_status' => 'error'])
            ));
        }

        $service = $context['tenant_plan_service'] ?? null;
        if (!$service instanceof TenantPlanService) {
            $service = new TenantPlanService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'TenantAssignPlan' => $this->respondPlan(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    'Plan asignado al tenant.',
                    'assign_plan',
                    $service->assignPlanToTenant($command + ['tenant_id' => $tenantId, 'project_id' => $projectId !== '' ? $projectId : null])
                ),
                'TenantGetPlan' => $this->respondPlan(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    'Plan del tenant cargado.',
                    'get_plan',
                    $service->getCurrentTenantPlan($tenantId, $projectId !== '' ? $projectId : null)
                ),
                'TenantListPlans' => $this->respondPlanList(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->listAvailablePlans()
                ),
                'TenantSetPlanLimits' => $this->respondPlanLimits(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->setPlanLimits(
                        trim((string) ($command['plan_key'] ?? '')),
                        is_array($command['limits'] ?? null) ? (array) $command['limits'] : [],
                        $actorUserId
                    )
                ),
                'TenantCheckPlanLimit' => $this->respondPlanLimitCheck(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->checkTenantPlanLimit(
                        $tenantId,
                        trim((string) ($command['limit_key'] ?? '')),
                        $command['usage_value'] ?? null,
                        $projectId !== '' ? $projectId : null
                    )
                ),
                'TenantGetEnabledModules' => $this->respondEnabledModules(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->getEnabledModules($tenantId, $projectId !== '' ? $projectId : null)
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
     * @param array<string, mixed> $tenantPlan
     * @return array<string, mixed>
     */
    private function respondPlan(callable $reply, string $channel, string $sessionId, string $actorUserId, string $text, string $action, array $tenantPlan): array
    {
        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'saas_plan_action' => $action,
            'tenant_plan' => $tenantPlan,
            'item' => $tenantPlan,
            'plan_key' => (string) ($tenantPlan['plan_key'] ?? ''),
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($tenantPlan['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function respondPlanList(callable $reply, string $channel, string $sessionId, string $actorUserId, array $items): array
    {
        return $this->withReplyText($reply('Planes disponibles cargados.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'saas_plan_action' => 'list_plans',
            'items' => $items,
            'result_count' => count($items),
            'actor_user_id' => $actorUserId,
            'result_status' => 'success',
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondPlanLimits(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        return $this->withReplyText($reply('Limites del plan actualizados.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'saas_plan_action' => 'set_plan_limits',
            'plan_limits' => $result,
            'item' => $result,
            'plan_key' => (string) ($result['plan_key'] ?? ''),
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondPlanLimitCheck(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        $text = ($result['within_limit'] ?? true) === true
            ? 'El limite consultado esta dentro del rango del plan.'
            : 'El limite consultado supera el rango actual del plan.';

        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'saas_plan_action' => 'check_plan_limit',
            'plan_limit_check' => $result,
            'item' => $result,
            'plan_key' => (string) ($result['plan_key'] ?? ''),
            'limit_key' => (string) ($result['limit_key'] ?? ''),
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondEnabledModules(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        return $this->withReplyText($reply('Modulos habilitados cargados.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'saas_plan_action' => 'get_enabled_modules',
            'enabled_modules_result' => $result,
            'enabled_modules' => $result['enabled_modules'] ?? [],
            'item' => $result,
            'plan_key' => (string) ($result['plan_key'] ?? ''),
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
        $action = trim((string) ($extra['saas_plan_action'] ?? ''));

        return $extra + [
            'module_used' => 'saas_plan',
            'saas_plan_action' => $action !== '' ? $action : 'none',
            'skill_group' => in_array($action, ['set_plan_limits', 'check_plan_limit'], true) ? 'plan_limits' : 'plan_admin',
            'plan_key' => '',
            'limit_key' => '',
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
            'TENANT_ID_REQUIRED' => 'Falta tenant_id para ejecutar la operacion del plan.',
            'TENANT_PLAN_KEY_INVALID' => 'El plan no es valido. Usa starter, growth, pro o custom.',
            'TENANT_PLAN_STATUS_INVALID' => 'El estado del plan no es valido. Usa active, paused o canceled.',
            'TENANT_PLAN_BILLING_PERIOD_INVALID' => 'El periodo de facturacion no es valido. Usa monthly, yearly o custom.',
            'TENANT_PLAN_NOT_FOUND' => 'No encontre un plan asignado para este tenant.',
            'PLAN_LIMITS_REQUIRED' => 'Debes enviar al menos un limite para actualizar el plan.',
            'PLAN_LIMIT_SCOPE_REQUIRED' => 'Faltan plan_key o limit_key para guardar el limite.',
            'PLAN_LIMIT_TYPE_INVALID' => 'El tipo de limite no es valido. Usa hard, soft o feature.',
            'PLAN_LIMIT_VALUE_REQUIRED' => 'Falta limit_value para guardar el limite.',
            default => $error !== '' ? $error : 'No pude ejecutar la operacion del plan SaaS.',
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
