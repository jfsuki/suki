<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class AgentToolsIntegrationService
{
    private ContractRegistry $contracts;
    private TenantPlanService $tenantPlanService;
    private TenantAccessControlService $accessControlService;
    private ProjectRegistry $projectRegistry;
    private SkillResolver $skillResolver;

    public function __construct(
        ?ContractRegistry $contracts = null,
        ?TenantPlanService $tenantPlanService = null,
        ?TenantAccessControlService $accessControlService = null,
        ?ProjectRegistry $projectRegistry = null,
        ?SkillResolver $skillResolver = null
    ) {
        $this->contracts = $contracts ?? new ContractRegistry();
        $this->tenantPlanService = $tenantPlanService ?? new TenantPlanService();
        $this->accessControlService = $accessControlService ?? new TenantAccessControlService();
        $this->projectRegistry = $projectRegistry ?? new ProjectRegistry();
        $this->skillResolver = $skillResolver ?? new SkillResolver();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function listToolGroups(string $tenantId, string $userId, ?string $projectId = null, array $options = []): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $userId = $this->requireString($userId, 'user_id');
        $items = [];
        $enabledCount = 0;
        $allowedCount = 0;

        foreach (array_keys($this->moduleDefinitions()) as $moduleKey) {
            $item = $this->buildModuleCapabilityPayload($tenantId, $userId, $moduleKey, $projectId, $options, false);
            $items[] = $item;
            if (($item['enabled'] ?? false) === true) {
                $enabledCount++;
            }
            if (($item['allowed'] ?? false) === true) {
                $allowedCount++;
            }
        }

        return [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'target_user_id' => $userId,
            'tool_groups' => $items,
            'result_count' => count($items),
            'enabled_count' => $enabledCount,
            'allowed_count' => $allowedCount,
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getModuleCapabilities(string $tenantId, string $userId, string $moduleKey, ?string $projectId = null, array $options = []): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $userId = $this->requireString($userId, 'user_id');
        $moduleKey = $this->normalizeModuleKey($moduleKey);
        if ($moduleKey === '') {
            throw new RuntimeException('AGENT_TOOLS_MODULE_KEY_INVALID');
        }

        return $this->buildModuleCapabilityPayload($tenantId, $userId, $moduleKey, $projectId, $options, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkModuleEnabled(string $tenantId, string $moduleKey, ?string $projectId = null): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $definition = $this->requireModuleDefinition($moduleKey);
        $planState = $this->resolveModulePlanState($tenantId, $definition, $projectId);

        return [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'module_key' => $definition['module_key'],
            'tool_group' => $definition['tool_group'],
            'enabled' => $planState['enabled'],
            'allowed' => null,
            'required_context' => [],
            'suggested_next_actions' => [],
            'ambiguity_detected' => false,
            'denial_reason' => $planState['denial_reason'],
            'plan_key' => $planState['plan_key'],
            'enabled_source' => $planState['enabled_source'],
            'result_status' => $planState['enabled'] ? 'enabled' : 'disabled',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function checkActionAllowed(
        string $tenantId,
        string $userId,
        string $moduleKey,
        string $actionKey,
        ?string $projectId = null,
        array $options = []
    ): array {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $userId = $this->requireString($userId, 'user_id');
        $definition = $this->requireModuleDefinition($moduleKey);
        $actionEntry = $this->resolveModuleActionEntry($definition, $actionKey);
        if ($actionEntry === null) {
            return [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'target_user_id' => $userId,
                'module_key' => $definition['module_key'],
                'tool_group' => $definition['tool_group'],
                'action_key' => trim((string) $actionKey),
                'catalog_action' => '',
                'skill_name' => '',
                'enabled' => false,
                'allowed' => false,
                'required_context' => [],
                'suggested_next_actions' => [],
                'ambiguity_detected' => false,
                'denial_reason' => 'action_not_registered',
                'result_status' => 'action_not_registered',
            ];
        }

        $planState = $this->resolveModulePlanState($tenantId, $definition, $projectId);
        $permissionState = $this->resolveActionPermissionState($tenantId, $userId, $definition, $actionEntry, $options);
        $allowed = ($planState['enabled'] ?? false) === true && ($permissionState['allowed'] ?? false) === true;
        $denialReason = null;
        if (($planState['enabled'] ?? false) !== true) {
            $denialReason = (string) ($planState['denial_reason'] ?? 'module_disabled_by_plan');
        } elseif (($permissionState['allowed'] ?? false) !== true) {
            $denialReason = (string) ($permissionState['denial_reason'] ?? 'permission_denied');
        }

        return [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'target_user_id' => $userId,
            'module_key' => $definition['module_key'],
            'tool_group' => $definition['tool_group'],
            'action_key' => $actionEntry['action_key'],
            'catalog_action' => $actionEntry['catalog_action'],
            'skill_name' => $actionEntry['skill_name'],
            'enabled' => (bool) ($planState['enabled'] ?? false),
            'allowed' => $allowed,
            'required_context' => $this->requiredContextForSkill($actionEntry['skill_name']),
            'suggested_next_actions' => $allowed ? [$actionEntry['action_key']] : [],
            'ambiguity_detected' => false,
            'denial_reason' => $denialReason,
            'plan_key' => $planState['plan_key'],
            'permission_checked' => $permissionState['permission_checked'] ?? ($definition['permission_module_key'] . '.' . $actionEntry['action_key']),
            'decision' => $allowed ? 'allow' : 'deny',
            'result_status' => $allowed ? 'allowed' : 'denied',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function resolveToolForRequest(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $userId = $this->requireString($payload['user_id'] ?? $payload['target_user_id'] ?? null, 'user_id');
        $projectId = $this->nullableString($payload['project_id'] ?? $payload['app_id'] ?? null);
        $messageText = trim((string) ($payload['request_text'] ?? $payload['message_text'] ?? ''));
        if ($messageText === '') {
            throw new RuntimeException('AGENT_TOOLS_REQUEST_TEXT_REQUIRED');
        }

        $requestedModule = $this->normalizeModuleKey((string) ($payload['requested_module'] ?? $payload['module_key'] ?? ''));
        $moduleSkillMatch = $this->resolveModuleSkillMatch($messageText, $payload);
        $rankedModules = $this->rankModulesForRequest($messageText, $requestedModule, $moduleSkillMatch);
        $topCandidate = $rankedModules[0] ?? null;
        $ambiguous = $this->isAmbiguousResolution($rankedModules, $requestedModule, $moduleSkillMatch);

        if ($requestedModule === '' && $ambiguous) {
            $candidateModules = array_map(
                fn(array $candidate): array => [
                    'module_key' => (string) ($candidate['module_key'] ?? ''),
                    'tool_group' => (string) ($candidate['tool_group'] ?? ''),
                    'label' => (string) ($candidate['label'] ?? ''),
                ],
                array_slice($rankedModules, 0, 3)
            );

            return [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'target_user_id' => $userId,
                'requested_module' => null,
                'resolved_module' => null,
                'tool_group' => '',
                'enabled' => null,
                'allowed' => null,
                'required_context' => [],
                'suggested_next_actions' => [],
                'ambiguity_detected' => true,
                'candidate_modules' => $candidateModules,
                'denial_reason' => null,
                'reply_hint' => $this->buildAmbiguityReply($candidateModules),
                'result_status' => 'ambiguous',
            ];
        }

        $resolvedModule = $requestedModule !== ''
            ? $requestedModule
            : trim((string) ($topCandidate['module_key'] ?? ''));
        if ($resolvedModule === '') {
            return [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'target_user_id' => $userId,
                'requested_module' => $requestedModule !== '' ? $requestedModule : null,
                'resolved_module' => null,
                'tool_group' => '',
                'enabled' => null,
                'allowed' => null,
                'required_context' => [],
                'suggested_next_actions' => [],
                'ambiguity_detected' => false,
                'candidate_modules' => [],
                'denial_reason' => 'request_unresolved',
                'reply_hint' => 'No detecte un modulo claro. Indica si quieres POS, compras, fiscal, ecommerce, media, acceso, plan o uso.',
                'result_status' => 'unresolved',
            ];
        }

        $definition = $this->requireModuleDefinition($resolvedModule);
        $skillName = '';
        if (is_array($moduleSkillMatch) && (string) ($moduleSkillMatch['module_key'] ?? '') === $resolvedModule) {
            $skillName = trim((string) ($moduleSkillMatch['name'] ?? ''));
        }

        $parserOutcome = null;
        $actionEntry = null;
        $requiredContext = [];
        $replyHint = '';
        $needsClarification = false;
        $parserAmbiguity = false;
        if ($skillName !== '') {
            $parserOutcome = $this->runModuleSkillParser($skillName, $payload + ['message_text' => $messageText]);
            $actionEntry = $this->resolveActionEntryFromSkillName($definition, $skillName);
            $requiredContext = $this->requiredContextForSkill($skillName);
            if (is_array($parserOutcome)) {
                $replyHint = trim((string) ($parserOutcome['reply'] ?? ''));
                $needsClarification = (string) ($parserOutcome['kind'] ?? '') === 'ask_user';
                $parserTelemetry = is_array($parserOutcome['telemetry'] ?? null) ? (array) $parserOutcome['telemetry'] : [];
                $parserAmbiguity = (($parserTelemetry['ambiguity_detected'] ?? false) === true);
                if ((string) ($parserOutcome['kind'] ?? '') === 'command') {
                    $replyHint = '';
                }
            }
        }

        $planState = $this->resolveModulePlanState($tenantId, $definition, $projectId);
        $permissionState = $actionEntry !== null
            ? $this->resolveActionPermissionState($tenantId, $userId, $definition, $actionEntry, $payload)
            : $this->resolveModulePermissionState($tenantId, $userId, $definition, $payload);
        $enabled = (bool) ($planState['enabled'] ?? false);
        $allowed = $enabled && (bool) ($permissionState['allowed'] ?? false);
        $denialReason = null;
        $resultStatus = 'resolved';

        if (!$enabled) {
            $denialReason = (string) ($planState['denial_reason'] ?? 'module_disabled_by_plan');
            $resultStatus = 'module_disabled';
        } elseif (!$allowed) {
            $denialReason = (string) ($permissionState['denial_reason'] ?? 'permission_denied');
            $resultStatus = 'permission_denied';
        } elseif ($needsClarification) {
            $resultStatus = $parserAmbiguity ? 'ambiguous' : 'needs_input';
        }

        if ($replyHint === '') {
            if ($resultStatus === 'module_disabled') {
                $replyHint = 'La mejor ruta seria ' . $definition['label'] . ', pero ese modulo no esta habilitado para este tenant.';
            } elseif ($resultStatus === 'permission_denied') {
                $replyHint = 'Identifique el modulo correcto, pero esta accion no esta permitida para el usuario actual.';
            } elseif ($resultStatus === 'resolved') {
                $replyHint = 'Identifique una ruta segura para esta solicitud.';
            }
        }

        $candidateModules = array_map(
            fn(array $candidate): array => [
                'module_key' => (string) ($candidate['module_key'] ?? ''),
                'tool_group' => (string) ($candidate['tool_group'] ?? ''),
                'label' => (string) ($candidate['label'] ?? ''),
            ],
            array_slice($rankedModules, 0, 3)
        );

        return [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'target_user_id' => $userId,
            'requested_module' => $requestedModule !== '' ? $requestedModule : null,
            'resolved_module' => $resolvedModule,
            'tool_group' => $definition['tool_group'],
            'skill_name' => $skillName,
            'action_key' => $actionEntry['action_key'] ?? '',
            'catalog_action' => $actionEntry['catalog_action'] ?? '',
            'enabled' => $enabled,
            'allowed' => $allowed,
            'required_context' => $requiredContext,
            'suggested_next_actions' => $allowed ? $this->suggestedNextActions($definition) : [],
            'ambiguity_detected' => $parserAmbiguity || $ambiguous,
            'candidate_modules' => $candidateModules,
            'denial_reason' => $denialReason,
            'reply_hint' => $replyHint,
            'result_status' => $resultStatus,
        ];
    }

    private function buildModuleCapabilityPayload(
        string $tenantId,
        string $userId,
        string $moduleKey,
        ?string $projectId,
        array $options,
        bool $includeActions
    ): array {
        $definition = $this->requireModuleDefinition($moduleKey);
        $planState = $this->resolveModulePlanState($tenantId, $definition, $projectId);
        $permissionState = $this->resolveModulePermissionState($tenantId, $userId, $definition, $options);
        $enabled = (bool) ($planState['enabled'] ?? false);
        $allowed = $enabled && (bool) ($permissionState['allowed'] ?? false);
        $denialReason = null;
        if (!$enabled) {
            $denialReason = (string) ($planState['denial_reason'] ?? 'module_disabled_by_plan');
        } elseif (!$allowed) {
            $denialReason = (string) ($permissionState['denial_reason'] ?? 'permission_denied');
        }

        $actionEntries = $this->moduleActionEntries($definition);
        $visibleActions = ($includeActions && $enabled && $allowed) ? $this->exportActionEntries($actionEntries) : [];

        return [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'target_user_id' => $userId,
            'module_key' => $definition['module_key'],
            'tool_group' => $definition['tool_group'],
            'label' => $definition['label'],
            'enabled' => $enabled,
            'allowed' => $allowed,
            'required_context' => [],
            'suggested_next_actions' => ($enabled && $allowed) ? $this->suggestedNextActions($definition) : [],
            'ambiguity_detected' => false,
            'denial_reason' => $denialReason,
            'plan_key' => $planState['plan_key'],
            'enabled_source' => $planState['enabled_source'],
            'permission_checked' => $permissionState['permission_checked'] ?? ($definition['permission_module_key'] . '.*'),
            'decision' => $allowed ? 'allow' : 'deny',
            'actions' => $visibleActions,
            'action_count' => count($visibleActions),
            'registered_action_count' => count($actionEntries),
            'capability_summary' => [
                'plan_managed' => $definition['plan_managed'],
                'registered_actions' => count($actionEntries),
                'visible_actions' => count($visibleActions),
            ],
            'result_status' => !$enabled ? 'disabled' : (!$allowed ? 'denied' : 'success'),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function resolveModulePlanState(string $tenantId, array $definition, ?string $projectId): array
    {
        if (($definition['plan_managed'] ?? false) !== true) {
            return [
                'enabled' => true,
                'denial_reason' => null,
                'plan_key' => '',
                'enabled_source' => 'core_module',
            ];
        }

        try {
            $plan = $this->tenantPlanService->getEnabledModules($tenantId, $projectId);
            $enabledModules = is_array($plan['enabled_modules'] ?? null) ? (array) $plan['enabled_modules'] : [];
            $planModuleKey = (string) ($definition['plan_module_key'] ?? '');
            $enabled = in_array($planModuleKey, $enabledModules, true);

            return [
                'enabled' => $enabled,
                'denial_reason' => $enabled ? null : 'module_disabled_by_plan',
                'plan_key' => (string) ($plan['plan_key'] ?? ''),
                'enabled_source' => 'tenant_plan',
            ];
        } catch (Throwable $e) {
            if ((string) $e->getMessage() === 'TENANT_PLAN_NOT_FOUND') {
                return [
                    'enabled' => false,
                    'denial_reason' => 'plan_not_assigned',
                    'plan_key' => '',
                    'enabled_source' => 'plan_unavailable',
                ];
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function resolveModulePermissionState(string $tenantId, string $userId, array $definition, array $options): array
    {
        $actionEntries = $this->moduleActionEntries($definition);
        $lastPermission = null;
        foreach ($actionEntries as $actionEntry) {
            $permission = $this->permissionDecision($tenantId, $userId, $definition, $actionEntry, $options);
            $lastPermission = $permission;
            if (($permission['allowed'] ?? false) === true) {
                return [
                    'allowed' => true,
                    'denial_reason' => null,
                    'permission_checked' => $permission['permission_checked'] ?? '',
                ];
            }
        }

        return [
            'allowed' => false,
            'denial_reason' => 'permission_denied',
            'permission_checked' => $lastPermission['permission_checked'] ?? ($definition['permission_module_key'] . '.*'),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $actionEntry
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function resolveActionPermissionState(
        string $tenantId,
        string $userId,
        array $definition,
        array $actionEntry,
        array $options
    ): array {
        $permission = $this->permissionDecision($tenantId, $userId, $definition, $actionEntry, $options);

        return [
            'allowed' => (($permission['allowed'] ?? false) === true),
            'denial_reason' => (($permission['allowed'] ?? false) === true) ? null : 'permission_denied',
            'permission_checked' => $permission['permission_checked'] ?? ($definition['permission_module_key'] . '.' . $actionEntry['action_key']),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $actionEntry
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function permissionDecision(
        string $tenantId,
        string $userId,
        array $definition,
        array $actionEntry,
        array $options
    ): array {
        $fallbackRole = $this->resolveFallbackRole($userId, $options);

        return $this->accessControlService->checkPermission(
            $tenantId,
            $userId,
            (string) ($definition['permission_module_key'] ?? $definition['module_key']),
            (string) ($actionEntry['action_key'] ?? '*'),
            [
                'required_role' => (string) ($actionEntry['required_role'] ?? 'viewer'),
                'fallback_role' => $fallbackRole,
                'allow_legacy_fallback' => true,
            ]
        );
    }

    /**
     * @param array<string, mixed>|null $moduleSkillMatch
     * @return array<int, array<string, mixed>>
     */
    private function rankModulesForRequest(string $messageText, string $requestedModule, ?array $moduleSkillMatch): array
    {
        $normalizedMessage = $this->normalizeText($messageText);
        $candidates = [];

        foreach ($this->moduleDefinitions() as $moduleKey => $definition) {
            $score = 0;
            $reasons = [];
            if ($requestedModule !== '' && $requestedModule === $moduleKey) {
                $score += 10;
                $reasons[] = 'requested_module';
            }

            foreach ((array) ($definition['aliases'] ?? []) as $alias) {
                $alias = $this->normalizeText((string) $alias);
                if ($alias !== '' && str_contains($normalizedMessage, $alias)) {
                    $score += 2;
                    $reasons[] = 'module_alias:' . $alias;
                }
            }

            foreach ($this->moduleActionEntries($definition) as $actionEntry) {
                $phrase = $this->normalizeText(str_replace('_', ' ', (string) ($actionEntry['action_key'] ?? '')));
                if ($phrase !== '' && strlen($phrase) > 3 && str_contains($normalizedMessage, $phrase)) {
                    $score += 1;
                    $reasons[] = 'action_hint:' . $phrase;
                }
            }

            if (is_array($moduleSkillMatch) && (string) ($moduleSkillMatch['module_key'] ?? '') === $moduleKey) {
                $score += 5;
                $reasons[] = 'skill_match:' . (string) ($moduleSkillMatch['name'] ?? '');
            }

            if ($score <= 0) {
                continue;
            }

            $candidates[] = [
                'module_key' => $moduleKey,
                'tool_group' => (string) ($definition['tool_group'] ?? $moduleKey),
                'label' => (string) ($definition['label'] ?? $moduleKey),
                'score' => $score,
                'reasons' => array_values(array_unique($reasons)),
            ];
        }

        usort(
            $candidates,
            static fn(array $left, array $right): int => ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
                ?: strcmp((string) ($left['module_key'] ?? ''), (string) ($right['module_key'] ?? ''))
        );

        return $candidates;
    }

    /**
     * @param array<int, array<string, mixed>> $rankedModules
     */
    private function isAmbiguousResolution(array $rankedModules, string $requestedModule, ?array $moduleSkillMatch = null): bool
    {
        if ($requestedModule !== '' || count($rankedModules) < 2) {
            return false;
        }

        $first = (int) ($rankedModules[0]['score'] ?? 0);
        $second = (int) ($rankedModules[1]['score'] ?? 0);

        if ($first <= 0 || $second <= 0) {
            return false;
        }

        if ($moduleSkillMatch === null) {
            return true;
        }

        if (abs($first - $second) <= 1) {
            return true;
        }

        return $first <= 2;
    }

    /**
     * @param array<int, array<string, mixed>> $candidateModules
     */
    private function buildAmbiguityReply(array $candidateModules): string
    {
        if ($candidateModules === []) {
            return 'Detecte mas de un modulo posible. Indica el modulo que quieres usar.';
        }

        $parts = [];
        foreach ($candidateModules as $candidate) {
            $moduleKey = trim((string) ($candidate['module_key'] ?? ''));
            if ($moduleKey !== '') {
                $parts[] = 'module_key=' . $moduleKey;
            }
        }

        return 'Veo mas de un modulo posible. Aclara con ' . implode(', ', $parts) . '.';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveModuleSkillMatch(string $messageText, array $payload): ?array
    {
        $skillRegistry = $this->moduleSkillRegistry();
        $resolved = $this->skillResolver->resolve($messageText, $skillRegistry, [
            'attachments_count' => is_numeric($payload['attachments_count'] ?? null)
                ? (int) $payload['attachments_count']
                : 0,
        ]);
        if (($resolved['detected'] ?? false) !== true || !is_array($resolved['selected'] ?? null)) {
            return null;
        }

        $selected = (array) $resolved['selected'];
        $skillName = trim((string) ($selected['name'] ?? ''));
        if ($skillName === '') {
            return null;
        }

        $moduleKey = $this->moduleKeyFromSkillName($skillName);
        if ($moduleKey === '') {
            return null;
        }

        return [
            'name' => $skillName,
            'module_key' => $moduleKey,
            'reason' => (string) ($resolved['reason'] ?? 'skill_match'),
        ];
    }

    private function moduleKeyFromSkillName(string $skillName): string
    {
        foreach ($this->moduleDefinitions() as $moduleKey => $definition) {
            foreach ($this->moduleActionEntries($definition) as $actionEntry) {
                if ((string) ($actionEntry['skill_name'] ?? '') === $skillName) {
                    return $moduleKey;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function runModuleSkillParser(string $skillName, array $context): ?array
    {
        if (str_starts_with($skillName, 'media_')) {
            return (new MediaMessageParser())->parse($skillName, $context);
        }
        if (in_array($skillName, ['entity_search', 'entity_resolve'], true)) {
            return (new EntitySearchMessageParser())->parse($skillName, $context);
        }
        if (str_starts_with($skillName, 'pos_')) {
            return (new POSMessageParser())->parse($skillName, $context);
        }
        if (str_starts_with($skillName, 'purchases_')) {
            return (new PurchasesMessageParser())->parse($skillName, $context);
        }
        if (str_starts_with($skillName, 'fiscal_')) {
            return (new FiscalEngineMessageParser())->parse($skillName, $context);
        }
        if (str_starts_with($skillName, 'ecommerce_')) {
            return (new EcommerceHubMessageParser())->parse($skillName, $context);
        }
        if (in_array($skillName, [
            'tenant_add_user',
            'tenant_list_users',
            'tenant_get_user_role',
            'tenant_update_user_role',
            'tenant_deactivate_user',
            'tenant_check_permission',
        ], true)) {
            return (new TenantAccessControlMessageParser())->parse($skillName, $context);
        }
        if (in_array($skillName, [
            'tenant_assign_plan',
            'tenant_get_plan',
            'tenant_list_plans',
            'tenant_set_plan_limits',
            'tenant_check_plan_limit',
            'tenant_get_enabled_modules',
        ], true)) {
            return (new TenantPlanMessageParser())->parse($skillName, $context);
        }
        if (str_starts_with($skillName, 'usage_')) {
            return (new UsageMeteringMessageParser())->parse($skillName, $context);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>|null
     */
    private function resolveActionEntryFromSkillName(array $definition, string $skillName): ?array
    {
        foreach ($this->moduleActionEntries($definition) as $actionEntry) {
            if ((string) ($actionEntry['skill_name'] ?? '') === $skillName) {
                return $actionEntry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>|null
     */
    private function resolveModuleActionEntry(array $definition, string $actionKey): ?array
    {
        $normalized = strtolower(trim($actionKey));
        if ($normalized === '') {
            return null;
        }

        foreach ($this->moduleActionEntries($definition) as $actionEntry) {
            $catalogAction = strtolower(trim((string) ($actionEntry['catalog_action'] ?? '')));
            $skillName = strtolower(trim((string) ($actionEntry['skill_name'] ?? '')));
            $entryActionKey = strtolower(trim((string) ($actionEntry['action_key'] ?? '')));
            if (
                $normalized === $entryActionKey
                || $normalized === $catalogAction
                || $normalized === $skillName
                || str_ends_with($catalogAction, '.' . $normalized)
            ) {
                return $actionEntry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    private function moduleActionEntries(array $definition): array
    {
        $catalogPrefix = trim((string) ($definition['catalog_prefix'] ?? ''));
        if ($catalogPrefix === '') {
            return [];
        }

        $entries = [];
        foreach ($this->actionCatalogEntries() as $entry) {
            if (!str_starts_with((string) ($entry['name'] ?? ''), $catalogPrefix)) {
                continue;
            }

            $catalogAction = trim((string) ($entry['name'] ?? ''));
            $actionKey = substr($catalogAction, strlen($catalogPrefix));
            if ($actionKey === false || $actionKey === '') {
                continue;
            }

            $entries[] = [
                'action_key' => $actionKey,
                'catalog_action' => $catalogAction,
                'skill_name' => $this->resolveSkillNameFromActionEntry($entry),
                'required_role' => strtolower(trim((string) ($entry['required_role'] ?? 'viewer'))) ?: 'viewer',
                'risk_level' => strtolower(trim((string) ($entry['risk_level'] ?? 'low'))) ?: 'low',
            ];
        }

        usort(
            $entries,
            fn(array $left, array $right): int => $this->actionWeight((string) ($left['action_key'] ?? ''))
                <=> $this->actionWeight((string) ($right['action_key'] ?? ''))
                ?: strcmp((string) ($left['action_key'] ?? ''), (string) ($right['action_key'] ?? ''))
        );

        return $entries;
    }

    private function resolveSkillNameFromActionEntry(array $entry): string
    {
        foreach ((array) ($entry['allowed_tools'] ?? []) as $tool) {
            $tool = trim((string) $tool);
            if ($tool === '' || $tool === 'CommandBus') {
                continue;
            }

            return $tool;
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportActionEntries(array $entries): array
    {
        return array_map(
            static fn(array $entry): array => [
                'action_key' => (string) ($entry['action_key'] ?? ''),
                'catalog_action' => (string) ($entry['catalog_action'] ?? ''),
                'skill_name' => (string) ($entry['skill_name'] ?? ''),
                'required_role' => (string) ($entry['required_role'] ?? 'viewer'),
                'risk_level' => (string) ($entry['risk_level'] ?? 'low'),
            ],
            $entries
        );
    }

    /**
     * @return array<int, string>
     */
    private function suggestedNextActions(array $definition): array
    {
        $actions = [];
        foreach ($this->moduleActionEntries($definition) as $entry) {
            $actionKey = trim((string) ($entry['action_key'] ?? ''));
            if ($actionKey !== '') {
                $actions[] = $actionKey;
            }
        }

        return array_slice($actions, 0, 3);
    }

    /**
     * @return array<int, string>
     */
    private function requiredContextForSkill(string $skillName): array
    {
        if ($skillName === '') {
            return [];
        }

        $skill = $this->moduleSkillRegistry()->get($skillName);
        if (!is_array($skill)) {
            return [];
        }

        $required = is_array($skill['input_schema']['required'] ?? null)
            ? (array) $skill['input_schema']['required']
            : [];

        return array_values(array_filter(array_map(
            static fn($item): string => trim((string) $item),
            $required
        ), static fn(string $item): bool => $item !== '' && $item !== 'message_text'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function actionCatalogEntries(): array
    {
        $catalog = $this->contracts->getActionCatalog();

        return is_array($catalog['catalog'] ?? null) ? array_values((array) $catalog['catalog']) : [];
    }

    private function moduleSkillRegistry(): SkillRegistry
    {
        $skillsCatalog = $this->contracts->getSkillsCatalog();
        $skillNames = [];
        foreach ($this->moduleDefinitions() as $definition) {
            foreach ($this->moduleActionEntries($definition) as $entry) {
                $skillName = trim((string) ($entry['skill_name'] ?? ''));
                if ($skillName !== '') {
                    $skillNames[$skillName] = true;
                }
            }
        }

        $filteredCatalog = [];
        foreach ((array) ($skillsCatalog['catalog'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name !== '' && isset($skillNames[$name])) {
                $filteredCatalog[] = $entry;
            }
        }

        return new SkillRegistry($skillsCatalog + ['catalog' => $filteredCatalog]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function moduleDefinitions(): array
    {
        return [
            'media' => [
                'module_key' => 'media',
                'tool_group' => 'media',
                'label' => 'Media/Documents',
                'catalog_prefix' => 'media.',
                'permission_module_key' => 'media',
                'plan_managed' => true,
                'plan_module_key' => 'media',
                'aliases' => ['media', 'documento', 'documentos', 'archivo', 'archivos', 'adjunto', 'adjuntos'],
            ],
            'entity_search' => [
                'module_key' => 'entity_search',
                'tool_group' => 'entity_search',
                'label' => 'Entity Search',
                'catalog_prefix' => 'entity.',
                'permission_module_key' => 'entity',
                'plan_managed' => false,
                'plan_module_key' => '',
                'aliases' => ['entity search', 'busqueda global', 'resolver referencia', 'encontrar registro'],
            ],
            'pos' => [
                'module_key' => 'pos',
                'tool_group' => 'pos',
                'label' => 'POS',
                'catalog_prefix' => 'pos.',
                'permission_module_key' => 'pos',
                'plan_managed' => true,
                'plan_module_key' => 'pos',
                'aliases' => ['pos', 'venta', 'ventas', 'caja', 'ticket', 'recibo'],
            ],
            'purchases' => [
                'module_key' => 'purchases',
                'tool_group' => 'purchases',
                'label' => 'Purchases',
                'catalog_prefix' => 'purchases.',
                'permission_module_key' => 'purchases',
                'plan_managed' => true,
                'plan_module_key' => 'purchases',
                'aliases' => ['compra', 'compras', 'proveedor', 'purchase', 'supplier'],
            ],
            'fiscal' => [
                'module_key' => 'fiscal',
                'tool_group' => 'fiscal',
                'label' => 'Fiscal',
                'catalog_prefix' => 'fiscal.',
                'permission_module_key' => 'fiscal',
                'plan_managed' => true,
                'plan_module_key' => 'fiscal',
                'aliases' => ['fiscal', 'factura electronica', 'factura', 'nota credito', 'soporte fiscal'],
            ],
            'ecommerce' => [
                'module_key' => 'ecommerce',
                'tool_group' => 'ecommerce',
                'label' => 'Ecommerce',
                'catalog_prefix' => 'ecommerce.',
                'permission_module_key' => 'ecommerce',
                'plan_managed' => true,
                'plan_module_key' => 'ecommerce',
                'aliases' => ['ecommerce', 'tienda online', 'tienda', 'pedido externo', 'catalogo externo', 'woocommerce', 'prestashop'],
            ],
            'access_control' => [
                'module_key' => 'access_control',
                'tool_group' => 'access_control',
                'label' => 'Access Control',
                'catalog_prefix' => 'users.',
                'permission_module_key' => 'users',
                'plan_managed' => true,
                'plan_module_key' => 'users',
                'aliases' => ['multiusuario', 'usuario', 'usuarios', 'rol', 'roles', 'permiso', 'permisos', 'acceso'],
            ],
            'saas_plan' => [
                'module_key' => 'saas_plan',
                'tool_group' => 'saas_plan',
                'label' => 'SaaS Plan',
                'catalog_prefix' => 'saas.',
                'permission_module_key' => 'saas',
                'plan_managed' => false,
                'plan_module_key' => '',
                'aliases' => ['plan saas', 'suscripcion', 'pricing', 'precio del plan', 'modulos por plan', 'plan actual'],
            ],
            'usage_metering' => [
                'module_key' => 'usage_metering',
                'tool_group' => 'usage_metering',
                'label' => 'Usage Metering',
                'catalog_prefix' => 'usage.',
                'permission_module_key' => 'usage',
                'plan_managed' => false,
                'plan_module_key' => '',
                'aliases' => ['consumo', 'uso del tenant', 'metrica de uso', 'limite de uso', 'cuota'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireModuleDefinition(string $moduleKey): array
    {
        $normalized = $this->normalizeModuleKey($moduleKey);
        $definition = $this->moduleDefinitions()[$normalized] ?? null;
        if (!is_array($definition)) {
            throw new RuntimeException('AGENT_TOOLS_MODULE_KEY_INVALID');
        }

        return $definition;
    }

    private function normalizeModuleKey(string $moduleKey): string
    {
        $normalized = $this->normalizeText($moduleKey);
        if ($normalized === '') {
            return '';
        }

        $aliases = [
            'media' => 'media',
            'documentos' => 'media',
            'documento' => 'media',
            'archivos' => 'media',
            'archivo' => 'media',
            'entity_search' => 'entity_search',
            'entity search' => 'entity_search',
            'busqueda global' => 'entity_search',
            'resolver referencia' => 'entity_search',
            'pos' => 'pos',
            'ventas' => 'pos',
            'venta' => 'pos',
            'caja' => 'pos',
            'purchases' => 'purchases',
            'compras' => 'purchases',
            'compra' => 'purchases',
            'purchase' => 'purchases',
            'fiscal' => 'fiscal',
            'factura' => 'fiscal',
            'ecommerce' => 'ecommerce',
            'tienda online' => 'ecommerce',
            'woocommerce' => 'ecommerce',
            'prestashop' => 'ecommerce',
            'access_control' => 'access_control',
            'usuarios' => 'access_control',
            'usuario' => 'access_control',
            'roles' => 'access_control',
            'rol' => 'access_control',
            'permisos' => 'access_control',
            'permiso' => 'access_control',
            'multiusuario' => 'access_control',
            'saas_plan' => 'saas_plan',
            'plan saas' => 'saas_plan',
            'suscripcion' => 'saas_plan',
            'pricing' => 'saas_plan',
            'usage_metering' => 'usage_metering',
            'usage' => 'usage_metering',
            'consumo' => 'usage_metering',
            'uso del tenant' => 'usage_metering',
            'limite de uso' => 'usage_metering',
        ];

        return $aliases[$normalized] ?? (array_key_exists($normalized, $this->moduleDefinitions()) ? $normalized : '');
    }

    private function resolveFallbackRole(string $userId, array $options): string
    {
        $role = strtolower(trim((string) ($options['fallback_role'] ?? $options['role'] ?? '')));
        if ($role !== '') {
            return $role;
        }

        $user = $this->projectRegistry->getUser($userId);
        if (is_array($user)) {
            $role = strtolower(trim((string) ($user['role'] ?? '')));
        }

        return $role !== '' ? $role : 'viewer';
    }

    private function actionWeight(string $actionKey): int
    {
        $actionKey = strtolower(trim($actionKey));
        return match (true) {
            str_starts_with($actionKey, 'list_'),
            str_starts_with($actionKey, 'get_'),
            str_starts_with($actionKey, 'search'),
            str_starts_with($actionKey, 'resolve'),
            str_starts_with($actionKey, 'check_'),
            str_starts_with($actionKey, 'validate'),
            str_starts_with($actionKey, 'ping') => 1,
            str_starts_with($actionKey, 'build_'),
            str_starts_with($actionKey, 'prepare_') => 2,
            str_starts_with($actionKey, 'create_'),
            str_starts_with($actionKey, 'link_'),
            str_starts_with($actionKey, 'register_') => 3,
            str_starts_with($actionKey, 'update_'),
            str_starts_with($actionKey, 'mark_') => 4,
            default => 5,
        };
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function requireString(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
