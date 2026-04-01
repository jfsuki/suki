<?php
// app/Core/ChatAgent.php

namespace App\Core;

use App\Core\Agents\ConversationGateway;
use App\Core\Agents\Memory\TokenBudgeter;
use App\Core\Agents\Memory\SemanticCache;
use App\Core\Agents\Processes\BuilderOnboardingProcess;
use App\Core\Agents\Processes\AppExecutionProcess;
use App\Core\Agents\AcidChatRunner;
use App\Core\Agents\Telemetry;
use App\Core\LLM\LLMRouter;
use App\Core\PlaybookInstaller;
use App\Core\SemanticMemoryService;

use RuntimeException;

final class ChatAgent
{
    private ?CommandLayer $command = null;
    private EntityRegistry $entities;
    private ?EntityMigrator $migrator = null;
    private $gateway = null;
    private ?LLMRouter $llmRouter = null;
    private ?Telemetry $telemetry = null;
    private ?TelemetryService $telemetryService = null;
    private ?AgentOpsSupervisor $agentOpsSupervisor = null;
    private ?AgentOpsObservabilityService $agentOpsObservabilityService = null;
    private ?IntentRouter $intentRouter = null;
    private ?CommandBus $commandBus = null;
    private ?ControlTowerRepository $controlTowerRepository = null;
    private ?ControlTowerFeedManager $controlTowerFeedManager = null;
    private ?TaskExecutionManager $taskExecutionManager = null;
    private ?IncidentManager $incidentManager = null;
    private bool $controlTowerResolved = false;
    private bool $controlTowerAvailable = false;
    private FormWizard $wizard;
    private ContractWriter $writer;
    private EntityBuilder $builder;
    private LocalJsonMemoryRepository $memory;
    private ?SemanticMemoryService $semanticMemory = null;

    public function __construct()
    {
        $this->entities = new EntityRegistry();
        $this->wizard = new FormWizard();
        $this->writer = new ContractWriter();
        $this->builder = new EntityBuilder();
        $this->memory = new LocalJsonMemoryRepository();
        $this->semanticMemory = new SemanticMemoryService();
    }

    public function handle(array $payload): array
    {
        $requestStartedAt = microtime(true);
        if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) { $_SERVER['REQUEST_TIME_FLOAT'] = $requestStartedAt; }
        
        $testMode = $this->isTestMode($payload);
        $text = trim((string) ($payload['message'] ?? $payload['text'] ?? ''));
        $channel = trim((string) ($payload['channel'] ?? 'local'));
        $sessionId = trim((string) ($payload['session_id'] ?? 'sess_' . time()));
        $userId = trim((string) ($payload['user_id'] ?? 'anon'));
        $tenantId = trim((string) ($payload['tenant_id'] ?? getenv('TENANT_ID') ?? 'default'));
        $role = trim((string) ($payload['role'] ?? $payload['user_role'] ?? ''));
        $chatExecAuthRequired = (bool) ($payload['chat_exec_auth_required'] ?? false);
        $isAuthenticated = array_key_exists('is_authenticated', $payload)
            ? (bool) $payload['is_authenticated']
            : !$chatExecAuthRequired;
        $authUserId = trim((string) ($payload['auth_user_id'] ?? ''));
        $authTenantId = trim((string) ($payload['auth_tenant_id'] ?? ''));
        $authProjectId = trim((string) ($payload['auth_project_id'] ?? ''));

        if ($channel === '') { $channel = 'local'; }
        if ($sessionId === '') { $sessionId = 'sess_' . time(); }
        if ($userId === '') { $userId = 'anon'; }
        if ($tenantId === '') { $tenantId = (string) (getenv('TENANT_ID') ?: 'default'); }
        if ($role === '') { $role = $isAuthenticated ? (string) (getenv('DEFAULT_ROLE') ?: 'admin') : 'guest'; }
        
        if ($isAuthenticated) {
            if ($authUserId === '') { $authUserId = $userId; }
            if ($authTenantId === '') { $authTenantId = $tenantId; }
        }
        $role = $this->normalizeRole($role);
        $mode = strtolower((string) ($payload['mode'] ?? 'app'));
        $projectId = (string) ($payload['project_id'] ?? '');

        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();

        if ($projectId === '') { $projectId = $manifest['id'] ?? 'default'; }
        if ($isAuthenticated && $authProjectId === '') { $authProjectId = $projectId; }
        
        $sessionBinding = $registry->getSession($sessionId);

        if (is_array($sessionBinding)) {
            $boundUser = trim((string) ($sessionBinding['user_id'] ?? ''));
            $boundProject = trim((string) ($sessionBinding['project_id'] ?? ''));
            $boundTenant = trim((string) ($sessionBinding['tenant_id'] ?? ''));

            if ($boundUser !== '' && $boundUser !== $userId) {
                return $this->reply('Esta sesion pertenece a otro usuario.', $channel, $sessionId, $userId, 'error');
            }
            if ($boundProject !== '' && $projectId !== '' && $boundProject !== $projectId) {
                return $this->reply('Esta sesion ya esta enlazada a otro proyecto.', $channel, $sessionId, $userId, 'error');
            }
            if ($boundTenant !== '' && $boundTenant !== $tenantId) {
                return $this->reply('Esta sesion pertenece a otro tenant.', $channel, $sessionId, $userId, 'error');
            }
            if ($boundProject !== '') { $projectId = $boundProject; }
        }
        if (!defined('TENANT_ID')) {
            if (is_numeric($tenantId)) {
                define('TENANT_ID', (int) $tenantId);
                putenv('TENANT_ID=' . $tenantId);
            } else {
                $hash = $this->stableTenantInt($tenantId);
                define('TENANT_ID', $hash);
                putenv('TENANT_ID=' . $hash);
                putenv('TENANT_KEY=' . $tenantId);
            }
        }
        $registry->ensureProject(
            $projectId,
            $manifest['name'] ?? 'Proyecto',
            $manifest['status'] ?? 'draft',
            $manifest['tenant_mode'] ?? 'shared',
            $userId,
            (string) ($manifest['storage_model'] ?? '')
        );
        $projectMeta = $registry->getProject($projectId) ?? [];
        $storageModel = StorageModel::normalize((string) ($projectMeta['storage_model'] ?? StorageModel::LEGACY));
        putenv('PROJECT_STORAGE_MODEL=' . $storageModel);
        putenv('DB_STORAGE_MODEL=' . $storageModel);
        putenv('PROJECT_ID=' . $projectId);
        StorageModel::clearCache();
        TableNamespace::clearCache();
        $registry->touchSession($sessionId, $userId, $projectId, $tenantId, $channel);
        $roleBeforeAccessControl = $role;

        if (!$testMode && $isAuthenticated && $authUserId !== '' && $authTenantId !== '') {
            try {
                $accessControl = new TenantAccessControlService();
                $tenantUser = $accessControl->resolveTenantUser($authTenantId, $authUserId);
                if (is_array($tenantUser)) {
                    $tenantStatus = trim((string) ($tenantUser['status'] ?? ''));
                    $tenantRole = trim((string) ($tenantUser['role_key'] ?? ''));
                    if ($tenantStatus === 'active' && $tenantRole !== '') {
                        $role = $tenantRole;
                    } elseif ($tenantStatus === 'inactive') {
                        $role = 'guest';
                    }
                }
            } catch (\Throwable $e) {
                // Keep legacy role fallback
            }
        }
        if ($role !== $roleBeforeAccessControl) {
            $registry->touchUser($userId, $role, $mode === 'builder' ? 'creator' : 'app', $tenantId);
            $registry->assignUserToProject($projectId, $userId, $role);
        }
        \App\Core\RoleContext::setRole($role);
        \App\Core\RoleContext::setUserId($userId);
        \App\Core\RoleContext::setUserLabel((string) ($payload['user_label'] ?? ''));
        
        if ($text === '' && empty($payload['meta'])) {
            return $this->reply('Mensaje vacio.', $channel, $sessionId, $userId, 'error');
        }

        $local = $this->parseLocal($text);
        
        // --- MEMORY FLOW STEP 1: thread_id ---
        $threadId = $tenantId . ':' . $sessionId;
        $memory = $this->conversationMemory();

        // --- MEMORY FLOW STEP 10: Clear Memory Command ---
        if (($local['command'] ?? '') === 'ClearMemory') {
            $memory->clear($threadId);
            return $this->reply('Memoria limpia. He olvidado nuestro contexto actual.', $channel, $sessionId, $userId, 'success');
        }

        // --- MEMORY FLOW STEP 4: Persist User Message ---
        $memory->append($threadId, 'user', $text);

        $messageId = $this->resolveMessageId($payload, $sessionId, $text);
        $conversationId = $this->resolveConversationId($sessionId, $payload);
        $gateway = $this->gateway();
        $gateway->setConversationMemory($memory);
        $ingressValidation = $gateway->validateIngressEnvelope([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'mode' => $mode,
            'message' => $text,
            'meta' => $payload['meta'] ?? [],
            'attachments' => $payload['attachments'] ?? [],
            'is_authenticated' => $isAuthenticated,
            'auth_tenant_id' => $authTenantId,
            'chat_exec_auth_required' => $chatExecAuthRequired,
        ]);
        $task = $this->createControlTowerTask([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'app_id' => $projectId,
            'conversation_id' => $conversationId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'message_id' => $messageId,
            'intent' => (string) ($local['command'] ?? 'conversation_turn'),
            'status' => 'pending',
            'source' => 'chat',
            'related_entities' => [],
            'related_events' => [],
            'idempotency_key' => $messageId,
            'metadata' => [
                'channel' => $channel,
                'mode' => $mode,
                'is_authenticated' => $isAuthenticated,
            ],
        ]);
        $this->linkControlTowerTask($gateway, $tenantId, $userId, $projectId, $mode, $task);

        if (($ingressValidation['ok'] ?? false) !== true) {
            return $this->handleIngressValidationFailure($channel, $sessionId, $userId, $tenantId, $projectId, $conversationId, $messageId, $text, $mode, $payload, $isAuthenticated, $role, $task, $ingressValidation, $requestStartedAt);
        }

        $result = $gateway->handle($tenantId, $userId, $text, $mode, $projectId);
        if (!empty($local['command']) && in_array($local['command'], ['RunTests', 'LLMUsage'], true)) {
            $utilityTelemetry = $this->buildLocalUtilityTelemetry(
                (string) ($local['command'] ?? 'local_utility'),
                $tenantId,
                $projectId,
                $sessionId,
                $userId,
                $task,
                $conversationId
            );
            $task = $this->controlTowerRecordRoute($task, $utilityTelemetry, 'local_utility');
            $task = $this->controlTowerMarkRunning($task, [
                'route_path' => (string) ($utilityTelemetry['route_path'] ?? ''),
                'gate_decision' => (string) ($utilityTelemetry['gate_decision'] ?? 'allow'),
            ]);
            try {
                $reply = $this->executeLocal($local, $channel, $sessionId, $userId, $mode, $tenantId);
                $reply = $this->annotateReplyWithControlTower($reply, $task);
                $task = $this->controlTowerCompleteTask($task, [
                    'result_status' => (string) ($reply['status'] ?? 'success'),
                    'response_kind' => 'local_utility',
                    'response_text' => (string) ($reply['data']['reply'] ?? ''),
                ]);
                $reply = $this->annotateReplyWithControlTower($reply, $task);
                $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $utilityTelemetry, $this->latencyMs($requestStartedAt), [
                    'llm_called' => false,
                    'error_flag' => false,
                    'error_type' => 'none',
                    'tool_calls_count' => 0,
                    'retry_count' => 0,
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]);
                $this->telemetry()->record($tenantId, array_merge($utilityTelemetry, [
                    'message' => $text,
                    'resolved_locally' => true,
                    'action' => 'respond_local',
                    'mode' => $mode,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                    'is_authenticated' => $isAuthenticated,
                    'effective_role' => $role,
                ], $this->buildAgentOpsTelemetryBase(
                    $utilityTelemetry,
                    $tenantId,
                    $projectId,
                    $sessionId,
                    $messageId,
                    $this->latencyMs($requestStartedAt),
                    'response.emitted',
                    [
                        'llm_called' => false,
                        'error_flag' => false,
                        'error_type' => 'none',
                        'response_kind' => 'local_utility',
                        'response_text' => (string) ($reply['data']['reply'] ?? ''),
                        'task_id' => (string) ($task['task_id'] ?? ''),
                        'conversation_id' => $conversationId,
                    ]
                )));
                return $reply;
            } catch (\Throwable $e) {
                $rawError = (string) $e->getMessage();
                $human = str_contains($rawError, 'SQLSTATE')
                    ? 'No pude conectar la base de datos. El contrato se guarda, pero falta revisar credenciales DB.'
                    : 'No pude ejecutar ese paso. Revisa configuracion o permisos.';
                $failure = $this->controlTowerFailTask($task, [
                    'error_type' => 'local_utility_failure',
                    'description' => $human,
                    'created_at' => date('c'),
                ]);
                $task = $failure['task'];
                $reply = $this->reply($human, $channel, $sessionId, $userId, 'error', [
                    'error' => $rawError,
                ]);
                $reply = $this->annotateReplyWithControlTower($reply, $task, $failure['incident']);
                $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $utilityTelemetry, $this->latencyMs($requestStartedAt), [
                    'llm_called' => false,
                    'error_flag' => true,
                    'error_type' => 'local_utility_failure',
                    'tool_calls_count' => 0,
                    'retry_count' => 0,
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]);
                $this->telemetry()->record($tenantId, array_merge($utilityTelemetry, [
                    'message' => $text,
                    'resolved_locally' => true,
                    'action' => 'respond_local',
                    'mode' => $mode,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                    'is_authenticated' => $isAuthenticated,
                    'effective_role' => $role,
                    'status' => 'error',
                ], $this->buildAgentOpsTelemetryBase(
                    $utilityTelemetry,
                    $tenantId,
                    $projectId,
                    $sessionId,
                    $messageId,
                    $this->latencyMs($requestStartedAt),
                    'response.emitted',
                    [
                        'llm_called' => false,
                        'error_flag' => true,
                        'error_type' => 'local_utility_failure',
                        'response_kind' => 'local_utility',
                        'response_text' => (string) ($reply['data']['reply'] ?? ''),
                        'task_id' => (string) ($task['task_id'] ?? ''),
                        'conversation_id' => $conversationId,
                    ]
                )));
                return $reply;
            }
        }
        $tRouter = microtime(true);
        $route = $this->intentRouter()->route($result, [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'mode' => $mode,
            'session_id' => $sessionId,
            'role' => $role,
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'message_text' => $text,
            'is_test' => $testMode,
            'is_authenticated' => $isAuthenticated,
            'auth_user_id' => $authUserId,
            'auth_tenant_id' => $authTenantId,
            'auth_project_id' => $authProjectId,
            'chat_exec_auth_required' => $chatExecAuthRequired,
            'manifest' => $manifest,
            'has_attachment' => !empty($payload['meta']) || !empty($payload['attachments']),
            'attachments_count' => $this->resolveAttachmentCount($payload),
            'attachments' => is_array($payload['attachments'] ?? null) ? (array) $payload['attachments'] : [],
        ]);

        $action = $route->kind();
        $telemetry = $route->telemetry();
        $telemetry = $this->attachControlTowerTelemetry($telemetry, $task, $conversationId);
        $state = $route->state();
        $task = $this->controlTowerRecordRoute($task, $telemetry, (string) ($telemetry['classification'] ?? $result['intent'] ?? 'unknown'));

        $securityBlock = $this->enforceExecutableChatSecurity(
            $route,
            $result,
            $chatExecAuthRequired,
            $isAuthenticated,
            $role,
            $tenantId,
            $authTenantId,
            $channel,
            $sessionId,
            $userId
        );
        if (is_array($securityBlock)) {
            $blockedTelemetry = array_merge($telemetry, [
                'gate_decision' => 'blocked',
                'fallback_reason' => 'security_block',
                'error_type' => 'security_block',
            ]);
            $failure = $this->controlTowerFailTask($task, [
                'error_type' => 'security_block',
                'description' => (string) (($securityBlock['data']['reply'] ?? $securityBlock['message'] ?? 'Bloqueado por seguridad.')),
                'created_at' => date('c'),
            ]);
            $task = $failure['task'];
            if ($route->isCommand()) {
                $this->recordToolExecutionTrace($tenantId, $projectId, $sessionId, $blockedTelemetry, [
                    'success' => false,
                    'execution_latency' => 0,
                    'error_code' => 'security_block',
                    'result_status' => 'blocked',
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]);
            }
            $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $blockedTelemetry, $this->latencyMs($requestStartedAt), [
                'llm_called' => false,
                'error_flag' => true,
                'error_type' => 'security_block',
                'tool_calls_count' => 0,
                'retry_count' => 0,
                'task_id' => (string) ($task['task_id'] ?? ''),
                'conversation_id' => $conversationId,
            ]);
            $this->telemetry()->record($tenantId, array_merge($blockedTelemetry, [
                'message' => $text,
                'resolved_locally' => true,
                'action' => 'execute_command',
                'mode' => $mode,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                'requested_slot' => (string) (($state['requested_slot'] ?? '') ?: ''),
                'is_authenticated' => $isAuthenticated,
                'effective_role' => $role,
                'status' => 'blocked',
            ], $this->buildAgentOpsTelemetryBase(
                $blockedTelemetry,
                $tenantId,
                $projectId,
                $sessionId,
                $messageId,
                $this->latencyMs($requestStartedAt),
                'response.blocked',
                [
                    'llm_called' => false,
                    'error_flag' => true,
                    'error_type' => 'security_block',
                    'response_kind' => 'blocked',
                    'response_text' => (string) (($securityBlock['data']['reply'] ?? $securityBlock['message'] ?? '')),
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]
            )));
            try {
                $this->telemetryService()->recordIntentMetric([
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'session_id' => $sessionId,
                    'mode' => $mode,
                    'intent' => (string) ($telemetry['classification'] ?? $result['intent'] ?? 'unknown'),
                    'action' => 'execute_command',
                    'latency_ms' => $this->latencyMs($requestStartedAt),
                    'status' => 'blocked',
                ]);
            } catch (\Throwable $e) {
                // observability must not block chat response
            }
            return $this->annotateReplyWithControlTower($securityBlock, $task, $failure['incident']);
        }

        if ($route->isLocalResponse()) {
            $localFailure = null;
            if ($this->shouldTraceBlockedToolExecution($telemetry)) {
                $this->recordToolExecutionTrace($tenantId, $projectId, $sessionId, $telemetry, [
                    'success' => false,
                    'execution_latency' => 0,
                    'result_status' => (string) ($telemetry['result_status'] ?? 'blocked'),
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]);
                $localFailure = $this->controlTowerFailTask($task, [
                    'error_type' => 'quality_gate_block',
                    'description' => (string) $route->reply(),
                    'created_at' => date('c'),
                ]);
                $task = $localFailure['task'];
            }
            $localResponseData = $this->extractOperationalTelemetryMarkers($telemetry);
            if (trim((string) ($localResponseData['session_id'] ?? '')) === '') {
                unset($localResponseData['session_id']);
            }
            if (trim((string) ($localResponseData['user_id'] ?? '')) === '') {
                unset($localResponseData['user_id']);
            }
            $reply = $this->reply(
                $route->reply(),
                $channel,
                $sessionId,
                $userId,
                'success',
                $localResponseData
            );
            if ($localFailure === null) {
                $task = $this->controlTowerCompleteTask($task, [
                    'result_status' => 'success',
                    'response_kind' => $action,
                    'response_text' => (string) $route->reply(),
                ]);
            }
            $reply = $this->annotateReplyWithControlTower(
                $reply,
                $task,
                is_array($localFailure) ? ($localFailure['incident'] ?? null) : null
            );
            $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                'llm_called' => false,
                'error_flag' => $localFailure !== null,
                'error_type' => $localFailure !== null ? 'quality_gate_block' : 'none',
                'tool_calls_count' => 0,
                'retry_count' => 0,
                'task_id' => (string) ($task['task_id'] ?? ''),
                'conversation_id' => $conversationId,
            ]);
            $this->telemetry()->record($tenantId, array_merge($telemetry, [
                'message' => $text,
                'resolved_locally' => true,
                'action' => $action,
                'mode' => $mode,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                'requested_slot' => (string) (($state['requested_slot'] ?? '') ?: ''),
                'is_authenticated' => $isAuthenticated,
                'effective_role' => $role,
            ], $this->buildAgentOpsTelemetryBase(
                $telemetry,
                $tenantId,
                $projectId,
                $sessionId,
                $messageId,
                $this->latencyMs($requestStartedAt),
                'response.emitted',
                [
                    'llm_called' => false,
                    'error_flag' => $localFailure !== null,
                    'error_type' => $localFailure !== null ? 'quality_gate_block' : 'none',
                    'response_kind' => $action,
                    'response_text' => (string) $route->reply(),
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]
            )));
            try {
                $this->telemetryService()->recordIntentMetric([
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'session_id' => $sessionId,
                    'mode' => $mode,
                    'intent' => (string) ($telemetry['classification'] ?? $result['intent'] ?? 'unknown'),
                    'action' => $action,
                    'latency_ms' => $this->latencyMs($requestStartedAt),
                    'status' => 'success',
                ]);
            } catch (\Throwable $e) {
                // observability must not block chat response
            }
            $this->persistToUserMemory($text, (string)$route->reply(), [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'tenant_id' => $tenantId,
                'role' => $role,
                'is_test' => $testMode
            ]);

            $out = $this->attachTestInfo($reply, $testMode, $telemetry, [
                'action' => $action,
                'resolved_locally' => true,
                'llm_called' => false,
                'provider_used' => 'none',
            ]);
            return $out;
        }

        if ($route->isCommand()) {
            $commandStartedAt = microtime(true);
            $commandPayload = $route->command();
            $commandExecutionErrorType = '';
            $task = $this->controlTowerMarkRunning($task, [
                'route_path' => (string) ($telemetry['route_path'] ?? ''),
                'gate_decision' => (string) ($telemetry['gate_decision'] ?? 'unknown'),
            ]);
            $qualityGate = $this->evaluateControlTowerQualityGates($telemetry, $commandPayload, $tenantId, $authTenantId, $task);
            if (($qualityGate['ok'] ?? true) !== true) {
                $taskManager = $this->taskExecutionManager();
                if ($taskManager && is_array($task)) {
                    try {
                        $task = $taskManager->blockExecution((string) $task['tenant_id'], (string) $task['task_id'], [
                            'warning_type' => 'quality_gate_block',
                            'errors' => is_array($qualityGate['errors'] ?? null) ? (array) $qualityGate['errors'] : [],
                            'checked' => is_array($qualityGate['checked'] ?? null) ? (array) $qualityGate['checked'] : [],
                            'timestamp' => date('c'),
                            'source' => 'system',
                        ]);
                    } catch (\Throwable $e) {
                        error_log('[ControlTower] block execution failed: ' . $e->getMessage());
                    }
                }
                $failure = $this->controlTowerFailTask($task, [
                    'error_type' => 'quality_gate_block',
                    'description' => 'Bloqueado por Control Tower quality gates.',
                    'created_at' => date('c'),
                    'quality_gate' => $qualityGate,
                ]);
                $task = $failure['task'];
                $blockedReply = $this->reply(
                    'Bloqueado por Control Tower. Falta pasar quality gates antes de ejecutar.',
                    $channel,
                    $sessionId,
                    $userId,
                    'error',
                    [
                        'quality_gate' => $qualityGate,
                    ]
                );
                $blockedReply = $this->annotateReplyWithControlTower($blockedReply, $task, $failure['incident']);
                $this->recordToolExecutionTrace($tenantId, $projectId, $sessionId, $telemetry, [
                    'success' => false,
                    'execution_latency' => 0,
                    'error_code' => 'quality_gate_block',
                    'result_status' => 'blocked',
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]);
                $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                    'llm_called' => false,
                    'error_flag' => true,
                    'error_type' => 'quality_gate_block',
                    'tool_calls_count' => 0,
                    'retry_count' => 0,
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]);
                $this->telemetry()->record($tenantId, array_merge($telemetry, [
                    'message' => $text,
                    'resolved_locally' => true,
                    'action' => 'execute_command',
                    'mode' => $mode,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                    'status' => 'blocked',
                    'is_authenticated' => $isAuthenticated,
                    'effective_role' => $role,
                ], $this->buildAgentOpsTelemetryBase(
                    $telemetry,
                    $tenantId,
                    $projectId,
                    $sessionId,
                    $messageId,
                    $this->latencyMs($requestStartedAt),
                    'response.blocked',
                    [
                        'llm_called' => false,
                        'error_flag' => true,
                        'error_type' => 'quality_gate_block',
                        'response_kind' => 'blocked',
                        'response_text' => (string) ($blockedReply['data']['reply'] ?? ''),
                        'task_id' => (string) ($task['task_id'] ?? ''),
                        'conversation_id' => $conversationId,
                    ]
                )));
                return $this->attachTestInfo($blockedReply, $testMode, $telemetry, [
                    'action' => 'execute_command',
                    'resolved_locally' => true,
                    'llm_called' => false,
                    'provider_used' => 'none',
                ]);
            }
            try {
                $reply = $this->dispatchCommandPayload($commandPayload, $channel, $sessionId, $userId, $mode);
                $commandReply = $this->extractReplyTextFromEnvelope($reply);
                if (($reply['status'] ?? '') === 'success') {
                    $this->gateway()->rememberExecution(
                        $tenantId,
                        $userId,
                        $projectId,
                        $mode,
                        $commandPayload,
                        (array) ($reply['data'] ?? []),
                        $text,
                        $commandReply
                    );
                    $followup = $this->gateway()->postExecutionFollowup(
                        $tenantId,
                        $userId,
                        $projectId,
                        $mode,
                        $commandPayload,
                        (array) ($reply['data'] ?? [])
                    );
                    if ($followup !== '') {
                        $current = trim((string) ($reply['data']['reply'] ?? ''));
                        $reply['data']['reply'] = trim($current . "\n" . $followup);
                        $reply['reply'] = trim($this->extractReplyTextFromEnvelope($reply));
                    }
                }
            } catch (\Throwable $e) {
                $rawError = (string) $e->getMessage();
                $human = $this->humanizeSqlError($rawError);
                $commandExecutionErrorType = 'command_exception';
                $reply = $this->reply('No pude ejecutar ese paso. Revisa permisos o datos.', $channel, $sessionId, $userId, 'error', [
                    'reply' => $human,
                    'error' => $rawError,
                ]);
            }
            $commandName = (string) ($commandPayload['command'] ?? 'unknown');
            $commandData = is_array($reply['data'] ?? null) ? (array) $reply['data'] : [];
            $commandMarkers = $this->extractOperationalTelemetryMarkers($commandData);
            $commandTelemetry = array_merge($telemetry, $commandMarkers);
            $commandStatus = (string) ($reply['status'] ?? 'error');
            $commandReply = $this->extractReplyTextFromEnvelope($reply);
            $blockedByGuardrail = $commandStatus !== 'success' && $this->looksLikeGuardrailMessage($commandReply);
            $commandErrorFlag = $commandStatus !== 'success';
            $commandErrorType = 'none';
            if ($commandErrorFlag) {
                if ($blockedByGuardrail) {
                    $commandErrorType = 'guardrail_blocked';
                } elseif ($commandExecutionErrorType !== '') {
                    $commandErrorType = $commandExecutionErrorType;
                } else {
                    $commandErrorType = 'command_failed';
                }
            }
            try {
                $this->telemetryService()->recordCommandMetric([
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'session_id' => $sessionId,
                    'mode' => $mode,
                    'command_name' => $commandName,
                    'latency_ms' => $this->latencyMs($commandStartedAt),
                    'status' => $commandStatus,
                    'blocked' => $blockedByGuardrail ? 1 : 0,
                ]);
                if ($blockedByGuardrail) {
                    $this->telemetryService()->recordGuardrailEvent([
                        'tenant_id' => $tenantId,
                        'project_id' => $projectId,
                        'session_id' => $sessionId,
                        'mode' => $mode,
                        'guardrail' => 'mode_guard',
                        'reason' => $commandReply,
                    ]);
                }
            } catch (\Throwable $e) {
                // observability must not block chat response
            }
            $this->recordToolExecutionTrace($tenantId, $projectId, $sessionId, $commandTelemetry, [
                'success' => !$commandErrorFlag,
                'execution_latency' => $this->latencyMs($commandStartedAt),
                'error_code' => $commandErrorFlag ? $commandErrorType : null,
                'result_status' => $commandStatus,
                'command_name' => $commandName,
                'task_id' => (string) ($task['task_id'] ?? ''),
                'conversation_id' => $conversationId,
            ]);
            if ($commandErrorFlag) {
                $failure = $this->controlTowerFailTask($task, [
                    'error_type' => $commandErrorType,
                    'description' => $commandReply !== '' ? $commandReply : 'Command execution failed.',
                    'created_at' => date('c'),
                ]);
                $task = $failure['task'];
                $reply = $this->annotateReplyWithControlTower($reply, $task, $failure['incident']);
            } else {
                $task = $this->controlTowerCompleteTask($task, [
                    'result_status' => $commandStatus,
                    'response_kind' => $action,
                    'response_text' => $commandReply,
                    'command_name' => $commandName,
                ]);
                $reply = $this->annotateReplyWithControlTower($reply, $task);
            }
            $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                'llm_called' => false,
                'error_flag' => $commandErrorFlag,
                'error_type' => $commandErrorType,
                'tool_calls_count' => 1,
                'retry_count' => 0,
                'task_id' => (string) ($task['task_id'] ?? ''),
                'conversation_id' => $conversationId,
            ] + $commandMarkers);
            $this->telemetry()->record($tenantId, array_merge($commandTelemetry, [
                'message' => $text,
                'resolved_locally' => true,
                'action' => $action,
                'mode' => $mode,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                'requested_slot' => (string) (($state['requested_slot'] ?? '') ?: ''),
                'is_authenticated' => $isAuthenticated,
                'effective_role' => $role,
            ], $this->buildAgentOpsTelemetryBase(
                $commandTelemetry,
                $tenantId,
                $projectId,
                $sessionId,
                $messageId,
                $this->latencyMs($requestStartedAt),
                'response.emitted',
                [
                    'llm_called' => false,
                    'error_flag' => $commandErrorFlag,
                    'error_type' => $commandErrorType,
                    'response_kind' => $action,
                    'response_text' => $commandReply,
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ] + $commandMarkers
            )));
            try {
                $this->telemetryService()->recordIntentMetric([
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'session_id' => $sessionId,
                    'mode' => $mode,
                    'intent' => (string) ($telemetry['classification'] ?? $result['intent'] ?? 'unknown'),
                    'action' => $action,
                    'latency_ms' => $this->latencyMs($requestStartedAt),
                    'status' => $commandStatus,
                ]);
            } catch (\Throwable $e) {
                // observability must not block chat response
            }
            return $this->attachTestInfo($reply, $testMode, $commandTelemetry, [
                'action' => $action,
                'resolved_locally' => true,
                'llm_called' => false,
                'provider_used' => 'none',
            ]);
        }

        if ($route->isLlmRequest()) {
            $semanticLocalReply = $this->buildSemanticLocalReply($route, $telemetry);
            if ($semanticLocalReply !== '') {
                $task = $this->controlTowerCompleteTask($task, [
                    'result_status' => 'success',
                    'response_kind' => 'respond_local',
                    'response_text' => $semanticLocalReply,
                    'provider' => 'semantic_memory',
                ]);
                $semanticReply = $this->reply($semanticLocalReply, $channel, $sessionId, $userId, 'success', [
                    'provider_used' => 'semantic_memory',
                    'memory_type' => (string) ($telemetry['memory_type'] ?? ''),
                    'source_ids' => is_array($telemetry['source_ids'] ?? null) ? (array) $telemetry['source_ids'] : [],
                    'evidence_gate_status' => (string) ($telemetry['evidence_gate_status'] ?? ''),
                ]);
                $semanticReply = $this->annotateReplyWithControlTower($semanticReply, $task);
                $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                    'llm_called' => false,
                    'error_flag' => false,
                    'error_type' => 'none',
                    'tool_calls_count' => 0,
                    'retry_count' => 0,
                    'provider_used' => 'semantic_memory',
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]);
                $this->telemetry()->record($tenantId, array_merge($telemetry, [
                    'message' => $text,
                    'provider_used' => 'semantic_memory',
                    'resolved_locally' => true,
                    'action' => 'respond_local',
                    'mode' => $mode,
                    'llm_request_count' => 0,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                    'requested_slot' => (string) (($state['requested_slot'] ?? '') ?: ''),
                    'is_authenticated' => $isAuthenticated,
                    'effective_role' => $role,
                ], $this->buildAgentOpsTelemetryBase(
                    $telemetry,
                    $tenantId,
                    $projectId,
                    $sessionId,
                    $messageId,
                    $this->latencyMs($requestStartedAt),
                    'response.emitted',
                    [
                        'llm_called' => false,
                        'error_flag' => false,
                        'error_type' => 'none',
                        'response_kind' => 'respond_local',
                        'response_text' => $semanticLocalReply,
                        'provider_used' => 'semantic_memory',
                        'task_id' => (string) ($task['task_id'] ?? ''),
                        'conversation_id' => $conversationId,
                    ]
                )));
                try {
                    $this->telemetryService()->recordIntentMetric([
                        'tenant_id' => $tenantId,
                        'project_id' => $projectId,
                        'session_id' => $sessionId,
                        'mode' => $mode,
                        'intent' => (string) ($telemetry['classification'] ?? $result['intent'] ?? 'unknown'),
                        'action' => 'respond_local',
                        'latency_ms' => $this->latencyMs($requestStartedAt),
                        'status' => 'success',
                    ]);
                } catch (\Throwable $ignored) {
                    // observability must not block chat response
                }
                return $this->attachTestInfo($semanticReply, $testMode, $telemetry, [
                    'action' => 'respond_local',
                    'resolved_locally' => true,
                    'llm_called' => false,
                    'provider_used' => 'semantic_memory',
                ]);
            }

            $task = $this->controlTowerMarkRunning($task, [
                'route_path' => (string) ($telemetry['route_path'] ?? ''),
                'gate_decision' => (string) ($telemetry['gate_decision'] ?? 'unknown'),
            ]);
            try {
                // --- MEMORY FLOW STEP 3, 5, 6: Load and Build Context ---
                $history = $memory->load($threadId);
                $systemPrompt = @file_get_contents(dirname(__DIR__, 2) . '/prompts/builder_system_prompt.txt') 
                    ?: "Eres SUKI. Responde breve y claro.";
                
                $messages = [['role' => 'system', 'content' => $systemPrompt]];
                foreach ($history as $msg) {
                    $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
                }

                // --- MEMORY FLOW STEP 7: Call LLM with Memory ---
                $llmResult = $this->llmRouter()->complete($messages, [
                    'mode' => $mode,
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'policy' => $route->llmRequest()['policy'] ?? [],
                ]);
            } catch (\Throwable $e) {
                $llmFailure = $this->extractLlmFailureDetails($e);
                $userSafeLlmUnavailableReply = $this->buildUserSafeLlmUnavailableReply($mode);
                $semanticFailureReply = $this->buildSemanticLlmFailureReply($route, $telemetry);
                if ($semanticFailureReply !== '') {
                    $task = $this->controlTowerCompleteTask($task, [
                        'result_status' => 'success',
                        'response_kind' => 'respond_local',
                        'response_text' => $semanticFailureReply,
                        'provider' => 'semantic_memory',
                    ]);
                    $semanticReply = $this->reply($semanticFailureReply, $channel, $sessionId, $userId, 'success', [
                        'provider_used' => 'semantic_memory',
                        'memory_type' => (string) ($telemetry['memory_type'] ?? ''),
                        'source_ids' => is_array($telemetry['source_ids'] ?? null) ? (array) $telemetry['source_ids'] : [],
                        'evidence_gate_status' => (string) ($telemetry['evidence_gate_status'] ?? ''),
                    ]);
                    $semanticReply = $this->annotateReplyWithControlTower($semanticReply, $task);
                    $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                        'llm_called' => true,
                        'error_flag' => false,
                        'error_type' => 'llm_unavailable',
                        'tool_calls_count' => 0,
                        'retry_count' => 0,
                        'provider_used' => 'semantic_memory',
                        'llm_provider_attempted' => 'llm',
                        'llm_error' => $llmFailure['message'],
                        'provider_errors' => $llmFailure['provider_errors'],
                        'provider_statuses' => $llmFailure['provider_statuses'],
                        'semantic_fallback_used' => true,
                        'task_id' => (string) ($task['task_id'] ?? ''),
                        'conversation_id' => $conversationId,
                    ]);
                    $this->telemetry()->record($tenantId, array_merge($telemetry, [
                        'message' => $text,
                        'provider_used' => 'semantic_memory',
                        'resolved_locally' => true,
                        'action' => 'respond_local',
                        'mode' => $mode,
                        'llm_request_count' => 1,
                        'session_id' => $sessionId,
                        'user_id' => $userId,
                        'project_id' => $projectId,
                        'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                        'requested_slot' => (string) (($state['requested_slot'] ?? '') ?: ''),
                        'status' => 'success',
                        'is_authenticated' => $isAuthenticated,
                        'effective_role' => $role,
                    ], $this->buildAgentOpsTelemetryBase(
                        $telemetry,
                        $tenantId,
                        $projectId,
                        $sessionId,
                        $messageId,
                        $this->latencyMs($requestStartedAt),
                        'response.emitted',
                        [
                            'llm_called' => true,
                            'error_flag' => false,
                            'error_type' => 'llm_unavailable',
                            'response_kind' => 'respond_local',
                            'response_text' => $semanticFailureReply,
                            'provider_used' => 'semantic_memory',
                            'llm_provider_attempted' => 'llm',
                            'llm_error' => $llmFailure['message'],
                            'provider_errors' => $llmFailure['provider_errors'],
                            'provider_statuses' => $llmFailure['provider_statuses'],
                            'semantic_fallback_used' => true,
                            'task_id' => (string) ($task['task_id'] ?? ''),
                            'conversation_id' => $conversationId,
                        ]
                    )));
                    try {
                        $this->telemetryService()->recordIntentMetric([
                            'tenant_id' => $tenantId,
                            'project_id' => $projectId,
                            'session_id' => $sessionId,
                            'mode' => $mode,
                            'intent' => (string) ($telemetry['classification'] ?? $result['intent'] ?? 'unknown'),
                            'action' => 'respond_local',
                            'latency_ms' => $this->latencyMs($requestStartedAt),
                            'status' => 'success',
                        ]);
                    } catch (\Throwable $ignored) {
                        // observability must not block chat response
                    }
                    return $this->attachTestInfo($semanticReply, $testMode, $telemetry, [
                        'action' => 'respond_local',
                        'resolved_locally' => true,
                        'llm_called' => true,
                        'provider_used' => 'semantic_memory',
                        'llm_provider_attempted' => 'llm',
                        'llm_error' => $llmFailure['message'],
                        'provider_errors' => $llmFailure['provider_errors'],
                        'provider_statuses' => $llmFailure['provider_statuses'],
                        'semantic_fallback_used' => true,
                    ]);
                }
                $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                    'llm_called' => true,
                    'error_flag' => true,
                    'error_type' => 'llm_unavailable',
                    'tool_calls_count' => 0,
                    'retry_count' => 0,
                    'llm_provider_attempted' => 'llm',
                    'llm_error' => $llmFailure['message'],
                    'provider_errors' => $llmFailure['provider_errors'],
                    'provider_statuses' => $llmFailure['provider_statuses'],
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]);
                $failure = $this->controlTowerFailTask($task, [
                    'error_type' => 'llm_unavailable',
                    'description' => $llmFailure['message'] !== ''
                        ? 'llm_unavailable: ' . $llmFailure['message']
                        : 'llm_unavailable',
                    'created_at' => date('c'),
                ]);
                $task = $failure['task'];
                $this->telemetry()->record($tenantId, array_merge($telemetry, [
                    'message' => $text,
                    'provider_used' => 'llm',
                    'resolved_locally' => true,
                    'action' => $action,
                    'mode' => $mode,
                    'llm_request_count' => 1,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                    'requested_slot' => (string) (($state['requested_slot'] ?? '') ?: ''),
                    'status' => 'error',
                    'is_authenticated' => $isAuthenticated,
                    'effective_role' => $role,
                    'llm_error' => $llmFailure['message'],
                    'provider_errors' => $llmFailure['provider_errors'],
                    'provider_statuses' => $llmFailure['provider_statuses'],
                ], $this->buildAgentOpsTelemetryBase(
                    $telemetry,
                    $tenantId,
                    $projectId,
                    $sessionId,
                    $messageId,
                    $this->latencyMs($requestStartedAt),
                    'response.emitted',
                    [
                        'llm_called' => true,
                        'error_flag' => true,
                        'error_type' => 'llm_unavailable',
                        'response_kind' => 'respond_local',
                        'response_text' => $userSafeLlmUnavailableReply,
                        'llm_provider_attempted' => 'llm',
                        'llm_error' => $llmFailure['message'],
                        'provider_errors' => $llmFailure['provider_errors'],
                        'provider_statuses' => $llmFailure['provider_statuses'],
                        'task_id' => (string) ($task['task_id'] ?? ''),
                        'conversation_id' => $conversationId,
                    ]
                )));
                try {
                    $this->telemetryService()->recordIntentMetric([
                        'tenant_id' => $tenantId,
                        'project_id' => $projectId,
                        'session_id' => $sessionId,
                        'mode' => $mode,
                        'intent' => (string) ($telemetry['classification'] ?? $result['intent'] ?? 'unknown'),
                        'action' => $action,
                        'latency_ms' => $this->latencyMs($requestStartedAt),
                        'status' => 'error',
                    ]);
                } catch (\Throwable $ignored) {
                    // observability must not block chat response
                }
                $errorReply = $this->annotateReplyWithControlTower(
                    $this->reply($userSafeLlmUnavailableReply, $channel, $sessionId, $userId),
                    $task,
                    $failure['incident']
                );
                return $this->attachTestInfo($errorReply, $testMode, $telemetry, [
                    'action' => $action,
                    'resolved_locally' => true,
                    'llm_called' => true,
                    'provider_used' => 'llm',
                    'llm_provider_attempted' => 'llm',
                    'llm_error' => $llmFailure['message'],
                    'provider_errors' => $llmFailure['provider_errors'],
                    'provider_statuses' => $llmFailure['provider_statuses'],
                ]);
            }
            $provider = $llmResult['provider'] ?? 'llm';
            $usage = $this->normalizeUsage((array) ($llmResult['usage'] ?? []));
            $json = $llmResult['json'] ?? null;
            $responseKind = 'send_to_llm';
            $responseText = '';
            if (is_array($json)) {
                $reply = $this->executeLlmJson($json, $channel, $sessionId, $userId, $mode);
                $responseText = (string) (($reply['data']['reply'] ?? $reply['reply'] ?? $reply['message'] ?? ''));
                $responseKind = (isset($json['command']) || (isset($json['actions']) && is_array($json['actions']) && $json['actions'] !== []))
                    ? 'execute_command'
                    : 'respond_local';
            } else {
                $responseText = (string) ($llmResult['text'] ?? '');
                if ($responseText === '') {
                    $responseText = 'Listo.';
                }
                $reply = $this->reply($responseText, $channel, $sessionId, $userId);
            }

            // --- MEMORY FLOW STEP 8: Persist Assistant Response ---
            $memory->append($threadId, 'assistant', $responseText);

            $task = $this->controlTowerCompleteTask($task, [
                'result_status' => 'success',
                'response_kind' => $responseKind,
                'response_text' => $responseText,
                'provider' => (string) $provider,
            ]);
            $reply = $this->annotateReplyWithControlTower($reply, $task);
            $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                'llm_called' => true,
                'error_flag' => false,
                'error_type' => 'none',
                'tool_calls_count' => 0,
                'retry_count' => 0,
                'usage' => $usage,
                'cost_estimate' => $llmResult['cost_estimate'] ?? null,
                'task_id' => (string) ($task['task_id'] ?? ''),
                'conversation_id' => $conversationId,
            ]);
            $this->telemetry()->record($tenantId, array_merge($telemetry, [
                'message' => $text,
                'provider_used' => $provider,
                'resolved_locally' => false,
                'action' => $action,
                'mode' => $mode,
                'llm_request_count' => 1,
                'usage' => $usage,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
                'requested_slot' => (string) (($state['requested_slot'] ?? '') ?: ''),
                'is_authenticated' => $isAuthenticated,
                'effective_role' => $role,
            ], $this->buildAgentOpsTelemetryBase(
                $telemetry,
                $tenantId,
                $projectId,
                $sessionId,
                $messageId,
                $this->latencyMs($requestStartedAt),
                'response.emitted',
                [
                    'llm_called' => true,
                    'error_flag' => false,
                    'error_type' => 'none',
                    'response_kind' => $responseKind,
                    'response_text' => $responseText,
                    'usage' => $usage,
                    'cost_estimate' => $llmResult['cost_estimate'] ?? null,
                    'task_id' => (string) ($task['task_id'] ?? ''),
                    'conversation_id' => $conversationId,
                ]
            )));
            try {
                $this->telemetryService()->recordIntentMetric([
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'session_id' => $sessionId,
                    'mode' => $mode,
                    'intent' => (string) ($telemetry['classification'] ?? $result['intent'] ?? 'unknown'),
                    'action' => $action,
                    'latency_ms' => $this->latencyMs($requestStartedAt),
                    'status' => 'success',
                ]);
                $this->telemetryService()->recordTokenUsage([
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'session_id' => $sessionId,
                    'provider' => (string) $provider,
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                ]);
            } catch (\Throwable $ignored) {
                // observability must not block chat response
            }
            return $this->attachTestInfo($reply, $testMode, $telemetry, [
                'action' => $action,
                'resolved_locally' => false,
                'llm_called' => true,
                'provider_used' => (string) $provider,
                'llm_result' => is_array($llmResult) ? $llmResult : [],
            ]);
        }

        $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
            'llm_called' => false,
            'error_flag' => true,
            'error_type' => 'route_error',
            'tool_calls_count' => 0,
            'retry_count' => 0,
            'task_id' => (string) ($task['task_id'] ?? ''),
            'conversation_id' => $conversationId,
        ]);
        $failure = $this->controlTowerFailTask($task, [
            'error_type' => 'route_error',
            'description' => ($mode === 'builder')
                ? 'No entendÃƒÆ’Ã‚Â­. CuÃƒÆ’Ã‚Â©ntame mÃƒÆ’Ã‚Â¡s sobre tu negocio o quÃƒÆ’Ã‚Â© quieres crear en tu aplicaciÃƒÆ’Ã‚Â³n.'
                : 'No entendi. Puedes decir: crear cliente nombre=Juan nit=123',
            'created_at' => date('c'),
        ]);
        $task = $failure['task'];
        $this->telemetry()->record($tenantId, array_merge($telemetry, [
            'message' => $text,
            'resolved_locally' => true,
            'action' => 'error',
            'mode' => $mode,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
            'status' => 'error',
            'is_authenticated' => $isAuthenticated,
            'effective_role' => $role,
        ], $this->buildAgentOpsTelemetryBase(
            $telemetry,
            $tenantId,
            $projectId,
            $sessionId,
            $messageId,
            $this->latencyMs($requestStartedAt),
            'response.emitted',
            [
                'llm_called' => false,
                'error_flag' => true,
                'error_type' => 'route_error',
                'response_kind' => 'error',
                'response_text' => ($mode === 'builder')
                    ? 'No entendÃƒÆ’Ã‚Â­. CuÃƒÆ’Ã‚Â©ntame mÃƒÆ’Ã‚Â¡s sobre tu negocio o quÃƒÆ’Ã‚Â© quieres crear en tu aplicaciÃƒÆ’Ã‚Â³n.'
                    : 'No entendi. Puedes decir: crear cliente nombre=Juan nit=123',
                'task_id' => (string) ($task['task_id'] ?? ''),
                'conversation_id' => $conversationId,
            ]
        )));
        try {
            $this->telemetryService()->recordIntentMetric([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'session_id' => $sessionId,
                'mode' => $mode,
                'intent' => (string) ($telemetry['classification'] ?? $result['intent'] ?? 'unknown'),
                'action' => 'error',
                'latency_ms' => $this->latencyMs($requestStartedAt),
                'status' => 'error',
            ]);
        } catch (\Throwable $ignored) {
            // observability must not block chat response
        }
        return $this->annotateReplyWithControlTower(
            $this->reply(($mode === 'builder')
                ? 'No entendÃƒÆ’Ã‚Â­. CuÃƒÆ’Ã‚Â©ntame mÃƒÆ’Ã‚Â¡s sobre tu negocio o quÃƒÆ’Ã‚Â© quieres crear en tu aplicaciÃƒÆ’Ã‚Â³n.'
                : 'No entendi. Puedes decir: crear cliente nombre=Juan nit=123', $channel, $sessionId, $userId, 'error'),
            $task,
            $failure['incident']
        );
    }

    private function buildSemanticLocalReply(IntentRouteResult $route, array $telemetry): string
    {
        if (!(bool) ($telemetry['gateway_fallback_promoted'] ?? false)) {
            return '';
        }

        return $this->buildSemanticReplyFromRoute($route);
    }

    private function buildSemanticLlmFailureReply(IntentRouteResult $route, array $telemetry): string
    {
        $hasSemanticEvidence = (bool) ($telemetry['rag_used'] ?? false)
            || trim((string) ($telemetry['evidence_gate_status'] ?? '')) === 'passed'
            || (int) ($telemetry['rag_result_count'] ?? 0) > 0;
        if (!$hasSemanticEvidence) {
            return '';
        }

        return $this->buildSemanticReplyFromRoute($route);
    }

    private function buildUserSafeLlmUnavailableReply(string $mode): string
    {
        if (strtolower(trim($mode)) === 'builder') {
            return 'No pude completar ese paso ahora. Dime en una frase corta que necesitas y sigo contigo.';
        }

        return 'Dime el dato clave o la accion que necesitas y sigo contigo.';
    }

    private function buildSemanticReplyFromRoute(IntentRouteResult $route): string
    {
        $llmRequest = $route->llmRequest();
        $semanticContext = is_array($llmRequest['semantic_context'] ?? null) ? (array) $llmRequest['semantic_context'] : [];
        $chunks = is_array($semanticContext['chunks'] ?? null) ? (array) $semanticContext['chunks'] : [];
        if ($chunks === []) {
            return '';
        }

        $parts = [];
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $snippet = $this->semanticChunkToReplySnippet((string) ($chunk['content'] ?? ''));
            if ($snippet === '' || in_array($snippet, $parts, true)) {
                continue;
            }
            $parts[] = $snippet;
            if (count($parts) >= 2) {
                break;
            }
        }

        if ($parts === []) {
            return '';
        }

        $reply = array_shift($parts);
        if ($reply === null || trim($reply) === '') {
            return '';
        }

        if ($parts !== []) {
            $reply = rtrim($reply, ". \t\n\r\0\x0B") . '. ' . ltrim((string) $parts[0]);
        }

        return trim($reply);
    }

    /**
     * @return array{message:string,provider_errors:array<string,string>,provider_statuses:array<string,string>}
     */
    private function extractLlmFailureDetails(\Throwable $error): array
    {
        $message = trim($error->getMessage());
        $providerErrors = [];
        $providerStatuses = [];
        $parts = preg_split('/\s+\|\s+/u', $message) ?: [$message];
        if ($parts !== []) {
            $message = trim((string) array_shift($parts));
        }
        foreach ($parts as $part) {
            $segment = trim((string) $part);
            if (str_starts_with($segment, 'provider_errors=')) {
                $decoded = json_decode(substr($segment, strlen('provider_errors=')), true);
                if (is_array($decoded)) {
                    foreach ($decoded as $provider => $providerMessage) {
                        $providerName = trim((string) $provider);
                        $text = trim((string) $providerMessage);
                        if ($providerName === '' || $text === '') {
                            continue;
                        }
                        $providerErrors[$providerName] = $text;
                    }
                }
                continue;
            }
            if (str_starts_with($segment, 'provider_statuses=')) {
                $decoded = json_decode(substr($segment, strlen('provider_statuses=')), true);
                if (is_array($decoded)) {
                    foreach ($decoded as $provider => $providerStatus) {
                        $providerName = trim((string) $provider);
                        $text = trim((string) $providerStatus);
                        if ($providerName === '' || $text === '') {
                            continue;
                        }
                        $providerStatuses[$providerName] = $text;
                    }
                }
            }
        }

        if ($message === '') {
            $message = 'No hay proveedores LLM disponibles.';
        }

        return [
            'message' => $message,
            'provider_errors' => $providerErrors,
            'provider_statuses' => $providerStatuses,
        ];
    }

    private function semanticChunkToReplySnippet(string $content): string
    {
        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);
        if ($content === '') {
            return '';
        }

        if (preg_match('/respuesta:\s*(.+)$/iu', $content, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        if (str_starts_with(strtolower($content), 'pregunta:')) {
            return '';
        }

        return trim($content);
    }

    public function parseLocal(string $text): array
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        if ($this->isLlmUsageRequest($normalized)) {
            return ['command' => 'LLMUsage'];
        }

        $tokens = $this->tokenize($text);
        if (!$tokens) {
            return [];
        }

        $first = strtolower($tokens[0]);
        if (in_array($first, ['probar', 'test', 'diagnostico', 'diagnosticar'], true)) {
            return ['command' => 'RunTests'];
        }

        if (in_array($normalized, ['limpiar memoria', 'olvida todo', 'reiniciar contexto', 'clear memory'], true)) {
            return ['command' => 'ClearMemory'];
        }


        if ($first === 'crear' && isset($tokens[1]) && in_array(strtolower($tokens[1]), ['tabla', 'entidad'], true)) {
            $entity = $tokens[2] ?? '';
            $fields = $this->parseFieldTokens(array_slice($tokens, 3));
            return ['command' => 'CreateEntity', 'entity' => $entity, 'fields' => $fields];
        }

        if ($first === 'crear' && isset($tokens[1]) && in_array(strtolower($tokens[1]), ['formulario', 'form'], true)) {
            $entity = $tokens[2] ?? '';
            return ['command' => 'CreateForm', 'entity' => $entity];
        }

        return $this->parseCrud($tokens);
    }

    private function executeLocal(array $parsed, string $channel, string $sessionId, string $userId, string $mode = 'app', string $tenantId = 'default'): array
    {
        $cmd = $parsed['command'] ?? '';
        if ($cmd === 'RunTests') {
            $runner = new UnitTestRunner();
            $result = $runner->run();
            $summary = $result['summary'];
            $warns = array_filter($result['tests'], fn($t) => $t['status'] === 'warn');
            $fails = array_filter($result['tests'], fn($t) => $t['status'] === 'fail');
            $warnList = $warns ? implode(', ', array_map(fn($t) => $t['name'], $warns)) : '';
            $failList = $fails ? implode(', ', array_map(fn($t) => $t['name'], $fails)) : '';
            $reply = "Pruebas: {$summary['passed']} ok, {$summary['warned']} warn, {$summary['failed']} fail.";
            if ($warnList !== '') {
                $reply .= " Warn: {$warnList}.";
            }
            if ($failList !== '') {
                $reply .= " Fail: {$failList}.";
            }
            $acid = null;
            try {
                $acidRunner = new AcidChatRunner();
                $acid = $acidRunner->run($tenantId ?: 'default', ['save' => true]);
                $acidSummary = $acid['summary'] ?? [];
                $reply .= " Chat ÃƒÆ’Ã‚Â¡cido: " . ($acidSummary['passed'] ?? 0) . " ok, " . ($acidSummary['failed'] ?? 0) . " fail.";
            } catch (\Throwable $e) {
                $reply .= " Chat ÃƒÆ’Ã‚Â¡cido: error al ejecutar.";
            }
            return $this->reply($reply, $channel, $sessionId, $userId, 'success', [
                'unit' => $result,
                'acid' => $acid ?? null,
            ]);
        }

        if ($cmd === 'LLMUsage') {
            $summary = $this->buildLlmUsageSummary($tenantId);
            return $this->reply($summary['reply'], $channel, $sessionId, $userId, 'success', $summary['data']);
        }

        if ($cmd === 'CreateEntity') {
            if ($mode === 'app') {
                return $this->reply('Estas en modo app. Usa el chat creador para crear tablas.', $channel, $sessionId, $userId, 'error');
            }
            $entityName = (string) ($parsed['entity'] ?? '');
            if ($entityName === '') {
                return $this->reply('Necesito el nombre de la tabla.', $channel, $sessionId, $userId, 'error');
            }
            if ($this->entityExists($entityName)) {
                return $this->reply('La tabla ' . $entityName . ' ya existe. No la voy a duplicar.', $channel, $sessionId, $userId, 'success', [
                    'entity' => ['name' => $entityName],
                    'already_exists' => true,
                ]);
            }
            $entity = $this->builder->build($entityName, $parsed['fields'] ?? []);
            $this->writer->writeEntity($entity);
            try {
                $this->migrator()->migrateEntity($entity, true);
            } catch (\Throwable $e) {
                $rawError = (string) $e->getMessage();
                $human = str_contains($rawError, 'SQLSTATE')
                    ? 'Tabla de contrato creada. Falta conectar correctamente la base de datos para crear la tabla fisica.'
                    : 'Tabla de contrato creada, pero no pude migrar a DB.';
                return $this->reply($human, $channel, $sessionId, $userId, 'warn', [
                    'entity' => $entity,
                    'error' => $rawError,
                ]);
            }
            return $this->reply('Tabla creada: ' . $entity['name'], $channel, $sessionId, $userId, 'success', ['entity' => $entity]);
        }

        if ($cmd === 'CreateForm') {
            if ($mode === 'app') {
                return $this->reply('Estas en modo app. Usa el chat creador para crear formularios.', $channel, $sessionId, $userId, 'error');
            }
            $entityName = (string) ($parsed['entity'] ?? '');
            if ($entityName === '') {
                return $this->reply('Necesito la entidad para el formulario.', $channel, $sessionId, $userId, 'error');
            }
            if ($this->formExistsForEntity($entityName)) {
                return $this->reply('El formulario de ' . $entityName . ' ya existe. No lo voy a duplicar.', $channel, $sessionId, $userId, 'success', [
                    'form' => ['name' => $entityName . '.form'],
                    'already_exists' => true,
                ]);
            }
            $entity = $this->entities->get($entityName);
            $form = $this->wizard->buildFromEntity($entity);
            $this->writer->writeForm($form);
            return $this->reply('Formulario creado para ' . $entityName, $channel, $sessionId, $userId, 'success', ['form' => $form]);
        }

        if (in_array($cmd, ['CreateRecord', 'QueryRecords', 'ReadRecord', 'UpdateRecord', 'DeleteRecord'], true)) {
            if ($mode === 'builder') {
                return $this->reply('Estas en modo creador. Usa el chat app para registrar datos.', $channel, $sessionId, $userId, 'error');
            }
            return $this->executeCrud($parsed, $channel, $sessionId, $userId);
        }

        return $this->reply('Comando no soportado.', $channel, $sessionId, $userId, 'error');
    }

    private function executeIntent(array $intent, string $channel, string $sessionId, string $userId): array
    {
        $actions = $intent['actions'] ?? [];
        if (!is_array($actions) || count($actions) === 0) {
            return $this->reply($this->buildHelpMessage(), $channel, $sessionId, $userId);
        }

        $results = [];
        $replyParts = [];
        foreach ($actions as $action) {
            $type = strtolower((string) ($action['type'] ?? 'help'));
            switch ($type) {
                case 'create_entity':
                    $entityName = (string) ($action['entity'] ?? '');
                    if ($entityName === '') {
                        $replyParts[] = 'Falta nombre de tabla.';
                        break;
                    }
                    $entity = $this->builder->build($entityName, $action['fields'] ?? [], ['label' => $action['label'] ?? null]);
                    $this->writer->writeEntity($entity);
                    $this->migrator()->migrateEntity($entity, true);
                    $results[] = ['entity' => $entity];
                    $replyParts[] = 'Tabla creada: ' . $entity['name'];
                    break;
                case 'add_field':
                    $replyParts[] = 'Agregar campos: pendiente.';
                    break;
                case 'create_form':
                    $entityName = (string) ($action['entity'] ?? '');
                    if ($entityName === '') {
                        $replyParts[] = 'Falta entidad para formulario.';
                        break;
                    }
                    $entity = $this->entities->get($entityName);
                    $form = $this->wizard->buildFromEntity($entity);
                    $this->writer->writeForm($form);
                    $results[] = ['form' => $form];
                    $replyParts[] = 'Formulario creado: ' . ($form['name'] ?? $entityName);
                    break;
                case 'create_record':
                    $results[] = $this->command()->createRecord((string) ($action['entity'] ?? ''), (array) ($action['data'] ?? []));
                    $replyParts[] = 'Registro creado.';
                    break;
                case 'query_records':
                    $results[] = $this->command()->queryRecords((string) ($action['entity'] ?? ''), (array) ($action['filters'] ?? []), 20, 0);
                    $replyParts[] = 'Consulta lista.';
                    break;
                case 'update_record':
                    $results[] = $this->command()->updateRecord((string) ($action['entity'] ?? ''), $action['id'] ?? null, (array) ($action['data'] ?? []));
                    $replyParts[] = 'Registro actualizado.';
                    break;
                case 'delete_record':
                    $results[] = $this->command()->deleteRecord((string) ($action['entity'] ?? ''), $action['id'] ?? null);
                    $replyParts[] = 'Registro eliminado.';
                    break;
                case 'run_tests':
                    $runner = new UnitTestRunner();
                    $result = $runner->run();
                    $results[] = $result;
                    $summary = $result['summary'];
                    $warns = array_filter($result['tests'], fn($t) => $t['status'] === 'warn');
                    $fails = array_filter($result['tests'], fn($t) => $t['status'] === 'fail');
                    $warnList = $warns ? implode(', ', array_map(fn($t) => $t['name'], $warns)) : '';
                    $failList = $fails ? implode(', ', array_map(fn($t) => $t['name'], $fails)) : '';
                    $line = "Pruebas: {$summary['passed']} ok, {$summary['warned']} warn, {$summary['failed']} fail.";
                    if ($warnList !== '') {
                        $line .= " Warn: {$warnList}.";
                    }
                    if ($failList !== '') {
                        $line .= " Fail: {$failList}.";
                    }
                    try {
                        $tenantId = getenv('TENANT_KEY') ?: (getenv('TENANT_ID') ?: 'default');
                        $acidRunner = new AcidChatRunner();
                        $acid = $acidRunner->run((string) $tenantId, ['save' => true]);
                        $results[] = ['acid' => $acid];
                        $acidSummary = $acid['summary'] ?? [];
                        $line .= " Chat ÃƒÆ’Ã‚Â¡cido: " . ($acidSummary['passed'] ?? 0) . " ok, " . ($acidSummary['failed'] ?? 0) . " fail.";
                    } catch (\Throwable $e) {
                        $line .= " Chat ÃƒÆ’Ã‚Â¡cido: error al ejecutar.";
                    }
                    $replyParts[] = $line;
                    break;
                case 'help':
                default:
                    $replyParts[] = $this->buildHelpMessage();
                    break;
            }
        }

        $reply = implode("\n", $replyParts);
        return $this->reply($reply, $channel, $sessionId, $userId, 'success', ['actions' => $actions, 'results' => $results]);
    }

    private function executeCrud(array $parsed, string $channel, string $sessionId, string $userId): array
    {
        $cmd = $parsed['command'];
        $entity = (string) ($parsed['entity'] ?? '');
        if ($entity === '') {
            return $this->reply('Falta entidad.', $channel, $sessionId, $userId, 'error');
        }
        $data = [];
        switch ($cmd) {
            case 'CreateRecord':
                $data = $this->command()->createRecord($entity, $parsed['data'] ?? []);
                $reply = 'Registro creado en ' . $entity;
                break;
            case 'QueryRecords':
                $data = $this->command()->queryRecords($entity, $parsed['filters'] ?? [], 20, 0);
                $reply = 'Resultados para ' . $entity . ': ' . count($data);
                break;
            case 'ReadRecord':
                $data = $this->command()->readRecord($entity, $parsed['id'] ?? null, true);
                $reply = 'Registro: ' . $entity;
                break;
            case 'UpdateRecord':
                $data = $this->command()->updateRecord($entity, $parsed['id'] ?? null, $parsed['data'] ?? []);
                $reply = 'Registro actualizado en ' . $entity;
                break;
            case 'DeleteRecord':
                $data = $this->command()->deleteRecord($entity, $parsed['id'] ?? null);
                $reply = 'Registro eliminado en ' . $entity;
                break;
            default:
                return $this->reply('Comando no soportado.', $channel, $sessionId, $userId, 'error');
        }
        return $this->reply($reply, $channel, $sessionId, $userId, 'success', $data);
    }

    private function parseCrud(array $tokens): array
    {
        $verb = strtolower(array_shift($tokens));
        $verbMap = [
            'crear' => 'CreateRecord',
            'nuevo' => 'CreateRecord',
            'agregar' => 'CreateRecord',
            'add' => 'CreateRecord',
            'listar' => 'QueryRecords',
            'lista' => 'QueryRecords',
            'ver' => 'QueryRecords',
            'buscar' => 'QueryRecords',
            'consulta' => 'QueryRecords',
            'actualizar' => 'UpdateRecord',
            'editar' => 'UpdateRecord',
            'update' => 'UpdateRecord',
            'eliminar' => 'DeleteRecord',
            'borrar' => 'DeleteRecord',
            'delete' => 'DeleteRecord',
            'leer' => 'ReadRecord',
        ];

        if (!isset($verbMap[$verb])) {
            return [];
        }

        $entity = '';
        $data = [];
        $filters = [];
        $id = null;
        foreach ($tokens as $token) {
            if (strpos($token, '=') !== false || strpos($token, ':') !== false) {
                $sep = strpos($token, '=') !== false ? '=' : ':';
                [$rawKey, $rawVal] = array_pad(explode($sep, $token, 2), 2, '');
                $key = trim($rawKey);
                $val = trim($rawVal);
                if ($key === '') {
                    continue;
                }
                if (strtolower($key) === 'id') {
                    $id = $val;
                    continue;
                }
                $data[$key] = $val;
                $filters[$key] = $val;
                continue;
            }
            if ($entity === '') {
                $entity = $token;
            }
        }
        if ($entity === '') {
            return [];
        }

        $command = $verbMap[$verb];
        if ($command === 'QueryRecords' && $id !== null && $id !== '') {
            $command = 'ReadRecord';
        }

        return [
            'command' => $command,
            'entity' => $entity,
            'data' => $data,
            'filters' => $filters,
            'id' => $id,
        ];
    }

    private function parseFieldTokens(array $tokens): array
    {
        $fields = [];
        foreach ($tokens as $token) {
            if (!str_contains($token, ':') && !str_contains($token, '=')) {
                continue;
            }
            $sep = str_contains($token, ':') ? ':' : '=';
            [$rawName, $rawType] = array_pad(explode($sep, $token, 2), 2, 'string');
            $name = trim($rawName);
            $type = trim($rawType);
            if ($name === '') {
                continue;
            }
            $fields[] = ['name' => $name, 'type' => $type];
        }
        return $fields;
    }

    private function tokenize(string $message): array
    {
        $tokens = [];
        $len = strlen($message);
        $buf = '';
        $inQuote = false;
        $quoteChar = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $message[$i];
            if ($inQuote) {
                if ($ch === $quoteChar) {
                    $inQuote = false;
                    continue;
                }
                if ($ch === '\\' && $i + 1 < $len) {
                    $buf .= $message[$i + 1];
                    $i++;
                    continue;
                }
                $buf .= $ch;
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $inQuote = true;
                $quoteChar = $ch;
                continue;
            }
            if (ctype_space($ch)) {
                if ($buf !== '') {
                    $tokens[] = $buf;
                    $buf = '';
                }
                continue;
            }
            $buf .= $ch;
        }

        if ($buf !== '') {
            $tokens[] = $buf;
        }
        return $tokens;
    }

    public function buildHelpMessage(string $mode = 'app', string $projectId = ''): string
    {
        if ($projectId === '') {
            $projectId = (string) ($_SESSION['current_project_id'] ?? '');
        }
        if ($projectId === '') {
            $registry = new ProjectRegistry();
            $manifest = $registry->resolveProjectFromManifest();
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        if ($mode === 'builder') {
            return $this->buildHelpMessageBuilder($projectId);
        }
        return $this->buildHelpMessageApp($projectId);
    }

    private function buildHelpMessageApp(string $projectId): string
    {
        $help = $this->loadTrainingHelp();
        $graph = (new CapabilityGraph())->build($projectId, 'app');
        $formNames = array_values(array_filter(array_map(
            static fn(array $f): string => (string) ($f['title'] ?? $f['name'] ?? ''),
            $graph['forms'] ?? []
        )));
        $entityNames = array_values(array_filter(array_map(
            static fn(array $e): string => (string) ($e['name'] ?? ''),
            $graph['entities'] ?? []
        )));
        $entityLabels = array_values(array_filter(array_map(
            static fn(array $e): string => (string) ($e['label'] ?? $e['name'] ?? ''),
            $graph['entities'] ?? []
        )));

        $stateKey = count($entityNames) === 0 ? 'empty' : 'ready';
        $lines = [];
        $lines[] = 'Hola, soy Cami. Estoy lista para ayudarte.';
        $lines = array_merge($lines, $help['app']['intro'] ?? []);
        $lines = array_merge($lines, $help['app']['steps'][$stateKey] ?? []);
        $lines[] = 'Ejemplos rapidos:';
        $examples = $this->buildCrudExamples($entityNames, $help['app']['examples'] ?? []);
        foreach ($examples as $ex) {
            $lines[] = '- ' . $ex;
        }
        $lines[] = 'Formularios activos: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 5)) : 'sin formularios');
        $lines[] = 'Entidades activas: ' . (count($entityLabels) ? implode(', ', array_slice($entityLabels, 0, 5)) : 'sin entidades');
        $question = $help['app']['next_questions'][$stateKey] ?? '';
        if ($question !== '') {
            $lines[] = $question;
        }
        $lines[] = 'Puedes enviar archivos (audio/imagen/PDF). Se procesaran cuando el OCR/voz este habilitado.';
        return implode("\n", $lines);
    }

    private function buildHelpMessageBuilder(string $projectId): string
    {
        $help = $this->loadTrainingHelp();
        $graph = (new CapabilityGraph())->build($projectId, 'builder');
        $formNames = array_values(array_filter(array_map(
            static fn(array $f): string => (string) ($f['title'] ?? $f['name'] ?? ''),
            $graph['forms'] ?? []
        )));
        $entityNames = array_values(array_filter(array_map(
            static fn(array $e): string => (string) ($e['name'] ?? ''),
            $graph['entities'] ?? []
        )));
        $entityLabels = array_values(array_filter(array_map(
            static fn(array $e): string => (string) ($e['label'] ?? $e['name'] ?? ''),
            $graph['entities'] ?? []
        )));

        $stateKey = count($entityNames) === 0 ? 'empty' : (count($formNames) === 0 ? 'no_forms' : 'ready');
        $lines = [];
        $lines[] = 'Estas en el modo CREADOR.';
        $lines = array_merge($lines, $help['builder']['intro'] ?? []);
        $lines = array_merge($lines, $help['builder']['steps'][$stateKey] ?? []);
        $lines[] = 'Ejemplos rapidos:';
        $examples = $this->buildBuilderExamples($entityNames, $help['builder']['examples'] ?? []);
        foreach ($examples as $ex) {
            $lines[] = '- ' . $ex;
        }
        $lines[] = 'Formularios activos: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 5)) : 'sin formularios');
        $lines[] = 'Entidades activas: ' . (count($entityLabels) ? implode(', ', array_slice($entityLabels, 0, 5)) : 'sin entidades');
        $question = $help['builder']['next_questions'][$stateKey] ?? '';
        if ($question !== '') {
            $lines[] = $question;
        }
        return implode("\n", $lines);
    }

    private function buildCrudExamples(array $entityNames, array $fallback): array
    {
        if (empty($entityNames)) {
            return $fallback;
        }
        $entity = $this->slugEntity($entityNames[0]);
        return [
            'crear ' . $entity . ' nombre=Ana',
            'listar ' . $entity,
            'actualizar ' . $entity . ' id=1 campo=valor',
            'eliminar ' . $entity . ' id=1',
        ];
    }

    private function buildBuilderExamples(array $entityNames, array $fallback): array
    {
        if (empty($entityNames)) {
            return $fallback;
        }
        $entity = $this->slugEntity($entityNames[0]);
        return [
            'crear tabla ' . $entity . ' nombre:texto',
            'crear formulario ' . $entity,
            'probar sistema',
        ];
    }

    private function slugEntity(string $label): string
    {
        $label = mb_strtolower($label, 'UTF-8');
        $label = preg_replace('/[^a-z0-9ÃƒÆ’Ã‚Â¡ÃƒÆ’Ã‚Â©ÃƒÆ’Ã‚Â­ÃƒÆ’Ã‚Â³ÃƒÆ’Ã‚ÂºÃƒÆ’Ã‚Â±ÃƒÆ’Ã‚Â¼\\s_-]/u', '', $label) ?? $label;
        $label = preg_replace('/\\s+/', '_', trim($label)) ?? $label;
        return $label;
    }

    private function loadTrainingHelp(): array
    {
        $path = APP_ROOT . '/contracts/agents/conversation_training_base.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [];
        }
        return (array) ($json['help'] ?? []);
    }

    private function isHelpIntent(string $text): bool
    {
        $text = trim(mb_strtolower($text));
        if ($text === '') return true;
        $keywords = ['hola', 'buenas', 'buenos', 'ayuda', 'help', 'menu', 'funciones', 'que puedes', 'que haces', 'opciones', 'guia'];
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function isCrudGuideTrigger(string $text): bool
    {
        $text = trim(mb_strtolower($text));
        if ($text === '') {
            return false;
        }
        $patterns = [
            'como creo',
            'como crear',
            'como hago para crear',
            'como puedo crear',
            'como registro',
            'como agrego',
            'como se crea',
            'explicame como',
            'explica como',
            'dime como crear',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isLlmUsageRequest(string $text): bool
    {
        $patterns = [
            'consumo ia',
            'uso ia',
            'tokens ia',
            'cuantos tokens',
            'cuantos request',
            'requests ia',
            'gasto ia',
            'uso de llm',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeUsage(array $usage): array
    {
        $prompt = (int) ($usage['prompt_tokens']
            ?? $usage['promptTokenCount']
            ?? $usage['input_tokens']
            ?? $usage['inputTokenCount']
            ?? 0);
        $completion = (int) ($usage['completion_tokens']
            ?? $usage['candidatesTokenCount']
            ?? $usage['output_tokens']
            ?? $usage['outputTokenCount']
            ?? 0);
        $total = (int) ($usage['total_tokens']
            ?? $usage['totalTokenCount']
            ?? ($prompt + $completion));

        return [
            'prompt_tokens' => max(0, $prompt),
            'completion_tokens' => max(0, $completion),
            'total_tokens' => max(0, $total),
        ];
    }

    private function buildLlmUsageSummary(string $tenantId): array
    {
        $tenantId = trim($tenantId) !== '' ? trim($tenantId) : 'default';
        $safeTenant = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $tenantId) ?? 'default';
        $dir = PROJECT_ROOT . '/storage/tenants/' . trim($safeTenant, '_') . '/telemetry';
        $file = $dir . '/' . date('Y-m-d') . '.log.jsonl';

        if (!is_file($file)) {
            return [
                'reply' => 'Hoy no hay consumo IA registrado. Requests IA: 0, Tokens: 0.',
                'data' => [
                    'llm_requests' => 0,
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'providers' => [],
                    'source' => $file,
                ],
            ];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $llmRequests = 0;
        $promptTokens = 0;
        $completionTokens = 0;
        $totalTokens = 0;
        $providers = [];

        foreach ($lines as $line) {
            $row = json_decode((string) $line, true);
            if (!is_array($row)) {
                continue;
            }
            $provider = (string) ($row['provider_used'] ?? '');
            if ($provider === '') {
                continue;
            }
            $llmRequests++;
            $providers[$provider] = (int) ($providers[$provider] ?? 0) + 1;
            $usage = $this->normalizeUsage((array) ($row['usage'] ?? []));
            $promptTokens += (int) ($usage['prompt_tokens'] ?? 0);
            $completionTokens += (int) ($usage['completion_tokens'] ?? 0);
            $totalTokens += (int) ($usage['total_tokens'] ?? 0);
        }

        arsort($providers);
        $providerText = empty($providers)
            ? 'sin llamadas a proveedor'
            : implode(', ', array_map(
                static fn(string $name, int $count): string => $name . ':' . $count,
                array_keys($providers),
                array_values($providers)
            ));

        $reply = 'Consumo IA de hoy:'
            . "\n- Requests IA: " . $llmRequests
            . "\n- Prompt tokens: " . $promptTokens
            . "\n- Completion tokens: " . $completionTokens
            . "\n- Total tokens: " . $totalTokens
            . "\n- Proveedores: " . $providerText;

        return [
            'reply' => $reply,
            'data' => [
                'llm_requests' => $llmRequests,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'providers' => $providers,
                'source' => $file,
            ],
        ];
    }

    private function buildContext(): array
    {
        $catalog = new ContractsCatalog();
        $entities = [];
        foreach ($catalog->entities() as $path) {
            $entities[] = basename($path, '.entity.json');
        }
        $forms = [];
        foreach ($catalog->forms() as $path) {
            $forms[] = basename($path, '.json');
        }
        return [
            'entities' => $entities,
            'forms' => $forms,
        ];
    }

    private function reply(string $text, string $channel, string $sessionId, string $userId, string $status = 'success', array $data = []): array
    {
        return [
            'status' => $status,
            'message' => $status === 'success' ? 'OK' : $text,
            'data' => array_merge([
                'reply' => $text,
                'channel' => $channel,
                'session_id' => $sessionId,
                'user_id' => $userId,
            ], $data),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function isTestMode(array $payload): bool
    {
        if (array_key_exists('test_mode', $payload)) {
            return (bool) $payload['test_mode'];
        }

        $meta = is_array($payload['meta'] ?? null) ? (array) $payload['meta'] : [];
        return (bool) ($meta['test_mode'] ?? false);
    }

    /**
     * @param array<string,mixed> $reply
     * @param array<string,mixed> $telemetry
     * @param array<string,mixed> $runtime
     * @return array<string,mixed>
     */
    private function attachTestInfo(array $reply, bool $testMode, array $telemetry, array $runtime = []): array
    {
        if (!$testMode) {
            return $reply;
        }

        $data = is_array($reply['data'] ?? null) ? (array) $reply['data'] : [];
        $data['test_info'] = $this->buildTestInfo($telemetry, $runtime);
        $reply['data'] = $data;

        return $reply;
    }

    /**
     * @param array<string,mixed> $telemetry
     * @param array<string,mixed> $runtime
     * @return array<string,mixed>
     */
    private function buildTestInfo(array $telemetry, array $runtime = []): array
    {
        $t4 = microtime(true);
        $retrieval = is_array($telemetry['retrieval'] ?? null) ? (array) $telemetry['retrieval'] : [];
        $semanticIntentCollection = trim((string) ($telemetry['semantic_intent_collection'] ?? ''));
        $semanticIntentSource = trim((string) ($telemetry['semantic_intent_source'] ?? ''));
        $semanticIntentUsed = $semanticIntentSource === 'agent_training';
        $retrievalAttempted = (bool) ($retrieval['retrieval_attempted'] ?? false);
        $embeddingUsed = $retrievalAttempted || $semanticIntentUsed;
        $embeddingModel = $embeddingUsed ? (string) (getenv('EMBEDDING_MODEL') ?: 'gemini-embedding-001') : '';
        $embeddingDimensions = $embeddingUsed
            ? max(1, (int) (getenv('EMBEDDING_OUTPUT_DIMENSIONALITY') ?: 768))
            : 0;
        $collection = trim((string) ($retrieval['collection'] ?? ''));
        if ($collection === '' && $semanticIntentCollection !== '') {
            $collection = $semanticIntentCollection;
        }
        $memoryType = trim((string) ($retrieval['memory_type'] ?? ''));
        if ($memoryType === '' && $semanticIntentUsed) {
            $memoryType = trim((string) ($telemetry['semantic_intent_memory_type'] ?? ''));
        }
        $hits = 0;
        if ($retrieval !== []) {
            $hits = max(
                0,
                (int) ($telemetry['rag_result_count_raw'] ?? $retrieval['retrieval_result_count'] ?? $telemetry['rag_result_count'] ?? 0)
            );
        } elseif ($semanticIntentUsed) {
            $hits = max(0, (int) ($telemetry['semantic_intent_hit_count'] ?? 0));
        }
        $topK = 0;
        if ($retrieval !== []) {
            $topK = max(0, (int) ($retrieval['top_k'] ?? 0));
        } elseif ($semanticIntentUsed) {
            $topK = max(0, (int) ($telemetry['semantic_intent_top_k'] ?? 0));
        }
        $evidenceCount = 0;
        if ($retrieval !== []) {
            $evidenceCount = max(0, (int) ($telemetry['rag_result_count'] ?? 0));
        } elseif ($semanticIntentUsed) {
            $evidenceCount = $hits;
        }
        $providerUsed = trim((string) ($runtime['provider_used'] ?? $telemetry['provider_used'] ?? ''));
        if (strtolower($providerUsed) === 'llm') {
            $providerUsed = '';
        }
        $providerErrorsRaw = is_array($runtime['provider_errors'] ?? null) ? (array) $runtime['provider_errors'] : [];
        $providerStatusesRaw = is_array($runtime['provider_statuses'] ?? null) ? (array) $runtime['provider_statuses'] : [];
        $llmCalled = (bool) ($runtime['llm_called'] ?? $telemetry['llm_called'] ?? false);
        $llmProvider = trim((string) ($runtime['llm_provider_attempted'] ?? ''));
        if ($llmProvider === '' && $llmCalled) {
            $llmProvider = $providerUsed !== '' ? $providerUsed : 'llm';
        } elseif ($llmProvider === 'llm' && $providerUsed !== '') {
            $llmProvider = $providerUsed;
        } elseif (($llmProvider === '' || $llmProvider === 'llm') && $providerUsed === '' && $providerStatusesRaw !== []) {
            $firstProvider = array_key_first($providerStatusesRaw);
            if (is_string($firstProvider) && trim($firstProvider) !== '') {
                $llmProvider = $firstProvider;
            }
        }
        $providerUsedLabel = $this->normalizeTestModeProviderLabel($providerUsed);
        $llmProviderLabel = $this->normalizeTestModeProviderLabel($llmProvider);
        $llmModel = $llmCalled
            ? $this->resolveTestModeLlmModel(
                is_array($runtime['llm_result'] ?? null) ? (array) $runtime['llm_result'] : [],
                $providerUsed
            )
            : '';

        return [
            'route_path' => trim((string) ($telemetry['route_path'] ?? '')) ?: 'unknown',
            'classification' => trim((string) ($telemetry['classification'] ?? '')) ?: 'unknown',
            'action' => trim((string) ($runtime['action'] ?? $telemetry['action'] ?? '')) ?: 'unknown',
            'resolved_locally' => (bool) ($runtime['resolved_locally'] ?? $telemetry['resolved_locally'] ?? false),
            'route_reason' => trim((string) ($telemetry['route_reason'] ?? '')) ?: 'unknown',
            'embedding_model' => $embeddingModel,
            'embedding_dimensions' => $embeddingDimensions,
            'embeddings_used' => $embeddingUsed,
            'vector_store' => $collection !== '' ? 'qdrant' : 'none',
            'collection' => $collection,
            'memory_type' => $memoryType !== '' ? $memoryType : 'none',
            'hits' => $hits,
            'top_k' => $topK,
            'evidence_count' => $evidenceCount,
            'top_score' => is_numeric($telemetry['retrieval_top_score'] ?? $telemetry['semantic_intent_similarity_score'] ?? null)
                ? (float) ($telemetry['retrieval_top_score'] ?? $telemetry['semantic_intent_similarity_score'])
                : 0.0,
            'llm_provider' => $llmCalled ? ($llmProviderLabel !== '' ? $llmProviderLabel : 'llm') : 'none',
            'llm_model' => $llmModel,
            'llm_error' => trim((string) ($runtime['llm_error'] ?? '')),
            'provider_errors' => $this->normalizeTestModeProviderMap($providerErrorsRaw),
            'provider_statuses' => $this->normalizeTestModeProviderMap($providerStatusesRaw),
            'semantic_fallback_used' => (bool) ($runtime['semantic_fallback_used'] ?? false),
            'agents_used' => $this->collectTestModeAgentsUsed(
                $telemetry,
                $runtime,
                $providerUsedLabel !== ''
                    ? $providerUsedLabel
                    : ($llmProviderLabel !== '' ? $llmProviderLabel : $providerUsed),
                $llmCalled
            ),
        ];
    }

    private function normalizeTestModeProviderLabel(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'deepseek' => 'deepseek_direct',
            default => strtolower(trim($provider)),
        };
    }

    /**
     * @param array<string,mixed> $providerMap
     * @return array<string,mixed>
     */
    private function normalizeTestModeProviderMap(array $providerMap): array
    {
        $normalized = [];
        foreach ($providerMap as $provider => $value) {
            $normalized[$this->normalizeTestModeProviderLabel((string) $provider)] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $llmResult
     */
    private function resolveTestModeLlmModel(array $llmResult, string $providerUsed): string
    {
        $raw = is_array($llmResult['raw'] ?? null) ? (array) $llmResult['raw'] : [];
        $rawData = is_array($raw['data'] ?? null) ? (array) $raw['data'] : [];
        $candidates = [
            trim((string) ($llmResult['model'] ?? '')),
            trim((string) ($raw['model'] ?? '')),
            trim((string) ($rawData['model'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return match (strtolower(trim($providerUsed))) {
            'gemini' => (string) (getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash-lite'),
            'deepseek' => (string) (getenv('DEEPSEEK_MODEL') ?: 'deepseek-chat'),
            'openrouter' => (string) (getenv('OPENROUTER_MODEL') ?: 'openrouter/free'),
            'claude' => (string) (getenv('CLAUDE_MODEL') ?: 'claude-3-5-haiku-latest'),
            'groq' => (string) (getenv('GROQ_MODEL') ?: ''),
            default => '',
        };
    }

    /**
     * @param array<string,mixed> $telemetry
     * @param array<string,mixed> $runtime
     * @return array<int,string>
     */
    private function collectTestModeAgentsUsed(array $telemetry, array $runtime, string $providerUsed, bool $llmCalled): array
    {
        $agentsUsed = [];
        $skillSelected = trim((string) ($telemetry['skill_selected'] ?? ''));
        $moduleUsed = trim((string) ($telemetry['module_used'] ?? ''));
        $skillGroup = trim((string) ($telemetry['skill_group'] ?? ''));
        $semanticIntentSource = trim((string) ($telemetry['semantic_intent_source'] ?? ''));
        $agentToolsAction = trim((string) ($telemetry['agent_tools_action'] ?? ''));

        if ($semanticIntentSource !== '') {
            $agentsUsed[] = $semanticIntentSource;
        }
        if ((bool) ($telemetry['rag_attempted'] ?? false)) {
            $agentsUsed[] = 'rag';
        }
        if ($skillSelected !== '' && $skillSelected !== 'none') {
            $agentsUsed[] = 'skill:' . $skillSelected;
        }
        if ($skillGroup !== '' && $skillGroup !== 'unknown') {
            $agentsUsed[] = 'skill_group:' . $skillGroup;
        }
        if ($moduleUsed !== '' && $moduleUsed !== 'none') {
            $agentsUsed[] = 'module:' . $moduleUsed;
        }
        if ($agentToolsAction !== '' && $agentToolsAction !== 'none') {
            $agentsUsed[] = 'agent_tools:' . $agentToolsAction;
        }
        if ($llmCalled) {
            $agentsUsed[] = 'llm:' . ($providerUsed !== '' ? $providerUsed : 'llm');
        }

        $agentsUsed = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $agentsUsed
        ), static fn(string $value): bool => $value !== '')));

        return $agentsUsed;
    }

    private function denyExecutableChat(
        string $channel,
        string $sessionId,
        string $userId,
        int $httpCode,
        string $reason
    ): array {
        $reply = $this->reply('Acceso no autorizado para este recurso.', $channel, $sessionId, $userId, 'error', [
            'error_code' => $reason,
            'http_code' => $httpCode,
        ]);
        return $reply;
    }

    private function isExecutableIntentOrAction(\App\Core\IntentRouteResult $route, array $gatewayResult): bool
    {
        if ($route->isCommand()) {
            return true;
        }

        $routeTelemetry = $route->telemetry();
        $actionType = strtoupper(trim((string) ($routeTelemetry['action_type'] ?? '')));
        if ($actionType === 'EXECUTABLE') {
            return true;
        }

        $rawAction = strtolower(trim((string) ($gatewayResult['action'] ?? '')));
        if ($rawAction === 'execute_command') {
            return true;
        }

        return false;
    }

    private function enforceExecutableChatSecurity(
        \App\Core\IntentRouteResult $route,
        array $gatewayResult,
        bool $chatExecAuthRequired,
        bool $isAuthenticated,
        string $role,
        string $tenantId,
        string $authTenantId,
        string $channel,
        string $sessionId,
        string $userId
    ): ?array {
        if (!$chatExecAuthRequired) {
            return null;
        }
        if (!$this->isExecutableIntentOrAction($route, $gatewayResult)) {
            return null;
        }
        if (!$isAuthenticated) {
            return $this->denyExecutableChat($channel, $sessionId, $userId, 401, 'missing_auth');
        }

        $normalizedRole = strtolower(trim($role));
        if ($normalizedRole === '' || $normalizedRole === 'guest') {
            return $this->denyExecutableChat($channel, $sessionId, $userId, 403, 'invalid_role');
        }

        if ($authTenantId === '' || $tenantId === '' || $authTenantId !== $tenantId) {
            return $this->denyExecutableChat($channel, $sessionId, $userId, 403, 'tenant_mismatch');
        }

        $routeTelemetry = $route->telemetry();
        $gateResults = is_array($routeTelemetry['gate_results'] ?? null) ? (array) $routeTelemetry['gate_results'] : [];
        $hardGates = ['allowlist_gate', 'schema_gate', 'auth_rbac_gate', 'tenant_scope_gate'];
        foreach ($gateResults as $gate) {
            if (!is_array($gate)) {
                continue;
            }
            $name = strtolower(trim((string) ($gate['name'] ?? '')));
            if (!in_array($name, $hardGates, true)) {
                continue;
            }
            $required = (bool) ($gate['required'] ?? false);
            $passed = (bool) ($gate['passed'] ?? true);
            if ($required && !$passed) {
                return $this->denyExecutableChat($channel, $sessionId, $userId, 403, 'hard_gate_failed:' . $name);
            }
        }

        return null;
    }

    private function normalizeRole(string $role): string
    {
        $role = mb_strtolower(trim($role), 'UTF-8');
        $map = [
            'administrador' => 'admin',
            'admin' => 'admin',
            'dueno' => 'admin',
            'dueÃƒÆ’Ã‚Â±o' => 'admin',
            'owner' => 'admin',
            'vendedora' => 'seller',
            'vendedor' => 'seller',
            'seller' => 'seller',
            'contadora' => 'accountant',
            'contador' => 'accountant',
            'accountant' => 'accountant',
            'guest' => 'guest',
        ];
        return $map[$role] ?? ($role !== '' ? $role : 'guest');
    }

    private function stableTenantInt(string $tenantId): int
    {
        $hash = crc32((string) $tenantId);
        $unsigned = (int) sprintf('%u', $hash);
        // keep inside signed INT range used by MySQL int columns
        $max = 2147483647;
        $value = $unsigned % $max;
        return $value > 0 ? $value : 1;
    }

    private function humanizeSqlError(string $rawError): string
    {
        if (!str_contains($rawError, 'SQLSTATE')) {
            return 'No pude ejecutar ese paso. Revisa permisos o datos.';
        }
        if (str_contains($rawError, '[1045]')) {
            return 'No pude conectar a la base de datos. Revisa usuario y clave de DB.';
        }
        if (str_contains($rawError, 'Out of range value for column') && str_contains($rawError, 'tenant_id')) {
            return 'Configuracion de tenant invalida. El identificador de empresa supero el limite permitido.';
        }
        if (str_contains($rawError, 'Base table or view not found')) {
            return 'La tabla aun no existe en DB. Crea o migra esa tabla desde el Creador.';
        }
        return 'No pude ejecutar por un error SQL. Revisa estructura de tablas y credenciales.';
    }

    private function command(): CommandLayer
    {
        if (!$this->command) {
            $this->command = new CommandLayer();
        }
        return $this->command;
    }

    private function migrator(): EntityMigrator
    {
        if (!$this->migrator) {
            $this->migrator = new EntityMigrator($this->entities);
        }
        return $this->migrator;
    }

    private function gateway(): ConversationGateway
    {
        if ($this->gateway === null) {
            $this->gateway = new ConversationGateway(
                null,
                $this->memory
            );
        }
        return $this->gateway;
    }
    private function llmRouter(): LLMRouter
    {
        if (!$this->llmRouter) {
            $this->llmRouter = new LLMRouter();
        }
        return $this->llmRouter;
    }

    private function conversationMemory(): ConversationMemory
    {
        $registry = new ProjectRegistry();
        return new ConversationMemory($registry->db());
    }


    private function telemetry(): Telemetry
    {
        if (!$this->telemetry) {
            $this->telemetry = new Telemetry();
        }
        return $this->telemetry;
    }

    private function telemetryService(): TelemetryService
    {
        if (!$this->telemetryService) {
            $this->telemetryService = new TelemetryService(new SqlMetricsRepository());
        }
        return $this->telemetryService;
    }

    private function agentOpsObservabilityService(): AgentOpsObservabilityService
    {
        if (!$this->agentOpsObservabilityService) {
            $this->agentOpsObservabilityService = new AgentOpsObservabilityService(new SqlMetricsRepository());
        }

        return $this->agentOpsObservabilityService;
    }

    private function agentOpsSupervisor(): AgentOpsSupervisor
    {
        if (!$this->agentOpsSupervisor) {
            $this->agentOpsSupervisor = new AgentOpsSupervisor();
        }
        return $this->agentOpsSupervisor;
    }

    private function intentRouter(): IntentRouter
    {
        if (!$this->intentRouter) {
            $this->intentRouter = new IntentRouter();
        }
        return $this->intentRouter;
    }

    private function controlTowerAvailable(): bool
    {
        if ($this->controlTowerResolved) {
            return $this->controlTowerAvailable;
        }

        $this->controlTowerResolved = true;
        try {
            $this->controlTowerRepository = new ControlTowerRepository();
            $this->controlTowerFeedManager = new ControlTowerFeedManager($this->controlTowerRepository);
            $this->taskExecutionManager = new TaskExecutionManager($this->controlTowerRepository, $this->controlTowerFeedManager);
            $this->incidentManager = new IncidentManager($this->controlTowerRepository, $this->controlTowerFeedManager);
            $this->controlTowerAvailable = true;
        } catch (\Throwable $e) {
            $this->controlTowerAvailable = false;
            error_log('[ControlTower] unavailable: ' . $e->getMessage());
        }

        return $this->controlTowerAvailable;
    }

    private function taskExecutionManager(): ?TaskExecutionManager
    {
        return $this->controlTowerAvailable() ? $this->taskExecutionManager : null;
    }

    private function incidentManager(): ?IncidentManager
    {
        return $this->controlTowerAvailable() ? $this->incidentManager : null;
    }

    private function resolveMessageId(array $payload, string $sessionId, string $message): string
    {
        $messageId = trim((string) ($payload['message_id'] ?? ''));
        if ($messageId !== '') {
            return $messageId;
        }

        return $sessionId . ':' . substr(sha1($message), 0, 12);
    }

    private function resolveConversationId(string $sessionId, array $payload): string
    {
        $conversationId = trim((string) ($payload['conversation_id'] ?? ''));
        if ($conversationId !== '') {
            return $conversationId;
        }

        return $sessionId !== '' ? $sessionId : 'conversation_default';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function createControlTowerTask(array $payload): ?array
    {
        $manager = $this->taskExecutionManager();
        if (!$manager) {
            return null;
        }

        try {
            return $manager->createTask($payload);
        } catch (\Throwable $e) {
            error_log('[ControlTower] create task failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $task
     */
    private function linkControlTowerTask(
        ConversationGateway $gateway,
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        ?array $task
    ): void {
        if (!is_array($task)) {
            return;
        }

        try {
            $gateway->linkTaskExecution($tenantId, $userId, $projectId, $mode, $task);
        } catch (\Throwable $e) {
            error_log('[ControlTower] link task failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $telemetry
     * @param array<string, mixed>|null $task
     * @return array<string, mixed>
     */
    private function attachControlTowerTelemetry(array $telemetry, ?array $task, string $conversationId): array
    {
        if (is_array($task)) {
            $telemetry['task_id'] = (string) ($task['task_id'] ?? '');
            $telemetry['conversation_id'] = (string) ($task['conversation_id'] ?? $conversationId);
        } else {
            $telemetry['task_id'] = '';
            $telemetry['conversation_id'] = $conversationId;
        }

        return $telemetry;
    }

    /**
     * @param array<string, mixed>|null $task
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>|null
     */
    private function controlTowerRecordRoute(?array $task, array $telemetry, string $intent): ?array
    {
        if (!is_array($task)) {
            return $task;
        }

        $manager = $this->taskExecutionManager();
        if (!$manager) {
            return $task;
        }

        try {
            return $manager->recordRouteTrace((string) $task['tenant_id'], (string) $task['task_id'], [
                'intent' => $intent,
                'route_path' => (string) ($telemetry['route_path'] ?? ''),
                'gate_decision' => (string) ($telemetry['gate_decision'] ?? 'unknown'),
                'route_reason' => (string) ($telemetry['route_reason'] ?? ''),
                'evidence_used' => is_array($telemetry['evidence_used'] ?? null) ? (array) $telemetry['evidence_used'] : [],
                'evidence_status' => is_array($telemetry['evidence_status'] ?? null) ? (array) $telemetry['evidence_status'] : [],
                'latency_ms' => is_numeric($telemetry['router_latency_ms'] ?? null) ? (int) $telemetry['router_latency_ms'] : 0,
            ]);
        } catch (\Throwable $e) {
            error_log('[ControlTower] record route failed: ' . $e->getMessage());
            return $task;
        }
    }

    /**
     * @param array<string, mixed>|null $task
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function controlTowerMarkRunning(?array $task, array $context = []): ?array
    {
        if (!is_array($task)) {
            return $task;
        }

        $manager = $this->taskExecutionManager();
        if (!$manager) {
            return $task;
        }

        try {
            return $manager->updateTask((string) $task['tenant_id'], (string) $task['task_id'], array_merge($context, [
                'status' => 'running',
            ]));
        } catch (\Throwable $e) {
            error_log('[ControlTower] mark running failed: ' . $e->getMessage());
            return $task;
        }
    }

    /**
     * @param array<string, mixed>|null $task
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    private function controlTowerCompleteTask(?array $task, array $result = []): ?array
    {
        if (!is_array($task)) {
            return $task;
        }

        $manager = $this->taskExecutionManager();
        if (!$manager) {
            return $task;
        }

        try {
            $task = $manager->attachExecutionResult((string) $task['tenant_id'], (string) $task['task_id'], $result);
            return $manager->updateTask((string) $task['tenant_id'], (string) $task['task_id'], [
                'status' => 'completed',
            ]);
        } catch (\Throwable $e) {
            error_log('[ControlTower] complete task failed: ' . $e->getMessage());
            return $task;
        }
    }

    /**
     * @param array<string, mixed>|null $task
     * @param array<string, mixed> $failure
     * @return array{task: array<string, mixed>|null, incident: array<string, mixed>|null}
     */
    private function controlTowerFailTask(?array $task, array $failure = []): array
    {
        if (!is_array($task)) {
            return ['task' => $task, 'incident' => null];
        }

        $manager = $this->taskExecutionManager();
        if (!$manager) {
            return ['task' => $task, 'incident' => null];
        }

        try {
            $task = $manager->attachExecutionResult((string) $task['tenant_id'], (string) $task['task_id'], [
                'failure' => $failure,
            ]);
            $task = $manager->updateTask((string) $task['tenant_id'], (string) $task['task_id'], [
                'status' => 'failed',
                'gate_decision' => (string) ($failure['gate_decision'] ?? ($task['gate_decision'] ?? 'unknown')),
            ]);
        } catch (\Throwable $e) {
            error_log('[ControlTower] fail task failed: ' . $e->getMessage());
        }

        $incident = null;
        $incidentManager = $this->incidentManager();
        if ($incidentManager) {
            try {
                $incident = $incidentManager->createFromTaskFailure($task, $failure);
            } catch (\Throwable $e) {
                error_log('[ControlTower] incident create failed: ' . $e->getMessage());
            }
        }

        return ['task' => $task, 'incident' => $incident];
    }

    /**
     * @param array<string, mixed> $reply
     * @param array<string, mixed>|null $task
     * @param array<string, mixed>|null $incident
     * @return array<string, mixed>
     */
    private function annotateReplyWithControlTower(array $reply, ?array $task, ?array $incident = null): array
    {
        $data = is_array($reply['data'] ?? null) ? (array) $reply['data'] : [];
        if (is_array($task)) {
            $data['task_id'] = (string) ($task['task_id'] ?? '');
            $data['conversation_id'] = (string) ($task['conversation_id'] ?? '');
            $data['task_status'] = (string) ($task['status'] ?? '');
        }
        if (is_array($incident)) {
            $data['incident_id'] = (string) ($incident['incident_id'] ?? '');
        }
        $reply['data'] = $data;
        return $reply;
    }

    /**
     * @param array<string, mixed>|null $task
     * @return array<string, mixed>
     */
    private function buildLocalUtilityTelemetry(
        string $commandName,
        string $tenantId,
        string $projectId,
        string $sessionId,
        string $userId,
        ?array $task,
        string $conversationId
    ): array {
        return $this->attachControlTowerTelemetry([
            'route_path' => 'cache>rules',
            'gate_decision' => 'allow',
            'route_reason' => 'control_tower_local_utility',
            'action_contract' => 'none',
            'rag_hit' => false,
            'source_ids' => [],
            'evidence_ids' => [],
            'evidence_used' => [],
            'llm_called' => false,
            'llm_used' => false,
            'semantic_enabled' => false,
            'rag_attempted' => false,
            'rag_used' => false,
            'rag_result_count' => 0,
            'evidence_gate_status' => 'skipped_by_rule',
            'fallback_reason' => 'none',
            'skill_detected' => false,
            'skill_selected' => 'none',
            'skill_executed' => false,
            'skill_failed' => false,
            'skill_execution_ms' => 0,
            'skill_result_status' => 'not_applicable',
            'skill_fallback_reason' => 'none',
            'tool_calls_count' => 0,
            'retry_count' => 0,
            'loop_guard_triggered' => false,
            'request_mode' => 'operation',
            'module_used' => 'control_tower',
            'task_action' => 'local_utility',
            'validation_result' => 'passed',
            'result_status' => 'success',
            'tenant_id' => $tenantId,
            'app_id' => $projectId,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'tool_usage' => [
                'tool_calls_count' => 0,
                'module_key' => 'control_tower',
                'action_key' => $commandName,
                'skill_selected' => 'none',
            ],
            'contract_versions' => $this->resolveExtendedContractVersions(),
        ], $task, $conversationId);
    }

    /**
     * @param array<string, mixed>|null $task
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function handleIngressValidationFailure(
        string $channel,
        string $sessionId,
        string $userId,
        string $tenantId,
        string $projectId,
        string $conversationId,
        string $messageId,
        string $text,
        string $mode,
        array $payload,
        bool $isAuthenticated,
        string $role,
        ?array $task,
        array $validation,
        float $requestStartedAt
    ): array {
        $telemetry = $this->buildLocalUtilityTelemetry(
            'ingress_validation',
            $tenantId,
            $projectId,
            $sessionId,
            $userId,
            $task,
            $conversationId
        );
        $telemetry['gate_decision'] = 'blocked';
        $telemetry['route_reason'] = 'conversation_gateway_ingress_validation_failed';
        $telemetry['fallback_reason'] = 'ingress_validation_failed';
        $telemetry['error_type'] = 'ingress_validation_failed';
        $failure = $this->controlTowerFailTask($task, [
            'error_type' => 'ingress_validation_failed',
            'description' => 'Mensaje bloqueado por validacion de ingreso.',
            'created_at' => date('c'),
            'validation' => $validation,
        ]);
        $task = $failure['task'];
        $reply = $this->reply(
            'Bloqueado por Conversation Gateway. Revisa tenant, autenticacion y forma del mensaje.',
            $channel,
            $sessionId,
            $userId,
            'error',
            [
                'ingress_validation' => $validation,
            ]
        );
        $reply = $this->annotateReplyWithControlTower($reply, $task, $failure['incident']);
        $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
            'llm_called' => false,
            'error_flag' => true,
            'error_type' => 'ingress_validation_failed',
            'tool_calls_count' => 0,
            'retry_count' => 0,
            'task_id' => (string) ($task['task_id'] ?? ''),
            'conversation_id' => $conversationId,
        ]);
        $this->telemetry()->record($tenantId, array_merge($telemetry, [
            'message' => $text,
            'resolved_locally' => true,
            'action' => 'blocked',
            'mode' => $mode,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'country' => (string) ($payload['country'] ?? $payload['country_code'] ?? ''),
            'is_authenticated' => $isAuthenticated,
            'effective_role' => $role,
            'status' => 'blocked',
        ], $this->buildAgentOpsTelemetryBase(
            $telemetry,
            $tenantId,
            $projectId,
            $sessionId,
            $messageId,
            $this->latencyMs($requestStartedAt),
            'response.blocked',
            [
                'llm_called' => false,
                'error_flag' => true,
                'error_type' => 'ingress_validation_failed',
                'response_kind' => 'blocked',
                'response_text' => (string) ($reply['data']['reply'] ?? ''),
                'task_id' => (string) ($task['task_id'] ?? ''),
                'conversation_id' => $conversationId,
            ]
        )));
        return $reply;
    }

    /**
     * @param array<string, mixed> $telemetry
     * @param array<string, mixed> $commandPayload
     * @param array<string, mixed>|null $task
     * @return array<string, mixed>
     */
    private function evaluateControlTowerQualityGates(
        array $telemetry,
        array $commandPayload,
        string $tenantId,
        string $authTenantId,
        ?array $task
    ): array {
        $manager = $this->taskExecutionManager();
        if (!$manager) {
            return [
                'ok' => true,
                'errors' => [],
                'warnings' => [],
                'checked' => [],
            ];
        }

        $gate = $manager->evaluateQualityGates([
            'tenant_id' => $tenantId,
            'auth_tenant_id' => $authTenantId,
            'action' => 'execute_command',
            'command' => $commandPayload,
            'route_telemetry' => $telemetry,
            'action_contract' => (string) ($telemetry['action_contract'] ?? ''),
        ]);
        return $gate;
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function resolveExtendedContractVersions(array $base = []): array
    {
        $versions = $base;
        $versions['gbo'] = $versions['gbo'] ?? $this->resolveVersionFromJsonArtifact(
            FRAMEWORK_ROOT . '/ontology/gbo_universal_concepts.json',
            ['ontology_version', 'schema_version']
        );
        $versions['beg'] = $versions['beg'] ?? $this->resolveVersionFromJsonArtifact(
            FRAMEWORK_ROOT . '/events/beg_event_types.json',
            ['beg_version', 'schema_version']
        );
        $versions['audit'] = $versions['audit'] ?? $this->resolveVersionFromJsonArtifact(
            FRAMEWORK_ROOT . '/audit/audit_rules.json',
            ['audit_version', 'schema_version']
        );

        return $versions;
    }

    /**
     * @param array<int, string> $keys
     */
    private function resolveVersionFromJsonArtifact(string $path, array $keys): string
    {
        if (!is_file($path)) {
            return 'unknown';
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return 'unknown';
        }

        foreach ($keys as $key) {
            $value = trim((string) ($decoded[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    private function buildAgentOpsTelemetryBase(
        array $routeTelemetry,
        string $tenantId,
        string $projectId,
        string $sessionId,
        string $messageId,
        int $latencyMs,
        string $eventName,
        array $runtimeContext = []
    ): array {
        $contractVersions = $this->resolveExtendedContractVersions(
            is_array($routeTelemetry['contract_versions'] ?? null)
                ? (array) $routeTelemetry['contract_versions']
                : []
        );
        $versions = is_array($routeTelemetry['versions'] ?? null) ? (array) $routeTelemetry['versions'] : [];
        $enforcementMode = trim((string) ($routeTelemetry['enforcement_mode'] ?? ''));
        $enforcementModeSource = trim((string) ($routeTelemetry['enforcement_mode_source'] ?? ''));
        $enforcementAppEnv = trim((string) ($routeTelemetry['enforcement_app_env'] ?? ''));
        if (empty($versions)) {
            $versions = [
                'prompt_version' => (string) (getenv('PROMPT_VERSION') ?: 'unknown'),
                'router_policy_version' => (string) ($contractVersions['router_policy'] ?? 'unknown'),
                'action_catalog_version' => (string) ($contractVersions['action_catalog'] ?? 'unknown'),
                'skills_catalog_version' => (string) ($contractVersions['skills_catalog'] ?? 'unknown'),
                'akp_version' => (string) (getenv('AKP_VERSION') ?: 'unknown'),
                'policy_pack_version' => (string) (getenv('POLICY_PACK_VERSION') ?: 'unknown'),
            ];
        }

        $runtimeEnvelope = $this->buildAgentOpsRuntimeEnvelope($routeTelemetry, $latencyMs, $runtimeContext);
        $runtimeObservability = $runtimeEnvelope['runtime'];
        $supervisor = $runtimeEnvelope['supervisor'];
        $toolUsage = is_array($runtimeObservability['tool_usage'] ?? null) ? (array) $runtimeObservability['tool_usage'] : [];
        if (trim((string) ($runtimeObservability['session_id'] ?? '')) === '') {
            $runtimeObservability['session_id'] = $sessionId;
        }
        if (trim((string) ($runtimeObservability['user_id'] ?? '')) === '') {
            $runtimeObservability['user_id'] = trim((string) ($routeTelemetry['user_id'] ?? '')) ?: 'anon';
        }
        if (($runtimeObservability['app_id'] ?? null) === null || trim((string) ($runtimeObservability['app_id'] ?? '')) === '') {
            $runtimeObservability['app_id'] = $projectId;
        }
        if (trim((string) ($runtimeObservability['tenant_id'] ?? '')) === '') {
            $runtimeObservability['tenant_id'] = $tenantId;
        }

        return [
            'event_name' => $eventName,
            'event_time' => date('c'),
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'route_path' => $runtimeObservability['route_path'],
            'gate_decision' => $runtimeObservability['gate_decision'],
            'task_id' => $runtimeObservability['task_id'],
            'conversation_id' => $runtimeObservability['conversation_id'],
            'action_contract' => $runtimeObservability['action_contract'],
            'rag_hit' => $runtimeObservability['rag_hit'],
            'source_ids' => $runtimeObservability['source_ids'],
            'evidence_ids' => $runtimeObservability['evidence_ids'],
            'evidence_used' => $runtimeObservability['evidence_used'],
            'llm_called' => $runtimeObservability['llm_called'],
            'llm_used' => $runtimeObservability['llm_used'],
            'route_reason' => $runtimeObservability['route_reason'],
            'semantic_enabled' => $runtimeObservability['semantic_enabled'],
            'rag_attempted' => $runtimeObservability['rag_attempted'],
            'rag_used' => $runtimeObservability['rag_used'],
            'rag_result_count' => $runtimeObservability['rag_result_count'],
            'evidence_gate_status' => $runtimeObservability['evidence_gate_status'],
            'fallback_reason' => $runtimeObservability['fallback_reason'],
            'skill_detected' => $runtimeObservability['skill_detected'],
            'skill_selected' => $runtimeObservability['skill_selected'],
            'skill_executed' => $runtimeObservability['skill_executed'],
            'skill_failed' => $runtimeObservability['skill_failed'],
            'skill_execution_ms' => $runtimeObservability['skill_execution_ms'],
            'skill_result_status' => $runtimeObservability['skill_result_status'],
            'skill_fallback_reason' => $runtimeObservability['skill_fallback_reason'],
            'tool_calls_count' => $runtimeObservability['tool_calls_count'],
            'retry_count' => $runtimeObservability['retry_count'],
            'loop_guard_triggered' => $runtimeObservability['loop_guard_triggered'],
            'request_mode' => $runtimeObservability['request_mode'],
            'app_id' => $runtimeObservability['app_id'],
            'user_id' => $runtimeObservability['user_id'],
            'memory_type' => $runtimeObservability['memory_type'],
            'tool_usage' => $toolUsage,
            'module_used' => $runtimeObservability['module_used'],
            'alert_action' => $runtimeObservability['alert_action'],
            'task_action' => $runtimeObservability['task_action'],
            'reminder_action' => $runtimeObservability['reminder_action'],
            'media_action' => $runtimeObservability['media_action'],
            'entity_search_action' => $runtimeObservability['entity_search_action'],
            'pos_action' => $runtimeObservability['pos_action'],
            'purchases_action' => $runtimeObservability['purchases_action'],
            'fiscal_action' => $runtimeObservability['fiscal_action'],
            'ecommerce_action' => $runtimeObservability['ecommerce_action'],
            'access_control_action' => $runtimeObservability['access_control_action'],
            'saas_plan_action' => $runtimeObservability['saas_plan_action'],
            'usage_metering_action' => $runtimeObservability['usage_metering_action'],
            'agent_tools_action' => $runtimeObservability['agent_tools_action'],
            'agentops_action' => $runtimeObservability['agentops_action'],
            'skill_group' => $runtimeObservability['skill_group'],
            'draft_id' => $runtimeObservability['draft_id'],
            'purchase_draft_id' => $runtimeObservability['purchase_draft_id'],
            'product_id' => $runtimeObservability['product_id'],
            'matched_product_id' => $runtimeObservability['matched_product_id'],
            'matched_by' => $runtimeObservability['matched_by'],
            'product_query' => $runtimeObservability['product_query'],
            'ambiguity_count' => $runtimeObservability['ambiguity_count'],
            'ambiguity_detected' => $runtimeObservability['ambiguity_detected'],
            'purchase_id' => $runtimeObservability['purchase_id'],
            'purchase_number' => $runtimeObservability['purchase_number'],
            'purchase_document_id' => $runtimeObservability['purchase_document_id'],
            'fiscal_document_id' => $runtimeObservability['fiscal_document_id'],
            'media_file_id' => $runtimeObservability['media_file_id'],
            'supplier_id' => $runtimeObservability['supplier_id'],
            'document_type' => $runtimeObservability['document_type'],
            'source_module' => $runtimeObservability['source_module'],
            'source_entity_type' => $runtimeObservability['source_entity_type'],
            'source_entity_id' => $runtimeObservability['source_entity_id'],
            'fiscal_status' => $runtimeObservability['fiscal_status'],
            'store_id' => $runtimeObservability['store_id'],
            'platform' => $runtimeObservability['platform'],
            'adapter_key' => $runtimeObservability['adapter_key'],
            'connection_status' => $runtimeObservability['connection_status'],
            'validation_result' => $runtimeObservability['validation_result'],
            'sync_job_id' => $runtimeObservability['sync_job_id'],
            'sync_type' => $runtimeObservability['sync_type'],
            'link_id' => $runtimeObservability['link_id'],
            'external_order_id' => $runtimeObservability['external_order_id'],
            'local_reference_type' => $runtimeObservability['local_reference_type'],
            'local_reference_id' => $runtimeObservability['local_reference_id'],
            'local_product_id' => $runtimeObservability['local_product_id'],
            'external_product_id' => $runtimeObservability['external_product_id'],
            'sync_status' => $runtimeObservability['sync_status'],
            'sync_direction' => $runtimeObservability['sync_direction'],
            'target_user_id' => $runtimeObservability['target_user_id'],
            'actor_user_id' => $runtimeObservability['actor_user_id'],
            'role_key' => $runtimeObservability['role_key'],
            'permission_checked' => $runtimeObservability['permission_checked'],
            'decision' => $runtimeObservability['decision'],
            'plan_key' => $runtimeObservability['plan_key'],
            'limit_key' => $runtimeObservability['limit_key'],
            'metric_key' => $runtimeObservability['metric_key'],
            'delta_value' => $runtimeObservability['delta_value'],
            'usage_value' => $runtimeObservability['usage_value'],
            'limit_value' => $runtimeObservability['limit_value'],
            'over_limit' => $runtimeObservability['over_limit'],
            'requested_module' => $runtimeObservability['requested_module'],
            'resolved_module' => $runtimeObservability['resolved_module'],
            'enabled' => $runtimeObservability['enabled'],
            'allowed' => $runtimeObservability['allowed'],
            'denial_reason' => $runtimeObservability['denial_reason'],
            'duplicate_blocked' => $runtimeObservability['duplicate_blocked'],
            'line_count' => $runtimeObservability['line_count'],
            'total' => $runtimeObservability['total'],
            'result_status' => $runtimeObservability['result_status'],
            'pending_items_count' => $runtimeObservability['pending_items_count'],
            'token_usage' => $runtimeObservability['token_usage'],
            'cost_estimate' => $runtimeObservability['cost_estimate'],
            'metrics_delta' => $runtimeObservability['metrics_delta'],
            'error_flag' => $runtimeObservability['error_flag'],
            'error_type' => $runtimeObservability['error_type'],
            'supervisor_status' => $supervisor['status'],
            'supervisor_score' => $supervisor['score'],
            'supervisor_flags' => $supervisor['flags'],
            'supervisor_reasons' => $supervisor['reasons'],
            'needs_regression_case' => $supervisor['needs_regression_case'],
            'needs_memory_hygiene' => $supervisor['needs_memory_hygiene'],
            'needs_training_gap_review' => $supervisor['needs_training_gap_review'],
            'contract_versions' => $contractVersions,
            'versions' => $versions,
            'latency_ms' => $latencyMs,
            'enforcement_mode' => $enforcementMode !== '' ? $enforcementMode : 'unknown',
            'enforcement_mode_source' => $enforcementModeSource !== '' ? $enforcementModeSource : 'unknown',
            'enforcement_app_env' => $enforcementAppEnv !== '' ? $enforcementAppEnv : 'unknown',
            'agentops_runtime' => $runtimeObservability,
        ];
    }

    private function buildAgentOpsRuntimeObservability(array $routeTelemetry, int $latencyMs, array $runtimeContext = []): array
    {
        $routePath = (string) ($routeTelemetry['route_path'] ?? '');
        $gateDecision = (string) ($routeTelemetry['gate_decision'] ?? 'unknown');
        $actionContract = trim((string) ($routeTelemetry['action_contract'] ?? ''));
        $ragHit = (bool) ($routeTelemetry['rag_hit'] ?? false);
        $sourceIds = $this->normalizeStringList($routeTelemetry['source_ids'] ?? []);
        $evidenceIds = $this->normalizeStringList($routeTelemetry['evidence_ids'] ?? []);
        $evidenceUsed = is_array($routeTelemetry['evidence_used'] ?? null)
            ? (array) $routeTelemetry['evidence_used']
            : (is_array($runtimeContext['evidence_used'] ?? null) ? (array) $runtimeContext['evidence_used'] : []);
        $llmCalled = array_key_exists('llm_called', $runtimeContext)
            ? (bool) $runtimeContext['llm_called']
            : (bool) ($routeTelemetry['llm_called'] ?? false);
        $errorType = trim((string) ($runtimeContext['error_type'] ?? $routeTelemetry['error_type'] ?? ''));
        $errorFlag = array_key_exists('error_flag', $runtimeContext)
            ? (bool) $runtimeContext['error_flag']
            : ($errorType !== '');
        if ($errorType === '') {
            $errorType = $errorFlag ? 'runtime_error' : 'none';
        }

        $tokenUsage = is_array($runtimeContext['usage'] ?? null)
            ? (array) $runtimeContext['usage']
            : (is_array($routeTelemetry['token_usage'] ?? null) ? (array) $routeTelemetry['token_usage'] : null);
        $costEstimate = $runtimeContext['cost_estimate'] ?? ($routeTelemetry['cost_estimate'] ?? null);
        if (!is_numeric($costEstimate)) {
            $costEstimate = null;
        }

        $stageLatency = [
            'router_ms' => max(0, (int) ($routeTelemetry['router_latency_ms'] ?? 0)),
            'skill_ms' => max(0, (int) ($routeTelemetry['skill_execution_ms'] ?? 0)),
            'rag_ms' => max(0, (int) (($routeTelemetry['retrieval']['retrieval_latency_ms'] ?? $routeTelemetry['retrieval_latency_ms'] ?? 0))),
        ];
        $taskId = trim((string) ($runtimeContext['task_id'] ?? $routeTelemetry['task_id'] ?? ''));
        $conversationId = trim((string) ($runtimeContext['conversation_id'] ?? $routeTelemetry['conversation_id'] ?? ''));
        $toolUsage = [
            'tool_calls_count' => max(0, (int) ($runtimeContext['tool_calls_count'] ?? $routeTelemetry['tool_calls_count'] ?? 0)),
            'module_key' => trim((string) ($runtimeContext['module_used'] ?? $routeTelemetry['module_used'] ?? '')) ?: 'none',
            'action_key' => trim((string) ($runtimeContext['task_action'] ?? $routeTelemetry['task_action'] ?? '')) ?: 'none',
            'skill_selected' => trim((string) ($routeTelemetry['skill_selected'] ?? '')) ?: 'none',
        ];

        return [
            'route_path' => $routePath !== '' ? $routePath : 'unknown',
            'gate_decision' => $gateDecision !== '' ? $gateDecision : 'unknown',
            'task_id' => $taskId,
            'conversation_id' => $conversationId,
            'action_contract' => $actionContract !== '' ? $actionContract : 'none',
            'route_reason' => trim((string) ($routeTelemetry['route_reason'] ?? '')) ?: 'unknown',
            'rag_hit' => $ragHit,
            'source_ids' => $sourceIds,
            'evidence_ids' => $evidenceIds,
            'evidence_used' => $evidenceUsed,
            'semantic_enabled' => (bool) ($routeTelemetry['semantic_enabled'] ?? false),
            'semantic_memory_status' => trim((string) ($routeTelemetry['semantic_memory_status'] ?? '')) ?: 'unknown',
            'rag_attempted' => (bool) ($routeTelemetry['rag_attempted'] ?? false),
            'rag_used' => (bool) ($routeTelemetry['rag_used'] ?? false),
            'rag_result_count' => max(0, (int) ($routeTelemetry['rag_result_count'] ?? 0)),
            'evidence_gate_status' => trim((string) ($routeTelemetry['evidence_gate_status'] ?? '')) ?: 'unknown',
            'fallback_reason' => trim((string) ($routeTelemetry['fallback_reason'] ?? '')) ?: 'none',
            'skill_detected' => (bool) ($routeTelemetry['skill_detected'] ?? false),
            'skill_selected' => trim((string) ($routeTelemetry['skill_selected'] ?? '')) ?: 'none',
            'skill_executed' => (bool) ($routeTelemetry['skill_executed'] ?? false),
            'skill_failed' => (bool) ($routeTelemetry['skill_failed'] ?? false),
            'skill_execution_ms' => max(0, (int) ($routeTelemetry['skill_execution_ms'] ?? 0)),
            'skill_result_status' => trim((string) ($routeTelemetry['skill_result_status'] ?? '')) ?: 'unknown',
            'skill_fallback_reason' => trim((string) ($routeTelemetry['skill_fallback_reason'] ?? '')) ?: 'none',
            'llm_called' => $llmCalled,
            'llm_used' => $llmCalled,
            'tool_calls_count' => max(0, (int) ($runtimeContext['tool_calls_count'] ?? $routeTelemetry['tool_calls_count'] ?? 0)),
            'retry_count' => max(0, (int) ($runtimeContext['retry_count'] ?? $routeTelemetry['retry_count'] ?? 0)),
            'llm_fallback_count' => max(0, (int) ($routeTelemetry['llm_fallback_count'] ?? 0)),
            'loop_guard_triggered' => (bool) ($routeTelemetry['loop_guard_triggered'] ?? false),
            'loop_guard_reason' => trim((string) ($routeTelemetry['loop_guard_reason'] ?? '')) ?: 'none',
            'loop_guard_stage' => trim((string) ($routeTelemetry['loop_guard_stage'] ?? '')) ?: 'none',
            'same_route_repeat_count' => max(0, (int) ($routeTelemetry['same_route_repeat_count'] ?? 0)),
            'request_mode' => trim((string) ($routeTelemetry['request_mode'] ?? 'operation')) ?: 'operation',
            'memory_type' => trim((string) ($routeTelemetry['memory_type'] ?? '')) ?: 'none',
            'module_used' => trim((string) ($runtimeContext['module_used'] ?? $routeTelemetry['module_used'] ?? '')) ?: 'none',
            'alert_action' => trim((string) ($runtimeContext['alert_action'] ?? $routeTelemetry['alert_action'] ?? '')) ?: 'none',
            'task_action' => trim((string) ($runtimeContext['task_action'] ?? $routeTelemetry['task_action'] ?? '')) ?: 'none',
            'reminder_action' => trim((string) ($runtimeContext['reminder_action'] ?? $routeTelemetry['reminder_action'] ?? '')) ?: 'none',
            'media_action' => trim((string) ($runtimeContext['media_action'] ?? $routeTelemetry['media_action'] ?? '')) ?: 'none',
            'entity_search_action' => trim((string) ($runtimeContext['entity_search_action'] ?? $routeTelemetry['entity_search_action'] ?? '')) ?: 'none',
            'pos_action' => trim((string) ($runtimeContext['pos_action'] ?? $routeTelemetry['pos_action'] ?? '')) ?: 'none',
            'purchases_action' => trim((string) ($runtimeContext['purchases_action'] ?? $routeTelemetry['purchases_action'] ?? '')) ?: 'none',
            'fiscal_action' => trim((string) ($runtimeContext['fiscal_action'] ?? $routeTelemetry['fiscal_action'] ?? '')) ?: 'none',
            'ecommerce_action' => trim((string) ($runtimeContext['ecommerce_action'] ?? $routeTelemetry['ecommerce_action'] ?? '')) ?: 'none',
            'access_control_action' => trim((string) ($runtimeContext['access_control_action'] ?? $routeTelemetry['access_control_action'] ?? '')) ?: 'none',
            'saas_plan_action' => trim((string) ($runtimeContext['saas_plan_action'] ?? $routeTelemetry['saas_plan_action'] ?? '')) ?: 'none',
            'usage_metering_action' => trim((string) ($runtimeContext['usage_metering_action'] ?? $routeTelemetry['usage_metering_action'] ?? '')) ?: 'none',
            'agent_tools_action' => trim((string) ($runtimeContext['agent_tools_action'] ?? $routeTelemetry['agent_tools_action'] ?? '')) ?: 'none',
            'agentops_action' => trim((string) ($runtimeContext['agentops_action'] ?? $routeTelemetry['agentops_action'] ?? '')) ?: 'none',
            'skill_group' => $this->preferRuntimeOrRouteString($runtimeContext, $routeTelemetry, 'skill_group', 'unknown'),
            'draft_id' => trim((string) ($runtimeContext['draft_id'] ?? $routeTelemetry['draft_id'] ?? '')),
            'purchase_draft_id' => trim((string) ($runtimeContext['purchase_draft_id'] ?? $routeTelemetry['purchase_draft_id'] ?? '')),
            'session_id' => trim((string) ($runtimeContext['session_id'] ?? $routeTelemetry['session_id'] ?? '')),
            'product_id' => trim((string) ($runtimeContext['product_id'] ?? $routeTelemetry['product_id'] ?? '')),
            'matched_product_id' => trim((string) ($runtimeContext['matched_product_id'] ?? $routeTelemetry['matched_product_id'] ?? '')),
            'matched_by' => trim((string) ($runtimeContext['matched_by'] ?? $routeTelemetry['matched_by'] ?? '')),
            'product_query' => trim((string) ($runtimeContext['product_query'] ?? $routeTelemetry['product_query'] ?? '')),
            'ambiguity_count' => is_numeric($runtimeContext['ambiguity_count'] ?? $routeTelemetry['ambiguity_count'] ?? null)
                ? max(0, (int) ($runtimeContext['ambiguity_count'] ?? $routeTelemetry['ambiguity_count']))
                : 0,
            'ambiguity_detected' => $this->preferRuntimeOrRouteBool($runtimeContext, $routeTelemetry, 'ambiguity_detected'),
            'purchase_id' => trim((string) ($runtimeContext['purchase_id'] ?? $routeTelemetry['purchase_id'] ?? '')),
            'purchase_number' => trim((string) ($runtimeContext['purchase_number'] ?? $routeTelemetry['purchase_number'] ?? '')),
            'purchase_document_id' => trim((string) ($runtimeContext['purchase_document_id'] ?? $routeTelemetry['purchase_document_id'] ?? '')),
            'fiscal_document_id' => trim((string) ($runtimeContext['fiscal_document_id'] ?? $routeTelemetry['fiscal_document_id'] ?? '')),
            'media_file_id' => trim((string) ($runtimeContext['media_file_id'] ?? $routeTelemetry['media_file_id'] ?? '')),
            'supplier_id' => trim((string) ($runtimeContext['supplier_id'] ?? $routeTelemetry['supplier_id'] ?? '')),
            'document_type' => trim((string) ($runtimeContext['document_type'] ?? $routeTelemetry['document_type'] ?? '')),
            'source_module' => trim((string) ($runtimeContext['source_module'] ?? $routeTelemetry['source_module'] ?? '')),
            'source_entity_type' => trim((string) ($runtimeContext['source_entity_type'] ?? $routeTelemetry['source_entity_type'] ?? '')),
            'source_entity_id' => trim((string) ($runtimeContext['source_entity_id'] ?? $routeTelemetry['source_entity_id'] ?? '')),
            'fiscal_status' => trim((string) ($runtimeContext['fiscal_status'] ?? $routeTelemetry['fiscal_status'] ?? '')),
            'store_id' => trim((string) ($runtimeContext['store_id'] ?? $routeTelemetry['store_id'] ?? '')),
            'platform' => trim((string) ($runtimeContext['platform'] ?? $routeTelemetry['platform'] ?? '')),
            'adapter_key' => trim((string) ($runtimeContext['adapter_key'] ?? $routeTelemetry['adapter_key'] ?? '')) ?: 'none',
            'connection_status' => trim((string) ($runtimeContext['connection_status'] ?? $routeTelemetry['connection_status'] ?? '')),
            'validation_result' => trim((string) ($runtimeContext['validation_result'] ?? $routeTelemetry['validation_result'] ?? '')) ?: 'none',
            'sync_job_id' => trim((string) ($runtimeContext['sync_job_id'] ?? $routeTelemetry['sync_job_id'] ?? '')),
            'sync_type' => trim((string) ($runtimeContext['sync_type'] ?? $routeTelemetry['sync_type'] ?? '')),
            'link_id' => trim((string) ($runtimeContext['link_id'] ?? $routeTelemetry['link_id'] ?? '')),
            'external_order_id' => trim((string) ($runtimeContext['external_order_id'] ?? $routeTelemetry['external_order_id'] ?? '')),
            'local_reference_type' => trim((string) ($runtimeContext['local_reference_type'] ?? $routeTelemetry['local_reference_type'] ?? '')),
            'local_reference_id' => trim((string) ($runtimeContext['local_reference_id'] ?? $routeTelemetry['local_reference_id'] ?? '')),
            'local_product_id' => trim((string) ($runtimeContext['local_product_id'] ?? $routeTelemetry['local_product_id'] ?? '')),
            'external_product_id' => trim((string) ($runtimeContext['external_product_id'] ?? $routeTelemetry['external_product_id'] ?? '')),
            'sync_status' => trim((string) ($runtimeContext['sync_status'] ?? $routeTelemetry['sync_status'] ?? '')),
            'sync_direction' => trim((string) ($runtimeContext['sync_direction'] ?? $routeTelemetry['sync_direction'] ?? '')),
            'target_user_id' => trim((string) ($runtimeContext['target_user_id'] ?? $routeTelemetry['target_user_id'] ?? '')),
            'actor_user_id' => trim((string) ($runtimeContext['actor_user_id'] ?? $routeTelemetry['actor_user_id'] ?? '')),
            'role_key' => trim((string) ($runtimeContext['role_key'] ?? $routeTelemetry['role_key'] ?? '')),
            'permission_checked' => trim((string) ($runtimeContext['permission_checked'] ?? $routeTelemetry['permission_checked'] ?? '')),
            'decision' => trim((string) ($runtimeContext['decision'] ?? $routeTelemetry['decision'] ?? '')),
            'plan_key' => trim((string) ($runtimeContext['plan_key'] ?? $routeTelemetry['plan_key'] ?? '')),
            'limit_key' => trim((string) ($runtimeContext['limit_key'] ?? $routeTelemetry['limit_key'] ?? '')),
            'metric_key' => trim((string) ($runtimeContext['metric_key'] ?? $routeTelemetry['metric_key'] ?? '')),
            'delta_value' => is_numeric($runtimeContext['delta_value'] ?? $routeTelemetry['delta_value'] ?? null)
                ? (float) ($runtimeContext['delta_value'] ?? $routeTelemetry['delta_value'])
                : null,
            'usage_value' => is_numeric($runtimeContext['usage_value'] ?? $routeTelemetry['usage_value'] ?? null)
                ? (float) ($runtimeContext['usage_value'] ?? $routeTelemetry['usage_value'])
                : null,
            'limit_value' => is_numeric($runtimeContext['limit_value'] ?? $routeTelemetry['limit_value'] ?? null)
                ? (float) ($runtimeContext['limit_value'] ?? $routeTelemetry['limit_value'])
                : null,
            'over_limit' => (($runtimeContext['over_limit'] ?? $routeTelemetry['over_limit'] ?? false) === true),
            'requested_module' => trim((string) ($runtimeContext['requested_module'] ?? $routeTelemetry['requested_module'] ?? '')),
            'resolved_module' => trim((string) ($runtimeContext['resolved_module'] ?? $routeTelemetry['resolved_module'] ?? '')),
            'enabled' => array_key_exists('enabled', $runtimeContext) || array_key_exists('enabled', $routeTelemetry)
                ? (($runtimeContext['enabled'] ?? $routeTelemetry['enabled'] ?? false) === true)
                : null,
            'allowed' => array_key_exists('allowed', $runtimeContext) || array_key_exists('allowed', $routeTelemetry)
                ? (($runtimeContext['allowed'] ?? $routeTelemetry['allowed'] ?? false) === true)
                : null,
            'denial_reason' => trim((string) ($runtimeContext['denial_reason'] ?? $routeTelemetry['denial_reason'] ?? '')),
            'duplicate_blocked' => (($runtimeContext['duplicate_blocked'] ?? $routeTelemetry['duplicate_blocked'] ?? false) === true),
            'line_count' => is_numeric($runtimeContext['line_count'] ?? $routeTelemetry['line_count'] ?? null)
                ? max(0, (int) ($runtimeContext['line_count'] ?? $routeTelemetry['line_count']))
                : null,
            'total' => is_numeric($runtimeContext['total'] ?? $routeTelemetry['total'] ?? null)
                ? (float) ($runtimeContext['total'] ?? $routeTelemetry['total'])
                : null,
            'result_status' => trim((string) ($runtimeContext['result_status'] ?? $routeTelemetry['result_status'] ?? '')) ?: 'unknown',
            'pending_items_count' => is_numeric($runtimeContext['pending_items_count'] ?? $routeTelemetry['pending_items_count'] ?? null)
                ? max(0, (int) ($runtimeContext['pending_items_count'] ?? $routeTelemetry['pending_items_count']))
                : null,
            'tenant_id' => trim((string) ($routeTelemetry['tenant_id'] ?? '')) ?: 'default',
            'app_id' => ($routeTelemetry['app_id'] ?? null),
            'user_id' => trim((string) ($routeTelemetry['user_id'] ?? '')) ?: 'anon',
            'query_hash' => trim((string) ($routeTelemetry['query_hash'] ?? '')),
            'runtime_budget' => is_array($routeTelemetry['runtime_budget'] ?? null) ? (array) $routeTelemetry['runtime_budget'] : [],
            'stage_latency_ms' => $stageLatency,
            'latency_ms' => $latencyMs,
            'token_usage' => $tokenUsage,
            'cost_estimate' => $costEstimate,
            'tool_usage' => $toolUsage,
            'contract_versions' => $this->resolveExtendedContractVersions(
                is_array($routeTelemetry['contract_versions'] ?? null)
                    ? (array) $routeTelemetry['contract_versions']
                    : []
            ),
            'metrics_delta' => is_array($routeTelemetry['metrics_delta'] ?? null) ? (array) $routeTelemetry['metrics_delta'] : [],
            'tenant_scope_violation_detected' => (bool) ($routeTelemetry['tenant_scope_violation_detected'] ?? false),
            'route_path_coherent' => (bool) ($routeTelemetry['route_path_coherent'] ?? true),
            'rag_error' => trim((string) ($routeTelemetry['rag_error'] ?? '')),
            'error_flag' => $errorFlag,
            'error_type' => $errorType,
        ];
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     * @return array{runtime: array<string, mixed>, supervisor: array<string, mixed>}
     */
    private function buildAgentOpsRuntimeEnvelope(array $routeTelemetry, int $latencyMs, array $runtimeContext = []): array
    {
        $runtimeObservability = $this->buildAgentOpsRuntimeObservability($routeTelemetry, $latencyMs, $runtimeContext);

        try {
            $supervisor = $this->agentOpsSupervisor()->evaluate($runtimeObservability, $routeTelemetry, $runtimeContext);
        } catch (\Throwable $ignored) {
            $supervisor = [
                'status' => 'needs_review',
                'score' => 0,
                'flags' => [],
                'reasons' => ['AgentOps Supervisor no pudo evaluar este turno.'],
                'route_path' => $runtimeObservability['route_path'],
                'skill_selected' => $runtimeObservability['skill_selected'],
                'rag_used' => $runtimeObservability['rag_used'],
                'evidence_gate_status' => $runtimeObservability['evidence_gate_status'],
                'fallback_reason' => $runtimeObservability['fallback_reason'],
                'needs_regression_case' => true,
                'needs_memory_hygiene' => false,
                'needs_training_gap_review' => false,
            ];
        }

        $runtimeObservability['supervisor'] = $supervisor;

        return [
            'runtime' => $runtimeObservability,
            'supervisor' => $supervisor,
        ];
    }

    private function rememberAgentOpsTrace(
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        array $routeTelemetry,
        int $latencyMs,
        array $runtimeContext = []
    ): void {
        $runtimeObservability = $this->buildAgentOpsRuntimeObservability($routeTelemetry, $latencyMs, $runtimeContext);

        try {
            $this->gateway()->rememberAgentOpsTrace(
                $tenantId,
                $userId,
                $projectId,
                $mode,
                $runtimeObservability
            );
        } catch (\Throwable $ignored) {
            // agentops trace persistence must not block chat response
        }

        try {
            $this->agentOpsObservabilityService()->recordDecisionTrace([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'session_id' => trim((string) ($runtimeObservability['session_id'] ?? '')),
                'route_path' => (string) ($runtimeObservability['route_path'] ?? 'unknown'),
                'selected_module' => $this->resolveDecisionSelectedModule($runtimeObservability),
                'selected_action' => $this->resolveDecisionSelectedAction($runtimeObservability),
                'evidence_source' => $this->resolveDecisionEvidenceSource($runtimeObservability),
                'ambiguity_detected' => (($runtimeObservability['ambiguity_detected'] ?? false) === true),
                'fallback_llm' => (($runtimeObservability['llm_called'] ?? false) === true),
                'latency_ms' => $latencyMs,
                'result_status' => $this->resolveDecisionResultStatus($runtimeObservability),
                'metadata_json' => [
                    'gate_decision' => (string) ($runtimeObservability['gate_decision'] ?? 'unknown'),
                    'module_used' => (string) ($runtimeObservability['module_used'] ?? 'none'),
                    'denial_reason' => (string) ($runtimeObservability['denial_reason'] ?? ''),
                    'permission_checked' => (string) ($runtimeObservability['permission_checked'] ?? ''),
                    'decision' => (string) ($runtimeObservability['decision'] ?? ''),
                    'error_type' => (string) ($runtimeObservability['error_type'] ?? 'none'),
                    'tool_calls_count' => (int) ($runtimeObservability['tool_calls_count'] ?? 0),
                    'task_id' => (string) ($runtimeObservability['task_id'] ?? ''),
                    'conversation_id' => (string) ($runtimeObservability['conversation_id'] ?? ''),
                    'contract_versions' => is_array($runtimeObservability['contract_versions'] ?? null)
                        ? (array) $runtimeObservability['contract_versions']
                        : [],
                    'tool_usage' => is_array($runtimeObservability['tool_usage'] ?? null)
                        ? (array) $runtimeObservability['tool_usage']
                        : [],
                    'cost_estimate' => $runtimeObservability['cost_estimate'] ?? null,
                ],
            ]);
        } catch (\Throwable $ignored) {
            // decision trace persistence must not block chat response
        }
    }

    /**
     * @param array<string, mixed> $runtimeObservability
     */
    private function resolveDecisionSelectedModule(array $runtimeObservability): string
    {
        foreach (['resolved_module', 'module_used', 'requested_module'] as $field) {
            $value = trim((string) ($runtimeObservability[$field] ?? ''));
            if ($value !== '' && $value !== 'none') {
                return $value;
            }
        }

        return 'none';
    }

    /**
     * @param array<string, mixed> $runtimeObservability
     */
    private function resolveDecisionSelectedAction(array $runtimeObservability): string
    {
        foreach ([
            'agentops_action',
            'agent_tools_action',
            'usage_metering_action',
            'saas_plan_action',
            'access_control_action',
            'ecommerce_action',
            'fiscal_action',
            'purchases_action',
            'pos_action',
            'entity_search_action',
            'media_action',
            'alert_action',
            'task_action',
            'reminder_action',
        ] as $field) {
            $value = trim((string) ($runtimeObservability[$field] ?? ''));
            if ($value !== '' && $value !== 'none') {
                return $value;
            }
        }

        $contractAction = trim((string) ($runtimeObservability['action_contract'] ?? ''));
        if ($contractAction !== '' && $contractAction !== 'none') {
            return $contractAction;
        }

        return trim((string) ($runtimeObservability['skill_selected'] ?? '')) ?: 'none';
    }

    /**
     * @param array<string, mixed> $runtimeObservability
     */
    private function resolveDecisionEvidenceSource(array $runtimeObservability): string
    {
        if (($runtimeObservability['rag_used'] ?? false) === true) {
            return 'rag';
        }
        if (($runtimeObservability['skill_detected'] ?? false) === true) {
            return 'skills';
        }
        if (($runtimeObservability['llm_called'] ?? false) === true) {
            return 'llm';
        }
        if (str_contains((string) ($runtimeObservability['route_path'] ?? ''), 'cache')) {
            return 'cache';
        }

        return 'rules';
    }

    /**
     * @param array<string, mixed> $runtimeObservability
     */
    private function resolveDecisionResultStatus(array $runtimeObservability): string
    {
        if (($runtimeObservability['error_flag'] ?? false) === true) {
            return 'error';
        }

        return trim((string) ($runtimeObservability['result_status'] ?? '')) ?: 'unknown';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    private function recordToolExecutionTrace(
        string $tenantId,
        string $projectId,
        string $sessionId,
        array $routeTelemetry,
        array $runtimeContext = []
    ): void {
        try {
            $success = (($runtimeContext['success'] ?? false) === true);
            $this->agentOpsObservabilityService()->recordToolExecutionTrace([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'module_key' => $this->resolveTraceModuleKey($routeTelemetry, $runtimeContext),
                'action_key' => $this->resolveTraceActionKey($routeTelemetry, $runtimeContext),
                'input_schema_valid' => $this->resolveSchemaGatePassed($routeTelemetry),
                'permission_check' => $this->resolvePermissionTraceStatus($routeTelemetry, $runtimeContext),
                'plan_check' => $this->resolvePlanTraceStatus($routeTelemetry, $runtimeContext),
                'execution_latency' => max(0, (int) ($runtimeContext['execution_latency'] ?? 0)),
                'success' => $success,
                'error_code' => $this->resolveToolTraceErrorCode($routeTelemetry, $runtimeContext),
                'metadata_json' => [
                    'session_id' => $sessionId,
                    'route_path' => (string) ($routeTelemetry['route_path'] ?? ''),
                    'gate_decision' => (string) ($routeTelemetry['gate_decision'] ?? ''),
                    'result_status' => (string) ($runtimeContext['result_status'] ?? $routeTelemetry['result_status'] ?? ''),
                    'permission_checked' => (string) ($routeTelemetry['permission_checked'] ?? ''),
                    'denial_reason' => (string) ($routeTelemetry['denial_reason'] ?? ''),
                    'requested_module' => (string) ($routeTelemetry['requested_module'] ?? ''),
                    'resolved_module' => (string) ($routeTelemetry['resolved_module'] ?? ''),
                    'command_name' => (string) ($runtimeContext['command_name'] ?? ''),
                    'task_id' => (string) ($runtimeContext['task_id'] ?? $routeTelemetry['task_id'] ?? ''),
                    'conversation_id' => (string) ($runtimeContext['conversation_id'] ?? $routeTelemetry['conversation_id'] ?? ''),
                ],
            ]);
        } catch (\Throwable $ignored) {
            // tool execution trace persistence must not block chat response
        }
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     */
    private function shouldTraceBlockedToolExecution(array $routeTelemetry): bool
    {
        $actionContract = trim((string) ($routeTelemetry['action_contract'] ?? ''));
        $gateDecision = trim((string) ($routeTelemetry['gate_decision'] ?? ''));

        return $actionContract !== '' && $actionContract !== 'none' && $gateDecision === 'blocked';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    private function resolveTraceModuleKey(array $routeTelemetry, array $runtimeContext = []): string
    {
        foreach (['resolved_module', 'requested_module', 'module_used'] as $field) {
            $value = trim((string) ($runtimeContext[$field] ?? $routeTelemetry[$field] ?? ''));
            if ($value !== '' && $value !== 'none') {
                return $value;
            }
        }

        $actionContract = trim((string) ($routeTelemetry['action_contract'] ?? ''));
        if ($actionContract !== '' && $actionContract !== 'none' && str_contains($actionContract, '.')) {
            return trim((string) strstr($actionContract, '.', true)) ?: 'none';
        }

        return 'none';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    private function resolveTraceActionKey(array $routeTelemetry, array $runtimeContext = []): string
    {
        foreach ([
            'agentops_action',
            'agent_tools_action',
            'usage_metering_action',
            'saas_plan_action',
            'access_control_action',
            'ecommerce_action',
            'fiscal_action',
            'purchases_action',
            'pos_action',
            'entity_search_action',
            'media_action',
            'alert_action',
            'task_action',
            'reminder_action',
        ] as $field) {
            $value = trim((string) ($runtimeContext[$field] ?? $routeTelemetry[$field] ?? ''));
            if ($value !== '' && $value !== 'none') {
                return $value;
            }
        }

        $actionContract = trim((string) ($routeTelemetry['action_contract'] ?? ''));
        if ($actionContract !== '' && $actionContract !== 'none' && str_contains($actionContract, '.')) {
            $parts = explode('.', $actionContract, 2);
            return trim((string) ($parts[1] ?? '')) ?: 'none';
        }

        return trim((string) ($runtimeContext['command_name'] ?? '')) ?: 'none';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     */
    private function resolveSchemaGatePassed(array $routeTelemetry): bool
    {
        $gateResult = $this->gateResultPassed($routeTelemetry, 'schema_gate');
        if ($gateResult !== null) {
            return $gateResult;
        }

        foreach ((array) ($routeTelemetry['contract_violations'] ?? []) as $violation) {
            if (str_starts_with((string) $violation, 'gate_schema_invalid:')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    private function resolvePermissionTraceStatus(array $routeTelemetry, array $runtimeContext = []): string
    {
        $explicit = trim((string) ($runtimeContext['permission_check'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $decision = strtolower(trim((string) ($routeTelemetry['decision'] ?? '')));
        if ($decision === 'allow') {
            return 'allow';
        }
        if ($decision === 'deny') {
            return 'deny';
        }

        $authGate = $this->gateResultPassed($routeTelemetry, 'auth_rbac_gate');
        if ($authGate === false) {
            return 'deny';
        }
        if ($authGate === true && trim((string) ($routeTelemetry['permission_checked'] ?? '')) !== '') {
            return 'allow';
        }

        foreach ((array) ($routeTelemetry['contract_violations'] ?? []) as $violation) {
            if (str_starts_with((string) $violation, 'gate_auth_rbac_failed:')) {
                return 'deny';
            }
        }

        return 'not_checked';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    private function resolvePlanTraceStatus(array $routeTelemetry, array $runtimeContext = []): string
    {
        $explicit = trim((string) ($runtimeContext['plan_check'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        if (($routeTelemetry['over_limit'] ?? false) === true) {
            return 'warn:over_limit';
        }

        $denialReason = trim((string) ($routeTelemetry['denial_reason'] ?? ''));
        if ($denialReason === 'module_disabled_by_plan' || $denialReason === 'plan_not_assigned') {
            return 'disabled';
        }

        if (array_key_exists('enabled', $routeTelemetry)) {
            return (($routeTelemetry['enabled'] ?? false) === true) ? 'enabled' : 'disabled';
        }

        if (trim((string) ($routeTelemetry['limit_key'] ?? '')) !== '') {
            return 'checked';
        }

        return 'not_checked';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     */
    private function resolveToolTraceErrorCode(array $routeTelemetry, array $runtimeContext = []): ?string
    {
        $explicit = trim((string) ($runtimeContext['error_code'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        if (($runtimeContext['success'] ?? false) === true) {
            return null;
        }

        $denialReason = trim((string) ($routeTelemetry['denial_reason'] ?? ''));
        if ($denialReason !== '') {
            return $denialReason;
        }

        $errorType = trim((string) ($runtimeContext['error_type'] ?? $routeTelemetry['error_type'] ?? ''));
        if ($errorType !== '' && $errorType !== 'none') {
            return $errorType;
        }

        foreach ((array) ($routeTelemetry['contract_violations'] ?? []) as $violation) {
            $violation = (string) $violation;
            if (str_starts_with($violation, 'gate_auth_rbac_failed:')) {
                return 'permission_denied';
            }
            if (str_starts_with($violation, 'gate_tenant_scope_failed:')) {
                return 'tenant_scope_denied';
            }
            if (str_starts_with($violation, 'gate_schema_invalid:')) {
                return 'schema_invalid';
            }
            if (str_starts_with($violation, 'minimum_evidence_missing:')) {
                return 'minimum_evidence_missing';
            }
        }

        return 'execution_failed';
    }

    /**
     * @param array<string, mixed> $routeTelemetry
     */
    private function gateResultPassed(array $routeTelemetry, string $gateName): ?bool
    {
        $gateResults = is_array($routeTelemetry['gate_results'] ?? null) ? (array) $routeTelemetry['gate_results'] : [];
        foreach ($gateResults as $gateResult) {
            if (!is_array($gateResult)) {
                continue;
            }
            if ((string) ($gateResult['gate'] ?? '') !== $gateName) {
                continue;
            }
            return (($gateResult['passed'] ?? false) === true);
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $candidate = trim((string) $item);
            if ($candidate === '') {
                continue;
            }
            $normalized[] = $candidate;
        }

        if (empty($normalized)) {
            return [];
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $reply
     */
    private function extractReplyTextFromEnvelope(array $reply): string
    {
        $candidate = trim((string) ($reply['reply'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $data = is_array($reply['data'] ?? null) ? (array) $reply['data'] : [];
        $candidate = trim((string) ($data['reply'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        return trim((string) ($reply['message'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function extractOperationalTelemetryMarkers(array $payload): array
    {
        $pendingItemsCount = $payload['pending_items_count'] ?? null;
        if (!is_numeric($pendingItemsCount)) {
            $pendingItemsCount = null;
        }

        return [
            'task_id' => trim((string) ($payload['task_id'] ?? '')) ?: '',
            'conversation_id' => trim((string) ($payload['conversation_id'] ?? '')) ?: '',
            'module_used' => trim((string) ($payload['module_used'] ?? '')) ?: 'none',
            'alert_action' => trim((string) ($payload['alert_action'] ?? '')) ?: 'none',
            'task_action' => trim((string) ($payload['task_action'] ?? '')) ?: 'none',
            'reminder_action' => trim((string) ($payload['reminder_action'] ?? '')) ?: 'none',
            'media_action' => trim((string) ($payload['media_action'] ?? '')) ?: 'none',
            'entity_search_action' => trim((string) ($payload['entity_search_action'] ?? '')) ?: 'none',
            'pos_action' => trim((string) ($payload['pos_action'] ?? '')) ?: 'none',
            'purchases_action' => trim((string) ($payload['purchases_action'] ?? '')) ?: 'none',
            'fiscal_action' => trim((string) ($payload['fiscal_action'] ?? '')) ?: 'none',
            'ecommerce_action' => trim((string) ($payload['ecommerce_action'] ?? '')) ?: 'none',
            'access_control_action' => trim((string) ($payload['access_control_action'] ?? '')) ?: 'none',
            'saas_plan_action' => trim((string) ($payload['saas_plan_action'] ?? '')) ?: 'none',
            'usage_metering_action' => trim((string) ($payload['usage_metering_action'] ?? '')) ?: 'none',
            'agent_tools_action' => trim((string) ($payload['agent_tools_action'] ?? '')) ?: 'none',
            'agentops_action' => trim((string) ($payload['agentops_action'] ?? '')) ?: 'none',
            'skill_group' => trim((string) ($payload['skill_group'] ?? '')) ?: '',
            'draft_id' => trim((string) ($payload['draft_id'] ?? '')) ?: '',
            'purchase_draft_id' => trim((string) ($payload['purchase_draft_id'] ?? '')) ?: '',
            'session_id' => trim((string) ($payload['session_id'] ?? '')) ?: '',
            'product_id' => trim((string) ($payload['product_id'] ?? '')) ?: '',
            'matched_product_id' => trim((string) ($payload['matched_product_id'] ?? '')) ?: '',
            'matched_by' => trim((string) ($payload['matched_by'] ?? '')) ?: '',
            'product_query' => trim((string) ($payload['product_query'] ?? '')) ?: '',
            'ambiguity_count' => is_numeric($payload['ambiguity_count'] ?? null)
                ? max(0, (int) $payload['ambiguity_count'])
                : 0,
            'ambiguity_detected' => (($payload['ambiguity_detected'] ?? false) === true),
            'purchase_id' => trim((string) ($payload['purchase_id'] ?? '')) ?: '',
            'purchase_number' => trim((string) ($payload['purchase_number'] ?? '')) ?: '',
            'purchase_document_id' => trim((string) ($payload['purchase_document_id'] ?? '')) ?: '',
            'fiscal_document_id' => trim((string) ($payload['fiscal_document_id'] ?? '')) ?: '',
            'media_file_id' => trim((string) ($payload['media_file_id'] ?? '')) ?: '',
            'supplier_id' => trim((string) ($payload['supplier_id'] ?? '')) ?: '',
            'document_type' => trim((string) ($payload['document_type'] ?? '')) ?: '',
            'source_module' => trim((string) ($payload['source_module'] ?? '')) ?: '',
            'source_entity_type' => trim((string) ($payload['source_entity_type'] ?? '')) ?: '',
            'source_entity_id' => trim((string) ($payload['source_entity_id'] ?? '')) ?: '',
            'fiscal_status' => trim((string) ($payload['fiscal_status'] ?? '')) ?: '',
            'store_id' => trim((string) ($payload['store_id'] ?? '')) ?: '',
            'platform' => trim((string) ($payload['platform'] ?? '')) ?: '',
            'adapter_key' => trim((string) ($payload['adapter_key'] ?? '')) ?: '',
            'connection_status' => trim((string) ($payload['connection_status'] ?? '')) ?: '',
            'validation_result' => trim((string) ($payload['validation_result'] ?? '')) ?: '',
            'sync_job_id' => trim((string) ($payload['sync_job_id'] ?? '')) ?: '',
            'sync_type' => trim((string) ($payload['sync_type'] ?? '')) ?: '',
            'link_id' => trim((string) ($payload['link_id'] ?? '')) ?: '',
            'external_order_id' => trim((string) ($payload['external_order_id'] ?? '')) ?: '',
            'local_reference_type' => trim((string) ($payload['local_reference_type'] ?? '')) ?: '',
            'local_reference_id' => trim((string) ($payload['local_reference_id'] ?? '')) ?: '',
            'local_product_id' => trim((string) ($payload['local_product_id'] ?? '')) ?: '',
            'external_product_id' => trim((string) ($payload['external_product_id'] ?? '')) ?: '',
            'sync_status' => trim((string) ($payload['sync_status'] ?? '')) ?: '',
            'sync_direction' => trim((string) ($payload['sync_direction'] ?? '')) ?: '',
            'target_user_id' => trim((string) ($payload['target_user_id'] ?? '')) ?: '',
            'actor_user_id' => trim((string) ($payload['actor_user_id'] ?? '')) ?: '',
            'role_key' => trim((string) ($payload['role_key'] ?? '')) ?: '',
            'permission_checked' => trim((string) ($payload['permission_checked'] ?? '')) ?: '',
            'decision' => trim((string) ($payload['decision'] ?? '')) ?: '',
            'plan_key' => trim((string) ($payload['plan_key'] ?? '')) ?: '',
            'limit_key' => trim((string) ($payload['limit_key'] ?? '')) ?: '',
            'metric_key' => trim((string) ($payload['metric_key'] ?? '')) ?: '',
            'delta_value' => is_numeric($payload['delta_value'] ?? null)
                ? (float) $payload['delta_value']
                : null,
            'usage_value' => is_numeric($payload['usage_value'] ?? null)
                ? (float) $payload['usage_value']
                : null,
            'limit_value' => is_numeric($payload['limit_value'] ?? null)
                ? (float) $payload['limit_value']
                : null,
            'over_limit' => (($payload['over_limit'] ?? false) === true),
            'requested_module' => trim((string) ($payload['requested_module'] ?? '')) ?: '',
            'resolved_module' => trim((string) ($payload['resolved_module'] ?? '')) ?: '',
            'enabled' => array_key_exists('enabled', $payload)
                ? (($payload['enabled'] ?? false) === true)
                : null,
            'allowed' => array_key_exists('allowed', $payload)
                ? (($payload['allowed'] ?? false) === true)
                : null,
            'denial_reason' => trim((string) ($payload['denial_reason'] ?? '')) ?: '',
            'duplicate_blocked' => (($payload['duplicate_blocked'] ?? false) === true),
            'line_count' => is_numeric($payload['line_count'] ?? null)
                ? max(0, (int) $payload['line_count'])
                : null,
            'total' => is_numeric($payload['total'] ?? null)
                ? (float) $payload['total']
                : null,
            'result_status' => trim((string) ($payload['result_status'] ?? '')) ?: '',
            'result_count' => is_numeric($payload['result_count'] ?? null)
                ? max(0, (int) $payload['result_count'])
                : null,
            'resolved' => array_key_exists('resolved', $payload)
                ? (bool) $payload['resolved']
                : null,
            'needs_clarification' => array_key_exists('needs_clarification', $payload)
                ? (bool) $payload['needs_clarification']
                : null,
            'pending_items_count' => $pendingItemsCount !== null ? max(0, (int) $pendingItemsCount) : null,
        ];
    }

    private function resolveAttachmentCount(array $payload): int
    {
        $count = 0;
        if (is_array($payload['attachments'] ?? null)) {
            $count += count((array) $payload['attachments']);
        }
        if (is_array($payload['meta'] ?? null) && !empty($payload['meta'])) {
            $count++;
        }

        return $count;
    }

    private function commandBus(): CommandBus
    {
        if (!$this->commandBus) {
            $this->commandBus = new CommandBus();
            $this->commandBus->register(new CreateEntityCommandHandler());
            $this->commandBus->register(new CreateFormCommandHandler());
            $this->commandBus->register(new CreateRelationCommandHandler());
            $this->commandBus->register(new CreateIndexCommandHandler());
            $this->commandBus->register(new InstallPlaybookCommandHandler());
            $this->commandBus->register(new ImportIntegrationOpenApiCommandHandler());
            $this->commandBus->register(new CompileWorkflowCommandHandler());
            $this->commandBus->register(new CrudCommandHandler());
            $this->commandBus->register(new AlertsCenterCommandHandler());
            $this->commandBus->register(new MediaCommandHandler());
            $this->commandBus->register(new EntitySearchCommandHandler());
            $this->commandBus->register(new POSCommandHandler());
            $this->commandBus->register(new PurchasesCommandHandler());
            $this->commandBus->register(new FiscalEngineCommandHandler());
            $this->commandBus->register(new EcommerceHubCommandHandler());
            $this->commandBus->register(new TenantAccessControlCommandHandler());
            $this->commandBus->register(new TenantPlanCommandHandler());
            $this->commandBus->register(new UsageMeteringCommandHandler());
            $this->commandBus->register(new AgentToolsIntegrationCommandHandler());
            $this->commandBus->register(new AgentOpsObservabilityCommandHandler());
            $this->commandBus->register(new LearningCenterCommandHandler());
            $this->commandBus->register(new MapCommandHandler(
                ['AuthLogin', 'AuthCreateUser'],
                function (array $command, array $context): array {
                    return $this->executeCommandPayload(
                        $command,
                        (string) ($context['channel'] ?? 'local'),
                        (string) ($context['session_id'] ?? 'sess'),
                        (string) ($context['user_id'] ?? 'anon'),
                        (string) ($context['mode'] ?? 'app')
                    );
                }
            ));
        }
        return $this->commandBus;
    }

    private function latencyMs(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }

    private function looksLikeGuardrailMessage(string $reply): bool
    {
        $reply = mb_strtolower(trim($reply), 'UTF-8');
        if ($reply === '') {
            return false;
        }

        $patterns = [
            'modo app',
            'modo creador',
            'chat creador',
            'chat de la app',
            'debe ser agregada por el creador',
            'permisos insuficientes',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($reply, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function dispatchCommandPayload(
        array $commandPayload,
        string $channel,
        string $sessionId,
        string $userId,
        string $mode
    ): array {
        try {
            $reply = function (
                string $text,
                string $ctxChannel,
                string $ctxSessionId,
                string $ctxUserId,
                string $status = 'success',
                array $data = []
            ): array {
                return $this->reply($text, $ctxChannel, $ctxSessionId, $ctxUserId, $status, $data);
            };
            return $this->commandBus()->dispatch($commandPayload, [
                'channel' => $channel,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'mode' => $mode,
                'reply' => $reply,
                'entity_exists' => fn(string $entity): bool => $this->entityExists($entity),
                'form_exists_for_entity' => fn(string $entity): bool => $this->formExistsForEntity($entity),
                'builder' => $this->builder,
                'writer' => $this->writer,
                'migrator' => $this->migrator(),
                'entities' => $this->entities,
                'wizard' => $this->wizard,
                'command_layer' => $this->command(),
                'playbook_installer' => new PlaybookInstaller(),
                'openapi_importer' => new OpenApiIntegrationImporter(),
                'workflow_repository' => new WorkflowRepository(),
                'workflow_executor' => $this->workflowExecutor(),
                'workflow_compiler' => new WorkflowCompiler(),
                'register_entity' => function (string $entityName, string $ctxUserId): void {
                    try {
                        $registry = new ProjectRegistry();
                        $manifest = $registry->resolveProjectFromManifest();
                        $projectId = (string) ($manifest['id'] ?? 'default');
                        $registry->ensureProject(
                            $projectId,
                            (string) ($manifest['name'] ?? 'Proyecto'),
                            (string) ($manifest['status'] ?? 'draft'),
                            (string) ($manifest['tenant_mode'] ?? 'shared'),
                            $ctxUserId,
                            (string) ($manifest['storage_model'] ?? '')
                        );
                        $registry->registerEntity($projectId, $entityName, 'chat');
                    } catch (\Throwable $e) {
                        // best effort registry sync
                    }
                },
                'register_form' => function (string $ctxUserId): void {
                    try {
                        $registry = new ProjectRegistry();
                        $manifest = $registry->resolveProjectFromManifest();
                        $registry->ensureProject(
                            (string) ($manifest['id'] ?? 'default'),
                            (string) ($manifest['name'] ?? 'Proyecto'),
                            (string) ($manifest['status'] ?? 'draft'),
                            (string) ($manifest['tenant_mode'] ?? 'shared'),
                            $ctxUserId,
                            (string) ($manifest['storage_model'] ?? '')
                        );
                    } catch (\Throwable $e) {
                        // best effort registry sync
                    }
                },
            ]);
        } catch (RuntimeException $e) {
            $code = (string) $e->getMessage();
            if (in_array($code, ['COMMAND_NOT_SUPPORTED', 'COMMAND_MISSING', 'INVALID_CONTEXT'], true)) {
                return $this->reply('Comando no soportado.', $channel, $sessionId, $userId, 'error');
            }
            throw $e;
        }
    }

    private function executeCommandPayload(array $command, string $channel, string $sessionId, string $userId, string $mode = 'app'): array
    {
        $cmd = (string) ($command['command'] ?? '');
        $data = (array) ($command['data'] ?? []);

        if ($cmd === '') {
            return $this->reply('Comando incompleto.', $channel, $sessionId, $userId, 'error');
        }

        switch ($cmd) {
            case 'AuthLogin':
                $loginId = (string) ($command['user_id'] ?? $data['user_id'] ?? '');
                $password = (string) ($command['password'] ?? $data['password'] ?? '');
                if ($loginId === '' || $password === '') {
                    return $this->reply('Necesito usuario y clave para iniciar sesiÃƒÆ’Ã‚Â³n.', $channel, $sessionId, $userId, 'error');
                }
                $registry = new ProjectRegistry();
                $manifest = $registry->resolveProjectFromManifest();
                $projectId = (string) ($command['project_id'] ?? $_SESSION['current_project_id'] ?? $manifest['id'] ?? 'default');
                $user = $registry->verifyAuthUser($projectId, $loginId, $password);
                if (!$user) {
                    return $this->reply('Usuario o clave incorrecta.', $channel, $sessionId, $userId, 'error');
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['auth_user'] = [
                        'id' => $user['id'],
                        'role' => $user['role'] ?? 'admin',
                        'tenant_id' => $user['tenant_id'] ?? 'default',
                        'project_id' => $projectId,
                        'label' => $user['label'] ?? $user['id'],
                    ];
                    $_SESSION['current_project_id'] = $projectId;
                }
                return $this->reply('Login listo. Ya puedes usar la app.', $channel, $sessionId, $userId, 'success', ['user' => $user]);
            case 'AuthCreateUser':
                $newId = (string) ($command['user_id'] ?? $data['user_id'] ?? '');
                $role = (string) ($command['role'] ?? $data['role'] ?? 'seller');
                $password = (string) ($command['password'] ?? $data['password'] ?? '');
                if ($newId === '' || $password === '') {
                    return $this->reply('Necesito usuario y clave para crear la cuenta.', $channel, $sessionId, $userId, 'error');
                }
                $registry = new ProjectRegistry();
                $manifest = $registry->resolveProjectFromManifest();
                $projectId = (string) ($command['project_id'] ?? $_SESSION['current_project_id'] ?? $manifest['id'] ?? 'default');
                $registry->createAuthUser($projectId, $newId, $password, $role, $command['tenant_id'] ?? 'default', $command['label'] ?? $newId);
                $registry->touchUser($newId, $role, 'auth', $command['tenant_id'] ?? 'default', $command['label'] ?? $newId);
                $registry->assignUserToProject($projectId, $newId, $role);
                return $this->reply('Usuario creado. Ãƒâ€šÃ‚Â¿Quieres iniciar sesiÃƒÆ’Ã‚Â³n ahora?', $channel, $sessionId, $userId, 'success');
        }

        return $this->reply('Comando no soportado.', $channel, $sessionId, $userId, 'error');
    }

    private function executeLlmJson(array $json, string $channel, string $sessionId, string $userId, string $mode = 'app'): array
    {
        if (isset($json['command'])) {
            return $this->dispatchCommandPayload((array) $json['command'], $channel, $sessionId, $userId, $mode);
        }
        if (isset($json['actions']) && is_array($json['actions'])) {
            foreach ($json['actions'] as $action) {
                if (!is_array($action)) continue;
                $type = strtolower((string) ($action['type'] ?? ''));
                if ($type === 'create_record') {
                    return $this->dispatchCommandPayload([
                        'command' => 'CreateRecord',
                        'entity' => $action['entity'] ?? '',
                        'data' => $action['data'] ?? [],
                    ], $channel, $sessionId, $userId, $mode);
                }
                if ($type === 'query_records') {
                    return $this->dispatchCommandPayload([
                        'command' => 'QueryRecords',
                        'entity' => $action['entity'] ?? '',
                        'filters' => $action['filters'] ?? [],
                    ], $channel, $sessionId, $userId, $mode);
                }
            }
        }
        if (isset($json['reply'])) {
            return $this->reply((string) $json['reply'], $channel, $sessionId, $userId);
        }
        return $this->reply('Listo.', $channel, $sessionId, $userId);
    }

    private function preferRuntimeOrRouteString(array $runtimeContext, array $routeTelemetry, string $key, string $default = ''): string
    {
        $runtimeValue = trim((string) ($runtimeContext[$key] ?? ''));
        if ($runtimeValue !== '') {
            return $runtimeValue;
        }

        $routeValue = trim((string) ($routeTelemetry[$key] ?? ''));
        if ($routeValue !== '') {
            return $routeValue;
        }

        return $default;
    }

    private function preferRuntimeOrRouteBool(array $runtimeContext, array $routeTelemetry, string $key): bool
    {
        if (array_key_exists($key, $runtimeContext)) {
            return $runtimeContext[$key] === true;
        }

        return ($routeTelemetry[$key] ?? false) === true;
    }

    private function entityExists(string $entity): bool
    {
        if ($entity === '') {
            return false;
        }
        try {
            $this->entities->get($entity);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function formExistsForEntity(string $entity): bool
    {
        $entity = strtolower(trim($entity));
        if ($entity === '') {
            return false;
        }
        $path = PROJECT_ROOT . '/contracts/forms/' . $entity . '.form.json';
        return is_file($path);
    }

    private function storeMemory(string $sessionId, string $userText, string $replyText): void
    {
        if ($sessionId === '') {
            return;
        }
        $memory = $this->memory->getSession($sessionId);
        $history = $memory['history'] ?? [];
        $history[] = ['u' => $userText, 'a' => $replyText, 'ts' => time()];
        if (count($history) > 6) {
            $history = array_slice($history, -6);
        }
        $summary = $memory['summary'] ?? '';
        if ($summary === '' && $userText !== '') {
            $summary = mb_substr($userText, 0, 120);
        }
        $this->memory->saveSession($sessionId, [
            'summary' => $summary,
            'history' => $history,
        ]);
    }

    private function persistToUserMemory(string $text, string $reply, array $params = []): void
    {
        if (trim($text) === '' || trim($reply) === '') {
            return;
        }

        try {
            if ($this->semanticMemory) {
                $this->semanticMemory->ingestUserInteraction(
                    (string) ($params['tenant_id'] ?? ''),
                    (string) ($params['user_id'] ?? ''),
                    $text,
                    ['reply' => $reply, 'session_id' => (string) ($params['session_id'] ?? '')]
                );
            }
        } catch (\Throwable $e) {
            // Memory ingestion should not block chat
        }
    }

    private function workflowExecutor(): \App\Core\WorkflowExecutor
    {
        $calculator = new \App\Core\Skills\CalculatorSkill();
        return new \App\Core\WorkflowExecutor([
            'calculator' => fn(array $in, array $ctx) => $calculator->handle($in, $ctx)
        ]);
    }
}






