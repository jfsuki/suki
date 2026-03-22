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

/**
 * ChatOrchestrator
 *
 * Punto de entrada único del nuevo pipeline multi-agente.
 * Actúa como drop-in replacement de ConversationGateway para ChatAgent.
 *
 * Principios:
 * - Token budgeting antes de cualquier LLM call.
 * - Caché semántico (0 USD para respuestas repetidas).
 * - Delegación CrewAI-style: Builder Process vs App Process.
 * - Self-healing: manejo de errores top-level.
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
        ?IntentRouter $intentRouter = null,
        ?CommandBus $commandBus = null,
        ?LLMRouter $llmRouter = null
    ) {
        $this->budgeter       = $budgeter;
        $this->semanticCache  = $semanticCache;
        $this->builderProcess = $builderProcess;
        $this->appProcess     = $appExecutionProcess;
        $this->intentRouter   = $intentRouter;
        $this->commandBus     = $commandBus;
        $this->llmRouter      = $llmRouter;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // COMPATIBILIDAD CON ConversationGateway (usado por ChatAgent.php)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Valida el sobre del mensaje entrante.
     * Replica el contrato de ConversationGateway::validateIngressEnvelope().
     *
     * @param array<string,mixed> $envelope
     * @return array<string,mixed>
     */
    public function validateIngressEnvelope(array $envelope): array
    {
        $errors   = [];
        $warnings = [];

        $tenantId            = trim((string) ($envelope['tenant_id']   ?? ''));
        $userId              = trim((string) ($envelope['user_id']     ?? ''));
        $projectId           = trim((string) ($envelope['project_id']  ?? ''));
        $mode                = strtolower(trim((string) ($envelope['mode'] ?? 'app')));
        $message             = trim((string) ($envelope['message']     ?? $envelope['text'] ?? ''));
        $hasAttachment       = !empty($envelope['meta']) || !empty($envelope['attachments']);
        $isAuthenticated     = array_key_exists('is_authenticated', $envelope)
                                ? (bool) $envelope['is_authenticated']
                                : true;
        $authTenantId        = trim((string) ($envelope['auth_tenant_id']       ?? ''));
        $chatExecAuthRequired = (bool) ($envelope['chat_exec_auth_required']    ?? false);

        if ($tenantId  === '') { $errors[] = 'tenant_id requerido.'; }
        if ($userId    === '') { $errors[] = 'user_id requerido.'; }
        if ($projectId === '') { $errors[] = 'project_id requerido.'; }
        if (!in_array($mode, ['app', 'builder'], true)) { $errors[] = 'mode invalido.'; }
        if ($message === '' && !$hasAttachment) { $errors[] = 'message vacio sin adjuntos.'; }
        if ($authTenantId !== '' && $tenantId !== '' && $authTenantId !== $tenantId) {
            $errors[] = 'auth_tenant_id no coincide con tenant_id.';
        }
        if ($chatExecAuthRequired && !$isAuthenticated) {
            $warnings[] = 'chat_exec_auth_required activo sin autenticacion.';
        }

        return [
            'ok'      => $errors === [],
            'errors'  => $errors,
            'warnings' => $warnings,
            'normalized' => [
                'tenant_id'        => $tenantId   !== '' ? $tenantId   : 'default',
                'user_id'          => $userId      !== '' ? $userId     : 'anon',
                'project_id'       => $projectId   !== '' ? $projectId  : 'default',
                'mode'             => in_array($mode, ['app', 'builder'], true) ? $mode : 'app',
                'message'          => $message,
                'is_authenticated' => $isAuthenticated,
            ],
        ];
    }

    /**
     * Entry point principal. Replica el contrato de ConversationGateway::handle().
     * ChatAgent lo llama directamente con estos parámetros.
     *
     * @return array<string,mixed>
     */
    public function handle(
        string $tenantId,
        string $userId,
        string $text,
        string $mode,
        string $projectId
    ): array {
        // Guardar contexto para métodos de compatibilidad secundarios
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

    /**
     * Recuerda el resultado de una ejecución de tool/comando.
     * Replica ConversationGateway::rememberExecution().
     *
     * @param array<string,mixed> $command
     * @param array<string,mixed> $resultData
     */
    public function rememberExecution(
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        array  $command,
        array  $resultData = [],
        string $userMessage = '',
        string $assistantReply = ''
    ): void {
        // El nuevo pipeline no tiene estado monolítico en DB heredada.
        // En el futuro, MemoryWindow o un EventStore reemplazará esto.
        // Por ahora es un no-op seguro para mantener compatibilidad.
        // TODO Fase 5+: persistir snapshot en ops_semantic_cache o EventStore.
        error_log(sprintf(
            '[ChatOrchestrator] rememberExecution: cmd=%s entity=%s tenant=%s mode=%s',
            (string) ($command['command'] ?? 'unknown'),
            (string) ($command['entity']  ?? ''),
            $tenantId,
            $mode
        ));
    }

    /**
     * Genera un mensaje de seguimiento post-ejecución (ej. "¿Quieres la forma?").
     * Replica ConversationGateway::postExecutionFollowup().
     *
     * @param array<string,mixed> $command
     * @param array<string,mixed> $resultData
     */
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

        // Sugerencias de seguimiento básicas — idéntico a la lógica del Gateway
        if ($commandName === 'CreateEntity' && $entity !== '') {
            return 'Tabla ' . $entity . ' creada. ¿Deseas crear el formulario para capturar datos? (si/no)';
        }
        if ($commandName === 'CreateForm') {
            return 'Formulario listo. Puedes registrar datos ahora o pedir otra tabla.';
        }
        return '';
    }

    /**
     * Vincula una tarea del Control Tower a la sesión actual.
     * Replica ConversationGateway::linkTaskExecution().
     *
     * @param array<string,mixed> $task
     */
    public function linkTaskExecution(
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        array $task
    ): void {
        // El nuevo pipeline v2 utiliza MemoryWindow persistente.
        // Por ahora, este método asegura compatibilidad con los disparadores
        // reactivos del Control Tower sin romper el flujo.
        error_log(sprintf(
            '[ChatOrchestrator] linkTaskExecution: task=%s tenant=%s',
            (string) ($task['id'] ?? 'unknown'),
            $tenantId
        ));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PIPELINE INTERNO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Núcleo del pipeline: Token Budget → Semantic Cache → Process Delegation.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function route(string $userText, array $state): array
    {
        $tenantId = (string) ($state['tenant_id'] ?? 'default');
        $mode     = (string) ($state['mode']      ?? 'app');

        // 1. Presupuesto de tokens (fail-fast contra entradas abusivas)
        try {
            $this->budgeter->enforceBudget($userText, 1000);
        } catch (\Throwable $e) {
            return $this->fallbackReply('Mensaje demasiado largo. Intenta con una frase más corta.');
        }

        // 2. Inicializar ventana de memoria
        $memoryWindow = new MemoryWindow(3);
        $memoryWindow->hydrateFromState($state, []);
        $memoryWindow->appendShortTerm('user', $userText);

        // 3. Caché semántico (0 tokens, 0 USD para duplicados recientes)
        $cacheContext = ['active_task' => $state['active_task'] ?? 'none'];
        $signature    = $this->semanticCache->generateSignature($tenantId, $mode, $userText, $cacheContext);
        $cached       = $this->semanticCache->get($signature);
        if ($cached !== null) {
            return $cached;
        }

        // 4. Delegar al Proceso correcto (patrón CrewAI)
        try {
            $response = $mode === 'builder'
                ? $this->builderProcess->execute($userText, $memoryWindow, $this->commandBus, $this->llmRouter)
                : $this->appProcess->execute($userText, $memoryWindow, $this->intentRouter, $this->llmRouter);

            // 5. Guardar en caché para requests idénticos futuros
            $this->semanticCache->set($signature, $tenantId, $mode, $response);

            return $response;

        } catch (\Throwable $e) {
            error_log('[ChatOrchestrator] Error en pipeline: ' . $e->getMessage());
            return $this->fallbackReply('Tuve un problema procesando eso. ¿Puedes intentarlo de nuevo?');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackReply(string $message): array
    {
        return [
            'action'        => 'ask_user',
            'reply'         => $message,
            'intent'        => 'fallback',
            'resolved_locally' => true,
            'state_updates' => [],
        ];
    }
}
