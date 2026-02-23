<?php
// app/Core/Agents/ConversationGateway.php

namespace App\Core\Agents;

use App\Core\ContractsCatalog;
use App\Core\EntityRegistry;
use App\Core\MemoryRepositoryInterface;
use App\Core\ProjectRegistry;
use App\Core\SqlMemoryRepository;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class ConversationGateway
{
    private string $projectRoot;
    private EntityRegistry $entities;
    private ContractsCatalog $catalog;
    private MemoryRepositoryInterface $memory;
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
        $this->dialogState = new DialogStateEngine();
    }

    public function handle(string $tenantId, string $userId, string $message, string $mode = 'app', string $projectId = 'default'): array
    {
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $userId = $userId !== '' ? $userId : 'anon';
        $mode = strtolower(trim($mode)) === 'builder' ? 'builder' : 'app';
        $this->contextProjectId = $projectId !== '' ? $projectId : 'default';
        $this->contextMode = $mode;
        $this->contextTenantId = $tenantId;
        $this->contextUserId = $userId;
        $this->contextSessionId = $this->sessionKey($tenantId, $this->contextProjectId, $mode, $userId);
        $this->contextProfileUser = $this->profileUserKey($userId);
        $this->scopedEntityNamesCache = null;
        $this->scopedFormNamesCache = null;

        $raw = trim($message);
        $this->appendShortTermLog('in', $raw, [
            'mode' => $mode,
            'project_id' => $this->contextProjectId,
        ]);
        $training = $this->loadTrainingBase($tenantId);
        $normalizedBase = $this->normalize($raw);

        $state = $this->loadState($tenantId, $userId, $this->contextProjectId, $mode);
        $lexicon = $this->loadLexicon($tenantId);
        $glossary = $this->getGlossary($tenantId);
        if (!empty($glossary)) {
            $lexicon = $this->mergeLexicon($lexicon, $glossary);
        }
        $profile = $this->getProfile($tenantId, $this->contextProfileUser);
        $state = $this->syncDialogState($state, $mode, $profile);
        $normalized = $this->normalizeWithTraining($raw, $training, $tenantId, $profile, $mode);
        $policy = $this->loadPolicy($tenantId);
        $confusionBase = $this->loadConfusionBase();

        if ($this->isPureGreeting($normalizedBase)) {
            if ($mode === 'builder') {
                $this->clearBuilderPendingCommand($state);
                $state['active_task'] = 'builder_onboarding';
                $state['missing'] = [];
                $state['requested_slot'] = null;
            }
            $reply = 'Hola, soy Cami. Dime que necesitas crear o consultar.';
            $state = $this->updateState($state, $raw, $reply, null, null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('greeting', true));
        }

        if ($mode === 'builder' && $this->shouldRestartBuilderOnboarding($normalizedBase, $state)) {
            $this->clearBuilderPendingCommand($state);
            $state['active_task'] = 'builder_onboarding';
            $state['entity'] = null;
            $state['collected'] = [];
            $state['missing'] = [];
            $state['requested_slot'] = null;
        }

        if ($mode === 'builder' && $this->shouldResetBuilderFlow($normalizedBase, $state)) {
            $this->clearBuilderPendingCommand($state);
            $state['builder_calc_prompt'] = null;
            $state['active_task'] = 'builder_onboarding';
            $state['entity'] = null;
            $state['collected'] = [];
            $state['missing'] = [];
            $state['requested_slot'] = null;
        }

        if ($this->isOutOfScopeQuestion($normalizedBase, $mode)) {
            if ($mode === 'builder') {
                $this->clearBuilderPendingCommand($state);
                $state['builder_calc_prompt'] = null;
                $state['active_task'] = 'builder_onboarding';
                $state['missing'] = [];
                $state['requested_slot'] = null;
            }
            $reply = $this->buildOutOfScopeReply($mode);
            $state = $this->updateState($state, $raw, $reply, null, null, [], $state['active_task'] ?? null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('scope_guard', true));
        }

        if ($this->isUnspscQuestion($normalizedBase)) {
            $reply = $this->buildUnspscReply($normalizedBase, $profile, $mode);
            $state = $this->updateState($state, $raw, $reply, 'unspsc_help', null, [], $state['active_task'] ?? null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('unspsc_help', true));
        }

        if ($this->isDialogChecklistQuestion($normalizedBase)) {
            $reply = $this->buildDialogChecklistReply($state, $mode, $profile);
            $state = $this->updateState($state, $raw, $reply, 'dialog_checklist', null, [], $state['active_task'] ?? null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('dialog_checklist', true));
        }

        $calcPromptRoute = $this->handleBuilderCalculatedPrompt(
            $normalizedBase,
            $raw,
            $state,
            $profile,
            $tenantId,
            $userId,
            $mode
        );
        if (!empty($calcPromptRoute)) {
            return $calcPromptRoute;
        }

        if ($mode === 'builder' && !empty($state['builder_pending_command']) && is_array($state['builder_pending_command'])) {
            if ($this->isBuilderOnboardingTrigger($normalizedBase)) {
                $this->clearBuilderPendingCommand($state);
                $state['active_task'] = 'builder_onboarding';
                $state['missing'] = [];
                $state['requested_slot'] = null;
                $state['collected'] = [];
                $state['entity'] = null;
            } else {
                $explicitEntityHint = $this->detectEntityKeywordInText($normalizedBase);
                if ((str_contains($normalizedBase, 'crear') || str_contains($normalizedBase, 'hacer') || $this->isAffirmativeReply($normalizedBase))
                    && ((str_contains($normalizedBase, 'tabla') || str_contains($normalizedBase, 'entidad')) || $explicitEntityHint !== '')
                ) {
                    $parsedOverride = $this->parseTableDefinition($normalizedBase);
                    $newEntity = $this->normalizeEntityForSchema((string) ($parsedOverride['entity'] ?? ''));
                    if ($newEntity === '' && $explicitEntityHint !== '') {
                        $newEntity = $this->normalizeEntityForSchema($explicitEntityHint);
                    }
                    if ($newEntity === '') {
                        $newEntity = $this->normalizeEntityForSchema($this->parseEntityFromCrudText($normalizedBase));
                    }
                    $newEntity = $this->adaptEntityToBusinessContext($newEntity, $profile, $normalizedBase);
                    $currentEntity = $this->normalizeEntityForSchema((string) ($state['builder_pending_command']['entity'] ?? ''));
                    if ($newEntity !== '' && $newEntity !== $currentEntity) {
                        $proposal = $this->buildCreateTableProposal($newEntity, $profile);
                        $resolvedEntity = (string) ($proposal['entity'] ?? $newEntity);
                        $this->setBuilderPendingCommand($state, (array) $proposal['command']);
                        $state['entity'] = $resolvedEntity;
                        $reply = 'Listo, cambio la tabla propuesta a ' . $resolvedEntity . '.' . "\n" . $proposal['reply'];
                        $state = $this->updateState($state, $raw, $reply, 'create', $resolvedEntity, [], 'create_table');
                        $this->saveState($tenantId, $userId, $state);
                        return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
                }
                if ($this->isFrustrationMessage($normalizedBase)) {
                    $this->clearBuilderPendingCommand($state);
                    $reply = 'Te entiendo. Vamos a ordenar esto.' . "\n"
                        . 'Dime en una frase que programa quieres crear para tu negocio.';
                    $state = $this->updateState($state, $raw, $reply, 'builder_onboarding', null, [], 'builder_onboarding');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isEntityListQuestion($normalizedBase)) {
                    $reply = $this->buildEntityList() . "\n" . $this->buildPendingPreviewReply($state['builder_pending_command']);
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isBuilderProgressQuestion($normalizedBase)) {
                    $reply = $this->buildBuilderPlanProgressReply($state, $profile, true);
                    $state = $this->updateState($state, $raw, $reply, 'builder_progress', (string) ($state['entity'] ?? null), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('builder_progress', true));
                }
                if ($this->isPendingPreviewQuestion($normalizedBase)) {
                    $reply = $this->buildPendingPreviewReply($state['builder_pending_command']);
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isClarificationRequest($normalizedBase)) {
                    $reply = $this->buildPendingClarificationReply($state['builder_pending_command']);
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isFieldHelpQuestion($normalizedBase)) {
                    $pendingEntity = $this->normalizeEntityForSchema((string) ($state['builder_pending_command']['entity'] ?? ($state['entity'] ?? '')));
                    $proposal = $this->buildCreateTableProposal($pendingEntity !== '' ? $pendingEntity : 'clientes', $profile);
                    $reply = $proposal['reply'];
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isAffirmativeReply($normalizedBase)) {
                    $command = $state['builder_pending_command'];
                    $commandName = (string) ($command['command'] ?? '');
                    $commandEntity = $this->normalizeEntityForSchema((string) ($command['entity'] ?? ''));
                    if ($commandName === 'CreateEntity' && $commandEntity !== '' && $this->entityExists($commandEntity)) {
                        $this->markBuilderCompletedEntity($state, $commandEntity);
                        $this->clearBuilderPendingCommand($state);
                        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
                        $proposal = null;
                        if ($businessType !== '' && is_array($state['builder_plan'] ?? null)) {
                            $proposal = $this->buildNextStepProposal($businessType, (array) $state['builder_plan'], $profile, (string) ($profile['owner_name'] ?? ''), $state);
                        }
                        if (is_array($proposal) && !empty($proposal['command'])) {
                            $this->setBuilderPendingCommand($state, (array) $proposal['command']);
                            $state['entity'] = $proposal['entity'] ?? $state['entity'] ?? null;
                            $reply = 'La tabla ' . $commandEntity . ' ya existe, no la vuelvo a crear.' . "\n" . (string) ($proposal['reply'] ?? '');
                            $activeTask = (string) ($proposal['active_task'] ?? 'create_table');
                            $state = $this->updateState($state, $raw, $reply, 'builder_next_step', (string) ($state['entity'] ?? null), [], $activeTask);
                            $this->saveState($tenantId, $userId, $state);
                            return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                        }

                        $reply = 'La tabla ' . $commandEntity . ' ya existe, no la vuelvo a crear.' . "\n"
                            . $this->buildBuilderPlanProgressReply($state, $profile, false);
                        $state = $this->updateState($state, $raw, $reply, 'builder_progress', $commandEntity, [], 'builder_onboarding');
                        $this->saveState($tenantId, $userId, $state);
                        return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
                    if ($commandName === 'CreateForm' && $commandEntity !== '' && $this->formExistsForEntity($commandEntity)) {
                        $this->markBuilderCompletedForm($state, $commandEntity . '.form');
                        $this->clearBuilderPendingCommand($state);
                        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
                        $proposal = null;
                        if ($businessType !== '' && is_array($state['builder_plan'] ?? null)) {
                            $proposal = $this->buildNextStepProposal($businessType, (array) $state['builder_plan'], $profile, (string) ($profile['owner_name'] ?? ''), $state);
                        }
                        if (is_array($proposal) && !empty($proposal['command']) && is_array($proposal['command'])) {
                            $this->setBuilderPendingCommand($state, (array) $proposal['command']);
                            $state['entity'] = $proposal['entity'] ?? $state['entity'] ?? null;
                            $reply = 'El formulario ' . $commandEntity . '.form ya existe, no lo vuelvo a crear.' . "\n" . (string) ($proposal['reply'] ?? '');
                            $activeTask = (string) ($proposal['active_task'] ?? 'create_table');
                            $state = $this->updateState($state, $raw, $reply, 'builder_next_step', (string) ($state['entity'] ?? null), [], $activeTask);
                            $this->saveState($tenantId, $userId, $state);
                            return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                        }
                        $reply = 'El formulario ' . $commandEntity . '.form ya existe, no lo vuelvo a crear.' . "\n"
                            . $this->buildBuilderPlanProgressReply($state, $profile, false);
                        $state = $this->updateState($state, $raw, $reply, 'builder_progress', $commandEntity, [], 'builder_onboarding');
                        $this->saveState($tenantId, $userId, $state);
                        return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
                    $this->clearBuilderPendingCommand($state);
                    $state = $this->updateState($state, $raw, 'OK', 'create', (string) ($command['entity'] ?? ($state['entity'] ?? null)), [], '', []);
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('execute_command', '', $command, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isNegativeReply($normalizedBase)) {
                    $commandName = (string) (($state['builder_pending_command']['command'] ?? ''));
                    if ($commandName === 'CreateForm') {
                        $entityName = $this->normalizeEntityForSchema((string) ($state['builder_pending_command']['entity'] ?? ($state['entity'] ?? '')));
                        $this->clearBuilderPendingCommand($state);
                        $reply = 'Listo, no creo el formulario ' . ($entityName !== '' ? ($entityName . '.form') : 'pendiente') . '.' . "\n"
                            . 'Dime si seguimos con la siguiente tabla o si quieres otro formulario.';
                        $state = $this->updateState($state, $raw, $reply, 'builder_next_step', $entityName !== '' ? $entityName : null, [], 'builder_onboarding');
                        $this->saveState($tenantId, $userId, $state);
                        return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
                    $entity = (string) ($state['entity'] ?? 'clientes');
                    $entityFromText = $this->detectEntityKeywordInText($normalizedBase);
                    if ($entityFromText === '' && preg_match('/\b(tabla|entidad)\b/u', $normalizedBase) === 1) {
                        $entityFromText = $this->parseEntityFromCrudText($normalizedBase);
                    }
                    if ($entityFromText !== '') {
                        $entity = $this->normalizeEntityForSchema($entityFromText);
                    }
                    $entity = $this->adaptEntityToBusinessContext($entity, $profile, $normalizedBase);
                    $this->clearBuilderPendingCommand($state);
                    $reply = 'Perfecto. Cambiamos la tabla a ' . $entity . '.'
                        . "\n"
                        . 'Ahora dime que datos quieres guardar. Ejemplo: nombre:texto documento:texto telefono:texto';
                    $state = $this->updateState($state, $raw, $reply, 'create', $entity, [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if (!$this->hasFieldPairs($normalizedBase) && !$this->isFieldHelpQuestion($normalizedBase) && !str_contains($normalizedBase, 'tabla')) {
                    if ($this->isNextStepQuestion($normalizedBase)) {
                        $reply = 'Estoy pendiente de esta accion:' . "\n" . $this->buildPendingPreviewReply($state['builder_pending_command']);
                    } elseif ($this->isQuestionLike($normalizedBase)) {
                        $reply = $this->buildPendingClarificationReply($state['builder_pending_command']);
                    } else {
                        $reply = 'Para continuar, responde "si" para crearla o "no" para cambiar nombre/campos.';
                    }
                    $loops = $this->incrementPendingLoopCounter($state);
                    if ($loops >= 3) {
                        $reply = $this->buildHardPendingLoopReply($state);
                    }
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
            }
        }

        if (
            $mode === 'builder'
            && ($state['active_task'] ?? '') === 'create_table'
            && $this->isBuilderOnboardingTrigger($normalized)
            && !str_contains($normalized, ':')
            && !str_contains($normalized, '=')
        ) {
            $state['active_task'] = null;
            $state['missing'] = [];
            $state['entity'] = null;
        }

        $confusionRoute = $this->routeConfusion($normalizedBase, $mode, $state, $profile, $confusionBase);
        if (!empty($confusionRoute)) {
            $reply = (string) ($confusionRoute['reply'] ?? '');
            $state = $this->updateState(
                $state,
                $raw,
                $reply,
                $confusionRoute['intent'] ?? null,
                $confusionRoute['entity'] ?? null,
                $confusionRoute['collected'] ?? [],
                $confusionRoute['active_task'] ?? ($state['active_task'] ?? null)
            );
            if (!empty($confusionRoute['pending_command']) && is_array($confusionRoute['pending_command'])) {
                $this->setBuilderPendingCommand($state, (array) $confusionRoute['pending_command']);
            }
            if (!empty($confusionRoute['state_patch']) && is_array($confusionRoute['state_patch'])) {
                foreach ($confusionRoute['state_patch'] as $k => $v) {
                    $state[$k] = $v;
                }
            }
            $this->saveState($tenantId, $userId, $state);
            return $this->result(
                (string) ($confusionRoute['action'] ?? 'respond_local'),
                $reply,
                $confusionRoute['command'] ?? null,
                null,
                $state,
                $this->telemetry('confusion_router', true, $confusionRoute)
            );
        }

        if (
            $this->isCapabilitiesQuestion($normalizedBase)
            && !(
                $mode === 'builder'
                && in_array((string) ($state['active_task'] ?? ''), ['create_table', 'create_form'], true)
            )
        ) {
            $reply = $this->buildCapabilities($profile, $training, $mode);
            $state = $this->updateState($state, $raw, $reply, 'APP_CAPABILITIES', null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('capabilities', true));
        }

        if ($mode === 'app' && $this->isLastActionQuestion($normalizedBase)) {
            $reply = $this->buildLastActionReply($state, $mode);
            $state = $this->updateState($state, $raw, $reply, $state['intent'] ?? null, $state['entity'] ?? null, [], $state['active_task'] ?? null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('last_action', true));
        }

        if ($mode === 'builder' && $this->isSoftwareBlueprintQuestion($normalizedBase)) {
            $reply = $this->buildSoftwareBlueprintReply($profile);
            $state = $this->updateState($state, $raw, $reply, 'software_blueprint', null, [], 'builder_onboarding');
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('software_blueprint', true));
        }

        if ($mode === 'builder') {
            $onboarding = $this->handleBuilderOnboarding($normalizedBase, $state, $profile, $tenantId, $userId);
            if ($onboarding !== null) {
                $reply = (string) ($onboarding['reply'] ?? 'Listo.');
                $nextState = is_array($onboarding['state'] ?? null) ? $onboarding['state'] : $state;
                $action = (string) ($onboarding['action'] ?? 'ask_user');
                $nextState = $this->updateState(
                    $nextState,
                    $raw,
                    $reply,
                    'builder_onboarding',
                    null,
                    [],
                    $nextState['active_task'] ?? 'builder_onboarding'
                );
                $this->saveState($tenantId, $userId, $nextState);
                return $this->result($action, $reply, null, null, $nextState, $this->telemetry('builder_onboarding', true));
            }
        }

        $trainingRoute = $this->routeTraining($normalized, $training, $profile, $tenantId, $userId, $state, $lexicon, $mode);
        if (
            $mode === 'builder'
            && in_array((string) ($state['active_task'] ?? ''), ['create_table', 'create_form'], true)
            && !empty($trainingRoute)
            && in_array((string) ($trainingRoute['action'] ?? ''), ['respond_local'], true)
        ) {
            $trainingRoute = [];
        }
        if (!empty($trainingRoute)) {
            $reply = $trainingRoute['reply'] ?? '';
            if (($trainingRoute['action'] ?? '') === 'ask_user') {
                if (!empty($trainingRoute['pending_command']) && is_array($trainingRoute['pending_command']) && $mode === 'builder') {
                    $this->setBuilderPendingCommand($state, (array) $trainingRoute['pending_command']);
                }
                $state = $this->updateState($state, $raw, $reply, $trainingRoute['intent'] ?? null, $trainingRoute['entity'] ?? null, $trainingRoute['collected'] ?? [], $trainingRoute['active_task'] ?? null);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('training', true, $trainingRoute));
            }
            if (($trainingRoute['action'] ?? '') === 'respond_local') {
                $state = $this->updateState($state, $raw, $reply, $trainingRoute['intent'] ?? null, $trainingRoute['entity'] ?? null, $trainingRoute['collected'] ?? [], $trainingRoute['active_task'] ?? null);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('training', true, $trainingRoute));
            }
            if (($trainingRoute['action'] ?? '') === 'execute_command' && !empty($trainingRoute['command'])) {
                $state = $this->updateState($state, $raw, 'OK', $trainingRoute['intent'] ?? null, $trainingRoute['entity'] ?? null, $trainingRoute['collected'] ?? [], $trainingRoute['active_task'] ?? '');
                $this->saveState($tenantId, $userId, $state);
                return $this->result('execute_command', '', $trainingRoute['command'], null, $state, $this->telemetry('training', true, $trainingRoute));
            }
        }

        if ($this->isCrudGuideRequest($normalized, $state, $training)) {
            $entity = $this->detectEntity($normalized, $lexicon, $state);
            if ($entity === '') {
                $reply = 'De cual lista? Ej: clientes, productos o facturas.';
                $state = $this->updateState($state, $raw, $reply, 'crud_guide', null, [], 'crud_guide');
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('crud_guide', true));
            }
            $reply = $this->buildCrudGuide($entity);
            $state = $this->updateState($state, $raw, $reply, 'crud_guide', $entity, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('crud_guide', true));
        }

        if ($this->isProfileHint($normalized)) {
            $updated = $this->updateProfileFromText($profile, $normalized, $tenantId, $userId);
            $state = $this->updateState($state, $raw, $updated['reply'], 'profile', null, [], 'profile');
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $updated['reply'], null, null, $state, $this->telemetry('profile', true));
        }

        $classification = $this->classify($normalized);

        if ($mode !== 'builder' && $this->hasBuildSignals($normalized)) {
            $reply = 'Eso se hace en el Creador de apps. Abre el chat creador para crear tablas o formularios.';
            $state = $this->updateState($state, $raw, $reply, null, null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('build_guard', true));
        }
        if ($mode === 'builder' && $this->hasRuntimeCrudSignals($normalized) && !$this->hasBuildSignals($normalized)) {
            $reply = 'Estas en el Creador. Aqui definimos estructura (tablas/formularios). Para registrar datos usa el chat de la app.';
            $state = $this->updateState($state, $raw, $reply, null, null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('use_guard', true));
        }

        if ($mode === 'builder') {
            $build = $this->parseBuild($normalized, $state, $profile);
            if (!empty($build['ask'])) {
                $state = $this->updateState($state, $raw, $build['ask'], null, $build['entity'] ?? null, $build['collected'] ?? [], $build['active_task'] ?? 'build');
                if (!empty($build['pending_command']) && is_array($build['pending_command'])) {
                    $this->setBuilderPendingCommand($state, (array) $build['pending_command']);
                }
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $build['ask'], null, null, $state, $this->telemetry('build', true, $build));
            }
            if (!empty($build['command'])) {
                $this->clearBuilderPendingCommand($state);
                $state = $this->updateState($state, $raw, 'OK', null, $build['entity'] ?? null, $build['collected'] ?? [], '');
                $this->saveState($tenantId, $userId, $state);
                return $this->result('execute_command', '', $build['command'], null, $state, $this->telemetry('build', true, $build));
            }
        }

        if (in_array($classification, ['greeting', 'thanks', 'confirm', 'faq'], true)) {
            $reply = $this->localReply($classification, $mode);
            $state = $this->updateState($state, $raw, $reply, null, null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry($classification, true));
        }

        if ($classification === 'status') {
            $reply = $mode === 'builder' ? $this->buildProjectStatus() : $this->buildAppStatus();
            $state = $this->updateState($state, $raw, $reply, $state['intent'] ?? null, $state['entity'] ?? null, $state['collected'] ?? [], $state['active_task'] ?? null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('status', true));
        }

        $shouldCrud = $classification === 'crud' || (($state['active_task'] ?? '') === 'crud') || !empty($state['missing']);
        if (
            $mode === 'app'
            && $classification === 'crud'
            && $this->isQuestionLike($normalized)
            && !$this->hasFieldPairs($normalized)
            && empty($state['missing'])
        ) {
            $shouldCrud = false;
        }
        if ($shouldCrud) {
            $parsed = $this->parseCrud($normalized, $lexicon, $state, $mode);
            if (!empty($parsed['missing_entity'])) {
                $entityName = (string) ($parsed['entity'] ?? '');
                if ($mode === 'builder') {
                    $reply = 'No existe la tabla ' . $entityName . '. Quieres crearla? Ej: crear tabla ' . $entityName . ' nombre:texto';
                    $state = $this->updateState($state, $raw, $reply, null, $entityName, [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('missing_entity', true, $parsed));
                }
                $reply = 'Esa tabla no existe en esta app. Debe ser agregada por el creador.';
                $state = $this->updateState($state, $raw, $reply, null, null, [], null);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('missing_entity', true, $parsed));
            }
            if (!empty($parsed['ask'])) {
                $state = $this->updateState($state, $raw, $parsed['ask'], $parsed['intent'] ?? null, $parsed['entity'] ?? null, $parsed['collected'] ?? [], 'crud', $parsed['missing'] ?? null);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $parsed['ask'], null, null, $state, $this->telemetry('crud', true, $parsed));
            }

            if (!empty($parsed['command'])) {
                $this->autoLearnGlossary($tenantId, $normalized, $parsed['entity'] ?? '', $lexicon);
                $state = $this->updateState($state, $raw, 'OK', $parsed['intent'] ?? null, $parsed['entity'] ?? null, $parsed['collected'] ?? [], '', []);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('execute_command', '', $parsed['command'], null, $state, $this->telemetry('crud', true, $parsed));
            }
        }

        if ($mode === 'app') {
            $reply = $this->buildAppQuestionReply($normalized, $lexicon, $state, $profile, $training);
            $state = $this->updateState($state, $raw, $reply, null, null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('question_local', true));
        }

        if ($mode === 'builder') {
            $reply = $this->buildBuilderFallbackReply($profile);
            $state = $this->updateState($state, $raw, $reply, null, null, [], 'builder_onboarding');
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('builder_fallback', true));
        }

        $capsule = $this->buildContextCapsule($normalized, $state, $lexicon, $policy, $classification);
        $state = $this->updateState($state, $raw, '', $capsule['intent'] ?? null, $capsule['entity'] ?? null, $capsule['state']['collected'] ?? [], $state['active_task'] ?? null);
        $this->saveState($tenantId, $userId, $state);

        return $this->result('send_to_llm', '', null, $capsule, $state, $this->telemetry('llm', false));
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
        }
        $this->contextProjectId = $projectId;
        $this->contextMode = $mode;
        $this->contextProfileUser = $this->profileUserKey($userId);
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
        $greetings = ['hola', 'buenas', 'buenos dias', 'buen dÃ­a', 'buen dia', 'buenas tardes', 'buenas noches', 'hello', 'saludos'];
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

    private function buildHelp(): string
    {
        $entities = $this->scopedEntityNames();
        $forms = $this->scopedFormNames();

        $lines = [];
        $lines[] = 'Puedo ayudarte a crear y usar la app.';
        $lines[] = 'Ejemplos rapidos:';
        $lines[] = '- crear tabla clientes nombre:texto nit:texto';
        $lines[] = '- crear formulario clientes';
        $lines[] = '- crear cliente nombre=Juan nit=123';
        $lines[] = '- listar cliente';
        $lines[] = 'Tambien puedes pedir: estado del proyecto';
        $lines[] = 'Entidades: ' . (count($entities) ? implode(', ', array_slice($entities, 0, 4)) : 'sin entidades');
        $lines[] = 'Formularios: ' . (count($forms) ? implode(', ', array_slice($forms, 0, 4)) : 'sin formularios');
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
        $active = (string) ($state['active_task'] ?? '');
        $isOnboarding = $active === 'builder_onboarding';
        $trigger = $this->isBuilderOnboardingTrigger($text);
        $businessHint = $this->detectBusinessType($text) !== '';
        if (!$isOnboarding && !$trigger && !$businessHint) {
            return null;
        }

        if ($isOnboarding && $this->isBuilderActionMessage($text) && !$trigger) {
            return null;
        }

        $localProfile = $profile;
        $localState = $state;
        $currentStep = (string) ($localState['onboarding_step'] ?? '');

        $name = $this->extractPersonName($text);
        if ($name !== '') {
            $localProfile['owner_name'] = $name;
        }

        $business = $this->detectBusinessType($text);
        if ($business === '' && (string) ($localState['onboarding_step'] ?? '') === 'business_type') {
            $scopeChoice = $this->detectBusinessScopeChoice($text);
            if ($scopeChoice !== '') {
                $scopeMap = [
                    'servicios' => 'servicios_mantenimiento',
                    'productos' => 'retail_tienda',
                    'ambos' => 'ferreteria',
                ];
                $business = (string) ($scopeMap[$scopeChoice] ?? '');
                if ($business !== '') {
                    $localProfile['business_scope'] = $scopeChoice;
                }
            }
        }
        $existingBusinessType = (string) ($localProfile['business_type'] ?? '');
        $explicitBusinessChange = preg_match('/\b(cambiar|cambia|otro negocio|nuevo negocio)\b/u', $text) === 1;
        if ($business !== '' && ($existingBusinessType === '' || $currentStep === 'business_type' || $explicitBusinessChange)) {
            $localProfile['business_type'] = $business;
            unset($localProfile['business_candidate']);
            unset($localState['unknown_business_notice_sent']);
        }
        $unknownBusinessCandidate = $this->detectUnknownBusinessCandidate($text, $business);
        if ($unknownBusinessCandidate !== '') {
            $localProfile['business_candidate'] = $unknownBusinessCandidate;
            $this->registerUnknownBusinessCase($tenantId, $userId, $unknownBusinessCandidate, $text);
        }
        $operationModel = $this->detectOperationModel($text);
        if ($operationModel !== '') {
            $localProfile['operation_model'] = $operationModel;
        }
        if (str_contains($text, 'no vendo productos')) {
            $localProfile['business_type'] = 'servicios_mantenimiento';
        }

        if (!empty($localProfile)) {
            $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
        }

        $owner = (string) ($localProfile['owner_name'] ?? '');
        $businessType = (string) ($localProfile['business_type'] ?? '');
        if (in_array($businessType, ['mixto', 'contado', 'credito'], true)) {
            $businessType = '';
            unset($localProfile['business_type']);
            $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
        }
        $businessCandidate = trim((string) ($localProfile['business_candidate'] ?? ''));
        if ($businessType === '') {
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = 'business_type';
            $greet = $owner !== '' ? 'Perfecto, ' . $owner . '. ' : 'Perfecto. ';
            $alreadyNotified = (bool) ($localState['unknown_business_notice_sent'] ?? false);
            $isOnboardingQuestion = $isOnboarding
                && ($this->isQuestionLike($text) || $this->isEntityListQuestion($text) || $this->isClarificationRequest($text))
                && !$this->isBuilderActionMessage($text)
                && !$businessHint;
            if ($isOnboardingQuestion) {
                $reply = 'Te explico facil: primero elijo el tipo de negocio para recomendar tablas correctas.' . "\n"
                    . 'Responde una opcion: servicios, productos o ambos.' . "\n"
                    . 'Si vendes y tambien atiendes servicios, responde: ambos.';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            }
            if ($businessCandidate !== '' && !$alreadyNotified) {
                $reply = $greet
                    . 'No tengo plantilla exacta para "' . $businessCandidate . '" todavia. '
                    . 'Ya lo registre para investigarlo y compartirlo con todos los agentes.'
                    . "\n"
                    . 'Paso 1: para empezar rapido, dime si manejas productos, servicios o ambos.';
                $localState['unknown_business_notice_sent'] = true;
            } else {
                $reply = $greet
                    . 'Vamos paso a paso para crear tu app.'
                    . "\n"
                    . 'Paso 1: responde solo una opcion: servicios, productos o ambos.';
            }
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
        }

        if (empty($localProfile['operation_model'])) {
            $captured = [];
            $needsDraft = $this->extractNeedItems($text, $businessType);
            if (!empty($needsDraft)) {
                $currentNeeds = [];
                if (is_array($localProfile['needs_scope_items'] ?? null)) {
                    $currentNeeds = array_values((array) $localProfile['needs_scope_items']);
                } elseif (!empty($localProfile['needs_scope'])) {
                    $currentNeeds = array_map('trim', explode(',', (string) $localProfile['needs_scope']));
                }
                $mergedNeeds = $this->mergeScopeLabels($currentNeeds, $needsDraft);
                $localProfile['needs_scope_items'] = $mergedNeeds;
                $localProfile['needs_scope'] = implode(', ', $mergedNeeds);
                $captured[] = 'control del negocio';
            }
            $docsDraft = $this->extractDocumentItems($text);
            if (!empty($docsDraft)) {
                $currentDocs = [];
                if (is_array($localProfile['documents_scope_items'] ?? null)) {
                    $currentDocs = array_values((array) $localProfile['documents_scope_items']);
                } elseif (!empty($localProfile['documents_scope'])) {
                    $currentDocs = array_map('trim', explode(',', (string) $localProfile['documents_scope']));
                }
                $mergedDocs = $this->mergeScopeLabels($currentDocs, $docsDraft);
                $localProfile['documents_scope_items'] = $mergedDocs;
                $localProfile['documents_scope'] = implode(', ', $mergedDocs);
                $captured[] = 'documentos';
            }
            if (!empty($captured)) {
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
            }
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = 'operation_model';
            $reply = 'Perfecto. Paso 2: como manejas pagos? contado, credito o mixto.';
            if (!empty($captured)) {
                $reply = 'Ya tome nota de ' . implode(' y ', $captured) . '.' . "\n" . $reply;
            }
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
        }

        $currentStep = (string) ($localState['onboarding_step'] ?? '');
        $needsItemsDetected = $currentStep === 'needs_scope' ? $this->extractNeedItems($text, $businessType) : [];
        $documentsItemsDetected = $currentStep === 'documents_scope' ? $this->extractDocumentItems($text) : [];
        $canCaptureAnswer = !$this->isQuestionLike($text)
            && !$this->isBuilderActionMessage($text)
            && !$this->isAffirmativeReply($text)
            && !$this->isNegativeReply($text);
        if (!empty($needsItemsDetected) || !empty($documentsItemsDetected)) {
            $canCaptureAnswer = true;
        }

        if ($currentStep === 'needs_scope' && $canCaptureAnswer) {
            $needsItems = !empty($needsItemsDetected) ? $needsItemsDetected : $this->extractNeedItems($text, $businessType);
            if ($this->isReferenceToPreviousScope($text) && !empty($localProfile['needs_scope'])) {
                $localProfile['needs_scope'] = (string) $localProfile['needs_scope'];
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
            } elseif ($this->isOnboardingMetaAnswer($text) && empty($needsItems)) {
                $reply = 'En este paso necesito que me digas que quieres controlar primero.' . "\n"
                    . 'Ejemplo: ' . $this->buildNeedsScopeExample($businessType, $localProfile) . '.';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            } elseif (!empty($needsItems)) {
                $localProfile['needs_scope_items'] = $needsItems;
                $localProfile['needs_scope'] = implode(', ', $needsItems);
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
            } else {
                $localProfile['needs_scope'] = $this->sanitizeRequirementText($text);
                unset($localProfile['needs_scope_items']);
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
            }
        }
        if (empty($localProfile['needs_scope'])) {
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = 'needs_scope';
            $reply = 'Paso 3: que necesitas controlar primero en tu negocio?' . "\n"
                . 'Ejemplos: citas, ordenes de trabajo, inventario, facturacion, pagos.';
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
        }

        if ($currentStep === 'documents_scope' && $canCaptureAnswer) {
            $documentItems = !empty($documentsItemsDetected) ? $documentsItemsDetected : $this->extractDocumentItems($text);
            if ($this->isReferenceToPreviousScope($text) && !empty($localProfile['documents_scope'])) {
                $localProfile['documents_scope'] = (string) $localProfile['documents_scope'];
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
            } elseif ($this->isOnboardingMetaAnswer($text) && empty($documentItems)) {
                $reply = 'En este paso necesito los documentos que vas a usar.' . "\n"
                    . 'Ejemplo: ' . $this->buildDocumentsScopeExample($businessType, $localProfile) . '.';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            } elseif (!empty($documentItems)) {
                $localProfile['documents_scope_items'] = $documentItems;
                $localProfile['documents_scope'] = implode(', ', $documentItems);
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
            } else {
                $localProfile['documents_scope'] = $this->sanitizeRequirementText($text);
                unset($localProfile['documents_scope_items']);
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
            }
        }
        if (empty($localProfile['documents_scope'])) {
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = 'documents_scope';
            $reply = 'Paso 4: que documentos necesitas usar?' . "\n"
                . 'Ejemplos: factura, orden de trabajo, historia clinica, cotizacion, recibo de pago.';
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
        }

        $plan = $this->buildBusinessPlan($businessType, $localProfile);
        $localState['builder_plan'] = $plan;
        $localState['active_task'] = 'builder_onboarding';
        $onboardingStep = (string) ($localState['onboarding_step'] ?? '');

        if ($onboardingStep === 'confirm_scope') {
            if (!$this->isAffirmativeReply($text) && !$this->isNegativeReply($text)) {
                $adjusted = false;
                $needsAdjust = $this->extractNeedItems($text, $businessType);
                $documentsAdjust = $this->extractDocumentItems($text);
                $allowNeedsAdjust = preg_match('/\b(controlar|controles|flujo|proceso|procesos|operacion|operaciones)\b/u', $text) === 1;
                if (!empty($needsAdjust) && ($allowNeedsAdjust || empty($documentsAdjust))) {
                    $currentNeeds = [];
                    if (is_array($localProfile['needs_scope_items'] ?? null)) {
                        $currentNeeds = array_values((array) $localProfile['needs_scope_items']);
                    } elseif (!empty($localProfile['needs_scope'])) {
                        $currentNeeds = array_map('trim', explode(',', (string) $localProfile['needs_scope']));
                    }
                    $mergedNeeds = $this->mergeScopeLabels($currentNeeds, $needsAdjust);
                    $localProfile['needs_scope_items'] = $mergedNeeds;
                    $localProfile['needs_scope'] = implode(', ', $mergedNeeds);
                    $adjusted = true;
                }
                $documentsExclude = $this->extractDocumentExclusions($text);
                if (!empty($documentsAdjust) || !empty($documentsExclude)) {
                    $currentDocs = [];
                    if (is_array($localProfile['documents_scope_items'] ?? null)) {
                        $currentDocs = array_values((array) $localProfile['documents_scope_items']);
                    } elseif (!empty($localProfile['documents_scope'])) {
                        $currentDocs = array_map('trim', explode(',', (string) $localProfile['documents_scope']));
                    }
                    $normalizedCurrent = [];
                    foreach ($currentDocs as $item) {
                        $label = trim((string) $item);
                        if ($label !== '') {
                            $normalizedCurrent[$this->normalize($label)] = $label;
                        }
                    }
                    foreach ($documentsExclude as $doc) {
                        unset($normalizedCurrent[$this->normalize((string) $doc)]);
                    }
                    foreach ($documentsAdjust as $doc) {
                        $key = $this->normalize((string) $doc);
                        if ($key !== '' && !isset($normalizedCurrent[$key])) {
                            $normalizedCurrent[$key] = (string) $doc;
                        }
                    }
                    $mergedDocs = array_values(array_filter(array_map('trim', array_values($normalizedCurrent))));
                    if (!empty($mergedDocs)) {
                        $localProfile['documents_scope_items'] = $mergedDocs;
                        $localProfile['documents_scope'] = implode(', ', $mergedDocs);
                    }
                    $adjusted = true;
                }
                if ($adjusted) {
                    $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
                    unset($localState['analysis_approved']);
                    $reply = $this->buildRequirementsSummaryReply($businessType, $localProfile, $plan);
                    return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
                }
            }
            if ($this->isNegativeReply($text)) {
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
                $localState['onboarding_step'] = 'needs_scope';
                unset($localState['analysis_approved']);
                $reply = 'Listo, ajustamos el alcance.' . "\n"
                    . 'Dime que quieres cambiar primero (control del negocio o documentos).';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            }
            if ($this->isAffirmativeReply($text)) {
                $localState['analysis_approved'] = true;
                $localState['onboarding_step'] = 'plan_ready';
            }
        }

        if (empty($localState['analysis_approved'])) {
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = 'confirm_scope';
            $reply = $this->buildRequirementsSummaryReply($businessType, $localProfile, $plan);
            if (!$this->isAffirmativeReply($text)) {
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            }
        }
        $localState['onboarding_step'] = 'plan_ready';

        if ($isOnboarding && $this->isFieldHelpQuestion($text)) {
            $entityHint = $this->parseEntityFromCrudText($text);
            $planEntities = is_array($plan['entities'] ?? null) ? $plan['entities'] : [];
            if ($entityHint !== '' && !empty($planEntities)) {
                foreach ($planEntities as $candidate) {
                    $candidate = strtolower((string) $candidate);
                    if ($candidate === '') {
                        continue;
                    }
                    $candidateFlat = str_replace('_', ' ', $candidate);
                    $hintFlat = str_replace('_', ' ', strtolower($entityHint));
                    if ($candidate === strtolower($entityHint) || str_contains($candidate, strtolower($entityHint)) || str_contains($candidateFlat, $hintFlat)) {
                        $entityHint = $candidate;
                        break;
                    }
                }
            }
            if ($entityHint === '') {
                foreach ($planEntities as $candidate) {
                    $candidate = (string) $candidate;
                    if ($candidate === '') {
                        continue;
                    }
                    $candidateFlat = strtolower(str_replace('_', ' ', $candidate));
                    if (str_contains($text, strtolower($candidate)) || str_contains($text, $candidateFlat)) {
                        $entityHint = $candidate;
                        break;
                    }
                }
            }
            if ($entityHint === '') {
                $entityHint = $this->parseEntityFromText($text);
                if (in_array($entityHint, ['si', 'no', 'que', 'cual', 'cuales', 'crear', 'hacer', 'paso'], true)) {
                    $entityHint = '';
                }
            }
            if ($entityHint === '') {
                $entityHint = (string) ($plan['first_entity'] ?? 'clientes');
            }
            $proposal = $this->buildCreateTableProposal($entityHint, $localProfile);
            $this->setBuilderPendingCommand($localState, (array) $proposal['command']);
            $localState['entity'] = $entityHint;
            $localState['active_task'] = 'create_table';
            return ['action' => 'ask_user', 'reply' => $proposal['reply'], 'state' => $localState];
        }

        if (
            $this->isNextStepQuestion($text)
            || $trigger
            || $businessHint
            || $operationModel !== ''
            || ($isOnboarding && $this->isBuilderProgressQuestion($text))
            || (
                $isOnboarding
                && $this->isAffirmativeReply($text)
                && !$this->hasBuildSignals($text)
                && !$this->hasFieldPairs($text)
            )
        ) {
            $proposal = $this->buildNextStepProposal($businessType, $plan, $localProfile, $owner, $localState);
            if (!empty($proposal['command']) && is_array($proposal['command'])) {
                $this->setBuilderPendingCommand($localState, (array) $proposal['command']);
                $localState['entity'] = $proposal['entity'] ?? null;
                $localState['active_task'] = (string) ($proposal['active_task'] ?? 'create_table');
                return ['action' => 'ask_user', 'reply' => (string) ($proposal['reply'] ?? ''), 'state' => $localState];
            }

            $this->clearBuilderPendingCommand($localState);
            $localState['active_task'] = (string) ($proposal['active_task'] ?? 'builder_onboarding');
            return ['action' => 'respond_local', 'reply' => (string) ($proposal['reply'] ?? $this->buildBuilderPlanProgressReply($localState, $localProfile, false)), 'state' => $localState];
        }

        return null;
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
        return preg_match('/^(si|s\x{00ed}|ok|dale|confirmo|hagalo|hazlo|de una|claro|correcto|procede|empieza|inicia|hagale|h[áa]gale|si hagale|si hagale amigo|si amigo|listo|perfecto|perfecto dale|hagale pues)\s*$/u', $text) === 1;
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
            'estética' => 'spa_bienestar',
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
        if ($detectedBusinessType !== '') {
            return '';
        }

        $patterns = [
            '/(?:mi\\s+)?(?:empresa|negocio|programa|app)\\s+(?:de|para)\\s+([a-z0-9_\\-\\s]{3,80})/iu',
            '/(?:tengo\\s+una\\s+empresa\\s+de|me\\s+dedico\\s+a|trabajo\\s+en)\\s+([a-z0-9_\\-\\s]{3,80})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $candidate = trim((string) ($m[1] ?? ''));
                $candidate = preg_replace('/\\s+/', ' ', $candidate) ?? $candidate;
                $candidate = trim($candidate, " .,:;!?");
                $candidate = preg_replace('/\\b(que|quiero|necesito|hacer|crear|programa|app|sistema)\\b/iu', '', $candidate) ?? $candidate;
                $candidate = preg_replace('/\\s+/', ' ', trim($candidate)) ?? $candidate;
                if ($candidate !== '' && mb_strlen($candidate, 'UTF-8') >= 4) {
                    return $candidate;
                }
            }
        }
        return '';
    }

    private function detectOperationModel(string $text): string
    {
        if (str_contains($text, 'mixto') || str_contains($text, 'misto') || str_contains($text, 'contado y credito') || str_contains($text, 'credito y contado')) {
            return 'mixto';
        }
        if (str_contains($text, 'credito') || str_contains($text, 'a credito') || str_contains($text, 'cartera')) {
            return 'credito';
        }
        if (str_contains($text, 'contado') || str_contains($text, 'efectivo') || str_contains($text, 'inmediato')) {
            return 'contado';
        }
        return '';
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

    private function buildBusinessPlanReply(string $businessType, array $plan, string $owner = ''): string
    {
        $businessType = $this->normalizeBusinessType($businessType);
        $domainProfile = $this->findDomainProfile($businessType);
        $businessLabel = (string) ($domainProfile['label'] ?? $businessType);
        $planEntities = (array) ($plan['entities'] ?? []);
        $existingEntities = $this->scopedEntityNames();
        $forms = $this->scopedFormNames();
        $suggestedReports = (array) ($plan['reports'] ?? []);
        $suggestedWorkflows = (array) ($plan['workflows'] ?? []);
        $accountingFocus = (array) ($plan['accounting_focus'] ?? []);
        $operationModel = (string) ($plan['operation_model'] ?? 'mixto');

        $nextEntity = '';
        foreach ($planEntities as $candidate) {
            if (!in_array($candidate, $existingEntities, true)) {
                $nextEntity = (string) $candidate;
                break;
            }
        }
        $currentEntity = $nextEntity !== '' ? $nextEntity : ((string) ($plan['first_entity'] ?? 'clientes'));
        $firstFields = $this->buildFieldSuggestion($currentEntity);

        $lines = [];
        if ($owner !== '') {
            $lines[] = 'Perfecto, ' . $owner . '.';
        }
        $lines[] = 'Para tu negocio (' . $businessLabel . ') te recomiendo esta ruta:';
        if (!empty($planEntities)) {
            $lines[] = '- Tablas base: ' . implode(', ', $planEntities);
        }
        $lines[] = '- Modelo de pago: ' . $operationModel . '.';
        if (!empty($accountingFocus)) {
            $lines[] = '- Controles clave: ' . implode(', ', array_slice($accountingFocus, 0, 3));
        }
        if (!empty($suggestedReports)) {
            $lines[] = '- Reportes sugeridos: ' . implode(', ', array_slice($suggestedReports, 0, 3));
        }
        if (!empty($suggestedWorkflows)) {
            $lines[] = '- Flujo recomendado: ' . implode(' -> ', array_slice($suggestedWorkflows, 0, 4));
        }
        if ($nextEntity !== '') {
            $lines[] = 'Siguiente tabla recomendada: ' . $nextEntity . '.';
            $lines[] = 'Paso siguiente:';
            $lines[] = '1) Crea la tabla.';
            $lines[] = '2) Crea el formulario.';
            $lines[] = '3) Prueba crear un primer registro.';
            $lines[] = $firstFields;
            $checks = (array) ($plan['accounting_checks'] ?? []);
            if (!empty($checks)) {
                $lines[] = 'Control contable minimo sugerido:';
                foreach (array_slice($checks, 0, 4) as $check) {
                    $lines[] = '- ' . $check;
                }
            }
            $lines[] = 'Cuando la crees, te guio al siguiente paso (formulario y flujo).';
            return implode("\n", $lines);
        }

        if (!empty($existingEntities) && empty($forms)) {
            $lines[] = 'Ya tienes tablas base.';
            $lines[] = 'Paso siguiente: crear formulario para ' . $existingEntities[0] . '.';
            $lines[] = 'Escribe: crear formulario ' . $existingEntities[0];
            return implode("\n", $lines);
        }

        $lines[] = 'La estructura base ya esta creada.';
        $lines[] = 'Paso siguiente: abre el chat de la app para registrar datos reales.';
        return implode("\n", $lines);
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
            'historia_clinica' => ['historia clinica', 'historias clinicas', 'historia médica', 'historias médicas', 'historia medica', 'historias medicas', 'evolucion clinica'],
            'inventario' => ['inventario', 'stock', 'existencias'],
            'medicamentos' => ['medicamento', 'medicamentos', 'farmacia'],
            'medico_turno' => ['medico en turno', 'medicos en turno', 'doctor en turno', 'doctores en turno', 'turno medico', 'turnos medicos'],
            'pacientes' => ['paciente', 'pacientes', 'mascota', 'mascotas', 'animalito', 'animalitos'],
            'duenos' => ['dueño', 'dueno', 'dueños', 'duenos', 'propietario', 'propietarios'],
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
            'duenos' => 'dueños',
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

    private function buildFieldSuggestion(string $entity, array $profile = []): string
    {
        $entity = strtolower(trim($entity));
        $suggested = $this->suggestFieldsForEntity($entity, $profile);

        $lines = [];
        $lines[] = 'Para ' . $entity . ' te sugiero estos campos base:';
        foreach ($suggested as $field) {
            $lines[] = '- ' . $field;
        }
        $lines[] = 'Tipos rapidos: texto=palabras, numero=entero, decimal=con decimales, fecha=AAAA-MM-DD, bool=si/no.';
        $lines[] = 'Si estas de acuerdo, enviame una sola linea asi:';
        $lines[] = 'crear tabla ' . $entity . ' ' . implode(' ', $suggested);
        return implode("\n", $lines);
    }

    private function buildCreateTableProposal(string $entity, array $profile = []): array
    {
        $entity = $this->normalizeEntityForSchema($entity);
        $entity = $this->adaptEntityToBusinessContext($entity, $profile);
        if ($entity === '') {
            $entity = 'clientes';
        }
        $suggested = $this->suggestFieldsForEntity($entity, $profile);
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

        if (str_contains($text, 'paciente') || str_contains($text, 'pacientes')) {
            return 'pacientes';
        }

        if (in_array($businessType, ['clinica_medica', 'odontologia'], true) && in_array($entity, ['clientes', 'cliente'], true)) {
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
        if (preg_match('/\b(crear|construir|armar|disenar|diseÃ±ar|hacer)\b.{0,30}\b(app|aplicacion|programa|software)\b/u', $text) === 1) {
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

    private function routeTraining(string $text, array $training, array $profile = [], string $tenantId = 'default', string $userId = 'anon', array $state = [], array $lexicon = [], string $mode = 'app'): array
    {
        $route = [];
        if (!empty($training) && !empty($training['intents'])) {
            $route = $this->classifyWithTraining($text, $training, $profile);
        }
        if (empty($route['intent'])) {
            $route = $this->classifyWithPlaybookIntents($text, $profile);
        }
        if (empty($route['intent'])) {
            return [];
        }
        $intentName = (string) $route['intent'];
        $action = (string) ($route['action'] ?? '');
        $confidence = (float) ($route['confidence'] ?? 0);
        $threshold = (float) ($training['routing']['confidence_threshold'] ?? 0.72);
        $missing = $route['missing_required'] ?? false;

        $allowedTrainingActions = [
            'PROJECT_STATUS_SUMMARY',
            'PROJECT_ENTITY_LIST',
            'PROJECT_FORM_LIST',
            'APP_CAPABILITIES',
            'CRUD_GUIDE',
            'USER_PROFILE_LEARN',
            'TRAINING_MEMORY_UPDATE',
            'AUTH_LOGIN',
            'USER_CREATE',
            'PROJECT_SWITCH',
            'APPLY_PLAYBOOK_FERRETERIA',
            'APPLY_PLAYBOOK_FARMACIA',
            'APPLY_PLAYBOOK_RESTAURANTE',
            'APPLY_PLAYBOOK_MANTENIMIENTO',
            'APPLY_PLAYBOOK_PRODUCCION',
            'APPLY_PLAYBOOK_BELLEZA',
        ];
        if ($this->hasCrudSignals($text) && !in_array($action, $allowedTrainingActions, true)) {
            return [];
        }

        if ($confidence < $threshold || $missing) {
            if (!empty($route['ask'])) {
                return [
                    'action' => 'ask_user',
                    'reply' => $route['ask'],
                    'intent' => $intentName,
                    'collected' => $route['entities'] ?? [],
                    'active_task' => 'training'
                ];
            }
            return [];
        }

        switch ($action) {
            case 'PROJECT_STATUS_SUMMARY':
                if ($mode !== 'builder') {
                    $entityInText = $this->detectEntity($text, $lexicon, $state);
                    if ($entityInText !== '' || str_contains($text, 'listar') || str_contains($text, 'lista') || str_contains($text, 'ver ')) {
                        return [];
                    }
                }
                if ($mode === 'builder') {
                    return ['action' => 'respond_local', 'reply' => $this->buildProjectStatus(), 'intent' => $intentName];
                }
                return ['action' => 'respond_local', 'reply' => $this->buildAppStatus(), 'intent' => $intentName];
            case 'PROJECT_ENTITY_LIST':
                if ($mode !== 'builder') {
                    $wantsCatalog = str_contains($text, 'tabla')
                        || str_contains($text, 'tablas')
                        || str_contains($text, 'entidad')
                        || str_contains($text, 'entidades');
                    if (!$wantsCatalog) {
                        return [];
                    }
                    $detectedEntity = $this->detectEntity($text, $lexicon, $state);
                    if ($detectedEntity !== '' || $this->isDataListRequest($text) || !$wantsCatalog) {
                        return [];
                    }
                }
                return ['action' => 'respond_local', 'reply' => $this->buildEntityList(), 'intent' => $intentName];
            case 'PROJECT_FORM_LIST':
                if ($mode !== 'builder') {
                    $wantsFormsCatalog = str_contains($text, 'formulario')
                        || str_contains($text, 'formularios')
                        || str_contains($text, 'pantalla')
                        || str_contains($text, 'pantallas')
                        || str_contains($text, 'vista')
                        || str_contains($text, 'vistas');
                    if (!$wantsFormsCatalog || $this->isDataListRequest($text) || str_contains($text, 'crear ') || str_contains($text, 'usar ')) {
                        return [];
                    }
                }
                return ['action' => 'respond_local', 'reply' => $this->buildFormList(), 'intent' => $intentName];
            case 'APP_CAPABILITIES':
                return ['action' => 'respond_local', 'reply' => $this->buildCapabilities($profile, $training, $mode), 'intent' => $intentName];
            case 'CRUD_GUIDE':
                if (!$this->isCrudGuideRequest($text, $state, $training)) {
                    return [];
                }
                $entity = '';
                if (!empty($route['entities']['entity'])) {
                    $entity = (string) $route['entities']['entity'];
                }
                if ($entity === '') {
                    $entity = $this->detectEntity($text, $lexicon, $state);
                }
                if ($entity === '') {
                    return ['action' => 'ask_user', 'reply' => 'De cual lista? Ej: clientes, productos o facturas.', 'intent' => $intentName, 'active_task' => 'crud_guide'];
                }
                return ['action' => 'respond_local', 'reply' => $this->buildCrudGuide($entity), 'intent' => $intentName, 'entity' => $entity];
            case 'USER_PROFILE_LEARN':
                $updated = $this->updateProfileFromText($profile, $text, $tenantId, $userId);
                return ['action' => 'respond_local', 'reply' => $updated['reply'], 'intent' => $intentName];
            case 'TRAINING_MEMORY_UPDATE':
                $updated = $this->storeMemoryNote($profile, $text, $tenantId, $userId);
                return ['action' => 'respond_local', 'reply' => $updated['reply'], 'intent' => $intentName];
            case 'AUTH_LOGIN':
                $authParsed = $this->parseAuthLogin($text, $state);
                if (!empty($authParsed['ask'])) {
                    return ['action' => 'ask_user', 'reply' => $authParsed['ask'], 'intent' => $intentName, 'active_task' => 'AUTH_LOGIN', 'collected' => $authParsed['collected'] ?? []];
                }
                if (!empty($authParsed['command'])) {
                    return ['action' => 'execute_command', 'command' => $authParsed['command'], 'intent' => $intentName, 'collected' => $authParsed['collected'] ?? []];
                }
                return ['action' => 'respond_local', 'reply' => 'Necesito tu usuario y clave para iniciar sesion.', 'intent' => $intentName];
            case 'USER_CREATE':
                $userParsed = $this->parseUserCreate($text, $state);
                if (!empty($userParsed['ask'])) {
                    return ['action' => 'ask_user', 'reply' => $userParsed['ask'], 'intent' => $intentName, 'active_task' => 'USER_CREATE', 'collected' => $userParsed['collected'] ?? []];
                }
                if (!empty($userParsed['command'])) {
                    return ['action' => 'execute_command', 'command' => $userParsed['command'], 'intent' => $intentName, 'collected' => $userParsed['collected'] ?? []];
                }
                return ['action' => 'respond_local', 'reply' => 'Necesito usuario, rol y clave para crear la cuenta.', 'intent' => $intentName];
            case 'PROJECT_SWITCH':
                if (!empty($route['ask'])) {
                    return ['action' => 'ask_user', 'reply' => $route['ask'], 'intent' => $intentName, 'active_task' => $action];
                }
                return ['action' => 'respond_local', 'reply' => 'Dime el ID del proyecto para cambiar.', 'intent' => $intentName];
            case 'APPLY_PLAYBOOK_FERRETERIA':
            case 'APPLY_PLAYBOOK_FARMACIA':
            case 'APPLY_PLAYBOOK_RESTAURANTE':
            case 'APPLY_PLAYBOOK_MANTENIMIENTO':
            case 'APPLY_PLAYBOOK_PRODUCCION':
            case 'APPLY_PLAYBOOK_BELLEZA':
                return $this->routePlaybookAction($action, $intentName, $text, $profile, $tenantId, $userId, $mode, $state);
            default:
                return [];
        }
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
            $this->appendResearchTopic($tenantId, $sectorKey . ':playbook_missing', [
                'requested_action' => $action,
                'source' => 'playbook_router',
            ]);
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

        $proposal = $this->buildCreateTableProposal($targetEntity, $updatedProfile);
        $replyLines = [];
        $replyLines[] = 'Entendi tu dolor de negocio en ' . strtolower($this->humanizeSectorKey($sectorKey)) . '.';
        if ($diagnosis !== '') {
            $replyLines[] = 'Diagnostico: ' . $diagnosis;
        }
        if ($solutionPitch !== '') {
            $replyLines[] = 'Solucion recomendada: ' . $solutionPitch;
        }
        if ($miniApp !== '') {
            $replyLines[] = 'Mini-app sugerida: ' . str_replace('_', ' ', $miniApp) . '.';
        }
        if (!empty($keyFields)) {
            $replyLines[] = 'Campos clave sugeridos: ' . implode(', ', $keyFields) . '.';
        }
        $replyLines[] = $proposal['reply'];

        return [
            'action' => 'ask_user',
            'reply' => implode("\n", $replyLines),
            'intent' => $intentName,
            'entity' => (string) ($proposal['entity'] ?? $targetEntity),
            'pending_command' => is_array($proposal['command'] ?? null) ? $proposal['command'] : [],
            'active_task' => 'create_table',
            'collected' => $collected,
        ];
    }

    private function sectorKeyByPlaybookAction(string $action): string
    {
        $map = [
            'APPLY_PLAYBOOK_FERRETERIA' => 'FERRETERIA',
            'APPLY_PLAYBOOK_FARMACIA' => 'FARMACIA',
            'APPLY_PLAYBOOK_RESTAURANTE' => 'RESTAURANTE',
            'APPLY_PLAYBOOK_MANTENIMIENTO' => 'MANTENIMIENTO',
            'APPLY_PLAYBOOK_PRODUCCION' => 'PRODUCCION',
            'APPLY_PLAYBOOK_BELLEZA' => 'BELLEZA',
        ];
        return (string) ($map[$action] ?? '');
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

    private function routeConfusion(string $text, string $mode, array $state, array $profile, array $confusionBase): array
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

        if ($mode === 'builder' && $this->hasRuntimeCrudSignals($text) && !$this->hasBuildSignals($text)) {
            return [
                'action' => 'respond_local',
                'reply' => 'Estas en el Creador. Aqui definimos estructura. Para registrar datos usa el chat de la app.',
                'intent' => 'mode_switch_app',
            ];
        }

        if ($mode !== 'builder') {
            return [];
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
            $this->trainingBaseCache[$cacheKey] = [
                'data' => [],
                'base_mtime' => $baseMtime,
                'override_hash' => $overrideHash,
            ];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->trainingBaseCache[$cacheKey] = [
                'data' => [],
                'base_mtime' => $baseMtime,
                'override_hash' => $overrideHash,
            ];
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
            return [];
        }
        $legacy = $this->readJson($path, []);
        if (!empty($legacy)) {
            $this->memory->saveTenantMemory($tenantId, 'training_overrides', $legacy);
        }
        return $legacy;
    }

    private function applyTrainingOverrides(array $training, array $overrides): array
    {
        if (empty($overrides)) {
            return $training;
        }
        if (empty($overrides['intents']) || empty($training['intents'])) {
            return $training;
        }
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
            'last_action' => null,
            'dialog' => null,
            'last_messages' => [],
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
        return array_merge($default, $stored);
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
            throw new RuntimeException('Schema de working memory no existe: ' . $path);
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
            $this->domainPlaybookCache = [];
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $base = is_array($decoded) ? $decoded : [];

        $projectPath = $this->projectRoot . '/contracts/knowledge/domain_playbooks.json';
        if (is_file($projectPath)) {
            $projectOverride = $this->readJson($projectPath, []);
            if (!empty($projectOverride)) {
                foreach (['solver_intents', 'sector_playbooks', 'knowledge_prompt_template'] as $key) {
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
            $this->accountingKnowledgeCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->accountingKnowledgeCache = [];
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
            $this->unspscCommonCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->unspscCommonCache = [];
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
            $this->writeJson($path, $default);
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload !== false) {
            file_put_contents($path, $payload, LOCK_EX);
        }
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
}


