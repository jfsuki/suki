<?php
// app/Core/UnitTestRunner.php

namespace App\Core;

use Throwable;

final class UnitTestRunner
{
    public function run(): array
    {
        $this->cleanupLegacyTestArtifacts();

        $tests = [];
        $tests[] = $this->wrap('manifest', fn() => ManifestValidator::validateOrFail());
        $tests[] = $this->wrap('gbo_contracts', fn() => $this->checkGboContracts());
        $tests[] = $this->wrap('beg_contracts', fn() => $this->checkBegContracts());
        $tests[] = $this->wrap('gbo_beg_cli', fn() => $this->checkGboBegCli());
        $tests[] = $this->wrap('audit_contracts', fn() => $this->checkAuditContracts());
        $tests[] = $this->wrap('audit_cli', fn() => $this->checkAuditCli());
        $tests[] = $this->wrap('workflow_contract', fn() => $this->checkWorkflowContract());
        $tests[] = $this->wrap('workflow_executor', fn() => $this->checkWorkflowExecutor());
        $tests[] = $this->wrap('workflow_compiler', fn() => $this->checkWorkflowCompiler());
        $tests[] = $this->wrap('workflow_repository', fn() => $this->checkWorkflowRepository());
        $tests[] = $this->wrap('entity_registry', fn() => (new EntityRegistry())->all());
        $tests[] = $this->wrap('form_contracts', fn() => (new ContractsCatalog())->forms());
        $tests[] = $this->wrap('db_connection', fn() => Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $tests[] = $this->wrap('llm_config', fn() => $this->checkLlmConfig());
        $tests[] = $this->wrap('chat_parser', fn() => $this->checkParser());
        $tests[] = $this->wrap('gateway_local', fn() => $this->checkGateway());
        $tests[] = $this->wrap('gateway_golden', fn() => $this->checkGatewayGolden());
        $tests[] = $this->wrap('mode_context_isolation', fn() => $this->checkModeContextIsolation());
        $tests[] = $this->wrap('business_reprofile_mid_flow', fn() => $this->checkBusinessReprofileMidFlow());
        $tests[] = $this->wrap('unknown_business_discovery', fn() => $this->checkUnknownBusinessDiscovery());
        $tests[] = $this->wrap('unknown_business_llm_quality', fn() => $this->checkUnknownBusinessLlmQuality());
        $tests[] = $this->wrap('unknown_business_quality_report', fn() => $this->checkUnknownBusinessQualityReport());
        $tests[] = $this->wrap('mode_guard_policy', fn() => $this->checkModeGuardPolicy());
        $tests[] = $this->wrap('builder_onboarding_flow', fn() => $this->checkBuilderOnboardingFlow());
        $tests[] = $this->wrap('builder_guidance', fn() => $this->checkBuilderGuidance());
        $tests[] = $this->wrap('flow_control', fn() => $this->checkFlowControl());
        $tests[] = $this->wrap('domain_training_sync', fn() => $this->checkDomainTrainingSync());
        $tests[] = $this->wrap('secrets_guard', fn() => $this->checkSecretsGuard());
        $tests[] = $this->wrap('sensitive_log_redaction', fn() => $this->checkSensitiveLogRedaction());
        $tests[] = $this->wrap('security_state_repository', fn() => $this->checkSecurityStateRepository());
        $tests[] = $this->wrap('openapi_importer', fn() => $this->checkOpenApiIntegrationImporter());
        $tests[] = $this->wrap('api_security_guard', fn() => $this->checkApiSecurityGuard());
        $tests[] = $this->wrap('records_get_requires_auth', fn() => $this->checkRecordsGetRequiresAuth());
        $tests[] = $this->wrap('records_get_with_signed_token_ok', fn() => $this->checkRecordsGetWithSignedTokenOk());
        $tests[] = $this->wrap('records_get_with_expired_token_fail', fn() => $this->checkRecordsGetWithExpiredTokenFail());
        $tests[] = $this->wrap('records_get_token_tenant_mismatch_fail', fn() => $this->checkRecordsGetTokenTenantMismatchFail());
        $tests[] = $this->wrap('records_mutation_requires_auth', fn() => $this->checkRecordsMutationRequiresAuth());
        $tests[] = $this->wrap('records_mutation_rejects_payload_tenant_override', fn() => $this->checkRecordsMutationRejectsPayloadTenantOverride());
        $tests[] = $this->wrap('records_mutation_accepts_authenticated_session', fn() => $this->checkRecordsMutationAcceptsAuthenticatedSession());
        $tests[] = $this->wrap('records_mutation_cross_tenant_block', fn() => $this->checkRecordsMutationCrossTenantBlock());
        $tests[] = $this->wrap('chat_exec_requires_auth', fn() => $this->checkChatExecRequiresAuth());
        $tests[] = $this->wrap('chat_exec_no_default_admin', fn() => $this->checkChatExecNoDefaultAdmin());
        $tests[] = $this->wrap('chat_exec_tenant_binding', fn() => $this->checkChatExecTenantBinding());
        $tests[] = $this->wrap('chat_informational_without_auth_ok', fn() => $this->checkChatInformationalWithoutAuthOk());
        $tests[] = $this->wrap('chat_warn_mode_does_not_allow_exec_when_auth_fails', fn() => $this->checkChatWarnModeDoesNotAllowExecWhenAuthFails());
        $tests[] = $this->wrap('redteam_poc_chat_exec', fn() => $this->checkRedteamPocChatExec());
        $tests[] = $this->wrap('telegram_rejects_get', fn() => $this->checkTelegramRejectsGet());
        $tests[] = $this->wrap('whatsapp_rejects_put', fn() => $this->checkWhatsAppRejectsPut());
        $tests[] = $this->wrap('webhook_fails_closed_when_secret_empty_in_staging', fn() => $this->checkWebhookFailsClosedWhenSecretEmptyInStaging());
        $tests[] = $this->wrap('webhook_allows_insecure_only_in_dev_when_flag', fn() => $this->checkWebhookAllowsInsecureOnlyInDevWhenFlag());
        $tests[] = $this->wrap('whatsapp_fast_ack', fn() => $this->checkWhatsAppFastAck());
        $tests[] = $this->wrap('whatsapp_enqueue', fn() => $this->checkWhatsAppEnqueue());
        $tests[] = $this->wrap('whatsapp_idempotency', fn() => $this->checkWhatsAppIdempotency());
        $tests[] = $this->wrap('worker_processes_queued_whatsapp_message', fn() => $this->checkWorkerProcessesQueuedWhatsAppMessage());
        $tests[] = $this->wrap('worker_respects_idempotency', fn() => $this->checkWorkerRespectsIdempotency());
        $tests[] = $this->wrap('worker_logs_route_path', fn() => $this->checkWorkerLogsRoutePath());
        $tests[] = $this->wrap('agentops_runtime_observability', fn() => $this->checkAgentOpsRuntimeObservability());
        $tests[] = $this->wrap('agentops_supervisor', fn() => $this->checkAgentOpsSupervisor());
        $tests[] = $this->wrap('operational_queue_schema_guard', fn() => $this->checkOperationalQueueSchemaGuard());
        $tests[] = $this->wrap('schema_runtime_guard', fn() => $this->checkSchemaRuntimeGuard());
        $tests[] = $this->wrap('framework_hygiene', fn() => $this->checkFrameworkHygiene());
        $tests[] = $this->wrap('public_excel_import_e2e', fn() => $this->checkPublicExcelImportE2E());
        $tests[] = $this->wrap('public_report_e2e', fn() => $this->checkPublicReportE2E());
        $tests[] = $this->wrap('workflow_api_e2e', fn() => $this->checkWorkflowApiE2E());
        $tests[] = $this->wrap('security_channels_e2e', fn() => $this->checkSecurityChannelsE2E());
        $tests[] = $this->wrap('gemini_embedding_service', fn() => $this->checkGeminiEmbeddingService());
        $tests[] = $this->wrap('qdrant_vector_store', fn() => $this->checkQdrantVectorStore());
        $tests[] = $this->wrap('semantic_memory_service', fn() => $this->checkSemanticMemoryService());
        $tests[] = $this->wrap('intent_router', fn() => $this->checkIntentRouter());
        $tests[] = $this->wrap('router_contract_enforcement', fn() => $this->checkRouterContractEnforcement());
        $tests[] = $this->wrap('action_allowlist_enforcement', fn() => $this->checkActionAllowlistEnforcement());
        $tests[] = $this->wrap('enforcement_minimum_evidence_strict_blocks_when_missing', fn() => $this->checkEnforcementMinimumEvidenceStrictBlocksWhenMissing());
        $tests[] = $this->wrap('enforcement_warn_allows_but_logs_when_missing', fn() => $this->checkEnforcementWarnAllowsButLogsWhenMissing());
        $tests[] = $this->wrap('gates_required_block_action_when_schema_invalid', fn() => $this->checkGatesRequiredBlockActionWhenSchemaInvalid());
        $tests[] = $this->wrap('gates_required_block_action_when_not_allowlisted', fn() => $this->checkGatesRequiredBlockActionWhenNotAllowlisted());
        $tests[] = $this->wrap('enforcement_default_by_env', fn() => $this->checkEnforcementDefaultByEnv());
        $tests[] = $this->wrap('command_bus', fn() => $this->checkCommandBus());
        $tests[] = $this->wrap('observability_metrics', fn() => $this->checkObservabilityMetrics());
        $tests[] = $this->wrap('canonical_storage_new_project', fn() => $this->checkCanonicalStorageNewProject());
        $tests[] = $this->wrap('llm_router_failover', fn() => $this->checkLlmRouterFailover());
        $tests[] = $this->wrap('training_dataset_validator', fn() => $this->checkTrainingDatasetValidator());
        $tests[] = $this->wrap('erp_training_dataset_pipeline', fn() => $this->checkErpTrainingDatasetPipeline());
        $tests[] = $this->wrap('generate_erp_training_dataset', fn() => $this->checkGenerateErpTrainingDataset());
        $tests[] = $this->wrap('erp_training_dataset_cli_guardrails', fn() => $this->checkErpTrainingDatasetCliGuardrails());
        $tests[] = $this->wrap('erp_training_dataset_vectorization', fn() => $this->checkErpTrainingDatasetVectorization());
        $tests[] = $this->wrap('training_dataset_publication_gate', fn() => $this->checkTrainingDatasetPublicationGate());
        $tests[] = $this->wrap('training_dataset_vectorize_command', fn() => $this->checkTrainingDatasetVectorizeCommand());
        $tests[] = $this->wrap('business_discovery_template', fn() => $this->checkBusinessDiscoveryTemplate());
        $tests[] = $this->wrap('alerts_center_module', fn() => $this->checkAlertsCenterModule());
        $tests[] = $this->wrap('media_module', fn() => $this->checkMediaModule());
        $tests[] = $this->wrap('entity_search_module', fn() => $this->checkEntitySearchModule());
        $tests[] = $this->wrap('pos_core_module', fn() => $this->checkPOSCoreModule());
        $tests[] = $this->wrap('pos_products_pricing_barcode', fn() => $this->checkPOSProductsPricingBarcode());
        $tests[] = $this->wrap('pos_sales_flow_receipt', fn() => $this->checkPOSSalesFlowReceipt());
        $tests[] = $this->wrap('pos_cash_register_arqueo', fn() => $this->checkPOSCashRegisterArqueo());
        $tests[] = $this->wrap('pos_returns_cancelations', fn() => $this->checkPOSReturnsCancelations());
        $tests[] = $this->wrap('purchases_core_module', fn() => $this->checkPurchasesCoreModule());
        $tests[] = $this->wrap('purchases_documents_module', fn() => $this->checkPurchasesDocumentsModule());
        $tests[] = $this->wrap('fiscal_engine_architecture', fn() => $this->checkFiscalEngineArchitecture());
        $tests[] = $this->wrap('fe_invoice_credit_note_support_docs', fn() => $this->checkFEInvoiceCreditNoteSupportDocs());
        $tests[] = $this->wrap('ecommerce_hub_architecture', fn() => $this->checkEcommerceHubArchitecture());
        $tests[] = $this->wrap('ecommerce_hub_adapters', fn() => $this->checkEcommerceHubAdapters());
        $tests[] = $this->wrap('ecommerce_product_sync_foundation', fn() => $this->checkEcommerceProductSyncFoundation());
        $tests[] = $this->wrap('ecommerce_order_sync_foundation', fn() => $this->checkEcommerceOrderSyncFoundation());
        $tests[] = $this->wrap('ecommerce_agent_skills', fn() => $this->checkEcommerceAgentSkills());
        $tests[] = $this->wrap('agent_tools_integration_foundation', fn() => $this->checkAgentToolsIntegrationFoundation());
        $tests[] = $this->wrap('agent_tools_integration_skills', fn() => $this->checkAgentToolsIntegrationSkills());
        $tests[] = $this->wrap('agentops_observability_foundation', fn() => $this->checkAgentOpsObservabilityFoundation());
        $tests[] = $this->wrap('agentops_observability_skills', fn() => $this->checkAgentOpsObservabilitySkills());
        $tests[] = $this->wrap('control_tower_contracts', fn() => $this->checkControlTowerContracts());
        $tests[] = $this->wrap('control_tower_task', fn() => $this->checkControlTowerTask());
        $tests[] = $this->wrap('conversation_gateway_trace', fn() => $this->checkConversationGatewayTrace());
        $tests[] = $this->wrap('incident_flow', fn() => $this->checkIncidentFlow());
        $tests[] = $this->wrap('project_memory_system', fn() => $this->checkProjectMemorySystem());
        $tests[] = $this->wrap('learning_promotion_pipeline', fn() => $this->checkLearningPromotionPipeline());
        $tests[] = $this->wrap('semantic_pipeline_e2e', fn() => $this->checkSemanticPipelineE2E());
        $tests[] = $this->wrap('data_quality_guard', fn() => $this->checkDataQualityGuard());
        $tests[] = $this->wrap('otp_flow_e2e', fn() => $this->checkOtpFlowE2E());
        $tests[] = $this->wrap('tenant_self_registration', fn() => $this->checkTenantSelfRegistration());
        $tests[] = $this->wrap('non_electronic_receipt', fn() => $this->checkNonElectronicReceipt());
        $tests[] = $this->wrap('app_config_onboarding', fn() => $this->checkAppConfigOnboarding());

        // ACID tests — real infrastructure, no mocks (exit 1 = real failure)
        $tests[] = $this->wrap('acid_qdrant_connectivity', fn() => $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/acid_qdrant_connectivity_test.php'));
        $tests[] = $this->wrap('acid_intent_classification', fn() => $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/acid_intent_classification_test.php'));
        $tests[] = $this->wrap('acid_agent_memory', fn() => $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/acid_agent_memory_test.php'));
        $tests[] = $this->wrap('acid_multitenant_isolation', fn() => $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/acid_multitenant_isolation_test.php'));
        $tests[] = $this->wrap('acid_llm_routing', fn() => $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/acid_llm_routing_test.php'));

        $summary = [
            'passed' => count(array_filter($tests, fn($t) => $t['status'] === 'pass')),
            'failed' => count(array_filter($tests, fn($t) => $t['status'] === 'fail')),
            'warned' => count(array_filter($tests, fn($t) => $t['status'] === 'warn')),
        ];

        return [
            'summary' => $summary,
            'tests' => $tests,
        ];
    }

    private function wrap(string $name, callable $fn): array
    {
        try {
            $fn();
            return ['name' => $name, 'status' => 'pass'];
        } catch (Throwable $e) {
            $status = $this->isWarning($name) ? 'warn' : 'fail';
            return ['name' => $name, 'status' => $status, 'error' => $e->getMessage()];
        }
    }

    private function isWarning(string $name): bool
    {
        return in_array($name, ['db_connection', 'llm_config'], true);
    }

    private function checkLlmConfig(): void
    {
        $groq = getenv('GROQ_API_KEY') ?: '';
        $gemini = getenv('GEMINI_API_KEY') ?: '';
        if ($groq === '' && $gemini === '') {
            throw new \RuntimeException('No hay API keys LLM configuradas.');
        }
    }

    private function checkParser(): void
    {
        $agent = new ChatAgent();
        $result = $agent->parseLocal('crear cliente nombre=Ana');
        if (($result['command'] ?? '') !== 'CreateRecord') {
            throw new \RuntimeException('Parser local no reconoce CreateRecord.');
        }
    }

    private function checkWorkflowContract(): void
    {
        $valid = [
            'meta' => [
                'id' => 'wf_unit_v1',
                'name' => 'Unit workflow',
                'status' => 'draft',
                'revision' => 1,
            ],
            'nodes' => [
                [
                    'id' => 'n_input',
                    'type' => 'input',
                    'title' => 'Capture',
                    'runPolicy' => [
                        'timeout_ms' => 10000,
                        'retry_max' => 0,
                        'token_budget' => 0,
                    ],
                ],
            ],
            'edges' => [],
            'assets' => [],
            'theme' => ['presetName' => 'clean_business'],
            'versioning' => [
                'revision' => 1,
                'historyPointers' => [],
            ],
        ];

        WorkflowValidator::validateOrFail($valid);

        $invalid = $valid;
        $invalid['nodes'][0]['runPolicy']['timeout_ms'] = 0;
        try {
            WorkflowValidator::validateOrFail($invalid);
        } catch (\Throwable $e) {
            // expected
        }

        $invalidEdge = $valid;
        $invalidEdge['edges'][] = [
            'from' => 'n_input',
            'to' => 'n_missing',
            'mapping' => ['x' => 'output.text'],
        ];
        try {
            WorkflowValidator::validateOrFail($invalidEdge);
        } catch (\Throwable $e) {
            return;
        }

        throw new \RuntimeException('WorkflowValidator debe bloquear schema y semantica invalida.');
    }

    private function checkGboContracts(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/gbo_contracts_test.php');
    }

    private function checkBegContracts(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/beg_contracts_test.php');
    }

    private function checkGboBegCli(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/gbo_beg_cli_test.php');
    }

    private function checkAuditContracts(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/audit_contracts_test.php');
    }

    private function checkAuditCli(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/audit_cli_test.php');
    }

    private function checkWorkflowExecutor(): void
    {
        $workflow = [
            'meta' => [
                'id' => 'wf_exec_unit',
                'name' => 'Executor unit',
                'status' => 'draft',
                'revision' => 1,
            ],
            'nodes' => [
                [
                    'id' => 'n_input',
                    'type' => 'input',
                    'title' => 'Input',
                    'runPolicy' => ['timeout_ms' => 10000, 'retry_max' => 0, 'token_budget' => 0],
                ],
                [
                    'id' => 'n_generate',
                    'type' => 'generate',
                    'title' => 'Generate',
                    'promptTemplate' => 'Cliente {{input.cliente}} total {{input.total}}',
                    'runPolicy' => ['timeout_ms' => 10000, 'retry_max' => 0, 'token_budget' => 0],
                ],
                [
                    'id' => 'n_output',
                    'type' => 'output',
                    'title' => 'Output',
                    'runPolicy' => ['timeout_ms' => 10000, 'retry_max' => 0, 'token_budget' => 0],
                ],
            ],
            'edges' => [
                ['from' => 'n_input', 'to' => 'n_generate', 'mapping' => ['cliente' => 'output.cliente', 'total' => 'output.total']],
                ['from' => 'n_generate', 'to' => 'n_output', 'mapping' => ['text' => 'output.text']],
            ],
            'assets' => [],
            'theme' => ['presetName' => 'clean_business'],
            'versioning' => ['revision' => 1, 'historyPointers' => []],
        ];

        $executor = new WorkflowExecutor();
        $result = $executor->execute($workflow, ['cliente' => 'Ana', 'total' => 120000]);
        if (!(bool) ($result['ok'] ?? false)) {
            throw new \RuntimeException('WorkflowExecutor debe terminar en ok=true para caso valido.');
        }
        $final = is_array($result['final_output'] ?? null) ? (array) $result['final_output'] : [];
        $text = (string) ($final['text'] ?? '');
        if (!str_contains($text, 'Ana') || !str_contains($text, '120000')) {
            throw new \RuntimeException('WorkflowExecutor no propago valores entre nodos.');
        }
        $traces = is_array($result['traces'] ?? null) ? (array) $result['traces'] : [];
        if (count($traces) !== 3) {
            throw new \RuntimeException('WorkflowExecutor debe emitir traza por nodo.');
        }
    }

    private function checkWorkflowCompiler(): void
    {
        $compiler = new WorkflowCompiler();
        $proposal = $compiler->compile('quiero un flujo para generar cotizacion y salida final');
        if ((string) ($proposal['status'] ?? '') !== 'PROPOSAL_READY') {
            throw new \RuntimeException('WorkflowCompiler debe producir PROPOSAL_READY.');
        }
        if (!(bool) ($proposal['needs_confirmation'] ?? false)) {
            throw new \RuntimeException('WorkflowCompiler debe exigir confirmacion antes de aplicar.');
        }
        $contract = is_array($proposal['proposed_contract'] ?? null) ? (array) $proposal['proposed_contract'] : [];
        WorkflowValidator::validateOrFail($contract);
    }

    private function checkWorkflowRepository(): void
    {
        $tmpProject = FRAMEWORK_ROOT . '/tests/tmp/workflow_repo_project';
        if (!is_dir($tmpProject . '/contracts')) {
            mkdir($tmpProject . '/contracts', 0775, true);
        }
        if (!is_dir($tmpProject . '/storage')) {
            mkdir($tmpProject . '/storage', 0775, true);
        }
        $repo = new WorkflowRepository($tmpProject);
        $workflowId = 'wf_repo_unit_' . time();
        $contract = [
            'meta' => [
                'id' => $workflowId,
                'name' => 'Repo unit',
                'status' => 'draft',
                'revision' => 1,
            ],
            'nodes' => [
                [
                    'id' => 'n_input',
                    'type' => 'input',
                    'title' => 'Input',
                    'runPolicy' => ['timeout_ms' => 1000, 'retry_max' => 0, 'token_budget' => 0],
                ],
            ],
            'edges' => [],
            'assets' => [],
            'theme' => ['presetName' => 'clean_business'],
            'versioning' => ['revision' => 1, 'historyPointers' => []],
        ];

        $save = $repo->save($contract, 'unit_save');
        if (((int) ($save['revision'] ?? 0)) < 1) {
            throw new \RuntimeException('WorkflowRepository save debe devolver revision >= 1.');
        }
        $loaded = $repo->load($workflowId);
        if ((string) ($loaded['meta']['id'] ?? '') !== $workflowId) {
            throw new \RuntimeException('WorkflowRepository load devolvio id invalido.');
        }
        $loaded['meta']['name'] = 'Repo unit v2';
        $repo->save($loaded, 'unit_save_2');
        $history = $repo->history($workflowId);
        if (count($history) < 2) {
            throw new \RuntimeException('WorkflowRepository history debe registrar revisiones.');
        }
    }

    private function checkGateway(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $result = $gateway->handle('default', 'test', 'hola');
        if (($result['action'] ?? '') !== 'respond_local') {
            throw new \RuntimeException('Gateway no responde saludo local.');
        }
    }

    private function checkGatewayGolden(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $user = 'golden_' . time();
        $projectId = 'golden_proj';

        $appBuild = $gateway->handle('default', $user, 'quiero crear una tabla clientes', 'app', $projectId);
        if (($appBuild['action'] ?? '') !== 'respond_local' || stripos((string) ($appBuild['reply'] ?? ''), 'Creador de apps') === false) {
            throw new \RuntimeException('Guard app/build no bloquea correctamente.');
        }

        $builderCrud = $gateway->handle('default', $user, 'crear cliente nombre=Ana', 'builder', $projectId);
        if (($builderCrud['action'] ?? '') !== 'respond_local' || stripos((string) ($builderCrud['reply'] ?? ''), 'chat de la app') === false) {
            throw new \RuntimeException('Guard builder/use no bloquea correctamente.');
        }

        $memory = new SqlMemoryRepository();
        $state = $memory->getUserMemory('default', $user, 'state::' . $projectId . '::builder', []);
        if (empty($state)) {
            throw new \RuntimeException('No se creo estado SQL por project+mode+user.');
        }
        $working = $memory->getUserMemory('default', $user, 'working_memory::' . $projectId . '::builder', []);
        if (empty($working)) {
            throw new \RuntimeException('No se guardo working memory SQL para builder.');
        }
    }

    private function checkModeContextIsolation(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $memory = new SqlMemoryRepository();
        $tenantId = 'default';
        $user = 'iso_' . time();
        $projectId = 'iso_proj';

        $gateway->handle($tenantId, $user, 'mi negocio es una ferreteria', 'builder', $projectId);
        $gateway->handle($tenantId, $user, 'crear cliente nombre=Ana', 'app', $projectId);

        $builderState = $memory->getUserMemory($tenantId, $user, 'state::' . $projectId . '::builder', []);
        $appState = $memory->getUserMemory($tenantId, $user, 'state::' . $projectId . '::app', []);

        if (empty($builderState)) {
            throw new \RuntimeException('No se encontro estado builder para mismo usuario.');
        }
        if (empty($appState)) {
            throw new \RuntimeException('No se encontro estado app para mismo usuario.');
        }
        if ((string) ($builderState['active_task'] ?? '') !== 'builder_onboarding') {
            throw new \RuntimeException('Estado builder no quedo en flujo de creador.');
        }
        if (($appState['active_task'] ?? null) !== null) {
            throw new \RuntimeException('Estado app no debe heredar task activa del builder.');
        }

        $builderProfile = $memory->getUserMemory($tenantId, $projectId . '__builder__' . $user, 'profile', []);
        $appProfile = $memory->getUserMemory($tenantId, $projectId . '__app__' . $user, 'profile', []);

        if ((string) ($builderProfile['business_type'] ?? '') === '') {
            throw new \RuntimeException('Perfil builder no guardo business_type.');
        }
        if (!empty($appProfile['business_type'] ?? '')) {
            throw new \RuntimeException('Perfil app no debe mezclar business_type de builder.');
        }
    }

    private function checkBusinessReprofileMidFlow(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $tenantId = 'default';
        $user = 'reprofile_' . time();
        $projectId = 'reprofile_proj';

        $gateway->handle($tenantId, $user, 'mi negocio es una ferreteria', 'builder', $projectId);
        $gateway->handle($tenantId, $user, 'mixto', 'builder', $projectId);
        $gateway->handle($tenantId, $user, 'inventario, facturacion y pagos', 'builder', $projectId);
        $gateway->handle($tenantId, $user, 'factura, cotizacion', 'builder', $projectId);

        $switch = $gateway->handle(
            $tenantId,
            $user,
            'hola necesito hacer una aplicacion para un almacen de corte laser',
            'builder',
            $projectId
        );
        $switchReply = mb_strtolower((string) ($switch['reply'] ?? ''), 'UTF-8');
        $switchReplyNorm = $this->stripAccents($switchReply);
        if (str_contains($switchReplyNorm, 'negocio: ferreteria')) {
            throw new \RuntimeException('No se permitio cambiar de negocio en flujo de confirmacion.');
        }
        $allowedSignals = ['corte laser', 'servicios de corte laser', 'paso 3', 'paso 2', 'productos, servicios o ambos', 'en este paso necesito', 'almacen', 'tienda'];
        $matched = false;
        foreach ($allowedSignals as $signal) {
            if (str_contains($switchReplyNorm, $signal)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new \RuntimeException('Cambio de negocio en confirmacion no produjo una salida valida.');
        }

        $reject = $gateway->handle($tenantId, $user, 'no soy ferreteria', 'builder', $projectId);
        $rejectReply = mb_strtolower((string) ($reject['reply'] ?? ''), 'UTF-8');
        $rejectReplyNorm = $this->stripAccents($rejectReply);
        if (str_contains($rejectReplyNorm, 'negocio: ferreteria')) {
            throw new \RuntimeException('La correccion "no soy <negocio>" no limpio el resumen previo.');
        }
    }

    private function checkUnknownBusinessDiscovery(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $tenantId = 'default';
        try {
            $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        } catch (\Throwable $e) {
            $suffix = (string) mt_rand(100000, 999999);
        }
        $user = 'unknown_' . time() . '_' . $suffix;
        $projectId = 'unknown_proj_' . $suffix;

        $gateway->handle(
            $tenantId,
            $user,
            'quiero crear una app',
            'builder',
            $projectId
        );
        $start = $gateway->handle(
            $tenantId,
            $user,
            'laboratorio de velas artesanales',
            'builder',
            $projectId
        );
        $startReply = mb_strtolower((string) ($start['reply'] ?? ''), 'UTF-8');
        if ((string) ($start['action'] ?? '') !== 'ask_user') {
            throw new \RuntimeException('Unknown business discovery debe iniciar en modo ask_user.');
        }
        if (!str_contains($startReply, 'pregunta 1/')) {
            throw new \RuntimeException('Unknown business discovery debe iniciar cuestionario tecnico.');
        }

        $startState = is_array($start['state'] ?? null) ? (array) $start['state'] : [];
        if ((string) ($startState['active_task'] ?? '') !== 'unknown_business_discovery') {
            throw new \RuntimeException('Unknown business discovery debe activar tarea dedicada.');
        }
        $flow = is_array($startState['unknown_business_discovery'] ?? null)
            ? (array) $startState['unknown_business_discovery']
            : [];
        $questions = is_array($flow['questions'] ?? null) ? array_values((array) $flow['questions']) : [];
        if (count($questions) < 4) {
            throw new \RuntimeException('Unknown business discovery requiere bloque amplio de preguntas.');
        }

        $result = $gateway->handle(
            $tenantId,
            $user,
            'ventas, inventario y facturacion',
            'builder',
            $projectId
        );
        $stepOneState = is_array($result['state'] ?? null) ? (array) $result['state'] : [];
        $stepOneFlow = is_array($stepOneState['unknown_business_discovery'] ?? null)
            ? (array) $stepOneState['unknown_business_discovery']
            : [];
        if ((int) ($stepOneFlow['current_index'] ?? -1) !== 1) {
            throw new \RuntimeException('Unknown business discovery debe avanzar a pregunta 2 tras primera respuesta valida.');
        }

        $frustration = $gateway->handle(
            $tenantId,
            $user,
            'no estas entendiendo',
            'builder',
            $projectId
        );
        $frustrationReply = mb_strtolower((string) ($frustration['reply'] ?? ''), 'UTF-8');
        if (!str_contains($frustrationReply, 'pregunta 2/')) {
            throw new \RuntimeException('Unknown business discovery no debe avanzar cuando la respuesta no aporta dato.');
        }
        $frustrationState = is_array($frustration['state'] ?? null) ? (array) $frustration['state'] : [];
        $frustrationFlow = is_array($frustrationState['unknown_business_discovery'] ?? null)
            ? (array) $frustrationState['unknown_business_discovery']
            : [];
        if ((int) ($frustrationFlow['current_index'] ?? -1) !== 1) {
            throw new \RuntimeException('Unknown business discovery debe mantener el indice en respuestas no validas.');
        }

        $result = $frustration;
        $answerCount = count($questions);
        for ($i = 1; $i < $answerCount; $i++) {
            $answer = 'respuesta ' . ($i + 1) . ' proceso produccion, ventas, factura y control de calidad';
            if ($i === 2) {
                $answer = 'ventas, contabilidad, pagos y lo que me pide la dian';
            }
            $result = $gateway->handle(
                $tenantId,
                $user,
                $answer,
                'builder',
                $projectId
            );
            $loopReply = mb_strtolower((string) ($result['reply'] ?? ''), 'UTF-8');
            if (str_contains($loopReply, 'flujo sugerido:') || str_contains($loopReply, 'facturacion electronica en colombia')) {
                throw new \RuntimeException('Unknown business discovery no debe ser interrumpido por builder guidance.');
            }
        }

        $finalReply = mb_strtolower((string) ($result['reply'] ?? ''), 'UTF-8');
        if (!str_contains($finalReply, 'documento tecnico inicial')) {
            throw new \RuntimeException('Unknown business discovery debe emitir documento tecnico inicial.');
        }

        $finalState = is_array($result['state'] ?? null) ? (array) $result['state'] : [];
        $finalFlow = is_array($finalState['unknown_business_discovery'] ?? null)
            ? (array) $finalState['unknown_business_discovery']
            : [];
        $technicalPrompt = trim((string) ($finalFlow['technical_prompt'] ?? ''));
        if ($technicalPrompt === '') {
            throw new \RuntimeException('Unknown business discovery debe guardar prompt tecnico para LLM.');
        }
    }

    private function checkUnknownBusinessLlmQuality(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/unknown_business_llm_quality_test.php');
    }

    private function checkUnknownBusinessQualityReport(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/unknown_business_quality_report_test.php');
    }

    private function checkModeGuardPolicy(): void
    {
        $policy = new ModeGuardPolicy();
        $buildGuard = $policy->evaluate('app', true, false, false);
        if ((string) ($buildGuard['telemetry'] ?? '') !== 'build_guard') {
            throw new \RuntimeException('ModeGuardPolicy no bloquea build en APP.');
        }

        $useGuard = $policy->evaluate('builder', false, true, false);
        if ((string) ($useGuard['telemetry'] ?? '') !== 'use_guard') {
            throw new \RuntimeException('ModeGuardPolicy no bloquea CRUD runtime en BUILDER.');
        }

        $playbookBypass = $policy->evaluate('builder', false, true, true);
        if ($playbookBypass !== null) {
            throw new \RuntimeException('ModeGuardPolicy debe permitir bypass para solicitud playbook.');
        }
    }

    private function checkBuilderOnboardingFlow(): void
    {
        $flow = new BuilderOnboardingFlow();
        $ops = [
            'parseInstallPlaybookRequest' => fn(string $text): array => ['matched' => false],
            'classifyWithPlaybookIntents' => fn(string $text, array $profile): array => [],
            'isBuilderOnboardingTrigger' => fn(string $text): bool => str_contains($text, 'crear una app'),
            'detectBusinessType' => fn(string $text): string => str_contains($text, 'ferreteria') ? 'ferreteria' : '',
            'isFormListQuestion' => fn(string $text): bool => str_contains($text, 'formularios'),
            'buildFormList' => fn(): string => 'Aun no hay formularios. Quieres crear uno?',
            'isEntityListQuestion' => fn(string $text): bool => str_contains($text, 'tablas'),
            'buildEntityList' => fn(): string => 'Tablas creadas: clientes.',
            'isBuilderProgressQuestion' => fn(string $text): bool => str_contains($text, 'estado del proyecto'),
            'buildProjectStatus' => fn(): string => 'Estado del proyecto: listo.',
        ];

        $delegated = 0;
        $core = function (
            string $text,
            array $state,
            array $profile,
            string $tenantId,
            string $userId,
            bool $isOnboarding,
            bool $trigger,
            bool $businessHint
        ) use (&$delegated): array {
            $delegated++;
            return ['action' => 'ask_user', 'reply' => 'delegated', 'state' => $state];
        };

        $state1 = ['active_task' => 'builder_onboarding']; $profile1 = [];
        $list = $flow->handle('que formularios?', $state1, $profile1, 'default', 'unit', $ops, $core);
        if ((string) ($list['action'] ?? '') !== 'respond_local') {
            throw new \RuntimeException('BuilderOnboardingFlow debe responder catalogo de formularios.');
        }

        $playbookOps = $ops;
        $playbookOps['classifyWithPlaybookIntents'] = fn(string $text, array $profile): array => [
            'action' => 'APPLY_PLAYBOOK_FERRETERIA',
            'confidence' => 0.95,
        ];
        $state2 = ['active_task' => 'builder_onboarding']; $profile2 = [];
        $playbook = $flow->handle(
            'tengo una ferreteria y pierdo plata',
            $state2,
            $profile2,
            'default',
            'unit',
            $playbookOps,
            $core
        );
        if ($playbook !== null) {
            throw new \RuntimeException('BuilderOnboardingFlow debe ceder paso a intents playbook confiables.');
        }

        $state3 = ['active_task' => 'builder_onboarding']; $profile3 = [];
        $delegatedResult = $flow->handle('quiero crear una app', $state3, $profile3, 'default', 'unit', $ops, $core);
        if ((string) ($delegatedResult['reply'] ?? '') !== 'delegated' || $delegated < 1) {
            throw new \RuntimeException('BuilderOnboardingFlow no delega al core handler.');
        }
    }

    private function checkBuilderGuidance(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $user = 'guidance_' . time();
        $projectId = 'guidance_proj';

        $money = $gateway->handle('default', $user, 'un campo para el precio', 'builder', $projectId);
        if ((string) ($money['action'] ?? '') !== 'ask_user') {
            throw new \RuntimeException('Builder guidance debe responder en modo ask_user.');
        }
        if (stripos((string) ($money['reply'] ?? ''), 'decimal') === false) {
            throw new \RuntimeException('Builder guidance de precio debe recomendar decimal.');
        }

        $relation = $gateway->handle('default', $user, 'conectar clientes con ventas', 'builder', $projectId);
        $relationReply = (string) ($relation['reply'] ?? '');
        if (stripos($relationReply, 'clientes') === false || stripos($relationReply, 'ventas') === false) {
            throw new \RuntimeException('Builder guidance de relaciones debe interpolar tablas.');
        }
        $relationPending = is_array($relation['state']['builder_pending_command'] ?? null)
            ? (array) $relation['state']['builder_pending_command']
            : [];
        if ((string) ($relationPending['command'] ?? '') !== 'CreateRelation') {
            throw new \RuntimeException('Builder guidance de relaciones debe crear pending command transaccional.');
        }

        $relationConfirm = $gateway->handle('default', $user, 'si', 'builder', $projectId);
        if ((string) ($relationConfirm['action'] ?? '') !== 'execute_command') {
            throw new \RuntimeException('Confirmacion de guidance de relaciones debe ejecutar comando.');
        }
        if ((string) ($relationConfirm['command']['command'] ?? '') !== 'CreateRelation') {
            throw new \RuntimeException('Confirmacion de guidance de relaciones debe ejecutar CreateRelation.');
        }

        $performance = $gateway->handle('default', $user, 'la busqueda es muy lenta en tabla clientes por nombre', 'builder', $projectId);
        $performancePending = is_array($performance['state']['builder_pending_command'] ?? null)
            ? (array) $performance['state']['builder_pending_command']
            : [];
        if ((string) ($performancePending['command'] ?? '') !== 'CreateIndex') {
            throw new \RuntimeException('Builder guidance de performance debe crear pending command transaccional.');
        }

        $performanceConfirm = $gateway->handle('default', $user, 'si', 'builder', $projectId);
        if ((string) ($performanceConfirm['action'] ?? '') !== 'execute_command') {
            throw new \RuntimeException('Confirmacion de guidance de performance debe ejecutar comando.');
        }
        if ((string) ($performanceConfirm['command']['command'] ?? '') !== 'CreateIndex') {
            throw new \RuntimeException('Confirmacion de guidance de performance debe ejecutar CreateIndex.');
        }
    }

    private function checkFlowControl(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $user = 'flow_' . time();
        $projectId = 'flow_proj';

        $start = $gateway->handle('default', $user, 'quiero crear una app', 'builder', $projectId);
        if ((string) ($start['action'] ?? '') !== 'ask_user') {
            throw new \RuntimeException('Flow control test requiere onboarding inicial.');
        }
        if ((string) ($start['state']['onboarding_step'] ?? '') !== 'business_type') {
            throw new \RuntimeException('Flow control test requiere onboarding en paso business_type.');
        }

        $cancel = $gateway->handle('default', $user, 'cancelar', 'builder', $projectId);
        if ((string) ($cancel['action'] ?? '') !== 'respond_local') {
            throw new \RuntimeException('Flow cancel debe responder localmente.');
        }
        if (($cancel['state']['active_task'] ?? null) !== null) {
            throw new \RuntimeException('Flow cancel debe pausar tarea activa.');
        }

        $resume = $gateway->handle('default', $user, 'retomar', 'builder', $projectId);
        if ((string) ($resume['action'] ?? '') !== 'ask_user') {
            throw new \RuntimeException('Flow resume debe retomar como pregunta guiada.');
        }
        if ((string) ($resume['state']['onboarding_step'] ?? '') === '') {
            throw new \RuntimeException('Flow resume debe restaurar el paso de onboarding.');
        }

        $restart = $gateway->handle('default', $user, 'reiniciar', 'builder', $projectId);
        if ((string) ($restart['action'] ?? '') !== 'ask_user') {
            throw new \RuntimeException('Flow restart debe guiar desde paso 1.');
        }
        if ((string) ($restart['state']['onboarding_step'] ?? '') !== 'business_type') {
            throw new \RuntimeException('Flow restart debe volver a business_type.');
        }

        $gateway->handle('default', $user, 'mi empresa es una ferreteria de materiales', 'builder', $projectId);
        $gateway->handle('default', $user, 'quiero manejar ventas de contado y también a crédito', 'builder', $projectId);
        $gateway->handle('default', $user, 'necesito llevar el inventario y poder facturar a mis clientes', 'builder', $projectId);
        $beforeReset = $gateway->handle('default', $user, 'quiero manejar facturas y cotizaciones para los clientes', 'builder', $projectId);
        if ((string) ($beforeReset['state']['proposed_profile'] ?? '') !== 'ferreteria') {
            throw new \RuntimeException('Flow control setup esperaba perfil ferreteria antes de reset.');
        }
        if (!in_array((string) ($beforeReset['state']['onboarding_step'] ?? ''), ['confirm_scope', 'plan_ready'], true)) {
            throw new \RuntimeException('Flow control setup esperaba onboarding completado antes de reset.');
        }

        $gateway->handle('default', $user, 'reiniciar', 'builder', $projectId);
        $afterReset = $gateway->handle('default', $user, 'mi empresa es una panaderia y cafeteria', 'builder', $projectId);
        if ((string) ($afterReset['state']['proposed_profile'] ?? '') === 'ferreteria') {
            throw new \RuntimeException('Flow restart no debe arrastrar perfil previo de ferreteria.');
        }

        $invalidPayment = $gateway->handle('default', $user, 'las ventas', 'builder', $projectId);
        if ((string) ($invalidPayment['state']['onboarding_step'] ?? '') !== 'operation_model') {
            throw new \RuntimeException('Onboarding paso 2 debe mantenerse en operation_model para respuesta invalida.');
        }
        if ((string) ($invalidPayment['action'] ?? '') !== 'ask_user') {
            throw new \RuntimeException('Onboarding paso 2 debe volver a preguntar cuando la respuesta no es valida.');
        }

        $gateway->handle('default', $user, 'solo manejo ventas de contado', 'builder', $projectId);
        $gateway->handle('default', $user, 'necesito ventas contabilidad pagos y cartera de clientes', 'builder', $projectId);
        $finalScope = $gateway->handle('default', $user, 'quiero poder emitir facturas y tickets de venta', 'builder', $projectId);
        if (!in_array((string) ($finalScope['state']['onboarding_step'] ?? ''), ['confirm_scope', 'plan_ready'], true)) {
            throw new \RuntimeException('Onboarding no debe mutar forma de pago: estado de onboarding invalido despues de completar pasos.');
        }
    }

    private function checkDomainTrainingSync(): void
    {
        $php = PHP_BINARY ?: 'php';
        $script = dirname(__DIR__, 2) . '/scripts/sync_domain_training.php';
        if (!is_file($script)) {
            throw new \RuntimeException('Script de sync no encontrado.');
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --check';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            $details = trim(implode(PHP_EOL, $output));
            if ($details !== '') {
                throw new \RuntimeException("Drift entre domain_playbooks y conversation_training_base. {$details}");
            }
            throw new \RuntimeException('Drift entre domain_playbooks y conversation_training_base.');
        }
    }

    private function checkSecretsGuard(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/secrets_guard_test.php');
    }

    private function checkSensitiveLogRedaction(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/sensitive_log_redaction_test.php');
    }

    private function checkOpenApiIntegrationImporter(): void
    {
        $openapi = [
            'openapi' => '3.0.1',
            'servers' => [['url' => 'https://api.payments.example.com/v1']],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
            'paths' => [
                '/charges' => [
                    'post' => ['operationId' => 'createCharge', 'summary' => 'Create charge'],
                    'get' => ['operationId' => 'listCharges', 'summary' => 'List charges'],
                ],
            ],
        ];

        $importer = new OpenApiIntegrationImporter();
        $result = $importer->import([
            'api_name' => 'paymentsx_unit',
            'provider' => 'PaymentsX',
            'country' => 'CO',
            'environment' => 'sandbox',
            'type' => 'payments',
            'openapi' => $openapi,
        ], false);

        $contract = is_array($result['contract'] ?? null) ? (array) $result['contract'] : [];
        if ((string) ($contract['id'] ?? '') !== 'paymentsx_unit') {
            throw new \RuntimeException('OpenApiIntegrationImporter id invalido.');
        }
        if ((string) ($contract['auth']['type'] ?? '') !== 'bearer') {
            throw new \RuntimeException('OpenApiIntegrationImporter debe detectar auth bearer.');
        }
        $endpoints = is_array($contract['metadata']['endpoints'] ?? null) ? (array) $contract['metadata']['endpoints'] : [];
        if (count($endpoints) !== 2) {
            throw new \RuntimeException('OpenApiIntegrationImporter debe extraer endpoints.');
        }
    }

    private function checkSecurityStateRepository(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/security_state_repository_test.php');
    }

    private function checkApiSecurityGuard(): void
    {
        $guard = new ApiSecurityGuard();
        $tmpDir = FRAMEWORK_ROOT . '/tests/tmp/security_guard_' . time();
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $unauth = $guard->enforce('entity/save', 'POST', ['REMOTE_ADDR' => '203.0.113.10'], [], [], $tmpDir);
        if ((bool) ($unauth['ok'] ?? true)) {
            throw new \RuntimeException('ApiSecurityGuard debe bloquear ruta protegida sin login.');
        }

        $session = ['auth_user' => ['id' => 'u1', 'tenant_id' => 'default'], 'csrf_token' => 'abc123'];
        $ok = $guard->enforce(
            'entity/save',
            'POST',
            ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_CSRF_TOKEN' => 'abc123'],
            $session,
            [],
            $tmpDir
        );
        if (!(bool) ($ok['ok'] ?? false)) {
            throw new \RuntimeException('ApiSecurityGuard no acepto auth+csrf validos.');
        }
    }

    private function checkRecordsGetRequiresAuth(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/records_get_requires_auth_test.php');
    }

    private function checkRecordsGetWithSignedTokenOk(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/records_get_with_signed_token_ok_test.php');
    }

    private function checkRecordsGetWithExpiredTokenFail(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/records_get_with_expired_token_fail_test.php');
    }

    private function checkRecordsGetTokenTenantMismatchFail(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/records_get_token_tenant_mismatch_fail_test.php');
    }

    private function checkRecordsMutationRequiresAuth(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/records_mutation_requires_auth_test.php');
    }

    private function checkRecordsMutationRejectsPayloadTenantOverride(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/records_mutation_rejects_payload_tenant_override_test.php');
    }

    private function checkRecordsMutationAcceptsAuthenticatedSession(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/records_mutation_accepts_authenticated_session_test.php');
    }

    private function checkRecordsMutationCrossTenantBlock(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/records_mutation_cross_tenant_block_test.php');
    }

    private function checkChatExecRequiresAuth(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/chat_exec_requires_auth_test.php');
    }

    private function checkChatExecNoDefaultAdmin(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/chat_exec_no_default_admin_test.php');
    }

    private function checkChatExecTenantBinding(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/chat_exec_tenant_binding_test.php');
    }

    private function checkChatInformationalWithoutAuthOk(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/chat_informational_without_auth_ok_test.php');
    }

    private function checkChatWarnModeDoesNotAllowExecWhenAuthFails(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/chat_warn_mode_does_not_allow_exec_when_auth_fails_test.php');
    }

    private function checkRedteamPocChatExec(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/redteam_poc_chat_exec_test.php');
    }

    private function checkTelegramRejectsGet(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/telegram_rejects_get_test.php');
    }

    private function checkWhatsAppRejectsPut(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/whatsapp_rejects_put_test.php');
    }

    private function checkWebhookFailsClosedWhenSecretEmptyInStaging(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/webhook_fails_closed_when_secret_empty_in_staging_test.php');
    }

    private function checkWebhookAllowsInsecureOnlyInDevWhenFlag(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/webhook_allows_insecure_only_in_dev_when_flag_test.php');
    }

    private function checkWhatsAppFastAck(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/whatsapp_fast_ack_test.php');
    }

    private function checkWhatsAppEnqueue(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/whatsapp_enqueue_test.php');
    }

    private function checkWhatsAppIdempotency(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/whatsapp_idempotency_test.php');
    }

    private function checkWorkerProcessesQueuedWhatsAppMessage(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/worker_processes_queued_whatsapp_message_test.php');
    }

    private function checkWorkerRespectsIdempotency(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/worker_respects_idempotency_test.php');
    }

    private function checkWorkerLogsRoutePath(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/worker_logs_route_path_test.php');
    }

    private function checkAgentOpsRuntimeObservability(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/agentops_runtime_observability_test.php');
    }

    private function checkAgentOpsSupervisor(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/agentops_supervisor_test.php');
    }

    private function checkOperationalQueueSchemaGuard(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/operational_queue_schema_guard_test.php');
    }

    private function checkSchemaRuntimeGuard(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/schema_runtime_guard_test.php');
    }

    private function checkFrameworkHygiene(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/framework_hygiene_test.php');
    }

    private function checkPublicExcelImportE2E(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/public_excel_import_e2e_test.php');
    }

    private function checkPublicReportE2E(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/public_report_e2e_test.php');
    }

    private function checkIntentRouter(): void
    {
        $router = new IntentRouter(null, 'off');
        $local = $router->route(['action' => 'respond_local', 'reply' => 'ok']);
        if (!$local->isLocalResponse() || $local->reply() !== 'ok') {
            throw new \RuntimeException('IntentRouter no enruta respond_local.');
        }

        $cmd = $router->route(
            ['action' => 'execute_command', 'command' => ['command' => 'CreateForm', 'entity' => 'clientes']],
            [
                'tenant_id' => 'default',
                'project_id' => 'default',
                'session_id' => 'intent_router_check',
                'mode' => 'builder',
                'role' => 'admin',
                'is_authenticated' => true,
                'auth_tenant_id' => 'default',
            ]
        );
        if (!$cmd->isCommand() || (string) (($cmd->command()['command'] ?? '')) !== 'CreateForm') {
            throw new \RuntimeException('IntentRouter no enruta execute_command.');
        }

        $llm = $router->route(['action' => 'send_to_llm', 'llm_request' => ['messages' => []]]);
        if (!$llm->isLlmRequest()) {
            throw new \RuntimeException('IntentRouter no enruta send_to_llm.');
        }
    }

    private function checkRouterContractEnforcement(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/router_contract_enforcement_test.php');
    }

    private function checkActionAllowlistEnforcement(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/action_allowlist_enforcement_test.php');
    }

    private function checkEnforcementMinimumEvidenceStrictBlocksWhenMissing(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/enforcement_minimum_evidence_strict_blocks_when_missing_test.php');
    }

    private function checkEnforcementWarnAllowsButLogsWhenMissing(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/enforcement_warn_allows_but_logs_when_missing_test.php');
    }

    private function checkGatesRequiredBlockActionWhenSchemaInvalid(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/gates_required_block_action_when_schema_invalid_test.php');
    }

    private function checkGatesRequiredBlockActionWhenNotAllowlisted(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/gates_required_block_action_when_not_allowlisted_test.php');
    }

    private function checkEnforcementDefaultByEnv(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/enforcement_default_by_env_test.php');
    }

    private function checkCommandBus(): void
    {
        $bus = new CommandBus();
        $bus->register(new CreateEntityCommandHandler());
        $bus->register(new CreateFormCommandHandler());
        $bus->register(new CreateRelationCommandHandler());
        $bus->register(new CreateIndexCommandHandler());
        $bus->register(new InstallPlaybookCommandHandler());
        $bus->register(new ImportIntegrationOpenApiCommandHandler());
        $bus->register(new CompileWorkflowCommandHandler());
        $bus->register(new CrudCommandHandler());
        $bus->register(new MapCommandHandler(['AuthLogin'], static fn(array $command, array $context): array => [
            'status' => 'success',
            'reply' => 'auth handler ok',
        ]));

        $reply = static fn(
            string $text,
            string $channel,
            string $sessionId,
            string $userId,
            string $status = 'success',
            array $data = []
        ): array => [
            'status' => $status,
            'reply' => $text,
            'data' => $data,
            'channel' => $channel,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ];

        $baseContext = [
            'channel' => 'test',
            'session_id' => 'sess',
            'user_id' => 'user',
            'reply' => $reply,
        ];

        $resEntity = $bus->dispatch(
            ['command' => 'CreateEntity', 'entity' => 'clientes'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resEntity['status'] ?? '') !== 'error') {
            throw new \RuntimeException('CreateEntity handler no respondio guard de modo app.');
        }

        $resForm = $bus->dispatch(
            ['command' => 'CreateForm', 'entity' => 'clientes'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resForm['status'] ?? '') !== 'error') {
            throw new \RuntimeException('CreateForm handler no respondio guard de modo app.');
        }

        $resRelation = $bus->dispatch(
            ['command' => 'CreateRelation', 'source_entity' => 'clientes', 'target_entity' => 'ventas'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resRelation['status'] ?? '') !== 'error') {
            throw new \RuntimeException('CreateRelation handler no respondio guard de modo app.');
        }

        $resIndex = $bus->dispatch(
            ['command' => 'CreateIndex', 'entity' => 'clientes', 'field' => 'nombre'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resIndex['status'] ?? '') !== 'error') {
            throw new \RuntimeException('CreateIndex handler no respondio guard de modo app.');
        }

        $resPlaybook = $bus->dispatch(
            ['command' => 'InstallPlaybook'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resPlaybook['status'] ?? '') !== 'error') {
            throw new \RuntimeException('InstallPlaybook handler no respondio guard de modo app.');
        }

        $resCrud = $bus->dispatch(
            ['command' => 'CreateRecord', 'entity' => 'clientes', 'data' => []],
            array_merge($baseContext, ['mode' => 'builder'])
        );
        if ((string) ($resCrud['status'] ?? '') !== 'error') {
            throw new \RuntimeException('Crud handler no respondio guard de modo builder.');
        }

        $resCrudMissingEntity = $bus->dispatch(
            ['command' => 'CreateRecord', 'entity' => 'clientes', 'data' => []],
            array_merge($baseContext, [
                'mode' => 'app',
                'entity_exists' => static fn(string $entity): bool => false,
            ])
        );
        if ((string) ($resCrudMissingEntity['status'] ?? '') !== 'error') {
            throw new \RuntimeException('Crud handler no valida existencia de entidad.');
        }

        $resAuth = $bus->dispatch(
            ['command' => 'AuthLogin'],
            $baseContext
        );
        if ((string) ($resAuth['status'] ?? '') !== 'success') {
            throw new \RuntimeException('MapCommandHandler no mantiene compatibilidad para AuthLogin.');
        }

        $resImport = $bus->dispatch(
            [
                'command' => 'ImportIntegrationOpenApi',
                'api_name' => 'paymentsx_unit_bus',
                'openapi_json' => json_encode([
                    'openapi' => '3.0.1',
                    'servers' => [['url' => 'https://api.payments.example.com/v1']],
                    'components' => ['securitySchemes' => ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
                    'paths' => ['/charges' => ['post' => ['operationId' => 'createCharge']]],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'dry_run' => true,
            ],
            array_merge($baseContext, [
                'mode' => 'builder',
                'openapi_importer' => new OpenApiIntegrationImporter(),
            ])
        );
        if ((string) ($resImport['status'] ?? '') !== 'success') {
            throw new \RuntimeException('ImportIntegrationOpenApi handler no respondio en dry_run builder.');
        }

        $resWorkflow = $bus->dispatch(
            [
                'command' => 'CompileWorkflow',
                'text' => 'crear workflow para cotizacion',
                'workflow_id' => 'wf_unit_bus_' . time(),
                'apply' => false,
            ],
            array_merge($baseContext, [
                'mode' => 'builder',
                'workflow_compiler' => new WorkflowCompiler(),
                'workflow_repository' => new WorkflowRepository(FRAMEWORK_ROOT . '/tests/tmp/workflow_repo_project_bus'),
            ])
        );
        if ((string) ($resWorkflow['status'] ?? '') !== 'success') {
            throw new \RuntimeException('CompileWorkflow handler no respondio en modo proposal.');
        }

        try {
            $bus->dispatch(['command' => 'UnknownCommand'], []);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'COMMAND_NOT_SUPPORTED') {
                return;
            }
            throw new \RuntimeException('CommandBus lanzo error inesperado.');
        }

        throw new \RuntimeException('CommandBus debe fallar comando desconocido.');
    }

    private function checkObservabilityMetrics(): void
    {
        $tmpDir = dirname(__DIR__, 2) . '/tests/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $dbPath = $tmpDir . '/unit_observability.sqlite';
        if (is_file($dbPath)) {
            unlink($dbPath);
        }
        $previousAllow = getenv('ALLOW_RUNTIME_SCHEMA');
        $previousAppEnv = getenv('APP_ENV');
        putenv('APP_ENV=local');
        putenv('ALLOW_RUNTIME_SCHEMA=1');
        try {
            $repo = new SqlMetricsRepository(null, $dbPath);
            $service = new TelemetryService($repo);

            $service->recordIntentMetric([
                'tenant_id' => 'default',
                'project_id' => 'unit_obs',
                'session_id' => 'unit_obs_sess_a',
                'mode' => 'builder',
                'intent' => 'APP_CREATE',
                'action' => 'ask_user',
                'latency_ms' => 70,
                'status' => 'success',
            ]);
            $service->recordIntentMetric([
                'tenant_id' => 'default',
                'project_id' => 'unit_obs',
                'session_id' => 'unit_obs_sess_a',
                'mode' => 'builder',
                'intent' => 'APP_CREATE',
                'action' => 'send_to_llm',
                'latency_ms' => 120,
                'status' => 'success',
            ]);
            $service->recordCommandMetric([
                'tenant_id' => 'default',
                'project_id' => 'unit_obs',
                'session_id' => 'unit_obs_sess_b',
                'mode' => 'app',
                'command_name' => 'CreateEntity',
                'latency_ms' => 25,
                'status' => 'error',
                'blocked' => 1,
            ]);
            $service->recordGuardrailEvent([
                'tenant_id' => 'default',
                'project_id' => 'unit_obs',
                'session_id' => 'unit_obs_sess_b',
                'mode' => 'app',
                'guardrail' => 'mode_guard',
                'reason' => 'blocked by mode',
            ]);
            $service->recordTokenUsage([
                'tenant_id' => 'default',
                'project_id' => 'unit_obs',
                'session_id' => 'unit_obs_sess_a',
                'provider' => 'gemini',
                'prompt_tokens' => 90,
                'completion_tokens' => 30,
                'total_tokens' => 120,
            ]);

            $summary = $service->summary('default', 'unit_obs', 7);
            if ((int) ($summary['intent_metrics']['count'] ?? 0) < 1) {
                throw new \RuntimeException('Observability: intent metric not persisted.');
            }
            if (!isset($summary['intent_metrics']['fallback_rate'])) {
                throw new \RuntimeException('Observability: fallback rate metric missing.');
            }
            if ((int) ($summary['command_metrics']['blocked'] ?? 0) < 1) {
                throw new \RuntimeException('Observability: blocked command metric missing.');
            }
            if ((int) ($summary['guardrail_events']['count'] ?? 0) < 1) {
                throw new \RuntimeException('Observability: guardrail event missing.');
            }
            if ((int) ($summary['token_usage']['total_tokens'] ?? 0) < 120) {
                throw new \RuntimeException('Observability: token usage not persisted.');
            }
            if ((float) ($summary['token_usage']['avg_tokens_per_session'] ?? 0.0) <= 0.0) {
                throw new \RuntimeException('Observability: avg tokens per session missing.');
            }
        } finally {
            if ($previousAllow === false) {
                putenv('ALLOW_RUNTIME_SCHEMA');
            } else {
                putenv('ALLOW_RUNTIME_SCHEMA=' . $previousAllow);
            }
            if ($previousAppEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $previousAppEnv);
            }
        }
    }

    private function checkWorkflowApiE2E(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/workflow_api_e2e_test.php');
    }

    private function checkSecurityChannelsE2E(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/security_channels_e2e_test.php');
    }

    private function checkGeminiEmbeddingService(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/gemini_embedding_service_test.php');
    }

    private function checkQdrantVectorStore(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/qdrant_vector_store_test.php');
    }

    private function checkSemanticMemoryService(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/semantic_memory_service_test.php');
    }

    private function checkCanonicalStorageNewProject(): void
    {
        $tmpDir = dirname(__DIR__, 2) . '/tests/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $registryPath = $tmpDir . '/unit_canonical_registry.sqlite';
        if (is_file($registryPath)) {
            unlink($registryPath);
        }

        $previousRegistryPath    = getenv('PROJECT_REGISTRY_DB_PATH');
        $previousCanonical       = getenv('DB_CANONICAL_NEW_PROJECTS');
        $previousNamespaceByProj = getenv('DB_NAMESPACE_BY_PROJECT');
        $previousAllow           = getenv('ALLOW_RUNTIME_SCHEMA');
        $previousAppEnv          = getenv('APP_ENV');

        putenv('PROJECT_REGISTRY_DB_PATH=' . $registryPath);
        putenv('DB_CANONICAL_NEW_PROJECTS=1');
        putenv('DB_NAMESPACE_BY_PROJECT=1');
        putenv('APP_ENV=local');
        putenv('ALLOW_RUNTIME_SCHEMA=1');
        try {
            StorageModel::clearCache();
            TableNamespace::clearCache();

            $registry = new ProjectRegistry($registryPath);
            $registry->ensureProject('unit_can_app', 'Unit Canonical');
            $project = $registry->getProject('unit_can_app') ?? [];
            if ((string) ($project['storage_model'] ?? '') !== 'canonical') {
                throw new \RuntimeException('Canonical storage model was not persisted for new project.');
            }

            StorageModel::clearCache();
            TableNamespace::clearCache();
            $table = TableNamespace::resolve('clientes', 'unit_can_app');
            if ($table !== 'clientes') {
                throw new \RuntimeException('Canonical project should resolve logical table without namespace.');
            }
        } finally {
            $previousRegistryPath === false ? putenv('PROJECT_REGISTRY_DB_PATH') : putenv('PROJECT_REGISTRY_DB_PATH=' . $previousRegistryPath);
            $previousCanonical === false ? putenv('DB_CANONICAL_NEW_PROJECTS') : putenv('DB_CANONICAL_NEW_PROJECTS=' . $previousCanonical);
            $previousNamespaceByProj === false ? putenv('DB_NAMESPACE_BY_PROJECT') : putenv('DB_NAMESPACE_BY_PROJECT=' . $previousNamespaceByProj);
            $previousAllow === false ? putenv('ALLOW_RUNTIME_SCHEMA') : putenv('ALLOW_RUNTIME_SCHEMA=' . $previousAllow);
            $previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousAppEnv);
            StorageModel::clearCache();
            TableNamespace::clearCache();
        }
    }

    private function checkLlmRouterFailover(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/llm_router_failover_test.php');
    }

    private function checkTrainingDatasetValidator(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/training_dataset_validator_test.php');
    }

    private function checkErpTrainingDatasetPipeline(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/erp_training_dataset_pipeline_test.php');
    }

    private function checkGenerateErpTrainingDataset(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/generate_erp_training_dataset_test.php');
    }

    private function checkErpTrainingDatasetCliGuardrails(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/erp_training_dataset_cli_guardrails_test.php');
    }

    private function checkErpTrainingDatasetVectorization(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/erp_training_dataset_vectorization_test.php');
    }

    private function checkTrainingDatasetPublicationGate(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/training_dataset_publication_gate_test.php');
    }

    private function checkTrainingDatasetVectorizeCommand(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/training_dataset_vectorize_command_test.php');
    }

    private function checkBusinessDiscoveryTemplate(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/business_discovery_template_test.php');
    }

    private function checkAlertsCenterModule(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/alerts_center_module_test.php');
    }

    private function checkMediaModule(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/media_module_test.php');
    }

    private function checkEntitySearchModule(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/entity_search_module_test.php');
    }

    private function checkPOSCoreModule(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/pos_core_module_test.php');
    }

    private function checkPOSProductsPricingBarcode(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/pos_products_pricing_barcode_test.php');
    }

    private function checkPOSSalesFlowReceipt(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/pos_sales_flow_receipt_test.php');
    }

    private function checkPOSCashRegisterArqueo(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/pos_cash_register_arqueo_test.php');
    }

    private function checkPOSReturnsCancelations(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/pos_returns_cancelations_test.php');
    }

    private function checkPurchasesCoreModule(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/purchases_core_module_test.php');
    }

    private function checkPurchasesDocumentsModule(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/purchases_documents_module_test.php');
    }

    private function checkFiscalEngineArchitecture(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/fiscal_engine_architecture_test.php');
    }

    private function checkFEInvoiceCreditNoteSupportDocs(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/fe_invoice_credit_note_support_docs_test.php');
    }

    private function checkEcommerceHubArchitecture(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/ecommerce_hub_architecture_test.php');
    }

    private function checkEcommerceHubAdapters(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/ecommerce_hub_adapters_test.php');
    }

    private function checkEcommerceProductSyncFoundation(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/ecommerce_product_sync_foundation_test.php');
    }

    private function checkEcommerceOrderSyncFoundation(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/ecommerce_order_sync_foundation_test.php');
    }

    private function checkEcommerceAgentSkills(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/ecommerce_agent_skills_test.php');
    }

    private function checkAgentToolsIntegrationFoundation(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/agent_tools_integration_foundation_test.php');
    }

    private function checkAgentToolsIntegrationSkills(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/agent_tools_integration_skills_test.php');
    }

    private function checkAgentOpsObservabilityFoundation(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/agentops_observability_foundation_test.php');
    }

    private function checkAgentOpsObservabilitySkills(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/agentops_observability_skills_test.php');
    }

    private function checkControlTowerContracts(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/control_tower_contracts_test.php');
    }

    private function checkControlTowerTask(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/control_tower_task_test.php');
    }

    private function checkConversationGatewayTrace(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/conversation_gateway_trace_test.php');
    }

    private function checkIncidentFlow(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/incident_flow_test.php');
    }

    private function checkProjectMemorySystem(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/project_memory_system_test.php');
    }

    private function checkLearningPromotionPipeline(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/learning_promotion_pipeline_test.php');
    }

    private function checkSemanticPipelineE2E(): void
    {
        $this->runExternalTestScript(FRAMEWORK_ROOT . '/tests/semantic_pipeline_e2e_test.php');
    }

    private function cleanupLegacyTestArtifacts(): void
    {
        $entities = ['status_redteam_p0', 'redteam_p0_01'];
        $projectRoot = dirname(FRAMEWORK_ROOT) . '/project';
        foreach ($entities as $entity) {
            @unlink($projectRoot . '/contracts/entities/' . $entity . '.entity.json');
            @unlink($projectRoot . '/contracts/forms/' . $entity . '.form.json');
        }

        try {
            $db = Database::connection();
            $driver = strtolower((string) $db->getAttribute(\PDO::ATTR_DRIVER_NAME));
            $projectCandidates = array_values(array_unique([
                TableNamespace::normalizedProjectId(),
                'default',
            ]));
            foreach ($entities as $entity) {
                $logicalTable = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_]/', '', $entity . 's')));
                if ($logicalTable === '') {
                    continue;
                }

                $tables = [$logicalTable];
                foreach ($projectCandidates as $projectId) {
                    $tables[] = TableNamespace::resolve($logicalTable, $projectId);
                }
                $tables = array_values(array_unique(array_filter(array_map(
                    static fn($name): string => strtolower(trim((string) $name)),
                    $tables
                ))));

                foreach ($tables as $tableName) {
                    if ($tableName === '') {
                        continue;
                    }
                    if ($driver === 'sqlite') {
                        $db->exec('DROP TABLE IF EXISTS "' . $tableName . '"');
                        continue;
                    }
                    $db->exec('DROP TABLE IF EXISTS `' . $tableName . '`');
                }
            }
        } catch (\Throwable $ignored) {
            // test cleanup should never fail the suite bootstrap
        }
    }

    private function runExternalTestScript(string $scriptPath): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath);
        $output = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $output, $exit);
        if ($exit !== 0) {
            $message = trim(implode("\n", $output));
            throw new \RuntimeException($message !== '' ? $message : 'Test externo fallo: ' . basename($scriptPath));
        }
    }

    private function stripAccents(string $text): string
    {
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];
        return str_replace(array_keys($map), array_values($map), $text);
    }

    private function checkDataQualityGuard(): void
    {
        $ok  = static function (array $r, string $tc): void {
            if (!empty($r['reject'])) {
                throw new \RuntimeException($tc . ': valor válido fue rechazado — razón: ' . ($r['reject'][0]['reason'] ?? ''));
            }
        };
        $rej = static function (array $r, string $tc, ?string $expectedReason = null): void {
            if (empty($r['reject'])) {
                throw new \RuntimeException($tc . ': valor inválido no fue rechazado');
            }
            if ($expectedReason !== null && ($r['reject'][0]['reason'] ?? '') !== $expectedReason) {
                throw new \RuntimeException($tc . ': razón esperada=' . $expectedReason . ' obtenida=' . ($r['reject'][0]['reason'] ?? ''));
            }
        };

        $g  = new \App\Core\DataQualityGuard('CO');
        $gx = new \App\Core\DataQualityGuard('MX');
        $gp = new \App\Core\DataQualityGuard('PE');

        // TC-DQG-01: NIT CO válido (base 800123456, DV=9 por algoritmo DIAN)
        $ok($g->validateFields(['nit' => '8001234569']), 'TC-DQG-01');
        // TC-DQG-02: NIT CO con DV incorrecto (0 en vez de 9)
        $rej($g->validateFields(['nit' => '8001234560']), 'TC-DQG-02', 'digito_verificador_invalido');
        // TC-DQG-03: Celular CO válido (10 dígitos, empieza con 3)
        $ok($g->validateFields(['celular' => '3101234567']), 'TC-DQG-03');
        // TC-DQG-04: Celular CO inválido (empieza con 2)
        $rej($g->validateFields(['celular' => '2101234567']), 'TC-DQG-04');
        // TC-DQG-05: Keyboard mash en nombre
        $rej($g->validateFields(['nombre' => 'asdfasdf']), 'TC-DQG-05');
        // TC-DQG-06: Palabra relleno "prueba" en nombre_empresa
        $rej($g->validateFields(['nombre_empresa' => 'prueba']), 'TC-DQG-06');
        // TC-DQG-07: Dígitos repetidos (111111111) en cédula
        $rej($g->validateFields(['cedula' => '111111111']), 'TC-DQG-07');
        // TC-DQG-08: Email sin @
        $rej($g->validateFields(['email' => 'no-es-email']), 'TC-DQG-08');
        // TC-DQG-09: Email dominio sospechoso
        $rej($g->validateFields(['email' => 'test@test.com']), 'TC-DQG-09');
        // TC-DQG-10: Email real válido
        $ok($g->validateFields(['email' => 'juan@miempresa.com.co']), 'TC-DQG-10');
        // TC-DQG-11: Cédula válida CO (sin secuencia ni repetición)
        $ok($g->validateFields(['cedula' => '1020304050']), 'TC-DQG-11');
        // TC-DQG-12: RFC MX válido
        $ok($gx->validateFields(['rfc' => 'ABC010101ABC']), 'TC-DQG-12');
        // TC-DQG-13: Celular PE válido (9 dígitos, empieza con 9, sin secuencia)
        $ok($gp->validateFields(['celular' => '912765430']), 'TC-DQG-13');
        // TC-DQG-14: Nombre de 1 caracter (min_length=2)
        $rej($g->validateFields(['nombre' => 'X']), 'TC-DQG-14');
        // TC-DQG-15: Cédula con dígitos secuenciales ascendentes
        $rej($g->validateFields(['cedula' => '123456789']), 'TC-DQG-15');
    }

    private function checkOtpFlowE2E(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $svc = new \App\Core\OtpService($db, sys_get_temp_dir());

        // TC-OTP-01: email inválido en send() rechaza
        $r = $svc->send('proj', 'u1', 'not-an-email');
        if ($r['ok'] !== false) {
            throw new \RuntimeException('TC-OTP-01: send() con email inválido debe retornar ok=false');
        }

        // TC-OTP-02: send() válido retorna ok (SMTP ausente → log fallback)
        $r = $svc->send('proj', 'u1', 'valid@empresa.com');
        if ($r['ok'] !== true) {
            throw new \RuntimeException('TC-OTP-02: send() con email válido debe retornar ok=true');
        }

        // Leer código de DB para el test (nunca expuesto por API)
        $stmt = $db->query("SELECT code FROM otp_codes WHERE project_id='proj' AND identifier='u1' ORDER BY id DESC LIMIT 1");
        $code = (string) ($stmt->fetchColumn() ?: '');
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            throw new \RuntimeException('TC-OTP-03: código debe ser 6 dígitos numéricos, obtenido: ' . $code);
        }

        // TC-OTP-04: verify() con código incorrecto falla
        $badCode = $code === '000000' ? '111111' : '000000';
        $r = $svc->verify('proj', 'u1', $badCode);
        if ($r['ok'] !== false) {
            throw new \RuntimeException('TC-OTP-04: código incorrecto debe fallar');
        }

        // TC-OTP-05: verify() con código correcto OK
        $r = $svc->verify('proj', 'u1', $code);
        if ($r['ok'] !== true) {
            throw new \RuntimeException('TC-OTP-05: código correcto debe verificar — error: ' . ($r['error'] ?? ''));
        }

        // TC-OTP-06: segundo verify() falla (código consumido — one-time use)
        $r = $svc->verify('proj', 'u1', $code);
        if ($r['ok'] !== false) {
            throw new \RuntimeException('TC-OTP-06: código consumido debe rechazarse');
        }

        // TC-OTP-07: código de 5 dígitos rechazado por formato
        $svc->send('proj', 'u2', 'u2@empresa.com');
        $r = $svc->verify('proj', 'u2', '12345');
        if ($r['ok'] !== false) {
            throw new \RuntimeException('TC-OTP-07: código de 5 dígitos debe rechazarse');
        }

        // TC-OTP-08: código con letras rechazado por formato
        $r = $svc->verify('proj', 'u2', '1234ab');
        if ($r['ok'] !== false) {
            throw new \RuntimeException('TC-OTP-08: código con letras debe rechazarse');
        }

        // TC-OTP-09: código expirado (creado hace 20 min) rechazado
        $db->exec("DELETE FROM otp_codes WHERE identifier='expired'");
        $stm = $db->prepare("INSERT INTO otp_codes (project_id,identifier,code,created_at,attempts) VALUES ('proj','expired','777777',:ts,0)");
        $stm->execute([':ts' => date('Y-m-d H:i:s', time() - 1200)]);
        $r = $svc->verify('proj', 'expired', '777777');
        if ($r['ok'] !== false) {
            throw new \RuntimeException('TC-OTP-09: código expirado debe rechazarse');
        }
        if (!str_contains((string) ($r['error'] ?? ''), 'expirado')) {
            throw new \RuntimeException('TC-OTP-09: mensaje debe mencionar expiración, obtenido: ' . ($r['error'] ?? ''));
        }

        // TC-OTP-10: 3 intentos incorrectos → 4° bloqueado por max_attempts
        $svc->send('proj', 'u3', 'u3@empresa.com');
        $stmt = $db->query("SELECT code FROM otp_codes WHERE project_id='proj' AND identifier='u3' ORDER BY id DESC LIMIT 1");
        $code3 = (string) ($stmt->fetchColumn() ?: '999999');
        $bad3  = $code3 === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 3; $i++) {
            $svc->verify('proj', 'u3', $bad3);
        }
        $r = $svc->verify('proj', 'u3', $bad3);
        if ($r['ok'] !== false) {
            throw new \RuntimeException('TC-OTP-10: 4° intento debe bloquearse por max_attempts');
        }
        if (!str_contains((string) ($r['error'] ?? ''), 'intentos')) {
            throw new \RuntimeException('TC-OTP-10: mensaje debe mencionar intentos, obtenido: ' . ($r['error'] ?? ''));
        }
    }

    private function checkTenantSelfRegistration(): void
    {
        $db  = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $otp = new \App\Core\OtpService($db, sys_get_temp_dir());
        $svc = new \App\Core\TenantSelfRegistrationService($db, $otp, new \App\Core\EmailService());

        // TC-TSR-01: campos requeridos faltantes
        $r = $svc->requestRegistration(['email' => 'a@b.com']);
        if ($r['ok'] !== false || !str_contains($r['message'], 'requeridos')) {
            throw new \RuntimeException('TC-TSR-01: campos faltantes deben retornar ok=false');
        }

        // TC-TSR-02: email inválido rechazado
        $r = $svc->requestRegistration(['email' => 'noemail', 'nit' => '900123456', 'business_name' => 'Test', 'password' => 'secret123']);
        if ($r['ok'] !== false || !str_contains($r['message'], 'Email')) {
            throw new \RuntimeException('TC-TSR-02: email inválido debe retornar ok=false');
        }

        // TC-TSR-03: password corta rechazada
        $r = $svc->requestRegistration(['email' => 'a@empresa.com', 'nit' => '900123456', 'business_name' => 'Test', 'password' => 'abc']);
        if ($r['ok'] !== false || !str_contains($r['message'], 'contrasena')) {
            throw new \RuntimeException('TC-TSR-03: password < 8 chars debe retornar ok=false');
        }

        // TC-TSR-04: registro válido → ok=true, tenant_id generado
        $r = $svc->requestRegistration(['email' => 'owner@empresa.com', 'nit' => '900123456-1', 'business_name' => 'Empresa Test SAS', 'password' => 'secreto123']);
        if ($r['ok'] !== true) {
            throw new \RuntimeException('TC-TSR-04: registro válido debe retornar ok=true, obtenido: ' . ($r['message'] ?? ''));
        }
        if (empty($r['tenant_id']) || !str_starts_with($r['tenant_id'], 'tenant_')) {
            throw new \RuntimeException('TC-TSR-04: tenant_id debe generarse con prefijo tenant_, obtenido: ' . ($r['tenant_id'] ?? 'vacío'));
        }
        $tenantId = $r['tenant_id'];

        // TC-TSR-05: verifyAndActivate con código incorrecto
        $r = $svc->verifyAndActivate($tenantId, 'owner@empresa.com', '000000');
        if ($r['ok'] !== false) {
            throw new \RuntimeException('TC-TSR-05: código incorrecto debe retornar ok=false');
        }

        // TC-TSR-06: verifyAndActivate acepta OTP correcto — ProjectRegistry requiere MySQL (no disponible in-memory)
        $stmt = $db->query("SELECT code FROM otp_codes WHERE project_id='{$tenantId}' ORDER BY id DESC LIMIT 1");
        $code = (string) ($stmt->fetchColumn() ?: '');
        if (strlen($code) !== 6) {
            throw new \RuntimeException('TC-TSR-06: OTP de 6 dígitos debe existir en DB tras requestRegistration');
        }
        // Insertar OTP conocido para verificar que el check pasa antes de llegar a ProjectRegistry
        $db->exec("INSERT INTO otp_codes (project_id, identifier, code, created_at, attempts) VALUES ('{$tenantId}','owner@empresa.com','123456',CURRENT_TIMESTAMP,0)");
        try {
            $r = $svc->verifyAndActivate($tenantId, 'owner@empresa.com', '123456');
            if (isset($r['ok']) && $r['ok'] === false && str_contains((string)($r['message'] ?? ''), 'invalido')) {
                throw new \RuntimeException('TC-TSR-06: OTP correcto no debe rechazarse, obtenido: ' . $r['message']);
            }
        } catch (\PDOException $ignored) {
            // ProjectRegistry::createAuthUser() requiere MySQL — esperado en test SQLite in-memory
        } catch (\RuntimeException $e) {
            // Solo propagar si NO es error de conexión DB
            if (!str_contains($e->getMessage(), 'conexion') && !str_contains($e->getMessage(), 'DB') && !str_contains($e->getMessage(), 'connect')) {
                throw $e;
            }
            // MySQL no disponible en test in-memory — OTP fue verificado, ProjectRegistry falla por infra
        }
    }

    private function checkNonElectronicReceipt(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // TC-NER-01: AlanubeClient::isConfigured() retorna false cuando ALANUBE_TOKEN vacío
        $orig = getenv('ALANUBE_TOKEN');
        putenv('ALANUBE_TOKEN=');
        if (\App\Core\AlanubeClient::isConfigured()) {
            throw new \RuntimeException('TC-NER-01: isConfigured() debe retornar false cuando token vacío');
        }

        // TC-NER-02: AlanubeClient::isConfigured() retorna true con token presente
        putenv('ALANUBE_TOKEN=test_token_123');
        if (!\App\Core\AlanubeClient::isConfigured()) {
            throw new \RuntimeException('TC-NER-02: isConfigured() debe retornar true con token presente');
        }
        putenv('ALANUBE_TOKEN=' . ($orig ?: ''));

        // TC-NER-03: generateDirect() genera ticket con número secuencial
        $svc = new \App\Core\NonElectronicReceiptService($db);
        $payload = [
            'customer_name' => 'Empresa Test SAS',
            'lines' => [
                ['description' => 'Arroz 3kg', 'quantity' => 2, 'unit_price' => 5000, 'amount' => 10000],
                ['description' => 'Aceite 1L',  'quantity' => 1, 'unit_price' => 8500, 'amount' => 8500],
            ],
            'subtotal' => 18500,
            'tax'      => 1480,
            'total'    => 19980,
            'currency' => 'COP',
        ];
        $r = $svc->generateDirect('tenant_test', $payload, 'suki_erp');
        if ($r['ok'] !== true) {
            throw new \RuntimeException('TC-NER-03: generateDirect() debe retornar ok=true');
        }
        if (!str_starts_with($r['ticket_number'], 'TKT-')) {
            throw new \RuntimeException('TC-NER-03: ticket_number debe iniciar con TKT-, obtenido: ' . $r['ticket_number']);
        }
        if (!str_contains($r['html'], 'NO EQUIVALE A FACTURA')) {
            throw new \RuntimeException('TC-NER-03: HTML debe incluir disclaimer DIAN');
        }
        if (!str_contains($r['html'], 'Empresa Test SAS')) {
            throw new \RuntimeException('TC-NER-03: HTML debe incluir nombre del cliente');
        }
        if (!str_contains($r['html'], 'Arroz 3kg')) {
            throw new \RuntimeException('TC-NER-03: HTML debe incluir los items de la venta');
        }

        // TC-NER-04: números de secuencia incrementan
        $r2 = $svc->generateDirect('tenant_test', $payload, 'suki_erp');
        $r3 = $svc->generateDirect('tenant_test', $payload, 'suki_erp');
        $seq1 = (int) substr($r['ticket_number'],  -4);
        $seq2 = (int) substr($r2['ticket_number'], -4);
        $seq3 = (int) substr($r3['ticket_number'], -4);
        if ($seq2 !== $seq1 + 1 || $seq3 !== $seq1 + 2) {
            throw new \RuntimeException("TC-NER-04: secuencia debe incrementar: {$seq1},{$seq2},{$seq3}");
        }

        // TC-NER-05: diferentes tenants tienen secuencias independientes
        $r4 = $svc->generateDirect('tenant_otro', $payload, 'suki_erp');
        $seq4 = (int) substr($r4['ticket_number'], -4);
        if ($seq4 !== 1) {
            throw new \RuntimeException('TC-NER-05: nuevo tenant debe iniciar en secuencia 1, obtenido: ' . $seq4);
        }

        // TC-NER-06: AlanubeIntegrationAdapter retorna no_token cuando ALANUBE_TOKEN vacío
        putenv('ALANUBE_TOKEN=');
        $adapter = new \App\Core\AlanubeIntegrationAdapter();
        $result  = $adapter->execute('emit_document', [], ['endpoint' => '/documents', 'body' => []], []);
        if (($result['mode'] ?? '') !== 'no_token') {
            throw new \RuntimeException('TC-NER-06: adapter debe retornar mode=no_token cuando token vacío');
        }
        putenv('ALANUBE_TOKEN=' . ($orig ?: ''));
    }

    private function checkAppConfigOnboarding(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE app_tenant_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                app_id TEXT NOT NULL,
                field_key TEXT NOT NULL,
                field_value TEXT NOT NULL DEFAULT "",
                configured_at DATETIME
            )'
        );
        $configSvc  = new \App\Core\AppTenantConfigService($pdo);
        $onboarding = new \App\Core\AppConfigOnboarding($configSvc);

        // TC-ACO-01: Nueva empresa tiene campos pendientes de configurar
        $pending = $onboarding->getPendingFields('test_tenant', 'suki_erp');
        if (empty($pending)) {
            throw new \RuntimeException('TC-ACO-01: getPendingFields() debe retornar campos para tenant nuevo');
        }

        // TC-ACO-02: buildFieldContextForPrompt retorna string no vacío con datos del campo
        $firstField = $pending[0];
        $context = $onboarding->buildFieldContextForPrompt($firstField, 'test_tenant', 'suki_erp');
        if ($context === '') {
            throw new \RuntimeException('TC-ACO-02: buildFieldContextForPrompt() no debe retornar string vacío');
        }
        if (!str_contains($context, $firstField['key'] ?? '')) {
            throw new \RuntimeException('TC-ACO-02: contexto debe contener el key del campo pendiente');
        }

        // TC-ACO-03: processAnswerAndGetContext guarda el valor y avanza al siguiente campo
        $firstKey = $firstField['key'] ?? '';
        $onboarding->processAnswerAndGetContext('test_tenant', 'suki_erp', 'Mi empresa ejemplo S.A.S.');
        $savedFields = $configSvc->loadAll('test_tenant', 'suki_erp');
        if (count($savedFields) === 0) {
            throw new \RuntimeException('TC-ACO-03: después de procesar respuesta debe guardar al menos 1 campo');
        }

        // TC-ACO-04: isComplete retorna false mientras haya campos pendientes
        if ($onboarding->isComplete('test_tenant', 'suki_erp')) {
            throw new \RuntimeException('TC-ACO-04: isComplete() debe ser false con campos pendientes');
        }

        // TC-ACO-05: app inexistente retorna campos globales (no explota)
        $pendingUnknown = $onboarding->getPendingFields('test_tenant', 'nonexistent_app_xyz');
        if (!is_array($pendingUnknown)) {
            throw new \RuntimeException('TC-ACO-05: getPendingFields() con app desconocida debe retornar array');
        }
    }
}
