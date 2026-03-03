<?php
// app/Core/RouterPolicyEvaluator.php

declare(strict_types=1);

namespace App\Core;

final class RouterPolicyEvaluator
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $routerPolicy
     * @param array<string, mixed>|null $actionCatalogEntry
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function evaluate(
        array $input,
        array $routerPolicy,
        ?array $actionCatalogEntry,
        array $context,
        string $enforcementMode
    ): array {
        $mode = $this->normalizeMode($enforcementMode);
        $action = trim((string) ($input['action'] ?? 'respond_local'));
        $command = is_array($input['command'] ?? null) ? (array) $input['command'] : [];
        $routeOrder = $this->normalizeRouteOrder($input['route_order'] ?? []);
        $routePathSteps = $this->resolveRoutePathSteps($action, $routeOrder);
        $routePath = implode('>', $routePathSteps);

        $intentType = $this->resolveIntentType($action, $actionCatalogEntry);
        $catalogAction = trim((string) ($input['catalog_action_name'] ?? ''));
        $allowlisted = (bool) ($input['allowlisted'] ?? false);
        $preViolations = array_values(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            is_array($input['pre_violations'] ?? null) ? (array) $input['pre_violations'] : []
        ), static fn(string $value): bool => $value !== ''));

        $gatesRequired = $this->normalizeGateNames($actionCatalogEntry['gates_required'] ?? []);
        $schemaRequired = (bool) (($actionCatalogEntry['json_schema_required'] ?? false) === true);

        $gateResults = [];
        $violations = $preViolations;

        $allowlistGateRequired = $action === 'execute_command';
        $allowlistGate = $this->gateResult(
            'allowlist_gate',
            $allowlistGateRequired,
            !$allowlistGateRequired || $allowlisted,
            $allowlistGateRequired
                ? ($allowlisted ? 'allowlisted' : 'action_not_allowlisted:' . $catalogAction)
                : 'not_applicable'
        );
        $gateResults[] = $allowlistGate;
        if ($allowlistGateRequired && !$allowlistGate['passed']) {
            $violations[] = (string) $allowlistGate['reason'];
        }

        $schemaGateRequired = $action === 'execute_command' && ($schemaRequired || in_array('schema_guard', $gatesRequired, true));
        [$schemaPassed, $schemaReason] = $this->validateCommandSchema($command);
        $schemaGate = $this->gateResult('schema_gate', $schemaGateRequired, !$schemaGateRequired || $schemaPassed, $schemaReason);
        $gateResults[] = $schemaGate;
        if ($schemaGateRequired && !$schemaGate['passed']) {
            $violations[] = 'gate_schema_invalid:' . $schemaReason;
        }

        $authGateRequired = $action === 'execute_command'
            && ($mode !== 'off' || in_array('role_guard', $gatesRequired, true) || in_array('auth_guard', $gatesRequired, true));
        [$authPassed, $authReason] = $this->validateAuthRbac($context, $actionCatalogEntry);
        $authGate = $this->gateResult('auth_rbac_gate', $authGateRequired, !$authGateRequired || $authPassed, $authReason);
        $gateResults[] = $authGate;
        if ($authGateRequired && !$authGate['passed']) {
            $violations[] = 'gate_auth_rbac_failed:' . $authReason;
        }

        $tenantGateRequired = $action === 'execute_command'
            && ($mode !== 'off' || in_array('tenant_scope_guard', $gatesRequired, true));
        [$tenantPassed, $tenantReason, $resolvedTenant] = $this->validateTenantScope($context, $command);
        $tenantGate = $this->gateResult('tenant_scope_gate', $tenantGateRequired, !$tenantGateRequired || $tenantPassed, $tenantReason);
        $gateResults[] = $tenantGate;
        if ($tenantGateRequired && !$tenantGate['passed']) {
            $violations[] = 'gate_tenant_scope_failed:' . $tenantReason;
        }

        $modeGateRequired = $action === 'execute_command' && in_array('mode_guard', $gatesRequired, true);
        [$modePassed, $modeReason] = $this->validateMode($context);
        $modeGate = $this->gateResult('mode_guard_gate', $modeGateRequired, !$modeGateRequired || $modePassed, $modeReason);
        $gateResults[] = $modeGate;
        if ($modeGateRequired && !$modeGate['passed']) {
            $violations[] = 'gate_mode_failed:' . $modeReason;
        }

        // Track declarative gates listed in contract, even if runtime execution is delegated.
        foreach ($gatesRequired as $gateName) {
            if ($this->hasGateResult($gateResults, $gateName)) {
                continue;
            }
            $gateResults[] = $this->gateResult($gateName, true, true, 'deferred_to_execution_engine');
        }

        $evidence = $this->evaluateEvidence(
            $routerPolicy,
            $intentType,
            $actionCatalogEntry,
            $routePathSteps,
            $context,
            $input,
            $schemaGate['passed'],
            $authGate['passed'],
            $modeGate['passed']
        );
        $missingEvidence = is_array($evidence['missing'] ?? null) ? (array) $evidence['missing'] : [];
        foreach ($missingEvidence as $missing) {
            $violations[] = 'minimum_evidence_missing:' . (string) $missing;
        }

        $violations = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $violations
        ), static fn(string $value): bool => $value !== '')));

        $missingEvidenceAction = $this->resolveMissingEvidenceAction($routerPolicy);
        $onlyMissingEvidence = $this->containsOnlyMissingEvidenceViolations($violations);
        $hasViolations = !empty($violations);

        $gateDecision = 'off';
        $finalDecision = 'allow';
        $actionOverride = '';
        $replyOverride = '';

        if ($mode === 'off') {
            $gateDecision = 'off';
            $finalDecision = 'allow';
        } elseif (!$hasViolations) {
            $gateDecision = 'allow';
            $finalDecision = 'allow';
        } elseif ($mode === 'warn') {
            $gateDecision = 'warn';
            $finalDecision = 'warn';
            if ($onlyMissingEvidence) {
                if ($missingEvidenceAction === 'ASK') {
                    $finalDecision = 'ask';
                    $actionOverride = 'ask_user';
                    $replyOverride = 'Falta evidencia minima para continuar. Comparte un dato critico verificable para seguir.';
                } elseif ($missingEvidenceAction === 'SAFE_RESPONSE') {
                    $finalDecision = 'safe_response';
                    $actionOverride = 'respond_local';
                    $replyOverride = 'No hay evidencia minima suficiente. Te doy una respuesta segura sin ejecutar acciones.';
                }
            }
        } else {
            $gateDecision = 'blocked';
            $finalDecision = 'blocked';
            if ($this->containsMissingEvidenceViolations($violations)) {
                if ($missingEvidenceAction === 'ASK') {
                    $actionOverride = 'ask_user';
                    $replyOverride = 'Bloqueado por contrato (strict): falta evidencia minima verificable.';
                } elseif ($missingEvidenceAction === 'SAFE_RESPONSE') {
                    $actionOverride = 'respond_local';
                    $replyOverride = 'Bloqueado por contrato (strict): no hay evidencia minima para ejecutar.';
                }
            }
        }

        $resolveCriteria = $this->resolveCriteriaCode($action, $hasViolations, $finalDecision);

        return [
            'route_path' => $routePath,
            'route_path_steps' => $routePathSteps,
            'intent_type' => $intentType,
            'evidence_status' => $evidence,
            'evidence_used' => [
                'tenant_id' => $resolvedTenant,
                'source_ids' => $evidence['source_ids'] ?? [],
                'sources_used' => $evidence['sources_used'] ?? [],
            ],
            'gate_results' => $gateResults,
            'violations' => $violations,
            'gate_decision' => $gateDecision,
            'final_decision' => $finalDecision,
            'action_override' => $actionOverride,
            'reply_override' => $replyOverride,
            'resolve_criteria_code' => $resolveCriteria,
            'missing_evidence_action' => $missingEvidenceAction,
        ];
    }

    private function normalizeMode(string $mode): string
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
        foreach ($value as $stage) {
            $stage = strtolower(trim((string) $stage));
            if ($stage === '') {
                continue;
            }
            if (!in_array($stage, $result, true)) {
                $result[] = $stage;
            }
        }
        return empty($result) ? ['cache', 'rules', 'rag', 'llm'] : $result;
    }

    /**
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

        return empty($path) ? ['cache', 'rules'] : $path;
    }

    /**
     * @param array<string, mixed>|null $actionCatalogEntry
     */
    private function resolveIntentType(string $action, ?array $actionCatalogEntry): string
    {
        if (is_array($actionCatalogEntry)) {
            $type = strtoupper(trim((string) ($actionCatalogEntry['type'] ?? '')));
            if (in_array($type, ['EXECUTABLE', 'INFORMATIVE', 'FORBIDDEN'], true)) {
                return $type;
            }
        }

        if ($action === 'execute_command') {
            return 'EXECUTABLE';
        }
        if ($action === 'error') {
            return 'FORBIDDEN';
        }
        return 'INFORMATIVE';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeGateNames(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === '') {
                continue;
            }
            if (!in_array($name, $result, true)) {
                $result[] = $name;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $command
     * @return array{0: bool, 1: string}
     */
    private function validateCommandSchema(array $command): array
    {
        $commandName = trim((string) ($command['command'] ?? ''));
        if ($commandName === '') {
            return [false, 'missing_command_name'];
        }

        $entity = trim((string) ($command['entity'] ?? ''));
        $id = $command['id'] ?? null;
        $data = $command['data'] ?? null;

        switch ($commandName) {
            case 'CreateRecord':
                if ($entity === '') {
                    return [false, 'missing_entity'];
                }
                if (!is_array($data)) {
                    return [false, 'missing_or_invalid_data'];
                }
                return [true, 'schema_valid'];

            case 'UpdateRecord':
                if ($entity === '') {
                    return [false, 'missing_entity'];
                }
                if ($id === null || trim((string) $id) === '') {
                    return [false, 'missing_id'];
                }
                if (!is_array($data)) {
                    return [false, 'missing_or_invalid_data'];
                }
                return [true, 'schema_valid'];

            case 'DeleteRecord':
            case 'ReadRecord':
                if ($entity === '') {
                    return [false, 'missing_entity'];
                }
                if ($id === null || trim((string) $id) === '') {
                    return [false, 'missing_id'];
                }
                return [true, 'schema_valid'];

            case 'QueryRecords':
                if ($entity === '') {
                    return [false, 'missing_entity'];
                }
                if (array_key_exists('filters', $command) && !is_array($command['filters'])) {
                    return [false, 'invalid_filters'];
                }
                return [true, 'schema_valid'];

            default:
                // Backward-compatible fallback for commands not yet fully modeled.
                if ($entity === '' && empty($data) && $id === null) {
                    return [false, 'insufficient_payload'];
                }
                return [true, 'schema_valid_legacy'];
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $actionCatalogEntry
     * @return array{0: bool, 1: string}
     */
    private function validateAuthRbac(array $context, ?array $actionCatalogEntry): array
    {
        $requiredRole = strtolower(trim((string) (($actionCatalogEntry['required_role'] ?? '') ?: '')));
        if ($requiredRole === '') {
            return [true, 'role_not_required'];
        }

        $isAuthenticated = array_key_exists('is_authenticated', $context)
            ? (bool) $context['is_authenticated']
            : true;
        if (!$isAuthenticated) {
            return [false, 'not_authenticated'];
        }

        $role = strtolower(trim((string) ($context['role'] ?? getenv('DEFAULT_ROLE') ?: 'admin')));
        if ($role === '') {
            $role = 'admin';
        }
        if ($role === 'admin' || $role === $requiredRole) {
            return [true, 'role_allowed'];
        }

        return [false, 'required_role:' . $requiredRole . ',actual_role:' . $role];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $command
     * @return array{0: bool, 1: string, 2: string}
     */
    private function validateTenantScope(array $context, array $command): array
    {
        $contextTenant = trim((string) ($context['tenant_id'] ?? ''));
        $commandTenant = trim((string) ($command['tenant_id'] ?? ''));
        $envTenant = trim((string) (getenv('TENANT_KEY') ?: getenv('TENANT_ID') ?: ''));

        $resolved = $contextTenant !== '' ? $contextTenant : ($commandTenant !== '' ? $commandTenant : $envTenant);
        if ($resolved === '') {
            $resolved = 'default';
        }

        if ($contextTenant !== '' && $commandTenant !== '' && $contextTenant !== $commandTenant) {
            return [false, 'tenant_mismatch', $resolved];
        }

        return [true, 'tenant_scope_ok', $resolved];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{0: bool, 1: string}
     */
    private function validateMode(array $context): array
    {
        $mode = strtolower(trim((string) ($context['mode'] ?? 'app')));
        if ($mode === '') {
            $mode = 'app';
        }
        if ($mode === 'builder') {
            return [false, 'mode_builder_disallows_runtime_execution'];
        }
        return [true, 'mode_ok'];
    }

    /**
     * @param array<string, mixed> $routerPolicy
     * @param array<string, mixed>|null $actionCatalogEntry
     * @param array<int, string> $routePathSteps
     * @param array<string, mixed> $context
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function evaluateEvidence(
        array $routerPolicy,
        string $intentType,
        ?array $actionCatalogEntry,
        array $routePathSteps,
        array $context,
        array $input,
        bool $schemaPass,
        bool $authPass,
        bool $modePass
    ): array {
        $required = [];
        $policyMinimum = is_array($routerPolicy['minimum_evidence'] ?? null) ? (array) $routerPolicy['minimum_evidence'] : [];
        $intentPolicy = is_array($policyMinimum[$intentType] ?? null) ? (array) $policyMinimum[$intentType] : [];
        $requiredRaw = is_array($intentPolicy['required'] ?? null) ? (array) $intentPolicy['required'] : [];
        foreach ($requiredRaw as $item) {
            $value = trim((string) $item);
            if ($value !== '' && !in_array($value, $required, true)) {
                $required[] = $value;
            }
        }

        $sourceIds = [];
        foreach (['evidence_ids', 'source_ids'] as $key) {
            $list = is_array($context[$key] ?? null) ? (array) $context[$key] : [];
            foreach ($list as $id) {
                $id = trim((string) $id);
                if ($id !== '' && !in_array($id, $sourceIds, true)) {
                    $sourceIds[] = $id;
                }
            }
        }

        $sourcesUsed = [];
        foreach (['cache_hit' => 'cache', 'rules_hit' => 'rules', 'rag_hit' => 'rag'] as $flag => $name) {
            if ((bool) ($context[$flag] ?? false)) {
                $sourcesUsed[] = $name;
            }
        }

        $present = [];
        if (is_array($actionCatalogEntry)) {
            $present[] = 'contract_or_rule_reference';
        }
        if ($schemaPass) {
            $present[] = 'schema_validation_pass';
        }
        if ($authPass && $modePass) {
            $present[] = 'mode_and_role_guard_pass';
        }
        if (!empty($sourceIds) || !empty($sourcesUsed)) {
            $present[] = 'at_least_one_source_reference';
        }

        $inputEvidence = is_array($input['evidence'] ?? null) ? (array) $input['evidence'] : [];
        foreach ($inputEvidence as $evidenceName) {
            $evidenceName = trim((string) $evidenceName);
            if ($evidenceName !== '' && !in_array($evidenceName, $present, true)) {
                $present[] = $evidenceName;
            }
        }

        if ($intentType === 'FORBIDDEN') {
            $present[] = 'policy_or_guard_reference';
        }

        $present = array_values(array_unique($present));
        $missing = [];
        foreach ($required as $item) {
            if (!in_array($item, $present, true)) {
                $missing[] = $item;
            }
        }

        return [
            'intent_type' => $intentType,
            'required' => $required,
            'present' => $present,
            'missing' => $missing,
            'route_path_steps' => $routePathSteps,
            'sources_used' => $sourcesUsed,
            'source_ids' => $sourceIds,
        ];
    }

    /**
     * @param array<string, mixed> $routerPolicy
     */
    private function resolveMissingEvidenceAction(array $routerPolicy): string
    {
        $actions = is_array($routerPolicy['missing_evidence_actions'] ?? null)
            ? (array) $routerPolicy['missing_evidence_actions']
            : [];
        $defaultAction = strtoupper(trim((string) ($actions['default_action'] ?? 'ASK')));
        return in_array($defaultAction, ['ASK', 'SAFE_RESPONSE'], true) ? $defaultAction : 'ASK';
    }

    /**
     * @param array<int, string> $violations
     */
    private function containsMissingEvidenceViolations(array $violations): bool
    {
        foreach ($violations as $violation) {
            if (str_starts_with($violation, 'minimum_evidence_missing:')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, string> $violations
     */
    private function containsOnlyMissingEvidenceViolations(array $violations): bool
    {
        if (empty($violations)) {
            return false;
        }
        foreach ($violations as $violation) {
            if (!str_starts_with($violation, 'minimum_evidence_missing:')) {
                return false;
            }
        }
        return true;
    }

    private function resolveCriteriaCode(string $action, bool $hasViolations, string $finalDecision): string
    {
        if ($hasViolations || in_array($finalDecision, ['blocked', 'safe_response'], true)) {
            return 'FORBIDDEN_RESPONSE_VALID';
        }
        if ($action === 'execute_command') {
            return 'EXECUTION_PLAN_VALID';
        }
        return 'INFORMATIVE_RESPONSE_VALID';
    }

    /**
     * @param array<int, array{name:string,required:bool,passed:bool,reason:string}> $gateResults
     */
    private function hasGateResult(array $gateResults, string $gateName): bool
    {
        $aliases = [
            'schema_guard' => 'schema_gate',
            'role_guard' => 'auth_rbac_gate',
            'auth_guard' => 'auth_rbac_gate',
            'tenant_scope_guard' => 'tenant_scope_gate',
            'mode_guard' => 'mode_guard_gate',
            'allowlist_guard' => 'allowlist_gate',
        ];
        $matchName = $aliases[$gateName] ?? $gateName;
        foreach ($gateResults as $row) {
            if ((string) ($row['name'] ?? '') === $matchName) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{name:string,required:bool,passed:bool,reason:string}
     */
    private function gateResult(string $name, bool $required, bool $passed, string $reason): array
    {
        return [
            'name' => $name,
            'required' => $required,
            'passed' => $passed,
            'reason' => $reason,
        ];
    }
}
