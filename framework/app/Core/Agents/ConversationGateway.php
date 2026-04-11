<?php
// framework/app/Core/Agents/ConversationGateway.php

namespace App\Core\Agents;

use App\Core\EntityRegistry;
use App\Core\ContractsCatalog;
use App\Core\MemoryRepositoryInterface;
use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;
use App\Core\BuilderOnboardingFlow;
use App\Core\SqlMemoryRepository;
use App\Core\ModeGuardPolicy;
// Corrected Agent Namespaces
use App\Core\Agents\IntentClassifier;
use App\Core\LLM\LLMRouter;
use App\Core\Agents\DialogStateEngine;
use App\Core\Agents\KnowledgeProvider;
use App\Core\Agents\ConversationGatewayBuilderOnboardingTrait;
use App\Core\Agents\ConversationGatewayHandlePipelineTrait;
use App\Core\Agents\ConversationGatewayRoutingPolicyTrait;
use App\Core\Agents\ConversationGatewayStubsTrait;

/**
 * Orquestador principal de la conversacion (v2).
 * Refactorizado para delegar logica de dominio a KnowledgeProvider y pipeline a traits.
 */
class ConversationGateway
{
    use ConversationGatewayHandlePipelineTrait;
    use ConversationGatewayBuilderOnboardingTrait;
    use ConversationGatewayRoutingPolicyTrait;
    use ConversationGatewayStubsTrait {
        ConversationGatewayBuilderOnboardingTrait::normalize insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isPureGreeting insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isFarewell insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isQuestionLike insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isClarificationRequest insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::hasBuildSignals insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::hasFieldPairs insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::normalizeBusinessType insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isBusinessTypeRejectedByUser insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isAffirmativeReply insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::buildNextStepProposal insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isNegativeReply insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isBuilderOnboardingTrigger insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isEntityListQuestion insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::buildEntityList insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::isBuilderProgressQuestion insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::buildProjectStatus insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::parseInstallPlaybookRequest insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::detectBusinessType insteadof ConversationGatewayStubsTrait;
        ConversationGatewayBuilderOnboardingTrait::memoryWindow insteadof ConversationGatewayStubsTrait;
    }

    protected $projectRoot;
    protected $entities;
    protected $catalog;
    protected $memory;
    protected $intentClassifier;
    protected $modeGuardPolicy;
    protected $builderOnboardingFlow;
    protected $dialogState;
    protected $knowledge;
    protected $workingMessages = [];
    protected $trainingBaseCache = [];
    protected $confusionBaseCache = null;
    protected $contextTenantId = 'default';
    
    // Properties used by traits
    protected $contextProjectId;
    protected $contextMode;
    protected $contextUserId;
    protected $contextSessionId;
    protected $contextProfileUser;
    protected $scopedEntityNamesCache = [];
    protected $scopedFormNamesCache = [];
    protected $conversationMemory;

    public function __construct($projectRoot = null, $memory = null, ?KnowledgeProvider $knowledgeProvider = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 4) . '/project');

        // Ensure Environment is loaded for LLM/Embeddings AND DB
        if (!getenv('GEMINI_API_KEY') || !getenv('DB_USER')) {
            $envPath = $this->projectRoot . '/.env';
            if (file_exists($envPath)) {
                $loaderPath = $this->projectRoot . '/config/env_loader.php';
                if (file_exists($loaderPath)) {
                    require_once $loaderPath;
                    if (function_exists('loadEnv')) {
                        loadEnv($envPath);
                    }
                }
            }
        }

        $this->entities = new EntityRegistry();
        $this->catalog = new ContractsCatalog($this->projectRoot);
        $this->memory = $memory ?? new SqlMemoryRepository();
        $this->modeGuardPolicy = new ModeGuardPolicy();
        $this->builderOnboardingFlow = new BuilderOnboardingFlow();
        $this->dialogState = new DialogStateEngine();
        $this->knowledge = $knowledgeProvider ?? new KnowledgeProvider($this->projectRoot, $this->memory);
        
        // Explicit initialization to prevent trait-induced NULLs
        $this->scopedEntityNamesCache = [];
        $this->scopedFormNamesCache = [];
    }

    public function setConversationMemory($memory): void
    {
        $this->conversationMemory = $memory;
    }

    private function intentClassifier(): IntentClassifier
    {
        if ($this->intentClassifier === null) {
            try {
                $embedder = new GeminiEmbeddingService();
                $store = (new QdrantVectorStore())->forMemoryType('agent_training');
                $router = new LLMRouter();
            } catch (\Throwable $e) {
                $embedder = null;
                $store = null;
                $router = null;
            }
            $this->intentClassifier = new IntentClassifier(
                $embedder,
                $store,
                $router,
                $this->contextTenantId !== '' ? $this->contextTenantId : 'system'
            );
        }
        return $this->intentClassifier;
    }

    public function validateIngressEnvelope(array $payload): array
    {
        return ['ok' => true];
    }

    public function rememberExecution(
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        array $command,
        array $resultData = [],
        string $userMessage = '',
        string $assistantReply = ''
    ): void {
        // Implementation ported from ChatOrchestrator for compatibility
        $snapshot = [
            'ts'          => date('c'),
            'tenant_id'   => $tenantId,
            'user_id'     => $userId,
            'project_id'  => $projectId,
            'mode'        => $mode,
            'command'     => $command['command']  ?? 'unknown',
            'entity'      => $command['entity']   ?? '',
            'user_msg'    => $userMessage,
            'reply'       => $assistantReply,
            'result_ok'   => !empty($resultData['ok'] ?? true),
        ];

        $logDir = defined('APP_ROOT') ? APP_ROOT . '/tests/tmp' : sys_get_temp_dir();
        $logFile = $logDir . '/session_' . preg_replace('/[^a-z0-9_]/i', '_', $tenantId . '_' . $userId) . '.jsonl';

        @file_put_contents(
            $logFile,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    public function postExecutionFollowup(
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        array  $command,
        array  $resultData = []
    ): string {
        $mode = strtolower(trim($mode)) === 'builder' ? 'builder' : 'app';
        if ($mode !== 'builder') {
            return '';
        }
        $commandName = (string) ($command['command'] ?? '');
        $entity      = (string) ($command['entity']  ?? '');

        if ($commandName === 'CreateEntity' && $entity !== '') {
            return 'Tabla ' . $entity . ' creada. ¿Deseas crear el formulario para capturar datos? (si/no)';
        }
        if ($commandName === 'CreateForm') {
            return 'Formulario listo. Puedes registrar datos ahora o pedir otra tabla.';
        }
        return '';
    }

    public function linkTaskExecution(
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        array  $task
    ): void {
    }

    public function autolinkWorkflow(string $tenantId, string $userId, string $projectId, string $mode, array $command): void
    {
    }

    public function logClassification(string $tenantId, string $userId, array $classification): void
    {
    }

    public function rememberAgentOpsTrace(
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        array $trace
    ): void
    {
        // Snapshot logic for production readiness
        $trace['tenant_id'] = $tenantId;
        $trace['user_id'] = $userId;
        $trace['project_id'] = $projectId;
        $trace['mode'] = $mode;
        $trace['ts'] = $trace['ts'] ?? date('c');

        $logDir = defined('APP_ROOT') ? APP_ROOT . '/storage/logs/agentops' : sys_get_temp_dir();
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        
        $logFile = $logDir . '/trace_' . date('Y-m-d') . '.jsonl';
        @file_put_contents(
            $logFile,
            json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

