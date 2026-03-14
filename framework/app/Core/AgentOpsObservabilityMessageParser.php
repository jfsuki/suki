<?php

declare(strict_types=1);

namespace App\Core;

final class AgentOpsObservabilityMessageParser
{
    private string $message = '';

    /** @var array<string, string> */
    private array $pairs = [];

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $this->message = trim((string) ($context['message_text'] ?? ''));
        $this->pairs = $this->extractKeyValuePairs($this->message);

        $actorUserId = trim((string) ($context['auth_user_id'] ?? $context['user_id'] ?? '')) ?: 'system';
        $telemetry = $this->baseTelemetry($skillName, $actorUserId);
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'project_id' => ($projectId = trim((string) ($context['project_id'] ?? ''))) !== '' ? $projectId : null,
            'actor_user_id' => $actorUserId,
            'requested_by_user_id' => trim((string) ($context['user_id'] ?? '')) ?: $actorUserId,
            'message_text' => $this->message,
        ];

        return match ($skillName) {
            'agentops_get_metrics_summary' => $this->commandResult($baseCommand + [
                'command' => 'AgentOpsGetMetricsSummary',
                'days' => $this->daysValue(),
                'metric_key' => $this->metricKey(),
            ], $this->telemetry($telemetry, 'get_metrics_summary', ['metric_key' => $this->metricKey()])),
            'agentops_list_recent_decisions' => $this->commandResult($baseCommand + [
                'command' => 'AgentOpsListRecentDecisions',
                'limit' => $this->limitValue(),
                'result_status' => ($resultStatus = $this->resultStatus()) !== '' ? $resultStatus : null,
            ], $this->telemetry($telemetry, 'list_recent_decisions')),
            'agentops_list_tool_executions' => $this->commandResult($baseCommand + [
                'command' => 'AgentOpsListToolExecutions',
                'limit' => $this->limitValue(),
                'module_key' => ($moduleKey = $this->moduleKey()) !== '' ? $moduleKey : null,
            ], $this->telemetry($telemetry, 'list_tool_executions', ['requested_module' => $this->moduleKey()])),
            'agentops_get_anomaly_flags' => $this->commandResult($baseCommand + [
                'command' => 'AgentOpsGetAnomalyFlags',
                'days' => $this->daysValue(),
            ], $this->telemetry($telemetry, 'get_anomaly_flags')),
            default => $this->askUser('No pude interpretar la operacion de observabilidad AgentOps.', $telemetry),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function baseTelemetry(string $skillName, string $actorUserId): array
    {
        return [
            'module_used' => 'agentops_observability',
            'agentops_action' => $this->actionFromSkillName($skillName),
            'skill_group' => 'observability',
            'actor_user_id' => $actorUserId,
            'requested_module' => '',
            'metric_key' => '',
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
            'module_used' => 'agentops_observability',
            'agentops_action' => $action,
            'skill_group' => 'observability',
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
            'agentops_get_metrics_summary' => 'get_metrics_summary',
            'agentops_list_recent_decisions' => 'list_recent_decisions',
            'agentops_list_tool_executions' => 'list_tool_executions',
            'agentops_get_anomaly_flags' => 'get_anomaly_flags',
            default => 'none',
        };
    }

    private function metricKey(): ?string
    {
        $value = strtolower(trim($this->firstValue($this->pairs, ['metric_key', 'metric', 'metrica'])));
        return $value !== '' ? $value : null;
    }

    private function resultStatus(): string
    {
        return strtolower(trim($this->firstValue($this->pairs, ['result_status', 'status', 'estado'])));
    }

    private function moduleKey(): string
    {
        return strtolower(trim($this->firstValue($this->pairs, ['module_key', 'module', 'modulo'])));
    }

    private function daysValue(): int
    {
        $value = $this->firstValue($this->pairs, ['days', 'dias']);
        if ($value !== '' && is_numeric($value)) {
            return max(1, min(90, (int) $value));
        }

        if (preg_match('/\b(\d{1,2})\s*(dias|d[ií]as|days)\b/u', mb_strtolower($this->message, 'UTF-8'), $matches) === 1) {
            return max(1, min(90, (int) ($matches[1] ?? 7)));
        }

        return 7;
    }

    private function limitValue(): int
    {
        $value = $this->firstValue($this->pairs, ['limit', 'limite']);
        if ($value !== '' && is_numeric($value)) {
            return max(1, min(50, (int) $value));
        }

        return 10;
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+)=("([^"]*)"|\'([^\']*)\'|([^\s]+))/u', $message, $matches, PREG_SET_ORDER);
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
}
