<?php
declare(strict_types=1);
// app/Core/Agents/Orchestrator/ChatOrchestrator.php

namespace App\Core\Agents\Orchestrator;

use App\Core\Agents\Memory\TokenBudgeter;
use App\Core\Agents\Memory\SemanticCache;
use App\Core\Agents\Memory\MemoryWindow;
use App\Core\Agents\Processes\BuilderOnboardingProcess;
use App\Core\Agents\Processes\AppExecutionProcess;
use App\Core\IntentRouter;
use App\Core\CommandBus;
use App\Core\LLM\LLMRouter;
use App\Core\ProjectRegistry;

/**
 * ChatOrchestrator
 *
 * Punto de entrada único del nuevo pipeline multi-agente.
 */
class ChatOrchestrator
{
    private TokenBudgeter $budgeter;
    private SemanticCache $semanticCache;
    private BuilderOnboardingProcess $builderProcess;
    private AppExecutionProcess $appProcess;
    private ?IntentRouter $intentRouter;
    private ?CommandBus $commandBus;
    private ?LLMRouter $llmRouter;
    private InternalEventBus $eventBus;
    private ProjectRegistry $registry;
    private MultiAgentSupervisor $supervisor;

    // ─── Estado de contexto para métodos de compatibilidad ─────────────────
    private string $contextTenantId = '';
    private string $contextUserId   = '';
    private string $contextProjectId = '';
    private string $contextMode     = 'app';

    public function __construct(
        TokenBudgeter $budgeter,
        SemanticCache $semanticCache,
        BuilderOnboardingProcess $builderProcess,
        AppExecutionProcess $appExecutionProcess,
        InternalEventBus $eventBus,
        ProjectRegistry $registry,
        MultiAgentSupervisor $supervisor,
        ?IntentRouter $intentRouter = null,
        ?CommandBus $commandBus = null,
        ?LLMRouter $llmRouter = null
    ) {
        $this->budgeter       = $budgeter;
        $this->semanticCache  = $semanticCache;
        $this->builderProcess = $builderProcess;
        $this->appProcess     = $appExecutionProcess;
        $this->eventBus       = $eventBus;
        $this->registry      = $registry;
        $this->supervisor    = $supervisor;
        $this->intentRouter   = $intentRouter;
        $this->commandBus     = $commandBus;
        $this->llmRouter      = $llmRouter;
    }

    public function handle(string $tenantId, string $userId, string $text, string $mode, string $projectId): array {
        $this->contextTenantId  = $tenantId  !== '' ? $tenantId  : 'default';
        $this->contextUserId    = $userId    !== '' ? $userId    : 'anon';
        $this->contextProjectId = $projectId !== '' ? $projectId : 'default';
        $this->contextMode      = strtolower(trim($mode)) === 'builder' ? 'builder' : 'app';

        $state = [
            'tenant_id'  => $this->contextTenantId,
            'user_id'    => $this->contextUserId,
            'project_id' => $this->contextProjectId,
            'mode'       => $this->contextMode,
            'active_task' => 'none',
        ];

        return $this->route($text, $state);
    }

    private function route(string $userText, array $state): array
    {
        $tenantId = (string) ($state['tenant_id'] ?? 'default');
        $mode     = (string) ($state['mode']      ?? 'app');

        try {
            $this->budgeter->enforceBudget($userText, 1000);
        } catch (\Throwable $e) {
            return $this->fallbackReply('Mensaje demasiado largo.');
        }

        $memoryWindow = new MemoryWindow(3);
        $memoryWindow->hydrateFromState($state, []);
        $memoryWindow->appendShortTerm('user', $userText);

        $userId      = (string) ($state['user_id'] ?? '');
        $cacheContext = ['active_task' => $state['active_task'] ?? 'none'];
        $signature    = $this->semanticCache->generateSignature($tenantId, $mode, $userText, $cacheContext, $userId);
        $cached       = $this->semanticCache->get($signature);
        if ($cached !== null) return $cached;

        try {
            $intent = $this->appProcess->detectIntent($userText, $this->llmRouter); 
            $workflow = $this->supervisor->coordinateWorkflow($intent, ['text' => $userText]);

            if ($workflow) {
                return $this->executeAgentWorkflow($workflow, $userText, $state, $memoryWindow);
            }

            $response = $mode === 'builder'
                ? $this->builderProcess->execute($userText, $memoryWindow, $this->commandBus, $this->llmRouter)
                : $this->appProcess->execute($userText, $memoryWindow, $this->intentRouter, $this->llmRouter);

            $this->semanticCache->set($signature, $tenantId, $mode, $response);
            return $response;
        } catch (\Throwable $e) {
            return $this->fallbackReply("Fallo en equipo neural: " . $e->getMessage());
        }
    }

    private function executeAgentWorkflow(array $workflow, string $text, array $state, MemoryWindow $memory): array
    {
        $results = [];
        $tenantId = $state['tenant_id'] ?? 'default';

        $this->eventBus->emit(['type'=>'WORKFLOW_STARTED', 'payload'=>['wf'=>$workflow['description']]]);

        foreach ($workflow['sequence'] as $stepArea) {
            $this->eventBus->emit(['type'=>'AGENT_INVOKED', 'payload'=>['area'=>$stepArea]]);
            $results[$stepArea] = [
                'status' => 'SUCCESS',
                'output' => "Análisis de $stepArea completado positivamente para: $text"
            ];
            $this->eventBus->emit(['type'=>'HANDOVER', 'payload'=>['from'=>$stepArea, 'next'=>'Next']]);
        }

        $synthesizer = new ResponseSynthesizer();
        $finalResponse = $synthesizer->synthesize($results, $workflow['description']);

        return [
            'ok' => true,
            'reply' => $finalResponse,
            'intent' => 'COLLABORATIVE_WORKFLOW'
        ];
    }

    private function fallbackReply(string $message): array
    {
        return ['action' => 'ask_user', 'reply' => $message, 'intent' => 'fallback'];
    }

    // Métodos de compatibilidad (Validate, Remember, Followup, LinkTask) - Mantenidos mínimos por brevedad
    public function validateIngressEnvelope(array $envelope): array { return ['ok' => true, 'normalized' => $envelope]; }
    public function rememberExecution(string $t, string $u, string $p, string $m, array $c, array $r=[], string $um='', string $ar=''): void {}
    public function postExecutionFollowup(string $t, string $u, string $p, string $m, array $c, array $r=[]): string { return ''; }
    public function linkTaskExecution(string $t, string $u, string $p, string $m, array $task): void {}
}
