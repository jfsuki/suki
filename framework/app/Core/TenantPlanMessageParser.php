<?php

declare(strict_types=1);

namespace App\Core;

final class TenantPlanMessageParser
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
            'tenant_assign_plan' => $this->parseAssignPlan($baseCommand, $telemetry),
            'tenant_get_plan' => $this->commandResult($baseCommand + ['command' => 'TenantGetPlan'], $this->telemetry($telemetry, 'get_plan')),
            'tenant_list_plans' => $this->commandResult($baseCommand + ['command' => 'TenantListPlans'], $this->telemetry($telemetry, 'list_plans')),
            'tenant_set_plan_limits' => $this->parseSetPlanLimits($baseCommand, $telemetry),
            'tenant_check_plan_limit' => $this->parseCheckPlanLimit($baseCommand, $telemetry),
            'tenant_get_enabled_modules' => $this->commandResult($baseCommand + ['command' => 'TenantGetEnabledModules'], $this->telemetry($telemetry, 'get_enabled_modules')),
            default => $this->askUser('No pude interpretar la operacion del plan SaaS.', $telemetry),
        };
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseAssignPlan(array $baseCommand, array $telemetry): array
    {
        $planKey = $this->planKey();
        if ($planKey === '') {
            return $this->askUser(
                'Indica `plan_key` para asignar el plan al tenant. Planes: starter, growth, pro, custom.',
                $this->telemetry($telemetry, 'assign_plan', ['result_status' => 'needs_input'])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'TenantAssignPlan',
            'plan_key' => $planKey,
            'status' => ($status = $this->statusValue()) !== '' ? $status : null,
            'base_price' => $this->floatValue($this->firstValue($this->pairs, ['base_price', 'precio_base'])),
            'currency' => ($currency = strtoupper($this->firstValue($this->pairs, ['currency', 'moneda']))) !== '' ? $currency : null,
            'included_users' => $this->intValue($this->firstValue($this->pairs, ['included_users', 'usuarios_incluidos'])),
            'extra_user_price' => $this->floatValue($this->firstValue($this->pairs, ['extra_user_price', 'precio_usuario_extra'])),
            'billing_period' => ($period = $this->firstValue($this->pairs, ['billing_period', 'periodo'])) !== '' ? $period : null,
        ], $this->telemetry($telemetry, 'assign_plan', ['plan_key' => $planKey]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseSetPlanLimits(array $baseCommand, array $telemetry): array
    {
        $planKey = $this->planKey();
        $limitKey = $this->limitKey();
        $limitValue = $this->rawLimitValue();
        if ($planKey === '' || $limitKey === '' || $limitValue === null) {
            return $this->askUser(
                'Indica `plan_key`, `limit_key` y `limit_value` para actualizar el limite del plan.',
                $this->telemetry($telemetry, 'set_plan_limits', [
                    'plan_key' => $planKey,
                    'limit_key' => $limitKey,
                    'result_status' => 'needs_input',
                ])
            );
        }

        $limitType = $this->limitTypeValue();
        return $this->commandResult($baseCommand + [
            'command' => 'TenantSetPlanLimits',
            'plan_key' => $planKey,
            'limits' => [[
                'limit_key' => $limitKey,
                'limit_value' => $limitValue,
                'limit_type' => $limitType !== '' ? $limitType : null,
            ]],
        ], $this->telemetry($telemetry, 'set_plan_limits', ['plan_key' => $planKey, 'limit_key' => $limitKey]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCheckPlanLimit(array $baseCommand, array $telemetry): array
    {
        $limitKey = $this->limitKey();
        if ($limitKey === '') {
            return $this->askUser(
                'Indica `limit_key` para revisar el limite del plan. Ejemplos: users, stores, ai_requests_month.',
                $this->telemetry($telemetry, 'check_plan_limit', ['result_status' => 'needs_input'])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'TenantCheckPlanLimit',
            'limit_key' => $limitKey,
            'usage_value' => $this->rawUsageValue(),
        ], $this->telemetry($telemetry, 'check_plan_limit', ['limit_key' => $limitKey]));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseTelemetry(string $skillName, string $actorUserId): array
    {
        return [
            'module_used' => 'saas_plan',
            'saas_plan_action' => $this->actionFromSkillName($skillName),
            'skill_group' => $this->skillGroup($skillName),
            'plan_key' => '',
            'limit_key' => '',
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
            'module_used' => 'saas_plan',
            'saas_plan_action' => $action,
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
            'tenant_assign_plan' => 'assign_plan',
            'tenant_get_plan' => 'get_plan',
            'tenant_list_plans' => 'list_plans',
            'tenant_set_plan_limits' => 'set_plan_limits',
            'tenant_check_plan_limit' => 'check_plan_limit',
            'tenant_get_enabled_modules' => 'get_enabled_modules',
            default => 'none',
        };
    }

    private function skillGroup(string $skillName): string
    {
        return match ($skillName) {
            'tenant_set_plan_limits', 'tenant_check_plan_limit' => 'plan_limits',
            default => 'plan_admin',
        };
    }

    private function skillGroupFromAction(string $action): string
    {
        return in_array($action, ['set_plan_limits', 'check_plan_limit'], true) ? 'plan_limits' : 'plan_admin';
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

    private function planKey(): string
    {
        $value = strtolower(trim($this->firstValue($this->pairs, ['plan_key', 'plan'])));
        if ($value !== '') {
            return $value;
        }

        if (preg_match('/\b(starter|growth|pro|custom)\b/u', mb_strtolower($this->message, 'UTF-8'), $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function statusValue(): string
    {
        return strtolower(trim($this->firstValue($this->pairs, ['status', 'estado'])));
    }

    private function limitKey(): string
    {
        $value = strtolower(trim($this->firstValue($this->pairs, ['limit_key', 'limit', 'limite'])));
        if ($value !== '') {
            return $value;
        }

        foreach (['users', 'stores', 'pos_registers', 'ecommerce_channels', 'ai_requests_month', 'sync_jobs_month', 'storage_mb', 'active_modules'] as $candidate) {
            if (str_contains(mb_strtolower($this->message, 'UTF-8'), $candidate)) {
                return $candidate;
            }
        }

        if (preg_match('/\bmodulo[s]?\s+([a-z_]+)/u', mb_strtolower($this->message, 'UTF-8'), $matches) === 1) {
            return 'module:' . trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function limitTypeValue(): string
    {
        return strtolower(trim($this->firstValue($this->pairs, ['limit_type', 'tipo_limite'])));
    }

    private function rawLimitValue(): mixed
    {
        $raw = $this->firstValue($this->pairs, ['limit_value', 'value', 'valor']);
        if ($raw === '') {
            return null;
        }

        return $this->normalizeScalar($raw);
    }

    private function rawUsageValue(): mixed
    {
        $raw = $this->firstValue($this->pairs, ['usage', 'usage_value', 'uso', 'valor_actual']);
        if ($raw === '') {
            return null;
        }

        return $this->normalizeScalar($raw);
    }

    private function normalizeScalar(string $value): mixed
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $normalized = strtolower($value);
        if (in_array($normalized, ['true', 'false', 'yes', 'no', 'si', 'on', 'off', '1', '0'], true)) {
            return in_array($normalized, ['true', 'yes', 'si', 'on', '1'], true);
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

    private function floatValue(string $value): ?float
    {
        return $value !== '' && is_numeric($value) ? (float) $value : null;
    }
}
