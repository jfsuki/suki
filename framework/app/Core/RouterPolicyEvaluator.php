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
        $routePathSteps = $this->normalizeProvidedRoutePathSteps($input['route_path_steps'] ?? null);
        if ($routePathSteps === []) {
            $routePathSteps = $this->resolveRoutePathSteps($action, $routeOrder);
        }
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

        $builderModeGateRequired = $action === 'execute_command' && in_array('builder_mode_guard', $gatesRequired, true);
        [$builderModePassed, $builderModeReason] = $this->validateBuilderMode($context);
        $builderModeGate = $this->gateResult(
            'builder_mode_gate',
            $builderModeGateRequired,
            !$builderModeGateRequired || $builderModePassed,
            $builderModeReason
        );
        $gateResults[] = $builderModeGate;
        if ($builderModeGateRequired && !$builderModeGate['passed']) {
            $violations[] = 'gate_builder_mode_failed:' . $builderModeReason;
        }

        // Track declarative gates listed in contract.
        // P0 hard gates are never deferred; missing implementation is treated as violation.
        foreach ($gatesRequired as $gateName) {
            if ($this->hasGateResult($gateResults, $gateName)) {
                continue;
            }
            $canonicalGate = $this->canonicalGateName($gateName);
            if (in_array($canonicalGate, $this->hardGateNames(), true)) {
                $gateResults[] = $this->gateResult($canonicalGate, true, false, 'hard_gate_not_implemented');
                $violations[] = 'hard_gate_not_implemented:' . $canonicalGate;
                continue;
            }
            $gateResults[] = $this->gateResult($canonicalGate, true, true, 'deferred_to_execution_engine');
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
            $modeGate['passed'],
            (bool) ($input['skip_evidence_gate'] ?? false),
            trim((string) ($input['evidence_gate_status'] ?? ''))
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
        $hardGateFailures = $this->collectHardGateFailures($gateResults, $action);
        if (!empty($hardGateFailures)) {
            foreach ($hardGateFailures as $hardFailure) {
                $tag = 'hard_gate_failed:' . $hardFailure;
                if (!in_array($tag, $violations, true)) {
                    $violations[] = $tag;
                }
            }
            $hasViolations = true;
        }

        $gateDecision = 'off';
        $finalDecision = 'allow';
        $actionOverride = '';
        $replyOverride = '';

        if (!empty($hardGateFailures)) {
            $gateDecision = 'blocked';
            $finalDecision = 'blocked';
            $actionOverride = 'respond_local';
            $replyOverride = 'Acceso no autorizado para este recurso.';
        } elseif ($mode === 'off') {
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
            return ['cache', 'rules', 'skills', 'rag', 'llm'];
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
        return empty($result) ? ['cache', 'rules', 'skills', 'rag', 'llm'] : $result;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeProvidedRoutePathSteps(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowed = ['cache', 'rules', 'skills', 'rag', 'llm', 'action_contract'];
        $result = [];
        foreach ($value as $stage) {
            $stage = strtolower(trim((string) $stage));
            if ($stage === '' || !in_array($stage, $allowed, true) || in_array($stage, $result, true)) {
                continue;
            }
            $result[] = $stage;
        }

        return $result;
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

            case 'CreateTask':
                $title = trim((string) ($command['title'] ?? ''));
                if ($title === '') {
                    return [false, 'missing_title'];
                }
                return [true, 'schema_valid'];

            case 'ListPendingTasks':
                if (array_key_exists('statuses', $command) && !is_array($command['statuses'])) {
                    return [false, 'invalid_statuses'];
                }
                return [true, 'schema_valid'];

            case 'UpdateTaskStatus':
                if (($command['id'] ?? null) === null || trim((string) ($command['id'] ?? '')) === '') {
                    return [false, 'missing_id'];
                }
                if (trim((string) ($command['status'] ?? '')) === '') {
                    return [false, 'missing_status'];
                }
                return [true, 'schema_valid'];

            case 'CreateReminder':
                if (trim((string) ($command['title'] ?? '')) === '') {
                    return [false, 'missing_title'];
                }
                if (trim((string) ($command['remind_at'] ?? '')) === '') {
                    return [false, 'missing_remind_at'];
                }
                return [true, 'schema_valid'];

            case 'ListReminders':
                if (array_key_exists('statuses', $command) && !is_array($command['statuses'])) {
                    return [false, 'invalid_statuses'];
                }
                return [true, 'schema_valid'];

            case 'UpdateReminderStatus':
                if (($command['id'] ?? null) === null || trim((string) ($command['id'] ?? '')) === '') {
                    return [false, 'missing_id'];
                }
                if (trim((string) ($command['status'] ?? '')) === '') {
                    return [false, 'missing_status'];
                }
                return [true, 'schema_valid'];

            case 'CreateAlert':
                if (trim((string) ($command['title'] ?? '')) === '') {
                    return [false, 'missing_title'];
                }
                if (trim((string) ($command['alert_type'] ?? '')) === '') {
                    return [false, 'missing_alert_type'];
                }
                return [true, 'schema_valid'];

            case 'ListAlerts':
                if (array_key_exists('statuses', $command) && !is_array($command['statuses'])) {
                    return [false, 'invalid_statuses'];
                }
                return [true, 'schema_valid'];

            case 'UpdateAlertStatus':
                if (($command['id'] ?? null) === null || trim((string) ($command['id'] ?? '')) === '') {
                    return [false, 'missing_id'];
                }
                if (trim((string) ($command['status'] ?? '')) === '') {
                    return [false, 'missing_status'];
                }
                return [true, 'schema_valid'];

            case 'FetchPendingOperationalItems':
                return [true, 'schema_valid'];

            case 'UploadMedia':
                if (trim((string) ($command['entity_type'] ?? '')) === '') {
                    return [false, 'missing_entity_type'];
                }
                if (trim((string) ($command['entity_id'] ?? '')) === '') {
                    return [false, 'missing_entity_id'];
                }
                $hasSource = trim((string) ($command['source_path'] ?? '')) !== ''
                    || is_array($command['file'] ?? null);
                return $hasSource ? [true, 'schema_valid'] : [false, 'missing_source_file'];

            case 'ListMedia':
                if (trim((string) ($command['entity_type'] ?? '')) === '') {
                    return [false, 'missing_entity_type'];
                }
                if (trim((string) ($command['entity_id'] ?? '')) === '') {
                    return [false, 'missing_entity_id'];
                }
                return [true, 'schema_valid'];

            case 'GetMedia':
            case 'DeleteMedia':
            case 'GenerateMediaThumbnail':
                if (trim((string) ($command['media_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_media_id'];
                }
                return [true, 'schema_valid'];

            case 'SearchEntities':
            case 'ResolveEntityReference':
                $query = trim((string) ($command['query'] ?? ''));
                $filters = $command['filters'] ?? null;
                if ($query === '' && !is_array($filters)) {
                    return [false, 'missing_query'];
                }
                if ($filters !== null && !is_array($filters)) {
                    return [false, 'invalid_filters'];
                }
                return [true, 'schema_valid'];

            case 'GetEntityByReference':
                if (trim((string) ($command['entity_type'] ?? '')) === '') {
                    return [false, 'missing_entity_type'];
                }
                if (trim((string) ($command['entity_id'] ?? '')) === '') {
                    return [false, 'missing_entity_id'];
                }
                if (array_key_exists('filters', $command) && !is_array($command['filters'])) {
                    return [false, 'invalid_filters'];
                }
                return [true, 'schema_valid'];

            case 'CreatePOSDraft':
                $tenantId = trim((string) ($command['tenant_id'] ?? ''));
                if ($tenantId === '') {
                    return [false, 'missing_tenant_id'];
                }
                if (array_key_exists('metadata', $command) && !is_array($command['metadata'])) {
                    return [false, 'invalid_metadata'];
                }
                return [true, 'schema_valid'];

            case 'GetPOSDraft':
                if (trim((string) ($command['draft_id'] ?? $command['sale_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                return [true, 'schema_valid'];

            case 'AddPOSDraftLine':
                if (trim((string) ($command['draft_id'] ?? $command['sale_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                $productId = trim((string) ($command['product_id'] ?? ''));
                $query = trim((string) ($command['query'] ?? ''));
                $sku = trim((string) ($command['sku'] ?? ''));
                $barcode = trim((string) ($command['barcode'] ?? ''));
                if ($productId === '' && $query === '' && $sku === '' && $barcode === '') {
                    return [false, 'missing_product_reference'];
                }
                if (array_key_exists('qty', $command) && (!is_numeric($command['qty']) || (float) $command['qty'] <= 0)) {
                    return [false, 'invalid_qty'];
                }
                return [true, 'schema_valid'];

            case 'AddPOSLineByReference':
                if (trim((string) ($command['draft_id'] ?? $command['sale_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                $productId = trim((string) ($command['product_id'] ?? ''));
                $query = trim((string) ($command['query'] ?? ''));
                $sku = trim((string) ($command['sku'] ?? ''));
                $barcode = trim((string) ($command['barcode'] ?? ''));
                if ($productId === '' && $query === '' && $sku === '' && $barcode === '') {
                    return [false, 'missing_product_reference'];
                }
                if (array_key_exists('qty', $command) && (!is_numeric($command['qty']) || (float) $command['qty'] <= 0)) {
                    return [false, 'invalid_qty'];
                }
                if (array_key_exists('override_price', $command) && $command['override_price'] !== null && $command['override_price'] !== '' && !is_numeric($command['override_price'])) {
                    return [false, 'invalid_override_price'];
                }
                return [true, 'schema_valid'];

            case 'RemovePOSDraftLine':
                if (trim((string) ($command['draft_id'] ?? $command['sale_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                if (trim((string) ($command['line_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_line_id'];
                }
                return [true, 'schema_valid'];

            case 'AttachPOSDraftCustomer':
                if (trim((string) ($command['draft_id'] ?? $command['sale_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                $customerId = trim((string) ($command['customer_id'] ?? ''));
                $query = trim((string) ($command['query'] ?? ''));
                if ($customerId === '' && $query === '') {
                    return [false, 'missing_customer_reference'];
                }
                return [true, 'schema_valid'];

            case 'ListPOSOpenDrafts':
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                return [true, 'schema_valid'];

            case 'FindPOSProduct':
                $productId = trim((string) ($command['product_id'] ?? ''));
                $query = trim((string) ($command['query'] ?? ''));
                $sku = trim((string) ($command['sku'] ?? ''));
                $barcode = trim((string) ($command['barcode'] ?? ''));
                if ($productId === '' && $query === '' && $sku === '' && $barcode === '') {
                    return [false, 'missing_product_reference'];
                }
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                return [true, 'schema_valid'];

            case 'GetPOSProductCandidates':
                $query = trim((string) ($command['query'] ?? ''));
                $sku = trim((string) ($command['sku'] ?? ''));
                $barcode = trim((string) ($command['barcode'] ?? ''));
                if ($query === '' && $sku === '' && $barcode === '') {
                    return [false, 'missing_product_reference'];
                }
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                return [true, 'schema_valid'];

            case 'RepricePOSDraft':
                if (trim((string) ($command['draft_id'] ?? $command['sale_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                $lineId = trim((string) ($command['line_id'] ?? $command['id'] ?? ''));
                $hasMutation = array_key_exists('qty', $command)
                    || array_key_exists('quantity', $command)
                    || array_key_exists('override_price', $command)
                    || array_key_exists('tax_rate', $command)
                    || array_key_exists('base_price', $command);
                if ($hasMutation && $lineId === '') {
                    return [false, 'missing_line_id'];
                }
                if (array_key_exists('qty', $command) && $command['qty'] !== null && $command['qty'] !== '' && (!is_numeric($command['qty']) || (float) $command['qty'] <= 0)) {
                    return [false, 'invalid_qty'];
                }
                if (array_key_exists('quantity', $command) && $command['quantity'] !== null && $command['quantity'] !== '' && (!is_numeric($command['quantity']) || (float) $command['quantity'] <= 0)) {
                    return [false, 'invalid_qty'];
                }
                if (array_key_exists('override_price', $command) && $command['override_price'] !== null && $command['override_price'] !== '' && !is_numeric($command['override_price'])) {
                    return [false, 'invalid_override_price'];
                }
                if (array_key_exists('tax_rate', $command) && $command['tax_rate'] !== null && $command['tax_rate'] !== '' && !is_numeric($command['tax_rate'])) {
                    return [false, 'invalid_tax_rate'];
                }
                if (array_key_exists('base_price', $command) && $command['base_price'] !== null && $command['base_price'] !== '' && !is_numeric($command['base_price'])) {
                    return [false, 'invalid_base_price'];
                }
                return [true, 'schema_valid'];

            case 'FinalizePOSSale':
                if (trim((string) ($command['draft_id'] ?? $command['sale_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                return [true, 'schema_valid'];

            case 'GetPOSSale':
                if (trim((string) ($command['sale_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_sale_id'];
                }
                return [true, 'schema_valid'];

            case 'ListPOSSales':
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['date_from', 'date_to'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'BuildPOSReceipt':
                $saleId = trim((string) ($command['sale_id'] ?? $command['id'] ?? ''));
                $saleNumber = trim((string) ($command['sale_number'] ?? $command['number'] ?? ''));
                if ($saleId === '' && $saleNumber === '') {
                    return [false, 'missing_sale_reference'];
                }
                return [true, 'schema_valid'];

            case 'GetPOSSaleByNumber':
                if (trim((string) ($command['sale_number'] ?? $command['number'] ?? '')) === '') {
                    return [false, 'missing_sale_number'];
                }
                return [true, 'schema_valid'];

            case 'CancelPOSSale':
                $saleId = trim((string) ($command['sale_id'] ?? $command['id'] ?? ''));
                $saleNumber = trim((string) ($command['sale_number'] ?? $command['number'] ?? ''));
                if ($saleId === '' && $saleNumber === '') {
                    return [false, 'missing_sale_reference'];
                }
                if (array_key_exists('reason', $command) && $command['reason'] !== null && $command['reason'] !== '' && !is_string($command['reason'])) {
                    return [false, 'invalid_reason'];
                }
                return [true, 'schema_valid'];

            case 'CreatePOSReturn':
                $saleId = trim((string) ($command['sale_id'] ?? $command['id'] ?? ''));
                $saleNumber = trim((string) ($command['sale_number'] ?? $command['number'] ?? ''));
                if ($saleId === '' && $saleNumber === '') {
                    return [false, 'missing_sale_reference'];
                }
                if (array_key_exists('qty', $command) && $command['qty'] !== null && $command['qty'] !== '' && (!is_numeric($command['qty']) || (float) $command['qty'] <= 0)) {
                    return [false, 'invalid_qty'];
                }
                foreach (['reason', 'sale_line_id', 'line_id', 'product_id'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'GetPOSReturn':
                if (trim((string) ($command['return_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_return_id'];
                }
                return [true, 'schema_valid'];

            case 'ListPOSReturns':
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['status', 'sale_id', 'return_number', 'date_from', 'date_to'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'BuildPOSReturnReceipt':
                if (trim((string) ($command['return_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_return_id'];
                }
                return [true, 'schema_valid'];

            case 'OpenPOSCashRegister':
                if (trim((string) ($command['cash_register_id'] ?? '')) === '') {
                    return [false, 'missing_cash_register_id'];
                }
                if (!array_key_exists('opening_amount', $command) || !is_numeric($command['opening_amount']) || (float) $command['opening_amount'] < 0) {
                    return [false, 'invalid_opening_amount'];
                }
                return [true, 'schema_valid'];

            case 'GetPOSOpenCashSession':
                if (trim((string) ($command['cash_register_id'] ?? '')) === '') {
                    return [false, 'missing_cash_register_id'];
                }
                return [true, 'schema_valid'];

            case 'ClosePOSCashRegister':
                if (trim((string) ($command['session_id'] ?? '')) === '') {
                    return [false, 'missing_session_id'];
                }
                if (!array_key_exists('counted_cash_amount', $command) || !is_numeric($command['counted_cash_amount']) || (float) $command['counted_cash_amount'] < 0) {
                    return [false, 'invalid_counted_cash_amount'];
                }
                return [true, 'schema_valid'];

            case 'BuildPOSCashSummary':
                if (trim((string) ($command['session_id'] ?? '')) === '') {
                    return [false, 'missing_session_id'];
                }
                return [true, 'schema_valid'];

            case 'ListPOSCashSessions':
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['status', 'cash_register_id', 'date_from', 'date_to'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'CreatePurchaseDraft':
                if (trim((string) ($command['tenant_id'] ?? '')) === '') {
                    return [false, 'missing_tenant_id'];
                }
                if (array_key_exists('metadata', $command) && !is_array($command['metadata'])) {
                    return [false, 'invalid_metadata'];
                }
                return [true, 'schema_valid'];

            case 'GetPurchaseDraft':
                if (trim((string) ($command['draft_id'] ?? $command['purchase_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                return [true, 'schema_valid'];

            case 'AddPurchaseDraftLine':
                if (trim((string) ($command['draft_id'] ?? $command['purchase_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                $productId = trim((string) ($command['product_id'] ?? ''));
                $query = trim((string) ($command['query'] ?? ''));
                $label = trim((string) ($command['product_label'] ?? ''));
                $sku = trim((string) ($command['sku'] ?? ''));
                $supplierSku = trim((string) ($command['supplier_sku'] ?? ''));
                if ($productId === '' && $query === '' && $label === '' && $sku === '' && $supplierSku === '') {
                    return [false, 'missing_product_reference'];
                }
                if (!array_key_exists('unit_cost', $command) || !is_numeric($command['unit_cost'])) {
                    return [false, 'invalid_unit_cost'];
                }
                if (array_key_exists('qty', $command) && (!is_numeric($command['qty']) || (float) $command['qty'] <= 0)) {
                    return [false, 'invalid_qty'];
                }
                if (array_key_exists('tax_rate', $command) && $command['tax_rate'] !== null && $command['tax_rate'] !== '' && !is_numeric($command['tax_rate'])) {
                    return [false, 'invalid_tax_rate'];
                }
                return [true, 'schema_valid'];

            case 'RemovePurchaseDraftLine':
                if (trim((string) ($command['draft_id'] ?? $command['purchase_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                if (trim((string) ($command['line_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_line_id'];
                }
                return [true, 'schema_valid'];

            case 'AttachPurchaseDraftSupplier':
                if (trim((string) ($command['draft_id'] ?? $command['purchase_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                $supplierId = trim((string) ($command['supplier_id'] ?? ''));
                $query = trim((string) ($command['query'] ?? ''));
                if ($supplierId === '' && $query === '') {
                    return [false, 'missing_supplier_reference'];
                }
                return [true, 'schema_valid'];

            case 'FinalizePurchase':
                if (trim((string) ($command['draft_id'] ?? $command['purchase_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                return [true, 'schema_valid'];

            case 'GetPurchase':
                if (trim((string) ($command['purchase_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_purchase_id'];
                }
                return [true, 'schema_valid'];

            case 'ListPurchases':
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['status', 'supplier_id', 'purchase_number', 'date_from', 'date_to'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'GetPurchaseByNumber':
                if (trim((string) ($command['purchase_number'] ?? $command['number'] ?? '')) === '') {
                    return [false, 'missing_purchase_number'];
                }
                return [true, 'schema_valid'];

            case 'AttachPurchaseDraftDocument':
                if (trim((string) ($command['draft_id'] ?? $command['purchase_draft_id'] ?? '')) === '') {
                    return [false, 'missing_draft_id'];
                }
                if (trim((string) ($command['media_file_id'] ?? $command['media_id'] ?? '')) === '') {
                    return [false, 'missing_media_file_id'];
                }
                if (array_key_exists('total_amount', $command) && $command['total_amount'] !== null && $command['total_amount'] !== '' && !is_numeric($command['total_amount'])) {
                    return [false, 'invalid_total_amount'];
                }
                return [true, 'schema_valid'];

            case 'AttachPurchaseDocument':
                $purchaseId = trim((string) ($command['purchase_id'] ?? ''));
                $purchaseNumber = trim((string) ($command['purchase_number'] ?? $command['number'] ?? ''));
                $purchaseQuery = trim((string) ($command['purchase_query'] ?? $command['query'] ?? ''));
                if ($purchaseId === '' && $purchaseNumber === '' && $purchaseQuery === '') {
                    return [false, 'missing_purchase_reference'];
                }
                if (trim((string) ($command['media_file_id'] ?? $command['media_id'] ?? '')) === '') {
                    return [false, 'missing_media_file_id'];
                }
                if (array_key_exists('total_amount', $command) && $command['total_amount'] !== null && $command['total_amount'] !== '' && !is_numeric($command['total_amount'])) {
                    return [false, 'invalid_total_amount'];
                }
                return [true, 'schema_valid'];

            case 'ListPurchaseDocuments':
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['purchase_id', 'purchase_number', 'purchase_query', 'purchase_draft_id', 'draft_id', 'media_file_id', 'document_type', 'supplier_id', 'supplier_query', 'document_number', 'date_from', 'date_to'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'GetPurchaseDocument':
            case 'DetachPurchaseDocument':
            case 'RegisterPurchaseDocumentMetadata':
                if (trim((string) ($command['purchase_document_id'] ?? $command['document_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_purchase_document_id'];
                }
                if ((string) ($command['command'] ?? '') === 'RegisterPurchaseDocumentMetadata') {
                    if (array_key_exists('total_amount', $command) && $command['total_amount'] !== null && $command['total_amount'] !== '' && !is_numeric($command['total_amount'])) {
                        return [false, 'invalid_total_amount'];
                    }
                }
                return [true, 'schema_valid'];

            case 'CreateFiscalDocument':
                foreach (['source_module', 'source_entity_type', 'source_entity_id', 'document_type'] as $key) {
                    if (trim((string) ($command[$key] ?? '')) === '') {
                        return [false, 'missing_' . $key];
                    }
                }
                if (array_key_exists('status', $command) && $command['status'] !== null && $command['status'] !== '' && !is_string($command['status'])) {
                    return [false, 'invalid_status'];
                }
                if (array_key_exists('lines', $command) && !is_array($command['lines'])) {
                    return [false, 'invalid_lines'];
                }
                return [true, 'schema_valid'];

            case 'CreateFiscalSalesInvoiceFromSale':
                if (
                    trim((string) ($command['sale_id'] ?? '')) === ''
                    && trim((string) ($command['sale_number'] ?? '')) === ''
                    && trim((string) ($command['source_entity_id'] ?? '')) === ''
                ) {
                    return [false, 'missing_sale_reference'];
                }
                foreach (['document_type', 'document_number', 'status'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'GetFiscalDocument':
                if (trim((string) ($command['fiscal_document_id'] ?? $command['document_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_fiscal_document_id'];
                }
                return [true, 'schema_valid'];

            case 'ListFiscalDocuments':
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['source_module', 'source_entity_type', 'source_entity_id', 'document_type', 'status', 'document_number', 'external_provider', 'external_reference', 'date_from', 'date_to'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'ListFiscalDocumentsByType':
                if (trim((string) ($command['document_type'] ?? '')) === '') {
                    return [false, 'missing_document_type'];
                }
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['document_type', 'status', 'date_from', 'date_to'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'GetFiscalDocumentBySource':
                foreach (['source_module', 'source_entity_type', 'source_entity_id'] as $key) {
                    if (trim((string) ($command[$key] ?? '')) === '') {
                        return [false, 'missing_' . $key];
                    }
                }
                if (array_key_exists('document_type', $command) && $command['document_type'] !== null && $command['document_type'] !== '' && !is_string($command['document_type'])) {
                    return [false, 'invalid_document_type'];
                }
                return [true, 'schema_valid'];

            case 'BuildFiscalDocumentPayload':
                if (trim((string) ($command['fiscal_document_id'] ?? $command['document_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_fiscal_document_id'];
                }
                return [true, 'schema_valid'];

            case 'RecordFiscalEvent':
                if (trim((string) ($command['fiscal_document_id'] ?? $command['document_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_fiscal_document_id'];
                }
                if (trim((string) ($command['event_type'] ?? '')) === '') {
                    return [false, 'missing_event_type'];
                }
                if (array_key_exists('event_status', $command) && $command['event_status'] !== null && $command['event_status'] !== '' && !is_string($command['event_status'])) {
                    return [false, 'invalid_event_status'];
                }
                if (array_key_exists('payload', $command) && $command['payload'] !== null && !is_array($command['payload'])) {
                    return [false, 'invalid_payload'];
                }
                return [true, 'schema_valid'];

            case 'CreateFiscalCreditNote':
                if (
                    trim((string) ($command['return_id'] ?? '')) === ''
                    && trim((string) ($command['sale_id'] ?? '')) === ''
                    && trim((string) ($command['sale_number'] ?? '')) === ''
                    && trim((string) ($command['source_entity_id'] ?? '')) === ''
                ) {
                    return [false, 'missing_credit_note_source'];
                }
                foreach (['status', 'reason'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'CreateFiscalSupportDocumentFromPurchase':
                if (
                    trim((string) ($command['purchase_id'] ?? '')) === ''
                    && trim((string) ($command['purchase_number'] ?? '')) === ''
                    && trim((string) ($command['source_entity_id'] ?? '')) === ''
                ) {
                    return [false, 'missing_purchase_reference'];
                }
                foreach (['document_type', 'document_number', 'status'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'UpdateFiscalDocumentStatus':
                if (trim((string) ($command['fiscal_document_id'] ?? $command['document_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_fiscal_document_id'];
                }
                if (trim((string) ($command['status'] ?? '')) === '') {
                    return [false, 'missing_status'];
                }
                if (array_key_exists('reason', $command) && $command['reason'] !== null && $command['reason'] !== '' && !is_string($command['reason'])) {
                    return [false, 'invalid_reason'];
                }
                return [true, 'schema_valid'];

            case 'CreateEcommerceStore':
                if (trim((string) ($command['tenant_id'] ?? '')) === '') {
                    return [false, 'missing_tenant_id'];
                }
                if (trim((string) ($command['platform'] ?? '')) === '') {
                    return [false, 'missing_platform'];
                }
                if (trim((string) ($command['store_name'] ?? $command['name'] ?? '')) === '') {
                    return [false, 'missing_store_name'];
                }
                if (array_key_exists('metadata', $command) && !is_array($command['metadata'])) {
                    return [false, 'invalid_metadata'];
                }
                return [true, 'schema_valid'];

            case 'UpdateEcommerceStore':
                if (trim((string) ($command['store_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_store_id'];
                }
                if (array_key_exists('metadata', $command) && !is_array($command['metadata'])) {
                    return [false, 'invalid_metadata'];
                }
                return [true, 'schema_valid'];

            case 'RegisterEcommerceStoreCredentials':
                if (trim((string) ($command['store_id'] ?? '')) === '') {
                    return [false, 'missing_store_id'];
                }
                if (trim((string) ($command['credential_type'] ?? '')) === '') {
                    return [false, 'missing_credential_type'];
                }
                $hasCredentialPayload = (
                    trim((string) ($command['token'] ?? '')) !== ''
                    || trim((string) ($command['api_key'] ?? '')) !== ''
                    || trim((string) ($command['secret'] ?? '')) !== ''
                    || trim((string) ($command['client_secret'] ?? '')) !== ''
                    || trim((string) ($command['password'] ?? '')) !== ''
                    || trim((string) ($command['key'] ?? '')) !== ''
                    || is_array($command['credentials'] ?? null)
                    || is_array($command['payload'] ?? null)
                    || trim((string) ($command['payload_json'] ?? '')) !== ''
                );
                if (!$hasCredentialPayload) {
                    return [false, 'missing_credential_payload'];
                }
                return [true, 'schema_valid'];

            case 'ValidateEcommerceStoreSetup':
            case 'GetEcommerceStore':
                if (trim((string) ($command['store_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_store_id'];
                }
                return [true, 'schema_valid'];

            case 'ValidateEcommerceConnection':
            case 'GetEcommerceStoreMetadata':
            case 'PingEcommerceStore':
                if (trim((string) ($command['store_id'] ?? $command['id'] ?? '')) === '') {
                    return [false, 'missing_store_id'];
                }
                return [true, 'schema_valid'];

            case 'GetEcommercePlatformCapabilities':
                if (
                    trim((string) ($command['store_id'] ?? '')) === ''
                    && trim((string) ($command['platform'] ?? '')) === ''
                ) {
                    return [false, 'missing_store_id_or_platform'];
                }
                if (array_key_exists('platform', $command) && $command['platform'] !== null && $command['platform'] !== '' && !is_string($command['platform'])) {
                    return [false, 'invalid_platform'];
                }
                return [true, 'schema_valid'];

            case 'ListEcommerceStores':
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['platform', 'status', 'connection_status'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'CreateEcommerceSyncJob':
                if (trim((string) ($command['store_id'] ?? '')) === '') {
                    return [false, 'missing_store_id'];
                }
                if (trim((string) ($command['sync_type'] ?? '')) === '') {
                    return [false, 'missing_sync_type'];
                }
                return [true, 'schema_valid'];

            case 'ListEcommerceSyncJobs':
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['store_id', 'sync_type', 'status'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'ListEcommerceOrderRefs':
                if (trim((string) ($command['store_id'] ?? '')) === '') {
                    return [false, 'missing_store_id'];
                }
                if (array_key_exists('limit', $command) && (!is_numeric($command['limit']) || (int) $command['limit'] < 1)) {
                    return [false, 'invalid_limit'];
                }
                foreach (['external_order_id', 'local_order_status', 'external_status'] as $key) {
                    if (array_key_exists($key, $command) && $command[$key] !== null && $command[$key] !== '' && !is_string($command[$key])) {
                        return [false, 'invalid_' . $key];
                    }
                }
                return [true, 'schema_valid'];

            case 'CreateEntity':
                if ($entity === '') {
                    return [false, 'missing_entity'];
                }
                if (!is_array($command['fields'] ?? null) || empty((array) $command['fields'])) {
                    return [false, 'missing_or_invalid_fields'];
                }
                return [true, 'schema_valid'];

            case 'CreateForm':
                if ($entity === '') {
                    return [false, 'missing_entity'];
                }
                return [true, 'schema_valid'];

            case 'CreateRelation':
                $source = trim((string) ($command['source_entity'] ?? ''));
                $target = trim((string) ($command['target_entity'] ?? ''));
                if ($source === '' || $target === '') {
                    return [false, 'missing_relation_entities'];
                }
                return [true, 'schema_valid'];

            case 'CreateIndex':
                $field = trim((string) ($command['field'] ?? ''));
                if ($entity === '' || $field === '') {
                    return [false, 'missing_entity_or_field'];
                }
                return [true, 'schema_valid'];

            case 'InstallPlaybook':
                $sectorKey = trim((string) ($command['sector_key'] ?? ''));
                if ($sectorKey === '') {
                    return [false, 'missing_sector_key'];
                }
                return [true, 'schema_valid'];

            case 'CompileWorkflow':
                $text = trim((string) ($command['text'] ?? ''));
                if ($text === '') {
                    return [false, 'missing_workflow_text'];
                }
                return [true, 'schema_valid'];

            case 'ImportIntegrationOpenApi':
                $apiName = trim((string) ($command['api_name'] ?? $command['integration_id'] ?? ''));
                $docUrl = trim((string) ($command['doc_url'] ?? ''));
                $openapiJson = trim((string) ($command['openapi_json'] ?? ''));
                if ($apiName === '') {
                    return [false, 'missing_api_name'];
                }
                if ($docUrl === '' && $openapiJson === '') {
                    return [false, 'missing_openapi_source'];
                }
                return [true, 'schema_valid'];

            case 'AuthLogin':
                $userId = trim((string) ($command['user_id'] ?? $command['usuario'] ?? $command['user'] ?? ''));
                $password = trim((string) ($command['password'] ?? $command['clave'] ?? ''));
                if ($userId === '' || $password === '') {
                    return [false, 'missing_credentials'];
                }
                return [true, 'schema_valid'];

            case 'AuthCreateUser':
                $newUser = trim((string) ($command['user_id'] ?? $command['usuario'] ?? $command['user'] ?? ''));
                $newRole = trim((string) ($command['role'] ?? $command['rol'] ?? ''));
                $newPassword = trim((string) ($command['password'] ?? $command['clave'] ?? ''));
                if ($newUser === '' || $newRole === '' || $newPassword === '') {
                    return [false, 'missing_user_create_fields'];
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
            : false;
        if (!$isAuthenticated) {
            return [false, 'not_authenticated'];
        }

        $role = strtolower(trim((string) ($context['role'] ?? getenv('DEFAULT_ROLE') ?: 'guest')));
        if ($role === '') {
            $role = 'guest';
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
        $authTenant = trim((string) ($context['auth_tenant_id'] ?? ''));
        $chatExecAuthRequired = (bool) ($context['chat_exec_auth_required'] ?? false);

        $resolved = $contextTenant !== '' ? $contextTenant : ($commandTenant !== '' ? $commandTenant : $envTenant);
        if ($resolved === '') {
            $resolved = 'default';
        }

        if ($chatExecAuthRequired && $contextTenant === '') {
            return [false, 'missing_context_tenant', $resolved];
        }

        if ($contextTenant !== '' && $commandTenant !== '' && $contextTenant !== $commandTenant) {
            return [false, 'tenant_mismatch', $resolved];
        }

        if ($authTenant !== '' && $resolved !== $authTenant) {
            return [false, 'tenant_mismatch_auth_context', $resolved];
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
     * @param array<string, mixed> $context
     * @return array{0: bool, 1: string}
     */
    private function validateBuilderMode(array $context): array
    {
        $mode = strtolower(trim((string) ($context['mode'] ?? 'app')));
        if ($mode === '') {
            $mode = 'app';
        }
        if ($mode !== 'builder') {
            return [false, 'mode_builder_required'];
        }
        return [true, 'mode_builder_ok'];
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
        bool $modePass,
        bool $skipEvidenceGate,
        string $evidenceGateStatus
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
        // Deterministic routes resolved before LLM fallback are evidence-backed by rules even when
        // upstream payloads do not yet annotate explicit source flags.
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        if (empty($sourcesUsed) && $action !== 'send_to_llm' && in_array('rules', $routePathSteps, true)) {
            $sourcesUsed[] = 'rules';
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
        if ($skipEvidenceGate || $evidenceGateStatus === 'skipped_by_rule') {
            $required = [];
            $present[] = 'evidence_gate_skipped_by_rule';
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
        $matchName = $this->canonicalGateName($gateName);
        foreach ($gateResults as $row) {
            if ((string) ($row['name'] ?? '') === $matchName) {
                return true;
            }
        }
        return false;
    }

    private function canonicalGateName(string $gateName): string
    {
        $aliases = [
            'schema_guard' => 'schema_gate',
            'role_guard' => 'auth_rbac_gate',
            'auth_guard' => 'auth_rbac_gate',
            'tenant_scope_guard' => 'tenant_scope_gate',
            'mode_guard' => 'mode_guard_gate',
            'builder_mode_guard' => 'builder_mode_gate',
            'allowlist_guard' => 'allowlist_gate',
        ];
        $normalized = strtolower(trim($gateName));
        return $aliases[$normalized] ?? $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function hardGateNames(): array
    {
        return ['allowlist_gate', 'schema_gate', 'auth_rbac_gate', 'tenant_scope_gate'];
    }

    /**
     * @param array<int, array{name:string,required:bool,passed:bool,reason:string}> $gateResults
     * @return array<int, string>
     */
    private function collectHardGateFailures(array $gateResults, string $action): array
    {
        if ($action !== 'execute_command') {
            return [];
        }

        $hard = $this->hardGateNames();
        $failures = [];
        foreach ($gateResults as $gate) {
            $name = strtolower(trim((string) ($gate['name'] ?? '')));
            if (!in_array($name, $hard, true)) {
                continue;
            }
            $required = (bool) ($gate['required'] ?? false);
            $passed = (bool) ($gate['passed'] ?? true);
            if ($required && !$passed) {
                $reason = trim((string) ($gate['reason'] ?? 'gate_failed'));
                $failures[] = $name . ':' . ($reason !== '' ? $reason : 'gate_failed');
            }
        }

        return array_values(array_unique($failures));
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
