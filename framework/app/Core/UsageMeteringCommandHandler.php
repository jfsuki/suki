<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class UsageMeteringCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'UsageRecordEvent',
        'UsageGetSummary',
        'UsageCheckLimit',
        'UsageListMetrics',
        'UsageGetHistory',
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
                'Estas en modo creador. Usa el chat de la app para revisar y registrar uso del tenant.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData(['result_status' => 'error'])
            ));
        }

        $service = $context['usage_metering_service'] ?? null;
        if (!$service instanceof UsageMeteringService) {
            $service = new UsageMeteringService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'UsageRecordEvent' => $this->respondEvent(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->recordUsageEvent($command + [
                        'tenant_id' => $tenantId,
                        'project_id' => $projectId !== '' ? $projectId : null,
                    ])
                ),
                'UsageGetSummary' => $this->respondSummary(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->getTenantUsageSummary(
                        $tenantId,
                        array_filter([
                            'metric_key' => $command['metric_key'] ?? null,
                            'period_key' => $command['period_key'] ?? null,
                        ], static fn($value): bool => $value !== null && $value !== ''),
                        $projectId !== '' ? $projectId : null
                    )
                ),
                'UsageCheckLimit' => $this->respondLimitCheck(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->checkUsageLimit(
                        $tenantId,
                        trim((string) ($command['metric_key'] ?? '')),
                        $projectId !== '' ? $projectId : null,
                        trim((string) ($command['period_key'] ?? '')) ?: null
                    )
                ),
                'UsageListMetrics' => $this->respondMetricsList(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->listMetrics()
                ),
                'UsageGetHistory' => $this->respondHistory(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->getMetricUsageHistory(
                        $tenantId,
                        trim((string) ($command['metric_key'] ?? '')),
                        array_filter([
                            'period_key' => $command['period_key'] ?? null,
                            'source_module' => $command['source_module'] ?? null,
                            'source_action' => $command['source_action'] ?? null,
                            'source_ref' => $command['source_ref'] ?? null,
                            'limit' => $command['limit'] ?? null,
                        ], static fn($value): bool => $value !== null && $value !== ''),
                        $projectId !== '' ? $projectId : null
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
    private function respondEvent(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        return $this->withReplyText($reply('Evento de uso registrado.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'usage_metering_action' => 'record_event',
            'usage_event' => $result['event'] ?? null,
            'usage_meter' => $result['meter'] ?? null,
            'item' => $result,
            'metric_key' => (string) ($result['metric_key'] ?? ''),
            'delta_value' => $result['event']['delta_value'] ?? null,
            'usage_value' => $result['usage_value'] ?? null,
            'limit_value' => $result['limit_value'] ?? null,
            'plan_key' => (string) ($result['plan_key'] ?? ''),
            'limit_key' => (string) ($result['limit_key'] ?? ''),
            'over_limit' => (($result['over_limit'] ?? false) === true),
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondSummary(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        $items = is_array($result['items'] ?? null) ? (array) $result['items'] : [];
        $first = is_array($items[0] ?? null) ? (array) $items[0] : [];

        return $this->withReplyText($reply('Resumen de uso cargado.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'usage_metering_action' => 'get_summary',
            'usage_summary' => $result,
            'items' => $items,
            'item' => $first !== [] ? $first : $result,
            'metric_key' => (string) ($first['metric_key'] ?? ''),
            'usage_value' => $first['usage_value'] ?? null,
            'limit_value' => $first['limit_value'] ?? null,
            'plan_key' => (string) ($first['plan_key'] ?? ''),
            'limit_key' => (string) ($first['limit_key'] ?? ''),
            'over_limit' => !empty($result['over_limit_metrics'] ?? []),
            'result_count' => count($items),
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondLimitCheck(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        $text = ($result['over_limit'] ?? false) === true
            ? 'La metrica consultada supera el limite actual del plan.'
            : ((($result['near_limit'] ?? false) === true)
                ? 'La metrica consultada esta cerca del limite actual del plan.'
                : 'La metrica consultada esta dentro del limite actual del plan.');

        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'usage_metering_action' => 'check_limit',
            'usage_limit_check' => $result,
            'item' => $result,
            'metric_key' => (string) ($result['metric_key'] ?? ''),
            'usage_value' => $result['usage_value'] ?? null,
            'limit_value' => $result['limit_value'] ?? null,
            'plan_key' => (string) ($result['plan_key'] ?? ''),
            'limit_key' => (string) ($result['limit_key'] ?? ''),
            'over_limit' => (($result['over_limit'] ?? false) === true),
            'actor_user_id' => $actorUserId,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function respondMetricsList(callable $reply, string $channel, string $sessionId, string $actorUserId, array $items): array
    {
        return $this->withReplyText($reply('Metricas de uso disponibles cargadas.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'usage_metering_action' => 'list_metrics',
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
    private function respondHistory(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        return $this->withReplyText($reply('Historial de uso cargado.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'usage_metering_action' => 'get_history',
            'usage_history' => $result,
            'items' => $result['events'] ?? [],
            'item' => $result['meter'] ?? $result,
            'metric_key' => (string) ($result['metric_key'] ?? ''),
            'usage_value' => $result['usage_value'] ?? null,
            'result_count' => (int) ($result['result_count'] ?? 0),
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
        $action = trim((string) ($extra['usage_metering_action'] ?? ''));

        return $extra + [
            'module_used' => 'usage_metering',
            'usage_metering_action' => $action !== '' ? $action : 'none',
            'skill_group' => match ($action) {
                'record_event', 'get_history' => 'usage_tracking',
                'get_summary', 'check_limit' => 'resource_control',
                default => 'usage_admin',
            },
            'metric_key' => '',
            'delta_value' => null,
            'usage_value' => null,
            'limit_value' => null,
            'over_limit' => false,
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
            'TENANT_ID_REQUIRED' => 'Falta tenant_id para operar el usage metering.',
            'USAGE_METRIC_KEY_INVALID' => 'La metrica no es valida. Usa una metrica soportada por usage metering.',
            'USAGE_DELTA_VALUE_REQUIRED' => 'Falta delta_value para registrar el evento de uso.',
            'USAGE_DELTA_VALUE_INVALID' => 'delta_value debe ser numerico.',
            'SOURCE_MODULE_REQUIRED' => 'Falta source_module para registrar el evento de uso.',
            'USAGE_PERIOD_KEY_INVALID' => 'period_key no es valido para la metrica indicada.',
            default => $error !== '' ? $error : 'No pude ejecutar la operacion de usage metering.',
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
