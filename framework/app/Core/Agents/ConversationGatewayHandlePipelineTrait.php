<?php
declare(strict_types=1);
// app/Core/Agents/ConversationGatewayHandlePipelineTrait.php

namespace App\Core\Agents;

trait ConversationGatewayHandlePipelineTrait
{
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

        if ($mode === 'builder' && $this->isFarewell($normalizedBase)) {
            $this->clearBuilderPendingCommand($state);
            $state['builder_calc_prompt'] = null;
            $state['active_task'] = null;
            $state['missing'] = [];
            $state['requested_slot'] = null;
            $reply = 'Listo. Cuando quieras seguimos.';
            $state = $this->updateState($state, $raw, $reply, 'farewell', null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('farewell', true));
        }

        if ($mode === 'builder' && $this->isBuilderContextResetHint($normalizedBase)) {
            $existingBusinessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
            $normalizedResetText = $this->normalizeBuilderIntentText($normalizedBase);
            $mustClearBusinessProfile = $this->isBusinessTypeRejectedByUser($normalizedBase, $existingBusinessType)
                || preg_match('/\bno\s+te\s+he\s+dicho\b/u', $normalizedResetText) === 1;

            $state = $this->resetBuilderInferenceState($state);
            $state['builder_plan'] = null;
            if ($mustClearBusinessProfile) {
                $profile = $this->resetBuilderBusinessProfile($profile);
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $profile);
            }

            $reply = 'Entendido. A que se dedica tu negocio?';
            $state = $this->updateState($state, $raw, $reply, 'builder_context_reset', null, [], 'builder_onboarding');
            $this->saveState($tenantId, $userId, $state);
            $telemetry = $this->telemetry('builder_context_reset', true);
            $telemetry['builder_context_reset'] = true;
            $telemetry['builder_context_profile_cleared'] = $mustClearBusinessProfile;
            return $this->result('ask_user', $reply, null, null, $state, $telemetry);
        }

        if ($mode === 'builder' && $this->isAmbiguousBuilderCreateRequest($normalizedBase)) {
            if (
                !empty($state['builder_pending_command'])
                || in_array((string) ($state['active_task'] ?? ''), ['create_table', 'create_form', 'crud'], true)
            ) {
                $state = $this->resetBuilderInferenceState($state);
            } else {
                $this->clearBuilderPendingCommand($state);
                $state['missing'] = [];
                $state['requested_slot'] = null;
            }
            $state['active_task'] = 'builder_onboarding';
            $currentStep = $this->resolveBuilderOnboardingStep($profile, $state);
            $state['onboarding_step'] = $currentStep;

            // ── FAST PATH: LLM-first translation instead of static canned reply.
            // BuilderFastPathParser sends a compact payload (no history, no RAG) and returns strict JSON.
            // PHP validates the JSON and picks the reply. Fallback is always a safe hardcoded string.
            $fastPath = $this->builderFastPath(
                $normalizedBase,
                $currentStep,
                $profile,
                $state,
                $this->builderAllowedValuesForStep($currentStep, $profile, $state)
            );

            // If the LLM mapped a real value (e.g. business_type), persist it now.
            if (
                $fastPath['intent'] === 'set_business_type'
                && !empty($fastPath['mapped_fields']['business_type'])
            ) {
                $profile['business_type'] = (string) $fastPath['mapped_fields']['business_type'];
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $profile);
            }

            $reply = $fastPath['reply'];
            $state = $this->updateState($state, $raw, $reply, 'builder_clarify', null, [], 'builder_onboarding');
            $this->saveState($tenantId, $userId, $state);
            $telemetry = $this->telemetry('builder_clarify', true);
            $telemetry['builder_ambiguity_guard'] = true;
            $telemetry['builder_onboarding_step'] = $currentStep;
            $telemetry['fast_path_intent'] = $fastPath['intent'];
            $telemetry['fast_path_confidence'] = $fastPath['confidence'];
            $telemetry['fast_path_via'] = $fastPath['via'];
            return $this->result('ask_user', $reply, null, null, $state, $telemetry);
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

        $flowExpiryRoute = $this->routeFlowRuntimeExpiry($normalizedBase, $state, $mode, $profile);
        if (!empty($flowExpiryRoute)) {
            $reply = (string) ($flowExpiryRoute['reply'] ?? 'Listo.');
            $state = $this->updateState(
                $state,
                $raw,
                $reply,
                (string) ($flowExpiryRoute['intent'] ?? 'FLOW_RUNTIME_EXPIRED'),
                null,
                $flowExpiryRoute['collected'] ?? [],
                $flowExpiryRoute['active_task'] ?? ($state['active_task'] ?? null)
            );
            $this->saveState($tenantId, $userId, $state);
            return $this->result(
                (string) ($flowExpiryRoute['action'] ?? 'ask_user'),
                $reply,
                null,
                null,
                $state,
                $this->telemetry('flow_expiry', true, $flowExpiryRoute)
            );
        }

        $flowControlRoute = $this->routeFlowControl($normalizedBase, $state, $profile, $mode, $tenantId, $userId);
        if (!empty($flowControlRoute)) {
            $reply = (string) ($flowControlRoute['reply'] ?? 'Listo.');
            $state = $this->updateState(
                $state,
                $raw,
                $reply,
                (string) ($flowControlRoute['intent'] ?? 'flow_control'),
                $flowControlRoute['entity'] ?? null,
                $flowControlRoute['collected'] ?? [],
                $flowControlRoute['active_task'] ?? ($state['active_task'] ?? null)
            );
            if (!empty($flowControlRoute['state_patch']) && is_array($flowControlRoute['state_patch'])) {
                foreach ($flowControlRoute['state_patch'] as $key => $value) {
                    $state[$key] = $value;
                }
            }
            $this->saveState($tenantId, $userId, $state);
            return $this->result(
                (string) ($flowControlRoute['action'] ?? 'respond_local'),
                $reply,
                null,
                null,
                $state,
                $this->telemetry('flow_control', true, $flowControlRoute)
            );
        }

        $feedbackRoute = $this->routeFeedbackLoop($normalizedBase, $state, $mode, $tenantId, $userId);
        if (!empty($feedbackRoute)) {
            $reply = (string) ($feedbackRoute['reply'] ?? 'Gracias.');
            $state = $this->updateState(
                $state,
                $raw,
                $reply,
                (string) ($feedbackRoute['intent'] ?? 'feedback_loop'),
                null,
                $feedbackRoute['collected'] ?? [],
                $feedbackRoute['active_task'] ?? ($state['active_task'] ?? null)
            );
            $this->saveState($tenantId, $userId, $state);
            return $this->result(
                (string) ($feedbackRoute['action'] ?? 'respond_local'),
                $reply,
                null,
                null,
                $state,
                $this->telemetry('feedback_loop', true, $feedbackRoute)
            );
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
                    // FALL THROUGH to routeConfusion if user text is long/descriptive (to let LLM handle it)
                    if (mb_strlen($normalizedBase) < 15) {
                        return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
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
                    // FALL THROUGH to routeConfusion if user text is long/descriptive (to let LLM handle it)
                    if (mb_strlen($normalizedBase) < 15) {
                        return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
                }
                if ($this->isClarificationRequest($normalizedBase)) {
                    $reply = $this->buildPendingClarificationReply($state['builder_pending_command']);
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    // FALL THROUGH to routeConfusion if user text is long/descriptive (to let LLM handle it)
                    if (mb_strlen($normalizedBase) < 15) {
                        return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
                }
                if ($this->isFieldHelpQuestion($normalizedBase)) {
                    $pendingEntity = $this->normalizeEntityForSchema((string) ($state['builder_pending_command']['entity'] ?? ($state['entity'] ?? '')));
                    $proposal = $this->buildCreateTableProposal($pendingEntity !== '' ? $pendingEntity : 'clientes', $profile);
                    $reply = $proposal['reply'];
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    // FALL THROUGH to routeConfusion if user text is long/descriptive (to let LLM handle it)
                    if (mb_strlen($normalizedBase) < 15) {
                        return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
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
                    // FALL THROUGH to routeConfusion if user text is long/descriptive (to let LLM handle it)
                    if (mb_strlen($normalizedBase) < 15) {
                        return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
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

        $skipBuilderConfusionRoute = $mode === 'builder'
            && in_array((string) ($state['active_task'] ?? ''), ['builder_onboarding', 'unknown_business_discovery'], true)
            && ($this->isBuilderUserFrustrated($normalizedBase) || $this->isClarificationRequest($normalizedBase));
        $confusionRoute = $skipBuilderConfusionRoute
            ? []
            : $this->routeConfusion($normalizedBase, $mode, $state, $profile, $confusionBase, $tenantId, $userId);
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
                        $telemetry = $this->telemetry('confusion_router', true, $confusionRoute);
            if (isset($confusionRoute['llm_telemetry'])) {
                $telemetry['llm_telemetry'] = $confusionRoute['llm_telemetry'];
            }
            $this->saveState($tenantId, $userId, $state);
            return $this->result(
                (string) ($confusionRoute['action'] ?? 'respond_local'),
                $reply,
                $confusionRoute['command'] ?? null,
                null,
                $state,
                $telemetry
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

        $workflowRoute = $this->routeWorkflowBuilder($normalizedBase, $mode, $state);
        if (!empty($workflowRoute)) {
            $reply = (string) ($workflowRoute['reply'] ?? 'Listo.');
            $action = (string) ($workflowRoute['action'] ?? 'ask_user');
            if (!empty($workflowRoute['pending_command']) && is_array($workflowRoute['pending_command'])) {
                $this->setBuilderPendingCommand($state, (array) $workflowRoute['pending_command']);
            }
            $state = $this->updateState(
                $state,
                $raw,
                $reply,
                $workflowRoute['intent'] ?? 'WORKFLOW_COMPILE',
                null,
                $workflowRoute['collected'] ?? [],
                $workflowRoute['active_task'] ?? 'workflow_builder'
            );
            $this->saveState($tenantId, $userId, $state);
            return $this->result(
                $action === 'respond_local' ? 'respond_local' : 'ask_user',
                $reply,
                null,
                null,
                $state,
                $this->telemetry('workflow_builder', true, $workflowRoute)
            );
        }

        $builderGuidanceRoute = $this->routeBuilderGuidance($normalizedBase, $training, $state, $lexicon, $mode);
        if (!empty($builderGuidanceRoute)) {
            $reply = (string) ($builderGuidanceRoute['reply'] ?? 'Listo.');
            $action = (string) ($builderGuidanceRoute['action'] ?? 'respond_local');
            if (!empty($builderGuidanceRoute['pending_command']) && is_array($builderGuidanceRoute['pending_command'])) {
                $this->setBuilderPendingCommand($state, (array) $builderGuidanceRoute['pending_command']);
            }
            $state = $this->updateState(
                $state,
                $raw,
                $reply,
                $builderGuidanceRoute['intent'] ?? null,
                null,
                $builderGuidanceRoute['collected'] ?? [],
                $builderGuidanceRoute['active_task'] ?? 'builder_guidance'
            );
            $this->saveState($tenantId, $userId, $state);
            return $this->result(
                $action === 'ask_user' ? 'ask_user' : 'respond_local',
                $reply,
                null,
                null,
                $state,
                $this->telemetry('builder_guidance', true, $builderGuidanceRoute)
            );
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
                if (!empty($trainingRoute['state_patch']) && is_array($trainingRoute['state_patch'])) {
                    foreach ($trainingRoute['state_patch'] as $key => $value) {
                        $state[$key] = $value;
                    }
                }
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('training', true, $trainingRoute));
            }
            if (($trainingRoute['action'] ?? '') === 'respond_local') {
                $state = $this->updateState($state, $raw, $reply, $trainingRoute['intent'] ?? null, $trainingRoute['entity'] ?? null, $trainingRoute['collected'] ?? [], $trainingRoute['active_task'] ?? null);
                if (!empty($trainingRoute['state_patch']) && is_array($trainingRoute['state_patch'])) {
                    foreach ($trainingRoute['state_patch'] as $key => $value) {
                        $state[$key] = $value;
                    }
                }
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

        $builderSemanticProfileHint = $this->normalizeBusinessType($this->detectBusinessType($normalized)) !== '';
        $builderScopeStep = (string) ($state['onboarding_step'] ?? '');
        $builderScopeDeflection = $mode === 'builder'
            && (string) ($state['active_task'] ?? '') === 'builder_onboarding'
            && in_array($builderScopeStep, ['needs_scope', 'documents_scope'], true)
            && (
                $builderSemanticProfileHint
                || preg_match('/\b(vendo|vendemos|tengo una|tengo un|mi empresa|mi negocio|programa|progama|app)\b/u', $normalized) === 1
            );
        $skipBuilderProfileLearning = $mode === 'builder'
            && (
                (
                    ((string) ($state['active_task'] ?? '')) === ''
                    && !$this->isBuilderOnboardingTrigger($normalized)
                    && $builderSemanticProfileHint
                )
                || $builderScopeDeflection
            );
        if ($this->isProfileHint($normalized) && !$skipBuilderProfileLearning) {
            $updated = $this->updateProfileFromText($profile, $normalized, $tenantId, $userId);
            $state = $this->updateState($state, $raw, $updated['reply'], 'profile', null, [], 'profile');
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $updated['reply'], null, null, $state, $this->telemetry('profile', true));
        }

        $classification = $this->classify($normalized);

        $isPlaybookBuilderRequest = !empty($this->parseInstallPlaybookRequest($normalized)['matched']);
        $modeGuardDecision = $this->modeGuardPolicy->evaluate(
            $mode,
            $this->hasBuildSignals($normalized),
            $this->hasRuntimeCrudSignals($normalized),
            $isPlaybookBuilderRequest
        );
        if (is_array($modeGuardDecision)) {
            $reply = (string) ($modeGuardDecision['reply'] ?? '');
            $telemetry = (string) ($modeGuardDecision['telemetry'] ?? 'mode_guard');
            $state = $this->updateState($state, $raw, $reply, null, null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry($telemetry, true));
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
                if ($this->isAlertsCenterOperationalEntity($entityName)) {
                    $capsule = $this->buildContextCapsule($normalized, $state, $lexicon, $policy, $classification);
                    $state = $this->updateState(
                        $state,
                        $raw,
                        '',
                        $capsule['intent'] ?? null,
                        $capsule['entity'] ?? null,
                        $capsule['state']['collected'] ?? [],
                        $state['active_task'] ?? null
                    );
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result(
                        'send_to_llm',
                        '',
                        null,
                        $capsule,
                        $state,
                        $this->telemetry('missing_entity', true, $parsed + ['deferred_to_alerts_center_skill' => true])
                    );
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

        $capsule = $this->buildContextCapsule($normalized, $state, $lexicon, $policy, $classification);
        $state = $this->updateState($state, $raw, '', $capsule['intent'] ?? null, $capsule['entity'] ?? null, $capsule['state']['collected'] ?? [], $state['active_task'] ?? null);
        $this->saveState($tenantId, $userId, $state);
        $telemetry = $this->telemetry('llm', false);
        if ($mode === 'builder') {
            $telemetry['builder_normal_flow'] = true;
            $telemetry['builder_fallback_disabled'] = true;
            $telemetry['route_reason'] = 'builder_continues_to_router';
            $telemetry['routing_hint_steps'] = ['cache', 'rules', 'skills', 'rag', 'llm'];
        }

        return $this->result('send_to_llm', '', null, $capsule, $state, $telemetry);
    }

}
