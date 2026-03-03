<?php
// app/Core/IntentRouter.php

namespace App\Core;

final class IntentRouter
{
    private ContractRegistry $contracts;
    private RouterPolicyEvaluator $policyEvaluator;
    private string $enforcementMode;

    public function __construct(
        ?ContractRegistry $contracts = null,
        ?string $enforcementMode = null,
        ?RouterPolicyEvaluator $policyEvaluator = null
    )
    {
        $this->contracts = $contracts ?? new ContractRegistry();
        $this->policyEvaluator = $policyEvaluator ?? new RouterPolicyEvaluator();
        $this->enforcementMode = $this->normalizeEnforcementMode(
            $enforcementMode ?? (string) (getenv('ENFORCEMENT_MODE') ?: 'off')
        );
    }

    public function route(array $gatewayResult, array $context = []): IntentRouteResult
    {
        $action = (string) ($gatewayResult['action'] ?? 'respond_local');
        $reply = (string) ($gatewayResult['reply'] ?? '');
        $command = is_array($gatewayResult['command'] ?? null) ? (array) $gatewayResult['command'] : [];
        $llmRequest = is_array($gatewayResult['llm_request'] ?? null) ? (array) $gatewayResult['llm_request'] : [];
        $state = is_array($gatewayResult['state'] ?? null) ? (array) $gatewayResult['state'] : [];
        $telemetry = is_array($gatewayResult['telemetry'] ?? null) ? (array) $gatewayResult['telemetry'] : [];

        $routeOrder = ['cache', 'rules', 'rag', 'llm'];
        $violations = [];
        $contractVersions = [
            'router_policy' => 'unknown',
            'action_catalog' => 'unknown',
            'agentops_metrics_contract' => 'unknown',
        ];
        $catalog = [];
        $routerPolicy = [
            'route_order' => $routeOrder,
            'rules' => ['llm_is_last_resort' => true],
            'minimum_evidence' => [],
            'missing_evidence_actions' => ['default_action' => 'ASK'],
        ];
        try {
            $routerPolicy = $this->contracts->getRouterPolicy();
            $actionCatalog = $this->contracts->getActionCatalog();
            $agentOpsContract = $this->contracts->getAgentOpsMetricsContract();
            $routeOrder = $this->normalizeRouteOrder($routerPolicy['route_order'] ?? []);
            $catalog = is_array($actionCatalog['catalog'] ?? null) ? (array) $actionCatalog['catalog'] : [];
            $contractVersions['router_policy'] = (string) ($routerPolicy['version'] ?? 'unknown');
            $contractVersions['action_catalog'] = (string) ($actionCatalog['version'] ?? 'unknown');
            $contractVersions['agentops_metrics_contract'] = (string) ($agentOpsContract['version'] ?? 'unknown');
        } catch (\Throwable $e) {
            if ($this->enforcementMode !== 'off') {
                $violations[] = 'contract_registry_unavailable:' . $e->getMessage();
            }
        }

        $routePathSteps = $this->resolveRoutePathSteps($action, $routeOrder);
        if ($action === 'send_to_llm' && !in_array('llm', $routePathSteps, true)) {
            $violations[] = 'router_policy_missing_llm_stage_for_send_to_llm';
        }

        $actionCatalogEntry = null;
        $catalogActionName = '';
        $allowlisted = true;
        if ($action === 'execute_command') {
            $commandName = (string) ($command['command'] ?? '');
            $catalogActionName = $this->mapCommandToCatalogAction($commandName);
            $actionCatalogEntry = $this->resolveCatalogEntry($catalog, $catalogActionName);
            if ($catalogActionName === '') {
                $violations[] = 'action_catalog_mapping_missing_for_command:' . $commandName;
                $allowlisted = false;
            } elseif ($actionCatalogEntry === null) {
                $violations[] = 'action_not_allowlisted:' . $catalogActionName;
                $allowlisted = false;
            }
        }

        $evaluation = $this->policyEvaluator->evaluate(
            [
                'action' => $action,
                'command' => $command,
                'llm_request' => $llmRequest,
                'route_order' => $routeOrder,
                'route_path_steps' => $routePathSteps,
                'catalog_action_name' => $catalogActionName,
                'allowlisted' => $allowlisted,
                'pre_violations' => $violations,
                'evidence' => is_array($gatewayResult['evidence'] ?? null) ? (array) $gatewayResult['evidence'] : [],
            ],
            $routerPolicy,
            is_array($actionCatalogEntry) ? (array) $actionCatalogEntry : null,
            $context,
            $this->enforcementMode
        );
        $violations = is_array($evaluation['violations'] ?? null) ? (array) $evaluation['violations'] : [];
        $gateDecision = (string) ($evaluation['gate_decision'] ?? $this->resolveGateDecision($violations));
        $routePathSteps = is_array($evaluation['route_path_steps'] ?? null)
            ? (array) $evaluation['route_path_steps']
            : $routePathSteps;
        $versions = [
            'prompt_version' => (string) (getenv('PROMPT_VERSION') ?: 'unknown'),
            'router_policy_version' => $contractVersions['router_policy'],
            'action_catalog_version' => $contractVersions['action_catalog'],
            'akp_version' => (string) (getenv('AKP_VERSION') ?: 'unknown'),
            'policy_pack_version' => (string) (getenv('POLICY_PACK_VERSION') ?: 'unknown'),
        ];
        $routePath = implode('>', $routePathSteps);
        $agentOps = [
            'route_path' => $routePath,
            'route_path_steps' => $routePathSteps,
            'gate_decision' => $gateDecision,
            'final_decision' => (string) ($evaluation['final_decision'] ?? 'allow'),
            'resolve_criteria_code' => (string) ($evaluation['resolve_criteria_code'] ?? 'INFORMATIVE_RESPONSE_VALID'),
            'contract_versions' => $contractVersions,
            'versions' => $versions,
            'enforcement_mode' => $this->enforcementMode,
            'gate_results' => is_array($evaluation['gate_results'] ?? null) ? (array) $evaluation['gate_results'] : [],
            'evidence_status' => is_array($evaluation['evidence_status'] ?? null) ? (array) $evaluation['evidence_status'] : [],
            'evidence_used' => is_array($evaluation['evidence_used'] ?? null) ? (array) $evaluation['evidence_used'] : [],
        ];
        if (!empty($violations)) {
            $agentOps['contract_violations'] = $violations;
        }
        if (is_array($actionCatalogEntry)) {
            $agentOps['gates_required'] = is_array($actionCatalogEntry['gates_required'] ?? null)
                ? (array) $actionCatalogEntry['gates_required']
                : [];
            $agentOps['action_contract'] = (string) ($actionCatalogEntry['name'] ?? '');
            $agentOps['action_type'] = (string) ($actionCatalogEntry['type'] ?? '');
        }
        $telemetry = array_merge($telemetry, $agentOps);

        if ($gateDecision === 'blocked') {
            $overrideAction = trim((string) ($evaluation['action_override'] ?? ''));
            $replyOverride = trim((string) ($evaluation['reply_override'] ?? ''));
            $replyBlocked = $replyOverride !== '' ? $replyOverride : $this->buildBlockedReply($routerPolicy, $violations);
            if ($overrideAction === 'ask_user') {
                return new IntentRouteResult('ask_user', $replyBlocked, [], [], $state, $telemetry, $agentOps);
            }
            $safeReply = $this->buildBlockedReply($routerPolicy, $violations);
            return new IntentRouteResult('respond_local', $replyOverride !== '' ? $replyOverride : $safeReply, [], [], $state, $telemetry, $agentOps);
        }

        if ($gateDecision === 'warn') {
            $overrideAction = trim((string) ($evaluation['action_override'] ?? ''));
            $replyOverride = trim((string) ($evaluation['reply_override'] ?? ''));
            if ($overrideAction === 'ask_user') {
                return new IntentRouteResult(
                    'ask_user',
                    $replyOverride !== '' ? $replyOverride : 'Falta evidencia minima para continuar.',
                    [],
                    [],
                    $state,
                    $telemetry,
                    $agentOps
                );
            }
            if ($overrideAction === 'respond_local') {
                return new IntentRouteResult(
                    'respond_local',
                    $replyOverride !== '' ? $replyOverride : 'No hay evidencia minima suficiente para ejecutar.',
                    [],
                    [],
                    $state,
                    $telemetry,
                    $agentOps
                );
            }
        }

        if (in_array($action, ['respond_local', 'ask_user'], true)) {
            return new IntentRouteResult($action, $reply, [], [], $state, $telemetry, $agentOps);
        }
        if ($action === 'execute_command' && !empty($command)) {
            return new IntentRouteResult($action, '', $command, [], $state, $telemetry, $agentOps);
        }
        if ($action === 'send_to_llm' && !empty($llmRequest)) {
            return new IntentRouteResult($action, '', [], $llmRequest, $state, $telemetry, $agentOps);
        }

        return new IntentRouteResult('error', $reply, [], [], $state, $telemetry, $agentOps);
    }

    private function normalizeEnforcementMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['off', 'warn', 'strict'], true) ? $mode : 'off';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeRouteOrder(mixed $value): array
    {
        if (!is_array($value) || empty($value)) {
            return ['cache', 'rules', 'rag', 'llm'];
        }
        $result = [];
        foreach ($value as $step) {
            $normalized = strtolower(trim((string) $step));
            if ($normalized === '') {
                continue;
            }
            if (!in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }
        if (empty($result)) {
            return ['cache', 'rules', 'rag', 'llm'];
        }
        return $result;
    }

    /**
     * @param array<int, string> $routeOrder
     * @return array<int, string>
     */
    private function resolveRoutePathSteps(string $action, array $routeOrder): array
    {
        if ($action === 'send_to_llm') {
            return $routeOrder;
        }

        $path = [];
        foreach ($routeOrder as $step) {
            $path[] = $step;
            if ($step === 'rules') {
                break;
            }
        }

        if (empty($path)) {
            return ['cache', 'rules'];
        }
        return $path;
    }

    private function mapCommandToCatalogAction(string $commandName): string
    {
        $commandName = trim($commandName);
        if ($commandName === '') {
            return '';
        }

        $map = [
            'CreateRecord' => 'crud.create',
            'QueryRecords' => 'crud.query',
            'ReadRecord' => 'crud.read',
            'UpdateRecord' => 'crud.update',
            'DeleteRecord' => 'crud.delete',
            'CreateInvoice' => 'invoice.create',
            'GenerateReport' => 'report.generate',
            'ConfigureFEProvider' => 'settings.configure_fe_provider',
        ];

        if (isset($map[$commandName])) {
            return $map[$commandName];
        }

        if (str_starts_with($commandName, 'Support')) {
            return 'support.' . strtolower(substr($commandName, 7));
        }

        return '';
    }

    /**
     * @param array<int, array> $catalog
     */
    private function resolveCatalogEntry(array $catalog, string $actionName): ?array
    {
        if ($actionName === '') {
            return null;
        }

        $wildcardCandidates = [];
        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($name === $actionName) {
                return $entry;
            }
            if (str_ends_with($name, '.*')) {
                $wildcardCandidates[] = $entry;
            }
        }

        foreach ($wildcardCandidates as $entry) {
            $name = trim((string) ($entry['name'] ?? ''));
            $prefix = substr($name, 0, -1);
            if ($prefix !== '' && str_starts_with($actionName, $prefix)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $violations
     */
    private function resolveGateDecision(array $violations): string
    {
        if ($this->enforcementMode === 'off') {
            return 'off';
        }
        if (empty($violations)) {
            return 'allow';
        }
        if ($this->enforcementMode === 'warn') {
            return 'warn';
        }
        return 'blocked';
    }

    /**
     * @param array<int, string> $violations
     */
    private function mustBlock(array $violations): bool
    {
        return $this->enforcementMode === 'strict' && !empty($violations);
    }

    /**
     * @param array<int, string> $violations
     */
    private function buildBlockedReply(array $routerPolicy, array $violations): string
    {
        $missingEvidence = is_array($routerPolicy['missing_evidence_actions'] ?? null)
            ? (array) $routerPolicy['missing_evidence_actions']
            : [];
        $defaultAction = strtolower(trim((string) ($missingEvidence['default_action'] ?? 'ASK')));
        $reason = !empty($violations) ? $violations[0] : 'contract_violation';

        if ($defaultAction === 'safe_response') {
            return 'Bloqueado por contrato (strict): no hay evidencia minima para ejecutar. Motivo: ' . $reason . '.';
        }

        return 'Bloqueado por contrato (strict): falta evidencia minima. Confirma el dato critico faltante. Motivo: ' . $reason . '.';
    }
}
