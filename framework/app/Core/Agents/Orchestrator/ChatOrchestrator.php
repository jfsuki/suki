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
        $results        = [];
        $handoffContext = '';
        $tenantId       = (string) ($state['tenant_id'] ?? 'default');
        $registryDb     = $this->registry->db();
        $isParallel     = (bool) ($workflow['parallel'] ?? false);

        $this->eventBus->emit(['type' => 'WORKFLOW_STARTED', 'payload' => ['wf' => $workflow['description']]]);

        if ($isParallel) {
            $results = $this->executeParallel($workflow['sequence'], $text, $state, $tenantId, $registryDb);
        } else {
            foreach ($workflow['sequence'] as $stepArea) {
                $this->eventBus->emit(['type' => 'AGENT_INVOKED', 'payload' => ['area' => $stepArea]]);

                $persona     = \App\Core\Agents\Registry\SpecialistPersonas::getPersonaForTenant(
                    $stepArea, $tenantId, $registryDb
                );
                $agentOutput = $this->callSpecialistLLM($persona, $text, $state, $handoffContext);

                $results[$stepArea] = ['status' => 'SUCCESS', 'output' => $agentOutput];

                // Contexto acumulado para el siguiente agente en la cadena
                $handoffContext = "[{$stepArea}]: {$agentOutput}";

                $this->eventBus->emit(['type' => 'HANDOVER', 'payload' => [
                    'from'    => $stepArea,
                    'context' => substr($handoffContext, 0, 200),
                ]]);
            }
        }

        $synthesizer   = new ResponseSynthesizer();
        $finalResponse = $synthesizer->synthesize($results, $workflow['description']);

        return [
            'ok'     => true,
            'reply'  => $finalResponse,
            'intent' => 'COLLABORATIVE_WORKFLOW',
        ];
    }

    private function callSpecialistLLM(array $persona, string $userText, array $state, string $handoffContext = ''): string
    {
        if ($this->llmRouter === null) {
            return (string) ($persona['description'] ?? $persona['name'] ?? 'Agente sin LLM configurado.');
        }
        try {
            $llmParams    = is_array($persona['llm_params'] ?? null) ? (array) $persona['llm_params'] : [];
            $systemPrompt = trim((string) ($persona['prompt_base'] ?? $persona['description']));

            // FASE 6: Per-agent RAG — inyectar conocimiento de la colección propia del agente
            $agentKnowledge = $this->fetchAgentKnowledge($persona, $userText, (string) ($state['tenant_id'] ?? ''));
            if ($agentKnowledge !== '') {
                $systemPrompt .= "\n\nCONOCIMIENTO BASE DEL AGENTE:\n" . $agentKnowledge;
            }

            $capsule = [
                'policy' => [
                    'max_output_tokens'    => (int) ($llmParams['max_tokens'] ?? 400),
                    'requires_strict_json' => false,
                ],
                'prompt_contract' => [
                    'SYSTEM'         => $systemPrompt,
                    'USER_MESSAGE'   => $userText,
                    'TENANT_CONTEXT' => [
                        'tenant_id'  => $state['tenant_id'] ?? 'default',
                        'project_id' => $state['project_id'] ?? '',
                    ],
                ],
            ];

            if ($handoffContext !== '') {
                $capsule['prompt_contract']['HANDOFF_CONTEXT'] = $handoffContext;
            }

            $result  = $this->llmRouter->chat($capsule, [
                'temperature' => (float) ($llmParams['temperature'] ?? 0.3),
            ]);
            $content = (string) ($result['text'] ?? $result['content'] ?? '');
            return $content !== '' ? $content : (string) ($persona['description'] ?? '');
        } catch (\Throwable $e) {
            return (string) ($persona['description'] ?? '') . ' — error: ' . $e->getMessage();
        }
    }

    /**
     * FASE 5: Ejecución paralela con curl_multi (Windows-safe, PHP 8.3).
     * Si el LLMRouter no soporta prepareAsyncRequest(), cae a secuencial sin fallar.
     *
     * @param array<int,string> $sequence
     */
    private function executeParallel(array $sequence, string $text, array $state, string $tenantId, \PDO $registryDb): array
    {
        $results    = [];
        $personaMap = [];

        foreach ($sequence as $stepArea) {
            $personaMap[$stepArea] = \App\Core\Agents\Registry\SpecialistPersonas::getPersonaForTenant(
                $stepArea, $tenantId, $registryDb
            );
        }

        // Sin LLM router: devolver description de cada agente como placeholder
        if ($this->llmRouter === null) {
            foreach ($sequence as $stepArea) {
                $results[$stepArea] = [
                    'status' => 'SUCCESS',
                    'output' => (string) ($personaMap[$stepArea]['description'] ?? $stepArea),
                ];
            }
            return $results;
        }

        // Intentar paralelismo via curl_multi si el router lo soporta
        $multiHandle = curl_multi_init();
        $handles     = [];

        foreach ($sequence as $stepArea) {
            $persona      = $personaMap[$stepArea];
            $systemPrompt = trim((string) ($persona['prompt_base'] ?? $persona['description']));
            $agentKb      = $this->fetchAgentKnowledge($persona, $text, $tenantId);
            if ($agentKb !== '') {
                $systemPrompt .= "\n\nCONOCIMIENTO BASE DEL AGENTE:\n" . $agentKb;
            }

            $capsule = [
                'policy' => ['max_output_tokens' => 400, 'requires_strict_json' => false],
                'prompt_contract' => [
                    'SYSTEM'         => $systemPrompt,
                    'USER_MESSAGE'   => $text,
                    'TENANT_CONTEXT' => ['tenant_id' => $tenantId],
                ],
            ];

            try {
                // prepareAsyncRequest() retorna un curl handle o null (contrato opcional)
                $ch = method_exists($this->llmRouter, 'prepareAsyncRequest')
                    ? $this->llmRouter->prepareAsyncRequest($capsule)
                    : null;

                if ($ch !== null && is_resource($ch)) {
                    curl_multi_add_handle($multiHandle, $ch);
                    $handles[$stepArea] = $ch;
                } else {
                    // Fallback secuencial por agente
                    $results[$stepArea] = [
                        'status' => 'SUCCESS',
                        'output' => $this->callSpecialistLLM($persona, $text, $state),
                    ];
                }
            } catch (\Throwable) {
                $results[$stepArea] = [
                    'status' => 'SUCCESS',
                    'output' => $this->callSpecialistLLM($persona, $text, $state),
                ];
            }
        }

        // Procesar handles async si los hay
        if (!empty($handles)) {
            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle);
            } while ($running > 0);

            foreach ($handles as $stepArea => $ch) {
                $rawResponse = curl_multi_getcontent($ch);
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);

                $parsed = method_exists($this->llmRouter, 'parseAsyncResponse')
                    ? $this->llmRouter->parseAsyncResponse((string) $rawResponse)
                    : ['text' => ''];

                $results[$stepArea] = [
                    'status' => 'SUCCESS',
                    'output' => (string) ($parsed['text'] ?? ''),
                ];
            }
        }

        curl_multi_close($multiHandle);
        return $results;
    }

    /**
     * FASE 6: Recupera fragmentos de conocimiento de la colección Qdrant privada del agente.
     * Tenant isolation obligatoria en el filter. Falla silenciosamente — nunca bloquea el flujo.
     */
    private function fetchAgentKnowledge(array $persona, string $userText, string $tenantId): string
    {
        $collection = (string) ($persona['qdrant_collection'] ?? '');
        if ($collection === '' || $tenantId === '') {
            return '';
        }

        try {
            $embedding   = new \App\Core\GeminiEmbeddingService();
            $embedResult = $embedding->embed($userText);
            $vector      = is_array($embedResult['vector'] ?? null) ? (array) $embedResult['vector'] : [];
            if (empty($vector)) {
                return '';
            }

            $store   = new \App\Core\QdrantVectorStore(null, null, $collection);
            // Firma: query(vector, filter, limit) — tenant isolation obligatoria
            $results = $store->query($vector, [
                'must' => [['key' => 'tenant_id', 'match' => ['value' => $tenantId]]],
            ], 3);

            if (empty($results)) {
                return '';
            }

            $chunks = [];
            foreach ($results as $result) {
                $text = (string) ($result['payload']['text'] ?? $result['payload']['content'] ?? '');
                if ($text !== '') {
                    $chunks[] = $text;
                }
            }

            return implode("\n---\n", $chunks);
        } catch (\Throwable $e) {
            error_log('[ChatOrchestrator] fetchAgentKnowledge error: ' . $e->getMessage());
            return '';
        }
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
