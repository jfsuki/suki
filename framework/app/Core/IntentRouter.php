<?php
// app/Core/IntentRouter.php

namespace App\Core;

final class IntentRouter
{
    private ContractRegistry $contracts;
    private RouterPolicyEvaluator $policyEvaluator;
    private string $enforcementMode;
    private string $enforcementModeSource;
    private string $effectiveAppEnv;
    private ?SemanticMemoryService $semanticMemory;
    private bool $semanticMemoryResolved;

    public function __construct(
        ?ContractRegistry $contracts = null,
        ?string $enforcementMode = null,
        ?RouterPolicyEvaluator $policyEvaluator = null,
        ?SemanticMemoryService $semanticMemory = null
    )
    {
        $this->contracts = $contracts ?? new ContractRegistry();
        $this->policyEvaluator = $policyEvaluator ?? new RouterPolicyEvaluator();
        $this->semanticMemory = $semanticMemory;
        $this->semanticMemoryResolved = $semanticMemory !== null;
        $rawAppEnv = (string) (getenv('APP_ENV') ?: getenv('SUKI_ENV') ?: 'dev');
        $rawMode = getenv('ENFORCEMENT_MODE');
        $resolved = EnforcementModePolicy::resolve(
            $rawAppEnv,
            $enforcementMode !== null ? $enforcementMode : ($rawMode === false ? null : (string) $rawMode)
        );
        $this->enforcementMode = (string) ($resolved['mode'] ?? 'warn');
        $this->enforcementModeSource = (string) ($resolved['source'] ?? 'app_env_default');
        $this->effectiveAppEnv = (string) ($resolved['app_env'] ?? 'dev');
    }

    public function route(array $gatewayResult, array $context = []): IntentRouteResult
    {
        $action = (string) ($gatewayResult['action'] ?? 'respond_local');
        $reply = (string) ($gatewayResult['reply'] ?? '');
        $command = is_array($gatewayResult['command'] ?? null) ? (array) $gatewayResult['command'] : [];
        $llmRequest = is_array($gatewayResult['llm_request'] ?? null) ? (array) $gatewayResult['llm_request'] : [];
        $state = is_array($gatewayResult['state'] ?? null) ? (array) $gatewayResult['state'] : [];
        $telemetry = is_array($gatewayResult['telemetry'] ?? null) ? (array) $gatewayResult['telemetry'] : [];
        $routingHintSteps = $this->extractRoutingHintSteps($gatewayResult, $telemetry);

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

        $routePathSteps = $this->resolveRoutePathSteps($action, $routeOrder, $routingHintSteps);
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

        $retrieval = $this->maybeRetrieveEvidence(
            $action,
            is_array($actionCatalogEntry) ? (array) $actionCatalogEntry : null,
            $gatewayResult,
            $context
        );
        if (!empty($retrieval)) {
            if (array_key_exists('rag_hit', $retrieval)) {
                $telemetry['rag_hit'] = (bool) $retrieval['rag_hit'];
                $gatewayResult['rag_hit'] = (bool) $retrieval['rag_hit'];
            }
            foreach (['source_ids', 'evidence_ids'] as $key) {
                $list = is_array($retrieval[$key] ?? null) ? (array) $retrieval[$key] : [];
                if (!empty($list)) {
                    $normalized = array_values(array_unique(array_filter(array_map(
                        static fn($value): string => trim((string) $value),
                        $list
                    ), static fn(string $value): bool => $value !== '')));
                    if (!empty($normalized)) {
                        $telemetry[$key] = $normalized;
                        $gatewayResult[$key] = $normalized;
                    }
                }
            }
            if (is_array($retrieval['telemetry'] ?? null)) {
                $telemetry['retrieval'] = (array) $retrieval['telemetry'];
            }
            if (!empty($telemetry['source_ids'] ?? []) || !empty($telemetry['evidence_ids'] ?? [])) {
                $evidence = is_array($gatewayResult['evidence'] ?? null) ? (array) $gatewayResult['evidence'] : [];
                $evidence[] = 'at_least_one_source_reference';
                $gatewayResult['evidence'] = array_values(array_unique(array_filter(array_map(
                    static fn($value): string => trim((string) $value),
                    $evidence
                ), static fn(string $value): bool => $value !== '')));
            }
        }

        $evaluationContext = $this->mergeRoutingEvidenceContext($context, $gatewayResult, $telemetry, $routePathSteps, $action);
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
            $evaluationContext,
            $this->enforcementMode
        );
        $violations = is_array($evaluation['violations'] ?? null) ? (array) $evaluation['violations'] : [];
        $gateDecision = (string) ($evaluation['gate_decision'] ?? $this->resolveGateDecision($violations));
        $routePathSteps = is_array($evaluation['route_path_steps'] ?? null)
            ? (array) $evaluation['route_path_steps']
            : $routePathSteps;
        if ($action === 'execute_command') {
            $routePathSteps = $this->appendActionContractStage($routePathSteps);
        }
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
            'enforcement_mode_source' => $this->enforcementModeSource,
            'enforcement_app_env' => $this->effectiveAppEnv,
            'gate_results' => is_array($evaluation['gate_results'] ?? null) ? (array) $evaluation['gate_results'] : [],
            'evidence_status' => is_array($evaluation['evidence_status'] ?? null) ? (array) $evaluation['evidence_status'] : [],
            'evidence_used' => is_array($evaluation['evidence_used'] ?? null) ? (array) $evaluation['evidence_used'] : [],
            'routing_hint_steps' => $routingHintSteps,
            'is_authenticated' => (bool) ($context['is_authenticated'] ?? false),
            'effective_role' => strtolower(trim((string) ($context['role'] ?? 'guest'))) ?: 'guest',
        ];
        if (is_array($telemetry['retrieval'] ?? null)) {
            $agentOps['retrieval'] = (array) $telemetry['retrieval'];
        }
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
            if ($action === 'send_to_llm') {
                $replyOverride = trim((string) ($evaluation['reply_override'] ?? ''));
                $warnReply = $replyOverride !== ''
                    ? $replyOverride
                    : 'Falta evidencia minima para continuar. Comparte un dato verificable para seguir.';
                return new IntentRouteResult('ask_user', $warnReply, [], [], $state, $telemetry, $agentOps);
            }
            if (!in_array($action, ['respond_local', 'ask_user'], true)) {
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
     * @param array<int, string> $routingHintSteps
     * @return array<int, string>
     */
    private function resolveRoutePathSteps(string $action, array $routeOrder, array $routingHintSteps = []): array
    {
        if (!empty($routingHintSteps)) {
            $hintPath = [];
            $allowed = ['cache', 'rules', 'rag', 'llm', 'action_contract'];
            foreach ($routingHintSteps as $step) {
                $step = strtolower(trim((string) $step));
                if ($step === '' || !in_array($step, $allowed, true)) {
                    continue;
                }
                if (!in_array($step, $hintPath, true)) {
                    $hintPath[] = $step;
                }
            }
            if (!empty($hintPath)) {
                return $hintPath;
            }
        }

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

    /**
     * @param array<int, string> $routePathSteps
     * @return array<int, string>
     */
    private function appendActionContractStage(array $routePathSteps): array
    {
        if (in_array('action_contract', $routePathSteps, true)) {
            return $routePathSteps;
        }
        if (empty($routePathSteps)) {
            return ['cache', 'rules', 'action_contract'];
        }

        $result = [];
        foreach ($routePathSteps as $step) {
            $step = strtolower(trim((string) $step));
            if ($step === '') {
                continue;
            }
            $result[] = $step;
            if ($step === 'rules') {
                $result[] = 'action_contract';
            }
        }
        if (!in_array('action_contract', $result, true)) {
            $result[] = 'action_contract';
        }
        return array_values(array_unique($result));
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $gatewayResult
     * @param array<string,mixed> $telemetry
     * @param array<int,string> $routePathSteps
     * @param string $action
     * @return array<string,mixed>
     */
    private function mergeRoutingEvidenceContext(
        array $context,
        array $gatewayResult,
        array $telemetry,
        array $routePathSteps,
        string $action
    ): array
    {
        $merged = $context;
        foreach (['cache_hit', 'rules_hit', 'rag_hit'] as $flag) {
            if (array_key_exists($flag, $merged)) {
                continue;
            }
            if (array_key_exists($flag, $telemetry)) {
                $merged[$flag] = (bool) $telemetry[$flag];
                continue;
            }
            if (array_key_exists($flag, $gatewayResult)) {
                $merged[$flag] = (bool) $gatewayResult[$flag];
            }
        }
        if (!array_key_exists('rules_hit', $merged) && $action !== 'send_to_llm' && in_array('rules', $routePathSteps, true)) {
            $merged['rules_hit'] = true;
        }
        foreach (['source_ids', 'evidence_ids'] as $listKey) {
            if (array_key_exists($listKey, $merged)) {
                continue;
            }
            $source = $telemetry[$listKey] ?? $gatewayResult[$listKey] ?? null;
            if (is_array($source)) {
                $merged[$listKey] = array_values(array_filter(array_map(
                    static fn($value): string => trim((string) $value),
                    $source
                ), static fn(string $value): bool => $value !== ''));
            }
        }
        return $merged;
    }

    /**
     * @param array<string,mixed> $gatewayResult
     * @param array<string,mixed> $telemetry
     * @return array<int,string>
     */
    private function extractRoutingHintSteps(array $gatewayResult, array $telemetry): array
    {
        $source = $telemetry['routing_hint_steps'] ?? $gatewayResult['routing_hint_steps'] ?? null;
        if (!is_array($source)) {
            return [];
        }
        $steps = [];
        foreach ($source as $step) {
            $step = strtolower(trim((string) $step));
            if ($step === '') {
                continue;
            }
            if (!in_array($step, $steps, true)) {
                $steps[] = $step;
            }
        }
        return $steps;
    }

    /**
     * @param array<string,mixed>|null $actionCatalogEntry
     * @param array<string,mixed> $gatewayResult
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function maybeRetrieveEvidence(
        string $action,
        ?array $actionCatalogEntry,
        array $gatewayResult,
        array $context
    ): array {
        if (!$this->shouldAttemptRetrieval($action, $actionCatalogEntry)) {
            return [];
        }

        $semanticMemory = $this->semanticMemory();
        if (!$semanticMemory) {
            return [];
        }

        $tenantId = trim((string) ($context['tenant_id'] ?? ''));
        if ($tenantId === '') {
            return [];
        }
        $appId = trim((string) ($context['app_id'] ?? $context['project_id'] ?? ''));
        $query = $this->extractRetrievalQuery($gatewayResult, $context);
        if ($query === '') {
            return [];
        }

        try {
            $topK = max(1, (int) (getenv('SEMANTIC_MEMORY_TOP_K') ?: 5));
            return $semanticMemory->retrieve($query, [
                'tenant_id' => $tenantId,
                'app_id' => $appId !== '' ? $appId : null,
            ], $topK);
        } catch (\Throwable $e) {
            return [
                'rag_hit' => false,
                'source_ids' => [],
                'evidence_ids' => [],
                'telemetry' => [
                    'retrieval_attempted' => true,
                    'retrieval_result_count' => 0,
                    'retrieval_error' => trim((string) $e->getMessage()),
                ],
            ];
        }
    }

    /**
     * @param array<string,mixed>|null $actionCatalogEntry
     */
    private function shouldAttemptRetrieval(string $action, ?array $actionCatalogEntry): bool
    {
        if ($action === 'send_to_llm') {
            return true;
        }
        if (!is_array($actionCatalogEntry)) {
            return false;
        }
        return (bool) ($actionCatalogEntry['rag_required'] ?? false);
    }

    private function semanticMemory(): ?SemanticMemoryService
    {
        if ($this->semanticMemoryResolved) {
            return $this->semanticMemory;
        }
        $this->semanticMemoryResolved = true;

        if (!SemanticMemoryService::isEnabledFromEnv()) {
            $this->semanticMemory = null;
            return null;
        }

        try {
            $this->semanticMemory = new SemanticMemoryService();
            return $this->semanticMemory;
        } catch (\Throwable $e) {
            $this->semanticMemory = null;
            return null;
        }
    }

    /**
     * @param array<string,mixed> $gatewayResult
     * @param array<string,mixed> $context
     */
    private function extractRetrievalQuery(array $gatewayResult, array $context): string
    {
        $candidates = [];
        $contextText = trim((string) ($context['message_text'] ?? $context['message'] ?? $context['text'] ?? ''));
        if ($contextText !== '') {
            $candidates[] = $contextText;
        }

        $userText = trim((string) ($gatewayResult['user_text'] ?? ''));
        if ($userText !== '') {
            $candidates[] = $userText;
        }

        $llmRequest = is_array($gatewayResult['llm_request'] ?? null) ? (array) $gatewayResult['llm_request'] : [];
        $messages = is_array($llmRequest['messages'] ?? null) ? (array) $llmRequest['messages'] : [];
        foreach (array_reverse($messages) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = strtolower(trim((string) ($message['role'] ?? '')));
            if ($role !== 'user' && $role !== '') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (is_string($part) && trim($part) !== '') {
                        $candidates[] = trim($part);
                    } elseif (is_array($part) && trim((string) ($part['text'] ?? '')) !== '') {
                        $candidates[] = trim((string) $part['text']);
                    }
                }
            } elseif (is_string($content) && trim($content) !== '') {
                $candidates[] = trim($content);
            }
            if (!empty($candidates)) {
                break;
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
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
            'CreateEntity' => 'builder.create_entity',
            'CreateForm' => 'builder.create_form',
            'CreateRelation' => 'builder.create_relation',
            'CreateIndex' => 'builder.create_index',
            'InstallPlaybook' => 'builder.install_playbook',
            'CompileWorkflow' => 'builder.compile_workflow',
            'ImportIntegrationOpenApi' => 'builder.import_integration_openapi',
            'AuthLogin' => 'auth.login',
            'AuthCreateUser' => 'auth.create_user',
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
