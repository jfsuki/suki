<?php
// app/Core/IntentRouter.php

namespace App\Core;

final class IntentRouter
{
    private const REQUEST_MODE_OPERATION = 'operation';
    private const REQUEST_MODE_RESEARCH = 'research';
    private const TRACE_HISTORY_LIMIT = 8;
    private const REQUEST_MODE_BUDGETS = [
        'operation' => [
            'max_router_steps' => 5,
            'max_tool_calls' => 1,
            'max_retries' => 0,
            'max_llm_fallbacks' => 1,
            'max_semantic_queries' => 1,
            'max_context_chunks' => 2,
            'max_execution_ms' => 1500,
            'max_same_route_repeats' => 2,
        ],
        'research' => [
            'max_router_steps' => 6,
            'max_tool_calls' => 2,
            'max_retries' => 1,
            'max_llm_fallbacks' => 1,
            'max_semantic_queries' => 2,
            'max_context_chunks' => 4,
            'max_execution_ms' => 3000,
            'max_same_route_repeats' => 3,
        ],
    ];
    /** @var array<int,string> */
    private const RESEARCH_ACTIVE_TASKS = [
        'unknown_business_discovery',
        'business_research_confirmation',
    ];

    private ContractRegistry $contracts;
    private RouterPolicyEvaluator $policyEvaluator;
    private string $enforcementMode;
    private string $enforcementModeSource;
    private string $effectiveAppEnv;
    private ?SemanticMemoryService $semanticMemory;
    private SkillResolver $skillResolver;
    private SkillExecutor $skillExecutor;
    private bool $semanticMemoryResolved;
    /** @var array<string,mixed> */
    private array $semanticMemoryAvailability;

    public function __construct(
        ?ContractRegistry $contracts = null,
        ?string $enforcementMode = null,
        ?RouterPolicyEvaluator $policyEvaluator = null,
        ?SemanticMemoryService $semanticMemory = null,
        ?SkillResolver $skillResolver = null,
        ?SkillExecutor $skillExecutor = null
    )
    {
        $this->contracts = $contracts ?? new ContractRegistry();
        $this->policyEvaluator = $policyEvaluator ?? new RouterPolicyEvaluator();
        $this->semanticMemory = $semanticMemory;
        $this->skillResolver = $skillResolver ?? new SkillResolver();
        $this->skillExecutor = $skillExecutor ?? new SkillExecutor();
        $this->semanticMemoryResolved = $semanticMemory !== null;
        $this->semanticMemoryAvailability = $semanticMemory !== null
            ? ['enabled' => true, 'status' => 'enabled', 'reason' => 'semantic_memory_injected']
            : ['enabled' => false, 'status' => 'unresolved', 'reason' => 'semantic_memory_not_resolved'];
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
        $routeStartedAt = microtime(true);
        $action = (string) ($gatewayResult['action'] ?? 'respond_local');
        $reply = (string) ($gatewayResult['reply'] ?? '');
        $command = is_array($gatewayResult['command'] ?? null) ? (array) $gatewayResult['command'] : [];
        $llmRequest = is_array($gatewayResult['llm_request'] ?? null) ? (array) $gatewayResult['llm_request'] : [];
        $state = is_array($gatewayResult['state'] ?? null) ? (array) $gatewayResult['state'] : [];
        $telemetry = array_merge(
            $this->defaultSkillTelemetry(),
            is_array($gatewayResult['telemetry'] ?? null) ? (array) $gatewayResult['telemetry'] : []
        );
        $routingHintSteps = $this->extractRoutingHintSteps($gatewayResult, $telemetry);
        $rawRequestMode = $context['request_mode'] ?? $gatewayResult['request_mode'] ?? $telemetry['request_mode'] ?? null;
        $normalizedExplicitRequestMode = $this->normalizeRequestMode((string) $rawRequestMode);
        $requestModeInvalid = $rawRequestMode !== null
            && trim((string) $rawRequestMode) !== ''
            && $normalizedExplicitRequestMode === '';
        $requestMode = $normalizedExplicitRequestMode !== ''
            ? $normalizedExplicitRequestMode
            : $this->resolveRequestMode($gatewayResult, $context, $state, $telemetry);
        $runtimeBudget = $this->runtimeBudgetConfig($requestMode);
        $queryHash = $this->hashQueryForTrace($this->extractRetrievalQuery($gatewayResult, $context));

        $telemetry['request_mode'] = $requestMode;
        $telemetry['request_mode_invalid'] = $requestModeInvalid;
        $telemetry['runtime_budget'] = $runtimeBudget;
        $telemetry['query_hash'] = $queryHash;

        $routeOrder = ['cache', 'rules', 'skills', 'rag', 'llm'];
        $violations = [];
        $contractVersions = [
            'router_policy' => 'unknown',
            'action_catalog' => 'unknown',
            'agentops_metrics_contract' => 'unknown',
            'skills_catalog' => 'unknown',
        ];
        $catalog = [];
        $skillsRegistry = null;
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

        try {
            $skillsCatalog = $this->contracts->getSkillsCatalog();
            $skillsRegistry = new SkillRegistry($skillsCatalog);
            $contractVersions['skills_catalog'] = (string) ($skillsCatalog['version'] ?? 'unknown');
        } catch (\Throwable $e) {
            $telemetry['skill_result_status'] = 'registry_unavailable';
            $telemetry['skill_fallback_reason'] = 'skills_catalog_unavailable';
            $telemetry['skill_failure_detail'] = trim((string) $e->getMessage());
        }

        $skillOutcome = $this->maybeExecuteSkill(
            $action,
            $gatewayResult,
            $context,
            $runtimeBudget,
            $skillsRegistry
        );
        if ($skillOutcome !== []) {
            $action = (string) ($skillOutcome['action'] ?? $action);
            $reply = array_key_exists('reply', $skillOutcome) ? (string) ($skillOutcome['reply'] ?? '') : $reply;
            $command = is_array($skillOutcome['command'] ?? null) ? (array) $skillOutcome['command'] : $command;
            $llmRequest = is_array($skillOutcome['llm_request'] ?? null) ? (array) $skillOutcome['llm_request'] : $llmRequest;
            $context = array_merge($context, is_array($skillOutcome['context_overrides'] ?? null) ? (array) $skillOutcome['context_overrides'] : []);
            $telemetry = array_merge($telemetry, is_array($skillOutcome['telemetry'] ?? null) ? (array) $skillOutcome['telemetry'] : []);
            $skillRoutingHintSteps = $skillOutcome['routing_hint_steps'] ?? null;
            if (is_array($skillRoutingHintSteps) && $skillRoutingHintSteps !== []) {
                $routingHintSteps = $skillRoutingHintSteps;
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
            $context,
            $requestMode,
            $runtimeBudget
        );
        $llmContext = [];
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
                foreach ([
                    'semantic_memory_status',
                    'memory_type',
                    'reason',
                    'route_reason',
                    'semantic_enabled',
                    'rag_attempted',
                    'rag_used',
                    'rag_result_count',
                    'evidence_gate_status',
                    'fallback_reason',
                    'skip_evidence_gate',
                    'tenant_id',
                    'app_id',
                    'sector',
                    'agent_role',
                    'user_id',
                ] as $telemetryKey) {
                    if (array_key_exists($telemetryKey, $telemetry['retrieval'])) {
                        $telemetry[$telemetryKey] = $telemetry['retrieval'][$telemetryKey];
                    }
                }
            }
            $llmContext = is_array($retrieval['llm_context'] ?? null) ? (array) $retrieval['llm_context'] : [];
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
                'skip_evidence_gate' => (bool) ($telemetry['skip_evidence_gate'] ?? false),
                'evidence_gate_status' => (string) ($telemetry['evidence_gate_status'] ?? ''),
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
            'skills_catalog_version' => $contractVersions['skills_catalog'],
            'akp_version' => (string) (getenv('AKP_VERSION') ?: 'unknown'),
            'policy_pack_version' => (string) (getenv('POLICY_PACK_VERSION') ?: 'unknown'),
        ];
        $routePath = implode('>', $routePathSteps);
        $routeStageReasons = $this->buildRouteStageReasons($action, $gatewayResult, $telemetry, $routePathSteps);
        $routeLatencyMs = $this->latencyMs($routeStartedAt);
        $agentOps = [
            'route_path' => $routePath,
            'route_path_steps' => $routePathSteps,
            'route_stage_reasons' => $routeStageReasons,
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
            'route_reason' => $this->resolveRouteReason($action, $gateDecision, $telemetry),
            'request_mode' => $requestMode,
            'runtime_budget' => $runtimeBudget,
            'query_hash' => $queryHash,
            'router_latency_ms' => $routeLatencyMs,
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

        $runtimeGuard = $this->evaluateRuntimeGuard(
            $action,
            $gateDecision,
            $routePathSteps,
            $telemetry,
            $context,
            $state,
            $runtimeBudget
        );
        $telemetry = array_merge($telemetry, is_array($runtimeGuard['telemetry'] ?? null) ? (array) $runtimeGuard['telemetry'] : []);
        $agentOps = array_merge($agentOps, is_array($runtimeGuard['telemetry'] ?? null) ? (array) $runtimeGuard['telemetry'] : []);

        $finalAction = (bool) ($runtimeGuard['triggered'] ?? false)
            ? 'respond_local'
            : (($action === 'send_to_llm' && $gateDecision !== 'allow') ? 'respond_local' : $action);
        $telemetry['llm_used'] = $finalAction === 'send_to_llm';
        $telemetry['metrics_delta'] = $this->buildMetricsDelta($finalAction, $telemetry);
        $agentOps['metrics_delta'] = $telemetry['metrics_delta'];

        if ((bool) ($runtimeGuard['triggered'] ?? false)) {
            $guardReply = trim((string) ($runtimeGuard['reply'] ?? ''));
            return new IntentRouteResult(
                'respond_local',
                $guardReply !== '' ? $guardReply : 'Detuve esta ruta para evitar repeticion o exceso de presupuesto.',
                [],
                [],
                $state,
                $telemetry,
                $agentOps
            );
        }

        if ($action === 'send_to_llm' && !empty($llmRequest) && !empty($llmContext)) {
            $llmRequest = $this->injectSemanticContext($llmRequest, $llmContext, $telemetry);
        }

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
            return ['cache', 'rules', 'skills', 'rag', 'llm'];
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
            return ['cache', 'rules', 'skills', 'rag', 'llm'];
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
            $allowed = ['cache', 'rules', 'skills', 'rag', 'llm', 'action_contract'];
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
     * @param array<string,mixed> $gatewayResult
     * @param array<string,mixed> $context
     * @param array<string,mixed> $state
     * @param array<string,mixed> $telemetry
     */
    private function resolveRequestMode(array $gatewayResult, array $context, array $state, array $telemetry): string
    {
        foreach ([
            $context['request_mode'] ?? null,
            $gatewayResult['request_mode'] ?? null,
            $telemetry['request_mode'] ?? null,
        ] as $candidate) {
            $normalized = $this->normalizeRequestMode((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        $activeTask = strtolower(trim((string) ($state['active_task'] ?? $gatewayResult['active_task'] ?? '')));
        if ((bool) ($state['unknown_business_force_research'] ?? false) || in_array($activeTask, self::RESEARCH_ACTIVE_TASKS, true)) {
            return self::REQUEST_MODE_RESEARCH;
        }

        return self::REQUEST_MODE_OPERATION;
    }

    private function normalizeRequestMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === self::REQUEST_MODE_OPERATION || $mode === self::REQUEST_MODE_RESEARCH) {
            return $mode;
        }

        return '';
    }

    /**
     * @return array<string,int>
     */
    private function runtimeBudgetConfig(string $requestMode): array
    {
        $normalizedMode = $this->normalizeRequestMode($requestMode);
        if ($normalizedMode === '') {
            $normalizedMode = self::REQUEST_MODE_OPERATION;
        }

        return self::REQUEST_MODE_BUDGETS[$normalizedMode];
    }

    private function hashQueryForTrace(string $query): string
    {
        $normalized = $this->normalizeFreeText($query);
        if ($normalized === '') {
            return '';
        }

        return substr(sha1($normalized), 0, 16);
    }

    private function latencyMs(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param array<string,mixed> $telemetry
     * @param array<string,mixed> $context
     * @param array<string,mixed> $state
     * @param array<string,int> $runtimeBudget
     * @return array{triggered:bool,reply:string,telemetry:array<string,mixed>}
     */
    private function evaluateRuntimeGuard(
        string $action,
        string $gateDecision,
        array $routePathSteps,
        array $telemetry,
        array $context,
        array $state,
        array $runtimeBudget
    ): array {
        $routePath = implode('>', $routePathSteps);
        $routeStepCount = count($routePathSteps);
        $toolCallsCount = $this->resolveCount($context['tool_calls_count'] ?? $telemetry['tool_calls_count'] ?? ($action === 'execute_command' ? 1 : 0));
        $retryCount = $this->resolveCount($context['retry_count'] ?? $telemetry['retry_count'] ?? 0);
        $semanticQueriesCount = (bool) ($telemetry['rag_attempted'] ?? false) ? 1 : 0;
        $llmFallbackCount = ($action === 'send_to_llm' && $gateDecision === 'allow') ? 1 : 0;
        $routerLatencyMs = $this->resolveCount($telemetry['router_latency_ms'] ?? 0);
        $loopCheck = $this->detectRepeatedRouteLoop($state, $telemetry, $routePath, $runtimeBudget);
        $tenantScopeViolation = trim((string) ($telemetry['tenant_id'] ?? $context['tenant_id'] ?? '')) === '';
        $guardTriggered = false;
        $guardReason = 'none';
        $guardStage = 'none';
        $guardReply = '';

        if ((bool) ($telemetry['request_mode_invalid'] ?? false)) {
            $guardTriggered = true;
            $guardReason = 'invalid_request_mode';
            $guardStage = 'router';
            $guardReply = 'El request_mode recibido no es valido para esta ruta.';
        } elseif ($routeStepCount > (int) ($runtimeBudget['max_router_steps'] ?? 4)) {
            $guardTriggered = true;
            $guardReason = 'router_steps_budget_exceeded';
            $guardStage = 'router';
            $guardReply = 'Detuve esta ruta porque excede el presupuesto operativo permitido.';
        } elseif ($toolCallsCount > (int) ($runtimeBudget['max_tool_calls'] ?? 1)) {
            $guardTriggered = true;
            $guardReason = 'tool_calls_budget_exceeded';
            $guardStage = 'execution';
            $guardReply = 'Detuve esta ruta porque excede el numero permitido de acciones en este turno.';
        } elseif ($retryCount > (int) ($runtimeBudget['max_retries'] ?? 0)) {
            $guardTriggered = true;
            $guardReason = 'retry_budget_exceeded';
            $guardStage = 'retry';
            $guardReply = 'Detuve esta ruta para evitar reintentos repetitivos sin control.';
        } elseif ($semanticQueriesCount > (int) ($runtimeBudget['max_semantic_queries'] ?? 1)) {
            $guardTriggered = true;
            $guardReason = 'semantic_query_budget_exceeded';
            $guardStage = 'rag';
            $guardReply = 'Detuve esta ruta porque excede el presupuesto de consultas semanticas.';
        } elseif ($llmFallbackCount > (int) ($runtimeBudget['max_llm_fallbacks'] ?? 1)) {
            $guardTriggered = true;
            $guardReason = 'llm_fallback_budget_exceeded';
            $guardStage = 'llm';
            $guardReply = 'Detuve esta ruta porque excede el presupuesto de fallback a LLM.';
        } elseif ($routerLatencyMs > (int) ($runtimeBudget['max_execution_ms'] ?? 1500)) {
            $guardTriggered = true;
            $guardReason = 'execution_time_budget_exceeded';
            $guardStage = 'router';
            $guardReply = 'Detuve esta ruta porque excede el tiempo maximo permitido para este modo.';
        } elseif ((bool) ($loopCheck['triggered'] ?? false)) {
            $guardTriggered = true;
            $guardReason = 'repeated_route_without_progress';
            $guardStage = 'router';
            $guardReply = 'Detuve esta ruta para evitar repetir el mismo fallback sin progreso. Cambia el dato clave o concreta el siguiente paso.';
        }

        $telemetryPatch = [
            'tool_calls_count' => $toolCallsCount,
            'retry_count' => $retryCount,
            'semantic_queries_count' => $semanticQueriesCount,
            'llm_fallback_count' => $llmFallbackCount,
            'loop_guard_triggered' => $guardTriggered,
            'loop_guard_reason' => $guardReason,
            'loop_guard_stage' => $guardStage,
            'same_route_repeat_count' => (int) ($loopCheck['repeat_count'] ?? 0),
            'tenant_scope_violation_detected' => $tenantScopeViolation,
            'route_path_coherent' => $routePath !== '',
        ];

        if ($guardTriggered) {
            $telemetryPatch['fallback_reason'] = $guardReason;
            $telemetryPatch['route_reason'] = $action === 'send_to_llm'
                ? 'loop_guard_blocked_before_llm'
                : 'loop_guard_blocked_before_action';
        }

        return [
            'triggered' => $guardTriggered,
            'reply' => $guardReply,
            'telemetry' => $telemetryPatch,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $telemetry
     * @param array<string,int> $runtimeBudget
     * @return array{triggered:bool,repeat_count:int}
     */
    private function detectRepeatedRouteLoop(array $state, array $telemetry, string $routePath, array $runtimeBudget): array
    {
        $history = is_array($state['agentops_trace_history'] ?? null) ? (array) $state['agentops_trace_history'] : [];
        if ($history === [] || $routePath === '') {
            return ['triggered' => false, 'repeat_count' => 0];
        }

        $requestMode = trim((string) ($telemetry['request_mode'] ?? self::REQUEST_MODE_OPERATION));
        $routeReason = trim((string) ($telemetry['route_reason'] ?? ''));
        $queryHash = trim((string) ($telemetry['query_hash'] ?? ''));
        if ($queryHash === '') {
            return ['triggered' => false, 'repeat_count' => 0];
        }

        $repeatCount = 0;
        foreach (array_reverse(array_slice($history, -self::TRACE_HISTORY_LIMIT)) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string) ($entry['query_hash'] ?? '')) !== $queryHash) {
                break;
            }
            if (trim((string) ($entry['route_path'] ?? '')) !== $routePath) {
                break;
            }
            if (trim((string) ($entry['route_reason'] ?? '')) !== $routeReason) {
                break;
            }
            if (trim((string) ($entry['request_mode'] ?? self::REQUEST_MODE_OPERATION)) !== $requestMode) {
                break;
            }
            $repeatCount++;
        }

        return [
            'triggered' => $repeatCount >= (int) ($runtimeBudget['max_same_route_repeats'] ?? 2),
            'repeat_count' => $repeatCount,
        ];
    }

    /**
     * @param mixed $value
     */
    private function resolveCount(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $telemetry
     * @return array<string,int>
     */
    private function buildMetricsDelta(string $finalAction, array $telemetry): array
    {
        $evidenceGateStatus = trim((string) ($telemetry['evidence_gate_status'] ?? ''));
        $fallbackReason = trim((string) ($telemetry['fallback_reason'] ?? ''));
        $tenantViolation = (bool) ($telemetry['tenant_scope_violation_detected'] ?? false);

        return [
            'requests_total' => 1,
            'routed_by_rules' => ($finalAction !== 'send_to_llm' && !(bool) ($telemetry['rag_used'] ?? false)) ? 1 : 0,
            'routed_by_rag' => (bool) ($telemetry['rag_used'] ?? false) ? 1 : 0,
            'routed_by_llm' => $finalAction === 'send_to_llm' ? 1 : 0,
            'evidence_gate_failures' => $evidenceGateStatus === 'insufficient_evidence' ? 1 : 0,
            'rag_errors' => ($fallbackReason === 'rag_error' || trim((string) ($telemetry['rag_error'] ?? '')) !== '') ? 1 : 0,
            'loop_guard_hits' => (bool) ($telemetry['loop_guard_triggered'] ?? false) ? 1 : 0,
            'semantic_disabled_requests' => trim((string) ($telemetry['semantic_memory_status'] ?? '')) === 'disabled' ? 1 : 0,
            'llm_fallback_requests' => $finalAction === 'send_to_llm' ? 1 : 0,
            'tenant_scope_violations_detected' => $tenantViolation ? 1 : 0,
            'rag_result_count_total' => $this->resolveCount($telemetry['rag_result_count'] ?? 0),
        ];
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
        foreach ([
            'semantic_enabled',
            'rag_attempted',
            'rag_used',
            'rag_result_count',
            'evidence_gate_status',
            'fallback_reason',
            'memory_type',
            'tenant_id',
            'app_id',
            'sector',
            'agent_role',
            'user_id',
            'skip_evidence_gate',
            'request_mode',
            'runtime_budget',
            'query_hash',
            'tool_calls_count',
            'retry_count',
            'loop_guard_triggered',
            'skill_detected',
            'skill_selected',
            'skill_executed',
            'skill_failed',
            'skill_execution_ms',
            'skill_result_status',
            'skill_fallback_reason',
        ] as $key) {
            if (!array_key_exists($key, $merged) && array_key_exists($key, $telemetry)) {
                $merged[$key] = $telemetry[$key];
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
     * @return array<string,mixed>
     */
    private function defaultSkillTelemetry(): array
    {
        return [
            'skill_detected' => false,
            'skill_selected' => 'none',
            'skill_executed' => false,
            'skill_failed' => false,
            'skill_execution_mode' => 'none',
            'skill_execution_ms' => 0,
            'skill_result_status' => 'not_detected',
            'skill_fallback_reason' => 'none',
        ];
    }

    /**
     * @param array<string,mixed> $gatewayResult
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function maybeExecuteSkill(
        string $action,
        array $gatewayResult,
        array $context,
        array $runtimeBudget,
        ?SkillRegistry $skillsRegistry
    ): array {
        if ($action === 'execute_command' || !$skillsRegistry) {
            return [];
        }

        $query = $this->extractRetrievalQuery($gatewayResult, $context);
        $resolved = $this->skillResolver->resolve($query, $skillsRegistry, $context);
        if ($action !== 'send_to_llm') {
            $selectedName = strtolower(trim((string) (($resolved['selected']['name'] ?? '') ?: '')));
            if (!(bool) ($resolved['detected'] ?? false) || !$this->isDeterministicPreLlmSkill($selectedName)) {
                return [];
            }
        }
        if (!(bool) ($resolved['detected'] ?? false)) {
            return [
                'telemetry' => [
                    'skill_detected' => false,
                    'skill_selected' => 'none',
                    'skill_executed' => false,
                    'skill_failed' => false,
                    'skill_execution_mode' => 'none',
                    'skill_execution_ms' => 0,
                    'skill_result_status' => (string) ($resolved['reason'] ?? 'not_detected'),
                    'skill_fallback_reason' => 'no_skill_match',
                ],
            ];
        }

        $skill = is_array($resolved['selected'] ?? null) ? (array) $resolved['selected'] : [];
        if ($skill === []) {
            return [
                'telemetry' => [
                    'skill_detected' => false,
                    'skill_selected' => 'none',
                    'skill_executed' => false,
                    'skill_failed' => false,
                    'skill_execution_mode' => 'none',
                    'skill_execution_ms' => 0,
                    'skill_result_status' => 'selection_failed',
                    'skill_fallback_reason' => 'no_selected_skill',
                ],
            ];
        }

        $execution = $this->skillExecutor->execute($skill, $gatewayResult, $context, $runtimeBudget);
        $telemetry = is_array($execution['telemetry'] ?? null) ? (array) $execution['telemetry'] : [];
        $telemetry['skill_detected'] = true;
        $telemetry['skill_match_reason'] = (string) ($resolved['reason'] ?? 'skill_match');
        $telemetry['matched_skills'] = is_array($resolved['matched_skills'] ?? null) ? (array) $resolved['matched_skills'] : [];

        return array_merge($execution, [
            'telemetry' => $telemetry,
        ]);
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
        array $context,
        string $requestMode,
        array $runtimeBudget
    ): array {
        $query = $this->extractRetrievalQuery($gatewayResult, $context);
        $memoryType = $this->resolveRetrievalMemoryType($context, $actionCatalogEntry);
        $runtimeConfig = SemanticMemoryService::retrievalRuntimeConfig();
        $availability = SemanticMemoryService::availabilityFromEnv();
        $scope = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')),
            'app_id' => trim((string) ($context['app_id'] ?? $context['project_id'] ?? '')) ?: null,
            'sector' => trim((string) ($context['sector'] ?? '')) ?: null,
            'agent_role' => trim((string) ($context['agent_role'] ?? '')) ?: null,
            'user_id' => trim((string) ($context['user_id'] ?? '')) ?: null,
        ];
        $policy = $this->evaluateRetrievalPolicy($action, $actionCatalogEntry, $query, $scope);
        $baseTelemetry = [
            'semantic_enabled' => (bool) ($availability['enabled'] ?? false),
            'semantic_memory_status' => (string) ($availability['status'] ?? ($this->semanticMemoryAvailability['status'] ?? 'unresolved')),
            'rag_attempted' => false,
            'rag_used' => false,
            'rag_result_count' => 0,
            'route_reason' => (string) ($policy['route_reason'] ?? 'rag_not_applicable'),
            'evidence_gate_status' => (string) ($policy['evidence_gate_status'] ?? 'skipped_by_rule'),
            'fallback_reason' => (string) ($policy['fallback_reason'] ?? 'rag_not_required'),
            'skip_evidence_gate' => (bool) ($policy['skip_evidence_gate'] ?? false),
            'memory_type' => $memoryType,
            'tenant_id' => (string) $scope['tenant_id'],
            'app_id' => $scope['app_id'],
            'sector' => $scope['sector'],
            'agent_role' => $scope['agent_role'],
            'user_id' => $scope['user_id'],
            'top_k' => $runtimeConfig['top_k'],
            'min_score' => $runtimeConfig['min_score'],
            'min_evidence_chunks' => $runtimeConfig['min_evidence_chunks'],
            'max_context_chunks' => min(max(1, (int) ($runtimeBudget['max_context_chunks'] ?? $runtimeConfig['max_context_chunks'])), max(1, (int) $runtimeConfig['top_k'])),
            'reason' => (string) ($policy['reason'] ?? 'rag_not_applicable'),
            'request_mode' => $requestMode,
            'runtime_budget' => $runtimeBudget,
            'retrieval_latency_ms' => 0,
        ];

        if (!(bool) ($policy['should_attempt'] ?? false)) {
            return [
                'ok' => true,
                'rag_hit' => false,
                'hits' => [],
                'source_ids' => [],
                'evidence_ids' => [],
                'llm_context' => [],
                'telemetry' => $baseTelemetry,
            ];
        }

        $semanticMemory = $this->semanticMemory();
        if (!$semanticMemory) {
            $disabled = SemanticMemoryService::disabledResult(
                (string) ($this->semanticMemoryAvailability['reason'] ?? 'semantic_memory_unavailable'),
                [
                    'memory_type' => $memoryType,
                ]
            );
            $disabled['llm_context'] = [];
            $disabled['telemetry'] = array_merge(
                $baseTelemetry,
                is_array($disabled['telemetry'] ?? null) ? (array) $disabled['telemetry'] : [],
                [
                    'semantic_enabled' => false,
                    'semantic_memory_status' => (string) ($this->semanticMemoryAvailability['status'] ?? 'disabled'),
                    'evidence_gate_status' => 'disabled_by_config',
                    'fallback_reason' => 'semantic_memory_unavailable',
                    'route_reason' => 'rag_unavailable_before_llm',
                    'skip_evidence_gate' => false,
                ]
            );
            return $disabled;
        }

        try {
            $retrievalStartedAt = microtime(true);
            $retrieval = $semanticMemory->retrieve($query, array_merge($scope, [
                'memory_type' => $memoryType,
            ]), $runtimeConfig['top_k']);
            $preparedContext = SemanticMemoryService::prepareContext(
                $retrieval,
                (int) ($runtimeBudget['max_context_chunks'] ?? $runtimeConfig['max_context_chunks'])
            );
            $rawHitCount = count((array) ($retrieval['hits'] ?? []));
            $passed = $preparedContext['used_count'] >= $runtimeConfig['min_evidence_chunks'];
            $retrievalLatencyMs = $this->latencyMs($retrievalStartedAt);

            return [
                'ok' => true,
                'rag_hit' => $passed,
                'hits' => $passed ? (array) $preparedContext['chunks'] : [],
                'source_ids' => $passed ? (array) $preparedContext['source_ids'] : [],
                'evidence_ids' => $passed ? (array) $preparedContext['evidence_ids'] : [],
                'llm_context' => $passed ? $preparedContext : [],
                'telemetry' => array_merge(
                    $baseTelemetry,
                    is_array($retrieval['telemetry'] ?? null) ? (array) $retrieval['telemetry'] : [],
                    [
                        'semantic_enabled' => true,
                        'semantic_memory_status' => 'enabled',
                        'rag_attempted' => true,
                        'rag_used' => $passed,
                        'rag_result_count' => $passed ? (int) $preparedContext['used_count'] : 0,
                        'rag_result_count_raw' => $rawHitCount,
                        'evidence_gate_status' => $passed ? 'passed' : 'insufficient_evidence',
                        'fallback_reason' => $passed ? 'llm_last_resort_after_rag' : 'insufficient_evidence',
                        'route_reason' => $passed ? 'rag_backed_llm_fallback' : 'rag_attempted_but_insufficient',
                        'skip_evidence_gate' => false,
                        'retrieval_latency_ms' => $retrievalLatencyMs,
                    ]
                ),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => true,
                'rag_hit' => false,
                'hits' => [],
                'source_ids' => [],
                'evidence_ids' => [],
                'llm_context' => [],
                'telemetry' => array_merge($baseTelemetry, [
                    'semantic_enabled' => true,
                    'semantic_memory_status' => 'error',
                    'rag_attempted' => true,
                    'rag_used' => false,
                    'rag_result_count' => 0,
                    'evidence_gate_status' => 'insufficient_evidence',
                    'fallback_reason' => 'rag_error',
                    'route_reason' => 'rag_error_before_llm',
                    'skip_evidence_gate' => false,
                    'rag_error' => trim((string) $e->getMessage()),
                    'reason' => 'rag_error',
                    'retrieval_latency_ms' => 0,
                ]),
            ];
        }
    }

    private function semanticMemory(): ?SemanticMemoryService
    {
        if ($this->semanticMemoryResolved) {
            return $this->semanticMemory;
        }
        $this->semanticMemoryResolved = true;

        $availability = SemanticMemoryService::availabilityFromEnv();
        $this->semanticMemoryAvailability = $availability;
        if (!(bool) ($availability['enabled'] ?? false)) {
            $this->semanticMemory = null;
            return null;
        }

        try {
            $this->semanticMemory = new SemanticMemoryService();
            $this->semanticMemoryAvailability = [
                'enabled' => true,
                'status' => 'enabled',
                'reason' => 'semantic_memory_enabled',
            ];
            return $this->semanticMemory;
        } catch (\Throwable $e) {
            $this->semanticMemory = null;
            $this->semanticMemoryAvailability = [
                'enabled' => false,
                'status' => 'error',
                'reason' => 'semantic_memory_init_failed:' . trim((string) $e->getMessage()),
            ];
            return null;
        }
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $actionCatalogEntry
     */
    private function resolveRetrievalMemoryType(array $context, ?array $actionCatalogEntry): string
    {
        $candidate = trim((string) ($context['memory_type'] ?? ''));
        if (in_array($candidate, ['agent_training', 'sector_knowledge', 'user_memory'], true)) {
            return $candidate;
        }

        if (is_array($actionCatalogEntry)) {
            $catalogMemoryType = trim((string) ($actionCatalogEntry['memory_type'] ?? ''));
            if (in_array($catalogMemoryType, ['agent_training', 'sector_knowledge', 'user_memory'], true)) {
                return $catalogMemoryType;
            }
        }

        return 'sector_knowledge';
    }

    /**
     * @param array<string,mixed>|null $actionCatalogEntry
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function evaluateRetrievalPolicy(
        string $action,
        ?array $actionCatalogEntry,
        string $query,
        array $scope
    ): array {
        if ($action !== 'send_to_llm') {
            return [
                'should_attempt' => false,
                'skip_evidence_gate' => true,
                'evidence_gate_status' => 'skipped_by_rule',
                'route_reason' => 'deterministic_route_resolved_before_rag',
                'fallback_reason' => 'deterministic_route_resolved',
                'reason' => 'deterministic_route_resolved',
            ];
        }

        if (trim($query) === '') {
            return [
                'should_attempt' => false,
                'skip_evidence_gate' => true,
                'evidence_gate_status' => 'skipped_by_rule',
                'route_reason' => 'empty_query_before_rag',
                'fallback_reason' => 'empty_query',
                'reason' => 'empty_query',
            ];
        }

        if ($this->isTrivialConversationQuery($query)) {
            return [
                'should_attempt' => false,
                'skip_evidence_gate' => true,
                'evidence_gate_status' => 'skipped_by_rule',
                'route_reason' => 'rag_skipped_by_rule',
                'fallback_reason' => 'rag_not_required_for_trivial_query',
                'reason' => 'trivial_query',
            ];
        }

        if ((string) ($scope['tenant_id'] ?? '') === '') {
            return [
                'should_attempt' => false,
                'skip_evidence_gate' => false,
                'evidence_gate_status' => 'insufficient_evidence',
                'route_reason' => 'rag_skipped_missing_tenant_scope',
                'fallback_reason' => 'missing_tenant_scope',
                'reason' => 'missing_tenant_scope',
            ];
        }

        $requiresCorpus = $this->isTechnicalInformativeQuery($query)
            || (is_array($actionCatalogEntry) && (bool) ($actionCatalogEntry['rag_required'] ?? false));
        if (!$requiresCorpus) {
            return [
                'should_attempt' => false,
                'skip_evidence_gate' => true,
                'evidence_gate_status' => 'skipped_by_rule',
                'route_reason' => 'rag_skipped_by_rule',
                'fallback_reason' => 'rag_not_required',
                'reason' => 'non_technical_query',
            ];
        }

        return [
            'should_attempt' => true,
            'skip_evidence_gate' => false,
            'evidence_gate_status' => 'insufficient_evidence',
            'route_reason' => 'rag_attempt_required',
            'fallback_reason' => 'awaiting_rag_result',
            'reason' => 'technical_query_requires_corpus',
        ];
    }

    private function isTrivialConversationQuery(string $query): bool
    {
        $normalized = $this->normalizeFreeText($query);
        if ($normalized === '') {
            return true;
        }

        $trivialExact = [
            'hola',
            'hello',
            'hi',
            'gracias',
            'muchas gracias',
            'ok',
            'oki',
            'ok gracias',
            'dale',
            'vale',
            'listo',
            'si',
            'no',
            'adios',
            'buenos dias',
            'buenas tardes',
            'buenas noches',
        ];
        if (in_array($normalized, $trivialExact, true)) {
            return true;
        }

        $tokens = preg_split('/\s+/', $normalized) ?: [];
        return count($tokens) <= 2 && preg_match('/^(hola|gracias|ok|si|no|dale|vale|adios)/u', $normalized) === 1;
    }

    private function isTechnicalInformativeQuery(string $query): bool
    {
        $normalized = $this->normalizeFreeText($query);
        if ($normalized === '') {
            return false;
        }

        $technicalTerms = [
            'arquitectura',
            'architecture',
            'configuracion',
            'config',
            'policy',
            'politica',
            'proceso',
            'process',
            'error',
            'falla',
            'issue',
            'bug',
            'ayuda',
            'help',
            'soporte',
            'support',
            'faq',
            'documentacion',
            'docs',
            'workflow',
            'schema',
            'contrato',
            'contract',
            'qdrant',
            'vector',
            'embedding',
            'retrieval',
            'rag',
            'tenant',
            'app_id',
            'agent_role',
            'user_memory',
            'sector_knowledge',
            'agent_training',
            'api',
            'integracion',
            'integration',
            'billing',
            'invoice',
            'invoices',
            'factura',
            'facturas',
            'facturacion',
            'cobro',
            'como configurar',
            'como funciona',
            'necesito ayuda',
            'ayuda con',
            'problema con',
            'no funciona',
            'por que',
            'why',
            'how',
            'troubleshoot',
            'troubleshooting',
            'memoria semantica',
        ];
        foreach ($technicalTerms as $term) {
            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        $questionStarters = ['como', 'por que', 'porque', 'que', 'cual', 'where', 'what', 'how', 'why'];
        $tokens = preg_split('/\s+/', $normalized) ?: [];
        if (count($tokens) >= 5) {
            foreach ($questionStarters as $starter) {
                if (str_starts_with($normalized, $starter . ' ')) {
                    return true;
                }
            }
        }

        return str_contains($normalized, '?') && count($tokens) >= 5;
    }

    private function normalizeFreeText(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * @param array<string,mixed> $gatewayResult
     * @param array<string,mixed> $telemetry
     * @param array<int,string> $routePathSteps
     * @return array<string,string>
     */
    private function buildRouteStageReasons(
        string $action,
        array $gatewayResult,
        array $telemetry,
        array $routePathSteps
    ): array {
        $reasons = [];
        foreach ($routePathSteps as $step) {
            $normalizedStep = strtolower(trim((string) $step));
            if ($normalizedStep === '') {
                continue;
            }
            if ($normalizedStep === 'cache') {
                $cacheHit = (bool) ($telemetry['cache_hit'] ?? $gatewayResult['cache_hit'] ?? false);
                $reasons[$normalizedStep] = $cacheHit ? 'cache_hit' : 'cache_miss_or_unavailable';
                continue;
            }
            if ($normalizedStep === 'rules') {
                $rulesHit = (bool) ($telemetry['rules_hit'] ?? $gatewayResult['rules_hit'] ?? ($action !== 'send_to_llm'));
                $reasons[$normalizedStep] = $rulesHit ? 'resolved_by_rules_or_dsl' : 'rules_unresolved';
                continue;
            }
            if ($normalizedStep === 'skills') {
                if (!(bool) ($telemetry['skill_detected'] ?? false)) {
                    $reasons[$normalizedStep] = 'skill_not_detected';
                    continue;
                }
                $skillName = trim((string) ($telemetry['skill_selected'] ?? ''));
                $skillStatus = trim((string) ($telemetry['skill_result_status'] ?? 'selected'));
                $skillFallback = trim((string) ($telemetry['skill_fallback_reason'] ?? 'none'));
                if ((bool) ($telemetry['skill_failed'] ?? false)) {
                    $reasons[$normalizedStep] = 'skill_failed:' . ($skillFallback !== '' ? $skillFallback : 'runtime_failure');
                    continue;
                }
                $reasons[$normalizedStep] = 'skill_selected:' . ($skillName !== '' ? $skillName : 'unknown') . ':' . $skillStatus;
                continue;
            }
            if ($normalizedStep === 'rag') {
                $status = trim((string) ($telemetry['evidence_gate_status'] ?? ''));
                $reason = trim((string) ($telemetry['reason'] ?? $telemetry['fallback_reason'] ?? 'rag_not_evaluated'));
                $reasons[$normalizedStep] = $status !== '' ? ($status . ':' . $reason) : $reason;
                continue;
            }
            if ($normalizedStep === 'llm') {
                if ($action !== 'send_to_llm') {
                    $reasons[$normalizedStep] = 'not_needed';
                    continue;
                }
                if ((bool) ($telemetry['rag_used'] ?? false)) {
                    $reasons[$normalizedStep] = 'llm_last_resort_after_rag';
                    continue;
                }
                $fallbackReason = trim((string) ($telemetry['fallback_reason'] ?? 'llm_last_resort'));
                $reasons[$normalizedStep] = $fallbackReason;
                continue;
            }
            $reasons[$normalizedStep] = 'stage_processed';
        }

        return $reasons;
    }

    /**
     * @param array<string,mixed> $telemetry
     */
    private function resolveRouteReason(string $action, string $gateDecision, array $telemetry): string
    {
        if ((bool) ($telemetry['skill_executed'] ?? false) && $action !== 'send_to_llm') {
            return 'skill_resolved_before_llm';
        }
        if ($action !== 'send_to_llm') {
            return 'deterministic_route_resolved';
        }
        if ($gateDecision === 'blocked') {
            return 'blocked_before_llm';
        }
        if ($gateDecision === 'warn') {
            return 'fallback_blocked_by_evidence_gate';
        }
        if ((bool) ($telemetry['rag_used'] ?? false) && (bool) ($telemetry['skill_executed'] ?? false)) {
            return 'llm_after_skill_and_verified_rag';
        }
        if ((bool) ($telemetry['rag_used'] ?? false)) {
            return 'llm_after_verified_rag';
        }
        if (trim((string) ($telemetry['route_reason'] ?? '')) !== '') {
            return (string) $telemetry['route_reason'];
        }
        return 'llm_last_resort_without_rag';
    }

    /**
     * @param array<string,mixed> $llmRequest
     * @param array<string,mixed> $semanticContext
     * @param array<string,mixed> $telemetry
     * @return array<string,mixed>
     */
    private function injectSemanticContext(array $llmRequest, array $semanticContext, array $telemetry): array
    {
        if ($semanticContext === []) {
            return $llmRequest;
        }

        $llmRequest['semantic_context'] = $semanticContext;
        $llmRequest['semantic_context_meta'] = [
            'memory_type' => (string) ($telemetry['memory_type'] ?? ''),
            'tenant_id' => (string) ($telemetry['tenant_id'] ?? ''),
            'app_id' => $telemetry['app_id'] ?? null,
            'sector' => $telemetry['sector'] ?? null,
            'agent_role' => $telemetry['agent_role'] ?? null,
            'user_id' => $telemetry['user_id'] ?? null,
        ];

        $contextLines = ["Contexto semantico verificado:"];
        foreach ((array) ($semanticContext['chunks'] ?? []) as $index => $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $source = trim((string) ($chunk['source'] ?? $chunk['source_id'] ?? 'fuente'));
            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $contextLines[] = ($index + 1) . '. [' . $source . '] ' . $content;
        }

        if ($contextLines !== ["Contexto semantico verificado:"]) {
            $contextBlock = implode("\n", $contextLines);
            $llmRequest['user_message'] = trim((string) ($llmRequest['user_message'] ?? ''));
            $llmRequest['user_message'] = trim($llmRequest['user_message'] . "\n\n" . $contextBlock);
            if (is_array($llmRequest['prompt_contract'] ?? null)) {
                $llmRequest['prompt_contract']['SEMANTIC_CONTEXT'] = [
                    'memory_type' => (string) ($telemetry['memory_type'] ?? ''),
                    'chunks' => (array) ($semanticContext['chunks'] ?? []),
                    'source_ids' => (array) ($semanticContext['source_ids'] ?? []),
                    'evidence_ids' => (array) ($semanticContext['evidence_ids'] ?? []),
                ];
            }
        }

        return $llmRequest;
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
            'CreateTask' => 'ops.task.create',
            'ListPendingTasks' => 'ops.task.list_pending',
            'UpdateTaskStatus' => 'ops.task.update_status',
            'CreateReminder' => 'ops.reminder.create',
            'ListReminders' => 'ops.reminder.list',
            'UpdateReminderStatus' => 'ops.reminder.update_status',
            'CreateAlert' => 'ops.alert.create',
            'ListAlerts' => 'ops.alert.list',
            'UpdateAlertStatus' => 'ops.alert.update_status',
            'FetchPendingOperationalItems' => 'ops.pending.list',
            'UploadMedia' => 'media.upload',
            'ListMedia' => 'media.list',
            'GetMedia' => 'media.get',
            'DeleteMedia' => 'media.delete',
            'GenerateMediaThumbnail' => 'media.generate_thumbnail',
            'SearchEntities' => 'entity.search',
            'ResolveEntityReference' => 'entity.resolve',
            'CreatePOSDraft' => 'pos.create_draft',
            'GetPOSDraft' => 'pos.get_draft',
            'AddPOSDraftLine' => 'pos.add_draft_line',
            'AddPOSLineByReference' => 'pos.add_line_by_reference',
            'RemovePOSDraftLine' => 'pos.remove_draft_line',
            'AttachPOSDraftCustomer' => 'pos.attach_customer',
            'ListPOSOpenDrafts' => 'pos.list_open_drafts',
            'FindPOSProduct' => 'pos.find_product',
            'GetPOSProductCandidates' => 'pos.get_product_candidates',
            'RepricePOSDraft' => 'pos.reprice_draft',
            'FinalizePOSSale' => 'pos.finalize_sale',
            'GetPOSSale' => 'pos.get_sale',
            'ListPOSSales' => 'pos.list_sales',
            'BuildPOSReceipt' => 'pos.build_receipt',
            'GetPOSSaleByNumber' => 'pos.get_sale_by_number',
            'CancelPOSSale' => 'pos.cancel_sale',
            'CreatePOSReturn' => 'pos.create_return',
            'GetPOSReturn' => 'pos.get_return',
            'ListPOSReturns' => 'pos.list_returns',
            'BuildPOSReturnReceipt' => 'pos.build_return_receipt',
            'OpenPOSCashRegister' => 'pos.open_cash_register',
            'GetPOSOpenCashSession' => 'pos.get_open_cash_session',
            'ClosePOSCashRegister' => 'pos.close_cash_register',
            'BuildPOSCashSummary' => 'pos.build_cash_summary',
            'ListPOSCashSessions' => 'pos.list_cash_sessions',
            'CreatePurchaseDraft' => 'purchases.create_draft',
            'GetPurchaseDraft' => 'purchases.get_draft',
            'AddPurchaseDraftLine' => 'purchases.add_draft_line',
            'RemovePurchaseDraftLine' => 'purchases.remove_draft_line',
            'AttachPurchaseDraftSupplier' => 'purchases.attach_supplier',
            'FinalizePurchase' => 'purchases.finalize',
            'GetPurchase' => 'purchases.get_purchase',
            'ListPurchases' => 'purchases.list',
            'GetPurchaseByNumber' => 'purchases.get_by_number',
            'AttachPurchaseDraftDocument' => 'purchases.attach_document_to_draft',
            'AttachPurchaseDocument' => 'purchases.attach_document',
            'ListPurchaseDocuments' => 'purchases.list_documents',
            'GetPurchaseDocument' => 'purchases.get_document',
            'DetachPurchaseDocument' => 'purchases.detach_document',
            'RegisterPurchaseDocumentMetadata' => 'purchases.register_document_metadata',
            'CreateFiscalDocument' => 'fiscal.create_document',
            'CreateFiscalSalesInvoiceFromSale' => 'fiscal.create_sales_invoice_from_sale',
            'CreateFiscalCreditNote' => 'fiscal.create_credit_note',
            'CreateFiscalSupportDocumentFromPurchase' => 'fiscal.create_support_document_from_purchase',
            'GetFiscalDocument' => 'fiscal.get_document',
            'ListFiscalDocuments' => 'fiscal.list_documents',
            'ListFiscalDocumentsByType' => 'fiscal.list_documents_by_type',
            'GetFiscalDocumentBySource' => 'fiscal.get_by_source',
            'BuildFiscalDocumentPayload' => 'fiscal.build_document_payload',
            'RecordFiscalEvent' => 'fiscal.record_event',
            'UpdateFiscalDocumentStatus' => 'fiscal.update_status',
            'CreateEcommerceStore' => 'ecommerce.create_store',
            'UpdateEcommerceStore' => 'ecommerce.update_store',
            'RegisterEcommerceStoreCredentials' => 'ecommerce.register_credentials',
            'ValidateEcommerceStoreSetup' => 'ecommerce.validate_store_setup',
            'ValidateEcommerceConnection' => 'ecommerce.validate_connection',
            'GetEcommerceStoreMetadata' => 'ecommerce.get_store_metadata',
            'GetEcommercePlatformCapabilities' => 'ecommerce.get_platform_capabilities',
            'PingEcommerceStore' => 'ecommerce.ping_store',
            'ListEcommerceStores' => 'ecommerce.list_stores',
            'GetEcommerceStore' => 'ecommerce.get_store',
            'CreateEcommerceSyncJob' => 'ecommerce.create_sync_job',
            'ListEcommerceSyncJobs' => 'ecommerce.list_sync_jobs',
            'ListEcommerceOrderRefs' => 'ecommerce.list_order_refs',
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

    private function isDeterministicPreLlmSkill(string $selectedName): bool
    {
        return str_starts_with($selectedName, 'media_')
            || str_starts_with($selectedName, 'pos_')
            || str_starts_with($selectedName, 'purchases_')
            || str_starts_with($selectedName, 'fiscal_')
            || str_starts_with($selectedName, 'ecommerce_')
            || in_array($selectedName, ['entity_search', 'entity_resolve'], true);
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
