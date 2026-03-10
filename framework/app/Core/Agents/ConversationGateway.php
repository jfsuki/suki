<?php
declare(strict_types=1);
// app/Core/Agents/ConversationGateway.php

namespace App\Core\Agents;

use App\Core\ContractsCatalog;
use App\Core\EntityRegistry;
use App\Core\BuilderOnboardingFlow;
use App\Core\MemoryRepositoryInterface;
use App\Core\ModeGuardPolicy;
use App\Core\ProjectRegistry;
use App\Core\SqlMemoryRepository;
use App\Core\LLM\LLMRouter;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class ConversationGateway
{
    use ConversationGatewayHandlePipelineTrait;
    use ConversationGatewayBuilderOnboardingTrait;
    use ConversationGatewayRoutingPolicyTrait;

    private string $projectRoot;
    private EntityRegistry $entities;
    private ContractsCatalog $catalog;
    private MemoryRepositoryInterface $memory;
    private ModeGuardPolicy $modeGuardPolicy;
    private BuilderOnboardingFlow $builderOnboardingFlow;
    private DialogStateEngine $dialogState;
    private array $trainingBaseCache = [];
    private ?array $confusionBaseCache = null;
    private ?array $domainPlaybookCache = null;
    private ?array $accountingKnowledgeCache = null;
    private ?array $unspscCommonCache = null;
    private ?array $countryOverridesCache = null;
    private array $latamLexiconCache = [];
    private string $contextProjectId = 'default';
    private string $contextMode = 'app';
    private string $contextProfileUser = 'anon';
    private string $contextTenantId = 'default';
    private string $contextUserId = 'anon';
    private string $contextSessionId = 'default__app__anon';
    private ?ProjectRegistry $projectRegistry = null;
    private ?array $scopedEntityNamesCache = null;
    private ?array $scopedFormNamesCache = null;
    private ?object $workingMemorySchemaCache = null;

    public function __construct(?string $projectRoot = null, ?MemoryRepositoryInterface $memory = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
        $this->entities = new EntityRegistry();
        $this->catalog = new ContractsCatalog($this->projectRoot);
        $this->memory = $memory ?? new SqlMemoryRepository();
        $this->modeGuardPolicy = new ModeGuardPolicy();
        $this->builderOnboardingFlow = new BuilderOnboardingFlow();
        $this->dialogState = new DialogStateEngine();
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
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $userId = $userId !== '' ? $userId : 'anon';
        $projectId = $projectId !== '' ? $projectId : 'default';
        $mode = strtolower(trim($mode)) === 'builder' ? 'builder' : 'app';

        $state = $this->loadState($tenantId, $userId, $projectId, $mode);
        $entity = $this->normalizeEntityForSchema((string) ($command['entity'] ?? ''));
        $state['last_action'] = [
            'command' => (string) ($command['command'] ?? ''),
            'entity' => $entity,
            'data' => is_array($command['data'] ?? null) ? $command['data'] : [],
            'filters' => is_array($command['filters'] ?? null) ? $command['filters'] : [],
            'result' => $this->summarizeExecutionData($resultData),
            'user_message' => $userMessage,
            'assistant_reply' => $assistantReply,
            'at' => date('c'),
        ];
        $commandName = (string) ($command['command'] ?? '');
        if ($mode === 'builder') {
            if ($commandName === 'CreateEntity' && $entity !== '') {
                $this->markBuilderCompletedEntity($state, $entity);
                $pendingEntity = $this->normalizeEntityForSchema((string) ($state['builder_pending_command']['entity'] ?? ''));
                if ($pendingEntity === '' || $pendingEntity === $entity) {
                    $this->clearBuilderPendingCommand($state);
                }
                $state['active_task'] = 'builder_onboarding';
                $state['onboarding_step'] = 'plan_ready';
            }
            if ($commandName === 'CreateForm') {
                $formEntity = $entity !== '' ? $entity : $this->normalizeEntityForSchema((string) ($command['entity'] ?? ''));
                if ($formEntity !== '') {
                    $this->markBuilderCompletedForm($state, $formEntity . '.form');
                }
                $this->clearBuilderPendingCommand($state);
                $state['active_task'] = 'builder_onboarding';
                $state['onboarding_step'] = 'plan_ready';
            }

            if (in_array($commandName, ['CreateRelation', 'CreateIndex', 'InstallPlaybook'], true)) {
                $state['feedback_pending'] = [
                    'command' => $commandName,
                    'entity' => $entity,
                    'asked_at' => date('c'),
                ];
            }
        }
        $this->contextProjectId = $projectId;
        $this->contextMode = $mode;
        $this->contextProfileUser = $this->profileUserKey($userId);
        $this->saveState($tenantId, $userId, $state);
    }

    public function rememberAgentOpsTrace(
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        array $trace
    ): void {
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $userId = $userId !== '' ? $userId : 'anon';
        $projectId = $projectId !== '' ? $projectId : 'default';
        $mode = strtolower(trim($mode)) === 'builder' ? 'builder' : 'app';

        $this->contextProjectId = $projectId;
        $this->contextMode = $mode;
        $this->contextProfileUser = $this->profileUserKey($userId);

        $state = $this->loadState($tenantId, $userId, $projectId, $mode);
        $history = is_array($state['agentops_trace_history'] ?? null) ? (array) $state['agentops_trace_history'] : [];
        $history[] = [
            'at' => date('c'),
            'route_path' => trim((string) ($trace['route_path'] ?? '')),
            'route_reason' => trim((string) ($trace['route_reason'] ?? '')),
            'request_mode' => trim((string) ($trace['request_mode'] ?? 'operation')) ?: 'operation',
            'query_hash' => trim((string) ($trace['query_hash'] ?? '')),
            'llm_used' => (bool) ($trace['llm_used'] ?? false),
            'rag_attempted' => (bool) ($trace['rag_attempted'] ?? false),
            'rag_used' => (bool) ($trace['rag_used'] ?? false),
            'evidence_gate_status' => trim((string) ($trace['evidence_gate_status'] ?? '')),
            'fallback_reason' => trim((string) ($trace['fallback_reason'] ?? '')),
            'module_used' => trim((string) ($trace['module_used'] ?? '')) ?: 'none',
            'alert_action' => trim((string) ($trace['alert_action'] ?? '')) ?: 'none',
            'task_action' => trim((string) ($trace['task_action'] ?? '')) ?: 'none',
            'reminder_action' => trim((string) ($trace['reminder_action'] ?? '')) ?: 'none',
            'media_action' => trim((string) ($trace['media_action'] ?? '')) ?: 'none',
            'pending_items_count' => is_numeric($trace['pending_items_count'] ?? null)
                ? max(0, (int) $trace['pending_items_count'])
                : null,
            'loop_guard_triggered' => (bool) ($trace['loop_guard_triggered'] ?? false),
            'tool_calls_count' => max(0, (int) ($trace['tool_calls_count'] ?? 0)),
            'retry_count' => max(0, (int) ($trace['retry_count'] ?? 0)),
        ];
        $state['agentops_trace_history'] = array_values(array_slice($history, -8));
        $state['agentops_last_trace'] = end($state['agentops_trace_history']) ?: null;
        $this->saveState($tenantId, $userId, $state);
    }

    public function postExecutionFollowup(
        string $tenantId,
        string $userId,
        string $projectId,
        string $mode,
        array $command,
        array $resultData = []
    ): string {
        $mode = strtolower(trim($mode)) === 'builder' ? 'builder' : 'app';
        if ($mode !== 'builder') {
            return '';
        }

        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $userId = $userId !== '' ? $userId : 'anon';
        $projectId = $projectId !== '' ? $projectId : 'default';

        $this->contextProjectId = $projectId;
        $this->contextMode = $mode;
        $this->contextProfileUser = $this->profileUserKey($userId);

        $state = $this->loadState($tenantId, $userId, $projectId, $mode);
        $profile = $this->getProfile($tenantId, $this->profileUserKey($userId));
        $commandName = (string) ($command['command'] ?? '');
        $entity = $this->normalizeEntityForSchema((string) ($command['entity'] ?? ''));

        if ($commandName === 'CreateEntity' && $entity !== '') {
            $alreadyExists = (bool) ($resultData['already_exists'] ?? false);
            $plan = is_array($state['builder_plan'] ?? null) ? (array) $state['builder_plan'] : [];
            $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
            $nextProposal = null;
            if ($businessType !== '' && !empty($plan)) {
                $nextProposal = $this->buildNextStepProposal(
                    $businessType,
                    $plan,
                    $profile,
                    (string) ($profile['owner_name'] ?? ''),
                    $state
                );
            }

            $progress = $this->computeBuilderPlanProgress($plan, $state);
            $done = count((array) ($progress['done_entities'] ?? []));
            $total = count((array) ($progress['plan_entities'] ?? []));
            $nextCommand = is_array($nextProposal['command'] ?? null) ? (array) $nextProposal['command'] : [];
            $nextEntity = $this->normalizeEntityForSchema((string) ($nextProposal['entity'] ?? ($nextCommand['entity'] ?? '')));
            $nextLabel = $nextEntity !== '' ? $nextEntity : ($nextCommand['command'] ?? '');

            if ($alreadyExists) {
                if (!empty($nextCommand)) {
                    $this->setBuilderPendingCommand($state, $nextCommand);
                    $state['active_task'] = ((string) ($nextCommand['command'] ?? '') === 'CreateForm') ? 'create_form' : 'create_table';
                    $state['entity'] = $nextEntity !== '' ? $nextEntity : ($state['entity'] ?? null);
                    $this->saveState($tenantId, $userId, $state);

                    $lines = [];
                    if ($total > 0) {
                        $lines[] = 'Avance: ' . $done . '/' . $total . ' tablas.';
                    }
                    $lines[] = 'Seguimos con: ' . ($nextLabel !== '' ? $nextLabel : 'siguiente paso') . '.';
                    $lines[] = $this->buildPendingPreviewReply($nextCommand);
                    return implode("\n", $lines);
                }
                return $this->buildBuilderPlanProgressReply($state, $profile, false);
            }

            $state['builder_calc_prompt'] = [
                'entity' => $entity,
                'phase' => 'confirm',
                'next_command' => $nextCommand,
                'done' => $done,
                'total' => $total,
            ];
            $this->saveState($tenantId, $userId, $state);

            $lines = [];
            if ($total > 0) {
                $lines[] = 'Avance: tabla ' . $done . ' de ' . $total . ' creada.';
            }
            if ($nextLabel !== '') {
                $lines[] = 'Siguiente sugerida: ' . $nextLabel . '.';
            }
            $lines[] = 'Antes de seguir: esta tabla tiene campos calculados? (si/no)';
            $lines[] = 'Ejemplo: total=subtotal+iva';
            return implode("\n", $lines);
        }

        if ($commandName === 'CreateForm') {
            return $this->buildBuilderPlanProgressReply($state, $profile, false);
        }

        if (in_array($commandName, ['CreateRelation', 'CreateIndex', 'InstallPlaybook'], true)) {
            return 'Si te sirvio este paso, escribe: "me sirvio".' . "\n"
                . 'Si no te sirvio, escribe: "no me sirvio".';
        }

        return '';
    }

    private function handleBuilderCalculatedPrompt(
        string $text,
        string $raw,
        array &$state,
        array $profile,
        string $tenantId,
        string $userId,
        string $mode
    ): ?array {
        if ($mode !== 'builder') {
            return null;
        }
        $prompt = is_array($state['builder_calc_prompt'] ?? null) ? (array) $state['builder_calc_prompt'] : [];
        if (empty($prompt)) {
            return null;
        }

        $entity = $this->normalizeEntityForSchema((string) ($prompt['entity'] ?? ''));
        $phase = strtolower((string) ($prompt['phase'] ?? 'confirm'));

        if ($phase === 'capture') {
            if ($this->isNegativeReply($text) || $this->isNextStepQuestion($text)) {
                $state['builder_calc_prompt'] = null;
                $resume = $this->resumeAfterCalculatedPrompt($state, $prompt, $profile);
                $reply = 'Listo, esta tabla queda sin campos calculados.' . "\n" . $resume;
                $state = $this->updateState($state, $raw, $reply, 'builder_formula_skip', $entity !== '' ? $entity : null, [], $state['active_task'] ?? 'builder_onboarding');
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_formula', true));
            }

            $formulaRows = $this->parseCalculatedExpressions($raw);
            if (empty($formulaRows)) {
                $reply = 'Escribe la formula en una sola linea. Ejemplo: total=subtotal+iva' . "\n"
                    . 'Si no necesitas formula, responde: no.';
                $state = $this->updateState($state, $raw, $reply, 'builder_formula_capture', $entity !== '' ? $entity : null, [], 'builder_onboarding');
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_formula', true));
            }

            $saved = $this->appendBuilderFormulaNotes($state, $entity, $formulaRows);
            $state['builder_calc_prompt'] = null;
            $resume = $this->resumeAfterCalculatedPrompt($state, $prompt, $profile);
            $reply = 'Perfecto. Guarde ' . count($saved) . ' campo(s) calculado(s) para ' . ($entity !== '' ? $entity : 'esta tabla') . ':' . "\n";
            foreach ($saved as $row) {
                $reply .= '- ' . $row['field'] . ' = ' . $row['expression'] . "\n";
            }
            $reply .= $resume;
            $state = $this->updateState($state, $raw, $reply, 'builder_formula_saved', $entity !== '' ? $entity : null, [], $state['active_task'] ?? 'builder_onboarding');
            $this->saveState($tenantId, $userId, $state);
            return $this->result('ask_user', trim($reply), null, null, $state, $this->telemetry('builder_formula', true));
        }

        if ($this->isClarificationRequest($text) || $this->isFieldHelpQuestion($text) || str_contains($text, 'que es') || str_contains($text, 'formula')) {
            $reply = 'Campo calculado = dato que se calcula solo.' . "\n"
                . 'Ejemplos:' . "\n"
                . '- total=subtotal+iva' . "\n"
                . '- saldo=total-abono' . "\n"
                . 'Responde "si" si quieres agregarlo o "no" para seguir.';
            $state = $this->updateState($state, $raw, $reply, 'builder_formula_help', $entity !== '' ? $entity : null, [], 'builder_onboarding');
            $this->saveState($tenantId, $userId, $state);
            return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_formula', true));
        }

        if ($this->isNegativeReply($text) || $this->isNextStepQuestion($text)) {
            $state['builder_calc_prompt'] = null;
            $resume = $this->resumeAfterCalculatedPrompt($state, $prompt, $profile);
            $reply = 'Perfecto, seguimos sin campos calculados en ' . ($entity !== '' ? $entity : 'esta tabla') . '.' . "\n" . $resume;
            $state = $this->updateState($state, $raw, $reply, 'builder_formula_skip', $entity !== '' ? $entity : null, [], $state['active_task'] ?? 'builder_onboarding');
            $this->saveState($tenantId, $userId, $state);
            return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_formula', true));
        }

        $formulaRows = $this->parseCalculatedExpressions($raw);
        if (!empty($formulaRows)) {
            $saved = $this->appendBuilderFormulaNotes($state, $entity, $formulaRows);
            $state['builder_calc_prompt'] = null;
            $resume = $this->resumeAfterCalculatedPrompt($state, $prompt, $profile);
            $reply = 'Perfecto. Guarde ' . count($saved) . ' campo(s) calculado(s):' . "\n";
            foreach ($saved as $row) {
                $reply .= '- ' . $row['field'] . ' = ' . $row['expression'] . "\n";
            }
            $reply .= $resume;
            $state = $this->updateState($state, $raw, trim($reply), 'builder_formula_saved', $entity !== '' ? $entity : null, [], $state['active_task'] ?? 'builder_onboarding');
            $this->saveState($tenantId, $userId, $state);
            return $this->result('ask_user', trim($reply), null, null, $state, $this->telemetry('builder_formula', true));
        }

        if ($this->isAffirmativeReply($text)) {
            $prompt['phase'] = 'capture';
            $state['builder_calc_prompt'] = $prompt;
            $reply = 'Listo. Escribe la formula en una linea.' . "\n"
                . 'Ejemplo: total=subtotal+iva' . "\n"
                . 'Si quieres omitir, responde: no.';
            $state = $this->updateState($state, $raw, $reply, 'builder_formula_capture', $entity !== '' ? $entity : null, [], 'builder_onboarding');
            $this->saveState($tenantId, $userId, $state);
            return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_formula', true));
        }

        $reply = 'Antes de continuar necesito una confirmacion corta:' . "\n"
            . '- si (agregar campo calculado)' . "\n"
            . '- no (seguir al siguiente paso)';
        $state = $this->updateState($state, $raw, $reply, 'builder_formula_confirm', $entity !== '' ? $entity : null, [], 'builder_onboarding');
        $this->saveState($tenantId, $userId, $state);
        return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_formula', true));
    }

    private function resumeAfterCalculatedPrompt(array &$state, array $prompt, array $profile): string
    {
        $nextCommand = is_array($prompt['next_command'] ?? null) ? (array) $prompt['next_command'] : [];
        $done = (int) ($prompt['done'] ?? 0);
        $total = (int) ($prompt['total'] ?? 0);

        if (!empty($nextCommand)) {
            $this->setBuilderPendingCommand($state, $nextCommand);
            $state['active_task'] = ((string) ($nextCommand['command'] ?? '') === 'CreateForm') ? 'create_form' : 'create_table';
            $state['entity'] = $this->normalizeEntityForSchema((string) ($nextCommand['entity'] ?? ($state['entity'] ?? '')));
            $lines = [];
            if ($total > 0) {
                $lines[] = 'Avance actual: ' . $done . '/' . $total . ' tablas.';
            }
            $lines[] = 'Siguiente paso listo:';
            $lines[] = $this->buildPendingPreviewReply($nextCommand);
            return implode("\n", $lines);
        }

        $state['active_task'] = 'builder_onboarding';
        $this->clearBuilderPendingCommand($state);
        if ($total > 0) {
            return 'Avance actual: ' . $done . '/' . $total . ' tablas.' . "\n"
                . $this->buildBuilderPlanProgressReply($state, $profile, false);
        }
        return $this->buildBuilderPlanProgressReply($state, $profile, false);
    }

    private function appendBuilderFormulaNotes(array &$state, string $entity, array $formulaRows): array
    {
        $entity = $this->normalizeEntityForSchema($entity);
        $saved = [];
        $notes = is_array($state['builder_formula_notes'] ?? null) ? (array) $state['builder_formula_notes'] : [];
        $bucket = is_array($notes[$entity] ?? null) ? (array) $notes[$entity] : [];
        foreach ($formulaRows as $row) {
            $field = $this->normalizeEntityForSchema((string) ($row['field'] ?? ''));
            $expression = trim((string) ($row['expression'] ?? ''));
            if ($field === '' || $expression === '') {
                continue;
            }
            $signature = sha1($field . '|' . $expression);
            $exists = false;
            foreach ($bucket as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (($item['signature'] ?? '') === $signature) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                continue;
            }
            $entry = [
                'field' => $field,
                'expression' => $expression,
                'signature' => $signature,
                'created_at' => date('c'),
            ];
            $bucket[] = $entry;
            $saved[] = $entry;
        }
        $notes[$entity] = array_slice($bucket, -30);
        $state['builder_formula_notes'] = $notes;
        return $saved;
    }

    private function parseCalculatedExpressions(string $text): array
    {
        $rows = [];
        if ($text === '') {
            return $rows;
        }

        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*([a-zA-Z0-9_+\-*\/().\s]+)/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $field = $this->normalizeEntityForSchema((string) ($match[1] ?? ''));
                $expression = trim((string) ($match[2] ?? ''));
                $expression = preg_replace('/\s+/', ' ', $expression) ?? $expression;
                $expression = preg_replace('/[^a-zA-Z0-9_+\-*\/(). ]/', '', $expression) ?? $expression;
                if ($field === '' || $expression === '') {
                    continue;
                }
                if (preg_match('/[+\-*\/()]/', $expression) !== 1 && !str_contains($expression, '_')) {
                    continue;
                }
                $rows[] = [
                    'field' => $field,
                    'expression' => trim($expression),
                ];
            }
        }
        return $rows;
    }

    private function isPureGreeting(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $greetings = ['hola', 'buenas', 'buenos dias', 'buen dÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â­a', 'buen dia', 'buenas tardes', 'buenas noches', 'hello', 'saludos'];
        return in_array($text, $greetings, true);
    }

    private function isCapabilitiesQuestion(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $patterns = [
            'que puedo',
            'q puedo',
            'que opciones',
            'q opciones',
            'que haces',
            'como me ayudas',
            'que puedo hacer',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isSoftwareBlueprintQuestion(string $text): bool
    {
        $patterns = [
            'ruta para hacer un software',
            'como construyo una app',
            'logica de programacion',
            'arquitectura de base de datos',
            'desarrollo de software',
            'como crear un programa bien',
            'guia tecnica',
            'mejor practica',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isUnspscQuestion(string $text): bool
    {
        $patterns = [
            'unspsc',
            'clasificador de bienes',
            'clasificador de bienes y servicios',
            'colombia compra',
            'codigo dian',
            'codigo producto',
            'codigo de producto',
            'codigo servicio',
            'codigo de servicio',
            'codigo de factura electronica',
            'codigo de facturacion electronica',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function buildUnspscReply(string $text, array $profile = [], string $mode = 'app'): string
    {
        $knowledge = $this->loadUnspscCommon();
        if (empty($knowledge)) {
            return 'Puedo ayudarte con codigos UNSPSC, pero la base local no esta cargada.' . "\n"
                . 'Usa el clasificador oficial de Colombia Compra y te guio a guardarlo en tu app.';
        }

        $matches = $this->matchUnspscItems($text, $knowledge);
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        if ($businessType === '') {
            $businessType = $this->detectBusinessType($text);
        }
        $topScore = !empty($matches) ? (int) ($matches[0]['_score'] ?? 0) : 0;
        $useDirectMatches = !empty($matches) && $topScore >= 6;
        $lines = [];
        $lines[] = 'Para facturacion electronica usa codigo UNSPSC por producto/servicio.';

        if ($useDirectMatches) {
            $displayMatches = array_values(array_filter(
                $matches,
                static fn(array $item): bool => ((int) ($item['_score'] ?? 0)) >= 20
            ));
            if (empty($displayMatches)) {
                $displayMatches = $matches;
            }
            $lines[] = 'Coincidencias sugeridas:';
            foreach (array_slice($displayMatches, 0, 4) as $item) {
                $code = (string) ($item['code'] ?? '');
                $name = (string) ($item['name_es'] ?? '');
                $alias = is_array($item['aliases'] ?? null) ? $item['aliases'] : [];
                $aliasText = !empty($alias) ? ' | alias: ' . implode(', ', array_slice($alias, 0, 2)) : '';
                $lines[] = '- ' . $code . ' - ' . $name . $aliasText;
            }
        } else {
            $recommended = $this->recommendedUnspscByBusiness($businessType, $knowledge);
            if (!empty($recommended)) {
                $label = $businessType !== '' ? $businessType : 'tu negocio';
                $lines[] = 'Codigos comunes para ' . str_replace('_', ' ', $label) . ':';
                foreach (array_slice($recommended, 0, 4) as $item) {
                    $lines[] = '- ' . (string) ($item['code'] ?? '') . ' - ' . (string) ($item['name_es'] ?? '');
                }
            } else {
                $lines[] = 'Dime el nombre comercial del item (ej: tornillo galvanizado 1/4) y te sugiero codigo.';
            }
        }

        $lines[] = 'Paso final: valida el codigo exacto en el clasificador oficial antes de emitir.';
        if ($mode === 'builder') {
            $lines[] = 'Tip builder: agrega campo codigo_unspsc:texto en productos y servicios.';
        } else {
            $lines[] = 'Tip app: si falta el campo codigo_unspsc, pidelo al creador de la app.';
        }

        return implode("\n", $lines);
    }

    private function isOutOfScopeQuestion(string $text, string $mode): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $scopeKeywords = [
            'tabla', 'entidad', 'formulario', 'form', 'campo', 'campos', 'app', 'aplicacion', 'programa', 'proyecto',
            'base de datos', 'reporte', 'dashboard', 'usuario', 'rol', 'permiso', 'inventario',
            'cliente', 'clientes', 'producto', 'productos', 'servicio', 'servicios', 'factura', 'facturas',
            'crear', 'listar', 'actualizar', 'eliminar', 'guardar', 'registrar',
        ];
        foreach ($this->scopedEntityNames() as $entityName) {
            $name = strtolower((string) $entityName);
            if ($name !== '') {
                $scopeKeywords[] = $name;
                if (str_ends_with($name, 's')) {
                    $scopeKeywords[] = rtrim($name, 's');
                } else {
                    $scopeKeywords[] = $name . 's';
                }
            }
        }
        foreach (array_unique($scopeKeywords) as $keyword) {
            if ($keyword !== '' && str_contains($text, $keyword)) {
                return false;
            }
        }

        $offTopicKeywords = [
            'presidente', 'petro', 'politica', 'futbol', 'partido', 'noticia', 'noticias', 'clima', 'pronostico',
            'horoscopo', 'celebridad', 'farandula', 'chisme', 'elecciones', 'guerra',
        ];
        foreach ($offTopicKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        if ($mode === 'builder' && preg_match('/\b(sabes sobre|que opinas|cuentame de)\b/u', $text) === 1) {
            return true;
        }

        return false;
    }

    private function buildOutOfScopeReply(string $mode): string
    {
        if ($mode === 'builder') {
            return 'Te ayudo a crear apps y procesos de negocio.' . "\n"
                . 'Para temas generales (noticias, politica, deportes), usa Google, ChatGPT o Gemini.' . "\n"
                . 'Si seguimos aqui, dime que programa quieres crear para tu negocio.';
        }

        return 'Estoy enfocada en esta app (registrar y consultar datos).' . "\n"
            . 'Para temas generales usa Google, ChatGPT o Gemini.' . "\n"
            . 'Si quieres, te guio con una accion de la app.';
    }

    private function isPendingPreviewQuestion(string $text): bool
    {
        $patterns = [
            'que vas a crear',
            'q vas a crear',
            'que se va a crear',
            'que vas a hacer',
            'que hara',
            'que va a crear',
            'cual es esa tabla',
            'que tabla es esa',
            'cuales campos vas a crear',
            'que campos vas a crear',
            'campos vas a crear',
            'cuales campos',
            'que campos',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isFrustrationMessage(string $text): bool
    {
        $patterns = ['no sabes', 'no entiendes', 'no entendiste', 'estas mal', 'eso no', 'no era eso'];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isClarificationRequest(string $text): bool
    {
        $patterns = ['no entiendo', 'no entendi', 'explicame', 'explica', 'aclarame', 'aclara', 'no me quedo claro', 'que debo hacer'];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function buildPendingPreviewReply(array $command): string
    {
        $commandName = (string) ($command['command'] ?? '');
        if ($commandName === 'CreateEntity') {
            $entity = (string) ($command['entity'] ?? 'tabla');
            $fields = is_array($command['fields'] ?? null) ? $command['fields'] : [];
            $fieldNames = [];
            foreach (array_slice($fields, 0, 8) as $field) {
                $name = trim((string) ($field['name'] ?? ''));
                if ($name !== '') {
                    $fieldNames[] = $name;
                }
            }
            $preview = !empty($fieldNames) ? implode(', ', $fieldNames) : 'campos base';
            return 'Voy a crear la tabla ' . $entity . ' con estos datos: ' . $preview . '.' . "\n"
                . 'Responde "si" para crearla o "no" para cambiarla.';
        }
        if ($commandName === 'CreateForm') {
            $entity = (string) ($command['entity'] ?? 'tabla');
            return 'Voy a crear el formulario para ' . $entity . '.' . "\n"
                . 'Responde "si" para crearlo o "no" para cambiarlo.';
        }
        if ($commandName === 'InstallPlaybook') {
            $sector = strtoupper(trim((string) ($command['sector_key'] ?? 'NEGOCIO')));
            return 'Voy a instalar la plantilla experta para ' . $sector . '.' . "\n"
                . 'Responde "si" para instalarla o "no" para cancelarla.';
        }
        if ($commandName === 'CreateRelation') {
            $source = (string) ($command['source_entity'] ?? 'tabla_origen');
            $target = (string) ($command['target_entity'] ?? 'tabla_destino');
            $fk = (string) ($command['fk_field'] ?? ($source . '_id'));
            return 'Voy a conectar ' . $target . ' con ' . $source . ' usando el campo ' . $fk . '.' . "\n"
                . 'Responde "si" para aplicarlo o "no" para cambiarlo.';
        }
        if ($commandName === 'CreateIndex') {
            $entity = (string) ($command['entity'] ?? 'tabla');
            $field = (string) ($command['field'] ?? 'nombre');
            return 'Voy a optimizar ' . $entity . ' con un indice en ' . $field . '.' . "\n"
                . 'Responde "si" para aplicarlo o "no" para cambiarlo.';
        }
        if ($commandName === 'ImportIntegrationOpenApi') {
            $apiName = (string) ($command['api_name'] ?? 'api_externa');
            $docUrl = (string) ($command['doc_url'] ?? '');
            return 'Voy a importar la integracion ' . $apiName . ' desde OpenAPI.' . "\n"
                . ($docUrl !== '' ? 'Fuente: ' . $docUrl . "\n" : '')
                . 'Responde "si" para crear el contrato o "no" para cambiarlo.';
        }
        if ($commandName === 'CompileWorkflow') {
            $workflowId = (string) ($command['workflow_id'] ?? 'wf_nuevo');
            return 'Voy a compilar tu descripcion en un workflow y guardarlo como borrador (' . $workflowId . ').' . "\n"
                . 'Responde "si" para compilarlo o "no" para cambiar la descripcion.';
        }
        return 'Tengo una accion pendiente para continuar.' . "\n"
            . 'Responde "si" para ejecutarla o "no" para cambiarla.';
    }

    private function buildPendingClarificationReply(array $command): string
    {
        $commandName = (string) ($command['command'] ?? '');
        if ($commandName === 'CreateEntity') {
            $entity = (string) ($command['entity'] ?? 'tabla');
            return 'Te explico facil:' . "\n"
                . '- Voy a crear la tabla ' . $entity . ' para guardar esos datos.' . "\n"
                . '- Si estas de acuerdo responde "si".' . "\n"
                . '- Si quieres cambiar datos responde "no".' . "\n"
                . '- Si quieres ver campos sugeridos, escribe: "cuales campos me sugieres".';
        }
        if ($commandName === 'CreateForm') {
            $entity = (string) ($command['entity'] ?? 'tabla');
            return 'Te explico facil:' . "\n"
                . '- Voy a crear el formulario para la tabla ' . $entity . '.' . "\n"
                . '- Responde "si" para seguir o "no" para cambiar.';
        }
        if ($commandName === 'InstallPlaybook') {
            $sector = strtoupper(trim((string) ($command['sector_key'] ?? 'NEGOCIO')));
            return 'Te explico facil:' . "\n"
                . '- Voy a instalar la plantilla para ' . $sector . '.' . "\n"
                . '- Responde "si" para instalarla o "no" para mantenerlo manual.';
        }
        if ($commandName === 'CreateRelation') {
            $source = (string) ($command['source_entity'] ?? 'tabla_origen');
            $target = (string) ($command['target_entity'] ?? 'tabla_destino');
            $fk = (string) ($command['fk_field'] ?? ($source . '_id'));
            return 'Te explico facil:' . "\n"
                . '- Voy a relacionar ' . $target . ' con ' . $source . '.' . "\n"
                . '- Creare el campo ' . $fk . ' para guardar la referencia.' . "\n"
                . '- Responde "si" para aplicarlo o "no" para cambiar la relacion.';
        }
        if ($commandName === 'CreateIndex') {
            $entity = (string) ($command['entity'] ?? 'tabla');
            $field = (string) ($command['field'] ?? 'nombre');
            return 'Te explico facil:' . "\n"
                . '- Voy a crear un indice en ' . $entity . '.' . $field . ' para acelerar busquedas.' . "\n"
                . '- Responde "si" para aplicarlo o "no" para elegir otro campo.';
        }
        if ($commandName === 'ImportIntegrationOpenApi') {
            $apiName = (string) ($command['api_name'] ?? 'api_externa');
            return 'Te explico facil:' . "\n"
                . '- Voy a leer el OpenAPI de ' . $apiName . '.' . "\n"
                . '- Con eso creare el contrato de integracion con base_url, autenticacion y endpoints.' . "\n"
                . '- Responde "si" para continuar o "no" para cambiar la fuente.';
        }
        if ($commandName === 'CompileWorkflow') {
            return 'Te explico facil:' . "\n"
                . '- Voy a convertir tu idea en nodos y conexiones de workflow.' . "\n"
                . '- Luego lo guardare como borrador para que lo edites.' . "\n"
                . '- Responde "si" para compilar o "no" para ajustar.';
        }
        return 'Te explico facil: tengo una accion pendiente.' . "\n"
            . 'Responde "si" para seguir o "no" para cambiar.';
    }

    private function setBuilderPendingCommand(array &$state, array $command): void
    {
        $command['_tx'] = [
            'signature' => $this->pendingSignature($command),
            'created_at' => date('c'),
        ];
        $state['builder_pending_command'] = $command;
        $state['pending_loop_counter'] = 0;
    }

    private function clearBuilderPendingCommand(array &$state): void
    {
        $state['builder_pending_command'] = null;
        $state['pending_loop_counter'] = 0;
    }

    private function pendingSignature(array $command): string
    {
        $base = [
            'command' => (string) ($command['command'] ?? ''),
            'entity' => (string) ($command['entity'] ?? ''),
            'fields' => is_array($command['fields'] ?? null) ? $command['fields'] : [],
            'source_entity' => (string) ($command['source_entity'] ?? ''),
            'target_entity' => (string) ($command['target_entity'] ?? ''),
            'fk_field' => (string) ($command['fk_field'] ?? ''),
            'field' => (string) ($command['field'] ?? ''),
            'index_name' => (string) ($command['index_name'] ?? ''),
            'sector_key' => (string) ($command['sector_key'] ?? ''),
            'api_name' => (string) ($command['api_name'] ?? ''),
            'doc_url' => (string) ($command['doc_url'] ?? ''),
            'workflow_id' => (string) ($command['workflow_id'] ?? ''),
            'text' => (string) ($command['text'] ?? ''),
        ];
        $json = json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = (string) microtime(true);
        }
        return sha1($json);
    }

    private function incrementPendingLoopCounter(array &$state): int
    {
        $current = (int) ($state['pending_loop_counter'] ?? 0);
        $current++;
        $state['pending_loop_counter'] = $current;
        return $current;
    }

    private function markBuilderCompletedEntity(array &$state, string $entity): void
    {
        $entity = $this->normalizeEntityForSchema($entity);
        if ($entity === '') {
            return;
        }
        $completed = is_array($state['builder_completed_entities'] ?? null) ? $state['builder_completed_entities'] : [];
        $completed[$entity] = date('c');
        $state['builder_completed_entities'] = $completed;
    }

    private function markBuilderCompletedForm(array &$state, string $formName): void
    {
        $formName = strtolower(trim($formName));
        if ($formName === '') {
            return;
        }
        if (!str_ends_with($formName, '.form')) {
            $formName .= '.form';
        }
        $completed = is_array($state['builder_completed_forms'] ?? null) ? $state['builder_completed_forms'] : [];
        $completed[$formName] = date('c');
        $state['builder_completed_forms'] = $completed;
    }

    private function buildHardPendingLoopReply(array $state): string
    {
        $pending = is_array($state['builder_pending_command'] ?? null) ? (array) $state['builder_pending_command'] : [];
        if (empty($pending)) {
            return 'No tengo accion pendiente. Dime el siguiente paso que quieres crear.';
        }

        return 'Para no enredarnos, te resumo la accion pendiente:' . "\n"
            . $this->buildPendingPreviewReply($pending) . "\n"
            . 'Responde solo una opcion:' . "\n"
            . '- si (ejecutar)' . "\n"
            . '- no (cambiar tabla/campos)' . "\n"
            . '- mostrar avance (ver estado actual)';
    }

    private function buildSoftwareBlueprintReply(array $profile = []): string
    {
        $playbook = $this->loadDomainPlaybook();
        $route = is_array($playbook['software_build_route'] ?? null) ? $playbook['software_build_route'] : [];
        $logic = is_array($playbook['programming_logic_principles'] ?? null) ? $playbook['programming_logic_principles'] : [];
        $db = is_array($playbook['database_architecture_principles'] ?? null) ? $playbook['database_architecture_principles'] : [];
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));

        $lines = [];
        $lines[] = 'Ruta profesional para construir tu app sin enredos:';
        if (!empty($route)) {
            foreach (array_slice($route, 0, 6) as $step) {
                $lines[] = '- ' . $step;
            }
        } else {
            $lines[] = '- Definir proceso de negocio';
            $lines[] = '- Definir tablas y relaciones';
            $lines[] = '- Crear formularios y pruebas';
            $lines[] = '- Activar reportes y seguridad';
        }

        if (!empty($logic)) {
            $lines[] = 'Reglas de logica que aplicare por ti:';
            foreach (array_slice($logic, 0, 4) as $rule) {
                $lines[] = '- ' . $rule;
            }
        }

        if (!empty($db)) {
            $lines[] = 'Base de datos (resumen):';
            foreach (array_slice($db, 0, 4) as $rule) {
                $lines[] = '- ' . $rule;
            }
        }

        if ($businessType !== '') {
            $profileData = $this->findDomainProfile($businessType, $playbook);
            $label = (string) ($profileData['label'] ?? $businessType);
            $lines[] = 'Plantilla activa para tu negocio: ' . $label . '.';
        }

        $lines[] = 'Siguiente paso: dime tu negocio y empezamos con la primera tabla.';
        return implode("\n", $lines);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($converted !== false) {
            $text = $converted;
        }
        return $text;
    }

    private function normalizeWithTraining(string $text, array $training, string $tenantId = 'default', array $profile = [], string $mode = 'app'): string
    {
        $text = $this->normalize($text);
        if (empty($training)) {
            return $text;
        }
        $typos = $training['typos_and_normalization']['rules'] ?? [];
        foreach ($typos as $rule) {
            $match = mb_strtolower((string) ($rule['match'] ?? ''));
            $replace = mb_strtolower((string) ($rule['replace'] ?? ''));
            if ($match !== '') {
                $text = str_replace($match, $replace, $text);
            }
        }
        $fillers = $training['typos_and_normalization']['strip_fillers'] ?? [];
        foreach ($fillers as $filler) {
            $f = mb_strtolower((string) $filler);
            if ($f !== '') {
                $pattern = '/\\b' . preg_quote($f, '/') . '\\b/u';
                $text = preg_replace($pattern, ' ', $text) ?? $text;
            }
        }
        // Normalize very short slang tokens (q/k) to avoid missing intents.
        $text = preg_replace('/\\bq\\b/u', 'que', $text) ?? $text;
        $text = preg_replace('/\\bk\\b/u', 'que', $text) ?? $text;
        $text = preg_replace('/\\bqueiro\\b/u', 'quiero', $text) ?? $text;
        $text = preg_replace('/\\bqiero\\b/u', 'quiero', $text) ?? $text;

        $latamLexicon = $this->loadLatamLexiconPack($tenantId);
        $text = $this->applyLatamLexiconPack($text, $latamLexicon, $mode);

        $countryCode = $this->resolveCountryCode($profile, $text);
        $countryOverrides = $this->loadCountryOverrides($tenantId);
        $globalRules = is_array($countryOverrides['global']['typo_rules'] ?? null)
            ? $countryOverrides['global']['typo_rules']
            : [];
        $countryRules = is_array($countryOverrides['countries'][$countryCode]['typo_rules'] ?? null)
            ? $countryOverrides['countries'][$countryCode]['typo_rules']
            : [];
        $allRules = array_merge($globalRules, $countryRules);
        foreach ($allRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $match = $this->normalize((string) ($rule['match'] ?? ''));
            $replace = $this->normalize((string) ($rule['replace'] ?? ''));
            if ($match === '') {
                continue;
            }
            $text = preg_replace('/\\b' . preg_quote($match, '/') . '\\b/u', $replace, $text) ?? $text;
        }

        $globalSynonyms = is_array($countryOverrides['global']['synonyms'] ?? null)
            ? $countryOverrides['global']['synonyms']
            : [];
        $countrySynonyms = is_array($countryOverrides['countries'][$countryCode]['synonyms'] ?? null)
            ? $countryOverrides['countries'][$countryCode]['synonyms']
            : [];
        foreach (array_merge($globalSynonyms, $countrySynonyms) as $alias => $target) {
            $alias = $this->normalize((string) $alias);
            $target = $this->normalize((string) $target);
            if ($alias === '' || $target === '') {
                continue;
            }
            if ($this->shouldSkipAmbiguousSynonym($alias, $target, $text, $mode)) {
                continue;
            }
            $text = preg_replace('/\\b' . preg_quote($alias, '/') . '\\b/u', $target, $text) ?? $text;
        }

        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
        return $text;
    }

    private function loadLatamLexiconPack(string $tenantId = 'default'): array
    {
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $basePath = $frameworkRoot . '/contracts/agents/latam_es_col_conversation_lexicon.json';
        $tenantPath = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/latam_lexicon_overrides.json';
        $baseMtime = is_file($basePath) ? (int) @filemtime($basePath) : 0;
        $cacheKey = $this->safe($tenantId);

        $tenant = $this->memory->getTenantMemory($tenantId, 'latam_lexicon_overrides', []);
        if (empty($tenant) && is_file($tenantPath)) {
            $tenant = $this->readJson($tenantPath, []);
            if (!empty($tenant)) {
                $this->memory->saveTenantMemory($tenantId, 'latam_lexicon_overrides', $tenant);
            }
        }
        $tenantHashSource = json_encode($tenant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tenantHash = is_string($tenantHashSource) ? sha1($tenantHashSource) : '';

        if (isset($this->latamLexiconCache[$cacheKey])) {
            $cached = $this->latamLexiconCache[$cacheKey];
            if (($cached['base_mtime'] ?? 0) === $baseMtime && ($cached['tenant_hash'] ?? '') === $tenantHash) {
                return is_array($cached['data'] ?? null) ? $cached['data'] : [];
            }
        }

        $base = $this->readJson($basePath, []);
        $merged = [
            'phrase_rules' => array_merge(
                is_array($base['phrase_rules'] ?? null) ? $base['phrase_rules'] : [],
                is_array($tenant['phrase_rules'] ?? null) ? $tenant['phrase_rules'] : []
            ),
            'synonyms' => array_merge(
                is_array($base['synonyms'] ?? null) ? $base['synonyms'] : [],
                is_array($tenant['synonyms'] ?? null) ? $tenant['synonyms'] : []
            ),
            'stop_tokens' => array_values(array_unique(array_merge(
                is_array($base['stop_tokens'] ?? null) ? $base['stop_tokens'] : [],
                is_array($tenant['stop_tokens'] ?? null) ? $tenant['stop_tokens'] : []
            ))),
        ];

        $this->latamLexiconCache[$cacheKey] = [
            'data' => $merged,
            'base_mtime' => $baseMtime,
            'tenant_hash' => $tenantHash,
        ];

        return $merged;
    }

    private function applyLatamLexiconPack(string $text, array $pack, string $mode = 'app'): string
    {
        $phraseRules = is_array($pack['phrase_rules'] ?? null) ? $pack['phrase_rules'] : [];
        foreach ($phraseRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $match = $this->normalize((string) ($rule['match'] ?? ''));
            $replace = $this->normalize((string) ($rule['replace'] ?? ''));
            if ($match === '') {
                continue;
            }
            $text = str_replace($match, $replace, $text);
        }

        $synonyms = is_array($pack['synonyms'] ?? null) ? $pack['synonyms'] : [];
        foreach ($synonyms as $alias => $target) {
            $alias = $this->normalize((string) $alias);
            $target = $this->normalize((string) $target);
            if ($alias === '' || $target === '') {
                continue;
            }
            if ($this->shouldSkipAmbiguousSynonym($alias, $target, $text, $mode)) {
                continue;
            }
            $text = preg_replace('/\\b' . preg_quote($alias, '/') . '\\b/u', $target, $text) ?? $text;
        }

        $stopTokens = is_array($pack['stop_tokens'] ?? null) ? $pack['stop_tokens'] : [];
        foreach ($stopTokens as $token) {
            $token = $this->normalize((string) $token);
            if ($token === '') {
                continue;
            }
            $text = preg_replace('/\\b' . preg_quote($token, '/') . '\\b/u', ' ', $text) ?? $text;
        }

        return preg_replace('/\s+/', ' ', trim($text)) ?? $text;
    }

    private function shouldSkipAmbiguousSynonym(string $alias, string $target, string $text, string $mode): bool
    {
        if ($mode === 'builder') {
            return false;
        }
        if ($alias === 'lista' && $target === 'tabla') {
            if (preg_match('/\b(lista(r)?|mostrar|ver|buscar|dame)\b/u', $text) === 1) {
                return true;
            }
            if (preg_match('/\blista\s+de\b/u', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    private function classify(string $text): string
    {
        if ($text === '') {
            return 'faq';
        }
        $greetings = ['hola', 'buenas', 'buenos', 'hello', 'saludos'];
        $thanks = ['gracias', 'thank', 'ok', 'listo'];
        $confirm = ['si', 'confirmo', 'dale'];
        $status = ['estado', 'estatus', 'status', 'progreso', 'avance', 'resumen del proyecto', 'estado del proyecto'];
        $buildMarkers = ['tabla', 'entidad', 'formulario', 'form'];
        $faq = ['ayuda', 'menu', 'funciones', 'que puedes', 'que haces', 'opciones'];

        foreach ($status as $w) {
            if (str_contains($text, $w)) return 'status';
        }
        foreach ($greetings as $w) {
            if (str_contains($text, $w)) return 'greeting';
        }
        foreach ($thanks as $w) {
            if (str_contains($text, $w)) return 'thanks';
        }
        foreach ($confirm as $w) {
            if ($text === $w) return 'confirm';
        }

        if (str_contains($text, 'crear')) {
            foreach ($buildMarkers as $marker) {
                if (str_contains($text, $marker)) {
                    return 'crud';
                }
            }
        }

        foreach ($faq as $w) {
            if (str_contains($text, $w)) return 'faq';
        }

        $crudVerbs = ['crear', 'agregar', 'nuevo', 'listar', 'lista', 'ver', 'buscar', 'mostrar', 'muestrame', 'dame', 'actualizar', 'editar', 'eliminar', 'borrar', 'guardar', 'registrar', 'emitir', 'facturar'];
        foreach ($crudVerbs as $verb) {
            if (str_contains($text, $verb)) return 'crud';
        }

        return 'question';
    }

    private function localReply(string $type, string $mode = 'app'): string
    {
        switch ($type) {
            case 'greeting':
                return 'Hola, soy Cami. Dime que necesitas crear o consultar.';
            case 'thanks':
                return 'Con gusto. Estoy atenta.';
            case 'confirm':
                return 'Listo, continuo.';
            case 'status':
                return $mode === 'builder' ? $this->buildProjectStatus() : $this->buildAppStatus();
            case 'faq':
            default:
                return $this->buildCapabilities([], [], $mode);
        }
    }

    private function buildProjectStatus(): string
    {
        $entityNames = $this->scopedEntityNames();
        $formNames = $this->scopedFormNames();
        $viewsPath = $this->projectRoot . '/views';
        $views = [];
        if (is_dir($viewsPath)) {
            $views = array_values(array_filter(scandir($viewsPath) ?: [], fn($f) => is_string($f) && !in_array($f, ['.', '..'], true)));
        }

        $lines = [];
        $lines[] = 'Estado del proyecto:';
        $lines[] = '- Entidades: ' . count($entityNames);
        $lines[] = '- Formularios: ' . count($formNames);
        $lines[] = '- Vistas: ' . count($views);
        $lines[] = 'Ultimas entidades: ' . (count($entityNames) ? implode(', ', array_slice($entityNames, 0, 3)) : 'sin entidades');
        $lines[] = 'Ultimos formularios: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 3)) : 'sin formularios');
        return implode("\n", $lines);
    }

    private function buildBuilderPlanProgressReply(array $state, array $profile = [], bool $includePending = true): string
    {
        $plan = is_array($state['builder_plan'] ?? null) ? (array) $state['builder_plan'] : [];
        if (empty($plan)) {
            return $this->buildProjectStatus();
        }

        $progress = $this->computeBuilderPlanProgress($plan, $state);
        $done = $progress['done_entities'];
        $missing = $progress['missing_entities'];
        $planEntities = $progress['plan_entities'];
        $missingForms = $progress['missing_forms'];

        $lines = [];
        $lines[] = 'Esto es lo que llevo de tu app:';
        $lines[] = '- Tablas creadas: ' . count($done) . '/' . count($planEntities) . '.';
        $lines[] = '- Creadas: ' . (!empty($done) ? implode(', ', array_slice($done, 0, 6)) : 'ninguna');
        if (!empty($missing)) {
            $next = (string) $missing[0];
            $lines[] = '- Faltan: ' . implode(', ', array_slice($missing, 0, 6)) . '.';
            $lines[] = 'Siguiente recomendada: ' . $next . '.';
            $lines[] = 'Si quieres, la creo por ti ahora. Responde: si.';
        } else {
            $lines[] = '- Ruta base de tablas completada.';
            if (!empty($missingForms)) {
                $lines[] = '- Falta crear formulario: ' . $missingForms[0] . '.';
            } else {
                $lines[] = '- Formularios de la ruta listos.';
                $lines[] = 'Siguiente paso: abre el chat de la app para registrar datos y validar flujo.';
            }
        }

        if ($includePending && !empty($state['builder_pending_command']) && is_array($state['builder_pending_command'])) {
            $lines[] = '';
            $lines[] = 'Accion pendiente:';
            $lines[] = $this->buildPendingPreviewReply((array) $state['builder_pending_command']);
        }

        return implode("\n", $lines);
    }

    private function buildAppStatus(): string
    {
        $entities = $this->scopedEntityNames();
        $forms = $this->scopedFormNames();
        $lines = [];
        $lines[] = 'En esta app puedes trabajar con:';
        $lines[] = '- Listas: ' . (count($entities) ? implode(', ', array_slice($entities, 0, 5)) : 'ninguna lista activa');
        $lines[] = '- Formularios: ' . (count($forms) ? implode(', ', array_slice($forms, 0, 5)) : 'ningun formulario activo');
        if (!empty($entities)) {
            $e = $entities[0];
            $lines[] = 'Ejemplos: crear ' . $e . ' nombre=valor, listar ' . $e;
        } else {
            $lines[] = 'No hay listas activas. Pide al creador agregar una tabla.';
        }
        return implode("\n", $lines);
    }

    private function syncDialogState(array $state, string $mode, array $profile = []): array
    {
        return $this->dialogState->sync(
            $state,
            $mode,
            $profile,
            count($this->scopedEntityNames()),
            count($this->scopedFormNames())
        );
    }

    private function isDialogChecklistQuestion(string $text): bool
    {
        $patterns = [
            'paso actual',
            'checklist',
            'en que paso',
            'en que vamos',
            'que falta',
            'q falta',
            'q mas falta',
            'que sigue',
            'estado actual',
            'avance actual',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function buildDialogChecklistReply(array $state, string $mode, array $profile = []): string
    {
        $synced = $this->syncDialogState($state, $mode, $profile);
        $reply = $this->dialogState->buildChecklistReply($synced, $mode);
        if ($mode === 'builder' && !empty($state['builder_pending_command']) && is_array($state['builder_pending_command'])) {
            $reply .= "\n\n" . 'Accion pendiente:' . "\n" . $this->buildPendingPreviewReply((array) $state['builder_pending_command']);
        }

        if ($mode === 'builder') {
            $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
            if ($businessType !== '') {
                $profileData = $this->findDomainProfile($businessType);
                $label = (string) ($profileData['label'] ?? $businessType);
                $reply .= "\n" . 'Ruta activa: ' . $label . '.';
            }
        }

        return $reply;
    }

    private function parseBuild(string $text, array $state, array $profile = []): array
    {
        $playbookInstall = $this->parseInstallPlaybookRequest($text);
        if (!empty($playbookInstall['matched'])) {
            if (!empty($playbookInstall['list_only'])) {
                return [
                    'ask' => $this->buildPlaybookListReply(),
                    'active_task' => 'builder_onboarding',
                ];
            }
            $sectorKey = (string) ($playbookInstall['sector_key'] ?? '');
            if ($sectorKey === '') {
                return [
                    'ask' => 'Dime el sector del playbook que quieres instalar.' . "\n" . $this->buildPlaybookListReply(),
                    'active_task' => 'builder_onboarding',
                ];
            }
            return [
                'command' => [
                    'command' => 'InstallPlaybook',
                    'sector_key' => $sectorKey,
                    'dry_run' => !empty($playbookInstall['dry_run']),
                    'overwrite' => !empty($playbookInstall['overwrite']),
                ],
                'entity' => null,
                'collected' => ['sector_key' => $sectorKey],
            ];
        }

        $hasCreate = str_contains($text, 'crear') || preg_match('/\b(crea|armar|construir|haz)\b/u', $text) === 1;
        $hasTable = str_contains($text, 'tabla') || str_contains($text, 'entidad');
        $hasForm = str_contains($text, 'formulario') || str_contains($text, 'form');

        if ($hasCreate && $hasTable) {
            if (!$this->hasFieldPairs($text)) {
                $entityHint = $this->parseEntityFromCrudText($text);
                if ($entityHint === '') {
                    $entityHint = (string) ($state['entity'] ?? 'clientes');
                }
                $entityHint = $this->adaptEntityToBusinessContext($this->normalizeEntityForSchema($entityHint), $profile, $text);
                if ($entityHint === '') {
                    return ['ask' => 'Como se llama la tabla? Ej: clientes nombre:texto nit:texto', 'active_task' => 'create_table'];
                }
                if ($this->entityExists($entityHint)) {
                    return $this->buildExistingEntityNextStep($entityHint, $state, $profile);
                }
                $dependencyGuide = $this->buildDependencyGuidanceForBuilder($entityHint, $profile);
                if (!empty($dependencyGuide)) {
                    return $dependencyGuide;
                }
                $proposal = $this->buildCreateTableProposal($entityHint, $profile);
                return [
                    'ask' => $proposal['reply'],
                    'active_task' => 'create_table',
                    'entity' => $entityHint,
                    'pending_command' => $proposal['command'],
                ];
            }
            $parsed = $this->parseTableDefinition($text);
            $parsed['entity'] = $this->adaptEntityToBusinessContext((string) ($parsed['entity'] ?? ''), $profile, $text);
            if ($parsed['entity'] === '') {
                return ['ask' => 'Como se llama la tabla? Ej: clientes nombre:texto nit:texto', 'active_task' => 'create_table'];
            }
            if ($this->entityExists((string) $parsed['entity'])) {
                return $this->buildExistingEntityNextStep((string) $parsed['entity'], $state, $profile);
            }
            $dependencyGuide = $this->buildDependencyGuidanceForBuilder($parsed['entity'], $profile);
            if (!empty($dependencyGuide)) {
                return $dependencyGuide;
            }
            if (empty($parsed['fields'])) {
                $proposal = $this->buildCreateTableProposal($parsed['entity'], $profile);
                return [
                    'ask' => $proposal['reply'],
                    'active_task' => 'create_table',
                    'entity' => $parsed['entity'],
                    'pending_command' => $proposal['command'],
                ];
            }
            return ['command' => ['command' => 'CreateEntity', 'entity' => $parsed['entity'], 'fields' => $parsed['fields']], 'entity' => $parsed['entity'], 'collected' => []];
        }

        if ($hasCreate && $hasForm) {
            $entity = $this->parseEntityFromText($text);
            $entity = $this->normalizeEntityForSchema($entity);
            if ($entity === '') {
                return ['ask' => 'De que tabla quieres el formulario? Ej: crear formulario clientes', 'active_task' => 'create_form'];
            }
            if (!$this->entityExists($entity)) {
                $proposal = $this->buildCreateTableProposal($entity, $profile);
                return [
                    'ask' => 'Para crear ese formulario primero necesito la tabla base.' . "\n" . $proposal['reply'],
                    'active_task' => 'create_table',
                    'entity' => $proposal['entity'],
                    'pending_command' => $proposal['command'],
                ];
            }
            $dependencyGuide = $this->buildDependencyGuidanceForBuilder($entity, $profile);
            if (!empty($dependencyGuide)) {
                return $dependencyGuide;
            }
            return ['command' => ['command' => 'CreateForm', 'entity' => $entity], 'entity' => $entity, 'collected' => []];
        }

        if ($hasCreate && !$hasTable && !$hasForm) {
            if (preg_match('/\b(programa|app|aplicacion|sistema)\b/u', $text) === 1 && !$this->hasFieldPairs($text)) {
                
        

        return [];
            }
            if (($state['active_task'] ?? '') !== 'create_table' && $this->isQuestionLike($text) && !$this->hasFieldPairs($text)) {
                
        

        return [];
            }
            $entity = $this->parseEntityFromCrudText($text);
            if ($entity !== '') {
                if ($this->entityExists($entity)) {
                    return ['ask' => 'La tabla ' . $entity . ' ya existe. Quieres crear su formulario?', 'active_task' => 'create_form', 'entity' => $entity];
                }
                $proposal = $this->buildCreateTableProposal($entity, $profile);
                return [
                    'ask' => $proposal['reply'],
                    'active_task' => 'create_table',
                    'entity' => $entity,
                    'pending_command' => $proposal['command'],
                ];
            }
        }

        if (($state['active_task'] ?? '') === 'create_table') {
            $currentEntity = (string) ($state['entity'] ?? '');
            if ($this->isBuilderProgressQuestion($text)) {
                return ['ask' => $this->buildBuilderPlanProgressReply($state, $profile, true), 'active_task' => 'create_table', 'entity' => $currentEntity];
            }
            if ($this->isEntityListQuestion($text)) {
                $reply = $this->buildEntityList();
                if ($currentEntity !== '') {
                    $proposal = $this->buildCreateTableProposal($currentEntity, $profile);
                    return ['ask' => $reply . "\n" . $proposal['reply'], 'active_task' => 'create_table', 'entity' => $currentEntity, 'pending_command' => $proposal['command']];
                }
                return ['ask' => $reply, 'active_task' => 'create_table'];
            }
            if ($this->isQuestionLike($text) && !$this->hasFieldPairs($text) && !str_contains($text, 'tabla') && !str_contains($text, 'entidad')) {
                if ($currentEntity !== '') {
                    $proposal = $this->buildCreateTableProposal($currentEntity, $profile);
                    return ['ask' => $proposal['reply'], 'active_task' => 'create_table', 'entity' => $currentEntity, 'pending_command' => $proposal['command']];
                }
                return ['ask' => 'Antes de crear, dime el nombre de la tabla. Ejemplo: clientes.', 'active_task' => 'create_table'];
            }
            if ($currentEntity !== '' && $this->isFieldHelpQuestion($text)) {
                $proposal = $this->buildCreateTableProposal($currentEntity, $profile);
                return ['ask' => $proposal['reply'], 'active_task' => 'create_table', 'entity' => $currentEntity, 'pending_command' => $proposal['command']];
            }
            if ($currentEntity !== '' && $this->isQuestionLike($text) && !$this->hasFieldPairs($text)) {
                $proposal = $this->buildCreateTableProposal($currentEntity, $profile);
                return ['ask' => $proposal['reply'], 'active_task' => 'create_table', 'entity' => $currentEntity, 'pending_command' => $proposal['command']];
            }
            $parsed = $this->parseTableDefinition($text);
            if ($parsed['entity'] === '' && !empty($state['entity'])) {
                $parsed['entity'] = (string) $state['entity'];
            }
            $parsed['entity'] = $this->adaptEntityToBusinessContext((string) ($parsed['entity'] ?? ''), $profile, $text);
            if ($parsed['entity'] === '') {
                return ['ask' => 'Necesito el nombre de la tabla. Ej: clientes nombre:texto nit:texto', 'active_task' => 'create_table'];
            }
            if ($this->entityExists((string) $parsed['entity'])) {
                return $this->buildExistingEntityNextStep((string) $parsed['entity'], $state, $profile);
            }
            $dependencyGuide = $this->buildDependencyGuidanceForBuilder($parsed['entity'], $profile);
            if (!empty($dependencyGuide)) {
                return $dependencyGuide;
            }
            if (empty($parsed['fields'])) {
                $proposal = $this->buildCreateTableProposal($parsed['entity'], $profile);
                return [
                    'ask' => $proposal['reply'],
                    'active_task' => 'create_table',
                    'entity' => $parsed['entity'],
                    'pending_command' => $proposal['command'],
                ];
            }
            return ['command' => ['command' => 'CreateEntity', 'entity' => $parsed['entity'], 'fields' => $parsed['fields']], 'entity' => $parsed['entity'], 'collected' => []];
        }

        if (($state['active_task'] ?? '') === 'create_form') {
            $entity = $this->parseEntityFromText($text);
            $entity = $this->normalizeEntityForSchema($entity);
            if ($entity === '') {
                return ['ask' => 'Necesito la tabla para el formulario. Ej: clientes', 'active_task' => 'create_form'];
            }
            return ['command' => ['command' => 'CreateForm', 'entity' => $entity], 'entity' => $entity, 'collected' => []];
        }

        
        

        return [];
    }

    private function parseInstallPlaybookRequest(string $text): array
    {
        $text = preg_replace('/playbo+que/u', 'playbook', $text) ?? $text;

        $listSignals = [
            'que playbooks',
            'listar playbooks',
            'lista de playbooks',
            'playbooks disponibles',
            'sectores disponibles',
            'que sectores',
        ];
        foreach ($listSignals as $signal) {
            if (str_contains($text, $signal)) {
                return ['matched' => true, 'list_only' => true, 'sector_key' => ''];
            }
        }

        $installSignals = [
            'instalar playbook',
            'instala playbook',
            'aplicar playbook',
            'aplica playbook',
            'activar playbook',
            'instalar sector',
            'instala sector',
        ];

        $matched = false;
        foreach ($installSignals as $signal) {
            if (str_contains($text, $signal)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return ['matched' => false, 'list_only' => false, 'sector_key' => '', 'dry_run' => false, 'overwrite' => false];
        }

        $dryRun = str_contains($text, 'simulacion')
            || str_contains($text, 'dry run')
            || str_contains($text, 'sin instalar')
            || str_contains($text, 'solo revisar');
        $overwrite = str_contains($text, 'overwrite')
            || str_contains($text, 'sobrescribir')
            || str_contains($text, 'reinstalar');

        return [
            'matched' => true,
            'list_only' => false,
            'sector_key' => $this->detectSectorKeyFromText($text),
            'dry_run' => $dryRun,
            'overwrite' => $overwrite,
        ];
    }

    private function buildPlaybookListReply(): string
    {
        $playbook = $this->loadDomainPlaybook();
        $sectors = is_array($playbook['sector_playbooks'] ?? null) ? $playbook['sector_playbooks'] : [];
        if (empty($sectors)) {
            return 'No hay playbooks sectoriales disponibles en este proyecto.';
        }
        $lines = [];
        $lines[] = 'Playbooks disponibles:';
        foreach ($sectors as $sector) {
            if (!is_array($sector)) {
                continue;
            }
            $key = strtoupper((string) ($sector['sector_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = $this->humanizeSectorKey($key);
            $miniApps = is_array($sector['mini_apps'] ?? null) ? $sector['mini_apps'] : [];
            $mini = !empty($miniApps) ? (string) ($miniApps[0] ?? '') : '';
            if ($mini !== '') {
                $lines[] = '- ' . $label . ' (' . $key . '): ' . str_replace('_', ' ', $mini);
            } else {
                $lines[] = '- ' . $label . ' (' . $key . ')';
            }
        }
        $lines[] = 'Para instalar, escribe: instalar playbook FERRETERIA (o el sector que necesites).';
        return implode("\n", $lines);
    }

    private function detectSectorKeyFromText(string $text): string
    {
        $text = $this->normalize($text);
        $playbook = $this->loadDomainPlaybook();
        $sectors = is_array($playbook['sector_playbooks'] ?? null) ? $playbook['sector_playbooks'] : [];
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        $profileByKey = [];
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $key = strtolower((string) ($profile['key'] ?? ''));
            if ($key !== '') {
                $profileByKey[$key] = $profile;
            }
        }

        foreach ($sectors as $sector) {
            if (!is_array($sector)) {
                continue;
            }
            $sectorKey = strtoupper((string) ($sector['sector_key'] ?? ''));
            if ($sectorKey === '') {
                continue;
            }
            if (str_contains($text, strtolower($sectorKey))) {
                return $sectorKey;
            }
            $triggers = is_array($sector['triggers'] ?? null) ? $sector['triggers'] : [];
            foreach ($triggers as $trigger) {
                $needle = $this->normalize((string) $trigger);
                if ($needle !== '' && str_contains($text, $needle)) {
                    return $sectorKey;
                }
            }
            $profileKey = strtolower((string) ($sector['profile_key'] ?? ''));
            if ($profileKey !== '' && isset($profileByKey[$profileKey])) {
                $profile = $profileByKey[$profileKey];
                $label = $this->normalize((string) ($profile['label'] ?? ''));
                if ($label !== '' && str_contains($text, $label)) {
                    return $sectorKey;
                }
                $aliases = is_array($profile['aliases'] ?? null) ? $profile['aliases'] : [];
                foreach ($aliases as $alias) {
                    $needle = $this->normalize((string) $alias);
                    if ($needle !== '' && str_contains($text, $needle)) {
                        return $sectorKey;
                    }
                }
            }
        }

        return '';
    }

    private function buildExistingEntityNextStep(string $entity, array $state, array $profile = []): array
    {
        $entity = $this->normalizeEntityForSchema($entity);
        if ($entity === '') {
            return ['ask' => 'La tabla ya existe. Dime otra tabla para continuar.', 'active_task' => 'builder_onboarding'];
        }

        if (!$this->formExistsForEntity($entity)) {
            return [
                'ask' => 'La tabla ' . $entity . ' ya existe. No la vuelvo a crear.' . "\n"
                    . 'Siguiente paso recomendado: crear formulario ' . $entity . '.form.' . "\n"
                    . 'Quieres que lo cree por ti ahora? Responde: si o no.',
                'active_task' => 'create_form',
                'entity' => $entity,
                'pending_command' => [
                    'command' => 'CreateForm',
                    'entity' => $entity,
                ],
            ];
        }

        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        if ($businessType !== '' && is_array($state['builder_plan'] ?? null)) {
            $proposal = $this->buildNextStepProposal(
                $businessType,
                (array) $state['builder_plan'],
                $profile,
                (string) ($profile['owner_name'] ?? ''),
                $state
            );
            if (!empty($proposal['command']) && is_array($proposal['command'])) {
                return [
                    'ask' => 'La tabla ' . $entity . ' ya existe. No la vuelvo a crear.' . "\n" . (string) ($proposal['reply'] ?? ''),
                    'active_task' => (string) ($proposal['active_task'] ?? 'create_table'),
                    'entity' => (string) ($proposal['entity'] ?? $entity),
                    'pending_command' => (array) $proposal['command'],
                ];
            }
            return [
                'ask' => 'La tabla ' . $entity . ' ya existe. No la vuelvo a crear.' . "\n"
                    . (string) ($proposal['reply'] ?? $this->buildBuilderPlanProgressReply($state, $profile, false)),
                'active_task' => (string) ($proposal['active_task'] ?? 'builder_onboarding'),
                'entity' => $entity,
            ];
        }

        return [
            'ask' => 'La tabla ' . $entity . ' ya existe. No la vuelvo a crear.' . "\n"
                . $this->buildBuilderPlanProgressReply($state, $profile, false),
            'active_task' => 'builder_onboarding',
            'entity' => $entity,
        ];
    }

    private function handleBuilderOnboarding(string $text, array $state, array $profile, string $tenantId, string $userId): ?array
    {
        return $this->builderOnboardingFlow->handle(
            $text,
            $state,
            $profile,
            $tenantId,
            $userId,
            [
                'parseInstallPlaybookRequest' => fn(string $value): array => $this->parseInstallPlaybookRequest($value),
                'classifyWithPlaybookIntents' => fn(string $value, array $context): array => $this->classifyWithPlaybookIntents($value, $context),
                'isBuilderOnboardingTrigger' => fn(string $value): bool => $this->isBuilderOnboardingTrigger($value),
                'detectBusinessType' => fn(string $value): string => $this->detectBusinessType($value),
                'isFormListQuestion' => fn(string $value): bool => $this->isFormListQuestion($value),
                'buildFormList' => fn(): string => $this->buildFormList(),
                'isEntityListQuestion' => fn(string $value): bool => $this->isEntityListQuestion($value),
                'buildEntityList' => fn(): string => $this->buildEntityList(),
                'isBuilderProgressQuestion' => fn(string $value): bool => $this->isBuilderProgressQuestion($value),
                'buildProjectStatus' => fn(): string => $this->buildProjectStatus(),
            ],
            function (
                string $innerText,
                array $innerState,
                array $innerProfile,
                string $innerTenantId,
                string $innerUserId,
                bool $isOnboarding,
                bool $trigger,
                bool $businessHint
            ): ?array {
                return $this->handleBuilderOnboardingCore(
                    $innerText,
                    $innerState,
                    $innerProfile,
                    $innerTenantId,
                    $innerUserId,
                    $isOnboarding,
                    $trigger,
                    $businessHint
                );
            }
        );
    }

    private function isBuilderOnboardingTrigger(string $text): bool
    {
        $patterns = [
            'como creo mi programa',
            'como crear mi programa',
            'crear mi programa',
            'crear un programa',
            'crear programa',
            'crear una app',
            'crear app',
            'crear aplicacion',
            'hacer un programa',
            'hacer una app',
            'hacer una aplicacion',
            'quiero crear un programa',
            'quiero crear una app',
            'quiero crear app',
            'quiero hacer un programa',
            'quiero hacer una app',
            'quiero hacer app',
            'que debo hacer ahora',
            'paso sigue',
            'ruta de trabajo',
            'empresa de',
            'tengo una empresa',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function shouldResetBuilderFlow(string $text, array $state): bool
    {
        $activeTask = (string) ($state['active_task'] ?? '');
        if (!in_array($activeTask, ['create_table', 'create_form', 'crud'], true)) {
            return false;
        }
        if ($this->hasFieldPairs($text)) {
            return false;
        }
        if ($this->isBuilderOnboardingTrigger($text)) {
            return true;
        }
        $mentionsProgram = preg_match('/\b(programa|app|aplicacion|sistema)\b/u', $text) === 1;
        $mentionsBuild = preg_match('/\b(hacer|crear|control|negocio)\b/u', $text) === 1;
        return $mentionsProgram && $mentionsBuild;
    }

    private function shouldRestartBuilderOnboarding(string $text, array $state): bool
    {
        if (empty($state['builder_pending_command']) || !is_array($state['builder_pending_command'])) {
            return false;
        }
        if ($this->isAffirmativeReply($text) || $this->isNegativeReply($text)) {
            return false;
        }
        if ($this->isBuilderOnboardingTrigger($text)) {
            return true;
        }
        if (preg_match('/\b(quiero crear|quiero hacer|nuevo programa|nueva app)\b/u', $text) === 1) {
            return true;
        }
        return false;
    }

    private function isNextStepQuestion(string $text): bool
    {
        $patterns = [
            'que debo hacer',
            'paso sigue',
            'siguiente paso',
            'que hago ahora',
            'como sigo',
            'que mas sigue',
            'q mas sigue',
            'que mas falta',
            'q mas falta',
            'q mas debe tener',
            'que sigue ahora',
            'q sigue ahora',
            'ahora que otra',
            'ahora q otra',
            'que otra',
            'q otra',
            'que otra vas hacer',
            'q otra vas hacer',
            'que otra sigue',
            'q otra sigue',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isBuilderProgressQuestion(string $text): bool
    {
        $patterns = [
            'que has hecho',
            'q has hecho',
            'muestrame q has hecho',
            'muestrame que has hecho',
            'muestreme que has hecho',
            'que llevamos',
            'q llevamos',
            'que falta',
            'q falta',
            'que mas falta',
            'q mas falta',
            'que hemos hecho',
            'q hemos hecho',
            'resumen',
            'avance',
            'progreso',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isBuilderActionMessage(string $text): bool
    {
        if (str_contains($text, ':') || str_contains($text, '=')) {
            return true;
        }
        $patterns = [
            'crear tabla',
            'crea tabla',
            'crear entidad',
            'crea entidad',
            'crear formulario',
            'crea formulario',
            'crear form',
            'crea form',
            'guardar tabla',
            'crear ',
            'crea ',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function detectBusinessScopeChoice(string $text): string
    {
        if (preg_match('/\b(ambos|mixto|mixta|productos\\s+y\\s+servicios|servicios\\s+y\\s+productos)\b/u', $text) === 1) {
            return 'ambos';
        }
        if (preg_match('/\b(servicios|servicio)\b/u', $text) === 1) {
            return 'servicios';
        }
        if (preg_match('/\b(productos|producto)\b/u', $text) === 1) {
            return 'productos';
        }
        return '';
    }

    private function isQuestionLike(string $text): bool
    {
        if (str_contains($text, '?')) {
            return true;
        }
        $markers = ['que', 'como', 'cual', 'ayudame', 'explicame', 'debe', 'lleva', 'llevar'];
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function hasFieldPairs(string $text): bool
    {
        return str_contains($text, ':') || str_contains($text, '=');
    }

    private function isAffirmativeReply(string $text): bool
    {
        $text = trim(preg_replace('/[!?.,;]+/u', '', $text) ?? $text);
        if ($text === '') {
            return false;
        }
        return preg_match('/^(si|s\x{00ed}|ok|dale|confirmo|hagalo|hazlo|de una|claro|correcto|procede|empieza|inicia|hagale|h[ÃƒÆ’Ã‚Â¡a]gale|si hagale|si hagale amigo|si amigo|listo|perfecto|perfecto dale|hagale pues)\s*$/u', $text) === 1;
    }

    private function isNegativeReply(string $text): bool
    {
        $text = trim(preg_replace('/[!?.,;]+/u', '', $text) ?? $text);
        if ($text === '') {
            return false;
        }
        if ($this->isPendingPreviewQuestion($text) || $this->isClarificationRequest($text) || $this->isFieldHelpQuestion($text) || $this->isBuilderProgressQuestion($text)) {
            return false;
        }
        return preg_match('/^(no|todavia no|aun no|ahora no|mejor no|detente|cancelar|cambiar)\s*$/u', $text) === 1;
    }

    private function extractPersonName(string $text): string
    {
        if (preg_match('/\\bme\\s+llamo\\s+([a-zA-Z\\x{00C0}-\\x{017F}]+)/u', $text, $m)) {
            $name = trim((string) ($m[1] ?? ''));
            if ($name !== '') {
                return ucfirst($name);
            }
        }
        return '';
    }

    private function detectBusinessType(string $text): string
    {
        $text = $this->stripNegatedBusinessMentions($text);
        if (str_contains($text, 'taller automotriz') || (str_contains($text, 'automotriz') && str_contains($text, 'taller'))) {
            return 'taller_automotriz';
        }
        if (str_contains($text, 'sap') && (str_contains($text, 'lote') || str_contains($text, 'lotes'))) {
            return 'manufactura_pyme';
        }
        $playbook = $this->loadDomainPlaybook();
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        $best = '';
        $bestScore = 0;
        foreach ($profiles as $profile) {
            $key = strtolower((string) ($profile['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $score = 0;
            $label = strtolower((string) ($profile['label'] ?? ''));
            if ($label !== '' && str_contains($text, $label)) {
                $score += 6;
            }
            if (str_contains($text, $key)) {
                $score += 4;
            }
            $aliases = is_array($profile['aliases'] ?? null) ? $profile['aliases'] : [];
            foreach ($aliases as $alias) {
                $alias = strtolower((string) $alias);
                if ($alias !== '' && str_contains($text, $alias)) {
                    $aliasWeight = strlen($alias) >= 10 ? 5 : 3;
                    $score += max($aliasWeight, substr_count($text, $alias) * $aliasWeight);
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $key;
            }
        }
        if ($best !== '') {
            return $best;
        }

        $fallbackMap = [
            'spa' => 'spa_bienestar',
            'estetica' => 'spa_bienestar',
            'estÃƒÆ’Ã‚Â©tica' => 'spa_bienestar',
            'belleza' => 'spa_bienestar',
            'tratamientos' => 'spa_bienestar',
            'veterinaria' => 'veterinaria',
            'mascota' => 'veterinaria',
            'farmacia' => 'farmacia_naturista',
            'naturista' => 'farmacia_naturista',
            'ferreteria' => 'ferreteria',
            'corte laser' => 'corte_laser',
            'laser' => 'corte_laser',
            'restaurante' => 'restaurante_cafeteria',
            'cafeteria' => 'restaurante_cafeteria',
            'panaderia' => 'restaurante_cafeteria',
            'pasteleria' => 'restaurante_cafeteria',
            'ropa' => 'retail_tienda',
            'calzado' => 'retail_tienda',
            'consultoria' => 'consultoria_profesional',
            'asesoria' => 'consultoria_profesional',
            'iglesia' => 'iglesia_fundacion',
            'fundacion' => 'iglesia_fundacion',
            'crm' => 'crm_comercial',
            'lead' => 'crm_comercial',
            'oportunidad' => 'crm_comercial',
            'contabilidad' => 'contabilidad_general',
            'asiento' => 'contabilidad_general',
            'clinica' => 'clinica_medica',
            'hospital' => 'clinica_medica',
            'odontologia' => 'odontologia',
            'dental' => 'odontologia',
            'automotriz' => 'taller_automotriz',
            'mecanica' => 'taller_automotriz',
            'produccion' => 'manufactura_pyme',
            'lotes' => 'manufactura_pyme',
            'lote' => 'manufactura_pyme',
            'distribuidora' => 'distribuidora_mayorista',
            'mayorista' => 'distribuidora_mayorista',
            'ecommerce' => 'ecommerce_marketplace',
            'marketplace' => 'ecommerce_marketplace',
            'constructora' => 'constructora_obras',
            'obra' => 'constructora_obras',
            'colegio' => 'colegio_academia',
            'academia' => 'colegio_academia',
            'hotel' => 'hotel_turismo',
            'hostal' => 'hotel_turismo',
            'agro' => 'agropecuario',
            'bolso' => 'retail_tienda',
            'bolsos' => 'retail_tienda',
            'marroquineria' => 'retail_tienda',
            'modisteria' => 'retail_tienda',
            'confeccion' => 'retail_tienda',
            'servicio' => 'servicios_mantenimiento',
            'mantenimiento' => 'servicios_mantenimiento',
            'producto' => 'retail_tienda',
            'tienda' => 'retail_tienda',
        ];
        foreach ($fallbackMap as $term => $mapped) {
            if (str_contains($text, $term)) {
                return $mapped;
            }
        }
        return '';
    }

    private function detectUnknownBusinessCandidate(string $text, string $detectedBusinessType): string
    {
        $normalizedDetected = $this->normalizeBusinessType($detectedBusinessType);
        if ($normalizedDetected !== '' && !in_array($normalizedDetected, ['retail_tienda', 'servicios_mantenimiento'], true)) {
            return '';
        }

        $normalizedText = $this->normalize($text);
        if (
            preg_match(
                '/\b(?:no\s+soy|no\s+es)\s+(?:una|un)?\s*[a-z0-9_\-\s]{2,60}(?:,|\\.|;)?\s*(?:es|soy)\s+(?:una|un)?\s*([a-z0-9_\-\s]{2,60})/u',
                $normalizedText,
                $m
            ) === 1
        ) {
            $candidate = trim((string) ($m[1] ?? ''));
            $candidate = preg_split('/(?:,|\\.|;|\\bpero\\b|\\by\\b)/u', $candidate)[0] ?? $candidate;
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && !$this->isGenericBusinessCandidate($candidate)) {
                return $candidate;
            }
        }

        $patterns = [
            '/(?:mi\\s+)?(?:empresa|negocio|programa|app)\\s+(?:de|para)\\s+([a-z0-9_\\-\\s]{3,80})/iu',
            '/(?:tengo\\s+una\\s+empresa\\s+de|me\\s+dedico\\s+a|trabajo\\s+en)\\s+([a-z0-9_\\-\\s]{3,80})/iu',
            '/(?:fabrico|confecciono|vendo|produzco)\\s+([a-z0-9_\\-\\s]{3,80})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $candidate = trim((string) ($m[1] ?? ''));
                $candidate = preg_replace('/\\s+/', ' ', $candidate) ?? $candidate;
                $candidate = trim($candidate, " .,:;!?");
                $candidate = preg_replace('/\\b(que|quiero|necesito|hacer|crear|programa|app|sistema)\\b/iu', '', $candidate) ?? $candidate;
                $candidate = preg_replace('/\\s+/', ' ', trim($candidate)) ?? $candidate;
                if (
                    $candidate !== ''
                    && mb_strlen($candidate, 'UTF-8') >= 4
                    && !$this->isGenericBusinessCandidate($candidate)
                ) {
                    return $candidate;
                }
            }
        }

        $fallback = trim((string) (preg_replace('/\s+/', ' ', $text) ?? $text));
        $fallback = trim($fallback, " .,:;!?");
        $genericReplies = ['servicios', 'productos', 'ambos', 'mixto', 'contado', 'credito'];
        $isGenericOnboardingPhrase = preg_match('/\b(quiero|necesito|crear|hacer)\b/u', $fallback) === 1
            && preg_match('/\b(app|aplicacion|programa|sistema)\b/u', $fallback) === 1
            && preg_match('/\b(de|para)\b/u', $fallback) !== 1;
        if (
            $fallback !== ''
            && !in_array($fallback, $genericReplies, true)
            && !$this->isBuilderOnboardingTrigger($fallback)
            && !$isGenericOnboardingPhrase
            && mb_strlen($fallback, 'UTF-8') >= 8
            && preg_match('/[=:]/', $fallback) !== 1
            && preg_match('/^\p{L}+$/u', $fallback) !== 1
            && !$this->isGenericBusinessCandidate($fallback)
        ) {
            return $fallback;
        }
        return '';
    }

    private function isGenericBusinessCandidate(string $candidate): bool
    {
        $normalized = $this->normalize($candidate);
        if ($normalized === '') {
            return true;
        }

        $genericExact = [
            'inventario',
            'ventas',
            'facturacion',
            'contabilidad',
            'compras',
            'pagos',
            'reportes',
            'crm',
            'app',
            'programa',
            'sistema',
            'erp',
            'clientes',
            'productos',
        ];
        if (in_array($normalized, $genericExact, true)) {
            return true;
        }

        $tokens = preg_split('/\s+/u', $normalized) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), static fn($value): bool => $value !== ''));
        if (empty($tokens)) {
            return true;
        }

        $genericTokens = [
            'inventario',
            'ventas',
            'facturacion',
            'contabilidad',
            'compras',
            'pagos',
            'reportes',
            'crm',
            'erp',
            'cliente',
            'clientes',
            'producto',
            'productos',
            'kardex',
            'cartera',
            'caja',
        ];
        foreach ($tokens as $token) {
            if (mb_strlen($token, 'UTF-8') <= 2) {
                continue;
            }
            if (!in_array($token, $genericTokens, true)) {
                return false;
            }
        }

        return true;
    }

    private function detectOperationModel(string $text): string
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return '';
        }
        if (
            str_contains($normalized, 'mixto')
            || str_contains($normalized, 'misto')
            || str_contains($normalized, 'contado y credito')
            || str_contains($normalized, 'credito y contado')
            || str_contains($normalized, 'contado y a credito')
        ) {
            return 'mixto';
        }
        if (str_contains($normalized, 'credito') || str_contains($normalized, 'a credito') || str_contains($normalized, 'fiado')) {
            return 'credito';
        }
        if (str_contains($normalized, 'contado') || str_contains($normalized, 'efectivo') || str_contains($normalized, 'inmediato')) {
            return 'contado';
        }
        return '';
    }

    private function isOperationModelOverrideHint(string $text): bool
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return false;
        }
        if (preg_match('/\b(forma de pago|formas de pago|como cobro|como cobras|cobro|cobras|manejo pagos|medio de pago)\b/u', $normalized) !== 1) {
            return false;
        }
        return preg_match('/\b(contado|credito|mixto|efectivo|a credito|fiado)\b/u', $normalized) === 1;
    }

    private function normalizeOperationModel(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return 'mixto';
        }
        if (in_array($value, ['contado', 'credito', 'mixto'], true)) {
            return $value;
        }
        if (str_contains($value, 'credito')) {
            return 'credito';
        }
        if (str_contains($value, 'contado')) {
            return 'contado';
        }
        if (str_contains($value, 'mixto') || str_contains($value, 'ambos')) {
            return 'mixto';
        }
        return 'mixto';
    }

    private function normalizeBusinessType(string $businessType): string
    {
        $businessType = strtolower(trim($businessType));
        if ($businessType === '') {
            return '';
        }
        $playbook = $this->loadDomainPlaybook();
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        foreach ($profiles as $profile) {
            $key = strtolower((string) ($profile['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if ($businessType === $key || str_contains($businessType, $key)) {
                return $key;
            }
            $label = strtolower((string) ($profile['label'] ?? ''));
            if ($label !== '' && str_contains($businessType, $label)) {
                return $key;
            }
            $aliases = is_array($profile['aliases'] ?? null) ? $profile['aliases'] : [];
            foreach ($aliases as $alias) {
                $alias = strtolower((string) $alias);
                if ($alias !== '' && str_contains($businessType, $alias)) {
                    return $key;
                }
            }
        }
        if (str_contains($businessType, 'servicio')) {
            return 'servicios_mantenimiento';
        }
        if (str_contains($businessType, 'producto') || str_contains($businessType, 'tienda')) {
            return 'retail_tienda';
        }
        return $businessType;
    }

    private function domainLabelByBusinessType(string $businessType): string
    {
        $profile = $this->findDomainProfile($this->normalizeBusinessType($businessType));
        $label = trim((string) ($profile['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        $businessType = trim($businessType);
        if ($businessType === '') {
            return 'tu negocio';
        }
        return ucfirst(str_replace('_', ' ', $businessType));
    }

    private function resolveUnknownBusinessWithGemini(string $text, string $candidate, array $profile, array $state): array
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            
        

        return [];
        }

        $playbook = $this->loadDomainPlaybook();
        $unknownProtocol = is_array($playbook['unknown_business_protocol'] ?? null)
            ? (array) $playbook['unknown_business_protocol']
            : [];
        $enabled = (bool) ($unknownProtocol['enabled'] ?? true);
        if (!$enabled) {
            
        

        return [];
        }

        $hasGemini = trim((string) getenv('GEMINI_API_KEY')) !== '';
        if (!$hasGemini) {
            return ['status' => 'LLM_NOT_AVAILABLE'];
        }

        $discoveryState = is_array($state['unknown_business_discovery'] ?? null)
            ? (array) $state['unknown_business_discovery']
            : [];
        $discoveryAnswers = is_array($discoveryState['answers'] ?? null)
            ? array_values((array) $discoveryState['answers'])
            : [];
        $technicalPrompt = trim((string) ($discoveryState['technical_prompt'] ?? ''));
        $technicalBrief = trim((string) ($discoveryState['technical_brief'] ?? ''));
        $scopeFallback = $this->inferUnknownBusinessScopeFallback($text, $candidate, $profile, $state);

        $dedupeTtlSeconds = (int) ($unknownProtocol['llm_dedupe_ttl_seconds'] ?? 900);
        if ($dedupeTtlSeconds < 60 || $dedupeTtlSeconds > 86400) {
            $dedupeTtlSeconds = 900;
        }

        $confidenceThreshold = (float) ($unknownProtocol['llm_confidence_threshold'] ?? 0.85);
        if ($confidenceThreshold < 0.5 || $confidenceThreshold > 0.99) {
            $confidenceThreshold = 0.85;
        }

        $lastCandidate = trim((string) ($state['business_resolution_last_candidate'] ?? ''));
        $lastStatus = trim((string) ($state['business_resolution_last_status'] ?? ''));
        $lastAtRaw = trim((string) ($state['business_resolution_last_at'] ?? ''));
        $lastAt = $lastAtRaw !== '' ? strtotime($lastAtRaw) : false;
        $lastResult = is_array($state['business_resolution_last_result'] ?? null)
            ? (array) $state['business_resolution_last_result']
            : [];
        if (
            $lastCandidate !== ''
            && $this->normalize($lastCandidate) === $this->normalize($candidate)
            && $lastStatus !== ''
            && $lastAt !== false
            && (time() - $lastAt) < $dedupeTtlSeconds
        ) {
            if (!empty($lastResult)) {
                return $lastResult;
            }
            return ['status' => $lastStatus];
        }

        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        $knownProfiles = [];
        foreach ($profiles as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            if ($key !== '') {
                $knownProfiles[] = $key;
            }
        }
        $knownProfiles = array_values(array_unique($knownProfiles));

        $requiredOutputKeys = [
            'status',
            'confidence',
            'canonical_business_type',
            'business_candidate',
            'business_objective',
            'expected_result',
            'reason_short',
            'needs_normalized',
            'documents_normalized',
            'key_entities',
            'first_module',
            'operator_assistance_flow',
            'similar_user_signals',
            'training_dialog_flow',
            'training_gaps',
            'next_data_questions',
            'clarifying_question',
        ];
        $allowedNeedsVocabulary = $this->unknownBusinessAllowedNeedsVocabulary();
        $allowedDocumentsVocabulary = $this->unknownBusinessAllowedDocumentsVocabulary();
        $requiredBusinessInfo = $this->unknownBusinessRequiredInfoChecklist();

        $promptContract = [
            'ROLE' => 'Domain Classification Assistant',
            'CONTEXT' => [
                'known_profiles' => $knownProfiles,
                'language' => 'es-CO',
                'goal' => 'clasificar tipo de negocio y necesidades iniciales sin inventar',
                'has_discovery_answers' => !empty($discoveryAnswers),
                'required_business_information' => $requiredBusinessInfo,
                'fallback_needs' => $scopeFallback['needs'] ?? [],
                'fallback_documents' => $scopeFallback['documents'] ?? [],
                'allowed_needs_vocabulary' => $allowedNeedsVocabulary,
                'allowed_documents_vocabulary' => $allowedDocumentsVocabulary,
            ],
            'INPUT' => [
                'user_text' => $text,
                'business_candidate' => $candidate,
                'current_profile' => (string) ($profile['business_type'] ?? ''),
                'onboarding_step' => (string) ($state['onboarding_step'] ?? ''),
                'known_needs' => is_array($profile['needs_scope_items'] ?? null) ? array_values((array) $profile['needs_scope_items']) : [],
                'known_documents' => is_array($profile['documents_scope_items'] ?? null) ? array_values((array) $profile['documents_scope_items']) : [],
                'discovery_answers' => $discoveryAnswers,
                'answered_business_information' => $this->buildUnknownBusinessAnsweredInfo($discoveryAnswers),
                'technical_brief' => $technicalBrief,
                'compiled_research_prompt' => $technicalPrompt,
            ],
            'CONSTRAINTS' => [
                'no_invent_data' => true,
                'no_execute_actions' => true,
                'one_question_max_if_missing' => true,
                'prefer_known_profiles' => true,
                'use_discovery_answers_if_present' => true,
                'if_status_matched_require_scope_lists' => true,
                'if_status_matched_require_business_context' => true,
                'if_status_matched_require_richness_minimums' => [
                    'needs_min' => 5,
                    'documents_min' => 4,
                    'key_entities_min' => 6,
                ],
                'if_status_matched_require_assistance_pack' => true,
                'if_status_matched_require_operator_flow_min' => 5,
                'if_status_matched_require_similarity_signals_min' => 4,
                'if_status_matched_require_training_dialog_min' => 6,
                'if_status_needs_clarification_require_training_gaps' => true,
                'if_status_needs_clarification_require_next_data_questions_min' => 3,
                'if_missing_required_business_information_return_needs_clarification' => true,
                'if_status_needs_clarification_require_question' => true,
                'training_dialog_flow_item_format' => 'escenario | mensaje_usuario | respuesta_asistente | dato_critico',
                'next_data_questions_must_be_closed_options' => true,
                'prioritize_low_tech_user_language' => true,
                'forbid_unknown_scope_labels' => true,
                'forbid_legal_or_tax_advice_outside_input' => true,
                'avoid_confidence_1_0_without_hard_evidence' => true,
                'avoid_generic_entities_only' => true,
            ],
            'OUTPUT_FORMAT' => [
                'status' => ['type' => 'string', 'enum' => ['MATCHED', 'NEW_BUSINESS', 'NEEDS_CLARIFICATION', 'INVALID_REQUEST']],
                'confidence' => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 1.0],
                'canonical_business_type' => ['type' => 'string'],
                'business_candidate' => ['type' => 'string'],
                'business_objective' => ['type' => 'string'],
                'expected_result' => ['type' => 'string'],
                'reason_short' => ['type' => 'string'],
                'needs_normalized' => ['type' => 'array', 'items' => ['type' => 'string']],
                'documents_normalized' => ['type' => 'array', 'items' => ['type' => 'string']],
                'key_entities' => ['type' => 'array', 'items' => ['type' => 'string']],
                'first_module' => ['type' => 'string'],
                'operator_assistance_flow' => ['type' => 'array', 'items' => ['type' => 'string']],
                'similar_user_signals' => ['type' => 'array', 'items' => ['type' => 'string']],
                'training_dialog_flow' => ['type' => 'array', 'items' => ['type' => 'string']],
                'training_gaps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'next_data_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'clarifying_question' => ['type' => 'string'],
            ],
            'FAIL_RULES' => [
                'if_confidence_below' => $confidenceThreshold,
                'return_on_low_confidence' => 'NEEDS_CLARIFICATION',
                'if_required_key_missing' => 'INVALID_REQUEST',
                'if_required_business_info_missing' => 'NEEDS_CLARIFICATION',
                'required_output_keys' => $requiredOutputKeys,
            ],
        ];

        $capsule = [
            'intent' => 'BUSINESS_TYPE_DISCOVERY',
            'entity' => '',
            'entity_contract_min' => ['required' => [], 'types' => []],
            'state' => ['collected' => [], 'missing' => []],
            'user_message' => $text,
            'policy' => [
                'requires_strict_json' => true,
                'max_output_tokens' => 500,
                'latency_budget_ms' => 2500,
            ],
            'prompt_contract' => $promptContract,
        ];

        try {
            $router = new LLMRouter();
            $llm = $router->chat($capsule, ['mode' => 'gemini', 'temperature' => 0.1]);
            $json = is_array($llm['json'] ?? null) ? (array) $llm['json'] : [];
            if (empty($json)) {
                $emptyResult = [
                    'status' => 'INVALID_RESPONSE',
                    'confidence' => 0.0,
                    'canonical_business_type' => '',
                    'business_candidate' => $candidate,
                    'business_objective' => '',
                    'expected_result' => '',
                    'reason_short' => 'Respuesta vacia o no parseable del proveedor LLM.',
                    'needs_normalized' => $scopeFallback['needs'] ?? [],
                    'documents_normalized' => $scopeFallback['documents'] ?? [],
                    'key_entities' => [],
                    'first_module' => '',
                    'operator_assistance_flow' => [],
                    'similar_user_signals' => [],
                    'training_dialog_flow' => [],
                    'training_gaps' => [],
                    'next_data_questions' => [],
                    'clarifying_question' => 'Para ubicar bien tu negocio, dime en una frase que vendes o fabricas.',
                    'provider_used' => (string) ($llm['provider'] ?? 'gemini'),
                    'used_compiled_prompt' => $technicalPrompt !== '',
                ];
                $quality = $this->evaluateUnknownBusinessLlmQuality($emptyResult, $confidenceThreshold);
                $emptyResult['quality_score'] = $quality['score'];
                $emptyResult['quality_ok'] = $quality['ok'];
                $emptyResult['quality_issues'] = $quality['issues'];
                $this->persistUnknownBusinessLlmSample(
                    $this->contextTenantId,
                    $this->contextUserId,
                    $candidate,
                    $text,
                    $emptyResult,
                    $quality
                );
                return $emptyResult;
            }

            $resolved = $this->normalizeUnknownBusinessLlmResolution(
                $json,
                $candidate,
                $text,
                $profile,
                $state,
                $confidenceThreshold,
                $scopeFallback
            );
            $resolved['provider_used'] = (string) ($llm['provider'] ?? 'gemini');
            $resolved['used_compiled_prompt'] = $technicalPrompt !== '';

            $quality = $this->evaluateUnknownBusinessLlmQuality($resolved, $confidenceThreshold);
            $resolved['quality_score'] = $quality['score'];
            $resolved['quality_ok'] = $quality['ok'];
            $resolved['quality_issues'] = $quality['issues'];
            $this->persistUnknownBusinessLlmSample(
                $this->contextTenantId,
                $this->contextUserId,
                $candidate,
                $text,
                $resolved,
                $quality
            );

            return $resolved;
        } catch (\Throwable $e) {
            $errorResult = [
                'status' => 'ERROR',
                'error' => $e->getMessage(),
                'confidence' => 0.0,
                'canonical_business_type' => '',
                'business_candidate' => $candidate,
                'business_objective' => '',
                'expected_result' => '',
                'reason_short' => 'Fallo de llamada LLM.',
                'needs_normalized' => $scopeFallback['needs'] ?? [],
                'documents_normalized' => $scopeFallback['documents'] ?? [],
                'key_entities' => [],
                'first_module' => '',
                'operator_assistance_flow' => [],
                'similar_user_signals' => [],
                'training_dialog_flow' => [],
                'training_gaps' => [],
                'next_data_questions' => [],
                'clarifying_question' => 'Para ubicar bien tu negocio, dime en una frase que vendes o fabricas.',
            ];
            $quality = $this->evaluateUnknownBusinessLlmQuality($errorResult, $confidenceThreshold);
            $errorResult['quality_score'] = $quality['score'];
            $errorResult['quality_ok'] = $quality['ok'];
            $errorResult['quality_issues'] = $quality['issues'];
            $this->persistUnknownBusinessLlmSample(
                $this->contextTenantId,
                $this->contextUserId,
                $candidate,
                $text,
                $errorResult,
                $quality
            );
            return $errorResult;
        }
    }

    private function normalizeUnknownBusinessLlmResolution(
        array $json,
        string $candidate,
        string $text,
        array $profile,
        array $state,
        float $confidenceThreshold,
        array $scopeFallback = []
    ): array {
        $status = $this->normalizeUnknownBusinessStatus((string) ($json['status'] ?? ''));
        $confidence = $this->clampConfidence($json['confidence'] ?? 0.0);
        $canonicalBusinessType = $this->normalizeBusinessType((string) ($json['canonical_business_type'] ?? ''));
        $businessCandidate = $this->sanitizeRequirementText((string) ($json['business_candidate'] ?? ''));
        if ($businessCandidate === '') {
            $businessCandidate = $this->sanitizeRequirementText($candidate);
        }
        if ($businessCandidate === '') {
            $businessCandidate = $candidate;
        }

        $needs = $this->canonicalizeUnknownBusinessNeeds(
            $this->normalizeUnknownBusinessList($json['needs_normalized'] ?? [])
        );
        $documents = $this->canonicalizeUnknownBusinessDocuments(
            $this->normalizeUnknownBusinessList($json['documents_normalized'] ?? [])
        );
        $businessObjective = $this->sanitizeRequirementText((string) ($json['business_objective'] ?? ''));
        $expectedResult = $this->sanitizeRequirementText((string) ($json['expected_result'] ?? ''));
        $keyEntities = $this->normalizeUnknownBusinessEntityList($json['key_entities'] ?? []);
        $firstModule = $this->sanitizeRequirementText((string) ($json['first_module'] ?? ''));
        $operatorAssistanceFlow = $this->normalizeUnknownBusinessList($json['operator_assistance_flow'] ?? []);
        $similarUserSignals = $this->normalizeUnknownBusinessList($json['similar_user_signals'] ?? []);
        $trainingDialogFlow = $this->normalizeUnknownBusinessList($json['training_dialog_flow'] ?? []);
        $trainingGaps = $this->normalizeUnknownBusinessList($json['training_gaps'] ?? []);
        $nextDataQuestions = $this->normalizeUnknownBusinessList($json['next_data_questions'] ?? []);
        $fallbackNeeds = is_array($scopeFallback['needs'] ?? null)
            ? array_values(array_filter(array_map('strval', (array) $scopeFallback['needs'])))
            : [];
        $fallbackDocuments = is_array($scopeFallback['documents'] ?? null)
            ? array_values(array_filter(array_map('strval', (array) $scopeFallback['documents'])))
            : [];
        if ($needs === [] && $fallbackNeeds !== []) {
            $needs = $this->canonicalizeUnknownBusinessNeeds($this->mergeScopeLabels([], $fallbackNeeds));
        }
        if ($documents === [] && $fallbackDocuments !== []) {
            $documents = $this->canonicalizeUnknownBusinessDocuments($this->mergeScopeLabels([], $fallbackDocuments));
        }
        $operatorAssistanceFlow = $this->mergeScopeLabels(
            $operatorAssistanceFlow,
            $this->buildUnknownBusinessOperatorAssistanceFallback($businessCandidate, $canonicalBusinessType)
        );
        $similarUserSignals = $this->mergeScopeLabels(
            $similarUserSignals,
            $this->buildUnknownBusinessSimilaritySignalsFallback($businessCandidate, $canonicalBusinessType)
        );
        $trainingDialogFlow = $this->mergeScopeLabels(
            $trainingDialogFlow,
            $this->buildUnknownBusinessTrainingDialogFallback($businessCandidate, $canonicalBusinessType)
        );
        $trainingGaps = $this->mergeScopeLabels(
            $trainingGaps,
            $this->buildUnknownBusinessTrainingGapsFallback($businessCandidate, $canonicalBusinessType)
        );
        $nextDataQuestions = $this->mergeScopeLabels(
            $nextDataQuestions,
            $this->buildUnknownBusinessNextDataQuestionsFallback($businessCandidate, $canonicalBusinessType)
        );

        $reason = trim((string) ($json['reason_short'] ?? ''));
        $clarifyingQuestion = trim((string) ($json['clarifying_question'] ?? ''));

        $knownProfile = $canonicalBusinessType !== '' ? $this->findDomainProfile($canonicalBusinessType) : [];
        if ($status === 'MATCHED' && $canonicalBusinessType === '') {
            $status = 'NEEDS_CLARIFICATION';
            if ($reason === '') {
                $reason = 'No se pudo confirmar un perfil canonico de negocio.';
            }
        }
        if ($status === 'MATCHED' && $canonicalBusinessType !== '' && empty($knownProfile)) {
            $status = 'NEEDS_CLARIFICATION';
            if ($reason === '') {
                $reason = 'El perfil canonico sugerido no existe en la base actual.';
            }
        }
        if ($status === 'MATCHED' && $confidence < $confidenceThreshold) {
            $status = 'NEEDS_CLARIFICATION';
            if ($reason === '') {
                $reason = 'Confianza insuficiente para confirmar el tipo de negocio.';
            }
        }
        if ($status === 'MATCHED' && ($needs === [] || $documents === [])) {
            $fallback = $this->inferUnknownBusinessScopeFallback($text, $candidate, $profile, $state);
            if ($needs === []) {
                $needs = is_array($fallback['needs'] ?? null) ? (array) $fallback['needs'] : [];
            }
            if ($documents === []) {
                $documents = is_array($fallback['documents'] ?? null) ? (array) $fallback['documents'] : [];
            }
            if ($needs === [] || $documents === []) {
                $status = 'NEEDS_CLARIFICATION';
                if ($reason === '') {
                    $reason = 'La respuesta no trajo alcance minimo para entrenar y confirmar.';
                }
            }
        }
        if (
            $status === 'MATCHED'
            && ($businessObjective === '' || $expectedResult === '' || $firstModule === '' || count($keyEntities) < 2)
        ) {
            $status = 'NEEDS_CLARIFICATION';
            if ($reason === '') {
                $reason = 'La respuesta no trajo contexto tecnico minimo para entrenar en produccion.';
            }
        }
        if (
            $status === 'MATCHED'
            && (count($operatorAssistanceFlow) < 5 || count($trainingDialogFlow) < 6 || count($similarUserSignals) < 4)
        ) {
            $status = 'NEEDS_CLARIFICATION';
            if ($reason === '') {
                $reason = 'Falta flujo conversacional de uso para entrenar asistencia en app creada.';
            }
        }

        if ($status === 'NEW_BUSINESS') {
            if ($confidence < 0.65) {
                $status = 'NEEDS_CLARIFICATION';
                if ($reason === '') {
                    $reason = 'Confianza baja para crear playbook nuevo.';
                }
            }
            if ($needs === []) {
                $needs = $fallbackNeeds !== [] ? $fallbackNeeds : ['inventario', 'ventas', 'pagos'];
            }
            if ($documents === []) {
                $documents = $fallbackDocuments !== [] ? $fallbackDocuments : ['factura', 'orden de trabajo', 'cotizacion'];
            }
            if ($businessObjective === '' || $expectedResult === '' || $firstModule === '' || count($keyEntities) < 2) {
                $status = 'NEEDS_CLARIFICATION';
                if ($reason === '') {
                    $reason = 'Falta contexto tecnico para construir playbook temporal confiable.';
                }
            }
        }

        if ($status === 'NEEDS_CLARIFICATION') {
            if (count($trainingGaps) < 4) {
                $trainingGaps = $this->mergeScopeLabels(
                    $trainingGaps,
                    [
                        'definir_catalogo_servicios_con_precios',
                        'definir_regla_de_comisiones_por_personal',
                        'definir_pasos_operativos_del_dia_para_usuario_no_tecnico',
                        'definir_flujo_de_cierre_de_caja',
                    ]
                );
            }
            if (count($nextDataQuestions) < 3) {
                $nextDataQuestions = $this->mergeScopeLabels(
                    $nextDataQuestions,
                    [
                        'Como pagas al personal? A) Porcentaje por servicio B) Sueldo fijo C) Alquiler de puesto.',
                        'Que quieres resolver primero? A) Agenda de citas B) Cobro y caja C) Inventario de insumos.',
                        'Como cobras normalmente? A) Efectivo B) Transferencia/Nequi C) Mixto.',
                    ]
                );
            }
        }

        if ($status === 'NEEDS_CLARIFICATION' && $clarifyingQuestion === '') {
            if ($businessObjective === '') {
                $clarifyingQuestion = 'En una frase, cual es el objetivo principal de tu app para este negocio?';
            } elseif ($firstModule === '') {
                $clarifyingQuestion = 'Cual es el primer modulo que quieres tener listo para operar hoy?';
            } else {
                $clarifyingQuestion = 'Para ubicar bien tu negocio, dime en una frase que vendes o fabricas.';
            }
        }

        $needs = array_values(array_slice($this->canonicalizeUnknownBusinessNeeds($this->mergeScopeLabels([], $needs)), 0, 8));
        $documents = array_values(array_slice($this->canonicalizeUnknownBusinessDocuments($this->mergeScopeLabels([], $documents)), 0, 8));
        $keyEntities = array_values(array_slice($this->normalizeUnknownBusinessEntityList($keyEntities), 0, 8));
        $operatorAssistanceFlow = array_values(array_slice($this->mergeScopeLabels([], $operatorAssistanceFlow), 0, 8));
        $similarUserSignals = array_values(array_slice($this->mergeScopeLabels([], $similarUserSignals), 0, 8));
        $trainingDialogFlow = array_values(array_slice($this->mergeScopeLabels([], $trainingDialogFlow), 0, 10));
        $trainingGaps = array_values(array_slice($this->mergeScopeLabels([], $trainingGaps), 0, 10));
        $nextDataQuestions = array_values(array_slice($this->mergeScopeLabels([], $nextDataQuestions), 0, 4));

        return [
            'status' => $status,
            'confidence' => $confidence,
            'canonical_business_type' => $canonicalBusinessType,
            'business_candidate' => $businessCandidate,
            'business_objective' => $businessObjective,
            'expected_result' => $expectedResult,
            'reason_short' => $reason,
            'needs_normalized' => $needs,
            'documents_normalized' => $documents,
            'key_entities' => $keyEntities,
            'first_module' => $firstModule,
            'operator_assistance_flow' => $operatorAssistanceFlow,
            'similar_user_signals' => $similarUserSignals,
            'training_dialog_flow' => $trainingDialogFlow,
            'training_gaps' => $trainingGaps,
            'next_data_questions' => $nextDataQuestions,
            'clarifying_question' => $clarifyingQuestion,
        ];
    }

    private function normalizeUnknownBusinessStatus(string $status): string
    {
        $status = strtoupper(trim($status));
        $map = [
            'SUCCESS' => 'MATCHED',
            'OK' => 'MATCHED',
            'RESOLVED' => 'MATCHED',
            'MATCH' => 'MATCHED',
            'NEW' => 'NEW_BUSINESS',
            'UNKNOWN' => 'NEEDS_CLARIFICATION',
            'CLARIFY' => 'NEEDS_CLARIFICATION',
        ];
        if (isset($map[$status])) {
            $status = $map[$status];
        }
        $allowed = ['MATCHED', 'NEW_BUSINESS', 'NEEDS_CLARIFICATION', 'INVALID_REQUEST'];
        if (!in_array($status, $allowed, true)) {
            return 'INVALID_RESPONSE';
        }
        return $status;
    }

    private function clampConfidence($value): float
    {
        $confidence = is_numeric($value) ? (float) $value : 0.0;
        if ($confidence > 1.0 && $confidence <= 100.0) {
            $confidence = $confidence / 100.0;
        }
        if ($confidence < 0.0) {
            return 0.0;
        }
        if ($confidence > 1.0) {
            return 1.0;
        }
        return $confidence;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeUnknownBusinessList($value): array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        $items = [];
        if (is_array($value)) {
            foreach ($value as $entry) {
                $items = array_merge($items, $this->normalizeUnknownBusinessList($entry));
            }
        } elseif (is_string($value)) {
            $raw = trim($value);
            if ($raw !== '') {
                if ((str_starts_with($raw, '[') || str_starts_with($raw, '{'))) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        return $this->normalizeUnknownBusinessList($decoded);
                    }
                }
                $parts = preg_split('/[,;\n\|]+/u', $raw) ?: [];
                foreach ($parts as $part) {
                    $clean = trim((string) $part);
                    if ($clean === '') {
                        continue;
                    }
                    $clean = preg_replace('/^[\-\*\ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢\d\.\)\(]+\s*/u', '', $clean) ?? $clean;
                    $clean = $this->normalize($clean);
                    $clean = trim($clean, " \t\n\r\0\x0B.,;:!?");
                    if ($clean === '' || preg_match('/^<.*>$/', $clean) === 1) {
                        continue;
                    }
                    $items[] = $clean;
                }
            }
        }

        $unique = [];
        foreach ($items as $item) {
            $key = $this->normalize($item);
            if ($key === '') {
                continue;
            }
            $unique[$key] = $item;
        }
        return array_values(array_slice($unique, 0, 8));
    }

    /**
     * @return array<int, string>
     */
    private function unknownBusinessAllowedNeedsVocabulary(): array
    {
        return [
            'ventas',
            'facturacion',
            'inventario',
            'pagos',
            'productos',
            'servicios/tratamientos',
            'citas',
            'historia clinica',
            'pacientes',
            'medicamentos',
            'muestras/examenes',
            'ordenes de trabajo',
            'gastos/costos',
            'cartera',
            'compras',
            'produccion',
            'contabilidad',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function unknownBusinessAllowedDocumentsVocabulary(): array
    {
        return [
            'factura',
            'ticket',
            'recibo de pago',
            'recibo de caja',
            'comprobante de egreso',
            'orden de trabajo',
            'cotizacion',
            'orden de compra',
            'historia clinica',
            'receta',
            'remision',
            'inventario',
            'comprobante contable',
            'nota credito',
            'nota debito',
        ];
    }

    /**
     * @param array<int, string> $items
     * @return array<int, string>
     */
    private function canonicalizeUnknownBusinessNeeds(array $items): array
    {
        $allowed = $this->unknownBusinessAllowedNeedsVocabulary();
        $aliasMap = [
            'inventario' => ['inventario', 'stock', 'existencia', 'control inventario', 'control inventarios'],
            'ventas' => ['venta', 'ventas', 'ventas pos', 'punto de venta', 'pos'],
            'facturacion' => ['facturacion', 'factura', 'factura electronica', 'facturacion electronica', 'fiscal'],
            'pagos' => ['pago', 'pagos', 'recaudo', 'cobro', 'abono'],
            'productos' => ['producto', 'productos', 'catalogo', 'sku', 'item', 'items', 'insumo', 'insumos'],
            'servicios/tratamientos' => ['servicio', 'servicios', 'tratamiento', 'tratamientos', 'procedimiento', 'procedimientos'],
            'citas' => ['cita', 'citas', 'agenda', 'agendar', 'reserva', 'reservas'],
            'historia clinica' => ['historia clinica', 'historia medica', 'expediente', 'evolucion clinica'],
            'pacientes' => ['paciente', 'pacientes', 'cliente', 'clientes', 'mascota', 'mascotas'],
            'medicamentos' => ['medicamento', 'medicamentos', 'farmacia'],
            'muestras/examenes' => ['laboratorio', 'examen', 'examenes', 'muestra', 'muestras', 'analitica'],
            'ordenes de trabajo' => ['orden de trabajo', 'ordenes de trabajo', 'ot', 'mantenimiento'],
            'gastos/costos' => ['gasto', 'gastos', 'costo', 'costos', 'egreso', 'egresos'],
            'cartera' => ['cartera', 'cuentas por cobrar', 'cx c', 'cxc'],
            'compras' => ['compra', 'compras', 'proveedor', 'proveedores'],
            'produccion' => ['produccion', 'fabricacion', 'manufactura'],
            'contabilidad' => ['contabilidad', 'asiento', 'asientos', 'contable'],
        ];

        $resolved = [];
        foreach ($items as $item) {
            $token = $this->normalize((string) $item);
            $token = str_replace(['_', '-', '/'], ' ', $token);
            $token = preg_replace('/\s+/', ' ', trim($token)) ?? trim($token);
            if ($token === '') {
                continue;
            }

            $canonical = $this->matchUnknownBusinessCanonicalToken($token, $aliasMap);
            if ($canonical === '') {
                $derived = $this->extractNeedItems($token, '');
                foreach ($derived as $label) {
                    $label = trim((string) $label);
                    if ($label === '' || !in_array($label, $allowed, true)) {
                        continue;
                    }
                    $resolved[$this->normalize($label)] = $label;
                }
            } elseif (in_array($canonical, $allowed, true)) {
                $resolved[$this->normalize($canonical)] = $canonical;
            }

            if ($canonical === '' && in_array($token, $allowed, true)) {
                $resolved[$this->normalize($token)] = $token;
            }
        }

        return array_values(array_slice($resolved, 0, 8));
    }

    /**
     * @param array<int, string> $items
     * @return array<int, string>
     */
    private function canonicalizeUnknownBusinessDocuments(array $items): array
    {
        $allowed = $this->unknownBusinessAllowedDocumentsVocabulary();
        $aliasMap = [
            'factura' => ['factura', 'facturacion', 'factura electronica', 'factura fiscal'],
            'ticket' => ['ticket', 'pos', 'tirilla'],
            'recibo de pago' => ['recibo de pago', 'comprobante de pago', 'soporte de pago', 'pago'],
            'recibo de caja' => ['recibo de caja', 'recibo caja', 'rc'],
            'comprobante de egreso' => ['comprobante de egreso', 'egreso', 'salida de caja'],
            'orden de trabajo' => ['orden de trabajo', 'ot', 'orden servicio', 'orden de servicio'],
            'cotizacion' => ['cotizacion', 'presupuesto', 'proforma', 'propuesta'],
            'orden de compra' => ['orden de compra', 'oc'],
            'historia clinica' => ['historia clinica', 'historia medica', 'expediente'],
            'receta' => ['receta', 'formula medica'],
            'remision' => ['remision', 'entrega'],
            'inventario' => ['inventario', 'kardex'],
            'comprobante contable' => ['comprobante contable', 'asiento', 'comprobante diario', 'contable'],
            'nota credito' => ['nota credito', 'nota de credito'],
            'nota debito' => ['nota debito', 'nota de debito'],
        ];

        $resolved = [];
        foreach ($items as $item) {
            $token = $this->normalize((string) $item);
            $token = str_replace(['_', '-', '/'], ' ', $token);
            $token = preg_replace('/\s+/', ' ', trim($token)) ?? trim($token);
            if ($token === '') {
                continue;
            }

            $canonical = $this->matchUnknownBusinessCanonicalToken($token, $aliasMap);
            if ($canonical === '') {
                $derived = $this->extractDocumentItems($token);
                foreach ($derived as $label) {
                    $label = trim((string) $label);
                    if ($label === '' || !in_array($label, $allowed, true)) {
                        continue;
                    }
                    $resolved[$this->normalize($label)] = $label;
                }
            } elseif (in_array($canonical, $allowed, true)) {
                $resolved[$this->normalize($canonical)] = $canonical;
            }

            if ($canonical === '' && in_array($token, $allowed, true)) {
                $resolved[$this->normalize($token)] = $token;
            }
        }

        return array_values(array_slice($resolved, 0, 8));
    }

    /**
     * @param array<string, array<int, string>> $aliasMap
     */
    private function matchUnknownBusinessCanonicalToken(string $token, array $aliasMap): string
    {
        foreach ($aliasMap as $canonical => $aliases) {
            $canonicalKey = $this->normalize((string) $canonical);
            if ($canonicalKey !== '' && ($token === $canonicalKey || str_contains($token, $canonicalKey))) {
                return (string) $canonical;
            }
            foreach ($aliases as $alias) {
                $aliasKey = $this->normalize((string) $alias);
                if ($aliasKey === '') {
                    continue;
                }
                if ($token === $aliasKey || str_contains($token, $aliasKey)) {
                    return (string) $canonical;
                }
            }
        }
        return '';
    }

    /**
     * @return array<int, string>
     */
    private function normalizeUnknownBusinessEntityList($value): array
    {
        $items = $this->normalizeUnknownBusinessList($value);
        $aliasMap = [
            'cliente' => ['cliente', 'clientes', 'paciente', 'pacientes', 'dueno', 'duenos', 'propietario'],
            'producto' => ['producto', 'productos', 'item', 'items'],
            'insumo' => ['insumo', 'insumos', 'materia prima', 'materias primas'],
            'servicio' => ['servicio', 'servicios', 'tratamiento', 'tratamientos', 'procedimiento'],
            'factura' => ['factura', 'facturas'],
            'pago' => ['pago', 'pagos', 'recaudo', 'cobro', 'abono'],
            'transaccion_venta' => ['venta', 'ventas', 'transaccion venta', 'ticket'],
            'cita' => ['cita', 'citas', 'reserva', 'reservas'],
            'inventario' => ['inventario', 'stock', 'kardex'],
            'orden' => ['orden', 'ordenes', 'orden trabajo', 'orden servicio'],
            'comprobante' => ['comprobante', 'recibo', 'soporte'],
            'cierre_caja' => ['cierre de caja', 'arqueo', 'cuadre de caja', 'caja diaria'],
            'cuenta_por_cobrar' => ['cartera', 'cuenta por cobrar', 'cuentas por cobrar', 'cxc'],
        ];

        $resolved = [];
        foreach ($items as $item) {
            $token = $this->normalize((string) $item);
            $token = str_replace(['-', '/'], ' ', $token);
            $token = preg_replace('/\s+/', ' ', trim($token)) ?? trim($token);
            if ($token === '') {
                continue;
            }
            $canonical = $this->matchUnknownBusinessCanonicalToken($token, $aliasMap);
            if ($canonical !== '') {
                $resolved[$canonical] = $canonical;
                continue;
            }

            $token = str_replace(' ', '_', $token);
            $token = preg_replace('/[^a-z0-9_]/', '', $token) ?? '';
            $token = trim($token, '_');
            if ($token === '' || strlen($token) < 3) {
                continue;
            }
            $resolved[$token] = $token;
        }

        return array_values(array_slice($resolved, 0, 8));
    }

    /**
     * @param array<int, string> $items
     * @param array<int, string> $allowed
     * @return array<int, string>
     */
    private function unknownBusinessScopeOutsideVocabulary(array $items, array $allowed): array
    {
        $allowedIndex = [];
        foreach ($allowed as $item) {
            $key = $this->normalize((string) $item);
            if ($key !== '') {
                $allowedIndex[$key] = true;
            }
        }

        $outside = [];
        foreach ($items as $item) {
            $key = $this->normalize((string) $item);
            if ($key === '' || isset($allowedIndex[$key])) {
                continue;
            }
            $outside[$key] = $key;
        }
        return array_values($outside);
    }

    /**
     * @return array<int, string>
     */
    private function unknownBusinessGenericEntityVocabulary(): array
    {
        return [
            'cliente',
            'producto',
            'servicio',
            'venta',
            'transaccion_venta',
            'pago',
            'caja',
            'cierre_caja',
            'factura',
            'inventario',
            'orden',
            'comprobante',
        ];
    }

    private function unknownBusinessSpecificEntityCount(array $entities): int
    {
        $generic = [];
        foreach ($this->unknownBusinessGenericEntityVocabulary() as $token) {
            $key = $this->normalize((string) $token);
            if ($key !== '') {
                $generic[$key] = true;
            }
        }

        $count = 0;
        foreach ($entities as $entity) {
            $key = $this->normalize((string) $entity);
            if ($key === '' || isset($generic[$key])) {
                continue;
            }
            $count++;
        }
        return $count;
    }

    private function looksWeakUnknownBusinessText(string $text): bool
    {
        $value = trim($text);
        if ($value === '') {
            return true;
        }
        if (mb_strlen($value, 'UTF-8') < 28) {
            return true;
        }
        $normalized = $this->normalize($value);
        $weakPhrases = [
            'perfil completamente definido',
            'coincide por catalogo',
            'coincide por perfil',
            'requerimientos estandar',
            'sin novedad',
            'todo correcto',
        ];
        foreach ($weakPhrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }
        return false;
    }

    private function inferUnknownBusinessScopeFallback(string $text, string $candidate, array $profile, array $state): array
    {
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        $needs = [];
        $documents = [];

        $profileNeeds = is_array($profile['needs_scope_items'] ?? null)
            ? array_values((array) $profile['needs_scope_items'])
            : array_values(array_filter(array_map('trim', explode(',', (string) ($profile['needs_scope'] ?? '')))));
        $profileDocuments = is_array($profile['documents_scope_items'] ?? null)
            ? array_values((array) $profile['documents_scope_items'])
            : array_values(array_filter(array_map('trim', explode(',', (string) ($profile['documents_scope'] ?? '')))));
        if ($profileNeeds !== []) {
            $needs = $this->mergeScopeLabels($needs, array_map('strval', $profileNeeds));
        }
        if ($profileDocuments !== []) {
            $documents = $this->mergeScopeLabels($documents, array_map('strval', $profileDocuments));
        }

        $sources = [];
        $normalizedText = $this->normalize($text);
        if ($normalizedText !== '') {
            $sources[] = $normalizedText;
        }
        $normalizedCandidate = $this->normalize($candidate);
        if ($normalizedCandidate !== '') {
            $sources[] = $normalizedCandidate;
        }
        $flow = is_array($state['unknown_business_discovery'] ?? null)
            ? (array) $state['unknown_business_discovery']
            : [];
        $answers = is_array($flow['answers'] ?? null) ? (array) $flow['answers'] : [];
        foreach ($answers as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $answer = $this->normalize((string) ($pair['answer'] ?? ''));
            if ($answer !== '') {
                $sources[] = $answer;
            }
        }

        foreach ($sources as $source) {
            $needs = $this->mergeScopeLabels($needs, $this->extractNeedItems($source, $businessType));
            $documents = $this->mergeScopeLabels($documents, $this->extractDocumentItems($source));
        }

        $draft = $this->buildUnknownBusinessLocalDraft($state, $candidate);
        $draftNeeds = is_array($draft['needs'] ?? null) ? array_values((array) $draft['needs']) : [];
        $draftDocuments = is_array($draft['documents'] ?? null) ? array_values((array) $draft['documents']) : [];
        if ($draftNeeds !== []) {
            $needs = $this->mergeScopeLabels($needs, array_map('strval', $draftNeeds));
        }
        if ($draftDocuments !== []) {
            $documents = $this->mergeScopeLabels($documents, array_map('strval', $draftDocuments));
        }

        return [
            'needs' => array_values(array_slice($needs, 0, 6)),
            'documents' => array_values(array_slice($documents, 0, 6)),
        ];
    }

    private function evaluateUnknownBusinessLlmQuality(array $resolved, float $confidenceThreshold): array
    {
        $status = strtoupper(trim((string) ($resolved['status'] ?? '')));
        $confidence = $this->clampConfidence($resolved['confidence'] ?? 0.0);
        $needs = is_array($resolved['needs_normalized'] ?? null) ? array_values((array) $resolved['needs_normalized']) : [];
        $documents = is_array($resolved['documents_normalized'] ?? null) ? array_values((array) $resolved['documents_normalized']) : [];
        $businessObjective = trim((string) ($resolved['business_objective'] ?? ''));
        $expectedResult = trim((string) ($resolved['expected_result'] ?? ''));
        $keyEntities = is_array($resolved['key_entities'] ?? null) ? array_values((array) $resolved['key_entities']) : [];
        $specificEntityCount = $this->unknownBusinessSpecificEntityCount($keyEntities);
        $firstModule = trim((string) ($resolved['first_module'] ?? ''));
        $operatorAssistanceFlow = is_array($resolved['operator_assistance_flow'] ?? null)
            ? array_values((array) $resolved['operator_assistance_flow'])
            : [];
        $similarUserSignals = is_array($resolved['similar_user_signals'] ?? null)
            ? array_values((array) $resolved['similar_user_signals'])
            : [];
        $trainingDialogFlow = is_array($resolved['training_dialog_flow'] ?? null)
            ? array_values((array) $resolved['training_dialog_flow'])
            : [];
        $trainingGaps = is_array($resolved['training_gaps'] ?? null)
            ? array_values((array) $resolved['training_gaps'])
            : [];
        $nextDataQuestions = is_array($resolved['next_data_questions'] ?? null)
            ? array_values((array) $resolved['next_data_questions'])
            : [];
        $canonical = $this->normalizeBusinessType((string) ($resolved['canonical_business_type'] ?? ''));
        $question = trim((string) ($resolved['clarifying_question'] ?? ''));
        $reason = trim((string) ($resolved['reason_short'] ?? ''));
        $needsOutsideVocabulary = $this->unknownBusinessScopeOutsideVocabulary(
            $needs,
            $this->unknownBusinessAllowedNeedsVocabulary()
        );
        $documentsOutsideVocabulary = $this->unknownBusinessScopeOutsideVocabulary(
            $documents,
            $this->unknownBusinessAllowedDocumentsVocabulary()
        );

        $score = 1.0;
        $issues = [];
        $allowed = ['MATCHED', 'NEW_BUSINESS', 'NEEDS_CLARIFICATION', 'INVALID_REQUEST'];
        if (!in_array($status, $allowed, true)) {
            $score -= 0.45;
            $issues[] = 'status_invalido';
        }

        if ($status === 'MATCHED') {
            if ($canonical === '') {
                $score -= 0.3;
                $issues[] = 'matched_sin_canonical';
            }
            if ($confidence < $confidenceThreshold) {
                $score -= 0.25;
                $issues[] = 'matched_confianza_baja';
            }
            if ($needs === []) {
                $score -= 0.15;
                $issues[] = 'matched_sin_needs';
            }
            if ($documents === []) {
                $score -= 0.15;
                $issues[] = 'matched_sin_documents';
            }
            if ($businessObjective === '') {
                $score -= 0.12;
                $issues[] = 'matched_sin_business_objective';
            }
            if ($expectedResult === '') {
                $score -= 0.12;
                $issues[] = 'matched_sin_expected_result';
            }
            if (count($keyEntities) < 2) {
                $score -= 0.1;
                $issues[] = 'matched_sin_key_entities';
            }
            if ($firstModule === '') {
                $score -= 0.08;
                $issues[] = 'matched_sin_first_module';
            }
            if (count($needs) < 5) {
                $score -= 0.12;
                $issues[] = 'matched_needs_pobres';
            }
            if (count($documents) < 4) {
                $score -= 0.1;
                $issues[] = 'matched_documents_pobres';
            }
            if (count($keyEntities) < 6) {
                $score -= 0.14;
                $issues[] = 'matched_key_entities_pobres';
            }
            if ($specificEntityCount < 2) {
                $score -= 0.12;
                $issues[] = 'matched_key_entities_genericas';
            }
            if ($this->looksWeakUnknownBusinessText($businessObjective)) {
                $score -= 0.08;
                $issues[] = 'matched_objetivo_debil';
            }
            if ($this->looksWeakUnknownBusinessText($expectedResult)) {
                $score -= 0.08;
                $issues[] = 'matched_resultado_debil';
            }
            if ($this->looksWeakUnknownBusinessText($reason)) {
                $score -= 0.06;
                $issues[] = 'matched_razon_debil';
            }
            if ($confidence >= 0.985) {
                $score -= 0.05;
                $issues[] = 'matched_confianza_excesiva';
            }
            if (count($operatorAssistanceFlow) < 5) {
                $score -= 0.1;
                $issues[] = 'matched_sin_operator_flow';
            }
            if (count($similarUserSignals) < 4) {
                $score -= 0.08;
                $issues[] = 'matched_sin_similarity_signals';
            }
            if (count($trainingDialogFlow) < 6) {
                $score -= 0.12;
                $issues[] = 'matched_sin_training_dialog_flow';
            }
        }

        if ($status === 'NEW_BUSINESS') {
            if ($confidence < 0.65) {
                $score -= 0.2;
                $issues[] = 'new_business_confianza_baja';
            }
            if ($needs === []) {
                $score -= 0.12;
                $issues[] = 'new_business_sin_needs';
            }
            if ($documents === []) {
                $score -= 0.12;
                $issues[] = 'new_business_sin_documents';
            }
            if ($businessObjective === '') {
                $score -= 0.1;
                $issues[] = 'new_business_sin_business_objective';
            }
            if ($expectedResult === '') {
                $score -= 0.1;
                $issues[] = 'new_business_sin_expected_result';
            }
            if (count($keyEntities) < 2) {
                $score -= 0.08;
                $issues[] = 'new_business_sin_key_entities';
            }
            if ($firstModule === '') {
                $score -= 0.08;
                $issues[] = 'new_business_sin_first_module';
            }
            if (count($needs) < 5) {
                $score -= 0.1;
                $issues[] = 'new_business_needs_pobres';
            }
            if (count($documents) < 4) {
                $score -= 0.08;
                $issues[] = 'new_business_documents_pobres';
            }
            if (count($keyEntities) < 6) {
                $score -= 0.1;
                $issues[] = 'new_business_key_entities_pobres';
            }
            if ($specificEntityCount < 2) {
                $score -= 0.08;
                $issues[] = 'new_business_key_entities_genericas';
            }
            if ($this->looksWeakUnknownBusinessText($businessObjective)) {
                $score -= 0.06;
                $issues[] = 'new_business_objetivo_debil';
            }
            if ($this->looksWeakUnknownBusinessText($expectedResult)) {
                $score -= 0.06;
                $issues[] = 'new_business_resultado_debil';
            }
            if (count($operatorAssistanceFlow) < 4) {
                $score -= 0.08;
                $issues[] = 'new_business_sin_operator_flow';
            }
            if (count($trainingDialogFlow) < 5) {
                $score -= 0.08;
                $issues[] = 'new_business_sin_training_dialog_flow';
            }
        }

        if ($status === 'NEEDS_CLARIFICATION' && $question === '') {
            $score -= 0.2;
            $issues[] = 'clarificacion_sin_pregunta';
        }
        if ($status === 'NEEDS_CLARIFICATION') {
            if ($businessObjective === '' && $expectedResult === '' && $firstModule === '' && count($keyEntities) === 0) {
                $score -= 0.12;
                $issues[] = 'clarificacion_sin_contexto';
            }
            if ($confidence >= $confidenceThreshold && $canonical !== '') {
                if ($businessObjective === '' || $expectedResult === '' || $firstModule === '' || count($keyEntities) < 2) {
                    $score -= 0.15;
                    $issues[] = 'clarificacion_por_respuesta_incompleta';
                }
            }
            if (count($trainingGaps) < 3) {
                $score -= 0.08;
                $issues[] = 'clarificacion_sin_training_gaps';
            }
            if (count($nextDataQuestions) < 3) {
                $score -= 0.06;
                $issues[] = 'clarificacion_sin_next_data_questions';
            }
            if (count($trainingDialogFlow) < 4) {
                $score -= 0.08;
                $issues[] = 'clarificacion_sin_dialog_flow';
            }
        }

        if ($status === 'INVALID_REQUEST' && $reason === '') {
            $score -= 0.1;
            $issues[] = 'invalid_request_sin_razon';
        }
        if ($needsOutsideVocabulary !== []) {
            $score -= min(0.2, 0.04 * count($needsOutsideVocabulary));
            $issues[] = 'needs_fuera_vocabulario';
        }
        if ($documentsOutsideVocabulary !== []) {
            $score -= min(0.2, 0.05 * count($documentsOutsideVocabulary));
            $issues[] = 'documents_fuera_vocabulario';
        }

        $score = max(0.0, min(1.0, $score));
        return [
            'ok' => $score >= 0.85,
            'score' => round($score, 4),
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function persistUnknownBusinessLlmSample(
        string $tenantId,
        string $userId,
        string $candidate,
        string $text,
        array $resolved,
        array $quality
    ): void {
        $tenantId = trim($tenantId) !== '' ? trim($tenantId) : 'default';
        $userId = trim($userId) !== '' ? trim($userId) : 'anon';
        $candidate = trim($candidate);
        if ($candidate === '') {
            $candidate = trim((string) ($resolved['business_candidate'] ?? ''));
        }
        if ($candidate === '') {
            $candidate = 'negocio_desconocido';
        }

        try {
            $bucket = $this->memory->getTenantMemory($tenantId, 'unknown_business_llm_samples', ['items' => []]);
            if (!is_array($bucket)) {
                $bucket = ['items' => []];
            }
            $items = is_array($bucket['items'] ?? null) ? array_values((array) $bucket['items']) : [];
            array_unshift($items, [
                'at' => date('c'),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'candidate' => $candidate,
                'user_text' => mb_substr(trim($text), 0, 260),
                'status' => (string) ($resolved['status'] ?? ''),
                'confidence' => (float) ($resolved['confidence'] ?? 0.0),
                'canonical_business_type' => (string) ($resolved['canonical_business_type'] ?? ''),
                'business_objective' => (string) ($resolved['business_objective'] ?? ''),
                'expected_result' => (string) ($resolved['expected_result'] ?? ''),
                'needs_normalized' => is_array($resolved['needs_normalized'] ?? null)
                    ? array_values((array) $resolved['needs_normalized'])
                    : [],
                'documents_normalized' => is_array($resolved['documents_normalized'] ?? null)
                    ? array_values((array) $resolved['documents_normalized'])
                    : [],
                'key_entities' => is_array($resolved['key_entities'] ?? null)
                    ? array_values((array) $resolved['key_entities'])
                    : [],
                'first_module' => (string) ($resolved['first_module'] ?? ''),
                'operator_assistance_flow' => is_array($resolved['operator_assistance_flow'] ?? null)
                    ? array_values((array) $resolved['operator_assistance_flow'])
                    : [],
                'similar_user_signals' => is_array($resolved['similar_user_signals'] ?? null)
                    ? array_values((array) $resolved['similar_user_signals'])
                    : [],
                'training_dialog_flow' => is_array($resolved['training_dialog_flow'] ?? null)
                    ? array_values((array) $resolved['training_dialog_flow'])
                    : [],
                'training_gaps' => is_array($resolved['training_gaps'] ?? null)
                    ? array_values((array) $resolved['training_gaps'])
                    : [],
                'next_data_questions' => is_array($resolved['next_data_questions'] ?? null)
                    ? array_values((array) $resolved['next_data_questions'])
                    : [],
                'provider_used' => (string) ($resolved['provider_used'] ?? ''),
                'quality_score' => (float) ($quality['score'] ?? 0.0),
                'quality_ok' => (bool) ($quality['ok'] ?? false),
                'quality_issues' => is_array($quality['issues'] ?? null) ? array_values((array) $quality['issues']) : [],
            ]);
            if (count($items) > 200) {
                $items = array_slice($items, 0, 200);
            }
            $bucket['items'] = $items;
            $bucket['updated_at'] = date('c');
            $this->memory->saveTenantMemory($tenantId, 'unknown_business_llm_samples', $bucket);

            if (!(bool) ($quality['ok'] ?? false)) {
                $scoreText = number_format((float) ($quality['score'] ?? 0.0), 2, '.', '');
                $issueText = is_array($quality['issues'] ?? null)
                    ? implode(', ', array_slice((array) $quality['issues'], 0, 3))
                    : 'sin_detalle';
                $sample = '[llm_quality=' . $scoreText . '] status=' . (string) ($resolved['status'] ?? '')
                    . ' issues=' . $issueText;
                $this->appendResearchTopic(
                    $tenantId,
                    $candidate . ':llm_quality',
                    $userId,
                    mb_substr($sample, 0, 220)
                );
            }
        } catch (\Throwable $e) {
            // nunca bloquear flujo de chat por persistencia de telemetria.
        }
    }

    private function findDomainProfile(string $businessType, array $playbook = []): array
    {
        if ($businessType === '') {
            
        

        return [];
        }
        if (empty($playbook)) {
            $playbook = $this->loadDomainPlaybook();
        }
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        foreach ($profiles as $profile) {
            $key = strtolower((string) ($profile['key'] ?? ''));
            if ($key !== '' && $key === $businessType) {
                return is_array($profile) ? $profile : [];
            }
        }
        
        

        return [];
    }

    private function workflowByBusinessType(string $businessType, array $playbook = []): array
    {
        if (empty($playbook)) {
            $playbook = $this->loadDomainPlaybook();
        }
        $templates = is_array($playbook['workflow_templates'] ?? null) ? $playbook['workflow_templates'] : [];
        if ($businessType === 'servicios_mantenimiento' || $businessType === 'corte_laser') {
            $serviceFlow = is_array($templates['servicios_con_orden'] ?? null) ? $templates['servicios_con_orden'] : [];
            if (!empty($serviceFlow)) {
                return $serviceFlow;
            }
        }
        $salesFlow = is_array($templates['ventas_basico'] ?? null) ? $templates['ventas_basico'] : [];
        return !empty($salesFlow) ? $salesFlow : ['Crear cliente', 'Crear item', 'Registrar factura', 'Registrar pago'];
    }

    private function normalizeFieldTypeAlias(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return 'texto';
        }
        $map = [
            'string' => 'texto',
            'text' => 'texto',
            'bool' => 'bool',
            'boolean' => 'bool',
            'int' => 'numero',
            'integer' => 'numero',
            'number' => 'numero',
            'numeric' => 'numero',
            'float' => 'decimal',
            'double' => 'decimal',
            'decimal' => 'decimal',
            'date' => 'fecha',
            'datetime' => 'fecha',
        ];
        return $map[$type] ?? $type;
    }

    private function buildBusinessPlan(string $businessType, array $profile = []): array
    {
        $businessType = $this->normalizeBusinessType($businessType);
        $operationModel = $this->normalizeOperationModel((string) ($profile['operation_model'] ?? ''));
        $playbook = $this->loadDomainPlaybook();
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        $accounting = $this->loadAccountingKnowledge();

        foreach ($profiles as $profile) {
            $key = (string) ($profile['key'] ?? '');
            if ($key !== '' && $key === $businessType) {
                $entities = (array) ($profile['entities'] ?? []);
                $entities = $this->mergeAccountingEntities($entities, $businessType, $accounting, $operationModel);
                $forms = is_array($profile['forms'] ?? null) ? $profile['forms'] : [];
                $reports = is_array($profile['reports'] ?? null) ? $profile['reports'] : [];
                $workflows = $this->workflowByBusinessType($businessType, $playbook);
                return [
                    'business_type' => $businessType,
                    'operation_model' => $operationModel,
                    'entities' => $entities,
                    'first_entity' => $entities[0] ?? 'clientes',
                    'forms' => $forms,
                    'reports' => $reports,
                    'workflows' => $workflows,
                    'accounting_focus' => (array) ($profile['accounting_focus'] ?? []),
                    'accounting_checks' => (array) ($accounting['checklists']['creator_minimum'] ?? []),
                    'tax_profile' => (array) ($accounting['tax_profiles']['CO'] ?? []),
                ];
            }
        }

        $defaults = [
            'servicios_mantenimiento' => ['clientes', 'servicios', 'ordenes_trabajo', 'facturas', 'gastos'],
            'servicios' => ['clientes', 'servicios', 'ordenes_trabajo', 'facturas', 'gastos'],
            'retail_tienda' => ['clientes', 'productos', 'facturas', 'proveedores', 'gastos'],
            'productos' => ['clientes', 'productos', 'facturas', 'proveedores', 'gastos'],
            'mixto' => ['clientes', 'productos', 'servicios', 'ordenes_trabajo', 'facturas', 'gastos'],
        ];
        $entities = $defaults[$businessType] ?? ['clientes', 'facturas', 'gastos'];
        $entities = $this->mergeAccountingEntities($entities, $businessType, $accounting, $operationModel);
        return [
            'business_type' => $businessType,
            'operation_model' => $operationModel,
            'entities' => $entities,
            'first_entity' => $entities[0] ?? 'clientes',
            'forms' => [],
            'reports' => [],
            'workflows' => $this->workflowByBusinessType($businessType, $playbook),
            'accounting_focus' => [],
            'accounting_checks' => (array) ($accounting['checklists']['creator_minimum'] ?? []),
            'tax_profile' => (array) ($accounting['tax_profiles']['CO'] ?? []),
        ];
    }

    private function isOnboardingMetaAnswer(string $text): bool
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return true;
        }

        $patterns = [
            'si',
            'si dale',
            'dale',
            'ok',
            'listo',
            'siguiente paso',
            'paso sigue',
            'que sigue',
            'q sigue',
            'que mas sigue',
            'q mas sigue',
            'que mas falta',
            'q mas falta',
            'no entiendo',
            'explicame',
            'ayudame',
            'alto',
        ];
        foreach ($patterns as $pattern) {
            if ($normalized === $pattern || str_contains($normalized, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isReferenceToPreviousScope(string $text): bool
    {
        $patterns = [
            'lo que ya te mencione',
            'lo q ya te mencione',
            'lo mismo',
            'igual que antes',
            'igual que te dije',
            'como te dije',
            'ya te dije',
            'ya lo mencione',
            'como lo anterior',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function mergeScopeLabels(array $current, array $incoming): array
    {
        $merged = [];
        foreach (array_merge($current, $incoming) as $label) {
            $value = trim((string) $label);
            if ($value === '') {
                continue;
            }
            $merged[$this->normalize($value)] = $value;
        }
        return array_values($merged);
    }

    private function buildNeedsScopeExample(string $businessType, array $profile = []): string
    {
        $domain = $this->findDomainProfile($businessType);
        $examples = is_array($domain['scope_examples'] ?? null) ? $domain['scope_examples'] : [];
        if (!empty($examples)) {
            return (string) $examples[0];
        }

        $operation = (string) ($profile['operation_model'] ?? '');
        if ($operation === 'contado') {
            return 'ventas del dia e inventario';
        }
        if ($operation === 'credito') {
            return 'ordenes de trabajo y cartera';
        }
        return 'citas y facturacion';
    }

    private function buildDocumentsScopeExample(string $businessType, array $profile = []): string
    {
        $domain = $this->findDomainProfile($businessType);
        $examples = is_array($domain['document_examples'] ?? null) ? $domain['document_examples'] : [];
        if (!empty($examples)) {
            return (string) $examples[0];
        }

        $operation = (string) ($profile['operation_model'] ?? '');
        if ($operation === 'contado') {
            return 'factura y recibo de caja';
        }
        if ($operation === 'credito') {
            return 'factura y recibo de abono';
        }
        return 'factura, orden de trabajo y recibo de pago';
    }

    private function sanitizeRequirementText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B.,;:!?");
        if (mb_strlen($text, 'UTF-8') > 220) {
            $text = mb_substr($text, 0, 220, 'UTF-8');
        }
        return $text;
    }

    private function extractNeedItems(string $text, string $businessType = ''): array
    {
        $map = [
            'citas' => ['cita', 'citas', 'agenda', 'agendar', 'reservas', 'reserva'],
            'historia_clinica' => ['historia clinica', 'historias clinicas', 'historia mÃƒÆ’Ã‚Â©dica', 'historias mÃƒÆ’Ã‚Â©dicas', 'historia medica', 'historias medicas', 'evolucion clinica'],
            'inventario' => ['inventario', 'stock', 'existencias'],
            'medicamentos' => ['medicamento', 'medicamentos', 'farmacia'],
            'medico_turno' => ['medico en turno', 'medicos en turno', 'doctor en turno', 'doctores en turno', 'turno medico', 'turnos medicos'],
            'pacientes' => ['paciente', 'pacientes', 'mascota', 'mascotas', 'animalito', 'animalitos'],
            'duenos' => ['dueÃƒÆ’Ã‚Â±o', 'dueno', 'dueÃƒÆ’Ã‚Â±os', 'duenos', 'propietario', 'propietarios'],
            'facturacion' => ['factura', 'facturacion', 'facturar', 'cobro', 'cobros'],
            'pagos' => ['pago', 'pagos', 'abono', 'abonos', 'caja', 'recaudo'],
            'ordenes_trabajo' => ['orden de trabajo', 'ordenes de trabajo', 'servicio tecnico'],
            'servicios' => ['servicio', 'servicios', 'tratamiento', 'tratamientos'],
            'productos' => ['producto', 'productos', 'articulo', 'articulos'],
            'laboratorio_examenes' => ['muestra medica', 'muestras medicas', 'muestra de laboratorio', 'examen', 'examenes', 'laboratorio', 'analitica'],
            'gastos' => ['gasto', 'gastos', 'egreso', 'egresos', 'costos', 'costo'],
        ];
        $labels = [
            'citas' => 'citas',
            'historia_clinica' => 'historia clinica',
            'inventario' => 'inventario',
            'medicamentos' => 'medicamentos',
            'medico_turno' => 'medico en turno',
            'pacientes' => 'pacientes',
            'duenos' => 'dueÃƒÆ’Ã‚Â±os',
            'facturacion' => 'facturacion',
            'pagos' => 'pagos',
            'ordenes_trabajo' => 'ordenes de trabajo',
            'servicios' => 'servicios/tratamientos',
            'productos' => 'productos',
            'laboratorio_examenes' => 'muestras/examenes',
            'gastos' => 'gastos/costos',
        ];

        $found = [];
        foreach ($map as $key => $aliases) {
            foreach ($aliases as $alias) {
                $alias = $this->normalize((string) $alias);
                if ($alias === '') {
                    continue;
                }
                if (str_contains($text, $alias)) {
                    if ($this->isNegativeMention($text, $alias)) {
                        unset($found[$key]);
                        continue;
                    }
                    $found[$key] = $labels[$key] ?? $key;
                    break;
                }
            }
        }

        if (empty($found)) {
            
        

        return [];
        }

        $ordered = [];
        foreach (array_keys($map) as $key) {
            if (isset($found[$key])) {
                $ordered[] = $found[$key];
            }
        }
        return array_values(array_unique($ordered));
    }

    private function extractDocumentItems(string $text): array
    {
        $map = [
            'factura' => ['factura', 'facturacion', 'factura electronica'],
            'historia_clinica' => ['historia clinica', 'historia medica', 'evolucion clinica'],
            'orden_trabajo' => ['orden de trabajo', 'orden trabajo', 'orden de trabaja'],
            'cotizacion' => ['cotizacion', 'presupuesto', 'propuesta'],
            'recibo_pago' => ['recibo de pago', 'recibo pago', 'comprobante de pago', 'comprobante pago', 'pago', 'pagos', 'abono', 'abonos'],
            'receta' => ['receta', 'recetas', 'formula medica', 'formula'],
            'remision' => ['remision', 'remisiones', 'entrega'],
            'control_impreso' => ['control impreso', 'la que le imprimo', 'imprimo para control', 'impresa para control'],
            'inventario' => ['inventario', 'kardex'],
        ];
        $labels = [
            'factura' => 'factura',
            'historia_clinica' => 'historia clinica',
            'orden_trabajo' => 'orden de trabajo',
            'cotizacion' => 'cotizacion',
            'recibo_pago' => 'recibo de pago',
            'receta' => 'receta',
            'remision' => 'remision',
            'control_impreso' => 'control impreso',
            'inventario' => 'inventario',
        ];

        $found = [];
        foreach ($map as $key => $aliases) {
            foreach ($aliases as $alias) {
                $alias = $this->normalize((string) $alias);
                if ($alias === '') {
                    continue;
                }
                if (str_contains($text, $alias)) {
                    if ($this->isNegativeMention($text, $alias)) {
                        unset($found[$key]);
                        continue;
                    }
                    $found[$key] = $labels[$key] ?? $key;
                    break;
                }
            }
        }

        if (empty($found)) {
            
        

        return [];
        }

        $ordered = [];
        foreach (array_keys($map) as $key) {
            if (isset($found[$key])) {
                $ordered[] = $found[$key];
            }
        }
        return array_values(array_unique($ordered));
    }

    private function extractDocumentExclusions(string $text): array
    {
        $map = [
            'factura' => ['factura', 'facturacion', 'factura electronica'],
            'historia_clinica' => ['historia clinica', 'historia medica', 'evolucion clinica'],
            'orden_trabajo' => ['orden de trabajo', 'orden trabajo'],
            'cotizacion' => ['cotizacion', 'presupuesto', 'propuesta'],
            'recibo_pago' => ['recibo de pago', 'recibo pago', 'comprobante de pago', 'comprobante pago'],
            'remision' => ['remision', 'remisiones', 'entrega'],
            'control_impreso' => ['control impreso', 'la que le imprimo', 'imprimo para control', 'impresa para control'],
            'inventario' => ['inventario', 'kardex'],
        ];
        $labels = [
            'factura' => 'factura',
            'historia_clinica' => 'historia clinica',
            'orden_trabajo' => 'orden de trabajo',
            'cotizacion' => 'cotizacion',
            'recibo_pago' => 'recibo de pago',
            'remision' => 'remision',
            'control_impreso' => 'control impreso',
            'inventario' => 'inventario',
        ];
        $excluded = [];
        foreach ($map as $key => $aliases) {
            foreach ($aliases as $alias) {
                $alias = $this->normalize((string) $alias);
                if ($alias === '') {
                    continue;
                }
                if ($this->isNegativeMention($text, $alias)) {
                    $excluded[$key] = $labels[$key] ?? $key;
                    break;
                }
            }
        }
        if (empty($excluded)) {
            
        

        return [];
        }
        $ordered = [];
        foreach (array_keys($map) as $key) {
            if (isset($excluded[$key])) {
                $ordered[] = $excluded[$key];
            }
        }
        return $ordered;
    }

    private function isNegativeMention(string $text, string $alias): bool
    {
        $alias = preg_quote($alias, '/');
        $patterns = [
            '/\bno\s+' . $alias . '\b/u',
            '/\b' . $alias . '\s+no\b/u',
            '/\bsin\s+' . $alias . '\b/u',
            '/\b' . $alias . '\s+no\s+quiero\b/u',
            '/\b' . $alias . '\s+no\s+necesito\b/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    private function buildRequirementsSummaryReply(string $businessType, array $profile, array $plan): string
    {
        $domain = $this->findDomainProfile($businessType);
        $label = (string) ($domain['label'] ?? $businessType);
        $needs = (string) ($profile['needs_scope'] ?? '');
        if (is_array($profile['needs_scope_items'] ?? null) && !empty($profile['needs_scope_items'])) {
            $needs = implode(', ', array_values((array) $profile['needs_scope_items']));
        }
        $documents = (string) ($profile['documents_scope'] ?? '');
        if (is_array($profile['documents_scope_items'] ?? null) && !empty($profile['documents_scope_items'])) {
            $documents = implode(', ', array_values((array) $profile['documents_scope_items']));
        }
        $operation = (string) ($profile['operation_model'] ?? 'mixto');
        $entities = is_array($plan['entities'] ?? null) ? array_values(array_filter((array) $plan['entities'])) : [];

        $lines = [];
        $lines[] = 'Antes de crear, confirmemos tu necesidad:';
        $lines[] = '- Negocio: ' . $label;
        $lines[] = '- Forma de pago: ' . $operation;
        $lines[] = '- Que quieres controlar: ' . ($needs !== '' ? $needs : 'pendiente');
        $lines[] = '- Documentos: ' . ($documents !== '' ? $documents : 'pendiente');
        if (!empty($entities)) {
            $lines[] = '- Ruta inicial sugerida: ' . implode(', ', array_slice($entities, 0, 6));
        }
        $lines[] = 'Si esta bien, responde: si.';
        $lines[] = 'Si quieres ajustar algo, responde: no.';
        return implode("\n", $lines);
    }

    private function isFieldHelpQuestion(string $text): bool
    {
        $patterns = [
            'campo',
            'campos',
            'que debe tener',
            'cual debe tener',
            'cuales debe tener',
            'que campos',
            'cuales campos',
            'debe llevar',
            'que debe llevar',
            'q debe',
            'que lleva',
            'me sugieres',
            'sugieres',
            'me recomiendas',
            'recomiendas',
            'ayudame',
            'ayuda',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }


    private function buildCreateTableProposal(string $entity, array $profile = [], array $fields = []): array
    {
        $entity = $this->normalizeEntityForSchema($entity);
        $entity = $this->adaptEntityToBusinessContext($entity, $profile);
        if ($entity === '') {
            $entity = 'clientes';
        }
        $suggested = !empty($fields) ? $fields : $this->suggestFieldsForEntity($entity, $profile);
        $fieldsCommand = $this->suggestedFieldsToCommand($suggested);
        $fieldNames = [];
        foreach ($suggested as $field) {
            $fieldNames[] = explode(':', $field, 2)[0] ?? $field;
        }
        $requiredNames = array_slice($fieldNames, 0, 4);
        $optionalNames = array_slice($fieldNames, 4);
        $preview = implode(', ', array_slice($fieldNames, 0, 6));
        $unspscHint = $this->entityShouldHaveUnspsc($entity)
            ? ' Incluiremos codigo_unspsc para facturacion electronica.'
            : '';

        $replyLines = [];
        $replyLines[] = 'Vamos paso a paso.';
        $replyLines[] = 'Paso 1: crearemos la tabla ' . $entity . '.';
        if (!empty($requiredNames)) {
            $replyLines[] = 'Campos principales: ' . implode(', ', $requiredNames) . '.';
        }
        if (!empty($optionalNames)) {
            $replyLines[] = 'Campos adicionales sugeridos: ' . implode(', ', array_slice($optionalNames, 0, 4)) . '.';
        }
        if ($preview !== '') {
            $replyLines[] = 'Se guardara esta informacion: ' . $preview . '.' . $unspscHint;
        }
        $replyLines[] = 'Tipos rapidos: texto=palabras, numero=entero, decimal=con decimales, fecha=AAAA-MM-DD, bool=si/no.';
        $replyLines[] = 'Quieres que la cree por ti ahora? Responde: si o no.';
        $reply = implode("\n", $replyLines);

        return [
            'entity' => $entity,
            'reply' => $reply,
            'command' => [
                'command' => 'CreateEntity',
                'entity' => $entity,
                'fields' => $fieldsCommand,
            ],
        ];
    }

    private function buildNextStepProposal(string $businessType, array $plan, array $profile = [], string $owner = '', array $state = []): array
    {
        $businessType = $this->normalizeBusinessType($businessType);
        $domainProfile = $this->findDomainProfile($businessType);
        $businessLabel = (string) ($domainProfile['label'] ?? $businessType);

        $progress = $this->computeBuilderPlanProgress($plan, $state);
        $planEntities = $progress['plan_entities'];
        $doneEntities = $progress['done_entities'];
        $missingEntities = $progress['missing_entities'];
        $missingForms = $progress['missing_forms'];

        $tablePreview = implode(', ', array_slice($planEntities, 0, 6));
        $ownerLine = $owner !== '' ? 'Perfecto, ' . $owner . '.' . "\n" : '';

        if (!empty($missingEntities)) {
            $nextEntity = (string) $missingEntities[0];
            $proposal = $this->buildCreateTableProposal($nextEntity, $profile);
            $proposal['active_task'] = 'create_table';
            $proposal['reply'] = $ownerLine
                . 'Ruta para ' . $businessLabel . ':' . "\n"
                . '- Tablas base: ' . ($tablePreview !== '' ? $tablePreview : $nextEntity) . "\n"
                . '- Avance: ' . count($doneEntities) . '/' . count($planEntities) . ' tablas creadas.' . "\n"
                . '- Siguiente tabla: ' . $nextEntity . ".\n"
                . $proposal['reply'];
            return $proposal;
        }

        if (!empty($missingForms)) {
            $nextForm = (string) $missingForms[0];
            $nextEntity = $this->normalizeEntityForSchema((string) preg_replace('/\.form$/i', '', $nextForm));
            $reply = $ownerLine
                . 'La base de tablas ya esta completa para ' . $businessLabel . '.' . "\n"
                . '- Avance tablas: ' . count($doneEntities) . '/' . count($planEntities) . '.' . "\n"
                . '- Siguiente paso: crear formulario ' . $nextForm . '.';
            return [
                'entity' => $nextEntity,
                'active_task' => 'create_form',
                'reply' => $reply . "\n" . 'Quieres que lo cree por ti ahora? Responde: si o no.',
                'command' => [
                    'command' => 'CreateForm',
                    'entity' => $nextEntity,
                ],
            ];
        }

        $replyLines = [];
        $replyLines[] = $ownerLine . 'Excelente. La ruta base de ' . $businessLabel . ' ya esta completa.';
        $replyLines[] = '- Tablas creadas: ' . count($doneEntities) . '/' . count($planEntities) . '.';
        $replyLines[] = '- Formularios de la ruta: completos.';
        $replyLines[] = 'Siguiente paso: abre el chat de la app para registrar datos reales y probar flujo.';
        return [
            'entity' => '',
            'active_task' => 'builder_onboarding',
            'reply' => implode("\n", $replyLines),
            'command' => null,
        ];
    }

    private function computeBuilderPlanProgress(array $plan, array $state = []): array
    {
        $planEntities = array_values(array_filter(array_map(
            fn($v) => $this->normalizeEntityForSchema((string) $v),
            is_array($plan['entities'] ?? null) ? $plan['entities'] : []
        )));
        $planForms = array_values(array_filter(array_map(
            fn($v) => strtolower(trim((string) $v)),
            is_array($plan['forms'] ?? null) ? $plan['forms'] : []
        )));
        $existingEntities = array_map(
            fn($name) => $this->normalizeEntityForSchema((string) $name),
            $this->scopedEntityNames()
        );
        $completedEntitiesFromState = [];
        if (is_array($state['builder_completed_entities'] ?? null)) {
            foreach ((array) $state['builder_completed_entities'] as $name => $ts) {
                $normalizedName = $this->normalizeEntityForSchema((string) $name);
                if ($normalizedName !== '') {
                    $completedEntitiesFromState[] = $normalizedName;
                }
            }
        }
        if (!empty($completedEntitiesFromState)) {
            $existingEntities = array_values(array_unique(array_merge($existingEntities, $completedEntitiesFromState)));
        }
        $existingForms = array_map(
            fn($name) => strtolower((string) $name),
            $this->scopedFormNames()
        );
        $completedFormsFromState = [];
        if (is_array($state['builder_completed_forms'] ?? null)) {
            foreach ((array) $state['builder_completed_forms'] as $name => $ts) {
                $candidate = strtolower(trim((string) $name));
                if ($candidate !== '') {
                    $completedFormsFromState[] = str_ends_with($candidate, '.form') ? $candidate : ($candidate . '.form');
                }
            }
        }
        if (!empty($completedFormsFromState)) {
            $existingForms = array_values(array_unique(array_merge($existingForms, $completedFormsFromState)));
        }

        $doneEntities = [];
        $missingEntities = [];
        foreach ($planEntities as $candidate) {
            if (in_array($candidate, $existingEntities, true)) {
                $doneEntities[] = $candidate;
            } else {
                $missingEntities[] = $candidate;
            }
        }

        $missingForms = [];
        foreach ($planForms as $formName) {
            if ($formName === '') {
                continue;
            }
            $normalizedForm = str_ends_with($formName, '.form') ? $formName : ($formName . '.form');
            if (!in_array($normalizedForm, $existingForms, true)) {
                $missingForms[] = $normalizedForm;
            }
        }

        return [
            'plan_entities' => $planEntities,
            'done_entities' => $doneEntities,
            'missing_entities' => $missingEntities,
            'plan_forms' => $planForms,
            'missing_forms' => $missingForms,
        ];
    }

    private function buildDependencyGuidanceForBuilder(string $entity, array $profile = []): array
    {
        $target = $this->normalizeEntityForSchema($entity);
        if ($target === '') {
            
        

        return [];
        }
        $rules = $this->loadEntityDependencyRules();
        if (empty($rules[$target]) || !is_array($rules[$target])) {
            
        

        return [];
        }
        $rule = $rules[$target];
        $requiresGroups = is_array($rule['requires'] ?? null) ? $rule['requires'] : [];
        if (empty($requiresGroups)) {
            
        

        return [];
        }

        $missingGroups = [];
        foreach ($requiresGroups as $group) {
            $group = array_values(array_filter(array_map(fn($v) => $this->normalizeEntityForSchema((string) $v), (array) $group)));
            if (empty($group)) {
                continue;
            }
            $groupSatisfied = false;
            foreach ($group as $candidate) {
                if ($candidate === $target) {
                    $groupSatisfied = true;
                    break;
                }
                if ($this->entityExists($candidate)) {
                    $groupSatisfied = true;
                    break;
                }
            }
            if (!$groupSatisfied) {
                $missingGroups[] = $group;
            }
        }
        if (empty($missingGroups)) {
            
        

        return [];
        }

        $nextEntity = $missingGroups[0][0] ?? '';
        if ($nextEntity === '' || $nextEntity === $target) {
            
        

        return [];
        }
        $proposal = $this->buildCreateTableProposal($nextEntity, $profile);
        $readableTarget = $this->entityDisplayName($target);
        $groupNames = [];
        foreach ($missingGroups as $group) {
            $labels = array_map(fn($v) => $this->entityDisplayName((string) $v), $group);
            $groupNames[] = implode(' o ', $labels);
        }
        $missingText = implode(' y ', $groupNames);
        $reply = 'Para crear ' . $readableTarget . ' primero necesitamos: ' . $missingText . '.' . "\n" . $proposal['reply'];

        return [
            'ask' => $reply,
            'active_task' => 'create_table',
            'entity' => $proposal['entity'],
            'pending_command' => $proposal['command'],
        ];
    }

    private function loadEntityDependencyRules(): array
    {
        $playbook = $this->loadDomainPlaybook();
        $rules = is_array($playbook['entity_dependencies'] ?? null) ? $playbook['entity_dependencies'] : [];
        return $rules;
    }

    private function normalizeEntityForSchema(string $entity): string
    {
        $entity = strtolower(trim($entity));
        if ($entity === '') {
            return '';
        }
        $entity = str_replace('-', '_', $entity);
        $map = [
            'cliente' => 'clientes',
            'producto' => 'productos',
            'servicio' => 'servicios',
            'factura' => 'facturas',
            'pago' => 'pagos',
            'abono' => 'abonos',
            'proveedor' => 'proveedores',
            'orden_trabajo' => 'ordenes_trabajo',
            'ordenes_trabajo' => 'ordenes_trabajo',
            'ordenes' => 'ordenes_trabajo',
            'lote' => 'lotes',
            'item_factura' => 'factura_items',
            'factura_item' => 'factura_items',
        ];
        if (isset($map[$entity])) {
            return $map[$entity];
        }
        return $entity;
    }

    private function entityDisplayName(string $entity): string
    {
        return str_replace('_', ' ', $entity);
    }

    private function adaptEntityToBusinessContext(string $entity, array $profile = [], string $text = ''): string
    {
        $entity = $this->normalizeEntityForSchema($entity);
        if ($entity === '') {
            return $entity;
        }
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));

        // Only force 'pacientes' if the sector is medical or if explicitly requested in a medical context
        $isMedical = in_array($businessType, ['clinica_medica', 'odontologia', 'veterinaria', 'spa_bienestar'], true);

        if ($isMedical && (str_contains($text, 'paciente') || str_contains($text, 'pacientes'))) {
            return 'pacientes';
        }

        if ($isMedical && in_array($entity, ['clientes', 'cliente'], true)) {
            return 'pacientes';
        }

        if ($businessType === 'spa_bienestar' && in_array($entity, ['clientes', 'cliente'], true)) {
            return 'pacientes';
        }

        return $entity;
    }

    private function suggestFieldsForEntity(string $entity, array $profile = []): array
    {
        $suggested = [];
        $entity = strtolower(trim($entity));
        $playbook = $this->loadDomainPlaybook();
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        $domainProfile = $this->findDomainProfile($businessType, $playbook);
        if (!empty($domainProfile)) {
            $suggested = (array) ($domainProfile['suggestions'][$entity . '_fields'] ?? []);
            if (empty($suggested) && str_ends_with($entity, 's')) {
                $suggested = (array) ($domainProfile['suggestions'][rtrim($entity, 's') . '_fields'] ?? []);
            } elseif (empty($suggested)) {
                $suggested = (array) ($domainProfile['suggestions'][$entity . 's_fields'] ?? []);
            }
        }
        if (empty($suggested)) {
            $commonEntities = is_array($playbook['common_entities'] ?? null) ? $playbook['common_entities'] : [];
            foreach ($commonEntities as $commonEntity) {
                $name = strtolower((string) ($commonEntity['name'] ?? ''));
                if ($name === '' || ($name !== $entity && rtrim($name, 's') !== rtrim($entity, 's'))) {
                    continue;
                }
                $fields = is_array($commonEntity['fields'] ?? null) ? $commonEntity['fields'] : [];
                foreach ($fields as $field) {
                    $fieldName = (string) ($field['name'] ?? '');
                    $fieldType = (string) ($field['type'] ?? 'texto');
                    if ($fieldName === '') {
                        continue;
                    }
                    $suggested[] = $fieldName . ':' . $this->normalizeFieldTypeAlias($fieldType);
                }
                break;
            }
        }
        if (empty($suggested)) {
            $accounting = $this->loadAccountingKnowledge();
            $templates = is_array($accounting['entity_field_templates'] ?? null) ? $accounting['entity_field_templates'] : [];
            if (!empty($templates[$entity]) && is_array($templates[$entity])) {
                $suggested = $templates[$entity];
            }
        }
        if (empty($suggested)) {
            $fallback = [
                'productos' => ['codigo:texto', 'nombre:texto', 'categoria:texto', 'precio_venta:decimal', 'stock_actual:decimal', 'activo:bool'],
                'producto' => ['codigo:texto', 'nombre:texto', 'categoria:texto', 'precio_venta:decimal', 'stock_actual:decimal', 'activo:bool'],
                'clientes' => ['nombre:texto', 'documento:texto', 'telefono:texto', 'email:texto', 'activo:bool'],
                'cliente' => ['nombre:texto', 'documento:texto', 'telefono:texto', 'email:texto', 'activo:bool'],
                'facturas' => ['fecha:fecha', 'cliente_id:numero', 'subtotal:decimal', 'impuesto:decimal', 'total:decimal', 'estado:texto'],
                'factura' => ['fecha:fecha', 'cliente_id:numero', 'subtotal:decimal', 'impuesto:decimal', 'total:decimal', 'estado:texto'],
                'ordenes_trabajo' => ['numero:texto', 'fecha:fecha', 'cliente_id:numero', 'descripcion:texto', 'estado:texto', 'valor_total:decimal'],
                'servicios' => ['codigo:texto', 'nombre:texto', 'categoria:texto', 'precio_base:decimal', 'iva_porcentaje:numero', 'activo:bool'],
                'cuentas_por_cobrar' => ['fecha:fecha', 'cliente_id:numero', 'referencia:texto', 'valor:decimal', 'saldo:decimal', 'estado:texto'],
                'cuentas_por_pagar' => ['fecha:fecha', 'proveedor_id:numero', 'referencia:texto', 'valor:decimal', 'saldo:decimal', 'estado:texto'],
                'impuestos' => ['codigo:texto', 'nombre:texto', 'tipo:texto', 'porcentaje:decimal', 'activo:bool']
            ];
            $suggested = $fallback[$entity] ?? ['nombre:texto', 'descripcion:texto', 'activo:bool'];
        }
        if ($this->entityShouldHaveUnspsc($entity)) {
            $hasUnspsc = false;
            foreach ($suggested as $field) {
                $fieldId = strtolower((string) explode(':', (string) $field, 2)[0]);
                if (in_array($fieldId, ['codigo_unspsc', 'unspsc_codigo', 'unspsc'], true)) {
                    $hasUnspsc = true;
                    break;
                }
            }
            if (!$hasUnspsc) {
                $suggested[] = 'codigo_unspsc:texto';
            }
        }
        return array_values($suggested);
    }

    private function entityShouldHaveUnspsc(string $entity): bool
    {
        $entity = strtolower(trim($entity));
        $entities = [
            'productos',
            'producto',
            'servicios',
            'servicio',
            'repuestos',
            'materiales',
            'insumos',
            'factura_items',
            'detalle_factura',
        ];
        return in_array($entity, $entities, true);
    }

    private function suggestedFieldsToCommand(array $suggested): array
    {
        $fields = [];
        foreach ($suggested as $fieldLine) {
            $fieldLine = (string) $fieldLine;
            if ($fieldLine === '' || !str_contains($fieldLine, ':')) {
                continue;
            }
            [$rawName, $rawType] = array_pad(explode(':', $fieldLine, 2), 2, 'texto');
            $name = trim($rawName);
            $type = trim($rawType);
            if ($name === '') {
                continue;
            }
            $fields[] = ['name' => $name, 'type' => $type !== '' ? $type : 'texto'];
        }
        return $fields;
    }

    private function parseTableDefinition(string $text): array
    {
        $text = str_replace(',', ' ', $text);
        $text = preg_replace('/^(quiero\\s+|puedo\\s+|necesito\\s+|me\\s+gustaria\\s+|vamos\\s+a\\s+|ayudame\\s+a\\s+|ayudame\\s+|por\\s+favor\\s+)?(crear\\s+)?(la\\s+|el\\s+|un\\s+|una\\s+)?(tabla|entidad)\\s+(de\\s+|para\\s+)?/iu', '', $text) ?? $text;
        $tokens = preg_split('/\\s+/', trim($text)) ?: [];
        $entity = '';
        $fields = [];
        $entityTokens = [];
        $stopWords = [
            'de', 'del', 'la', 'el', 'los', 'las', 'que', 'q', 'cual', 'como', 'debe', 'llevar', 'eso',
            'tabla', 'entidad', 'crear', 'programa', 'app', 'aplicacion', 'sistema', 'quiero', 'puedo',
            'necesito', 'ayudame', 'paso', 'sigue', 'ahora', 'explicame', 'explica', 'por', 'favor',
            'si', 'no', 'cuales', 'vas', 'hacer', 'a',
        ];
        $stopWords = array_values(array_unique(array_merge($stopWords, $this->confusionNonEntityTokens())));

        foreach ($tokens as $token) {
            if (str_contains($token, ':') || str_contains($token, '=')) {
                $sep = str_contains($token, ':') ? ':' : '=';
                [$rawName, $rawType] = array_pad(explode($sep, $token, 2), 2, 'text');
                $name = trim($rawName);
                $type = trim($rawType ?: 'text');
                if ($name === '') continue;
                $fields[] = ['name' => $name, 'type' => $type];
                continue;
            }
            $token = mb_strtolower(trim($token), 'UTF-8');
            if (in_array($token, $stopWords, true)) {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9_\\x{00C0}-\\x{017F}-]+$/u', $token)) {
                continue;
            }
            $entityTokens[] = $token;
        }

        if (!empty($entityTokens)) {
            $entity = implode('_', $entityTokens);
            $entity = preg_replace('/[^a-zA-Z0-9_]/u', '_', $entity) ?? $entity;
            $entity = preg_replace('/_+/', '_', $entity) ?? $entity;
            $entity = trim($entity, '_');
        }

        $entity = $this->normalizeEntityForSchema($entity);

        return ['entity' => $entity, 'fields' => $fields];
    }

    private function parseEntityFromText(string $text): string
    {
        $text = str_replace(',', ' ', $text);
        $text = preg_replace('/^(crear\\s+)?(el\\s+|la\\s+|un\\s+|una\\s+)?(formulario|form)\\s+/i', '', $text) ?? $text;
        $tokens = preg_split('/\\s+/', trim($text)) ?: [];
        $stop = [
            'de', 'del', 'la', 'el', 'un', 'una', 'para', 'que', 'quiero', 'necesito', 'ayudame', 'campos',
            'si', 'no', 'cual', 'cuales', 'vas', 'crear', 'hacer', 'paso', 'ahora', 'explicame', 'me', 'a',
        ];
        $stop = array_values(array_unique(array_merge($stop, $this->confusionNonEntityTokens())));
        foreach ($tokens as $token) {
            $token = strtolower(trim($token));
            if ($token === '' || in_array($token, $stop, true)) {
                continue;
            }
            if (preg_match('/^[a-z0-9_\\-]+$/i', $token) !== 1) {
                continue;
            }
            return $token;
        }
        return '';
    }

    private function parseCrud(string $text, array $lexicon, array $state, string $mode = 'app'): array
    {
        $collected = $this->extractFields($text, $lexicon);
        $requestedSlot = (string) ($state['requested_slot'] ?? '');
        if (empty($collected) && $requestedSlot !== '' && $this->isLikelyValueReply($text) && !$this->hasCrudSignals($text)) {
            $collected[$requestedSlot] = trim($text);
        } elseif (empty($collected) && !empty($state['missing']) && $this->isLikelyValueReply($text) && !$this->hasCrudSignals($text)) {
            $firstMissing = (string) ($state['missing'][0] ?? '');
            if ($firstMissing !== '') {
                $collected[$firstMissing] = trim($text);
            }
        }

        $intent = $this->detectIntent($text);
        if ($intent === '') {
            $stateIntent = (string) ($state['intent'] ?? '');
            if (!empty($collected) && $this->isCrudIntent($stateIntent) && !empty($state['entity'])) {
                $intent = (string) $state['intent'];
                $entity = (string) $state['entity'];
                $missing = $this->missingRequired($entity, $collected, $intent);
                if (!empty($missing)) {
                    $ask = $this->askOneMissing($missing);
                    return ['ask' => $ask, 'intent' => $intent, 'entity' => $entity, 'collected' => $collected, 'missing' => $missing];
                }
                $command = $this->buildCommand($intent, $entity, $collected);
                return ['command' => $command, 'intent' => $intent, 'entity' => $entity, 'collected' => $collected];
            }
            
        

        return [];
        }

        $explicitEntity = $this->parseEntityFromCrudText($text);
        $entity = $explicitEntity !== '' ? $explicitEntity : $this->detectEntity($text, $lexicon, $state);
        $entity = $this->normalizeEntityForSchema($entity);
        $explicitEntity = $this->normalizeEntityForSchema($explicitEntity);
        if ($explicitEntity !== '' && !$this->entityExists($explicitEntity)) {
            return ['missing_entity' => true, 'entity' => $explicitEntity, 'intent' => $intent, 'collected' => $collected];
        }
        if ($entity !== '' && !$this->entityExists($entity)) {
            return ['missing_entity' => true, 'entity' => $entity, 'intent' => $intent, 'collected' => $collected];
        }

        if ($intent === 'update' || $intent === 'delete') {
            if (!isset($collected['id'])) {
                $ask = 'Necesito el id del registro.';
                return ['ask' => $ask, 'intent' => $intent, 'entity' => $entity, 'collected' => $collected];
            }
        }

        if ($intent === 'create' && empty($collected)) {
            $firstField = $this->pickPrimaryCreateField($entity);
            if ($firstField !== '') {
                $ask = 'Vamos paso a paso. Para crear ' . $this->entityDisplayName($entity) . ', dime ' . $firstField . '.';
                return [
                    'ask' => $ask,
                    'intent' => $intent,
                    'entity' => $entity,
                    'collected' => [],
                    'missing' => [$firstField],
                ];
            }
            $ask = 'Que dato quieres guardar primero en ' . $this->entityDisplayName($entity) . '?';
            return ['ask' => $ask, 'intent' => $intent, 'entity' => $entity, 'collected' => [], 'missing' => ['dato']];
        }

        $missing = $this->missingRequired($entity, $collected, $intent);
        if (!empty($missing)) {
            $ask = $this->askOneMissing($missing);
            return ['ask' => $ask, 'intent' => $intent, 'entity' => $entity, 'collected' => $collected, 'missing' => $missing];
        }

        $command = $this->buildCommand($intent, $entity, $collected);
        return ['command' => $command, 'intent' => $intent, 'entity' => $entity, 'collected' => $collected];
    }

    private function isCrudIntent(string $intent): bool
    {
        return in_array($intent, ['create', 'list', 'update', 'delete'], true);
    }

    private function detectIntent(string $text): string
    {
        $map = [
            'crear' => 'create',
            'agregar' => 'create',
            'nuevo' => 'create',
            'guardar' => 'create',
            'registrar' => 'create',
            'emitir' => 'create',
            'facturar' => 'create',
            'listar' => 'list',
            'lista' => 'list',
            'mostrar' => 'list',
            'muestrame' => 'list',
            'dame' => 'list',
            'ver' => 'list',
            'buscar' => 'list',
            'actualizar' => 'update',
            'editar' => 'update',
            'eliminar' => 'delete',
            'borrar' => 'delete',
        ];
        foreach ($map as $verb => $intent) {
            if (str_contains($text, $verb)) {
                return $intent;
            }
        }
        return '';
    }

    private function detectEntity(string $text, array $lexicon, array $state): string
    {
        $text = trim($text);
        $textLower = mb_strtolower($text, 'UTF-8');
        $aliases = $lexicon['entity_aliases'] ?? [];
        $aliasKeys = array_keys($aliases);
        usort($aliasKeys, static fn($a, $b) => mb_strlen((string) $b, 'UTF-8') <=> mb_strlen((string) $a, 'UTF-8'));
        foreach ($aliasKeys as $alias) {
            $entity = (string) ($aliases[$alias] ?? '');
            if ($entity === '') {
                continue;
            }
            $aliasLower = mb_strtolower((string) $alias, 'UTF-8');
            if ($aliasLower !== '' && preg_match('/\b' . preg_quote($aliasLower, '/') . '\b/u', $textLower) === 1) {
                return $entity;
            }
        }

        $entityNames = $this->scopedEntityNames();
        usort($entityNames, static fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
        foreach ($entityNames as $name) {
            $nameLower = mb_strtolower($name, 'UTF-8');
            if ($nameLower !== '' && preg_match('/\b' . preg_quote($nameLower, '/') . '\b/u', $textLower) === 1) {
                return $name;
            }
        }

        foreach ($entityNames as $name) {
            $name = (string) $name;
            $nameLower = mb_strtolower($name, 'UTF-8');
            if ($nameLower !== '' && str_contains($textLower, $nameLower)) {
                return $name;
            }
            if ($nameLower !== '' && str_ends_with($nameLower, 's')) {
                $singular = substr($nameLower, 0, -1);
                if ($singular !== '' && str_contains($textLower, $singular)) {
                    return $name;
                }
            }
        }

        return (string) ($state['entity'] ?? '');
    }

    private function entityExists(string $entity): bool
    {
        if ($entity === '') {
            return false;
        }
        $target = $this->normalizeEntityForSchema($entity);
        $variants = array_values(array_unique(array_filter([
            strtolower($entity),
            strtolower($target),
            strtolower(rtrim($target, 's')),
            strtolower($target . 's'),
        ])));
        foreach ($this->scopedEntityNames() as $entityName) {
            $name = strtolower((string) $entityName);
            if (in_array($name, $variants, true)) {
                return true;
            }
        }
        return false;
    }

    private function formExistsForEntity(string $entity): bool
    {
        $entity = $this->normalizeEntityForSchema($entity);
        if ($entity === '') {
            return false;
        }
        $target = strtolower($entity . '.form');
        foreach ($this->scopedFormNames() as $formName) {
            $normalized = strtolower((string) $formName);
            if ($normalized === $target || $normalized === strtolower($entity)) {
                return true;
            }
        }
        return false;
    }

    private function hasBuildSignals(string $text): bool
    {
        if (preg_match('/\b(crear|construir|armar|disenar|diseÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â±ar|hacer)\b.{0,30}\b(app|aplicacion|programa|software)\b/u', $text) === 1) {
            return true;
        }
        $markers = ['tabla', 'entidad', 'formulario', 'form', 'campo', 'columnas'];
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        return false;
    }

    
    private function hasRuntimeCrudSignals(string $text): bool
    {
        if ($this->hasFieldPairs($text) && preg_match('/\b(crear|registrar|guardar|agregar)\b/u', $text) === 1) {
            return true;
        }
        if (preg_match('/\b(listar|ver|mostrar|buscar|actualizar|editar|eliminar|borrar)\b/u', $text) === 1) {
            return true;
        }
        return false;
    }
private function parseEntityFromCrudText(string $text): string
    {
        $text = preg_replace('/^(quiero|puedo|necesito|me\\s+gustaria|deseo)\\s+/i', '', $text) ?? $text;
        $text = preg_replace('/^(crear|crea|agregar|nuevo|listar|lista|mostrar|muestrame|dame|ver|buscar|actualizar|editar|eliminar|borrar|guardar|registrar|emitir|facturar)\\s+/i', '', $text) ?? $text;
        $text = preg_replace('/^(un|una|el|la|los|las|lista|lista\\s+de|registros|registro|datos)\\s+/i', '', $text) ?? $text;

        $tokens = preg_split('/\\s+/', trim($text)) ?: [];
        if (empty($tokens)) {
            return '';
        }

        $stopwords = [
            'que', 'q', 'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'lista', 'registros', 'registro',
            'datos', 'hay', 'estan', 'esta', 'guardados', 'guardado', 'actuales', 'mas', 'con', 'para', 'dame', 'muestrame', 'mostrar',
            'si', 'no', 'te', 'pedi', 'eso', 'bueno', 'quiero', 'puedo', 'necesito', 'ver', 'listar', 'crear', 'actualizar', 'eliminar',
            'en', 'mi', 'mis', 'app', 'aplicacion', 'sistema', 'ya', 'ayudame', 'campos', 'debe', 'tener', 'que', 'a',
            'tabla', 'tablas', 'entidad', 'entidades', 'sabes', 'sobre', 'cual', 'cuales', 'vas', 'debo', 'hacer', 'crea',
            'ahora', 'paso', 'sigue', 'siguiente', 'explicame'
        ];
        $stopwords = array_values(array_unique(array_merge($stopwords, $this->confusionNonEntityTokens())));

        foreach ($tokens as $token) {
            $candidate = trim($token, " \t\n\r\0\x0B.,;:!?");
            if ($candidate === '' || str_contains($candidate, '=') || str_contains($candidate, ':')) {
                continue;
            }
            if (in_array($candidate, $stopwords, true)) {
                continue;
            }
            return $candidate;
        }
        return '';
    }

    private function detectEntityKeywordInText(string $text): string
    {
        $candidates = [
            'pacientes',
            'paciente',
            'clientes',
            'cliente',
            'productos',
            'producto',
            'servicios',
            'servicio',
            'facturas',
            'factura',
            'proveedores',
            'proveedor',
            'ordenes_trabajo',
            'orden_trabajo',
            'pagos',
            'abonos',
        ];
        foreach ($candidates as $candidate) {
            if (preg_match('/\\b' . preg_quote($candidate, '/') . '\\b/u', $text) === 1) {
                return $candidate;
            }
        }
        return '';
    }

    private function isCrudGuideRequest(string $text, array $state = [], array $training = []): bool
    {
        if (($state['active_task'] ?? '') === 'crud_guide') {
            return true;
        }
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $patterns = $training['routing']['local_guides']['crud_guide_triggers'] ?? [
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

    private function buildCrudGuide(string $entity): string
    {
        $fields = [];
        try {
            $contract = $this->entities->get($entity);
            if (is_array($contract)) {
                foreach (($contract['fields'] ?? []) as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $name = (string) ($field['name'] ?? '');
                    if ($name === '' || $name === 'id') {
                        continue;
                    }
                    $fields[] = $name;
                    if (count($fields) >= 3) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $fields = [];
        }
        $exampleFields = $fields ?: ['nombre', 'nit'];
        $example = 'crear ' . $entity . ' ' . implode(' ', array_map(fn($f) => $f . '=valor', $exampleFields));
        $lines = [];
        $lines[] = 'Para crear un registro en ' . $entity . ', escribe:';
        $lines[] = '- ' . $example;
        $lines[] = 'Para verlos: listar ' . $entity;
        $lines[] = 'Para actualizar: actualizar ' . $entity . ' id=1 campo=valor';
        $lines[] = 'Para eliminar: eliminar ' . $entity . ' id=1';
        return implode("\n", $lines);
    }

    private function extractFields(string $text, array $lexicon): array
    {
        $result = [];
        $tokens = preg_split('/\s+/', $text) ?: [];

        foreach ($tokens as $token) {
            if (str_contains($token, '=') || str_contains($token, ':')) {
                $sep = str_contains($token, '=') ? '=' : ':';
                [$rawKey, $rawVal] = array_pad(explode($sep, $token, 2), 2, '');
                $key = trim($rawKey);
                $val = trim($rawVal);
                if ($key === '') continue;
                $key = $lexicon['field_aliases'][$key] ?? $key;
                $result[$key] = $val;
            }
        }

        return $result;
    }

    private function isLikelyValueReply(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (str_contains($text, '=') || str_contains($text, ':')) {
            return false;
        }
        if (str_contains($text, '?')) {
            return false;
        }
        $tokens = preg_split('/\s+/', $text) ?: [];
        $blocked = [
            'que', 'como', 'ayuda', 'no', 'porque', 'por', 'dime',
            'quiero', 'puedo', 'puedes', 'necesito', 'explicame', 'explica',
            'crear', 'listar', 'mostrar', 'ver', 'buscar', 'actualizar', 'editar', 'eliminar', 'borrar',
            'registrar', 'guardar', 'facturar',
        ];
        foreach ($tokens as $token) {
            $t = mb_strtolower($token, 'UTF-8');
            if (in_array($t, $blocked, true)) {
                return false;
            }
        }
        return true;
    }

    private function missingRequired(string $entity, array $collected, string $intent): array
    {
        if ($intent === 'list') {
            
        

        return [];
        }
        if (in_array($intent, ['update', 'delete'], true)) {
            
        

        return [];
        }
        if ($entity === '') {
            return ['entidad'];
        }

        $required = [];
        try {
            $contract = $this->entities->get($entity);
            foreach (($contract['fields'] ?? []) as $field) {
                if (!empty($field['required']) && ($field['source'] ?? '') === 'form') {
                    $required[] = (string) $field['name'];
                }
            }
        } catch (RuntimeException $e) {
            return ['entidad'];
        }

        $missing = [];
        foreach ($required as $name) {
            if (!array_key_exists($name, $collected)) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    private function buildCommand(string $intent, string $entity, array $collected): array
    {
        $map = [
            'create' => 'CreateRecord',
            'update' => 'UpdateRecord',
            'delete' => 'DeleteRecord',
            'list' => 'QueryRecords',
        ];
        $command = $map[$intent] ?? 'QueryRecords';
        $id = $collected['id'] ?? null;

        return [
            'command' => $command,
            'entity' => $entity,
            'id' => $id,
            'data' => $collected,
            'filters' => $collected,
        ];
    }

    private function askOneMissing(array $missing): string
    {
        $first = $missing[0] ?? 'dato';
        return 'Me falta ' . $first . '. Cual es?';
    }

    private function pickPrimaryCreateField(string $entity): string
    {
        if ($entity === '') {
            return '';
        }
        try {
            $contract = $this->entities->get($entity);
        } catch (\Throwable $e) {
            return '';
        }

        $priority = ['nombre', 'paciente', 'documento', 'descripcion', 'codigo', 'titulo', 'concepto'];
        $fields = is_array($contract['fields'] ?? null) ? $contract['fields'] : [];
        foreach ($priority as $candidate) {
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $name = $this->normalizeEntityForSchema((string) ($field['name'] ?? ''));
                $source = strtolower((string) ($field['source'] ?? 'form'));
                if ($name === $candidate && $name !== 'id' && $source !== 'system') {
                    return (string) ($field['name'] ?? $candidate);
                }
            }
        }
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = trim((string) ($field['name'] ?? ''));
            $source = strtolower((string) ($field['source'] ?? 'form'));
            if ($name === '' || strtolower($name) === 'id' || $source === 'system') {
                continue;
            }
            return $name;
        }
        return '';
    }

    private function buildEntityList(): string
    {
        $entities = $this->scopedEntityNames();
        if (empty($entities)) {
            return 'Aun no hay tablas creadas. Quieres crear una?';
        }
        $list = implode(', ', array_slice($entities, 0, 6));
        return 'Tablas creadas: ' . $list . '. Quieres ver los campos de alguna?';
    }

    private function buildFormList(): string
    {
        $forms = $this->scopedFormNames();
        if (empty($forms)) {
            return 'Aun no hay formularios. Quieres crear uno?';
        }
        $list = implode(', ', array_slice($forms, 0, 6));
        return 'Formularios: ' . $list . '. Quieres abrir alguno?';
    }

    private function buildCapabilities(array $profile = [], array $training = [], string $mode = 'app'): string
    {
        $entities = $this->scopedEntityNames();
        $forms = $this->scopedFormNames();

        if ($mode === 'builder') {
            $help = $training['help']['builder'] ?? [];
            $steps = $help['steps'] ?? [];
            $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
            $domainProfile = $this->findDomainProfile($businessType);
            $businessLabel = (string) ($domainProfile['label'] ?? $businessType);
            $capabilities = [
                'Crear la estructura de tu app por chat (tablas, formularios y flujo).',
                'Guiarte paso a paso sin tecnicismos.',
                'Sugerir campos segun tu tipo de negocio.',
                'Definir campos calculados y totales para tus formularios.',
                'Aplicar base contable y tributaria minima para que tu app sea operable.',
                'Sugerir codigo UNSPSC para productos y servicios (facturacion electronica).',
            ];
            if ($businessType !== '') {
                $capabilities[] = 'Ruta activa para tu negocio: ' . $businessLabel . '.';
            }
            if (!empty($domainProfile)) {
                $reports = is_array($domainProfile['reports'] ?? null) ? $domainProfile['reports'] : [];
                if (!empty($reports)) {
                    $capabilities[] = 'Reportes sugeridos para tu negocio: ' . implode(', ', array_slice($reports, 0, 2)) . '.';
                }
            }

            $stateKey = empty($entities) ? 'empty' : (empty($forms) ? 'no_forms' : 'ready');
            $stateSteps = $steps[$stateKey] ?? [];
            $actions = [];
            if (empty($entities)) {
                $actions[] = 'crear tabla clientes nombre:texto documento:texto telefono:texto';
            } elseif (empty($forms)) {
                $actions[] = 'crear formulario ' . $entities[0];
            } else {
                $actions[] = 'estado del proyecto';
                $actions[] = 'siguiente paso';
                $actions[] = 'crear tabla ordenes_trabajo numero:texto fecha:fecha estado:texto';
            }
            $actions[] = 'instalar playbook FERRETERIA en simulacion';

            $lines = [];
            $lines[] = 'Puedo ayudarte con:';
            foreach ($capabilities as $item) {
                $lines[] = '- ' . $item;
            }
            if (!empty($stateSteps)) {
                $lines[] = 'Paso actual recomendado:';
                foreach ($stateSteps as $step) {
                    $lines[] = '- ' . $step;
                }
            }
            $lines[] = 'Opciones activas ahora:';
            foreach (array_slice($actions, 0, 3) as $item) {
                $lines[] = '- ' . $item;
            }
            $lines[] = 'Opciones del editor visual que puedo dejarte listas por chat: campos calculados, totales, listas y documentos.';
            $lines[] = 'Tablas actuales: ' . (!empty($entities) ? implode(', ', array_slice($entities, 0, 5)) : 'sin tablas');
            $lines[] = 'Formularios actuales: ' . (!empty($forms) ? implode(', ', array_slice($forms, 0, 5)) : 'sin formularios');
            return implode("\n", $lines);
        }

        $help = $training['help']['app'] ?? [];
        $capabilities = $help['capabilities'] ?? [
            'Crear y consultar datos por chat.',
            'Guiarte paso a paso sin tecnicismos.',
            'Mostrar reportes y totales cuando los pidas.',
            'Sugerir codigo UNSPSC para productos y servicios.',
        ];
        $actions = [];
        if (!empty($entities)) {
            $entity = $entities[0];
            $actions[] = 'crear ' . $entity . ' nombre=valor';
            $actions[] = 'listar ' . $entity;
        } else {
            $actions[] = 'pedir al creador que agregue una tabla base';
        }
        if (!empty($forms)) {
            $actions[] = 'abrir formulario ' . $forms[0];
        }

        $lines = [];
        $lines[] = 'Puedo ayudarte con:';
        foreach (array_slice($capabilities, 0, 4) as $item) {
            $lines[] = '- ' . $item;
        }
        $lines[] = 'Opciones activas ahora:';
        foreach (array_slice($actions, 0, 3) as $item) {
            $lines[] = '- ' . $item;
        }
        $lines[] = 'Entidades activas: ' . (!empty($entities) ? implode(', ', array_slice($entities, 0, 4)) : 'sin entidades');
        return implode("\n", $lines);
    }

    private function buildAppQuestionReply(string $text, array $lexicon, array $state, array $profile, array $training): string
    {
        if (str_contains($text, 'quien es') || str_contains($text, 'existe') || str_contains($text, 'buscar')) {
            if ($this->entityExists('cliente') || $this->entityExists('clientes')) {
                return 'Para validar una persona en datos reales, escribe: buscar cliente nombre=Ana o listar cliente.';
            }
            return 'No hay tabla de clientes activa. Pide al creador agregarla.';
        }

        if (str_contains($text, 'factura') && !$this->entityExists('factura') && !$this->entityExists('facturas')) {
            return 'En esta app no esta habilitada la tabla de factura. Pide al creador agregar facturas.';
        }

        if (str_contains($text, 'factura') && ($this->entityExists('factura') || $this->entityExists('facturas'))) {
            $hasClients = $this->entityExists('clientes') || $this->entityExists('cliente');
            $hasItems = $this->entityExists('productos') || $this->entityExists('producto') || $this->entityExists('servicios') || $this->entityExists('servicio');
            if (!$hasClients || !$hasItems) {
                return 'La app aun no esta lista para facturar bien. Falta configurar clientes y catalogo de productos/servicios. Pide al creador completar esas tablas.';
            }
            $hasIntegration = !empty(glob($this->projectRoot . '/contracts/integrations/*.integration.json') ?: []);
            if ($hasIntegration) {
                return 'Puedes guardar facturas y enviarlas si la integracion esta configurada. Ejemplo: crear factura nombre=Ana nit=123.';
            }
            return 'Puedes guardar facturas en la app. El envio electronico aun no esta activo.';
        }

        if (str_contains($text, 'producto') && !$this->entityExists('producto') && !$this->entityExists('productos')) {
            return 'En esta app no esta habilitada la tabla de productos. Pide al creador agregar productos.';
        }

        if (str_contains($text, 'producto') && ($this->entityExists('producto') || $this->entityExists('productos'))) {
            return 'Si, puedes crear productos. Ejemplo: crear producto nombre=Lapiz precio=2000';
        }

        if ($this->isCapabilitiesQuestion($text)) {
            return $this->buildCapabilities($profile, $training);
        }

        return 'Solo puedo responder con datos reales de esta app. Dime una accion concreta: listar cliente, crear cliente nombre=Ana, o estado de la app.';
    }

    private function isLastActionQuestion(string $text): bool
    {
        $patterns = [
            'que guardaste',
            'que registraste',
            'que creaste',
            'que hiciste',
            'cual fue lo ultimo',
            'que fue lo ultimo',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function buildLastActionReply(array $state, string $mode = 'app'): string
    {
        $last = is_array($state['last_action'] ?? null) ? $state['last_action'] : [];
        if (empty($last)) {
            return $mode === 'builder'
                ? 'Aun no he ejecutado ninguna accion en esta sesion de creador.'
                : 'Aun no he guardado nada en esta sesion. Si quieres, te guio para crear el primer registro.';
        }

        $command = (string) ($last['command'] ?? '');
        $entity = $this->entityDisplayName((string) ($last['entity'] ?? 'registro'));
        $data = is_array($last['data'] ?? null) ? $last['data'] : [];
        $result = is_array($last['result'] ?? null) ? $last['result'] : [];

        if ($command === 'CreateRecord') {
            $parts = [];
            foreach ($data as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : '[valor]');
                if (count($parts) >= 4) {
                    break;
                }
            }
            $id = isset($result['id']) ? (string) $result['id'] : '';
            $detail = !empty($parts) ? implode(', ', $parts) : 'sin campos visibles';
            if ($id !== '') {
                return 'Lo ultimo que guarde en ' . $entity . ': ' . $detail . ' (id=' . $id . ').';
            }
            return 'Lo ultimo que guarde en ' . $entity . ': ' . $detail . '.';
        }

        if ($command === 'UpdateRecord') {
            return 'Lo ultimo fue una actualizacion en ' . $entity . '.';
        }
        if ($command === 'DeleteRecord') {
            return 'Lo ultimo fue una eliminacion en ' . $entity . '.';
        }
        if ($command === 'QueryRecords') {
            $count = (int) ($result['count'] ?? 0);
            if ($count > 0) {
                return 'Lo ultimo fue una consulta en ' . $entity . ' y encontre ' . $count . ' registros.';
            }
            return 'Lo ultimo fue una consulta en ' . $entity . '.';
        }

        return 'Lo ultimo ejecutado fue ' . ($command !== '' ? $command : 'una accion') . ' en ' . $entity . '.';
    }

    private function buildBuilderFallbackReply(array $profile = []): string
    {
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        $owner = trim((string) ($profile['owner_name'] ?? ''));
        if ($businessType === '') {
            $prefix = $owner !== '' ? 'Listo ' . $owner . '. ' : '';
            return $prefix . 'Vamos paso a paso: dime que tipo de negocio tienes (ej: spa, ferreteria, consultorio, restaurante).';
        }
        $domain = $this->findDomainProfile($businessType);
        $label = (string) ($domain['label'] ?? $businessType);
        return 'Seguimos con tu app de ' . $label . '. Dime si quieres crear la siguiente tabla o ver el siguiente paso.';
    }

    private function deriveWorkflowId(string $text): string
    {
        $seed = trim($text);
        $seed = preg_replace('/[^a-z0-9_\\-\\s]/iu', ' ', $seed) ?? $seed;
        $seed = strtolower(trim(preg_replace('/\\s+/', '_', $seed) ?? $seed));
        if ($seed === '') {
            return 'wf_' . date('Ymd_His');
        }
        $seed = preg_replace('/_+/', '_', $seed) ?? $seed;
        $seed = trim($seed, '_');
        if ($seed === '') {
            $seed = 'workflow';
        }
        if (!str_starts_with($seed, 'wf_')) {
            $seed = 'wf_' . $seed;
        }
        return substr($seed, 0, 64);
    }

    private function routeBuilderGuidance(string $text, array $training, array $state, array $lexicon, string $mode): array
    {
        if ($mode !== 'builder') {
            
        

        return [];
        }
        if (trim($text) === '') {
            
        

        return [];
        }
        if (!empty($state['builder_pending_command']) && is_array($state['builder_pending_command'])) {
            
        

        return [];
        }
        $activeTask = (string) ($state['active_task'] ?? '');
        if (in_array($activeTask, ['builder_onboarding', 'unknown_business_discovery', 'business_research_confirmation'], true)) {
            
        

        return [];
        }

        $guides = $this->loadBuilderGuidance($training);
        if (empty($guides)) {
            
        

        return [];
        }

        $bestGuide = [];
        $bestTrigger = '';
        $bestScore = 0;
        foreach ($guides as $guide) {
            if (!is_array($guide)) {
                continue;
            }
            $triggers = is_array($guide['user_triggers'] ?? null) ? $guide['user_triggers'] : [];
            foreach ($triggers as $trigger) {
                $needle = $this->normalize((string) $trigger);
                if ($needle === '' || !str_contains($text, $needle)) {
                    continue;
                }
                $score = mb_strlen($needle, 'UTF-8');
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestGuide = $guide;
                    $bestTrigger = (string) $trigger;
                }
            }
        }

        if (empty($bestGuide)) {
            $fallbackTopic = $this->detectBuilderGuidanceTopicFallback($text);
            if ($fallbackTopic !== '') {
                $bestGuide = $this->findBuilderGuidanceByTopic($guides, $fallbackTopic);
                $bestTrigger = '__fallback__';
            }
        }

        if (empty($bestGuide)) {
            
        

        return [];
        }

        $topic = strtoupper(trim((string) ($bestGuide['topic'] ?? 'GENERAL')));
        $template = trim((string) ($bestGuide['agent_response_template'] ?? ''));
        if ($template === '') {
            
        

        return [];
        }

        $reply = $this->renderBuilderGuidanceTemplate($template, $text, $state, $lexicon);
        if ($reply === '') {
            
        

        return [];
        }
        $flowHint = $this->guidanceFlowHint($topic);
        if ($flowHint !== '') {
            $reply .= "\nFlujo sugerido: " . $flowHint;
        }
        $pendingCommand = $this->buildBuilderGuidancePendingCommand($topic, $text, $state, $lexicon);
        if (!empty($pendingCommand) && is_array($pendingCommand)) {
            $commandRule = $this->feedbackRuleForCommand($training, (string) ($pendingCommand['command'] ?? ''));
            if ((bool) ($commandRule['require_extra_confirmation'] ?? false)) {
                $reply .= "\nAntes de ejecutar, confirma que esta accion aplica exactamente a tu caso. "
                    . 'Si quieres ajustar, responde "atras".';
            }
        }

        return [
            'action' => !empty($pendingCommand) || str_contains($reply, '?') ? 'ask_user' : 'respond_local',
            'reply' => $reply,
            'intent' => $this->guidanceIntentByTopic($topic),
            'active_task' => 'builder_guidance',
            'collected' => [
                'topic' => $topic,
                'trigger' => $bestTrigger,
            ],
            'pending_command' => $pendingCommand,
        ];
    }

    private function loadBuilderGuidance(array $training = []): array
    {
        $playbook = $this->loadDomainPlaybook();
        $playbookGuidance = is_array($playbook['builder_guidance'] ?? null) ? $playbook['builder_guidance'] : [];
        $trainingGuidance = is_array($training['builder_guidance'] ?? null) ? $training['builder_guidance'] : [];

        $merged = [];
        $seen = [];
        foreach (array_merge($playbookGuidance, $trainingGuidance) as $guide) {
            if (!is_array($guide)) {
                continue;
            }
            $topic = strtoupper(trim((string) ($guide['topic'] ?? '')));
            $template = trim((string) ($guide['agent_response_template'] ?? ''));
            $triggers = is_array($guide['user_triggers'] ?? null) ? $guide['user_triggers'] : [];
            if ($topic === '' || $template === '' || empty($triggers)) {
                continue;
            }
            $dedupeKey = mb_strtolower($topic . '|' . $template, 'UTF-8');
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $merged[] = [
                'topic' => $topic,
                'user_triggers' => array_values(array_filter(array_map(
                    static fn($v): string => trim((string) $v),
                    $triggers
                ), static fn(string $v): bool => $v !== '')),
                'agent_response_template' => $template,
            ];
        }

        return $merged;
    }

    private function feedbackRuleForCommand(array $training, string $command): array
    {
        $command = strtolower(trim($command));
        if ($command === '') {
            
        

        return [];
        }
        $rules = is_array($training['feedback_rules'] ?? null) ? $training['feedback_rules'] : [];
        $byCommand = is_array($rules['commands'] ?? null) ? $rules['commands'] : [];
        $rule = $byCommand[$command] ?? [];
        return is_array($rule) ? $rule : [];
    }

    private function guidanceIntentByTopic(string $topic): string
    {
        $topic = strtoupper(trim($topic));
        if ($topic === '') {
            return 'BUILDER_GUIDANCE';
        }
        return 'BUILDER_GUIDANCE_' . $topic;
    }

    private function buildBuilderGuidancePendingCommand(string $topic, string $text, array $state, array $lexicon): array
    {
        $topic = strtoupper(trim($topic));
        if ($topic === 'RELATIONS' || $topic === 'MASTER_DETAIL') {
            $tables = $this->detectRelationTablesFromText($text, $state, $lexicon);
            $source = $this->normalizeEntityForSchema((string) ($tables['tabla_A'] ?? ''));
            $target = $this->normalizeEntityForSchema((string) ($tables['tabla_B'] ?? ''));
            if ($source === '' || $target === '' || $source === $target) {
                
        

        return [];
            }

            return [
                'command' => 'CreateRelation',
                'source_entity' => $source,
                'target_entity' => $target,
                'relation_type' => $topic === 'MASTER_DETAIL' ? 'hasMany' : 'belongsTo',
                'fk_field' => $source . '_id',
            ];
        }

        if ($topic === 'PERFORMANCE') {
            $entity = $this->detectGuidanceEntityFromText($text, $state, $lexicon);
            $field = $this->detectGuidanceFieldFromText($text);
            if ($entity === '' || $field === '') {
                
        

        return [];
            }

            return [
                'command' => 'CreateIndex',
                'entity' => $entity,
                'field' => $field,
                'index_name' => 'idx_' . $entity . '_' . $field,
            ];
        }

        
        

        return [];
    }

    private function detectBuilderGuidanceTopicFallback(string $text): string
    {
        if (
            preg_match('/\\b(conectar|relacionar|vincular|unir)\\b/u', $text) === 1
            && preg_match('/\\b(con|y)\\b/u', $text) === 1
        ) {
            return 'RELATIONS';
        }
        if (
            preg_match('/\\b(lent[ao]|lento|optimizar|indice|busqueda|b\\x{00fa}squeda)\\b/u', $text) === 1
        ) {
            return 'PERFORMANCE';
        }
        return '';
    }

    private function shouldPrioritizeUnknownCandidate(string $text, string $detectedBusinessType, string $candidate): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return false;
        }

        $normalizedDetected = $this->normalizeBusinessType($detectedBusinessType);
        if (!in_array($normalizedDetected, ['', 'retail_tienda', 'servicios_mantenimiento'], true)) {
            return false;
        }

        $normalizedText = $this->normalize($text);
        $hasSpecializationSignal = preg_match(
            '/\b(fabrico|fabricamos|fabricacion|manufacturo|manufactura|produccion|producimos|servicio\s+de|servicios\s+de|taller\s+de|estudio\s+de|planta\s+de|laboratorio\s+de|corte\s+laser)\b/u',
            $normalizedText
        ) === 1;
        if (!$hasSpecializationSignal) {
            return false;
        }

        $normalizedCandidate = $this->normalize($candidate);
        $parts = preg_split('/\s+/u', $normalizedCandidate) ?: [];
        $wordCount = count(array_values(array_filter($parts, static fn($value): bool => trim((string) $value) !== '')));
        return $wordCount >= 2 || mb_strlen($candidate, 'UTF-8') >= 14;
    }

    private function discoveryQuestionsFromProtocol(array $unknownProtocol): array
    {
        $baseQuestions = [];
        if (is_array($unknownProtocol['discovery_questions'] ?? null)) {
            foreach ((array) $unknownProtocol['discovery_questions'] as $question) {
                $value = trim((string) $question);
                if ($value !== '') {
                    $baseQuestions[] = $value;
                }
            }
        }

        $technicalQuestions = [];
        if (is_array($unknownProtocol['technical_requirements_questions'] ?? null)) {
            foreach ((array) $unknownProtocol['technical_requirements_questions'] as $question) {
                $value = trim((string) $question);
                if ($value !== '') {
                    $technicalQuestions[] = $value;
                }
            }
        }

        $defaults = [
            'Cual es el objetivo principal de la app en una frase?',
            'Que proceso completo quieres operar primero (inicio a fin)?',
            'Que datos minimos necesitas capturar por registro?',
            'Que documentos o comprobantes debes emitir primero?',
            'Que indicador necesitas ver cada dia o semana?',
        ];

        return $this->mergeScopeLabels(array_merge($baseQuestions, $technicalQuestions), $defaults);
    }

    private function startUnknownBusinessDiscovery(string $candidate, array &$state, array $unknownProtocol): ?array
    {
        $candidate = trim($candidate);
        if ($candidate === '' || !(bool) ($unknownProtocol['enabled'] ?? true)) {
            return null;
        }

        if ((string) ($state['active_task'] ?? '') === 'unknown_business_discovery') {
            return null;
        }

        $existingFlow = is_array($state['unknown_business_discovery'] ?? null)
            ? (array) $state['unknown_business_discovery']
            : [];
        if (!empty($existingFlow)) {
            $existingCandidate = trim((string) ($existingFlow['candidate'] ?? ''));
            $alreadyCompleted = trim((string) ($existingFlow['completed_at'] ?? '')) !== '';
            if ($existingCandidate !== '' && $this->normalize($existingCandidate) === $this->normalize($candidate) && $alreadyCompleted) {
                return null;
            }
        }

        $questions = $this->discoveryQuestionsFromProtocol($unknownProtocol);
        if (empty($questions)) {
            return null;
        }

        $state['active_task'] = 'unknown_business_discovery';
        $state['onboarding_step'] = 'business_type';
        $state['unknown_business_discovery'] = [
            'candidate' => $candidate,
            'questions' => $questions,
            'answers' => [],
            'current_index' => 0,
            'started_at' => date('c'),
            'completed_at' => null,
            'technical_prompt' => null,
            'technical_brief' => null,
        ];
        $state['resolution_attempts'] = (int) ($state['resolution_attempts'] ?? 0) + 1;
        $state['unknown_business_notice_sent'] = true;

        $template = trim((string) ($unknownProtocol['message_template'] ?? 'No tengo plantilla exacta para "{business}" todavia. Ya lo registre para investigarlo y compartirlo con todos los agentes.'));
        if ($template === '') {
            $template = 'No tengo plantilla exacta para "{business}" todavia.';
        }
        $firstQuestion = trim((string) ($questions[0] ?? ''));
        if ($firstQuestion === '') {
            $firstQuestion = 'Que objetivo principal quieres resolver primero?';
        }

        $reply = str_replace('{business}', $candidate, $template) . "\n"
            . 'Para disenar bien la solucion, necesito datos minimos del negocio antes de construir.' . "\n"
            . 'Te pedire 1 dato critico por turno y luego te mostrare: "Esto entendi".' . "\n"
            . $this->buildUnknownBusinessInformationNeedsText() . "\n"
            . 'Pregunta 1/' . count($questions) . ': ' . $firstQuestion;

        return [
            'action' => 'ask_user',
            'reply' => $reply,
            'state' => $state,
        ];
    }

    private function handleUnknownBusinessDiscoveryStep(
        string &$text,
        array &$state,
        array &$profile,
        array $unknownProtocol,
        string $tenantId,
        string $userId,
        string &$completionNote
    ): ?array {
        if ((string) ($state['active_task'] ?? '') !== 'unknown_business_discovery') {
            return null;
        }

        $flow = is_array($state['unknown_business_discovery'] ?? null)
            ? (array) $state['unknown_business_discovery']
            : [];
        if (empty($flow)) {
            return null;
        }

        $questions = is_array($flow['questions'] ?? null) ? (array) $flow['questions'] : [];
        if (empty($questions)) {
            $questions = $this->discoveryQuestionsFromProtocol($unknownProtocol);
        }
        if (empty($questions)) {
            $state['active_task'] = 'builder_onboarding';
            $state['unknown_business_discovery'] = null;
            return null;
        }

        $candidate = trim((string) ($flow['candidate'] ?? ($profile['business_candidate'] ?? '')));
        if ($candidate === '') {
            $candidate = trim((string) ($profile['business_candidate'] ?? ''));
        }
        if ($candidate === '') {
            $state['active_task'] = 'builder_onboarding';
            $state['unknown_business_discovery'] = null;
            return null;
        }

        $answers = is_array($flow['answers'] ?? null) ? (array) $flow['answers'] : [];
        $index = (int) ($flow['current_index'] ?? 0);
        if ($index < 0) {
            $index = 0;
        }

        if ($index < count($questions)) {
            $answer = $this->sanitizeRequirementText($text);
            if ($answer === '' || $this->isUnknownDiscoveryNonAnswer($answer)) {
                $question = trim((string) ($questions[$index] ?? ''));
                if ($question === '') {
                    $question = 'Describe el proceso principal que quieres controlar.';
                }
                $prefix = $this->isFrustrationSignal($answer)
                    ? 'Entiendo la molestia. Para avanzar necesito este dato:'
                    : 'Necesito una respuesta corta para continuar.';
                return [
                    'action' => 'ask_user',
                    'reply' => $prefix . "\n"
                        . $this->buildUnknownBusinessUnderstandingSummary($candidate, $answers, $questions) . "\n"
                        . 'Pregunta ' . ($index + 1) . '/' . count($questions) . ': ' . $question,
                    'state' => $state,
                ];
            }
            if ($this->isUnknownDiscoveryRepeatedAnswer($answer, $answers)) {
                $question = trim((string) ($questions[$index] ?? ''));
                if ($question === '') {
                    $question = 'Describe el proceso principal que quieres controlar.';
                }
                return [
                    'action' => 'ask_user',
                    'reply' => 'Ese dato ya lo tengo. Solo necesito el que sigue.' . "\n"
                        . $this->buildUnknownBusinessUnderstandingSummary($candidate, $answers, $questions) . "\n"
                        . 'Pregunta ' . ($index + 1) . '/' . count($questions) . ': ' . $question,
                    'state' => $state,
                ];
            }

            $answers[] = [
                'question' => trim((string) ($questions[$index] ?? '')),
                'answer' => $answer,
            ];
            $index++;
        }

        if ($index < count($questions)) {
            $flow['answers'] = $answers;
            $flow['current_index'] = $index;
            $flow['questions'] = $questions;
            $state['unknown_business_discovery'] = $flow;
            $state['active_task'] = 'unknown_business_discovery';
            $state['onboarding_step'] = 'business_type';

            $question = trim((string) ($questions[$index] ?? ''));
            if ($question === '') {
                $question = 'Que proceso quieres controlar primero?';
            }
            return [
                'action' => 'ask_user',
                'reply' => $this->buildUnknownBusinessUnderstandingSummary($candidate, $answers, $questions) . "\n"
                    . 'Pregunta ' . ($index + 1) . '/' . count($questions) . ': ' . $question,
                'state' => $state,
            ];
        }

        $brief = $this->buildUnknownBusinessTechnicalBrief($candidate, $answers);
        $prompt = $this->buildUnknownBusinessResearchPrompt($candidate, $answers, $unknownProtocol, $profile, $state);
        $flow['answers'] = $answers;
        $flow['questions'] = $questions;
        $flow['current_index'] = count($questions);
        $flow['completed_at'] = date('c');
        $flow['technical_prompt'] = $prompt;
        $flow['technical_brief'] = $brief;
        $state['unknown_business_discovery'] = $flow;
        $state['active_task'] = 'builder_onboarding';
        $state['onboarding_step'] = 'business_type';
        $state['proposed_profile'] = null;
        $state['unknown_business_force_research'] = true;

        $profile['business_candidate'] = $candidate;
        $this->saveProfile($tenantId, $this->profileUserKey($userId), $profile);

        $text = $this->buildUnknownBusinessDiscoveryContextText($candidate, $answers);
        $completionNote = $this->buildUnknownBusinessUnderstandingSummary($candidate, $answers, $questions)
            . "\n" . 'Documento tecnico inicial listo para investigacion.'
            . "\n" . $brief;
        return null;
    }

    private function isUnknownDiscoveryNonAnswer(string $answer): bool
    {
        $normalized = $this->normalize($answer);
        if ($normalized === '') {
            return true;
        }
        if ($this->isAffirmativeReply($normalized) || $this->isNegativeReply($normalized)) {
            return true;
        }

        $phrases = [
            'ya te respondi',
            'ya te dije',
            'ya lo respondi',
            'no entiendes',
            'no estas entendiendo',
            'no estas',
            'no me entiendes',
            'no sirves',
            'que mas necesitas saber',
            'que mas',
            'que necesitas',
            'otra vez lo mismo',
            'no funciona',
        ];
        foreach ($phrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }
        if (preg_match('/\bno\s+est[ao]s?\s+en?tend\w*/u', $normalized) === 1) {
            return true;
        }
        if (preg_match('/\bno\s+me\s+en?tend\w*/u', $normalized) === 1) {
            return true;
        }

        return false;
    }

    private function isUnknownDiscoveryRepeatedAnswer(string $answer, array $answers): bool
    {
        $target = $this->normalize($answer);
        if ($target === '') {
            return false;
        }
        foreach ($answers as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $saved = $this->normalize((string) ($pair['answer'] ?? ''));
            if ($saved !== '' && $saved === $target) {
                return true;
            }
        }
        return false;
    }

    private function isFrustrationSignal(string $text): bool
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return false;
        }
        $markers = [
            'no entiendes',
            'no estas entendiendo',
            'no estas',
            'no me entiendes',
            'no sirves',
            'otra vez',
            'me frustra',
            'me molesta',
            'estoy cansado',
            'estoy cansada',
            'que estres',
            'que frustrante',
            'nada que',
        ];
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        if (preg_match('/\bno\s+est[ao]s?\s+en?tend\w*/u', $text) === 1) {
            return true;
        }
        if (preg_match('/\bno\s+me\s+en?tend\w*/u', $text) === 1) {
            return true;
        }
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function unknownBusinessRequiredInfoChecklist(): array
    {
        return [
            'Oferta principal (que vendes o prestas).',
            'Cliente objetivo y canal de venta (mostrador, domicilio, web, etc).',
            'Forma de cobro y medios de pago.',
            'Regla de pago al personal (comision, sueldo, alquiler de puesto).',
            'Proceso operativo principal de inicio a fin.',
            'Flujo diario para usuaria no tecnica (inicio de jornada -> atencion -> cobro -> cierre).',
            'Datos minimos por registro (productos, cantidades, precio, usuario, sede, etc).',
            'Documentos obligatorios que debes emitir.',
            'Reportes o indicadores diarios/semanales.',
            'Reglas criticas que no se pueden romper.',
            'Roles que operan la app (admin, caja, vendedor, contador, etc).',
            'Preguntas y errores frecuentes del usuario final durante uso de la app.',
            'Frases reales del usuario (con typos) que debemos entender en soporte.',
            'Modulo prioritario para salir en version 1.',
        ];
    }

    private function buildUnknownBusinessInformationNeedsText(): string
    {
        $items = $this->unknownBusinessRequiredInfoChecklist();
        if ($items === []) {
            return '';
        }
        $parts = [];
        foreach ($items as $index => $item) {
            $parts[] = ($index + 1) . ') ' . trim((string) $item);
        }
        return 'Informacion que necesito para construir y asistirte bien: ' . implode(' | ', $parts);
    }

    private function buildUnknownBusinessUnderstandingSummary(string $candidate, array $answers, array $questions): string
    {
        $lines = [];
        $labelCandidate = trim($candidate) !== '' ? trim($candidate) : 'negocio en estudio';
        $lines[] = 'Esto entendi de "' . $labelCandidate . '" hasta ahora:';

        $maxItems = 5;
        $used = 0;
        foreach ($answers as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $question = trim((string) ($pair['question'] ?? ''));
            $answer = trim((string) ($pair['answer'] ?? ''));
            if ($answer === '') {
                continue;
            }
            if ($question === '') {
                $question = 'Dato';
            }
            $question = rtrim($question, " \t\n\r\0\x0B?.!");
            $lines[] = '- ' . $question . ': ' . $answer . '.';
            $used++;
            if ($used >= $maxItems) {
                break;
            }
        }

        if ($used === 0) {
            $lines[] = '- Aun no tengo datos suficientes confirmados.';
        }

        $pending = max(0, count($questions) - count($answers));
        $lines[] = 'Datos pendientes por confirmar: ' . $pending . '.';
        return implode("\n", $lines);
    }

    /**
     * @return array<int, string>
     */
    private function buildUnknownBusinessOperatorAssistanceFallback(string $candidate, string $canonicalBusinessType): array
    {
        $label = trim($candidate) !== '' ? trim($candidate) : 'tu negocio';
        if ($canonicalBusinessType !== '') {
            $domainLabel = $this->domainLabelByBusinessType($canonicalBusinessType);
            if ($domainLabel !== '') {
                $label = $domainLabel;
            }
        }
        return [
            '1) Resumen inicial: confirmar que entendimos "' . $label . '" y objetivo principal de la app.',
            '2) Operacion diaria guiada: abrir agenda o ventas, registrar servicio/producto, cobrar y guardar soporte.',
            '3) Control del personal: calcular pago o comision por tarea realizada y validar reglas de negocio.',
            '4) Cierre diario: validar caja vs transferencias, gastos y pendientes por cobrar.',
            '5) Soporte rapido: ante duda o error, explicar en lenguaje simple y pedir solo 1 dato critico.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildUnknownBusinessSimilaritySignalsFallback(string $candidate, string $canonicalBusinessType): array
    {
        $label = trim($candidate) !== '' ? trim($candidate) : 'negocio';
        if ($canonicalBusinessType !== '') {
            $domainLabel = $this->domainLabelByBusinessType($canonicalBusinessType);
            if ($domainLabel !== '') {
                $label = $domainLabel;
            }
        }
        return [
            'usuario_no_tecnico_' . $this->normalize($label) . ': "no se de sistemas, solo quiero que funcione"',
            'usuario_con_frustracion: "no entiendo esto, ayudame paso a paso"',
            'usuario_con_urgencia: "es para ya, necesito cobrar ahora"',
            'usuario_con_pocos_datos: "no se, tu dime que necesitas"',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildUnknownBusinessTrainingDialogFallback(string $candidate, string $canonicalBusinessType): array
    {
        $label = trim($candidate) !== '' ? trim($candidate) : 'negocio';
        if ($canonicalBusinessType !== '') {
            $domainLabel = $this->domainLabelByBusinessType($canonicalBusinessType);
            if ($domainLabel !== '') {
                $label = $domainLabel;
            }
        }
        return [
            'deteccion_negocio | usuario: "tengo un ' . $label . '" | asistente: "Entendi tu negocio. Te guio con 1 dato por turno." | dato_critico: tipo_de_operacion',
            'resumen_entendimiento | usuario: "si, pero no se de administracion" | asistente: "Esto entendi: agenda, cobro y cierre diario. Confirmas?" | dato_critico: confirmacion_resumen',
            'captura_catalogo | usuario: "vendo varias cosas" | asistente: "Dime 3 servicios o productos con precio." | dato_critico: catalogo_base',
            'captura_regla_personal | usuario: "les pago como siempre" | asistente: "Es porcentaje, sueldo fijo o alquiler de puesto?" | dato_critico: regla_pago_personal',
            'captura_cobro | usuario: "cobro por nequi y efectivo" | asistente: "Perfecto, dejo medios de pago y cierre por canal." | dato_critico: medios_pago',
            'uso_operativo_app | usuario: "como lo uso en el dia?" | asistente: "Abres jornada, registras atencion, cobras, luego cierras caja." | dato_critico: flujo_diario',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildUnknownBusinessTrainingGapsFallback(string $candidate, string $canonicalBusinessType): array
    {
        $label = trim($candidate) !== '' ? trim($candidate) : 'negocio';
        if ($canonicalBusinessType !== '') {
            $domainLabel = $this->domainLabelByBusinessType($canonicalBusinessType);
            if ($domainLabel !== '') {
                $label = $domainLabel;
            }
        }
        return [
            'definir_catalogo_base_para_' . $this->normalize($label),
            'definir_flujo_diario_para_usuario_no_tecnico',
            'definir_regla_de_pago_al_personal',
            'definir_documentos_y_soportes_obligatorios',
            'definir_proceso_de_cierre_y_alertas_de_error',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildUnknownBusinessNextDataQuestionsFallback(string $candidate, string $canonicalBusinessType): array
    {
        $label = trim($candidate) !== '' ? trim($candidate) : 'tu negocio';
        if ($canonicalBusinessType !== '') {
            $domainLabel = $this->domainLabelByBusinessType($canonicalBusinessType);
            if ($domainLabel !== '') {
                $label = $domainLabel;
            }
        }
        return [
            'Para "' . $label . '", que quieres ordenar primero? A) Agenda/atencion B) Cobro/caja C) Inventario.',
            'Como pagas al personal? A) Porcentaje por servicio B) Sueldo fijo C) Alquiler de puesto.',
            'Como cobras a tus clientes? A) Efectivo B) Transferencia/Nequi C) Mixto.',
            'Que te duele mas hoy? A) Cruce de citas B) No saber utilidad C) No cuadrar caja.',
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildUnknownBusinessAnsweredInfo(array $answers): array
    {
        $rows = [];
        foreach ($answers as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $question = trim((string) ($pair['question'] ?? ''));
            $answer = trim((string) ($pair['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            $rows[] = [
                'question' => $question,
                'answer' => $answer,
            ];
            if (count($rows) >= 12) {
                break;
            }
        }
        return $rows;
    }

    private function buildUnknownBusinessTechnicalBrief(string $candidate, array $answers): string
    {
        $labels = [
            'Alcance inicial',
            'Modelo de cobro',
            'Reporte prioritario',
            'Objetivo principal',
            'Proceso operativo',
            'Datos minimos',
            'Documentos obligatorios',
            'Indicador principal',
            'Regla critica',
            'Roles operativos',
            'Modulo prioritario',
        ];

        $lines = [];
        $lines[] = 'Documento tecnico inicial:';
        $lines[] = 'Negocio candidato: ' . $candidate . '.';
        foreach ($answers as $index => $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $answer = trim((string) ($pair['answer'] ?? ''));
            if ($answer === '') {
                continue;
            }
            $label = $labels[$index] ?? ('Dato ' . ($index + 1));
            $lines[] = '- ' . $label . ': ' . $answer . '.';
        }
        return implode("\n", $lines);
    }

    private function buildUnknownBusinessResearchPrompt(
        string $candidate,
        array $answers,
        array $unknownProtocol,
        array $profile,
        array $state
    ): string {
        $template = is_array($unknownProtocol['research_prompt_template'] ?? null)
            ? (array) $unknownProtocol['research_prompt_template']
            : [];
        $requiredKeys = is_array($template['required_output_keys'] ?? null)
            ? array_values(array_filter(array_map('strval', (array) $template['required_output_keys'])))
            : [
                'status',
                'confidence',
                'canonical_business_type',
                'business_candidate',
                'business_objective',
                'expected_result',
                'reason_short',
                'needs_normalized',
                'documents_normalized',
                'key_entities',
                'first_module',
                'operator_assistance_flow',
                'similar_user_signals',
                'training_dialog_flow',
                'training_gaps',
                'next_data_questions',
                'clarifying_question',
            ];
        $requiredKeys = array_values(array_unique(array_merge(
            [
                'status',
                'confidence',
                'canonical_business_type',
                'business_candidate',
                'business_objective',
                'expected_result',
                'reason_short',
                'needs_normalized',
                'documents_normalized',
                'key_entities',
                'first_module',
                'operator_assistance_flow',
                'similar_user_signals',
                'training_dialog_flow',
                'training_gaps',
                'next_data_questions',
                'clarifying_question',
            ],
            $requiredKeys
        )));
        $allowedNeedsVocabulary = $this->unknownBusinessAllowedNeedsVocabulary();
        $allowedDocumentsVocabulary = $this->unknownBusinessAllowedDocumentsVocabulary();
        $requiredBusinessInfo = $this->unknownBusinessRequiredInfoChecklist();
        $answeredBusinessInfo = $this->buildUnknownBusinessAnsweredInfo($answers);

        $promptContract = [
            'ROLE' => 'Senior Business Systems Analyst',
            'CONTEXT' => [
                'goal' => (string) ($template['goal'] ?? 'Analizar negocio desconocido y convertirlo en propuesta estructurada.'),
                'language' => 'es-CO',
                'profile_hint' => (string) ($profile['business_type'] ?? ''),
                'onboarding_step' => (string) ($state['onboarding_step'] ?? ''),
                'strict_quality_target' => '10/10',
                'required_business_information' => $requiredBusinessInfo,
            ],
            'INPUT' => [
                'business_candidate' => $candidate,
                'requirements_answers' => $answers,
                'answered_business_information' => $answeredBusinessInfo,
            ],
            'CONSTRAINTS' => [
                'no_invent_data' => true,
                'one_question_max_if_missing' => true,
                'output_json_only' => true,
                'backward_compatible' => true,
                'if_status_matched_require_business_context' => true,
                'if_status_matched_require_richness_minimums' => [
                    'needs_min' => 5,
                    'documents_min' => 4,
                    'key_entities_min' => 6,
                ],
                'if_status_matched_require_assistance_pack' => true,
                'if_status_matched_require_operator_flow_min' => 5,
                'if_status_matched_require_similarity_signals_min' => 4,
                'if_status_matched_require_training_dialog_min' => 6,
                'if_status_needs_clarification_require_training_gaps' => true,
                'if_status_needs_clarification_require_next_data_questions_min' => 3,
                'if_missing_required_business_information_return_needs_clarification' => true,
                'training_dialog_flow_item_format' => 'escenario | mensaje_usuario | respuesta_asistente | dato_critico',
                'next_data_questions_must_be_closed_options' => true,
                'prioritize_low_tech_user_language' => true,
                'forbid_unknown_scope_labels' => true,
                'avoid_confidence_1_0_without_hard_evidence' => true,
                'avoid_generic_entities_only' => true,
                'allowed_needs_vocabulary' => $allowedNeedsVocabulary,
                'allowed_documents_vocabulary' => $allowedDocumentsVocabulary,
            ],
            'OUTPUT_FORMAT' => [
                'status' => ['type' => 'string', 'enum' => ['MATCHED', 'NEW_BUSINESS', 'NEEDS_CLARIFICATION', 'INVALID_REQUEST']],
                'confidence' => ['type' => 'number', 'minimum' => 0.0, 'maximum' => 1.0],
                'canonical_business_type' => ['type' => 'string'],
                'business_candidate' => ['type' => 'string'],
                'business_objective' => ['type' => 'string'],
                'expected_result' => ['type' => 'string'],
                'reason_short' => ['type' => 'string'],
                'needs_normalized' => ['type' => 'array', 'items' => ['type' => 'string']],
                'documents_normalized' => ['type' => 'array', 'items' => ['type' => 'string']],
                'key_entities' => ['type' => 'array', 'items' => ['type' => 'string']],
                'first_module' => ['type' => 'string'],
                'operator_assistance_flow' => ['type' => 'array', 'items' => ['type' => 'string']],
                'similar_user_signals' => ['type' => 'array', 'items' => ['type' => 'string']],
                'training_dialog_flow' => ['type' => 'array', 'items' => ['type' => 'string']],
                'training_gaps' => ['type' => 'array', 'items' => ['type' => 'string']],
                'next_data_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'clarifying_question' => ['type' => 'string'],
            ],
            'FAIL_RULES' => [
                'if_confidence_below' => 0.7,
                'return_on_low_confidence' => 'NEEDS_CLARIFICATION',
                'if_contract_conflict' => 'INVALID_REQUEST',
                'if_required_business_info_missing' => 'NEEDS_CLARIFICATION',
                'required_output_keys' => $requiredKeys,
            ],
        ];

        $payload = json_encode($promptContract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($payload) ? $payload : '';
    }

    private function buildUnknownBusinessDiscoveryContextText(string $candidate, array $answers): string
    {
        $parts = ['negocio ' . $candidate];
        foreach ($answers as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $answer = trim((string) ($pair['answer'] ?? ''));
            if ($answer === '') {
                continue;
            }
            $parts[] = $answer;
        }
        return $this->normalize(implode(' ', $parts));
    }

    private function buildUnknownBusinessLocalDraft(array $state, string $candidate): array
    {
        $texts = [$this->normalize($candidate)];
        $flow = is_array($state['unknown_business_discovery'] ?? null)
            ? (array) $state['unknown_business_discovery']
            : [];
        $answers = is_array($flow['answers'] ?? null) ? (array) $flow['answers'] : [];
        foreach ($answers as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $answer = $this->normalize((string) ($pair['answer'] ?? ''));
            if ($answer !== '') {
                $texts[] = $answer;
            }
        }

        $needs = [];
        $documents = [];
        foreach ($texts as $itemText) {
            if ($itemText === '') {
                continue;
            }
            $needs = $this->mergeScopeLabels($needs, $this->extractNeedItems($itemText, ''));
            $documents = $this->mergeScopeLabels($documents, $this->extractDocumentItems($itemText));
        }

        if (empty($needs)) {
            $needs = ['inventario', 'ventas', 'pagos'];
        }
        if (empty($documents)) {
            $documents = ['factura', 'orden de trabajo', 'cotizacion'];
        }

        return [
            'needs' => array_values(array_slice($needs, 0, 6)),
            'documents' => array_values(array_slice($documents, 0, 6)),
        ];
    }

    private function stripNegatedBusinessMentions(string $text): string
    {
        $clean = preg_replace('/\\bno\\s+soy\\s+(?:una|un)?\\s*[a-z0-9_\\-\\s]{2,40}(?:,|\\.|;|\\by\\b|\\bpero\\b)?/iu', ' ', $text);
        if (!is_string($clean)) {
            return $text;
        }
        $clean = preg_replace('/\\s+/', ' ', trim($clean)) ?? trim($clean);
        return $clean !== '' ? $clean : $text;
    }

    private function isBusinessTypeRejectedByUser(string $text, string $existingBusinessType): bool
    {
        if ($existingBusinessType === '') {
            return false;
        }
        $normalizedText = $this->normalize($text);
        if (!preg_match('/\b(?:no\s+soy|no\s+es)\s+(?:una|un)?\s*([a-z0-9_\-\s]{2,60})/u', $normalizedText, $match)) {
            return false;
        }
        $negatedChunk = trim((string) ($match[1] ?? ''));
        $negatedChunk = preg_split('/(?:,|\\.|;|\\bpero\\b|\\by\\b)/u', $negatedChunk)[0] ?? $negatedChunk;
        $negatedChunk = trim((string) $negatedChunk);
        if ($negatedChunk === '') {
            return false;
        }

        $needles = [$existingBusinessType];
        $profile = $this->findDomainProfile($this->normalizeBusinessType($existingBusinessType));
        $label = trim((string) ($profile['label'] ?? ''));
        if ($label !== '') {
            $needles[] = $label;
        }
        $aliases = is_array($profile['aliases'] ?? null) ? $profile['aliases'] : [];
        foreach ($aliases as $alias) {
            $needle = trim((string) $alias);
            if ($needle !== '') {
                $needles[] = $needle;
            }
        }

        foreach ($needles as $needle) {
            $normalizedNeedle = $this->normalize((string) $needle);
            if ($normalizedNeedle === '') {
                continue;
            }
            if (str_contains($negatedChunk, $normalizedNeedle) || str_contains($normalizedNeedle, $negatedChunk)) {
                return true;
            }
        }

        $needleTokens = [];
        foreach ($needles as $needle) {
            $parts = preg_split('/[^a-z0-9]+/u', $this->normalize((string) $needle)) ?: [];
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if (strlen($part) >= 5) {
                    $needleTokens[$part] = true;
                }
            }
        }

        if (!empty($needleTokens)) {
            $textTokens = preg_split('/[^a-z0-9]+/u', $negatedChunk) ?: [];
            foreach ($textTokens as $token) {
                $token = trim((string) $token);
                if (strlen($token) < 5) {
                    continue;
                }
                foreach (array_keys($needleTokens) as $needleToken) {
                    if (levenshtein($token, $needleToken) <= 2) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function shouldReprofileBusiness(string $text, string $existingBusinessType, string $detectedBusinessType, string $currentStep): bool
    {
        $existing = $this->normalizeBusinessType($existingBusinessType);
        $detected = $this->normalizeBusinessType($detectedBusinessType);
        if ($existing === '' || $detected === '' || $existing === $detected) {
            return false;
        }
        if ($currentStep === 'business_type') {
            return true;
        }

        $normalizedText = $this->normalize($text);
        return preg_match(
            '/\b(no\s+soy|no\s+es|soy\s+una|soy\s+un|fabrico|fabricamos|me\s+dedico|vendo|vendemos|presto|prestamos|ofrezco|ofrecemos|negocio|empresa|almacen|tienda|app|aplicacion)\b/u',
            $normalizedText
        ) === 1;
    }

    private function findBuilderGuidanceByTopic(array $guides, string $topic): array
    {
        $topic = strtoupper(trim($topic));
        foreach ($guides as $guide) {
            if (!is_array($guide)) {
                continue;
            }
            if (strtoupper(trim((string) ($guide['topic'] ?? ''))) === $topic) {
                return $guide;
            }
        }
        
        

        return [];
    }

    private function renderBuilderGuidanceTemplate(string $template, string $text, array $state, array $lexicon): string
    {
        $tables = $this->detectRelationTablesFromText($text, $state, $lexicon);
        $field = $this->detectGuidanceFieldFromText($text);

        return strtr($template, [
            '{tabla_A}' => $tables['tabla_A'],
            '{tabla_B}' => $tables['tabla_B'],
            '{campo}' => $field,
        ]);
    }

    private function detectRelationTablesFromText(string $text, array $state, array $lexicon): array
    {
        $tableA = '';
        $tableB = '';

        if (preg_match('/(?:conectar|relacionar|vincular|unir)\\s+([a-z0-9_]+)\\s+(?:con|y)\\s+([a-z0-9_]+)/u', $text, $matches) === 1) {
            $tableA = $this->normalizeEntityForSchema((string) ($matches[1] ?? ''));
            $tableB = $this->normalizeEntityForSchema((string) ($matches[2] ?? ''));
        }

        if ($tableA === '') {
            $tableA = $this->normalizeEntityForSchema((string) ($state['entity'] ?? ''));
        }
        if ($tableA === '') {
            $detected = $this->detectEntity($text, $lexicon, $state);
            $tableA = $this->normalizeEntityForSchema($detected);
        }
        if ($tableB === '') {
            if (preg_match('/\\bcon\\s+([a-z0-9_]+)/u', $text, $matches) === 1) {
                $tableB = $this->normalizeEntityForSchema((string) ($matches[1] ?? ''));
            }
        }
        if ($tableB === '' || $tableB === $tableA) {
            $keywordEntity = $this->normalizeEntityForSchema($this->detectEntityKeywordInText($text));
            if ($keywordEntity !== '' && $keywordEntity !== $tableA) {
                $tableB = $keywordEntity;
            }
        }
        if ($tableA === '') {
            $tableA = 'clientes';
        }
        if ($tableB === '' || $tableB === $tableA) {
            $tableB = 'ventas';
        }

        return [
            'tabla_A' => $tableA,
            'tabla_B' => $tableB,
        ];
    }

    private function detectGuidanceEntityFromText(string $text, array $state, array $lexicon): string
    {
        $matches = [];
        if (preg_match('/(?:tabla|lista|entidad)\\s+([a-z0-9_]+)/u', $text, $matches) === 1) {
            $entity = $this->normalizeEntityForSchema((string) ($matches[1] ?? ''));
            if ($entity !== '') {
                return $entity;
            }
        }

        $entity = $this->normalizeEntityForSchema($this->detectEntity($text, $lexicon, $state));
        if ($entity !== '') {
            return $entity;
        }

        $stateEntity = $this->normalizeEntityForSchema((string) ($state['entity'] ?? ''));
        if ($stateEntity !== '') {
            return $stateEntity;
        }

        return 'clientes';
    }

    private function detectGuidanceFieldFromText(string $text): string
    {
        $matches = [];
        if (preg_match('/\\bbusqueda\\s+por\\s+([a-z0-9_]+)/u', $text, $matches) === 1) {
            return $this->normalizeEntityForSchema((string) ($matches[1] ?? '')) ?: 'nombre';
        }
        if (preg_match('/\\bcampo\\s+([a-z0-9_]+)/u', $text, $matches) === 1) {
            return $this->normalizeEntityForSchema((string) ($matches[1] ?? '')) ?: 'nombre';
        }
        if (preg_match('/\\bpor\\s+([a-z0-9_]+)/u', $text, $matches) === 1) {
            return $this->normalizeEntityForSchema((string) ($matches[1] ?? '')) ?: 'nombre';
        }
        return 'nombre';
    }

    private function guidanceFlowHint(string $topic): string
    {
        $topic = strtoupper(trim($topic));
        if ($topic === '') {
            return '';
        }

        $flowKeyMap = [
            'FIELD_TYPE_SELECTION' => 'SECTOR_DISCOVERY_BASE',
            'RELATIONS' => 'SECTOR_DISCOVERY_BASE',
            'MASTER_DETAIL' => 'SECTOR_DISCOVERY_BASE',
            'PERFORMANCE' => 'SECTOR_DISCOVERY_BASE',
            'IMPORT_DATA' => 'SECTOR_DISCOVERY_BASE',
            'REPORTS_DOCS' => 'SECTOR_DISCOVERY_BASE',
            'FE_CO_SETUP' => 'SECTOR_DISCOVERY_BASE',
            'SECURITY_ROLES' => 'SECTOR_DISCOVERY_BASE',
        ];
        $flowKey = (string) ($flowKeyMap[$topic] ?? '');
        if ($flowKey === '') {
            return '';
        }

        $playbook = $this->loadDomainPlaybook();
        $flows = is_array($playbook['guided_conversation_flows'] ?? null) ? $playbook['guided_conversation_flows'] : [];
        foreach ($flows as $flow) {
            if (!is_array($flow)) {
                continue;
            }
            if ((string) ($flow['flow_key'] ?? '') !== $flowKey) {
                continue;
            }
            $steps = is_array($flow['steps'] ?? null) ? $flow['steps'] : [];
            $firstStep = is_array(($steps[0] ?? null)) ? $steps[0] : [];
            $ask = trim((string) ($firstStep['ask'] ?? ''));
            return $ask;
        }
        return '';
    }

    private function routeFlowRuntimeExpiry(string $text, array &$state, string $mode, array $profile): array
    {
        if ($mode !== 'builder') {
            
        

        return [];
        }

        $runtime = $this->normalizeFlowRuntime(is_array($state['flow_runtime'] ?? null) ? $state['flow_runtime'] : []);
        $step = trim((string) ($state['onboarding_step'] ?? ($runtime['current_step'] ?? '')));
        if ($step === '') {
            
        

        return [];
        }

        $lastActivityRaw = (string) ($runtime['last_activity_at'] ?? '');
        if ($lastActivityRaw === '') {
            
        

        return [];
        }

        $lastActivityAt = strtotime($lastActivityRaw);
        if ($lastActivityAt === false) {
            
        

        return [];
        }

        $ttlSeconds = $this->flowRuntimeTtlSeconds();
        if ($ttlSeconds <= 0 || (time() - $lastActivityAt) < $ttlSeconds) {
            
        

        return [];
        }

        $intent = $this->detectFlowControlIntent($text);
        if ($intent !== '') {
            
        

        return [];
        }

        $alreadyNotified = (bool) ($state['flow_expiry_notice_sent'] ?? false);
        if ($alreadyNotified) {
            
        

        return [];
        }

        $runtime['paused'] = true;
        $runtime['expired_at'] = date('c');
        $runtime['last_activity_at'] = date('c');
        $state['flow_runtime'] = $runtime;
        $state['active_task'] = null;
        $state['flow_expiry_notice_sent'] = true;

        return [
            'action' => 'ask_user',
            'intent' => 'FLOW_RUNTIME_EXPIRED',
            'reply' => 'Tu flujo quedo en pausa por inactividad en el paso "' . $step . '".' . "\n"
                . 'Responde "retomar" para continuar o "reiniciar" para empezar de cero.',
            'active_task' => null,
            'collected' => [
                'expired_step' => $step,
                'flow_ttl_seconds' => (string) $ttlSeconds,
            ],
        ];
    }

    private function flowRuntimeTtlSeconds(): int
    {
        $ttlMinutes = (int) (getenv('FLOW_RUNTIME_TTL_MINUTES') ?: 180);
        if ($ttlMinutes < 1) {
            $ttlMinutes = 1;
        }
        return $ttlMinutes * 60;
    }

    private function routeFlowControl(
        string $text,
        array &$state,
        array $profile,
        string $mode,
        string $tenantId,
        string $userId
    ): array {
        $intent = $this->detectFlowControlIntent($text);
        if ($intent === '') {
            
        

        return [];
        }
        $state['flow_expiry_notice_sent'] = false;

        if ($intent === 'cancel') {
            $hadPending = is_array($state['builder_pending_command'] ?? null);
            if ($hadPending) {
                $this->clearBuilderPendingCommand($state);
            }
            if ($mode === 'builder') {
                $runtime = $this->normalizeFlowRuntime(is_array($state['flow_runtime'] ?? null) ? $state['flow_runtime'] : []);
                if (($state['active_task'] ?? '') === 'builder_onboarding' || !empty($state['onboarding_step'])) {
                    $runtime['paused'] = true;
                }
                $runtime['last_activity_at'] = date('c');
                $state['flow_runtime'] = $runtime;
                $state['active_task'] = null;
                $state['requested_slot'] = null;
                $state['missing'] = [];
                $state['feedback_pending'] = null;
                $reply = $hadPending
                    ? 'Listo, cancele la accion pendiente. Cuando quieras seguimos.'
                    : 'Listo, flujo cancelado. Cuando quieras retomamos.';
            } else {
                $state['active_task'] = null;
                $state['requested_slot'] = null;
                $state['missing'] = [];
                $state['feedback_pending'] = null;
                $reply = 'Listo, cancele el flujo actual.';
            }
            return [
                'action' => 'respond_local',
                'intent' => 'FLOW_CONTROL_CANCEL',
                'reply' => $reply,
                'active_task' => $state['active_task'] ?? null,
            ];
        }

        if ($intent === 'restart') {
            if ($mode === 'builder') {
                $this->resetBuilderOnboardingProfile($profile, $tenantId, $userId);
                $this->clearBuilderPendingCommand($state);
                $state['builder_calc_prompt'] = null;
                $state['builder_formula_notes'] = [];
                $state['builder_plan'] = null;
                $state['analysis_approved'] = null;
                $state['proposed_profile'] = null;
                $state['dynamic_playbook'] = null;
                $state['dynamic_playbook_proposal'] = null;
                $state['unknown_business_discovery'] = null;
                $state['unknown_business_force_research'] = false;
                $state['unknown_business_notice_sent'] = false;
                $state['business_resolution_last_candidate'] = null;
                $state['business_resolution_last_status'] = null;
                $state['business_resolution_last_result'] = null;
                $state['business_resolution_last_at'] = null;
                $state['confirm_scope_last_hash'] = null;
                $state['confirm_scope_repeats'] = 0;
                $state['resolution_attempts'] = 0;
                $state['active_task'] = 'builder_onboarding';
                $state['onboarding_step'] = 'business_type';
                $state['entity'] = null;
                $state['collected'] = [];
                $state['missing'] = [];
                $state['requested_slot'] = null;
                $state['flow_runtime'] = $this->flowRuntimeDefaults();
                $state['feedback_pending'] = null;
                return [
                    'action' => 'ask_user',
                    'intent' => 'FLOW_CONTROL_RESTART',
                    'reply' => 'Listo, reinicie el flujo.' . "\n" . $this->buildOnboardingPromptForStep('business_type', $profile, $state),
                    'active_task' => 'builder_onboarding',
                ];
            }

            $state['active_task'] = null;
            $state['requested_slot'] = null;
            $state['missing'] = [];
            return [
                'action' => 'respond_local',
                'intent' => 'FLOW_CONTROL_RESTART',
                'reply' => 'Listo, reinicie esta conversacion.',
                'active_task' => null,
            ];
        }

        if ($intent === 'back') {
            if ($mode !== 'builder') {
                return [
                    'action' => 'respond_local',
                    'intent' => 'FLOW_CONTROL_BACK',
                    'reply' => 'En app no manejo pasos de creacion. Si quieres, digo tu ultimo estado.',
                ];
            }

            if (is_array($state['builder_pending_command'] ?? null)) {
                $this->clearBuilderPendingCommand($state);
                $state['active_task'] = 'builder_onboarding';
                return [
                    'action' => 'ask_user',
                    'intent' => 'FLOW_CONTROL_BACK',
                    'reply' => 'Listo, quite la accion pendiente. Dime que quieres ajustar.',
                    'active_task' => 'builder_onboarding',
                ];
            }

            $previousStep = $this->popPreviousOnboardingStep($state);
            if ($previousStep === '') {
                return [
                    'action' => 'respond_local',
                    'intent' => 'FLOW_CONTROL_BACK',
                    'reply' => 'No tengo un paso anterior para volver.',
                    'active_task' => $state['active_task'] ?? null,
                ];
            }

            $state['active_task'] = 'builder_onboarding';
            $state['onboarding_step'] = $previousStep;
            if ($previousStep !== 'plan_ready') {
                unset($state['analysis_approved']);
            }
            $runtime = $this->normalizeFlowRuntime(is_array($state['flow_runtime'] ?? null) ? $state['flow_runtime'] : []);
            $runtime['current_step'] = $previousStep;
            $runtime['paused'] = false;
            $runtime['last_activity_at'] = date('c');
            $state['flow_runtime'] = $runtime;

            return [
                'action' => 'ask_user',
                'intent' => 'FLOW_CONTROL_BACK',
                'reply' => 'Volvimos al paso anterior.' . "\n" . $this->buildOnboardingPromptForStep($previousStep, $profile, $state),
                'active_task' => 'builder_onboarding',
            ];
        }

        if ($intent === 'resume') {
            if ($mode !== 'builder') {
                $last = is_array($state['last_action'] ?? null) ? (array) $state['last_action'] : [];
                if (!empty($last)) {
                    return [
                        'action' => 'respond_local',
                        'intent' => 'FLOW_CONTROL_RESUME',
                        'reply' => $this->buildLastActionReply($state, $mode),
                    ];
                }
                return [
                    'action' => 'respond_local',
                    'intent' => 'FLOW_CONTROL_RESUME',
                    'reply' => 'No hay flujo pendiente en app. Dime la accion que quieres ejecutar.',
                ];
            }

            if (is_array($state['builder_pending_command'] ?? null)) {
                return [
                    'action' => 'ask_user',
                    'intent' => 'FLOW_CONTROL_RESUME',
                    'reply' => 'Retomamos esta accion pendiente:' . "\n" . $this->buildPendingPreviewReply((array) $state['builder_pending_command']),
                    'active_task' => 'create_table',
                ];
            }

            $runtime = $this->normalizeFlowRuntime(is_array($state['flow_runtime'] ?? null) ? $state['flow_runtime'] : []);
            $step = trim((string) ($state['onboarding_step'] ?? ($runtime['current_step'] ?? '')));
            if ($step === '') {
                return [
                    'action' => 'respond_local',
                    'intent' => 'FLOW_CONTROL_RESUME',
                    'reply' => 'No tengo un flujo pendiente. Si quieres empezamos uno nuevo.',
                    'active_task' => $state['active_task'] ?? null,
                ];
            }

            $state['active_task'] = 'builder_onboarding';
            $state['onboarding_step'] = $step;
            $runtime['paused'] = false;
            $runtime['flow_key'] = $runtime['flow_key'] ?: 'SECTOR_DISCOVERY_BASE';
            $runtime['current_step'] = $step;
            $runtime['last_activity_at'] = date('c');
            $state['flow_runtime'] = $runtime;
            return [
                'action' => 'ask_user',
                'intent' => 'FLOW_CONTROL_RESUME',
                'reply' => 'Retomamos donde quedamos (' . $step . ').' . "\n" . $this->buildOnboardingPromptForStep($step, $profile, $state),
                'active_task' => 'builder_onboarding',
            ];
        }

        
        

        return [];
    }

    private function resetBuilderOnboardingProfile(array $profile, string $tenantId, string $userId): void
    {
        $reset = $profile;
        foreach ([
            'business_type',
            'business_label',
            'business_scope',
            'business_candidate',
            'operation_model',
            'needs_scope',
            'needs_scope_items',
            'documents_scope',
            'documents_scope_items',
        ] as $key) {
            unset($reset[$key]);
        }
        $this->saveProfile($tenantId, $this->profileUserKey($userId), $reset);
    }

    private function detectFlowControlIntent(string $text): string
    {
        $normalized = trim((string) (preg_replace('/\s+/', ' ', strtolower($this->normalize($text))) ?? ''));
        if ($normalized === '') {
            return '';
        }

        $cancelWords = [
            'cancelar',
            'cancelar operacion',
            'olvidalo',
            'ya no',
            'salir',
            'abortar',
            'detener',
            'no quiero hacer esto',
            'dejalo asi',
            'dejarlo asi',
            'menu principal',
        ];
        if (in_array($normalized, $cancelWords, true)) {
            return 'cancel';
        }

        $backWords = [
            'atras',
            'volver',
            'volver atras',
            'regresar',
            'paso anterior',
        ];
        if (in_array($normalized, $backWords, true)) {
            return 'back';
        }

        $restartWords = [
            'reiniciar',
            'empezar de nuevo',
            'volver al inicio',
            'comenzar de cero',
            'reset',
            'limpiar chat',
        ];
        if (in_array($normalized, $restartWords, true)) {
            return 'restart';
        }

        $resumeWords = [
            'retomar',
            'continuar',
            'continuemos',
            'reanudar',
            'resume',
            'seguir donde quede',
            'donde quedamos',
        ];
        if (in_array($normalized, $resumeWords, true)) {
            return 'resume';
        }

        return '';
    }
    private function popPreviousOnboardingStep(array &$state): string
    {
        $runtime = $this->normalizeFlowRuntime(is_array($state['flow_runtime'] ?? null) ? $state['flow_runtime'] : []);
        $history = array_values((array) ($runtime['step_history'] ?? []));
        if (!empty($history)) {
            $previous = (string) array_pop($history);
            $runtime['step_history'] = $history;
            $state['flow_runtime'] = $runtime;
            return $previous;
        }

        $current = trim((string) ($state['onboarding_step'] ?? ($runtime['current_step'] ?? '')));
        $map = [
            'operation_model' => 'business_type',
            'needs_scope' => 'operation_model',
            'documents_scope' => 'needs_scope',
            'confirm_scope' => 'documents_scope',
            'plan_ready' => 'confirm_scope',
        ];
        return (string) ($map[$current] ?? '');
    }

    private function buildOnboardingPromptForStep(string $step, array $profile, array $state): string
    {
        $step = trim($step);
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        return match ($step) {
            'business_type' => 'Paso 1: responde solo una opcion: servicios, productos o ambos.',
            'operation_model' => 'Paso 2: como manejas pagos? contado, credito o mixto.',
            'needs_scope' => 'Paso 3: que necesitas controlar primero en tu negocio?' . "\n"
                . 'Ejemplos: ' . $this->buildNeedsScopeExample($businessType, $profile) . '.',
            'documents_scope' => 'Paso 4: que documentos necesitas usar?' . "\n"
                . 'Ejemplos: ' . $this->buildDocumentsScopeExample($businessType, $profile) . '.',
            'confirm_scope' => $this->buildRequirementsSummaryReply($businessType, $profile, $this->buildBusinessPlan($businessType, $profile)),
            'plan_ready' => $this->buildBuilderPlanProgressReply($state, $profile, true),
            default => 'Dime tu siguiente paso y lo retomamos.',
        };
    }

    private function routeFeedbackLoop(string $text, array &$state, string $mode, string $tenantId, string $userId): array
    {
        if ($mode !== 'builder') {
            
        

        return [];
        }
        if (is_array($state['builder_pending_command'] ?? null)) {
            
        

        return [];
        }
        $pending = is_array($state['feedback_pending'] ?? null) ? (array) $state['feedback_pending'] : [];
        if (empty($pending)) {
            
        

        return [];
        }

        $normalized = trim(preg_replace('/\s+/', ' ', strtolower($text)) ?? strtolower($text));
        $helpful = null;
        if (preg_match('/^(me sirvio|si me sirvio|si sirvio)$/u', $normalized) === 1) {
            $helpful = true;
        } elseif (preg_match('/^(no me sirvio|no sirvio)$/u', $normalized) === 1) {
            $helpful = false;
        }

        if ($helpful === null) {
            if ($this->isQuestionLike($text)) {
                return [
                    'action' => 'ask_user',
                    'intent' => 'FLOW_FEEDBACK_PENDING',
                    'reply' => 'Antes de seguir, confirma feedback de este paso: "me sirvio" o "no me sirvio".',
                    'active_task' => $state['active_task'] ?? null,
                ];
            }
            
        

        return [];
        }

        $entry = [
            'command' => (string) ($pending['command'] ?? ''),
            'entity' => (string) ($pending['entity'] ?? ''),
            'helpful' => $helpful,
            'at' => date('c'),
        ];
        $log = is_array($state['feedback_log'] ?? null) ? (array) $state['feedback_log'] : [];
        $log[] = $entry;
        if (count($log) > 50) {
            $log = array_slice($log, -50);
        }
        $state['feedback_log'] = $log;
        $state['feedback_pending'] = null;

        $stats = $this->memory->getTenantMemory($tenantId, 'flow_feedback_stats', []);
        $commandKey = strtolower((string) ($entry['command'] ?? 'unknown'));
        if (!isset($stats[$commandKey]) || !is_array($stats[$commandKey])) {
            $stats[$commandKey] = ['total' => 0, 'helpful' => 0, 'not_helpful' => 0];
        }
        $stats[$commandKey]['total'] = (int) ($stats[$commandKey]['total'] ?? 0) + 1;
        if ($helpful) {
            $stats[$commandKey]['helpful'] = (int) ($stats[$commandKey]['helpful'] ?? 0) + 1;
        } else {
            $stats[$commandKey]['not_helpful'] = (int) ($stats[$commandKey]['not_helpful'] ?? 0) + 1;
        }
        $this->memory->saveTenantMemory($tenantId, 'flow_feedback_stats', $stats);
        $this->recordFeedbackRuleOverride($tenantId, $commandKey, $helpful, $stats[$commandKey]);

        return [
            'action' => 'respond_local',
            'intent' => 'FLOW_FEEDBACK_CAPTURED',
            'reply' => $helpful
                ? 'Perfecto, gracias por confirmar. Seguimos.'
                : 'Gracias por avisar. Ajusto el siguiente paso para mejorarlo.',
            'collected' => [
                'feedback_command' => (string) ($entry['command'] ?? ''),
                'feedback_helpful' => $helpful ? 'yes' : 'no',
                'feedback_user' => $userId,
            ],
            'active_task' => $state['active_task'] ?? null,
        ];
    }

    private function classifyWithTraining(string $text, array $training, array $profile = []): array
    {
        $intents = $training['intents'] ?? [];
        $entities = $this->extractEntitiesTraining($text, $training);
        $best = ['intent' => null, 'score' => 0, 'action' => null, 'ask' => null];
        $textTokens = $this->tokenizeTraining($text);

        foreach ($intents as $intent) {
            $score = 0.0;
            $utterances = $intent['utterances'] ?? [];
            foreach ($utterances as $utter) {
                $sample = mb_strtolower((string) $utter);
                $sample = preg_replace('/\{[^}]+\}/', '', $sample) ?? $sample;
                $sample = trim($sample);
                if ($sample !== '' && str_contains($text, $sample)) {
                    $score = max($score, 0.9);
                    continue;
                }
                if (!empty($textTokens) && $sample !== '') {
                    $utterTokens = $this->tokenizeTraining($sample);
                    if (!empty($utterTokens)) {
                        $overlap = $this->tokenOverlap($textTokens, $utterTokens);
                        if ($overlap >= 0.5) {
                            $score = max($score, 0.5 + ($overlap * 0.4));
                        } elseif ($overlap >= 0.34) {
                            $score = max($score, 0.45 + ($overlap * 0.3));
                        }
                    }
                }
            }
            if (!empty($intent['required_entities']) && !empty($entities)) {
                $score += 0.05;
            }
            $hardPenalty = $this->hardNegativePenalty(
                $text,
                $textTokens,
                is_array($intent['hard_negatives'] ?? null) ? (array) $intent['hard_negatives'] : []
            );
            if ($hardPenalty > 0.0) {
                $score = max(0.0, $score - $hardPenalty);
            }
            if ($score > 0.98) {
                $score = 0.98;
            }
            if ($score > $best['score']) {
                $best = [
                    'intent' => $intent['name'] ?? null,
                    'score' => $score,
                    'action' => $intent['action'] ?? null,
                    'disambiguation' => $intent['disambiguation_prompt'] ?? null,
                    'required' => $intent['required_entities'] ?? [],
                    'slot_filling' => $intent['slot_filling'] ?? []
                ];
            }
        }

        $missing = false;
        if (!empty($best['required'])) {
            foreach ($best['required'] as $req) {
                if (!array_key_exists($req, $entities)) {
                    $missing = true;
                    break;
                }
            }
        }

        $ask = null;
        if ($missing) {
            $slot = $best['slot_filling'][0] ?? null;
            if (is_array($slot) && !empty($slot['ask'])) {
                $ask = (string) $slot['ask'];
            } elseif (!empty($best['disambiguation'])) {
                $ask = (string) $best['disambiguation'];
            }
        }

        return [
            'intent' => $best['intent'],
            'action' => $best['action'],
            'confidence' => $best['score'],
            'missing_required' => $missing,
            'ask' => $ask,
            'entities' => $entities,
        ];
    }

    private function classifyWithPlaybookIntents(string $text, array $profile = []): array
    {
        $playbook = $this->loadDomainPlaybook();
        $intents = is_array($playbook['solver_intents'] ?? null) ? $playbook['solver_intents'] : [];
        if (empty($intents)) {
            
        

        return [];
        }

        $textTokens = $this->tokenizeTraining($text);
        $profileBusinessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        $best = ['intent' => null, 'score' => 0.0, 'action' => null, 'sector_key' => null];

        foreach ($intents as $intent) {
            if (!is_array($intent)) {
                continue;
            }
            $utterances = is_array($intent['utterances'] ?? null) ? $intent['utterances'] : [];
            $score = 0.0;
            foreach ($utterances as $utter) {
                $sample = $this->normalize((string) $utter);
                if ($sample === '') {
                    continue;
                }
                if (str_contains($text, $sample)) {
                    $score = max($score, 0.92);
                    continue;
                }
                if (empty($textTokens)) {
                    continue;
                }
                $utterTokens = $this->tokenizeTraining($sample);
                if (empty($utterTokens)) {
                    continue;
                }
                $overlap = $this->tokenOverlap($textTokens, $utterTokens);
                if ($overlap >= 0.52) {
                    $score = max($score, 0.5 + ($overlap * 0.45));
                } elseif ($overlap >= 0.36) {
                    $score = max($score, 0.45 + ($overlap * 0.35));
                }
            }

            $sectorKey = strtoupper(trim((string) ($intent['sector_key'] ?? '')));
            $sector = $this->findSectorPlaybook($sectorKey, $playbook);
            $triggers = is_array($sector['triggers'] ?? null) ? $sector['triggers'] : [];
            foreach ($triggers as $trigger) {
                $needle = $this->normalize((string) $trigger);
                if ($needle !== '' && str_contains($text, $needle)) {
                    $score += 0.03;
                }
            }
            $sectorProfile = $this->normalizeBusinessType((string) ($sector['profile_key'] ?? ''));
            if ($sectorProfile !== '' && $profileBusinessType !== '' && $sectorProfile === $profileBusinessType) {
                $score += 0.08;
            }
            $hardPenalty = $this->hardNegativePenalty(
                $text,
                $textTokens,
                is_array($intent['hard_negatives'] ?? null) ? (array) $intent['hard_negatives'] : []
            );
            if ($hardPenalty > 0.0) {
                $score = max(0.0, $score - $hardPenalty);
            }

            if ($score > 0.98) {
                $score = 0.98;
            }
            if ($score > (float) $best['score']) {
                $best = [
                    'intent' => (string) ($intent['name'] ?? ''),
                    'score' => $score,
                    'action' => (string) ($intent['action'] ?? ''),
                    'sector_key' => $sectorKey,
                ];
            }
        }

        if ((string) ($best['intent'] ?? '') === '') {
            
        

        return [];
        }

        return [
            'intent' => (string) $best['intent'],
            'action' => (string) ($best['action'] ?? ''),
            'confidence' => (float) ($best['score'] ?? 0.0),
            'missing_required' => false,
            'ask' => null,
            'entities' => [
                'sector_key' => (string) ($best['sector_key'] ?? ''),
            ],
        ];
    }

    private function routePlaybookAction(
        string $action,
        string $intentName,
        string $text,
        array $profile,
        string $tenantId,
        string $userId,
        string $mode,
        array $state = []
    ): array {
        $sectorKey = $this->sectorKeyByPlaybookAction($action);
        if ($sectorKey === '') {
            
        

        return [];
        }
        $playbook = $this->loadDomainPlaybook();
        $sector = $this->findSectorPlaybook($sectorKey, $playbook);
        if (empty($sector)) {
            $sample = 'requested_action=' . $action . '; source=playbook_router';
            $this->appendResearchTopic($tenantId, $sectorKey . ':playbook_missing', $userId, mb_substr($sample, 0, 220));
            
        

        return [];
        }

        $updatedProfile = $profile;
        $profileBusinessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        $sectorProfile = $this->normalizeBusinessType((string) ($sector['profile_key'] ?? ''));
        if ($sectorProfile !== '' && $profileBusinessType !== $sectorProfile) {
            $updatedProfile['business_type'] = $sectorProfile;
            $this->saveProfile($tenantId, $this->profileUserKey($userId), $updatedProfile);
            $profileBusinessType = $sectorProfile;
        }

        $this->saveSharedPlaybookKnowledge($tenantId, $sectorKey, $intentName, $mode, $text);
        $painPoint = is_array(($sector['pain_points'][0] ?? null)) ? $sector['pain_points'][0] : [];
        $diagnosis = trim((string) ($painPoint['diagnosis'] ?? ''));
        $solutionPitch = trim((string) ($painPoint['solution_pitch'] ?? ''));
        $miniApps = is_array($sector['mini_apps'] ?? null) ? $sector['mini_apps'] : [];
        $miniApp = (string) ($miniApps[0] ?? '');

        $blueprint = is_array($sector['blueprint'] ?? null) ? $sector['blueprint'] : [];
        $blueprintEntities = is_array($blueprint['entities'] ?? null) ? $blueprint['entities'] : [];
        $firstBlueprint = is_array(($blueprintEntities[0] ?? null)) ? $blueprintEntities[0] : [];
        $targetEntity = $this->normalizeEntityForSchema((string) ($firstBlueprint['name'] ?? ''));
        if ($targetEntity === '') {
            $domainProfile = $this->findDomainProfile($profileBusinessType, $playbook);
            $targetEntity = $this->normalizeEntityForSchema((string) (($domainProfile['entities'][0] ?? 'clientes')));
        }
        if ($targetEntity === '') {
            $targetEntity = 'clientes';
        }
        $keyFields = $this->sectorBlueprintFieldPreview($sector, 6);

        $collected = [
            'sector_key' => $sectorKey,
            'business_type' => $profileBusinessType !== '' ? $profileBusinessType : null,
        ];
        if ($mode !== 'builder') {
            $replyLines = [];
            $replyLines[] = 'Detecte este caso de negocio: ' . $this->humanizeSectorKey($sectorKey) . '.';
            if ($diagnosis !== '') {
                $replyLines[] = 'Diagnostico: ' . $diagnosis;
            }
            if ($solutionPitch !== '') {
                $replyLines[] = 'Solucion sugerida: ' . $solutionPitch;
            }
            if ($miniApp !== '') {
                $replyLines[] = 'Mini-app recomendada: ' . str_replace('_', ' ', $miniApp) . '.';
            }
            if (!empty($keyFields)) {
                $replyLines[] = 'Campos clave a configurar: ' . implode(', ', $keyFields) . '.';
            }
            $replyLines[] = 'Siguiente paso: abre el Creador de apps y dime "crear app para ' . strtolower($this->humanizeSectorKey($sectorKey)) . '".';
            return [
                'action' => 'respond_local',
                'reply' => implode("\n", $replyLines),
                'intent' => $intentName,
                'collected' => $collected,
                'active_task' => 'playbook_consulting',
            ];
        }

        $pendingCommand = [
            'command' => 'InstallPlaybook',
            'sector_key' => $sectorKey,
            'dry_run' => true,
        ];
        $feedbackRules = $this->loadTenantTrainingOverrides($tenantId);
        $commandRules = is_array($feedbackRules['feedback_rules']['commands'] ?? null)
            ? $feedbackRules['feedback_rules']['commands']
            : [];
        $installRule = is_array($commandRules['installplaybook'] ?? null) ? $commandRules['installplaybook'] : [];
        $replyLines = [];
        $replyLines[] = 'Entiendo tu necesidad en ' . strtolower($this->humanizeSectorKey($sectorKey)) . '.';
        if ($diagnosis !== '') {
            $replyLines[] = 'Diagnostico: ' . $diagnosis;
        }
        if ($solutionPitch !== '') {
            $replyLines[] = 'Solucion recomendada: ' . $solutionPitch;
        }
        if ($miniApp !== '') {
            $replyLines[] = 'Mini-app sugerida: ' . str_replace('_', ' ', $miniApp) . '.';
        }
        if ((bool) ($installRule['require_extra_confirmation'] ?? false)) {
            $replyLines[] = 'Antes de instalar, te confirmo que no toca datos existentes; solo agrega estructura guiada.';
        }
        $replyLines[] = 'Tengo una plantilla experta para ' . $sectorKey . '. Quieres instalarla?';

        return [
            'action' => 'ask_user',
            'reply' => implode("\n", $replyLines),
            'intent' => $intentName,
            'entity' => $targetEntity !== '' ? $targetEntity : null,
            'pending_command' => $pendingCommand,
            'active_task' => 'install_playbook',
            'collected' => $collected,
        ];
    }

    private function sectorKeyByPlaybookAction(string $action): string
    {
        $action = strtoupper(trim($action));
        $prefix = 'APPLY_PLAYBOOK_';
        if (!str_starts_with($action, $prefix)) {
            return '';
        }
        $sectorKey = substr($action, strlen($prefix));
        if (!is_string($sectorKey)) {
            return '';
        }
        $sectorKey = strtoupper(trim($sectorKey));
        if ($sectorKey === '' || preg_match('/^[A-Z0-9_]+$/', $sectorKey) !== 1) {
            return '';
        }
        return $sectorKey;
    }

    private function findSectorPlaybook(string $sectorKey, array $playbook = []): array
    {
        $sectorKey = strtoupper(trim($sectorKey));
        if ($sectorKey === '') {
            
        

        return [];
        }
        if (empty($playbook)) {
            $playbook = $this->loadDomainPlaybook();
        }
        $sectors = is_array($playbook['sector_playbooks'] ?? null) ? $playbook['sector_playbooks'] : [];
        foreach ($sectors as $sector) {
            if (!is_array($sector)) {
                continue;
            }
            if (strtoupper((string) ($sector['sector_key'] ?? '')) === $sectorKey) {
                return $sector;
            }
        }
        
        

        return [];
    }

    private function humanizeSectorKey(string $sectorKey): string
    {
        $label = strtolower(str_replace('_', ' ', trim($sectorKey)));
        return $label !== '' ? ucfirst($label) : 'Negocio';
    }

    private function sectorBlueprintFieldPreview(array $sector, int $limit = 6): array
    {
        $blueprint = is_array($sector['blueprint'] ?? null) ? $sector['blueprint'] : [];
        $entities = is_array($blueprint['entities'] ?? null) ? $blueprint['entities'] : [];
        $firstEntity = is_array(($entities[0] ?? null)) ? $entities[0] : [];
        $fields = is_array($firstEntity['fields'] ?? null) ? $firstEntity['fields'] : [];
        $preview = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $preview[] = $name;
            if (count($preview) >= $limit) {
                break;
            }
        }
        return $preview;
    }

    private function saveSharedPlaybookKnowledge(string $tenantId, string $sectorKey, string $intentName, string $mode, string $text): void
    {
        $state = $this->memory->getTenantMemory($tenantId, 'agent_shared_knowledge', [
            'sectors' => [],
            'recent' => [],
            'updated_at' => null,
        ]);
        $sectors = is_array($state['sectors'] ?? null) ? $state['sectors'] : [];
        if (!isset($sectors[$sectorKey]) || !is_array($sectors[$sectorKey])) {
            $sectors[$sectorKey] = [
                'hits' => 0,
                'last_intent' => null,
                'last_mode' => null,
                'updated_at' => null,
            ];
        }
        $sectors[$sectorKey]['hits'] = (int) ($sectors[$sectorKey]['hits'] ?? 0) + 1;
        $sectors[$sectorKey]['last_intent'] = $intentName;
        $sectors[$sectorKey]['last_mode'] = $mode;
        $sectors[$sectorKey]['updated_at'] = date('c');

        $recent = is_array($state['recent'] ?? null) ? $state['recent'] : [];
        $recent[] = [
            'ts' => date('c'),
            'sector_key' => $sectorKey,
            'intent' => $intentName,
            'mode' => $mode,
            'text_excerpt' => mb_substr(trim($text), 0, 160),
        ];
        if (count($recent) > 60) {
            $recent = array_slice($recent, -60);
        }

        $state['sectors'] = $sectors;
        $state['recent'] = $recent;
        $state['updated_at'] = date('c');
        $this->memory->saveTenantMemory($tenantId, 'agent_shared_knowledge', $state);
    }

    private function hasCrudSignals(string $text): bool
    {
        if (str_contains($text, '=') || str_contains($text, ':')) {
            return true;
        }
        $verbs = ['crear', 'agregar', 'nuevo', 'listar', 'ver', 'buscar', 'actualizar', 'editar', 'eliminar', 'borrar', 'guardar', 'registrar', 'emitir', 'facturar'];
        foreach ($verbs as $verb) {
            if (str_contains($text, $verb)) {
                return true;
            }
        }
        return false;
    }

    private function routeConfusion(string $text, string $mode, array $state, array $profile, array $confusionBase, string $tenantId = "default", string $userId = "anon"): array
    {
        if (empty($confusionBase) || empty($confusionBase['confusion_sets']) || !is_array($confusionBase['confusion_sets'])) {
            
        

        return [];
        }

        $capabilitiesSet = $this->confusionSetById($confusionBase, 'ASK_CAPABILITIES');
        if ($this->confusionMatches($text, $capabilitiesSet)) {
            return [
                'action' => 'respond_local',
                'reply' => $this->buildCapabilities($profile, [], $mode),
                'intent' => 'APP_CAPABILITIES',
            ];
        }

        $offTopicSet = $this->confusionSetById($confusionBase, 'OFF_TOPIC_GUARD');
        if ($this->confusionMatches($text, $offTopicSet)) {
            return [
                'action' => 'respond_local',
                'reply' => $this->buildOutOfScopeReply($mode),
                'intent' => 'scope_guard',
            ];
        }

        if ($mode === 'app' && $this->hasBuildSignals($text)) {
            return [
                'action' => 'respond_local',
                'reply' => 'Eso se hace en el Creador de apps. Abre el chat creador para crear tablas o formularios.',
                'intent' => 'mode_switch_builder',
            ];
        }

        $isPlaybookBuilderRequest = !empty($this->parseInstallPlaybookRequest($text)['matched']);
        if ($mode === 'builder' && $this->hasRuntimeCrudSignals($text) && !$this->hasBuildSignals($text) && !$isPlaybookBuilderRequest) {
            return [
                'action' => 'respond_local',
                'reply' => 'Estas en el Creador. Aqui definimos estructura. Para registrar datos usa el chat de la app.',
                'intent' => 'mode_switch_app',
            ];
        }



        $pending = is_array($state['builder_pending_command'] ?? null) ? $state['builder_pending_command'] : [];
        if ($this->isBuilderProgressQuestion($text)) {
            return [
                'action' => 'respond_local',
                'reply' => $this->buildBuilderPlanProgressReply($state, $profile, !empty($pending)),
                'intent' => 'builder_progress',
                'active_task' => !empty($pending) ? 'create_table' : (string) ($state['active_task'] ?? 'builder_onboarding'),
            ];
        }

        if (!empty($pending)) {
            $clarifySet = $this->confusionSetById($confusionBase, 'PENDING_CONFIRMATION_CLARIFY');
            if ($this->confusionMatches($text, $clarifySet) || $this->isPendingPreviewQuestion($text)) {
                return [
                    'action' => 'ask_user',
                    'reply' => $this->buildPendingPreviewReply($pending),
                    'intent' => 'pending_preview',
                    'active_task' => 'create_table',
                ];
            }

            $changeSet = $this->confusionSetById($confusionBase, 'PENDING_CONFIRMATION_CHANGE_ENTITY');
            if ($this->confusionMatches($text, $changeSet)) {
                $entity = $this->detectEntityKeywordInText($text);
                if ($entity === '') {
                    $entity = $this->parseEntityFromCrudText($text);
                }
                $entity = $this->adaptEntityToBusinessContext($this->normalizeEntityForSchema($entity), $profile, $text);
                $current = $this->normalizeEntityForSchema((string) ($pending['entity'] ?? ''));
                if ($entity !== '' && $entity !== $current) {
                    $proposal = $this->buildCreateTableProposal($entity, $profile);
                    return [
                        'action' => 'ask_user',
                        'reply' => 'Listo, cambio la tabla propuesta a ' . $proposal['entity'] . '.' . "\n" . $proposal['reply'],
                        'intent' => 'pending_change_entity',
                        'entity' => $proposal['entity'],
                        'pending_command' => $proposal['command'],
                        'active_task' => 'create_table',
                    ];
                }
            }
        }

        if ($mode === 'builder' && (bool) ($state['allow_builder_confusion_llm'] ?? false)) {
            $llmResolution = $this->resolveBuilderEntityConfusionWithLLM($text, $state, $profile, $tenantId, $userId);
            if (!empty($llmResolution)) {
                return $llmResolution;
            }
        }

        $askNextSet = $this->confusionSetById($confusionBase, 'ASK_NEXT_STEP');
        $isStepQuestion = $this->confusionMatches($text, $askNextSet);
        if ($isStepQuestion && empty($pending) && ($state['active_task'] ?? '') === 'builder_onboarding' && !$this->hasBuildSignals($text)) {
            $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
            if ($businessType !== '' && is_array($state['builder_plan'] ?? null)) {
                $proposal = $this->buildNextStepProposal($businessType, (array) $state['builder_plan'], $profile, (string) ($profile['owner_name'] ?? ''), $state);
                if (empty($proposal['command']) || !is_array($proposal['command'])) {
                    return [
                        'action' => 'respond_local',
                        'reply' => (string) ($proposal['reply'] ?? $this->buildBuilderPlanProgressReply($state, $profile, false)),
                        'intent' => 'builder_next_step',
                        'active_task' => (string) ($proposal['active_task'] ?? 'builder_onboarding'),
                    ];
                }
                return [
                    'action' => 'ask_user',
                    'reply' => $proposal['reply'],
                    'intent' => 'builder_next_step',
                    'entity' => $proposal['entity'],
                    'pending_command' => $proposal['command'],
                    'active_task' => (string) ($proposal['active_task'] ?? 'create_table'),
                ];
            }
            return [
                'action' => 'respond_local',
                'reply' => $this->buildBuilderFallbackReply($profile),
                'intent' => 'builder_next_step',
                'active_task' => 'builder_onboarding',
            ];
        }

        
        

        return [];
    }

    private function appendLlmTelemetry(string $reply, array $state): string
    {
        $usage = $state['llm_usage'] ?? null;
        if (!$usage || empty($usage['last'])) return $reply;

        $last = $usage['last'];
        $provider = strtoupper((string) ($last['provider'] ?? 'LLM'));
        $count = (int) ($usage['count'] ?? 0);
        $quality = (string) ($last['quality'] ?? 'Media');
        $quota = $count > 50 ? 'ÃƒÂ¢Ã…Â¡Ã‚Â ÃƒÂ¯Ã‚Â¸Ã‚Â Baja' : 'ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ OK';

        $footer = "\n\n---\n";
        $footer .= "ÃƒÂ°Ã…Â¸Ã‚Â¤Ã¢â‚¬â€œ **Amigo invocado**: {$provider} | ";
        $footer .= "ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã…Â  **Llamada**: #{$count} | ";
        $footer .= "ÃƒÂ¢Ã…Â¡Ã‚Â¡ **Calidad**: {$quality} | ";
        $footer .= "ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ¢â‚¬Â¹ **Cuota**: {$quota}";

        return $reply . $footer;
    }

    private function resolveBuilderEntityConfusionWithLLM(string $text, array $state, array $profile, string $tenantId, string $userId): array
    {
        $hasGemini = trim((string) getenv('GEMINI_API_KEY')) !== '';
                $hasDeepSeek = trim((string) getenv('DEEPSEEK_API_KEY')) !== '';
        
        // 0. Silent Learning: Check if we solved this recently
        $cacheKey = md5(trim(strtolower($text)));
        if (isset($state['llm_usage']['history'][$cacheKey])) {
            return $state['llm_usage']['history'][$cacheKey];
        }
        if (!$hasGemini && !$hasDeepSeek) return [];

        // 1. Build STATE_JSON with PENDING context
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        $plan = is_array($state['builder_plan'] ?? null) ? (array) $state['builder_plan'] : [];
        $progress = $this->computeBuilderPlanProgress($plan, $state);
        $pending = is_array($state['builder_pending_command'] ?? null) ? $state['builder_pending_command'] : [];

        $stateJson = [
            'current_sector' => ($businessType !== '') ? $businessType : 'general',
            'entities_done' => array_values($progress['done_entities']),
            'plan_missing_entities' => array_values($progress['missing_entities']),
            'pending_action' => !empty($pending) ? [
                'command' => $pending['command'] ?? 'None',
                'entity' => $pending['entity'] ?? 'None',
                'current_fields' => $pending['fields'] ?? []
            ] : null,
            'constraints' => is_array($state['constraints_log'] ?? null) ? array_values((array) $state['constraints_log']) : [],
        ];

        // 2. Build ROL DEL AGENTE prompt - Now with full control over pending state
        $promptContract = [
            'ROLE' => 'AGENTE ARQUITECTO DETERMINISTICO (FULL CONTROL)',
            'CONTEXT' => [
                'goal' => 'Gestionar la creacion de la app. El Agente puede: CREAR, MODIFICAR pendientes, o REINICIAR sector.',
                'state_json' => $stateJson,
            ],
            'RULES' => [
                '1_PENDING_HANDLING' => 'Si el user_text rechaza la accion pendiente o propone OTRA tabla, emite patch_type="clear_pending" o "modify_pending".',
                '2_SECTOR_SYNC' => 'Si el rubro mencionado (ej: reposteria) no coincide con current_sector, emite patch_type="change_sector".',
                '3_FIELD_CUSTOMIZATION' => 'Si el usuario pide campos especificos (ej: "que diga de que eps"), emite patch_type="modify_pending" con los nuevos campos.',
            ],
            'OUTPUT_FORMAT' => [
                'intent_classification' => ['type' => 'string', 'enum' => ['DETERMINISTICO', 'AMBIGUO', 'EXPLICATIVO']],
                'phase_1_plan' => [
                    'proposed_reply' => ['type' => 'string'],
                ],
                'phase_2_patch' => [
                    'patch_type' => ['type' => 'string', 'enum' => ['add_entity', 'modify_pending', 'clear_pending', 'change_sector', 'ask_question']],
                    'new_sector' => ['type' => 'string'],
                    'entity' => ['type' => 'string'],
                    'fields' => ['type' => 'array', 'items' => ['type' => 'string', 'description' => 'formato nombre:tipo']],
                ]
            ]
        ];

        $capsule = [
            'intent' => 'BUILDER_JSON_ASSISTANT',
            'user_message' => $text,
            'policy' => ['requires_strict_json' => true, 'max_output_tokens' => 800],
            'prompt_contract' => $promptContract,
        ];

        try {
            $router = new \App\Core\LLM\LLMRouter();
            $llm = $router->chat($capsule, ['mode' => $hasDeepSeek ? 'deepseek' : 'gemini', 'temperature' => 0.1]);
            $llmUsageData = [
                'provider' => $llm['provider'] ?? 'unknown',
                'usage' => $llm['usage'] ?? [],
                'quality' => !empty($llm['json']) ? 'Alta' : 'Baja'
            ];
            $json = is_array($llm['json'] ?? null) ? $llm['json'] : [];
            if (empty($json)) return [];

            $reply = (string) ($json['phase_1_plan']['proposed_reply'] ?? 'Entiendo.');
            $patch = $json['phase_2_patch'] ?? [];
            $patchType = (string) ($patch['patch_type'] ?? 'ask_question');

            // --- Handler: Change Sector ---
            if ($patchType === 'change_sector') {
                $newSector = $this->normalizeBusinessType((string) ($patch['new_sector'] ?? ''));
                if ($newSector !== '') {
                    $profile['business_type'] = $newSector;
                    $this->saveProfile($tenantId, $this->profileUserKey($userId), $profile);
                    $playbook = $this->loadDomainPlaybook();
                    $sectorData = $this->findSectorPlaybook($newSector, $playbook);
                    $newPlan = !empty($sectorData['blueprint']) ? $sectorData['blueprint'] : [];
                    $state['builder_plan'] = $newPlan;
                    $this->clearBuilderPendingCommand($state);
                    $state['active_task'] = 'builder_onboarding';
                    $targetEntity = (string) ($patch['entity'] ?? ($newPlan['entities'][0]['name'] ?? 'clientes'));
                    $proposal = $this->buildCreateTableProposal($targetEntity, $profile);
                    return [ 'llm_telemetry' => $llmUsageData, 'action' => 'ask_user', 'reply' => $reply . "\n\n" . $proposal['reply'],
                        'intent' => 'llm_change_sector',
                        'entity' => $proposal['entity'],
                        'pending_command' => $proposal['command'],
                        'state_patch' => ['builder_plan' => $newPlan, 'active_task' => 'create_table']
                    ];
                }
            }

            // --- Handler: Modify Pending ---
            if ($patchType === 'modify_pending' || $patchType === 'clear_pending') {
                $this->clearBuilderPendingCommand($state);
                if ($patchType === 'clear_pending' || empty($patch['entity'])) {
                    return ['action' => 'ask_user', 'reply' => $reply, 'intent' => 'llm_clear_pending'];
                }
                $newEntity = $this->normalizeEntityForSchema($patch['entity']);
                $fields = is_array($patch['fields'] ?? null) ? $patch['fields'] : [];
                $proposal = $this->buildCreateTableProposal($newEntity, $profile, $fields);
                return [ 'llm_telemetry' => $llmUsageData, 'action' => 'ask_user', 'reply' => $reply . "\n\n" . $proposal['reply'],
                    'intent' => 'llm_modify_pending',
                    'entity' => $proposal['entity'],
                    'pending_command' => $proposal['command'],
                    'active_task' => 'create_table'
                ];
            }

            // --- Handler: Add Entity ---
            if ($patchType === 'add_entity') {
                $entity = (string) ($patch['entity'] ?? '');
                if ($entity !== '') {
                    $proposal = $this->buildCreateTableProposal($entity, $profile);
                    return [ 'llm_telemetry' => $llmUsageData, 'action' => 'ask_user', 'reply' => $reply . "\n\n" . $proposal['reply'],
                        'intent' => 'llm_add_entity', 'entity' => $proposal['entity'],
                        'pending_command' => $proposal['command'], 'active_task' => 'create_table'
                    ];
                }
            }

            return ['action' => 'ask_user', 'reply' => $reply, 'intent' => 'llm_clarify'];
        } catch (\Exception $e) { return []; }
    }

    private function confusionSetById(array $confusionBase, string $id): array
    {
        $sets = is_array($confusionBase['confusion_sets'] ?? null) ? $confusionBase['confusion_sets'] : [];
        foreach ($sets as $set) {
            if (!is_array($set)) {
                continue;
            }
            if ((string) ($set['id'] ?? '') === $id) {
                return $set;
            }
        }
        
        

        return [];
    }

    private function confusionMatches(string $text, array $set): bool
    {
        if (empty($set)) {
            return false;
        }
        $examples = is_array($set['examples'] ?? null) ? $set['examples'] : [];
        foreach ($examples as $example) {
            $needle = $this->normalize((string) $example);
            if ($needle === '') {
                continue;
            }
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function isDataListRequest(string $text): bool
    {
        $patterns = [
            'listar ',
            'lista de',
            'dame ',
            'ver ',
            'muestrame',
            'mostrar ',
            'buscar ',
            'que clientes',
            'que productos',
            'que facturas',
            'que registros',
            'guardados',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isEntityListQuestion(string $text): bool
    {
        $patterns = ['q tablas', 'que tablas', 'tabla?', 'tablas?', 'que entidades', 'q entidades'];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isFormListQuestion(string $text): bool
    {
        $patterns = [
            'q formularios',
            'que formularios',
            'formulario?',
            'formularios?',
            'que forms',
            'q forms',
            'que pantallas',
            'q pantallas',
            'que vistas',
            'q vistas',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function tokenizeTraining(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\\s+/', ' ', trim($text)) ?? $text;
        if ($text === '') {
            
        

        return [];
        }
        $tokens = preg_split('/\\s+/', $text) ?: [];
        return array_values(array_filter($tokens, fn($t) => mb_strlen($t, 'UTF-8') >= 2));
    }

    private function tokenOverlap(array $textTokens, array $utterTokens): float
    {
        $textSet = array_unique($textTokens);
        $utterSet = array_unique($utterTokens);
        if (empty($utterSet)) {
            return 0.0;
        }
        $hits = array_intersect($textSet, $utterSet);
        return count($hits) / max(1, count($utterSet));
    }

    private function hardNegativePenalty(string $text, array $textTokens, array $hardNegatives): float
    {
        if (empty($hardNegatives)) {
            return 0.0;
        }

        $penalty = 0.0;
        foreach ($hardNegatives as $negative) {
            $sample = $this->normalize((string) $negative);
            if ($sample === '') {
                continue;
            }
            if (str_contains($text, $sample)) {
                $penalty = max($penalty, 0.62);
                continue;
            }

            $negTokens = $this->tokenizeTraining($sample);
            if (empty($negTokens) || empty($textTokens)) {
                continue;
            }
            $overlap = $this->tokenOverlap($textTokens, $negTokens);
            if ($overlap >= 0.55) {
                $penalty = max($penalty, 0.35 + ($overlap * 0.35));
            } elseif ($overlap >= 0.40) {
                $penalty = max($penalty, 0.22 + ($overlap * 0.25));
            }
        }

        return min(0.80, $penalty);
    }

    private function isProfileHint(string $text): bool
    {
        $markers = [
            'mi negocio',
            'mi empresa',
            'soy una',
            'soy un',
            'vendo',
            'prefiero respuestas',
        ];
        foreach ($markers as $m) {
            if (str_contains($text, $m)) {
                return true;
            }
        }
        return false;
    }

    private function extractEntitiesTraining(string $text, array $training): array
    {
        $entities = [];
        $defs = $training['entities'] ?? [];
        foreach ($defs as $name => $def) {
            $synonyms = $def['synonyms'] ?? [];
            foreach ($synonyms as $key => $value) {
                $k = mb_strtolower((string) $key);
                if ($k !== '' && str_contains($text, $k)) {
                    $entities[$name] = $value;
                }
            }
        }
        $patterns = $training['patterns'] ?? [];
        foreach ($patterns as $name => $pattern) {
            $regex = '/' . trim((string) $pattern, '/') . '/i';
            if (@preg_match($regex, $text, $match) && !empty($match[0])) {
                $entities[$name] = $match[0];
            }
        }
        return $entities;
    }

    private function updateProfileFromText(array $profile, string $text, string $tenantId, string $userId): array
    {
        $reply = 'Listo, lo tendre en cuenta.';
        $updated = $profile;
        $businessType = $this->detectBusinessType($text);
        if ($businessType !== '') {
            $businessType = $this->normalizeBusinessType($businessType);
            $updated['business_type'] = $businessType;
            $profileData = $this->findDomainProfile($businessType);
            $label = (string) ($profileData['label'] ?? $businessType);
            $reply = 'Listo, te voy guiando con plantilla de ' . $label . '.';
            $hint = $this->buildProfileLearningHint($businessType, $text);
            if ($hint !== '') {
                $reply .= "\n" . $hint;
            }
        }
        if (str_contains($text, 'respuesta corta') || str_contains($text, 'breve')) {
            $updated['preferred_style'] = 'breve';
            $reply = 'Listo, usare respuestas mas cortas.';
        }
        $this->saveProfile($tenantId, $this->profileUserKey($userId), $updated);
        return ['profile' => $updated, 'reply' => $reply];
    }

    private function buildProfileLearningHint(string $businessType, string $text): string
    {
        $businessType = $this->normalizeBusinessType($businessType);
        if ($businessType === '') {
            return '';
        }
        $playbook = $this->loadDomainPlaybook();
        $sectors = is_array($playbook['sector_playbooks'] ?? null) ? $playbook['sector_playbooks'] : [];
        $text = $this->normalize($text);
        foreach ($sectors as $sector) {
            if (!is_array($sector)) {
                continue;
            }
            $profileKey = $this->normalizeBusinessType((string) ($sector['profile_key'] ?? ''));
            if ($profileKey === '' || $profileKey !== $businessType) {
                continue;
            }
            $matches = false;
            $triggers = is_array($sector['triggers'] ?? null) ? $sector['triggers'] : [];
            foreach ($triggers as $trigger) {
                $needle = $this->normalize((string) $trigger);
                if ($needle !== '' && str_contains($text, $needle)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                $painPoints = is_array($sector['pain_points'] ?? null) ? $sector['pain_points'] : [];
                foreach ($painPoints as $painPoint) {
                    if (!is_array($painPoint)) {
                        continue;
                    }
                    $detect = $this->normalize((string) ($painPoint['detect'] ?? ''));
                    if ($detect !== '' && str_contains($text, $detect)) {
                        $matches = true;
                        break;
                    }
                }
            }
            if (!$matches) {
                return '';
            }
            $fields = $this->sectorBlueprintFieldPreview($sector, 6);
            $miniApps = is_array($sector['mini_apps'] ?? null) ? $sector['mini_apps'] : [];
            $miniApp = (string) ($miniApps[0] ?? '');
            $entity = '';
            $blueprint = is_array($sector['blueprint'] ?? null) ? $sector['blueprint'] : [];
            $entities = is_array($blueprint['entities'] ?? null) ? $blueprint['entities'] : [];
            if (is_array($entities[0] ?? null)) {
                $entity = (string) (($entities[0]['name'] ?? '') ?: '');
            }
            $lines = [];
            if ($miniApp !== '') {
                $lines[] = 'Mini-app recomendada: ' . str_replace('_', ' ', $miniApp) . '.';
            }
            if ($entity !== '') {
                $lines[] = 'Te sugiero empezar con la tabla ' . $entity . '.';
            }
            if (!empty($fields)) {
                $lines[] = 'Campos clave: ' . implode(', ', $fields) . '.';
            }
            return implode(' ', $lines);
        }
        return '';
    }

    private function storeMemoryNote(array $profile, string $text, string $tenantId, string $userId): array
    {
        $updated = $profile;
        $note = trim(mb_substr($text, 0, 200));
        if (!isset($updated['notes']) || !is_array($updated['notes'])) {
            $updated['notes'] = [];
        }
        if ($note !== '') {
            $updated['notes'][] = $note;
            $updated['notes'] = array_values(array_unique(array_slice($updated['notes'], -10)));
        }
        $this->saveProfile($tenantId, $this->profileUserKey($userId), $updated);
        return ['profile' => $updated, 'reply' => 'Listo, lo guardo para entenderte mejor.'];
    }

    private function registerUnknownBusinessCase(string $tenantId, string $userId, string $candidate, string $sampleText): void
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return;
        }
        $this->appendResearchTopic(
            $tenantId,
            $candidate,
            $userId,
            mb_substr(trim($sampleText), 0, 220)
        );
    }

    private function parseAuthLogin(string $text, array $state): array
    {
        $collected = $state['collected'] ?? [];
        $pairs = $this->parseKeyValues($text);
        $collected = array_merge($collected, $pairs);

        $user = $collected['usuario'] ?? $collected['user'] ?? $collected['user_id'] ?? null;
        $password = $collected['clave'] ?? $collected['password'] ?? $collected['codigo'] ?? null;

        if (!$user) {
            return ['ask' => 'Cual es tu usuario?', 'collected' => $collected];
        }
        if (!$password) {
            return ['ask' => 'Cual es tu clave o codigo?', 'collected' => $collected];
        }
        return [
            'command' => [
                'command' => 'AuthLogin',
                'user_id' => $user,
                'password' => $password,
            ],
            'collected' => $collected
        ];
    }

    private function parseUserCreate(string $text, array $state): array
    {
        $collected = $state['collected'] ?? [];
        $pairs = $this->parseKeyValues($text);
        $collected = array_merge($collected, $pairs);

        $user = $collected['usuario'] ?? $collected['user'] ?? $collected['user_id'] ?? null;
        $role = $collected['rol'] ?? $collected['role'] ?? null;
        $password = $collected['clave'] ?? $collected['password'] ?? null;

        if (!$user) {
            return ['ask' => 'Como se llamara el usuario?', 'collected' => $collected];
        }
        if (!$role) {
            return ['ask' => 'Que rol tendra (admin, vendedor, contador)?', 'collected' => $collected];
        }
        if (!$password) {
            return ['ask' => 'Que clave tendra?', 'collected' => $collected];
        }

        return [
            'command' => [
                'command' => 'AuthCreateUser',
                'user_id' => $user,
                'role' => $role,
                'password' => $password,
            ],
            'collected' => $collected
        ];
    }

    private function parseKeyValues(string $text): array
    {
        $result = [];
        $tokens = preg_split('/\s+/', $text) ?: [];
        foreach ($tokens as $token) {
            if (str_contains($token, '=') || str_contains($token, ':')) {
                $sep = str_contains($token, '=') ? '=' : ':';
                [$rawKey, $rawVal] = array_pad(explode($sep, $token, 2), 2, '');
                $key = trim($rawKey);
                $val = trim($rawVal);
                if ($key !== '' && $val !== '') {
                    $result[$key] = $val;
                }
            }
        }
        return $result;
    }

    private function autoLearnGlossary(string $tenantId, string $text, string $entity, array $lexicon): void
    {
        if ($entity === '') {
            return;
        }
        $glossary = $this->getGlossary($tenantId);
        if (!is_array($glossary)) {
            $glossary = [];
        }
        if (!isset($glossary['entity_aliases'])) {
            $glossary['entity_aliases'] = [];
        }
        $plural = $entity . 's';
        if (str_contains($text, $plural) && empty($lexicon['entity_aliases'][$plural])) {
            $glossary['entity_aliases'][$plural] = $entity;
        }
        $aliases = ['correo' => 'email', 'celular' => 'telefono', 'cel' => 'telefono', 'doc' => 'nit'];
        foreach ($aliases as $alias => $field) {
            if (str_contains($text, $alias) && empty($lexicon['field_aliases'][$alias])) {
                if (!isset($glossary['field_aliases'])) {
                    $glossary['field_aliases'] = [];
                }
                $glossary['field_aliases'][$alias] = $field;
            }
        }
        $this->saveGlossary($tenantId, $glossary);
    }

    private function mergeLexicon(array $base, array $extra): array
    {
        foreach (['synonyms', 'shortcuts', 'stop_phrases', 'entity_aliases', 'field_aliases'] as $key) {
            if (!isset($base[$key])) {
                $base[$key] = [];
            }
            if (!empty($extra[$key]) && is_array($extra[$key])) {
                $base[$key] = array_merge($base[$key], $extra[$key]);
            }
        }
        return $base;
    }

    private function loadTrainingBase(string $tenantId = 'default'): array
    {
        $path = $this->trainingBasePath();
        $baseMtime = is_file($path) ? (int) @filemtime($path) : 0;
        $overrides = $this->loadTenantTrainingOverrides($tenantId);
        $overrideHash = sha1(json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $cacheKey = $this->safe($tenantId);
        if (isset($this->trainingBaseCache[$cacheKey])) {
            $cached = $this->trainingBaseCache[$cacheKey];
            if (($cached['base_mtime'] ?? 0) === $baseMtime && ($cached['override_hash'] ?? '') === $overrideHash) {
                return $cached['data'] ?? [];
            }
        }
        if (!is_file($path)) {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $training = is_array($decoded) ? $decoded : [];
        $training = $this->applyTrainingOverrides($training, $overrides);
        $this->trainingBaseCache[$cacheKey] = [
            'data' => $training,
            'base_mtime' => $baseMtime,
            'override_hash' => $overrideHash,
        ];
        return $training;
    }

    private function loadConfusionBase(): array
    {
        if ($this->confusionBaseCache !== null) {
            return $this->confusionBaseCache;
        }
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $path = $frameworkRoot . '/contracts/agents/conversation_confusion_base.json';
        if (!is_file($path)) {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $this->confusionBaseCache = is_array($decoded) ? $decoded : [];
        return $this->confusionBaseCache;
    }

    private function confusionNonEntityTokens(): array
    {
        $confusion = $this->loadConfusionBase();
        $tokens = is_array($confusion['rules']['non_entity_tokens'] ?? null)
            ? $confusion['rules']['non_entity_tokens']
            : [];
        return array_values(array_filter(array_map(
            static fn($token) => trim(strtolower((string) $token)),
            $tokens
        )));
    }

    private function loadCountryOverrides(string $tenantId = 'default'): array
    {
        if ($this->countryOverridesCache !== null) {
            return $this->countryOverridesCache;
        }

        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $basePath = $frameworkRoot . '/contracts/agents/country_language_overrides.json';
        $tenantPath = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/country_language_overrides.json';

        $base = $this->readJson($basePath, [
            'global' => ['typo_rules' => [], 'synonyms' => []],
            'countries' => [],
        ]);
        $tenant = $this->memory->getTenantMemory($tenantId, 'country_language_overrides', []);
        if (empty($tenant) && is_file($tenantPath)) {
            $tenant = $this->readJson($tenantPath, []);
            if (!empty($tenant)) {
                $this->memory->saveTenantMemory($tenantId, 'country_language_overrides', $tenant);
            }
        }

        if (!empty($tenant['global']) && is_array($tenant['global'])) {
            $baseGlobal = is_array($base['global'] ?? null) ? $base['global'] : [];
            $tenantGlobal = $tenant['global'];
            $base['global'] = [
                'typo_rules' => array_merge(
                    is_array($baseGlobal['typo_rules'] ?? null) ? $baseGlobal['typo_rules'] : [],
                    is_array($tenantGlobal['typo_rules'] ?? null) ? $tenantGlobal['typo_rules'] : []
                ),
                'synonyms' => array_merge(
                    is_array($baseGlobal['synonyms'] ?? null) ? $baseGlobal['synonyms'] : [],
                    is_array($tenantGlobal['synonyms'] ?? null) ? $tenantGlobal['synonyms'] : []
                ),
            ];
        }

        if (!empty($tenant['countries']) && is_array($tenant['countries'])) {
            if (!is_array($base['countries'] ?? null)) {
                $base['countries'] = [];
            }
            foreach ($tenant['countries'] as $country => $cfg) {
                if (!is_array($cfg)) {
                    continue;
                }
                $country = strtoupper((string) $country);
                $existing = is_array($base['countries'][$country] ?? null) ? $base['countries'][$country] : [];
                $base['countries'][$country] = [
                    'typo_rules' => array_merge(
                        is_array($existing['typo_rules'] ?? null) ? $existing['typo_rules'] : [],
                        is_array($cfg['typo_rules'] ?? null) ? $cfg['typo_rules'] : []
                    ),
                    'synonyms' => array_merge(
                        is_array($existing['synonyms'] ?? null) ? $existing['synonyms'] : [],
                        is_array($cfg['synonyms'] ?? null) ? $cfg['synonyms'] : []
                    ),
                ];
            }
        }

        $this->countryOverridesCache = $base;
        return $this->countryOverridesCache;
    }

    private function resolveCountryCode(array $profile, string $text = ''): string
    {
        $country = strtoupper(trim((string) ($profile['country'] ?? $profile['country_code'] ?? '')));
        if ($country !== '') {
            return $country;
        }
        $text = $this->normalize($text);
        $map = [
            'colombia' => 'CO',
            'mexico' => 'MX',
            'argentina' => 'AR',
            'peru' => 'PE',
            'chile' => 'CL',
            'ecuador' => 'EC',
            'espana' => 'ES',
            'costa rica' => 'CR',
        ];
        foreach ($map as $token => $code) {
            if (str_contains($text, $token)) {
                return $code;
            }
        }
        return 'CO';
    }

    private function trainingBasePath(): string
    {
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        return $frameworkRoot . '/contracts/agents/conversation_training_base.json';
    }

    private function loadTenantTrainingOverrides(string $tenantId): array
    {
        $stored = $this->memory->getTenantMemory($tenantId, 'training_overrides', []);
        if (!empty($stored)) {
            return $stored;
        }

        $path = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/training_overrides.json';
        if (!is_file($path)) {
            $this->confusionBaseCache = [];
            return [];
        }
        $legacy = $this->readJson($path, []);
        if (!empty($legacy)) {
            $this->memory->saveTenantMemory($tenantId, 'training_overrides', $legacy);
        }
        return $legacy;
    }

    private function recordFeedbackRuleOverride(string $tenantId, string $commandKey, bool $helpful, array $stats): void
    {
        $overrides = $this->loadTenantTrainingOverrides($tenantId);
        if (!isset($overrides['feedback_rules']) || !is_array($overrides['feedback_rules'])) {
            $overrides['feedback_rules'] = ['commands' => []];
        }
        if (!isset($overrides['feedback_rules']['commands']) || !is_array($overrides['feedback_rules']['commands'])) {
            $overrides['feedback_rules']['commands'] = [];
        }

        $total = (int) ($stats['total'] ?? 0);
        $helpfulCount = (int) ($stats['helpful'] ?? 0);
        $notHelpfulCount = (int) ($stats['not_helpful'] ?? 0);
        $requireExtra = $total >= 3 && $notHelpfulCount > $helpfulCount;

        $overrides['feedback_rules']['commands'][$commandKey] = [
            'total' => $total,
            'helpful' => $helpfulCount,
            'not_helpful' => $notHelpfulCount,
            'last_helpful' => $helpful,
            'require_extra_confirmation' => $requireExtra,
            'updated_at' => date('c'),
        ];
        $overrides['updated'] = date('Y-m-d');

        $this->memory->saveTenantMemory($tenantId, 'training_overrides', $overrides);
    }

    private function applyTrainingOverrides(array $training, array $overrides): array
    {
        if (empty($overrides)) {
            return $training;
        }
        if (!empty($overrides['intents']) && !empty($training['intents'])) {
            foreach ($training['intents'] as &$intent) {
                $name = (string) ($intent['name'] ?? '');
                if ($name === '' || empty($overrides['intents'][$name]['utterances'])) {
                    continue;
                }
                $existing = $intent['utterances'] ?? [];
                $extras = $overrides['intents'][$name]['utterances'] ?? [];
                if (!is_array($existing)) {
                    $existing = [];
                }
                if (!is_array($extras)) {
                    $extras = [];
                }
                foreach ($extras as $u) {
                    if (!in_array($u, $existing, true)) {
                        $existing[] = $u;
                    }
                }
                $intent['utterances'] = $existing;
            }
            unset($intent);
        }

        if (!empty($overrides['feedback_rules']) && is_array($overrides['feedback_rules'])) {
            $existingRules = is_array($training['feedback_rules'] ?? null) ? $training['feedback_rules'] : [];
            $training['feedback_rules'] = array_replace_recursive($existingRules, $overrides['feedback_rules']);
        }
        return $training;
    }

    private function buildContextCapsule(string $text, array $state, array $lexicon, array $policy, string $classification): array
    {
        $entity = (string) ($state['entity'] ?? '');
        $required = [];
        $types = [];
        if ($entity !== '') {
            try {
                $contract = $this->entities->get($entity);
                foreach (($contract['fields'] ?? []) as $field) {
                    $name = $field['name'] ?? '';
                    if ($name === '') continue;
                    if (!empty($field['required']) && ($field['source'] ?? '') === 'form') {
                        $required[] = $name;
                    }
                    $types[$name] = $field['type'] ?? 'string';
                }
            } catch (RuntimeException $e) {
                $entity = '';
            }
        }

        $requiresJson = $classification !== 'question';
        return [
            'task' => $state['active_task'] ?? null,
            'intent' => $state['intent'] ?? 'question',
            'entity' => $entity,
            'entity_contract_min' => [
                'required' => $required,
                'types' => $types,
            ],
            'state' => [
                'collected' => $state['collected'] ?? [],
                'missing' => $state['missing'] ?? [],
            ],
            'user_message' => $text,
            'policy' => [
                'requires_strict_json' => $requiresJson,
                'latency_budget_ms' => (int) ($policy['latency_budget_ms'] ?? 1200),
                'max_output_tokens' => (int) ($policy['max_output_tokens'] ?? 400),
            ],
        ];
    }

    private function summarizeExecutionData(array $resultData): array
    {
        $summary = [];
        if (isset($resultData['id'])) {
            $summary['id'] = $resultData['id'];
        }
        if (is_array($resultData) && array_is_list($resultData)) {
            $summary['count'] = count($resultData);
            if (!empty($resultData[0]) && is_array($resultData[0])) {
                $summary['sample'] = array_slice($resultData[0], 0, 4, true);
            }
            return $summary;
        }
        foreach (['count', 'updated', 'deleted'] as $field) {
            if (isset($resultData[$field])) {
                $summary[$field] = $resultData[$field];
            }
        }
        return $summary;
    }

    private function telemetry(string $classification, bool $local, array $parsed = []): array
    {
        return [
            'classification' => $classification,
            'resolved_locally' => $local,
            'intent' => $parsed['intent'] ?? null,
            'entity' => $parsed['entity'] ?? null,
            'missing' => $parsed['missing'] ?? [],
        ];
    }

    private function result(string $action, string $reply, ?array $command, ?array $llmRequest, array $state, array $telemetry): array
    {
        $command = is_array($command) ? $command : [];
        $llmRequest = is_array($llmRequest) ? $llmRequest : [];
        $action = $this->normalizeGatewayAction($action, $command, $llmRequest);

        if (!isset($telemetry['routing_hint_steps']) || !is_array($telemetry['routing_hint_steps'])) {
            $telemetry['routing_hint_steps'] = $this->defaultRoutingHintSteps($action);
        }
        if (!array_key_exists('cache_hit', $telemetry)) {
            $telemetry['cache_hit'] = false;
        }
        if (!array_key_exists('rules_hit', $telemetry)) {
            $telemetry['rules_hit'] = $action !== 'send_to_llm';
        }
        if (!array_key_exists('rag_hit', $telemetry)) {
            $telemetry['rag_hit'] = false;
        }

        if (isset($telemetry['llm_telemetry']) || isset($llmRequest['llm_telemetry'])) {
            $llmRes = $telemetry['llm_telemetry'] ?? $llmRequest['llm_telemetry'];
            $usage = $state['llm_usage'] ?? ['count' => 0, 'last' => null, 'history' => []];
            $usage['count']++;
            $usage['last'] = $llmRes;
            $state['llm_usage'] = $usage;
            $reply = $this->appendLlmTelemetry($reply, $state);
        }
        $this->appendShortTermLog('out', $reply, [
            'action' => $action,
            'intent' => (string) ($telemetry['intent'] ?? ''),
            'entity' => (string) ($telemetry['entity'] ?? ''),
            'resolved_locally' => (bool) ($telemetry['resolved_locally'] ?? false),
        ]);
        return [
            'action' => $action,
            'reply' => $reply,
            'command' => $command,
            'llm_request' => $llmRequest,
            'state' => $state,
            'telemetry' => $telemetry,
        ];
    }

    /**
     * @param array<string,mixed> $command
     * @param array<string,mixed> $llmRequest
     */
    private function normalizeGatewayAction(string $action, array $command, array $llmRequest): string
    {
        $action = strtolower(trim($action));
        $allowed = ['respond_local', 'ask_user', 'execute_command', 'send_to_llm', 'error'];
        if (!in_array($action, $allowed, true)) {
            if (!empty($command)) {
                return 'execute_command';
            }
            if (!empty($llmRequest)) {
                return 'send_to_llm';
            }
            return 'respond_local';
        }

        if ($action === 'execute_command' && empty($command)) {
            return 'respond_local';
        }
        if ($action === 'send_to_llm' && empty($llmRequest)) {
            return 'respond_local';
        }

        return $action;
    }

    /**
     * @return array<int,string>
     */
    private function defaultRoutingHintSteps(string $action): array
    {
        return match ($action) {
            'send_to_llm' => ['cache', 'rules', 'skills', 'rag', 'llm'],
            'execute_command' => ['cache', 'rules', 'action_contract'],
            default => ['cache', 'rules'],
        };
    }

    private function updateState(array $state, string $userText, string $reply, ?string $intent, ?string $entity, array $collected, ?string $activeTask, ?array $missing = null): array
    {
        $history = $state['last_messages'] ?? [];
        $history[] = ['u' => $userText, 'a' => $reply, 'ts' => time()];
        if (count($history) > 4) {
            $history = array_slice($history, -4);
        }

        $state['intent'] = $intent ?? ($state['intent'] ?? null);
        $state['entity'] = $entity ?? ($state['entity'] ?? null);
        if ($activeTask !== null) {
            $state['active_task'] = $activeTask === '' ? null : $activeTask;
        }
        if ($missing !== null) {
            $state['missing'] = $missing;
            $state['requested_slot'] = $missing[0] ?? null;
        }
        $state['collected'] = array_merge($state['collected'] ?? [], $collected);
        if (!empty($collected) && isset($state['missing']) && is_array($state['missing'])) {
            $state['missing'] = array_values(array_filter(
                $state['missing'],
                fn($slot) => !array_key_exists((string) $slot, $state['collected'])
            ));
            $state['requested_slot'] = $state['missing'][0] ?? null;
        }
        $state['last_messages'] = $history;
        if (empty($state['summary'])) {
            $state['summary'] = mb_substr($userText, 0, 120);
        }
        return $state;
    }

    private function loadState(string $tenantId, string $userId, string $projectId = 'default', string $mode = 'app'): array
    {
        $default = [
            'active_task' => null,
            'intent' => null,
            'entity' => null,
            'collected' => [],
            'missing' => [],
            'requested_slot' => null,
            'builder_pending_command' => null,
            'pending_loop_counter' => 0,
            'builder_calc_prompt' => null,
            'builder_formula_notes' => [],
            'builder_completed_entities' => [],
            'builder_completed_forms' => [],
            'unknown_business_notice_sent' => false,
            'unknown_business_force_research' => false,
            'proposed_profile' => null,
            'resolution_attempts' => 0,
            'confirm_scope_last_hash' => null,
            'confirm_scope_repeats' => 0,
            'dynamic_playbook_proposal' => null,
            'dynamic_playbook' => null,
            'unknown_business_discovery' => null,
            'business_resolution_last_candidate' => null,
            'business_resolution_last_status' => null,
            'business_resolution_last_result' => null,
            'business_resolution_last_at' => null,
            'last_action' => null,
            'dialog' => null,
            'flow_runtime' => $this->flowRuntimeDefaults(),
            'flow_expiry_notice_sent' => false,
            'feedback_pending' => null,
            'feedback_log' => [],
            'last_messages' => [],
            'llm_usage' => ['count' => 0, 'last' => null, 'history' => []],
            'agentops_trace_history' => [],
            'agentops_last_trace' => null,
            'summary' => null,
        ];

        $userKey = $this->stateUserKey($userId);
        $stateKey = $this->stateMemoryKey($projectId, $mode);
        $stored = $this->memory->getUserMemory($tenantId, $userKey, $stateKey, []);
        if (!empty($stored)) {
            return $this->mergeStateDefaults($default, $stored);
        }

        // Legacy fallback (json files): read once, persist to SQL, then continue in SQL.
        $path = $this->statePath($tenantId, $projectId, $mode, $userId);
        if (is_file($path)) {
            $legacyScoped = $this->readJson($path, $default);
            $merged = $this->mergeStateDefaults($default, $legacyScoped);
            $this->memory->saveUserMemory($tenantId, $userKey, $stateKey, $merged);
            return $merged;
        }
        $legacyPath = $this->legacyStatePath($tenantId, $userId);
        if (is_file($legacyPath)) {
            $legacy = $this->readJson($legacyPath, $default);
            $merged = $this->mergeStateDefaults($default, $legacy);
            $this->memory->saveUserMemory($tenantId, $userKey, $stateKey, $merged);
            return $merged;
        }

        return $default;
    }

    private function saveState(string $tenantId, string $userId, array $state): void
    {
        $state = $this->syncFlowRuntimeState($state);
        $profile = $this->getProfile($tenantId, $this->profileUserKey($userId));
        $state = $this->syncDialogState($state, $this->contextMode, $profile);
        $state['working_memory_validated_at'] = date('c');
        $this->validateAndPersistWorkingMemory($tenantId, $userId, $state, $profile);
        $this->memory->saveUserMemory(
            $tenantId,
            $this->stateUserKey($userId),
            $this->stateMemoryKey($this->contextProjectId, $this->contextMode),
            $state
        );
    }

    private function validateAndPersistWorkingMemory(string $tenantId, string $userId, array $state, array $profile): void
    {
        $snapshot = $this->buildWorkingMemorySnapshot($tenantId, $userId, $state, $profile);
        $schema = $this->workingMemorySchema();
        $payload = json_decode(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!is_object($payload)) {
            throw new RuntimeException('No se pudo preparar working memory para validacion.');
        }
        $validator = new Validator();
        $result = $validator->validate($payload, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? (string) $error->message() : 'Working memory invalida';
            throw new RuntimeException('Working memory invalida: ' . $message);
        }
        $this->memory->saveUserMemory(
            $tenantId,
            $this->stateUserKey($userId),
            $this->workingMemoryKey($this->contextProjectId, $this->contextMode),
            $snapshot
        );
    }

    private function mergeStateDefaults(array $default, array $stored): array
    {
        $merged = array_merge($default, $stored);
        $merged['flow_runtime'] = $this->normalizeFlowRuntime($merged['flow_runtime'] ?? []);
        if (!is_array($merged['feedback_log'] ?? null)) {
            $merged['feedback_log'] = [];
        }
        if (isset($merged['feedback_pending']) && !is_array($merged['feedback_pending']) && $merged['feedback_pending'] !== null) {
            $merged['feedback_pending'] = null;
        }
        if (isset($merged['dynamic_playbook_proposal']) && !is_array($merged['dynamic_playbook_proposal']) && $merged['dynamic_playbook_proposal'] !== null) {
            $merged['dynamic_playbook_proposal'] = null;
        }
        if (isset($merged['dynamic_playbook']) && !is_array($merged['dynamic_playbook']) && $merged['dynamic_playbook'] !== null) {
            $merged['dynamic_playbook'] = null;
        }
        if (isset($merged['unknown_business_discovery']) && !is_array($merged['unknown_business_discovery']) && $merged['unknown_business_discovery'] !== null) {
            $merged['unknown_business_discovery'] = null;
        }
        if (!is_array($merged['agentops_trace_history'] ?? null)) {
            $merged['agentops_trace_history'] = [];
        }
        $merged['agentops_trace_history'] = array_values(array_slice(array_filter(
            $merged['agentops_trace_history'],
            static fn($entry): bool => is_array($entry)
        ), -8));
        if (isset($merged['agentops_last_trace']) && !is_array($merged['agentops_last_trace']) && $merged['agentops_last_trace'] !== null) {
            $merged['agentops_last_trace'] = null;
        }
        $merged['unknown_business_force_research'] = (bool) ($merged['unknown_business_force_research'] ?? false);
        $merged['resolution_attempts'] = (int) ($merged['resolution_attempts'] ?? 0);
        $merged['confirm_scope_repeats'] = (int) ($merged['confirm_scope_repeats'] ?? 0);
        return $merged;
    }

    private function flowRuntimeDefaults(): array
    {
        return [
            'flow_key' => null,
            'current_step' => null,
            'step_history' => [],
            'started_at' => null,
            'last_activity_at' => null,
            'paused' => false,
        ];
    }

    private function normalizeFlowRuntime(array $runtime): array
    {
        $defaults = $this->flowRuntimeDefaults();
        $merged = array_merge($defaults, $runtime);
        if (!is_array($merged['step_history'] ?? null)) {
            $merged['step_history'] = [];
        }
        $merged['step_history'] = array_values(array_filter(
            array_map(static fn($v): string => trim((string) $v), (array) $merged['step_history']),
            static fn(string $v): bool => $v !== ''
        ));
        if (count($merged['step_history']) > 20) {
            $merged['step_history'] = array_slice($merged['step_history'], -20);
        }
        $merged['paused'] = (bool) ($merged['paused'] ?? false);
        return $merged;
    }

    private function syncFlowRuntimeState(array $state): array
    {
        $runtime = $this->normalizeFlowRuntime(is_array($state['flow_runtime'] ?? null) ? $state['flow_runtime'] : []);
        $now = date('c');
        $activeTask = (string) ($state['active_task'] ?? '');
        $onboardingStep = trim((string) ($state['onboarding_step'] ?? ''));
        $shouldTrack = $this->contextMode === 'builder'
            && ($activeTask === 'builder_onboarding' || ($onboardingStep !== '' && !(bool) ($runtime['paused'] ?? false)));
        if ($shouldTrack) {
            $runtime['flow_key'] = 'SECTOR_DISCOVERY_BASE';
            $step = $onboardingStep !== '' ? $onboardingStep : (string) ($runtime['current_step'] ?? 'business_type');
            $prevStep = (string) ($runtime['current_step'] ?? '');
            if ($prevStep !== '' && $prevStep !== $step) {
                $history = (array) ($runtime['step_history'] ?? []);
                $history[] = $prevStep;
                $runtime['step_history'] = array_values(array_slice(array_filter($history, static fn($v): bool => (string) $v !== ''), -20));
            }
            $runtime['current_step'] = $step;
            if (empty($runtime['started_at'])) {
                $runtime['started_at'] = $now;
            }
            $runtime['paused'] = false;
            $runtime['last_activity_at'] = $now;
        } elseif (empty($runtime['last_activity_at'])) {
            $runtime['last_activity_at'] = $now;
        }
        $state['flow_runtime'] = $runtime;
        return $state;
    }

    private function buildWorkingMemorySnapshot(string $tenantId, string $userId, array $state, array $profile): array
    {
        $messages = [];
        $history = is_array($state['last_messages'] ?? null) ? (array) $state['last_messages'] : [];
        foreach (array_slice($history, -4) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            if (isset($turn['role'], $turn['text'])) {
                $messages[] = [
                    'role' => (string) $turn['role'],
                    'text' => (string) $turn['text'],
                ];
                continue;
            }
            $u = trim((string) ($turn['u'] ?? ''));
            $a = trim((string) ($turn['a'] ?? ''));
            if ($u !== '') {
                $messages[] = ['role' => 'user', 'text' => $u];
            }
            if ($a !== '') {
                $messages[] = ['role' => 'assistant', 'text' => $a];
            }
        }

        $actions = $this->contextMode === 'builder'
            ? ['project_status', 'create_entity', 'create_form', 'suggest_fields', 'builder_help']
            : ['app_status', 'create_record', 'query_records', 'read_record', 'update_record', 'delete_record'];

        $pendingConfirmations = [];
        if (is_array($state['builder_pending_command'] ?? null)) {
            $pendingConfirmations[] = (string) (($state['builder_pending_command']['command'] ?? '') ?: 'pending_command');
        }

        $dialog = is_array($state['dialog'] ?? null) ? (array) $state['dialog'] : [];
        $lastAction = is_array($state['last_action'] ?? null) ? (array) $state['last_action'] : [];

        return [
            'meta' => [
                'version' => '1.0',
                'updated_at' => date('c'),
                'tenant_id' => $tenantId !== '' ? $tenantId : 'default',
                'project_id' => $this->contextProjectId !== '' ? $this->contextProjectId : 'default',
                'user_id' => $userId !== '' ? $userId : 'anon',
                'mode' => $this->contextMode === 'builder' ? 'builder' : 'app',
            ],
            'identity' => [
                'business_type' => $profile['business_type'] ?? null,
                'payment_model' => $profile['operation_model'] ?? ($profile['payment_model'] ?? null),
                'language_profile' => is_array($profile['language_profile'] ?? null)
                    ? (array) $profile['language_profile']
                    : ['locale' => 'es-CO'],
            ],
            'conversation' => [
                'active_intent' => $state['intent'] ?? null,
                'requested_slot' => $state['requested_slot'] ?? null,
                'missing_slots' => array_values(array_map('strval', (array) ($state['missing'] ?? []))),
                'last_messages' => $messages,
            ],
            'build_state' => [
                'checklist_step' => $dialog['current_step_id'] ?? null,
                'plan_summary' => $state['summary'] ?? null,
                'pending_confirmations' => array_values(array_filter(array_map('strval', $pendingConfirmations))),
            ],
            'use_state' => [
                'last_entity' => $state['entity'] ?? null,
                'last_action' => isset($lastAction['command']) ? (string) $lastAction['command'] : null,
            ],
            'capabilities' => [
                'entities' => $this->scopedEntityNames(),
                'forms' => $this->scopedFormNames(),
                'actions' => $actions,
            ],
            'safety' => [
                'mode_guard' => true,
                'permission_guard' => true,
                'entity_exists_guard' => true,
            ],
            'telemetry' => [
                'local_resolved_count' => (int) ($state['local_resolved_count'] ?? 0),
                'llm_calls_count' => (int) ($state['llm_calls_count'] ?? 0),
                'loop_guard_hits' => (int) ($state['pending_loop_counter'] ?? 0),
            ],
        ];
    }

    private function workingMemorySchema(): object
    {
        if (is_object($this->workingMemorySchemaCache)) {
            return $this->workingMemorySchemaCache;
        }
        $frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $path = $frameworkRoot . '/contracts/agents/WORKING_MEMORY_SCHEMA.json';
        if (!is_file($path)) {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Schema de working memory vacio.');
        }
        $decoded = json_decode($raw);
        if (!is_object($decoded)) {
            throw new RuntimeException('Schema de working memory invalido.');
        }
        $this->workingMemorySchemaCache = $decoded;
        return $decoded;
    }

    private function loadLexicon(string $tenantId): array
    {
        $default = [
            'synonyms' => [],
            'shortcuts' => [],
            'stop_phrases' => [],
            'entity_aliases' => [
                'cliente' => 'cliente',
                'clientes' => 'cliente',
                'factura' => 'factura',
                'facturas' => 'factura',
            ],
            'field_aliases' => [
                'nit' => 'nit',
                'telefono' => 'telefono',
                'correo' => 'email',
            ],
        ];
        $stored = $this->memory->getTenantMemory($tenantId, 'lexicon', []);
        if (!empty($stored)) {
            return $this->mergeLexicon($default, $stored);
        }

        // Legacy fallback from JSON file, then persist into SQL memory.
        $path = $this->tenantPath($tenantId) . '/lexicon.json';
        if (is_file($path)) {
            $legacy = $this->readJson($path, $default);
            $merged = $this->mergeLexicon($default, $legacy);
            $this->memory->saveTenantMemory($tenantId, 'lexicon', $merged);
            return $merged;
        }

        return $default;
    }

    private function loadPolicy(string $tenantId): array
    {
        $default = [
            'ask_style' => 'short',
            'confirm_delete' => true,
            'max_questions_before_llm' => 2,
            'latency_budget_ms' => 1200,
            'max_output_tokens' => 400,
            'question_templates' => [],
        ];
        $stored = $this->memory->getTenantMemory($tenantId, 'dialog_policy', []);
        if (!empty($stored)) {
            return array_merge($default, $stored);
        }

        // Legacy fallback from JSON file, then persist into SQL memory.
        $path = $this->tenantPath($tenantId) . '/dialog_policy.json';
        if (is_file($path)) {
            $legacy = $this->readJson($path, $default);
            $merged = array_merge($default, $legacy);
            $this->memory->saveTenantMemory($tenantId, 'dialog_policy', $merged);
            return $merged;
        }

        return $default;
    }

    private function loadDomainPlaybook(): array
    {
        if ($this->domainPlaybookCache !== null) {
            return $this->domainPlaybookCache;
        }
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $frameworkPath = $frameworkRoot . '/contracts/agents/domain_playbooks.json';
        if (!is_file($frameworkPath)) {
            $this->domainPlaybookCache = [];
            
        

        return [];
        }
        $raw = file_get_contents($frameworkPath);
        if ($raw === false || $raw === '') {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $base = is_array($decoded) ? $decoded : [];

        $projectPath = $this->projectRoot . '/contracts/knowledge/domain_playbooks.json';
        if (is_file($projectPath)) {
            $projectOverride = $this->readJson($projectPath, []);
            if (!empty($projectOverride)) {
                foreach ([
                    'solver_intents',
                    'sector_playbooks',
                    'knowledge_prompt_template',
                    'builder_guidance',
                    'guided_conversation_flows',
                    'discovery',
                    'unknown_business_protocol',
                ] as $key) {
                    if (isset($projectOverride[$key]) && is_array($projectOverride[$key])) {
                        $base[$key] = $projectOverride[$key];
                    }
                }
                if (isset($projectOverride['meta']) && is_array($projectOverride['meta'])) {
                    $base['meta'] = array_merge(
                        is_array($base['meta'] ?? null) ? $base['meta'] : [],
                        ['project_override' => true]
                    );
                }
            }
        }

        $this->domainPlaybookCache = $base;
        return $this->domainPlaybookCache;
    }

    private function loadAccountingKnowledge(): array
    {
        if ($this->accountingKnowledgeCache !== null) {
            return $this->accountingKnowledgeCache;
        }
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $path = $frameworkRoot . '/contracts/agents/accounting_tax_knowledge_co.json';
        if (!is_file($path)) {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $this->accountingKnowledgeCache = is_array($decoded) ? $decoded : [];
        return $this->accountingKnowledgeCache;
    }

    private function loadUnspscCommon(): array
    {
        if ($this->unspscCommonCache !== null) {
            return $this->unspscCommonCache;
        }
        $frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $path = $frameworkRoot . '/contracts/agents/unspsc_co_common.json';
        if (!is_file($path)) {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $this->unspscCommonCache = is_array($decoded) ? $decoded : [];
        return $this->unspscCommonCache;
    }

    private function matchUnspscItems(string $text, array $knowledge): array
    {
        $items = is_array($knowledge['common_codes'] ?? null) ? $knowledge['common_codes'] : [];
        if (empty($items)) {
            
        

        return [];
        }
        $matches = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $score = 0;
            $lexicalMatch = false;
            $code = (string) ($item['code'] ?? '');
            $name = $this->normalize((string) ($item['name_es'] ?? ''));
            if ($code !== '' && str_contains($text, $code)) {
                $score += 100;
                $lexicalMatch = true;
            }
            if ($name !== '' && str_contains($text, $name)) {
                $score += 40;
                $lexicalMatch = true;
            }
            $aliases = is_array($item['aliases'] ?? null) ? $item['aliases'] : [];
            foreach ($aliases as $alias) {
                $aliasNorm = $this->normalize((string) $alias);
                if ($aliasNorm !== '' && str_contains($text, $aliasNorm)) {
                    $score += 25;
                    $lexicalMatch = true;
                }
            }
            $tags = is_array($item['business_tags'] ?? null) ? $item['business_tags'] : [];
            foreach ($tags as $tag) {
                $tagNorm = $this->normalize(str_replace('_', ' ', (string) $tag));
                if ($tagNorm !== '' && str_contains($text, $tagNorm)) {
                    $score += $lexicalMatch ? 3 : 1;
                }
            }
            if ($score > 0) {
                $item['_score'] = $score;
                $matches[] = $item;
            }
        }

        usort(
            $matches,
            static fn(array $a, array $b): int => ((int) ($b['_score'] ?? 0)) <=> ((int) ($a['_score'] ?? 0))
        );
        return $matches;
    }

    private function recommendedUnspscByBusiness(string $businessType, array $knowledge): array
    {
        $reco = is_array($knowledge['business_type_recommendations'] ?? null) ? $knowledge['business_type_recommendations'] : [];
        $codes = [];
        if ($businessType !== '' && is_array($reco[$businessType] ?? null)) {
            $codes = (array) $reco[$businessType];
        }
        if (empty($codes) && is_array($reco['default'] ?? null)) {
            $codes = (array) $reco['default'];
        }
        if (empty($codes)) {
            
        

        return [];
        }

        $items = is_array($knowledge['common_codes'] ?? null) ? $knowledge['common_codes'] : [];
        $byCode = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = (string) ($item['code'] ?? '');
            if ($code !== '') {
                $byCode[$code] = $item;
            }
        }
        $result = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if ($code !== '' && isset($byCode[$code])) {
                $result[] = $byCode[$code];
            }
        }
        return $result;
    }

    private function mergeAccountingEntities(array $entities, string $businessType, array $accounting, string $operationModel = 'mixto'): array
    {
        $businessType = $this->normalizeBusinessType($businessType);
        $operationModel = $this->normalizeOperationModel($operationModel);
        $base = is_array($accounting['minimum_entities']['default'] ?? null)
            ? $accounting['minimum_entities']['default']
            : [];
        $business = is_array($accounting['minimum_entities_by_business'][$businessType] ?? null)
            ? $accounting['minimum_entities_by_business'][$businessType]
            : [];
        if (empty($business)) {
            if (str_contains($businessType, 'servicio')) {
                $business = is_array($accounting['minimum_entities_by_business']['servicios'] ?? null)
                    ? $accounting['minimum_entities_by_business']['servicios']
                    : [];
            } elseif (str_contains($businessType, 'tienda') || str_contains($businessType, 'producto')) {
                $business = is_array($accounting['minimum_entities_by_business']['productos'] ?? null)
                    ? $accounting['minimum_entities_by_business']['productos']
                    : [];
            }
        }
        $byOperation = is_array($accounting['operation_model_entities'][$operationModel] ?? null)
            ? $accounting['operation_model_entities'][$operationModel]
            : [];
        $all = array_merge($entities, $base, $business, $byOperation);
        $all = array_values(array_filter(array_unique(array_map(static fn($v) => (string) $v, $all))));
        return $all;
    }

    private function projectRegistry(): ?ProjectRegistry
    {
        if ($this->projectRegistry instanceof ProjectRegistry) {
            return $this->projectRegistry;
        }
        try {
            $this->projectRegistry = new ProjectRegistry();
            return $this->projectRegistry;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function scopedEntityNames(): array
    {
        if (is_array($this->scopedEntityNamesCache)) {
            return $this->scopedEntityNamesCache;
        }

        $names = [];
        $registry = $this->projectRegistry();
        if ($registry instanceof ProjectRegistry) {
            try {
                $names = $registry->listEntityNames($this->contextProjectId);
            } catch (\Throwable $e) {
                $names = [];
            }

            if (empty($names)) {
                $hasAnyRegistryEntity = true;
                try {
                    $hasAnyRegistryEntity = $registry->hasAnyEntities();
                } catch (\Throwable $e) {
                    $hasAnyRegistryEntity = true;
                }
                if (!$hasAnyRegistryEntity) {
                    $names = array_map(
                        static fn($path): string => basename((string) $path, '.entity.json'),
                        $this->catalog->entities()
                    );
                }
            }
        } else {
            $names = array_map(
                static fn($path): string => basename((string) $path, '.entity.json'),
                $this->catalog->entities()
            );
        }

        $clean = [];
        foreach ($names as $name) {
            $value = trim((string) $name);
            if ($value !== '') {
                $clean[$value] = true;
            }
        }

        $result = array_keys($clean);
        sort($result, SORT_STRING);
        $this->scopedEntityNamesCache = $result;
        return $result;
    }

    private function scopedFormNames(): array
    {
        if (is_array($this->scopedFormNamesCache)) {
            return $this->scopedFormNamesCache;
        }

        $entitySet = [];
        foreach ($this->scopedEntityNames() as $entity) {
            $normalized = $this->normalizeEntityForSchema((string) $entity);
            if ($normalized !== '') {
                $entitySet[$normalized] = true;
            }
        }

        $forms = [];
        foreach ($this->catalog->forms() as $path) {
            $name = basename((string) $path, '.json');
            if ($name === '') {
                continue;
            }

            $formEntity = '';
            $raw = @file_get_contents((string) $path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $formEntity = $this->normalizeEntityForSchema((string) ($decoded['entity'] ?? ''));
                }
            }
            if ($formEntity === '') {
                $candidate = preg_replace('/\.form$/i', '', $name);
                $formEntity = $this->normalizeEntityForSchema((string) $candidate);
            }

            if (!empty($entitySet) && $formEntity !== '' && isset($entitySet[$formEntity])) {
                $forms[$name] = true;
            }
        }

        $result = array_keys($forms);
        sort($result, SORT_STRING);
        $this->scopedFormNamesCache = $result;
        return $result;
    }

    private function getProfile(string $tenantId, string $profileUserKey): array
    {
        $profile = $this->memory->getUserMemory($tenantId, $profileUserKey, 'profile', []);
        if (!empty($profile)) {
            return $profile;
        }

        $legacyPath = $this->projectRoot
            . '/storage/chat/profiles/'
            . $this->safe($tenantId)
            . '__'
            . $this->safe($profileUserKey)
            . '.json';
        if (!is_file($legacyPath)) {
            
        

        return [];
        }
        $legacy = $this->readJson($legacyPath, []);
        if (!empty($legacy)) {
            $this->memory->saveUserMemory($tenantId, $profileUserKey, 'profile', $legacy);
        }
        return $legacy;
    }

    private function saveProfile(string $tenantId, string $profileUserKey, array $profile): void
    {
        $this->memory->saveUserMemory($tenantId, $profileUserKey, 'profile', $profile);
    }

    private function getGlossary(string $tenantId): array
    {
        $glossary = $this->memory->getTenantMemory($tenantId, 'glossary', []);
        if (!empty($glossary)) {
            return $glossary;
        }

        $legacyPath = $this->projectRoot . '/storage/chat/glossary/' . $this->safe($tenantId) . '.json';
        if (!is_file($legacyPath)) {
            
        

        return [];
        }
        $legacy = $this->readJson($legacyPath, []);
        if (!empty($legacy)) {
            $this->memory->saveTenantMemory($tenantId, 'glossary', $legacy);
        }
        return $legacy;
    }

    private function saveGlossary(string $tenantId, array $glossary): void
    {
        $this->memory->saveTenantMemory($tenantId, 'glossary', $glossary);
    }

    private function appendResearchTopic(string $tenantId, string $topic, string $userId, string $sampleText): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            
        

        return [];
        }

        $queue = $this->memory->getTenantMemory($tenantId, 'research_queue', ['topics' => []]);
        if (empty($queue)) {
            $legacyPath = $this->projectRoot . '/storage/chat/research/' . $this->safe($tenantId) . '.json';
            $playbook = $this->loadDomainPlaybook();
            $unknownProtocol = is_array($playbook['unknown_business_protocol'] ?? null)
                ? (array) $playbook['unknown_business_protocol']
                : [];
            $storePathTemplate = trim((string) ($unknownProtocol['store_path'] ?? ''));
            if ($storePathTemplate !== '') {
                $resolvedPath = str_replace('{tenant}', $this->safe($tenantId), $storePathTemplate);
                if (!preg_match('/^[a-zA-Z]:[\\\\\\/]/', $resolvedPath) && !str_starts_with($resolvedPath, '/')) {
                    $resolvedPath = dirname($this->projectRoot) . '/' . ltrim($resolvedPath, '/');
                }
                $legacyPath = $resolvedPath;
            }
            if (is_file($legacyPath)) {
                $queue = $this->readJson($legacyPath, ['topics' => []]);
            }
        }
        $topics = is_array($queue['topics'] ?? null) ? $queue['topics'] : [];
        $key = mb_strtolower($topic, 'UTF-8');
        $now = date('c');
        $found = null;

        foreach ($topics as $idx => $entry) {
            $entryTopic = mb_strtolower((string) ($entry['topic'] ?? ''), 'UTF-8');
            if ($entryTopic === $key) {
                $found = $idx;
                break;
            }
        }

        if ($found === null) {
            $topics[] = [
                'topic' => $topic,
                'count' => 1,
                'status' => 'pending_research',
                'first_seen' => $now,
                'last_seen' => $now,
                'last_user' => $userId,
                'samples' => [$sampleText],
            ];
            $entry = $topics[array_key_last($topics)];
        } else {
            $entry = $topics[$found];
            $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
            $entry['status'] = (string) ($entry['status'] ?? 'pending_research');
            $entry['last_seen'] = $now;
            $entry['last_user'] = $userId;
            $samples = is_array($entry['samples'] ?? null) ? $entry['samples'] : [];
            if ($sampleText !== '' && !in_array($sampleText, $samples, true)) {
                $samples[] = $sampleText;
            }
            if (count($samples) > 5) {
                $samples = array_slice($samples, -5);
            }
            $entry['samples'] = $samples;
            $topics[$found] = $entry;
        }

        usort(
            $topics,
            static fn(array $a, array $b): int => ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0))
        );
        if (count($topics) > 200) {
            $topics = array_slice($topics, 0, 200);
        }

        $queue['topics'] = array_values($topics);
        $this->memory->saveTenantMemory($tenantId, 'research_queue', $queue);
        return $entry;
    }

    private function appendShortTermLog(string $direction, string $message, array $meta = []): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }
        try {
            $this->memory->appendShortTermMemory(
                $this->contextTenantId,
                $this->stateUserKey($this->contextUserId),
                $this->contextSessionId,
                'chat',
                $direction,
                $message,
                $meta
            );
        } catch (\Throwable $e) {
            // Never block conversation for telemetry storage.
        }
    }

    private function sessionKey(string $tenantId, string $projectId, string $mode, string $userId): string
    {
        return $this->safe($tenantId) . '__' . $this->safe($projectId) . '__' . $this->safe($mode) . '__' . $this->safe($userId);
    }

    private function stateUserKey(string $userId): string
    {
        $safeUser = $this->safe($userId);
        return $safeUser !== '' ? $safeUser : 'anon';
    }

    private function stateMemoryKey(string $projectId, string $mode): string
    {
        $safeProject = $this->safe($projectId);
        $safeMode = $this->safe($mode);
        if ($safeProject === '') {
            $safeProject = 'default';
        }
        if ($safeMode === '') {
            $safeMode = 'app';
        }
        return 'state::' . $safeProject . '::' . $safeMode;
    }

    private function workingMemoryKey(string $projectId, string $mode): string
    {
        $safeProject = $this->safe($projectId);
        $safeMode = $this->safe($mode);
        if ($safeProject === '') {
            $safeProject = 'default';
        }
        if ($safeMode === '') {
            $safeMode = 'app';
        }
        return 'working_memory::' . $safeProject . '::' . $safeMode;
    }

    private function statePath(string $tenantId, string $projectId, string $mode, string $userId): string
    {
        $key = $this->safe($projectId) . '__' . $this->safe($mode) . '__' . $this->safe($userId);
        return $this->tenantPath($tenantId) . '/agent_state/' . $key . '.json';
    }

    private function legacyStatePath(string $tenantId, string $userId): string
    {
        return $this->tenantPath($tenantId) . '/agent_state/' . $this->safe($userId) . '.json';
    }

    private function tenantPath(string $tenantId): string
    {
        $base = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId);
        if (!is_dir($base)) {
            mkdir($base . '/agent_state', 0775, true);
        }
        if (!is_dir($base . '/agent_state')) {
            mkdir($base . '/agent_state', 0775, true);
        }
        return $base;
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->confusionBaseCache = [];
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }


    private function profileUserKey(string $userId): string
    {
        return $this->safe($this->contextProjectId) . '__' . $this->safe($this->contextMode) . '__' . $this->safe($userId);
    }

    private function safe(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $value) ?? 'default';
        return trim($value, '_');
    }

    private function isAlertsCenterOperationalEntity(string $entity): bool
    {
        $entity = mb_strtolower(trim($entity), 'UTF-8');
        return in_array($entity, [
            'tarea',
            'tareas',
            'task',
            'tasks',
            'recordatorio',
            'recordatorios',
            'reminder',
            'reminders',
            'alerta',
            'alertas',
            'alert',
            'alerts',
        ], true);
    }

}
