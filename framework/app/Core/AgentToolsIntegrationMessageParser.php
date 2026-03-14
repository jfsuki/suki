<?php

declare(strict_types=1);

namespace App\Core;

final class AgentToolsIntegrationMessageParser
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
            'fallback_role' => trim((string) ($context['role'] ?? '')) ?: null,
            'message_text' => $this->message,
        ];

        return match ($skillName) {
            'agent_list_tool_groups' => $this->commandResult(
                $baseCommand + ['command' => 'AgentListToolGroups'],
                $this->telemetry($telemetry, 'list_tool_groups')
            ),
            'agent_get_module_capabilities' => $this->parseModuleCommand('AgentGetModuleCapabilities', 'get_module_capabilities', $baseCommand, $telemetry),
            'agent_resolve_tool_for_request' => $this->parseResolveToolForRequest($baseCommand, $telemetry),
            'agent_check_module_enabled' => $this->parseModuleCommand('AgentCheckModuleEnabled', 'check_module_enabled', $baseCommand, $telemetry),
            'agent_check_action_allowed' => $this->parseActionAllowed($baseCommand, $telemetry),
            default => $this->askUser('No pude interpretar la operacion de herramientas del agente.', $telemetry),
        };
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseModuleCommand(string $commandName, string $action, array $baseCommand, array $telemetry): array
    {
        $moduleKey = $this->moduleKey();
        if ($moduleKey === '') {
            return $this->askUser(
                'Indica `module_key` o nombra el modulo: media, entity_search, pos, purchases, fiscal, ecommerce, access_control, saas_plan o usage_metering.',
                $this->telemetry($telemetry, $action, ['result_status' => 'needs_input'])
            );
        }

        return $this->commandResult(
            $baseCommand + [
                'command' => $commandName,
                'module_key' => $moduleKey,
                'requested_module' => $moduleKey,
            ],
            $this->telemetry($telemetry, $action, ['requested_module' => $moduleKey])
        );
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseActionAllowed(array $baseCommand, array $telemetry): array
    {
        $moduleKey = $this->moduleKey();
        $actionKey = $this->actionKey();
        if ($moduleKey === '' || $actionKey === '') {
            return $this->askUser(
                'Indica `module_key` y `action_key` para revisar permisos. Ejemplo: `module_key=pos action_key=finalize_sale`.',
                $this->telemetry($telemetry, 'check_action_allowed', [
                    'requested_module' => $moduleKey !== '' ? $moduleKey : null,
                    'result_status' => 'needs_input',
                ])
            );
        }

        return $this->commandResult(
            $baseCommand + [
                'command' => 'AgentCheckActionAllowed',
                'module_key' => $moduleKey,
                'requested_module' => $moduleKey,
                'action_key' => $actionKey,
            ],
            $this->telemetry($telemetry, 'check_action_allowed', [
                'requested_module' => $moduleKey,
            ])
        );
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseResolveToolForRequest(array $baseCommand, array $telemetry): array
    {
        $requestText = $this->requestText();
        $requestedModule = $this->explicitModuleKey();

        return $this->commandResult(
            $baseCommand + [
                'command' => 'AgentResolveToolForRequest',
                'message_text' => $requestText,
                'request_text' => $requestText,
                'requested_module' => $requestedModule !== '' ? $requestedModule : null,
            ],
            $this->telemetry($telemetry, 'resolve_tool_for_request', [
                'requested_module' => $requestedModule,
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function baseTelemetry(string $skillName, string $actorUserId): array
    {
        return [
            'module_used' => 'agent_tools_integration',
            'agent_tools_action' => $this->actionFromSkillName($skillName),
            'skill_group' => $this->skillGroup($skillName),
            'requested_module' => '',
            'resolved_module' => '',
            'enabled' => null,
            'allowed' => null,
            'denial_reason' => '',
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
            'module_used' => 'agent_tools_integration',
            'agent_tools_action' => $action,
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
            'agent_list_tool_groups' => 'list_tool_groups',
            'agent_get_module_capabilities' => 'get_module_capabilities',
            'agent_resolve_tool_for_request' => 'resolve_tool_for_request',
            'agent_check_module_enabled' => 'check_module_enabled',
            'agent_check_action_allowed' => 'check_action_allowed',
            default => 'none',
        };
    }

    private function skillGroup(string $skillName): string
    {
        return match ($skillName) {
            'agent_resolve_tool_for_request', 'agent_check_action_allowed' => 'orchestration',
            default => 'capability_discovery',
        };
    }

    private function skillGroupFromAction(string $action): string
    {
        return in_array($action, ['resolve_tool_for_request', 'check_action_allowed'], true)
            ? 'orchestration'
            : 'capability_discovery';
    }

    private function moduleKey(): string
    {
        $value = strtolower(trim($this->firstValue($this->pairs, ['module_key', 'requested_module', 'module', 'modulo'])));
        if ($value !== '') {
            return $this->normalizeModuleAlias($value);
        }

        $normalizedMessage = mb_strtolower($this->message, 'UTF-8');
        foreach ($this->moduleAliasMap() as $alias => $moduleKey) {
            if (str_contains($normalizedMessage, $alias)) {
                return $moduleKey;
            }
        }

        return '';
    }

    private function actionKey(): string
    {
        return strtolower(trim($this->firstValue($this->pairs, ['action_key', 'action', 'accion', 'catalog_action', 'skill_name'])));
    }

    private function explicitModuleKey(): string
    {
        $value = strtolower(trim($this->firstValue($this->pairs, ['module_key', 'requested_module', 'module', 'modulo'])));
        if ($value === '') {
            return '';
        }

        return $this->normalizeModuleAlias($value);
    }

    private function requestText(): string
    {
        $explicit = trim((string) $this->firstValue($this->pairs, ['request_text', 'request', 'solicitud', 'texto']));
        if ($explicit !== '') {
            return $explicit;
        }

        $patterns = [
            '/^.*?\bpara\b\s+/iu',
            '/^.*?\bsegun\b\s+/iu',
            '/^.*?\bsegún\b\s+/iu',
        ];
        foreach ($patterns as $pattern) {
            $candidate = preg_replace($pattern, '', $this->message, 1);
            $candidate = is_string($candidate) ? trim($candidate) : '';
            if ($candidate !== '' && $candidate !== $this->message) {
                return $candidate;
            }
        }

        return $this->message;
    }

    /**
     * @return array<string, string>
     */
    private function moduleAliasMap(): array
    {
        return [
            'entity_search' => 'entity_search',
            'entity search' => 'entity_search',
            'busqueda global' => 'entity_search',
            'access_control' => 'access_control',
            'multiusuario' => 'access_control',
            'usage_metering' => 'usage_metering',
            'limite de uso' => 'usage_metering',
            'plan saas' => 'saas_plan',
            'saas_plan' => 'saas_plan',
            'ecommerce' => 'ecommerce',
            'woocommerce' => 'ecommerce',
            'prestashop' => 'ecommerce',
            'fiscal' => 'fiscal',
            'factura' => 'fiscal',
            'purchases' => 'purchases',
            'compras' => 'purchases',
            'compra' => 'purchases',
            'pos' => 'pos',
            'ventas' => 'pos',
            'venta' => 'pos',
            'media' => 'media',
            'documentos' => 'media',
            'documento' => 'media',
            'archivo' => 'media',
            'usuarios' => 'access_control',
            'usuario' => 'access_control',
            'permisos' => 'access_control',
            'consumo' => 'usage_metering',
            'suscripcion' => 'saas_plan',
        ];
    }

    private function normalizeModuleAlias(string $value): string
    {
        return $this->moduleAliasMap()[$value] ?? $value;
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
