<?php

declare(strict_types=1);

namespace App\Core;

final class UsageMeteringMessageParser
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
            'usage_record_event' => $this->parseRecordEvent($baseCommand, $telemetry),
            'usage_get_summary' => $this->commandResult($baseCommand + [
                'command' => 'UsageGetSummary',
                'metric_key' => ($metricKey = $this->metricKey()) !== '' ? $metricKey : null,
                'period_key' => ($periodKey = $this->periodKey()) !== '' ? $periodKey : null,
            ], $this->telemetry($telemetry, 'get_summary', ['metric_key' => $metricKey])),
            'usage_check_limit' => $this->parseCheckLimit($baseCommand, $telemetry),
            'usage_list_metrics' => $this->commandResult($baseCommand + ['command' => 'UsageListMetrics'], $this->telemetry($telemetry, 'list_metrics')),
            'usage_get_history' => $this->parseGetHistory($baseCommand, $telemetry),
            default => $this->askUser('No pude interpretar la operacion de usage metering.', $telemetry),
        };
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRecordEvent(array $baseCommand, array $telemetry): array
    {
        $metricKey = $this->metricKey();
        $deltaValue = $this->rawDeltaValue();
        if ($metricKey === '' || $deltaValue === null) {
            return $this->askUser(
                'Indica `metric_key` y `delta_value` para registrar el evento de uso.',
                $this->telemetry($telemetry, 'record_event', [
                    'metric_key' => $metricKey,
                    'result_status' => 'needs_input',
                ])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'UsageRecordEvent',
            'metric_key' => $metricKey,
            'delta_value' => $deltaValue,
            'unit' => ($unit = $this->firstValue($this->pairs, ['unit', 'unidad'])) !== '' ? $unit : null,
            'period_key' => ($periodKey = $this->periodKey()) !== '' ? $periodKey : null,
            'source_module' => ($sourceModule = $this->sourceModule()) !== '' ? $sourceModule : 'usage_metering',
            'source_action' => ($sourceAction = $this->firstValue($this->pairs, ['source_action', 'accion_fuente'])) !== '' ? $sourceAction : null,
            'source_ref' => ($sourceRef = $this->firstValue($this->pairs, ['source_ref', 'referencia_fuente'])) !== '' ? $sourceRef : null,
        ], $this->telemetry($telemetry, 'record_event', [
            'metric_key' => $metricKey,
            'delta_value' => $deltaValue,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCheckLimit(array $baseCommand, array $telemetry): array
    {
        $metricKey = $this->metricKey();
        if ($metricKey === '') {
            return $this->askUser(
                'Indica `metric_key` para revisar el limite de uso. Ejemplos: users, storage_mb, sync_jobs_month.',
                $this->telemetry($telemetry, 'check_limit', ['result_status' => 'needs_input'])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'UsageCheckLimit',
            'metric_key' => $metricKey,
            'period_key' => ($periodKey = $this->periodKey()) !== '' ? $periodKey : null,
        ], $this->telemetry($telemetry, 'check_limit', ['metric_key' => $metricKey]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetHistory(array $baseCommand, array $telemetry): array
    {
        $metricKey = $this->metricKey();
        if ($metricKey === '') {
            return $this->askUser(
                'Indica `metric_key` para devolver el historial de uso.',
                $this->telemetry($telemetry, 'get_history', ['result_status' => 'needs_input'])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'UsageGetHistory',
            'metric_key' => $metricKey,
            'period_key' => ($periodKey = $this->periodKey()) !== '' ? $periodKey : null,
            'limit' => $this->intValue($this->firstValue($this->pairs, ['limit', 'limite'])) ?? 50,
        ], $this->telemetry($telemetry, 'get_history', ['metric_key' => $metricKey]));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseTelemetry(string $skillName, string $actorUserId): array
    {
        return [
            'module_used' => 'usage_metering',
            'usage_metering_action' => $this->actionFromSkillName($skillName),
            'skill_group' => $this->skillGroup($skillName),
            'metric_key' => '',
            'delta_value' => null,
            'usage_value' => null,
            'limit_value' => null,
            'over_limit' => false,
            'actor_user_id' => $actorUserId,
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
            'module_used' => 'usage_metering',
            'usage_metering_action' => $action,
            'skill_group' => $this->skillGroupFromAction($action),
        ], $extra);

        $merged['ambiguity_detected'] = (($merged['ambiguity_detected'] ?? false) === true);
        $merged['over_limit'] = (($merged['over_limit'] ?? false) === true);
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
            'usage_record_event' => 'record_event',
            'usage_get_summary' => 'get_summary',
            'usage_check_limit' => 'check_limit',
            'usage_list_metrics' => 'list_metrics',
            'usage_get_history' => 'get_history',
            default => 'none',
        };
    }

    private function skillGroup(string $skillName): string
    {
        return match ($skillName) {
            'usage_record_event', 'usage_get_history' => 'usage_tracking',
            'usage_get_summary', 'usage_check_limit' => 'resource_control',
            default => 'usage_admin',
        };
    }

    private function skillGroupFromAction(string $action): string
    {
        return match ($action) {
            'record_event', 'get_history' => 'usage_tracking',
            'get_summary', 'check_limit' => 'resource_control',
            default => 'usage_admin',
        };
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+)=(\"([^\"]*)\"|\'([^\']*)\'|([^\\s]+))/u', $message, $matches, PREG_SET_ORDER);
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

    private function metricKey(): string
    {
        $value = strtolower(trim($this->firstValue($this->pairs, ['metric_key', 'metric', 'metrica', 'métrica'])));
        if ($value !== '') {
            return $this->normalizeMetricAlias($value);
        }

        $normalized = mb_strtolower($this->message, 'UTF-8');
        foreach ($this->metricAliases() as $metricKey => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($normalized, $alias)) {
                    return $metricKey;
                }
            }
        }

        return '';
    }

    private function periodKey(): string
    {
        return strtolower(trim($this->firstValue($this->pairs, ['period_key', 'periodo', 'period'])));
    }

    private function sourceModule(): string
    {
        return strtolower(trim($this->firstValue($this->pairs, ['source_module', 'modulo_fuente', 'módulo_fuente'])));
    }

    private function rawDeltaValue(): mixed
    {
        $raw = $this->firstValue($this->pairs, ['delta_value', 'delta', 'valor', 'cantidad']);
        if ($raw !== '') {
            return $this->normalizeScalar($raw);
        }

        if (preg_match('/(?:delta|suma|sumar|incrementa|incrementar)\s+(?:en\s+)?([0-9]+(?:\.[0-9]+)?)/u', mb_strtolower($this->message, 'UTF-8'), $matches) === 1) {
            return $this->normalizeScalar((string) ($matches[1] ?? ''));
        }

        return null;
    }

    private function normalizeMetricAlias(string $value): string
    {
        foreach ($this->metricAliases() as $metricKey => $aliases) {
            if ($value === $metricKey || in_array($value, $aliases, true)) {
                return $metricKey;
            }
        }

        return '';
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function metricAliases(): array
    {
        return [
            'users' => ['users', 'usuarios', 'usuarios_activos', 'active_users'],
            'ai_requests_month' => ['ai_requests_month', 'solicitudes_ia', 'peticiones_ia', 'requests_ia'],
            'storage_mb' => ['storage_mb', 'storage', 'almacenamiento', 'almacenamiento_mb'],
            'ecommerce_channels' => ['ecommerce_channels', 'canales_ecommerce', 'tiendas_ecommerce'],
            'sync_jobs_month' => ['sync_jobs_month', 'sync_jobs', 'sincronizaciones'],
            'pos_registers' => ['pos_registers', 'cajas_pos'],
            'active_stores' => ['active_stores', 'tiendas_activas'],
            'documents_uploaded' => ['documents_uploaded', 'documentos_subidos', 'documentos'],
            'sales_created' => ['sales_created', 'ventas_creadas', 'ventas'],
            'purchases_created' => ['purchases_created', 'compras_creadas', 'compras'],
        ];
    }

    private function normalizeScalar(string $value): mixed
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    private function intValue(string $value): ?int
    {
        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }
}
