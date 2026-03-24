<?php
declare(strict_types=1);
// app/Core/Agents/ConversationGatewayBuilderOnboardingTrait.php

namespace App\Core\Agents;

use App\Core\LLM\LLMRouter;

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
        $localProfile = $profile;
        $localState = $state;
        $currentStep = $this->resolveBuilderOnboardingStep($localProfile, $localState);
        $localState['active_task'] = (string) ($localState['active_task'] ?? '') === 'unknown_business_discovery'
            ? 'unknown_business_discovery'
            : 'builder_onboarding';
        $localState['onboarding_step'] = $currentStep;
        
        // Mapeo contextual para respuestas ambiguas (MisiÃ³n 2 - Paso 2D)
        $normalizedText = strtolower(trim((string) ($text ?? '')));
        if ($normalizedText === 'ambos' || $normalizedText === 'ambas' || $normalizedText === 'ambas cosas' || $normalizedText === 'ambos tipos') {
            $lastContext = is_array($state['last_question_context'] ?? null) ? $state['last_question_context'] : [];
            $lastField = (string) ($lastContext['field'] ?? '');
            if ($lastField === 'operation_model') {
                $text = 'mixto';
            } elseif ($lastField === 'business_type' || $lastField === 'operation_channels') {
                // Si dice ambas para tipo de negocio, asumimos el perfil mas completo o multicanal
                $text = 'retail_tienda y ecommerce'; 
            }
        }

        if ($this->isBuilderUserFrustrated($text)) {
            $assist = $this->clarifyBuilderStepViaLlm($text, $currentStep, $localProfile, $localState);
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = $currentStep;
            return [
                'action' => 'ask_user',
                'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, $currentStep, $localProfile),
                'state' => $localState,
            ];
        }

        if ($isOnboarding && $this->isBuilderActionMessage($text) && !$trigger) {
            return null;
        }

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
        if (
            $businessType === ''
            && $businessCandidate === ''
            && $currentStep === 'business_type'
            && !$this->isQuestionLike($text)
            && !$this->isBuilderActionMessage($text)
            && !$this->isAffirmativeReply($text)
            && !$this->isNegativeReply($text)
        ) {
            $assist = $this->clarifyBuilderStepViaLlm($text, 'business_type', $localProfile, $localState);
            $mappedBusinessType = $this->extractBuilderLlmAssistMappedValue($assist, 'business_type');
            if ($mappedBusinessType !== '') {
                $localProfile['business_type'] = $mappedBusinessType;
                unset($localProfile['business_candidate']);
                unset($localState['unknown_business_notice_sent']);
                $localState['proposed_profile'] = $mappedBusinessType;
                $localState['resolution_attempts'] = 0;
                $localState['dynamic_playbook_proposal'] = null;
                $localState['business_resolution_last_candidate'] = null;
                $localState['business_resolution_last_status'] = null;
                $localState['business_resolution_last_result'] = null;
                $localState['business_resolution_last_at'] = date('c');
                $businessType = $mappedBusinessType;
                $businessResolvedNote = 'Entendi tu negocio como "' . $this->domainLabelByBusinessType($mappedBusinessType) . '".'
                    . ' Si no es correcto, dime: "no, cambiemos el tipo de negocio".';
                $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
            } elseif ($assist !== null) {
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'business_type';
                return [
                    'action' => 'ask_user',
                    'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, 'business_type', $localProfile),
                    'state' => $localState,
                ];
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
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'business_type';
                return [
                    'action' => 'ask_user',
                    'reply' => $this->buildBuilderOnboardingRecoveryReply('business_type', $localProfile),
                    'state' => $localState,
                ];
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
                $assist = $this->clarifyBuilderStepViaLlm($text, 'operation_model', $localProfile, $localState);
                $mappedOperationModel = $this->extractBuilderLlmAssistMappedValue($assist, 'operation_model');
                if ($mappedOperationModel !== '') {
                    $localProfile['operation_model'] = $mappedOperationModel;
                    $operationModel = $mappedOperationModel;
                    $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
                } else {
                    $localState['active_task'] = 'builder_onboarding';
                    $localState['onboarding_step'] = 'operation_model';
                    $reply = $this->resolveBuilderLlmAssistHelpReply($assist, 'operation_model', $localProfile);
                    if ($businessResolvedNote !== '') {
                        $reply = $businessResolvedNote . "\n" . $reply;
                    }
                    return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
                }
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
                if ($businessResolvedNote !== '') {
                    $reply = $businessResolvedNote . "\n" . $reply;
                }
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
            }
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
            } elseif ($this->shouldEscalateUnusableBuilderScopeAnswer($text, [])) {
                $assist = $this->clarifyBuilderStepViaLlm($text, 'needs_scope', $localProfile, $localState);
                $mappedNeed = $this->extractBuilderLlmAssistMappedValue($assist, 'needs_scope');
                if ($mappedNeed !== '') {
                    $currentNeeds = [];
                    if (is_array($localProfile['needs_scope_items'] ?? null)) {
                        $currentNeeds = array_values((array) $localProfile['needs_scope_items']);
                    } elseif (!empty($localProfile['needs_scope'])) {
                        $currentNeeds = array_map('trim', explode(',', (string) $localProfile['needs_scope']));
                    }
                    $mergedNeeds = $this->mergeScopeLabels($currentNeeds, [$mappedNeed]);
                    $localProfile['needs_scope_items'] = $mergedNeeds;
                    $localProfile['needs_scope'] = implode(', ', $mergedNeeds);
                    $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
                } else {
                    $localState['active_task'] = 'builder_onboarding';
                    $localState['onboarding_step'] = 'needs_scope';
                    return [
                        'action' => 'ask_user',
                        'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, 'needs_scope', $localProfile),
                        'state' => $localState,
                    ];
                }
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
            } elseif ($this->shouldEscalateUnusableBuilderScopeAnswer($text, [])) {
                $assist = $this->clarifyBuilderStepViaLlm($text, 'documents_scope', $localProfile, $localState);
                $mappedDocument = $this->extractBuilderLlmAssistMappedValue($assist, 'documents_scope');
                if ($mappedDocument !== '') {
                    $currentDocs = [];
                    if (is_array($localProfile['documents_scope_items'] ?? null)) {
                        $currentDocs = array_values((array) $localProfile['documents_scope_items']);
                    } elseif (!empty($localProfile['documents_scope'])) {
                        $currentDocs = array_map('trim', explode(',', (string) $localProfile['documents_scope']));
                    }
                    $mergedDocs = $this->mergeScopeLabels($currentDocs, [$mappedDocument]);
                    $localProfile['documents_scope_items'] = $mergedDocs;
                    $localProfile['documents_scope'] = implode(', ', $mergedDocs);
                    $this->saveProfile($tenantId, $this->profileUserKey($userId), $localProfile);
                } else {
                    $localState['active_task'] = 'builder_onboarding';
                    $localState['onboarding_step'] = 'documents_scope';
                    return [
                        'action' => 'ask_user',
                        'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, 'documents_scope', $localProfile),
                        'state' => $localState,
                    ];
                }
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

                if ($this->isBuilderUserFrustrated($text) || $this->isClarificationRequest($text)) {
                    $assist = $this->clarifyBuilderStepViaLlm($text, 'confirm_scope', $localProfile, $localState);
                    return $this->routeBuilderConfirmScopeAssist($assist, $localProfile, $localState, $businessType, $plan);
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
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'confirm_scope';
                $localState['resolution_attempts'] = (int) ($localState['resolution_attempts'] ?? 0) + 1;
                $localState['confirm_scope_last_hash'] = null;
                $localState['confirm_scope_repeats'] = 0;
                $assist = $this->clarifyBuilderStepViaLlm($text, 'confirm_scope', $localProfile, $localState);
                return $this->routeBuilderConfirmScopeAssist($assist, $localProfile, $localState, $businessType, $plan);
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

        if ($isOnboarding || $trigger || $businessHint) {
            $localState['active_task'] = 'builder_onboarding';
            $step = (string) $localState['onboarding_step'];
            $reply = $this->buildBuilderOnboardingRecoveryReply($step, $localProfile);
            $localState['last_question_context'] = $this->inferLastQuestionContext($step, $reply);
            
            return [
                'action' => 'ask_user',
                'reply' => $reply,
                'state' => $localState,
            ];
        }

        // Safety fallback: si estamos aca, no entendimos el paso pero estamos en flujo de construccion.
        // NO retornamos null para evitar el fallback de ERP ("No entendi. Puedes decir: crear cliente...")
        // EXCEPTO si es un mensaje de accion (crear tabla, etc) que debe manejar el IntentRouter.
        if ($this->isBuilderActionMessage($text)) {
            return null;
        }

        $localState['active_task'] = 'builder_onboarding';
        $step = (string)($localState['onboarding_step'] ?? 'business_type');
        $reply = $this->buildBuilderOnboardingRecoveryReply($step, $localProfile);
        $localState['last_question_context'] = $this->inferLastQuestionContext($step, $reply);

        return [
            'action' => 'ask_user',
            'reply' => $reply,
            'state' => $localState,
        ];
    }

    private function resolveBuilderOnboardingStep(array $profile, array $state): string
    {

        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        if ($businessType === '') {
            return 'business_type';
        }

        if (trim((string) ($profile['operation_model'] ?? '')) === '') {
            return 'operation_model';
        }

        $needsKnown = !empty($profile['needs_scope'])
            || (is_array($profile['needs_scope_items'] ?? null) && !empty($profile['needs_scope_items']));
        if (!$needsKnown) {
            return 'needs_scope';
        }

        $documentsKnown = !empty($profile['documents_scope'])
            || (is_array($profile['documents_scope_items'] ?? null) && !empty($profile['documents_scope_items']));
        if (!$documentsKnown) {
            return 'documents_scope';
        }

        return !empty($state['analysis_approved']) ? 'plan_ready' : 'confirm_scope';
    }

    private function buildBuilderOnboardingRecoveryReply(string $step, array $profile): string
    {
        return match ($step) {
            'business_type' => 'Voy mas simple. Dime en una frase que vendes o a que se dedica tu negocio.',
            'operation_model' => 'Voy mas simple. Como cobras: contado, credito o mixto?',
            'needs_scope' => 'Voy mas simple. Que quieres controlar primero?',
            'documents_scope' => 'Voy mas simple. Que documentos vas a usar?',
            'confirm_scope' => 'Voy mas simple. Dime que quieres ajustar: negocio, pagos, control o documentos.',
            'plan_ready' => 'Voy mas simple. Dime si quieres crear la primera tabla o ajustar algo del alcance.',
            default => !empty($profile['business_type'])
                ? 'Voy mas simple. Dime el siguiente dato clave y sigo contigo.'
                : 'Voy mas simple. Dime en una frase que vendes o a que se dedica tu negocio.',
        };
    }

    private function clarifyBuilderStepViaLlm(string $text, string $step, array $profile, array $state): ?array
    {
        $step = trim($step);
        if ($step === '') {
            return null;
        }

        if (
            $this->isPureGreeting($text)
            || $this->isFarewell($text)
            || $this->isBuilderActionMessage($text)
        ) {
            return null;
        }

        $allowedValues = $this->builderLlmAllowedValuesForStep($step, $profile, $state);
        if ($allowedValues === []) {
            return null;
        }

        $stubRaw = trim((string) (getenv('SUKI_BUILDER_ONBOARDING_LLM_STUB_JSON') ?: ''));
        if ($stubRaw !== '') {
            $decoded = json_decode($stubRaw, true);
            if (is_array($decoded)) {
                if (isset($decoded[$step]) && is_array($decoded[$step])) {
                    $decoded = (array) $decoded[$step];
                }
                return $this->normalizeBuilderLlmAssistResult($decoded, $step, $profile, $state);
            }
        }

        $enabled = getenv('BUILDER_LLM_ASSIST_ENABLED');
        if ($enabled !== false && !in_array(strtolower(trim((string) $enabled)), ['1', 'true', 'yes', 'on'], true)) {
            return $this->buildBuilderLlmAssistFallback($step, $profile);
        }

        if (!$this->runtimeLlmChatAvailable()) {
            return $this->buildBuilderLlmAssistFallback($step, $profile);
        }

        $capsule = [
            'intent' => 'BUILDER_ONBOARDING_STEP_CLARIFIER',
            'entity' => '',
            'entity_contract_min' => ['required' => [], 'types' => []],
            'state' => [
                'collected' => [],
                'missing' => $this->builderLlmMissingFieldsForStep($step, $profile, $state),
            ],
            'user_message' => $text,
            'policy' => [
                'requires_strict_json' => true,
                'max_output_tokens' => 220,
                'latency_budget_ms' => 1800,
            ],
            'prompt_contract' => [
                'ROLE' => 'Builder Step Clarifier',
                'INPUT' => [
                    'onboarding_step' => $step,
                    'known_fields' => $this->builderLlmKnownFields($profile),
                    'missing_fields' => $this->builderLlmMissingFieldsForStep($step, $profile, $state),
                    'allowed_values' => $allowedValues,
                    'user_text' => $this->sanitizeRequirementText($text),
                ],
                'CONSTRAINTS' => [
                    'response_language' => 'es-CO',
                    'strict_catalog_only' => true,
                    'do_not_write_new_fields' => true,
                    'short_help_reply' => true,
                    'help_reply_max_words' => 18,
                    'if_not_sure_resolved_false' => true,
                    'if_user_is_confused_prefer_frustration_help' => true,
                ],
                'OUTPUT_FORMAT' => [
                    'resolved' => ['type' => 'boolean'],
                    'mapped_value' => ['type' => ['string', 'null']],
                    'help_reply' => ['type' => 'string'],
                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'intent_type' => ['type' => 'string', 'enum' => ['map_value', 'clarify', 'frustration_help']],
                ],
            ],
        ];

        try {
            $router = new LLMRouter();
            $providerMode = $this->resolveRuntimeLlmProviderMode();
            $llm = $router->chat($capsule, ['provider_mode' => $providerMode, 'temperature' => 0.1]);
            $json = is_array($llm['json'] ?? null) ? (array) $llm['json'] : [];
            if ($json === []) {
                return $this->buildBuilderLlmAssistFallback($step, $profile);
            }
            return $this->normalizeBuilderLlmAssistResult($json, $step, $profile, $state);
        } catch (\Throwable $e) {
            return $this->buildBuilderLlmAssistFallback($step, $profile);
        }
    }

    private function resolveBuilderLlmAssistHelpReply(?array $assist, string $step, array $profile): string
    {
        $reply = trim((string) ($assist['help_reply'] ?? ''));
        if ($reply !== '') {
            return $reply;
        }

        return $this->buildBuilderOnboardingRecoveryReply($step, $profile);
    }

    private function extractBuilderLlmAssistMappedValue(?array $assist, string $step): string
    {
        if (!is_array($assist) || !($assist['resolved'] ?? false)) {
            return '';
        }

        return trim((string) ($assist['mapped_value'] ?? ''));
    }

    private function normalizeBuilderLlmAssistResult(array $result, string $step, array $profile, array $state): array
    {
        $intentType = trim((string) ($result['intent_type'] ?? 'clarify'));
        if (!in_array($intentType, ['map_value', 'clarify', 'frustration_help'], true)) {
            $intentType = 'clarify';
        }

        $confidence = is_numeric($result['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $result['confidence']))
            : 0.0;

        $mappedValue = trim((string) ($result['mapped_value'] ?? ''));
        $mappedValue = $this->normalizeBuilderLlmMappedValue($step, $mappedValue, $profile, $state);
        $resolved = (bool) ($result['resolved'] ?? false) && $mappedValue !== '';

        return [
            'resolved' => $resolved,
            'mapped_value' => $resolved ? $mappedValue : null,
            'help_reply' => trim((string) ($result['help_reply'] ?? $this->buildBuilderOnboardingRecoveryReply($step, $profile))),
            'confidence' => $confidence,
            'intent_type' => $intentType,
        ];
    }

    private function normalizeBuilderLlmMappedValue(string $step, string $value, array $profile, array $state): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $allowedValues = $this->builderLlmAllowedValuesForStep($step, $profile, $state);
        $normalizedAllowed = [];
        foreach ($allowedValues as $allowedValue) {
            $normalizedAllowed[$this->normalize((string) $allowedValue)] = (string) $allowedValue;
        }

        $normalizedValue = $this->normalize($value);
        return (string) ($normalizedAllowed[$normalizedValue] ?? '');
    }

    private function buildBuilderLlmAssistFallback(string $step, array $profile): array
    {
        return [
            'resolved' => false,
            'mapped_value' => null,
            'help_reply' => $this->buildBuilderOnboardingRecoveryReply($step, $profile),
            'confidence' => 0.0,
            'intent_type' => 'clarify',
        ];
    }

    private function builderLlmKnownFields(array $profile): array
    {
        return [
            'business_type' => trim((string) ($profile['business_type'] ?? '')),
            'operation_model' => trim((string) ($profile['operation_model'] ?? '')),
            'needs_scope' => is_array($profile['needs_scope_items'] ?? null)
                ? array_values((array) $profile['needs_scope_items'])
                : trim((string) ($profile['needs_scope'] ?? '')),
            'documents_scope' => is_array($profile['documents_scope_items'] ?? null)
                ? array_values((array) $profile['documents_scope_items'])
                : trim((string) ($profile['documents_scope'] ?? '')),
        ];
    }

    private function builderLlmMissingFieldsForStep(string $step, array $profile, array $state): array
    {
        $ordered = ['business_type', 'operation_model', 'needs_scope', 'documents_scope', 'confirm_scope'];
        $currentIndex = array_search($step, $ordered, true);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        $missing = [];
        foreach (array_slice($ordered, $currentIndex) as $field) {
            if ($field === 'business_type' && $this->normalizeBusinessType((string) ($profile['business_type'] ?? '')) === '') {
                $missing[] = 'business_type';
            }
            if ($field === 'operation_model' && trim((string) ($profile['operation_model'] ?? '')) === '') {
                $missing[] = 'operation_model';
            }
            if ($field === 'needs_scope' && empty($profile['needs_scope']) && empty($profile['needs_scope_items'])) {
                $missing[] = 'needs_scope';
            }
            if ($field === 'documents_scope' && empty($profile['documents_scope']) && empty($profile['documents_scope_items'])) {
                $missing[] = 'documents_scope';
            }
            if ($field === 'confirm_scope' && empty($state['analysis_approved'])) {
                $missing[] = 'confirm_scope';
            }
        }

        return $missing;
    }

    private function builderLlmAllowedValuesForStep(string $step, array $profile, array $state): array
    {
        return match ($step) {
            'business_type' => $this->builderLlmAllowedBusinessTypes(),
            'operation_model' => ['contado', 'credito', 'mixto'],
            'needs_scope' => [
                'citas',
                'historia clinica',
                'inventario',
                'medicamentos',
                'medico en turno',
                'pacientes',
                'duenos',
                'facturacion',
                'pagos',
                'ordenes de trabajo',
                'servicios/tratamientos',
                'productos',
                'muestras/examenes',
                'gastos/costos',
            ],
            'documents_scope' => [
                'factura',
                'historia clinica',
                'orden de trabajo',
                'cotizacion',
                'recibo de pago',
                'receta',
                'remision',
                'control impreso',
                'inventario',
            ],
            'confirm_scope' => ['si', 'no', 'negocio', 'pagos', 'control', 'documentos'],
            default => [],
        };
    }

    private function builderLlmAllowedBusinessTypes(): array
    {
        $playbook = $this->loadDomainPlaybook();
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        $allowed = [];
        foreach ($profiles as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = $this->normalizeBusinessType((string) ($item['key'] ?? ''));
            if ($key !== '') {
                $allowed[] = $key;
            }
        }

        return array_values(array_unique($allowed));
    }

    private function routeBuilderConfirmScopeAssist(?array $assist, array $profile, array $state, string $businessType, array $plan): array
    {
        $mappedValue = $this->extractBuilderLlmAssistMappedValue($assist, 'confirm_scope');
        if ($mappedValue === '') {
            $state['active_task'] = 'builder_onboarding';
            $state['onboarding_step'] = 'confirm_scope';
            return [
                'action' => 'ask_user',
                'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, 'confirm_scope', $profile),
                'state' => $state,
            ];
        }

        if ($mappedValue === 'si') {
            $state['analysis_approved'] = true;
            $state['onboarding_step'] = 'plan_ready';
            $state['confirm_scope_last_hash'] = null;
            $state['confirm_scope_repeats'] = 0;
            $proposal = $this->buildNextStepProposal(
                $businessType,
                $plan,
                $profile,
                (string) ($profile['owner_name'] ?? ''),
                $state
            );
            if (!empty($proposal['command']) && is_array($proposal['command'])) {
                $this->setBuilderPendingCommand($state, (array) $proposal['command']);
                $state['entity'] = $proposal['entity'] ?? null;
                $state['active_task'] = (string) ($proposal['active_task'] ?? 'create_table');
                return ['action' => 'ask_user', 'reply' => (string) ($proposal['reply'] ?? ''), 'state' => $state];
            }

            $this->clearBuilderPendingCommand($state);
            $state['active_task'] = (string) ($proposal['active_task'] ?? 'builder_onboarding');
            return [
                'action' => 'respond_local',
                'reply' => (string) ($proposal['reply'] ?? $this->buildBuilderPlanProgressReply($state, $profile, false)),
                'state' => $state,
            ];
        }

        if ($mappedValue === 'no') {
            $state['onboarding_step'] = 'needs_scope';
            unset($state['analysis_approved']);
            return [
                'action' => 'ask_user',
                'reply' => 'Listo, ajustamos el alcance.' . "\n"
                    . 'Dime que quieres cambiar primero (control del negocio o documentos).',
                'state' => $state,
            ];
        }

        $nextStep = match ($mappedValue) {
            'negocio' => 'business_type',
            'pagos' => 'operation_model',
            'control' => 'needs_scope',
            'documentos' => 'documents_scope',
            default => 'confirm_scope',
        };
        $state['active_task'] = 'builder_onboarding';
        $state['onboarding_step'] = $nextStep;

        return [
            'action' => 'ask_user',
            'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, $nextStep, $profile),
            'state' => $state,
        ];
    }

    private function shouldEscalateUnusableBuilderScopeAnswer(string $text, array $detectedItems): bool
    {
        if (!empty($detectedItems)) {
            return false;
        }

        if ($this->isBuilderUserFrustrated($text) || $this->isClarificationRequest($text)) {
            return true;
        }

        $normalized = strtolower($this->normalize($text));
        if ($normalized === '') {
            return true;
        }

        $genericPatterns = [
            'crear una app',
            'crear un app',
            'hacer una app',
            'hacer un app',
            'crear algo',
            'mi app',
            'mi programa',
            'mi progama',
            'para mi empresa',
            'para mi negocio',
            'por donde vas',
            'q escribes',
            'que escribes',
            'que es es',
        ];
        foreach ($genericPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        if (preg_match('/\b(vendo|vendemos|tengo una|tengo un|mi negocio es|mi empresa es|fabricamos|ofrezco|ofrecemos)\b/u', $normalized) === 1) {
            return true;
        }

        $sanitized = $this->sanitizeRequirementText($normalized);
        if ($sanitized === '' || mb_strlen($sanitized, 'UTF-8') < 12) {
            return true;
        }

        $tokens = preg_split('/[^[:alnum:]_]+/u', $normalized) ?: [];
        $genericTokens = [
            'a', 'algo', 'app', 'ayuda', 'ayudame', 'crear', 'de', 'el', 'empresa', 'en', 'es', 'eso',
            'hacer', 'hacr', 'interesa', 'la', 'lo', 'me', 'mi', 'mis', 'negocio', 'para', 'por',
            'pp', 'progama', 'programa', 'q', 'que', 'te', 'todo', 'una', 'uno', 'vas', 'ya',
        ];
        $meaningfulTokens = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || mb_strlen($token, 'UTF-8') < 3) {
                continue;
            }
            if (in_array($token, $genericTokens, true)) {
                continue;
            }
            $meaningfulTokens[$token] = true;
        }

        return count($meaningfulTokens) < 2;
    }

    private function isBuilderUserFrustrated(string $text): bool
    {
        $normalized = strtolower($this->normalize($text));
        $patterns = [
            'no entendi', 'no entiendo', 'no te entendi', 'no te entiendo', 'que dices', 'que hablas',
            'estas loco', 'no me estas entendiendo', 'no me estas entenid', 'hablas por hablar', 'hablas por hablas', 'estas mal',
            'no se', 'ni idea', 'ayuda', 'me perdi', 'estoy confundido', 'no se que responder',
            'no se que hacer', 'como asi', 'que es eso', 'que significa', 'explicame', 'explicamelo facil',
            'por donde vas', 'q es es',
            'no comprendo', 'bot inutil'
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }
        return false;
    }
    private function inferLastQuestionContext(string $step, string $reply): array
    {
        $reply = strtolower($reply);
        if ($step === 'operation_model' || str_contains($reply, 'contado') || str_contains($reply, 'credito')) {
            return [
                'field' => 'operation_model',
                'options' => ['contado', 'credito', 'mixto']
            ];
        }
        if ($step === 'business_type' || str_contains($reply, 'fisica') || str_contains($reply, 'online')) {
            return [
                'field' => 'operation_channels',
                'options' => ['fisica', 'online', 'ambas']
            ];
        }
        return ['field' => $step];
    }
}
