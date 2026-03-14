<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class AgentOpsObservabilityCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'AgentOpsGetMetricsSummary',
        'AgentOpsListRecentDecisions',
        'AgentOpsListToolExecutions',
        'AgentOpsGetAnomalyFlags',
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
                'Estas en modo creador. Usa el chat de la app para consultar observabilidad AgentOps del runtime.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData(['result_status' => 'error'])
            ));
        }

        $service = $context['agentops_observability_service'] ?? null;
        if (!$service instanceof AgentOpsObservabilityService) {
            $service = new AgentOpsObservabilityService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'AgentOpsGetMetricsSummary' => $this->respondSummary(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->getMetricsSummary(
                        $tenantId,
                        $projectId !== '' ? $projectId : null,
                        max(1, (int) ($command['days'] ?? 7)),
                        is_string($command['metric_key'] ?? null) ? trim((string) $command['metric_key']) : null
                    )
                ),
                'AgentOpsListRecentDecisions' => $this->respondDecisionList(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->listRecentDecisions(
                        $tenantId,
                        $projectId !== '' ? $projectId : null,
                        array_filter([
                            'result_status' => $command['result_status'] ?? null,
                        ], static fn($value): bool => $value !== null && $value !== ''),
                        max(1, (int) ($command['limit'] ?? 10))
                    )
                ),
                'AgentOpsListToolExecutions' => $this->respondToolExecutionList(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->listToolExecutions(
                        $tenantId,
                        $projectId !== '' ? $projectId : null,
                        array_filter([
                            'module_key' => $command['module_key'] ?? null,
                        ], static fn($value): bool => $value !== null && $value !== ''),
                        max(1, (int) ($command['limit'] ?? 10))
                    )
                ),
                'AgentOpsGetAnomalyFlags' => $this->respondAnomalies(
                    $reply,
                    $channel,
                    $sessionId,
                    $actorUserId,
                    $service->getAnomalyFlags(
                        $tenantId,
                        $projectId !== '' ? $projectId : null,
                        max(1, (int) ($command['days'] ?? 7))
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
    private function respondSummary(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        return $this->withReplyText($reply('Resumen AgentOps cargado.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'agentops_action' => 'get_metrics_summary',
            'metric_key' => (string) ($result['metric_key'] ?? ''),
            'item' => $result,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondDecisionList(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        return $this->withReplyText($reply('Decisiones recientes cargadas.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'agentops_action' => 'list_recent_decisions',
            'items' => $result['decision_traces'] ?? [],
            'result_count' => (int) ($result['result_count'] ?? 0),
            'item' => $result,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondToolExecutionList(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        return $this->withReplyText($reply('Ejecuciones de herramientas cargadas.', $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'agentops_action' => 'list_tool_executions',
            'items' => $result['tool_executions'] ?? [],
            'result_count' => (int) ($result['result_count'] ?? 0),
            'item' => $result,
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondAnomalies(callable $reply, string $channel, string $sessionId, string $actorUserId, array $result): array
    {
        $text = ((int) ($result['result_count'] ?? 0) > 0)
            ? 'Se detectaron banderas de anomalía AgentOps.'
            : 'No se detectaron anomalías AgentOps en la ventana consultada.';

        return $this->withReplyText($reply($text, $channel, $sessionId, $actorUserId, 'success', $this->moduleData([
            'agentops_action' => 'get_anomaly_flags',
            'items' => $result['anomaly_flags'] ?? [],
            'result_count' => (int) ($result['result_count'] ?? 0),
            'item' => $result,
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
            'module_used' => 'agentops_observability',
            'agentops_action' => trim((string) ($extra['agentops_action'] ?? '')) ?: 'none',
            'skill_group' => 'observability',
            'metric_key' => '',
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
            'TENANT_ID_REQUIRED' => 'Falta tenant_id para consultar AgentOps.',
            'AGENTOPS_METRIC_KEY_INVALID' => 'La metrica solicitada no existe en el resumen AgentOps.',
            default => $error !== '' ? $error : 'No pude consultar la observabilidad AgentOps.',
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
