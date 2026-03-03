<?php
declare(strict_types=1);
// app/Core/Agents/ConversationGatewayBuilderOnboardingTrait.php

namespace App\Core\Agents;

trait ConversationGatewayBuilderOnboardingTrait
{
    private function handleBuilderOnboardingCore(
        string $text,
        array $state,
        array $profile,
        string $tenantId,
        string $userId,
        bool $isOnboarding,
        bool $trigger,
        bool $businessHint
    ): ?array {
        if ($isOnboarding && $this->isBuilderActionMessage($text) && !$trigger) {
            return null;
        }

        $localProfile = $profile;
        $localState = $state;
        $currentStep = (string) ($localState['onboarding_step'] ?? '');
        $playbookData = $this->loadDomainPlaybook();
        $unknownProtocol = is_array($playbookData['unknown_business_protocol'] ?? null)
            ? (array) $playbookData['unknown_business_protocol']
            : [];
        $unknownDiscoveryNote = '';

        $unknownDiscoveryRoute = $this->handleUnknownBusinessDiscoveryStep(
            $text,
            $localState,
            $localProfile,
            $unknownProtocol,
            $tenantId,
            $userId,
            $unknownDiscoveryNote
        );
        if (!empty($unknownDiscoveryRoute)) {
            return $unknownDiscoveryRoute;
        }

        if (
            (string) ($localState['active_task'] ?? '') === 'business_research_confirmation'
            && is_array($localState['dynamic_playbook_proposal'] ?? null)
            && !empty($localState['dynamic_playbook_proposal'])
        ) {
            $proposal = (array) $localState['dynamic_playbook_proposal'];
            $candidateLabel = trim((string) ($proposal['candidate'] ?? 'tu negocio'));
            $needsList = is_array($proposal['needs'] ?? null) ? array_values((array) $proposal['needs']) : [];
            $docsList = is_array($proposal['documents'] ?? null) ? array_values((array) $proposal['documents']) : [];

            if ($this->isAffirmativeReply($text)) {
                $dynamicKey = 'dynamic_' . $this->safe($candidateLabel !== '' ? $candidateLabel : 'custom');
                if ($dynamicKey === 'dynamic_') {
                    $dynamicKey = 'dynamic_custom';
                }
                $localProfile['business_type'] = $dynamicKey;
                $localProfile['business_label'] = $candidateLabel !== '' ? $candidateLabel : 'Negocio personalizado';
                if (!empty($needsList)) {
                    $localProfile['needs_scope_items'] = $needsList;
                    $localProfile['needs_scope'] = implode(', ', $needsList);
                }
                if (!empty($docsList)) {
                    $localProfile['documents_scope_items'] = $docsList;
                    $localProfile['documents_scope'] = implode(', ', $docsList);
                }
                unset($localProfile['business_candidate']);
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);

                $localState['proposed_profile'] = $dynamicKey;
                $localState['dynamic_playbook'] = $proposal;
                $localState['dynamic_playbook_proposal'] = null;
                $localState['business_resolution_last_candidate'] = $candidateLabel;
                $localState['business_resolution_last_status'] = 'CONFIRMED_NEW_BUSINESS';
                $localState['business_resolution_last_result'] = [
                    'status' => 'CONFIRMED_NEW_BUSINESS',
                    'business_candidate' => $candidateLabel,
                    'needs_normalized' => $needsList,
                    'documents_normalized' => $docsList,
                ];
                $localState['business_resolution_last_at'] = date('c');
                $localState['resolution_attempts'] = 0;
                $localState['analysis_approved'] = null;
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'operation_model';

                $reply = 'Perfecto. Confirmado: negocio de ' . ($candidateLabel !== '' ? $candidateLabel : 'tipo personalizado') . "\n"
                    . 'Paso 2: como manejas pagos? contado, credito o mixto.';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            }

            if ($this->isNegativeReply($text)) {
                $localState['dynamic_playbook_proposal'] = null;
                $localState['proposed_profile'] = null;
                $localState['resolution_attempts'] = (int) ($localState['resolution_attempts'] ?? 0) + 1;
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'business_type';
                $reply = 'Entendido, lo ajustamos.' . "\n"
                    . 'Dime en una frase que vendes o fabricas para ubicar mejor tu negocio.';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            }

            $needsPreview = !empty($needsList) ? implode(', ', array_slice($needsList, 0, 3)) : 'clientes, operaciones y ventas';
            $docsPreview = !empty($docsList) ? implode(', ', array_slice($docsList, 0, 3)) : 'factura, orden y cotizacion';
            $reply = 'Investigue tu negocio de "' . $candidateLabel . '".' . "\n"
                . 'Parece que necesitas: ' . $needsPreview . '.' . "\n"
                . 'Documentos clave: ' . $docsPreview . '.' . "\n"
                . 'Es correcto? Responde si o no.';
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
        }

        $name = $this->extractPersonName($text);
        if ($name !== '') {
            $localProfile['owner_name'] = $name;
        }

        $existingBusinessType = (string) ($localProfile['business_type'] ?? '');
        $business = $this->detectBusinessType($text);
        $forceUnknownResearch = (bool) ($localState['unknown_business_force_research'] ?? false);
        if ($forceUnknownResearch) {
            $business = '';
            unset($localProfile['business_type']);
            $localState['proposed_profile'] = null;
        }
        $businessShiftSignal = $this->shouldReprofileBusiness($text, $existingBusinessType, $business, $currentStep);
        $explicitBusinessChange = preg_match('/\b(cambiar|cambia|otro negocio|nuevo negocio|no soy|soy una|soy un|fabrico|me dedico)\b/u', $text) === 1
            || $businessShiftSignal;
        $explicitBusinessRejection = $this->isBusinessTypeRejectedByUser($text, $existingBusinessType);
        $businessResolvedNote = '';
        if ($explicitBusinessRejection) {
            $existingNormalized = $this->normalizeBusinessType($existingBusinessType);
            $detectedNormalized = $this->normalizeBusinessType($business);
            $hasReplacementBusiness = $detectedNormalized !== '' && $detectedNormalized !== $existingNormalized;
            if (!$hasReplacementBusiness) {
                $replacementCandidate = $this->detectUnknownBusinessCandidate($text, $business);
                $candidateNormalized = $this->normalizeBusinessType($replacementCandidate);
                $candidateProfile = $candidateNormalized !== '' ? $this->findDomainProfile($candidateNormalized) : [];
                if ($candidateNormalized !== '' && !empty($candidateProfile) && $candidateNormalized !== $existingNormalized) {
                    $business = $candidateNormalized;
                    $localProfile['business_type'] = $candidateNormalized;
                    $hasReplacementBusiness = true;
                }
            }
            if (!$hasReplacementBusiness) {
                $business = '';
                unset($localProfile['business_type']);
                $localState['proposed_profile'] = null;
                $localState['analysis_approved'] = null;
                $localState['dynamic_playbook_proposal'] = null;
                $localState['resolution_attempts'] = (int) ($localState['resolution_attempts'] ?? 0) + 1;
            }
        }
        if (
            $business === ''
            && (string) ($localState['onboarding_step'] ?? '') === 'business_type'
            && !(bool) ($localState['unknown_business_force_research'] ?? false)
        ) {
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
        $businessChanged = $business !== '' && $this->normalizeBusinessType($business) !== $this->normalizeBusinessType($existingBusinessType);
        if ($business !== '' && ($existingBusinessType === '' || $currentStep === 'business_type' || $explicitBusinessChange || $explicitBusinessRejection || $businessShiftSignal)) {
            $localProfile['business_type'] = $business;
            unset($localProfile['business_candidate']);
            unset($localState['unknown_business_notice_sent']);
            $localState['proposed_profile'] = $business;
            $localState['resolution_attempts'] = 0;
            $localState['dynamic_playbook_proposal'] = null;
            $localState['business_resolution_last_candidate'] = null;
            $localState['business_resolution_last_status'] = null;
            $localState['business_resolution_last_result'] = null;
            if ($businessChanged) {
                unset($localState['analysis_approved']);
                $localState['confirm_scope_last_hash'] = null;
                $localState['confirm_scope_repeats'] = 0;
                unset($localProfile['needs_scope'], $localProfile['needs_scope_items'], $localProfile['documents_scope'], $localProfile['documents_scope_items']);
                $localState['onboarding_step'] = !empty($localProfile['operation_model']) ? 'needs_scope' : 'operation_model';
                if ($businessResolvedNote === '' && $existingBusinessType !== '') {
                    $label = $this->domainLabelByBusinessType($business);
                    $businessResolvedNote = 'Entendido. Ajuste el negocio a "' . $label . '".';
                }
            }
        }
        $unknownBusinessCandidate = (bool) ($localState['unknown_business_force_research'] ?? false)
            ? trim((string) ($localProfile['business_candidate'] ?? ''))
            : $this->detectUnknownBusinessCandidate($text, $business);
        if ($unknownBusinessCandidate !== '') {
            $candidateAsKnownType = $this->normalizeBusinessType($unknownBusinessCandidate);
            $candidateKnownProfile = $candidateAsKnownType !== '' ? $this->findDomainProfile($candidateAsKnownType) : [];
            if ($candidateAsKnownType !== '' && !empty($candidateKnownProfile)) {
                $business = $candidateAsKnownType;
                $localProfile['business_type'] = $candidateAsKnownType;
                unset($localProfile['business_candidate']);
                $unknownBusinessCandidate = '';
            }
        }
        if ($unknownBusinessCandidate !== '') {
            $localProfile['business_candidate'] = $unknownBusinessCandidate;
            $this->registerUnknownBusinessCase($tenantId, $userId, $unknownBusinessCandidate, $text);
            if ($this->shouldPrioritizeUnknownCandidate($text, (string) ($localProfile['business_type'] ?? ''), $unknownBusinessCandidate)) {
                unset($localProfile['business_type']);
                $business = '';
                $localState['proposed_profile'] = null;
                $localState['analysis_approved'] = null;
            }
        }
        $shouldCaptureOperationModel = $currentStep === 'operation_model'
            || empty((string) ($localProfile['operation_model'] ?? ''))
            || $this->isOperationModelOverrideHint($text);
        $operationModel = $shouldCaptureOperationModel ? $this->detectOperationModel($text) : '';
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
        $llmThreshold = (float) ($unknownProtocol['llm_confidence_threshold'] ?? 0.85);
        if ($llmThreshold < 0.5 || $llmThreshold > 0.99) {
            $llmThreshold = 0.85;
        }

        if ($businessType === '' && $businessCandidate !== '') {
            $startUnknownDiscovery = $this->startUnknownBusinessDiscovery($businessCandidate, $localState, $unknownProtocol);
            if (!empty($startUnknownDiscovery)) {
                return $startUnknownDiscovery;
            }
            $localState['unknown_business_force_research'] = false;
            $resolution = $this->resolveUnknownBusinessWithGemini($text, $businessCandidate, $localProfile, $localState);
            $status = strtoupper(trim((string) ($resolution['status'] ?? '')));
            $confidence = (float) ($resolution['confidence'] ?? 0.0);
            if ($status === 'MATCHED' && $confidence < $llmThreshold) {
                $status = 'NEEDS_CLARIFICATION';
            }
            if ($status === 'MATCHED') {
                $resolvedType = $this->normalizeBusinessType((string) ($resolution['canonical_business_type'] ?? ''));
                if ($resolvedType !== '') {
                    $localProfile['business_type'] = $resolvedType;
                    $businessType = $resolvedType;
                    unset($localProfile['business_candidate']);
                    unset($localState['unknown_business_notice_sent']);
                    $localState['proposed_profile'] = $resolvedType;
                    $localState['resolution_attempts'] = 0;
                    $localState['dynamic_playbook_proposal'] = null;
                    $localState['business_resolution_last_candidate'] = $businessCandidate;
                    $localState['business_resolution_last_status'] = 'MATCHED';
                    $localState['business_resolution_last_result'] = $resolution;
                    $localState['business_resolution_last_at'] = date('c');

                    $resolvedNeeds = is_array($resolution['needs_normalized'] ?? null) ? $resolution['needs_normalized'] : [];
                    if (!empty($resolvedNeeds) && empty($localProfile['needs_scope'])) {
                        $mergedNeeds = $this->mergeScopeLabels([], array_map('strval', $resolvedNeeds));
                        if (!empty($mergedNeeds)) {
                            $localProfile['needs_scope_items'] = $mergedNeeds;
                            $localProfile['needs_scope'] = implode(', ', $mergedNeeds);
                        }
                    }
                    $resolvedDocs = is_array($resolution['documents_normalized'] ?? null) ? $resolution['documents_normalized'] : [];
                    if (!empty($resolvedDocs) && empty($localProfile['documents_scope'])) {
                        $mergedDocs = $this->mergeScopeLabels([], array_map('strval', $resolvedDocs));
                        if (!empty($mergedDocs)) {
                            $localProfile['documents_scope_items'] = $mergedDocs;
                            $localProfile['documents_scope'] = implode(', ', $mergedDocs);
                        }
                    }

                    $label = $this->domainLabelByBusinessType($resolvedType);
                    $businessResolvedNote = 'Entendi tu negocio como "' . $label . '". '
                        . 'Si no es correcto, dime: "no, cambiemos el tipo de negocio".';
                    if ($unknownDiscoveryNote !== '') {
                        $businessResolvedNote = $unknownDiscoveryNote . "\n" . $businessResolvedNote;
                    }
                    $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
                }
            } elseif ($status === 'NEW_BUSINESS') {
                $needsList = is_array($resolution['needs_normalized'] ?? null)
                    ? array_values(array_filter(array_map('strval', (array) $resolution['needs_normalized'])))
                    : [];
                $docsList = is_array($resolution['documents_normalized'] ?? null)
                    ? array_values(array_filter(array_map('strval', (array) $resolution['documents_normalized'])))
                    : [];
                if (empty($needsList)) {
                    $needsList = ['inventario', 'produccion', 'ventas'];
                }
                if (empty($docsList)) {
                    $docsList = ['factura', 'orden de trabajo', 'cotizacion'];
                }

                $localState['active_task'] = 'business_research_confirmation';
                $localState['onboarding_step'] = 'business_type';
                $localState['proposed_profile'] = null;
                $localState['resolution_attempts'] = (int) ($localState['resolution_attempts'] ?? 0) + 1;
                $localState['dynamic_playbook_proposal'] = [
                    'candidate' => $businessCandidate,
                    'needs' => $needsList,
                    'documents' => $docsList,
                ];
                $localState['business_resolution_last_candidate'] = $businessCandidate;
                $localState['business_resolution_last_status'] = 'NEW_BUSINESS';
                $localState['business_resolution_last_result'] = $resolution;
                $localState['business_resolution_last_at'] = date('c');

                $reply = 'He investigado tu negocio de "' . $businessCandidate . '".' . "\n"
                    . 'Parece que necesitas: ' . implode(', ', array_slice($needsList, 0, 3)) . '.' . "\n"
                    . 'Documentos clave: ' . implode(', ', array_slice($docsList, 0, 3)) . '.' . "\n"
                    . 'Es correcto? Responde si o no.';
                if ($unknownDiscoveryNote !== '') {
                    $reply = $unknownDiscoveryNote . "\n" . $reply;
                }
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            } elseif ($status === 'NEEDS_CLARIFICATION') {
                $question = trim((string) ($resolution['clarifying_question'] ?? ''));
                if ($question === '') {
                    $question = 'Para ubicar bien tu negocio, dime en una frase que vendes o fabricas.';
                }
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'business_type';
                $localState['resolution_attempts'] = (int) ($localState['resolution_attempts'] ?? 0) + 1;
                $localState['business_resolution_last_candidate'] = $businessCandidate;
                $localState['business_resolution_last_status'] = 'NEEDS_CLARIFICATION';
                $localState['business_resolution_last_result'] = $resolution;
                $localState['business_resolution_last_at'] = date('c');
                if ($unknownDiscoveryNote !== '') {
                    $question = $unknownDiscoveryNote . "\n" . $question;
                }
                return ['action' => 'ask_user', 'reply' => $question, 'state' => $localState];
            } elseif ($status !== '') {
                $localState['business_resolution_last_candidate'] = $businessCandidate;
                $localState['business_resolution_last_status'] = $status;
                $localState['business_resolution_last_result'] = $resolution;
                $localState['business_resolution_last_at'] = date('c');

                if ($unknownDiscoveryNote !== '' && in_array($status, ['LLM_NOT_AVAILABLE', 'ERROR', 'INVALID_RESPONSE', 'INVALID_REQUEST'], true)) {
                    $draft = $this->buildUnknownBusinessLocalDraft($localState, $businessCandidate);
                    $needsList = is_array($draft['needs'] ?? null) ? (array) $draft['needs'] : [];
                    $docsList = is_array($draft['documents'] ?? null) ? (array) $draft['documents'] : [];

                    $localState['active_task'] = 'business_research_confirmation';
                    $localState['onboarding_step'] = 'business_type';
                    $localState['proposed_profile'] = null;
                    $localState['dynamic_playbook_proposal'] = [
                        'candidate' => $businessCandidate,
                        'needs' => $needsList,
                        'documents' => $docsList,
                    ];
                    $localState['business_resolution_last_status'] = 'NEW_BUSINESS_LOCAL';
                    $localState['business_resolution_last_result']['fallback'] = 'local_research_draft';

                    $reply = $unknownDiscoveryNote . "\n"
                        . 'No pude consultar IA externa en este momento, pero ya tengo un borrador funcional.' . "\n"
                        . 'Necesidades sugeridas: ' . implode(', ', array_slice($needsList, 0, 3)) . '.' . "\n"
                        . 'Documentos clave: ' . implode(', ', array_slice($docsList, 0, 3)) . '.' . "\n"
                        . 'Es correcto? Responde si o no.';
                    return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
                }
            }
        }
        if ($businessType === '') {
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = 'business_type';
            $greet = $owner !== '' ? 'Perfecto, ' . $owner . '. ' : 'Perfecto. ';
            $alreadyNotified = (bool) ($localState['unknown_business_notice_sent'] ?? false);
            $unknownEnabled = (bool) ($unknownProtocol['enabled'] ?? true);
            $template = trim((string) ($unknownProtocol['message_template'] ?? 'No tengo plantilla exacta para "{business}" todavia. Ya lo registre para investigarlo y compartirlo con todos los agentes.'));
            $nextStepQuestion = trim((string) ($unknownProtocol['next_step_question'] ?? 'Para avanzar rapido, dime si manejas productos, servicios o ambos.'));
            if ($nextStepQuestion === '') {
                $nextStepQuestion = 'Para avanzar rapido, dime si manejas productos, servicios o ambos.';
            }
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

            $attempts = (int) ($localState['resolution_attempts'] ?? 0);
            $maxCorrectionAttempts = (int) ($unknownProtocol['max_correction_attempts'] ?? 2);
            if ($maxCorrectionAttempts < 1 || $maxCorrectionAttempts > 6) {
                $maxCorrectionAttempts = 2;
            }
            if ($attempts >= $maxCorrectionAttempts) {
                $reply = 'Para evitar confusiones, dime en una frase exacta que vendes o fabricas.' . "\n"
                    . 'Ejemplo: "fabrico bolsos" o "vendo repuestos".';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            }

            if ($businessCandidate !== '' && !$alreadyNotified && $unknownEnabled) {
                $message = str_replace('{business}', $businessCandidate, $template);
                $reply = $greet . $message . "\n" . $nextStepQuestion;
                $localState['unknown_business_notice_sent'] = true;
            } else {
                $reply = $greet
                    . 'Vamos paso a paso para crear tu app.'
                    . "\n"
                    . 'Paso 1: responde solo una opcion: servicios, productos o ambos.';
            }
            if ($unknownDiscoveryNote !== '') {
                $reply = $unknownDiscoveryNote . "\n" . $reply;
            }
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
        }
        if (empty($localProfile['operation_model'])) {
            if ($currentStep === 'operation_model' && $operationModel === '') {
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'operation_model';
                $reply = 'En este paso responde solo como cobras: contado, credito o mixto.';
                if ($businessResolvedNote !== '') {
                    $reply = $businessResolvedNote . "\n" . $reply;
                }
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            }
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
            if ($businessResolvedNote !== '') {
                $reply = $businessResolvedNote . "\n" . $reply;
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
            if ($businessResolvedNote !== '') {
                $reply = $businessResolvedNote . "\n" . $reply;
            }
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
            if ($businessResolvedNote !== '') {
                $reply = $businessResolvedNote . "\n" . $reply;
            }
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
                $localState['confirm_scope_last_hash'] = null;
                $localState['confirm_scope_repeats'] = 0;
            }
        }

        if (empty($localState['analysis_approved'])) {
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = 'confirm_scope';
            $reply = $this->buildRequirementsSummaryReply($businessType, $localProfile, $plan);
            if ($businessResolvedNote !== '') {
                $reply = $businessResolvedNote . "\n" . $reply;
            }

            $digest = sha1($reply);
            $prevDigest = (string) ($localState['confirm_scope_last_hash'] ?? '');
            $repeats = (int) ($localState['confirm_scope_repeats'] ?? 0);
            $repeats = $prevDigest !== '' && $prevDigest === $digest ? ($repeats + 1) : 1;
            $localState['confirm_scope_last_hash'] = $digest;
            $localState['confirm_scope_repeats'] = $repeats;

            if (!$this->isAffirmativeReply($text) && $repeats > 2) {
                unset($localState['analysis_approved']);
                $localState['onboarding_step'] = 'business_type';
                $localState['resolution_attempts'] = (int) ($localState['resolution_attempts'] ?? 0) + 1;
                $reply = 'Para evitar un bucle, vamos a recalibrar tu negocio.' . "\n"
                    . 'Dime en una frase exacta que vendes o fabricas.';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            }

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

}
