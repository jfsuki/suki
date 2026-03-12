<?php
// app/Core/ChatAgent.php

namespace App\Core;

use App\Core\Agents\ConversationGateway;
use App\Core\Agents\AcidChatRunner;
use App\Core\Agents\Telemetry;
use App\Core\LLM\LLMRouter;
use App\Core\PlaybookInstaller;

use RuntimeException;

final class ChatAgent
{
    private ?CommandLayer $command = null;
    private EntityRegistry $entities;
    private ?EntityMigrator $migrator = null;
    private ?ConversationGateway $gateway = null;
    private ?LLMRouter $llmRouter = null;
    private ?Telemetry $telemetry = null;
    private ?TelemetryService $telemetryService = null;
    private ?AgentOpsSupervisor $agentOpsSupervisor = null;
    private ?IntentRouter $intentRouter = null;
    private ?CommandBus $commandBus = null;
    private FormWizard $wizard;
    private ContractWriter $writer;
    private EntityBuilder $builder;
    private ChatMemoryStore $memory;

    public function __construct()
    {
        $this->entities = new EntityRegistry();
        $this->wizard = new FormWizard();
        $this->writer = new ContractWriter();
        $this->builder = new EntityBuilder();
        $this->memory = new ChatMemoryStore();
    }

    public function handle(array $payload): array
    {
        $requestStartedAt = microtime(true);
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
        if ($channel === '') {
            $channel = 'local';
        }
        if ($sessionId === '') {
            $sessionId = 'sess_' . time();
        }
        if ($userId === '') {
            $userId = 'anon';
        }
        if ($tenantId === '') {
            $tenantId = (string) (getenv('TENANT_ID') ?: 'default');
        }
        if ($role === '') {
            $role = $isAuthenticated
                ? (string) (getenv('DEFAULT_ROLE') ?: 'admin')
                : 'guest';
        }
        if ($isAuthenticated) {
            if ($authUserId === '') {
                $authUserId = $userId;
            }
            if ($authTenantId === '') {
                $authTenantId = $tenantId;
            }
        }
        $role = $this->normalizeRole($role);
        $mode = strtolower((string) ($payload['mode'] ?? 'app'));
        $projectId = (string) ($payload['project_id'] ?? '');
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        if ($projectId === '') {
            $projectId = $manifest['id'] ?? 'default';
        }
        if ($isAuthenticated && $authProjectId === '') {
            $authProjectId = $projectId;
        }
        $sessionBinding = $registry->getSession($sessionId);
        if (is_array($sessionBinding)) {
            $boundUser = trim((string) ($sessionBinding['user_id'] ?? ''));
            $boundProject = trim((string) ($sessionBinding['project_id'] ?? ''));
            $boundTenant = trim((string) ($sessionBinding['tenant_id'] ?? ''));

            if ($boundUser !== '' && $boundUser !== $userId) {
                return $this->reply(
                    'Esta sesion pertenece a otro usuario. Crea una sesion nueva para continuar.',
                    $channel,
                    $sessionId,
                    $userId,
                    'error',
                    ['session_id' => $sessionId, 'bound_user' => $boundUser]
                );
            }
            if ($boundProject !== '' && $projectId !== '' && $boundProject !== $projectId) {
                return $this->reply(
                    'Esta sesion ya esta enlazada a otro proyecto. Crea una sesion nueva para cambiar proyecto.',
                    $channel,
                    $sessionId,
                    $userId,
                    'error',
                    ['session_id' => $sessionId, 'bound_project' => $boundProject]
                );
            }
            if ($boundTenant !== '' && $boundTenant !== $tenantId) {
                return $this->reply(
                    'Esta sesion pertenece a otro tenant. Crea una sesion nueva para evitar mezclar datos.',
                    $channel,
                    $sessionId,
                    $userId,
                    'error',
                    ['session_id' => $sessionId, 'bound_tenant' => $boundTenant]
                );
            }
            if ($boundProject !== '') {
                $projectId = $boundProject;
            }
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
        $registry->touchUser($userId, $role, $mode === 'builder' ? 'creator' : 'app', $tenantId);
        $registry->assignUserToProject($projectId, $userId, $role);
        $registry->touchSession($sessionId, $userId, $projectId, $tenantId, $channel);
        \App\Core\RoleContext::setRole($role);
        \App\Core\RoleContext::setUserId($userId);
        \App\Core\RoleContext::setUserLabel((string) ($payload['user_label'] ?? ''));

        if ($text === '' && empty($payload['meta'])) {
            return $this->reply('Mensaje vacio.', $channel, $sessionId, $userId, 'error');
        }

        if ($text === '' && !empty($payload['meta'])) {
            return $this->reply('Archivo recibido. Procesaremos OCR/Audio cuando este habilitado.', $channel, $sessionId, $userId);
        }

        $local = $this->parseLocal($text);
        if (!empty($local['command']) && in_array($local['command'], ['RunTests', 'LLMUsage'], true)) {
            try {
                return $this->executeLocal($local, $channel, $sessionId, $userId, $mode, $tenantId);
            } catch (\Throwable $e) {
                $rawError = (string) $e->getMessage();
                $human = str_contains($rawError, 'SQLSTATE')
                    ? 'No pude conectar la base de datos. El contrato se guarda, pero falta revisar credenciales DB.'
                    : 'No pude ejecutar ese paso. Revisa configuracion o permisos.';
                return $this->reply($human, $channel, $sessionId, $userId, 'error', [
                    'error' => $rawError,
                ]);
            }
        }

        $gateway = $this->gateway();
        $result = $gateway->handle($tenantId, $userId, $text, $mode, $projectId);
        $route = $this->intentRouter()->route($result, [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'message_text' => $text,
            'channel' => $channel,
            'has_attachment' => !empty($payload['meta']) || !empty($payload['attachments']),
            'attachments_count' => $this->resolveAttachmentCount($payload),
            'attachments' => is_array($payload['attachments'] ?? null) ? (array) $payload['attachments'] : [],
            'role' => $role,
            'mode' => $mode,
            'message_id' => (string) ($payload['message_id'] ?? ''),
            'is_authenticated' => $isAuthenticated,
            'auth_user_id' => $authUserId,
            'auth_tenant_id' => $authTenantId,
            'auth_project_id' => $authProjectId,
            'chat_exec_auth_required' => $chatExecAuthRequired,
        ]);
        $action = $route->kind();
        $telemetry = $route->telemetry();
        $state = $route->state();
        $messageId = $this->resolveMessageId($payload, $sessionId, $text);

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
            $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $blockedTelemetry, $this->latencyMs($requestStartedAt), [
                'llm_called' => false,
                'error_flag' => true,
                'error_type' => 'security_block',
                'tool_calls_count' => 0,
                'retry_count' => 0,
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
            return $securityBlock;
        }

        if ($route->isLocalResponse()) {
            $reply = $this->reply($route->reply(), $channel, $sessionId, $userId);
            $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                'llm_called' => false,
                'error_flag' => false,
                'error_type' => 'none',
                'tool_calls_count' => 0,
                'retry_count' => 0,
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
                    'error_flag' => false,
                    'error_type' => 'none',
                    'response_kind' => $action,
                    'response_text' => (string) $route->reply(),
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
            return $reply;
        }

        if ($route->isCommand()) {
            $commandStartedAt = microtime(true);
            $commandPayload = $route->command();
            $commandExecutionErrorType = '';
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
            $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                'llm_called' => false,
                'error_flag' => $commandErrorFlag,
                'error_type' => $commandErrorType,
                'tool_calls_count' => 1,
                'retry_count' => 0,
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
            return $reply;
        }

        if ($route->isLlmRequest()) {
            try {
                $llmResult = $this->llmRouter()->chat($route->llmRequest(), [
                    'mode' => $mode,
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                ]);
            } catch (\Throwable $e) {
                $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                    'llm_called' => true,
                    'error_flag' => true,
                    'error_type' => 'llm_unavailable',
                    'tool_calls_count' => 0,
                    'retry_count' => 0,
                ]);
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
                        'response_text' => 'IA no disponible. Usa comandos simples.',
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
                return $this->reply('IA no disponible. Usa comandos simples.', $channel, $sessionId, $userId);
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
            $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
                'llm_called' => true,
                'error_flag' => false,
                'error_type' => 'none',
                'tool_calls_count' => 0,
                'retry_count' => 0,
                'usage' => $usage,
                'cost_estimate' => $llmResult['cost_estimate'] ?? null,
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
            return $reply;
        }

        $this->rememberAgentOpsTrace($tenantId, $userId, $projectId, $mode, $telemetry, $this->latencyMs($requestStartedAt), [
            'llm_called' => false,
            'error_flag' => true,
            'error_type' => 'route_error',
            'tool_calls_count' => 0,
            'retry_count' => 0,
        ]);
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
                'response_text' => 'No entendi. Puedes decir: crear cliente nombre=Juan nit=123',
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
        return $this->reply('No entendi. Puedes decir: crear cliente nombre=Juan nit=123', $channel, $sessionId, $userId, 'error');
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
                $reply .= " Chat ácido: " . ($acidSummary['passed'] ?? 0) . " ok, " . ($acidSummary['failed'] ?? 0) . " fail.";
            } catch (\Throwable $e) {
                $reply .= " Chat ácido: error al ejecutar.";
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
                        $line .= " Chat ácido: " . ($acidSummary['passed'] ?? 0) . " ok, " . ($acidSummary['failed'] ?? 0) . " fail.";
                    } catch (\Throwable $e) {
                        $line .= " Chat ácido: error al ejecutar.";
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
        $label = preg_replace('/[^a-z0-9áéíóúñü\\s_-]/u', '', $label) ?? $label;
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
            'dueño' => 'admin',
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
        if (!$this->gateway) {
            $this->gateway = new ConversationGateway();
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

    private function resolveMessageId(array $payload, string $sessionId, string $message): string
    {
        $messageId = trim((string) ($payload['message_id'] ?? ''));
        if ($messageId !== '') {
            return $messageId;
        }

        return $sessionId . ':' . substr(sha1($message), 0, 12);
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
        $contractVersions = is_array($routeTelemetry['contract_versions'] ?? null)
            ? (array) $routeTelemetry['contract_versions']
            : [];
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
            'action_contract' => $runtimeObservability['action_contract'],
            'rag_hit' => $runtimeObservability['rag_hit'],
            'source_ids' => $runtimeObservability['source_ids'],
            'evidence_ids' => $runtimeObservability['evidence_ids'],
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
            'module_used' => $runtimeObservability['module_used'],
            'alert_action' => $runtimeObservability['alert_action'],
            'task_action' => $runtimeObservability['task_action'],
            'reminder_action' => $runtimeObservability['reminder_action'],
            'media_action' => $runtimeObservability['media_action'],
            'entity_search_action' => $runtimeObservability['entity_search_action'],
            'pos_action' => $runtimeObservability['pos_action'],
            'purchases_action' => $runtimeObservability['purchases_action'],
            'draft_id' => $runtimeObservability['draft_id'],
            'purchase_draft_id' => $runtimeObservability['purchase_draft_id'],
            'session_id' => $runtimeObservability['session_id'],
            'product_id' => $runtimeObservability['product_id'],
            'matched_product_id' => $runtimeObservability['matched_product_id'],
            'matched_by' => $runtimeObservability['matched_by'],
            'product_query' => $runtimeObservability['product_query'],
            'ambiguity_count' => $runtimeObservability['ambiguity_count'],
            'purchase_id' => $runtimeObservability['purchase_id'],
            'purchase_number' => $runtimeObservability['purchase_number'],
            'purchase_document_id' => $runtimeObservability['purchase_document_id'],
            'media_file_id' => $runtimeObservability['media_file_id'],
            'supplier_id' => $runtimeObservability['supplier_id'],
            'document_type' => $runtimeObservability['document_type'],
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

        return [
            'route_path' => $routePath !== '' ? $routePath : 'unknown',
            'gate_decision' => $gateDecision !== '' ? $gateDecision : 'unknown',
            'action_contract' => $actionContract !== '' ? $actionContract : 'none',
            'route_reason' => trim((string) ($routeTelemetry['route_reason'] ?? '')) ?: 'unknown',
            'rag_hit' => $ragHit,
            'source_ids' => $sourceIds,
            'evidence_ids' => $evidenceIds,
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
            'purchase_id' => trim((string) ($runtimeContext['purchase_id'] ?? $routeTelemetry['purchase_id'] ?? '')),
            'purchase_number' => trim((string) ($runtimeContext['purchase_number'] ?? $routeTelemetry['purchase_number'] ?? '')),
            'purchase_document_id' => trim((string) ($runtimeContext['purchase_document_id'] ?? $routeTelemetry['purchase_document_id'] ?? '')),
            'media_file_id' => trim((string) ($runtimeContext['media_file_id'] ?? $routeTelemetry['media_file_id'] ?? '')),
            'supplier_id' => trim((string) ($runtimeContext['supplier_id'] ?? $routeTelemetry['supplier_id'] ?? '')),
            'document_type' => trim((string) ($runtimeContext['document_type'] ?? $routeTelemetry['document_type'] ?? '')),
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
        try {
            $this->gateway()->rememberAgentOpsTrace(
                $tenantId,
                $userId,
                $projectId,
                $mode,
                $this->buildAgentOpsRuntimeObservability($routeTelemetry, $latencyMs, $runtimeContext)
            );
        } catch (\Throwable $ignored) {
            // agentops trace persistence must not block chat response
        }
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
            'module_used' => trim((string) ($payload['module_used'] ?? '')) ?: 'none',
            'alert_action' => trim((string) ($payload['alert_action'] ?? '')) ?: 'none',
            'task_action' => trim((string) ($payload['task_action'] ?? '')) ?: 'none',
            'reminder_action' => trim((string) ($payload['reminder_action'] ?? '')) ?: 'none',
            'media_action' => trim((string) ($payload['media_action'] ?? '')) ?: 'none',
            'entity_search_action' => trim((string) ($payload['entity_search_action'] ?? '')) ?: 'none',
            'pos_action' => trim((string) ($payload['pos_action'] ?? '')) ?: 'none',
            'purchases_action' => trim((string) ($payload['purchases_action'] ?? '')) ?: 'none',
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
            'purchase_id' => trim((string) ($payload['purchase_id'] ?? '')) ?: '',
            'purchase_number' => trim((string) ($payload['purchase_number'] ?? '')) ?: '',
            'purchase_document_id' => trim((string) ($payload['purchase_document_id'] ?? '')) ?: '',
            'media_file_id' => trim((string) ($payload['media_file_id'] ?? '')) ?: '',
            'supplier_id' => trim((string) ($payload['supplier_id'] ?? '')) ?: '',
            'document_type' => trim((string) ($payload['document_type'] ?? '')) ?: '',
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
                'workflow_executor' => new WorkflowExecutor(),
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
                    return $this->reply('Necesito usuario y clave para iniciar sesión.', $channel, $sessionId, $userId, 'error');
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
                return $this->reply('Usuario creado. ¿Quieres iniciar sesión ahora?', $channel, $sessionId, $userId, 'success');
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
}

