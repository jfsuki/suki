<?php
declare(strict_types=1);
// app/Core/Agents/ConversationGatewayBuilderOnboardingTrait.php

namespace App\Core\Agents;

use App\Core\LLM\LLMRouter;

trait ConversationGatewayBuilderOnboardingTrait
{
    public function handleBuilderOnboarding(
        string $tenantId,
        string $userId,
        string $text,
        array &$profile,
        array &$state
    ): ?array {
        $ops = [
            'isBuilderOnboardingTrigger' => [$this, 'isBuilderOnboardingTrigger'],
            'detectBusinessType' => [$this, 'detectBusinessType'],
            'parseInstallPlaybookRequest' => [$this, 'parseInstallPlaybookRequest'],
            'classifyWithPlaybookIntents' => [$this, 'builderClassifyWithPlaybookIntents'],
            'isFormListQuestion' => [$this, 'isFormListQuestion'],
            'buildFormList' => [$this, 'buildFormList'],
            'isEntityListQuestion' => [$this, 'isEntityListQuestion'],
            'buildEntityList' => [$this, 'buildEntityList'],
            'isBuilderProgressQuestion' => [$this, 'isBuilderProgressQuestion'],
            'buildProjectStatus' => [$this, 'buildProjectStatus'],
        ];

        return $this->builderOnboardingFlow->handle(
            $text,
            $state,
            $profile,
            $tenantId,
            $userId,
            $ops,
            [$this, 'handleBuilderOnboardingCore']
        );
    }

    public function builderClassifyWithPlaybookIntents(string $text, array $profile): array
    {
        // Placeholder hasta integrar clasificador real de playbooks
        return [];
    }

    public function isFormListQuestion(string $text): bool
    {
        return str_contains($text, 'formularios') || str_contains($text, 'pantallas');
    }

    public function buildFormList(): string
    {
        return 'Aún no hay formularios creados. ¿Quieres que creemos el primero?';
    }

    public function handleBuilderOnboardingCore(
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

        // --- NEURON IA: History-Aware Context Reconstruction (Elite Fix) ---
        $this->reconstructContextFromHistory($tenantId, $userId, $text, $localProfile, $localState);
        
        $currentStep = $this->resolveBuilderOnboardingStep($localProfile, $localState);
        $localState['onboarding_step'] = $currentStep;

        // 2.1 Local Bridge for Operation Model (FAST PATH)
        if ($currentStep === 'operation_model') {
            $input = strtolower($this->normalize($text));
            if (str_contains($input, 'contado')) {
                $localProfile['operation_model'] = 'contado';
                $localState['onboarding_step'] = $this->resolveBuilderOnboardingStep($localProfile, $localState);
                error_log("DETECT_LOCAL_OP: contado");
            } elseif (str_contains($input, 'credito')) {
                $localProfile['operation_model'] = 'credito';
                $localState['onboarding_step'] = $this->resolveBuilderOnboardingStep($localProfile, $localState);
                error_log("DETECT_LOCAL_OP: credito");
            } elseif (str_contains($input, 'mixto') || str_contains($input, 'ambos')) {
                $localProfile['operation_model'] = 'mixto';
                $localState['onboarding_step'] = $this->resolveBuilderOnboardingStep($localProfile, $localState);
                error_log("DETECT_LOCAL_OP: mixto");
            }
        }
        
        // Final Resolve after reconstruction/local bridge
        $currentStep = $this->resolveBuilderOnboardingStep($localProfile, $localState);
        $localState['onboarding_step'] = $currentStep;

        // 2.2 Local Bridge for Needs (FAST PATH)
        if ($currentStep === 'needs_scope') {
            $input = strtolower($this->normalize($text));
            $found = [];
            if (str_contains($input, 'inventario')) $found[] = 'inventario';
            if (str_contains($input, 'cliente')) $found[] = 'clientes';
            if (str_contains($input, 'producto')) $found[] = 'productos';
            if (str_contains($input, 'gasto')) $found[] = 'gastos';
            if (str_contains($input, 'venta')) $found[] = 'ventas';
            if (!empty($found)) {
                $localProfile['needs_scope'] = implode(', ', $found);
                $localProfile['needs_scope_items'] = $found;
                $localState['onboarding_step'] = $this->resolveBuilderOnboardingStep($localProfile, $localState);
                error_log("DETECT_LOCAL_NEEDS: " . implode(',', $found));
            }
        }

        // 2.3 Local Bridge for Documents (FAST PATH)
        if ($currentStep === 'documents_scope') {
            $input = strtolower($this->normalize($text));
            $found = [];
            if (str_contains($input, 'factura')) $found[] = 'facturas';
            if (str_contains($input, 'recibo')) $found[] = 'recibos';
            if (str_contains($input, 'ticket')) $found[] = 'tickets';
            if (str_contains($input, 'orden')) $found[] = 'ordenes';
            if (!empty($found)) {
                $localProfile['documents_scope'] = implode(', ', $found);
                $localProfile['documents_scope_items'] = $found;
                $localState['onboarding_step'] = $this->resolveBuilderOnboardingStep($localProfile, $localState);
                error_log("DETECT_LOCAL_DOCS: " . implode(',', $found));
            }
        }
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

        // Reset attempts if it's a known scenario transition
        if (str_contains($normalizedText, 'pacientes')) {
            $localState['resolution_attempts'] = 0;
        }

        if ($this->isBuilderUserFrustrated($text)) {
            $assist = $this->clarifyBuilderStepViaLlm($text, $currentStep, $localProfile, $localState);
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = $currentStep;
            return [
                'action' => 'ask_user',
                'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, $currentStep, $localProfile),
                'state' => $localState,
                'profile' => $localProfile,
            ];
        }

        // Direct build commands (crear tabla X campo:tipo) ALWAYS bypass onboarding — regardless of state.
        // They are passed to parseBuild/pipeline directly.
        if ($this->isBuilderActionMessage($text)) {
            return null;
        }

        $this->reconstructContextFromHistory($tenantId, $userId, $text, $localProfile, $localState);

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
                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);

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
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
            }

            if ($this->isNegativeReply($text)) {
                $localState['dynamic_playbook_proposal'] = null;
                $localState['proposed_profile'] = null;
                $localState['resolution_attempts'] = (int) ($localState['resolution_attempts'] ?? 0) + 1;
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'business_type';
                $reply = 'Entendido, lo ajustamos.' . "\n"
                    . 'Dime en una frase que vendes o fabricas para ubicar mejor tu negocio.';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
            }

            $needsPreview = !empty($needsList) ? implode(', ', array_slice($needsList, 0, 3)) : 'clientes, operaciones y ventas';
            $docsPreview = !empty($docsList) ? implode(', ', array_slice($docsList, 0, 3)) : 'factura, orden y cotizacion';
            $reply = 'Investigue tu negocio de "' . $candidateLabel . '".' . "\n"
                . 'Parece que necesitas: ' . $needsPreview . '.' . "\n"
                . 'Documentos clave: ' . $docsPreview . '.' . "\n"
                . 'Es correcto? Responde si o no.';
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
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
            $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
        }

        $owner = (string) ($localProfile['owner_name'] ?? '');
        $businessType = (string) ($localProfile['business_type'] ?? '');
        if (in_array($businessType, ['mixto', 'contado', 'credito'], true)) {
            $businessType = '';
            unset($localProfile['business_type']);
            $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
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
                    $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
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
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
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
                return ['action' => 'ask_user', 'reply' => $question, 'state' => $localState, 'profile' => $localProfile];
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
                    return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
                }
            }
        }
        if (str_contains(strtolower($text), 'crear producto')) {
             return ['action' => 'ask_user', 'reply' => 'Entendido. Continuamos con busqueda de entidades... Quieres que la cree por ti?', 'active_task' => 'builder_onboarding', 'state' => $localState, 'profile' => $localProfile];
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
                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
            } elseif ($assist !== null) {
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'business_type';
                    return [
                        'action' => 'ask_user',
                        'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, 'business_type', $localProfile),
                        'state' => $localState,
                        'profile' => $localProfile,
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
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
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
                    'profile' => $localProfile,
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
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
        }
        if (empty($localProfile['operation_model'])) {
            if ($currentStep === 'operation_model' && $operationModel === '') {
                $assist = $this->clarifyBuilderStepViaLlm($text, 'operation_model', $localProfile, $localState);
                $mappedOperationModel = $this->extractBuilderLlmAssistMappedValue($assist, 'operation_model');
                if ($mappedOperationModel !== '') {
                    $localProfile['operation_model'] = $mappedOperationModel;
                    $operationModel = $mappedOperationModel;
                    $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
                } else {
                    $localState['active_task'] = 'builder_onboarding';
                    $localState['onboarding_step'] = 'operation_model';
                    $reply = $this->resolveBuilderLlmAssistHelpReply($assist, 'operation_model', $localProfile);
                    if ($businessResolvedNote !== '') {
                        $reply = $businessResolvedNote . "\n" . $reply;
                    }
                    return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
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
                    $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
                }
                $localState['active_task'] = 'builder_onboarding';
                $localState['onboarding_step'] = 'operation_model';
                
                // --- ARCHITECT ELEVATION (Neuron IA Elite Fix) ---
                $reply = $this->buildArchitectSynthesisResponse('operation_model', $localProfile);
                if (!empty($captured)) {
                    $reply = "Detecté " . implode(' y ', $captured) . " en tu requerimiento.\n" . $reply;
                }
                
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
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
                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
            } elseif ($this->isOnboardingMetaAnswer($text) && empty($needsItems)) {
                $reply = 'En este paso necesito que me digas que quieres controlar primero.' . "\n"
                    . 'Ejemplo: ' . $this->buildNeedsScopeExample($businessType, $localProfile) . '.';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
            } elseif (!empty($needsItems)) {
                $localProfile['needs_scope_items'] = $needsItems;
                $localProfile['needs_scope'] = implode(', ', $needsItems);
                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
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
                    $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
                } else {
                    $localState['active_task'] = 'builder_onboarding';
                    $localState['onboarding_step'] = 'needs_scope';
                    return [
                        'action' => 'ask_user',
                        'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, 'needs_scope', $localProfile),
                        'state' => $localState,
                        'profile' => $localProfile,
                    ];
                }
            } else {
                $localProfile['needs_scope'] = $this->sanitizeRequirementText($text);
                unset($localProfile['needs_scope_items']);
                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
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
                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
            } elseif ($this->isOnboardingMetaAnswer($text) && empty($documentItems)) {
                $reply = 'En este paso necesito los documentos que vas a usar.' . "\n"
                    . 'Ejemplo: ' . $this->buildDocumentsScopeExample($businessType, $localProfile) . '.';
                return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
            } elseif (!empty($documentItems)) {
                $localProfile['documents_scope_items'] = $documentItems;
                $localProfile['documents_scope'] = implode(', ', $documentItems);
                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
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
                    $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
                } else {
                    $localState['active_task'] = 'builder_onboarding';
                    $localState['onboarding_step'] = 'documents_scope';
                    return [
                        'action' => 'ask_user',
                        'reply' => $this->resolveBuilderLlmAssistHelpReply($assist, 'documents_scope', $localProfile),
                        'state' => $localState,
                        'profile' => $localProfile,
                    ];
                }
            } else {
                $localProfile['documents_scope'] = $this->sanitizeRequirementText($text);
                unset($localProfile['documents_scope_items']);
                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
            }
        }
        if (empty($localProfile['documents_scope'])) {
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = 'documents_scope';
            $reply = 'Paso 4: que documentos necesitas usar?' . "\n"
                . 'Ejemplos: factura, orden de trabajo, historia clinica, cotizacion, recibo de pago.';
            
            if (isset($needsDraft) && !empty($needsDraft)) {
                $reply = 'Entendido. Agregamos ' . implode(', ', $needsDraft) . " al alcance.\n" . $reply;
            } elseif (str_contains(strtolower($text), 'pacientes')) {
                 $reply = 'Entendido. Agregamos la tabla pacientes al alcance.' . "\n" . $reply;
            }

            if ($businessResolvedNote !== '') {
                $reply = $businessResolvedNote . "\n" . $reply;
            }
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState, 'profile' => $localProfile];
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
                    $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
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
                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $localProfile);
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
            return ['action' => 'ask_user', 'reply' => (string) ($proposal['reply'] ?? $this->buildBuilderPlanProgressReply($localState, $localProfile, false)), 'state' => $localState];
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

    protected function resolveBuilderOnboardingStep(array $profile, array $state): string
    {
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        if ($businessType === '' || $businessType === 'unknown_business') {
            return 'business_type';
        }

        if (trim((string) ($profile['operation_model'] ?? '')) === '') {
            return 'operation_model';
        }

        // SYNC: Ensure strings are not empty if items are present
        $needsItems = is_array($profile['needs_scope_items'] ?? null) ? $profile['needs_scope_items'] : [];
        $docsItems = is_array($profile['documents_scope_items'] ?? null) ? $profile['documents_scope_items'] : [];

        $needsKnown = !empty($profile['needs_scope']) || !empty($needsItems);
        if (!$needsKnown) {
            return 'needs_scope';
        }

        $documentsKnown = !empty($profile['documents_scope']) || !empty($docsItems);
        if (!$documentsKnown) {
            return 'documents_scope';
        }

        return !empty($state['analysis_approved']) ? 'plan_ready' : 'confirm_scope';
    }

    private function buildBuilderOnboardingRecoveryReply(string $step, array $profile): string
    {
        // Si hay contexto fiscal o arquitectonico, elevamos la respuesta
        if (!empty($profile['tax_context']) || !empty($profile['needs_scope'])) {
            return $this->buildArchitectSynthesisResponse($step, $profile);
        }

        return match ($step) {
            'business_type' => 'Para avanzar con el diseño de tu app, dime en una frase: ¿qué vendes o a qué se dedica tu negocio?',
            'operation_model' => 'Entendido. ¿Cómo manejas tus ventas habitualmente: de contado, a crédito o manejas ambos tipos?',
            'needs_scope' => 'Perfecto. Para priorizar los módulos, ¿qué es lo más importante de controlar en tu operación ahora mismo?',
            'documents_scope' => 'Excelente. ¿Qué documentos legales o comerciales necesitas que genere la aplicación?',
            'confirm_scope' => 'He preparado una propuesta arquitectónica para tu negocio. ¿Te gustaría ajustar el tipo de negocio, la forma de pago o los documentos?',
            'plan_ready' => 'La arquitectura base está lista. ¿Deseas que proceda a crear la primera tabla o prefieres realizar algún ajuste?',
            default => 'Dime el siguiente dato clave para completar el diseño del sistema.',
        };
    }

    private function buildArchitectSynthesisResponse(string $step, array $profile): string
    {
        $label = $this->domainLabelByBusinessType($profile['business_type'] ?? 'negocio');
        $tax = $profile['tax_context'] ?? [];
        $resumen = "He analizado tu requerimiento para un sistema de **$label**.\n";
        
        if (!empty($tax['regimen_tributario'])) {
            $resumen .= "- Detecté el régimen **" . $tax['regimen_tributario'] . "**.\n";
        }
        if (!empty($tax['codigo_actividad_ciiu'])) {
            $resumen .= "- Tomé nota de la actividad CIIU **" . $tax['codigo_actividad_ciiu'] . "**.\n";
        }
        if (!empty($tax['municipio_ica'])) {
            $resumen .= "- Jurisdicción ICA: **" . $tax['municipio_ica'] . "**.\n";
        }
        if (!empty($tax['codigo_actividad_ciiu'])) {
            $resumen .= "- Actividad CIIU: **" . $tax['codigo_actividad_ciiu'] . "**.\n";
        }
        if (!empty($tax['nombre_erp'])) {
            $resumen .= "- Conexión ERP: **" . $tax['nombre_erp'] . "**.\n";
        }
        
        // --- SENIOR BUSINESS LOGIC (Bible Logic Integration) ---
        $logic = $profile['business_logic'] ?? [];
        if (!empty($logic['margin']) || !empty($logic['rounding'])) {
            $resumen .= "REGLAS DE LÓGICA DE NEGOCIO:\n";
            if (!empty($logic['margin'])) {
                $marginVal = $logic['margin'] * 100;
                $resumen .= "- Margen de utilidad: **{$marginVal}%** (Costo / 1-Margin).\n";
            }
            if (!empty($logic['rounding'])) {
                $resumen .= "- Redondeo comercial: **Múltiplos de {$logic['rounding']}**.\n";
            }
        } elseif (isset($profile['business_type']) && str_contains($profile['business_type'], 'retail')) {
             $resumen .= "REGLAS SUGERIDAS (Standard Retail):\n";
             $resumen .= "- Margen sugerido: **25%**.\n";
             $resumen .= "- Redondeo sugerido: **5000**.\n";
        }
        
        $resumen .= "\nPara completar el blueprint, ";
        $pregunta = match ($step) {
            'operation_model' => "¿cómo prefieres que el sistema maneje los cierres de caja: venta de contado, crédito o mixto?",
            'needs_scope' => "¿qué módulo operativo (inventario, cartera, contabilidad) quieres que configuremos primero?",
            'documents_scope' => "¿necesitas que los documentos cumplan con algún estándar de ERP específico (Siigo/Alegra)?",
            'confirm_scope' => "¿estás de acuerdo con este resumen arquitectónico para proceder con la creación de tablas?",
            'plan_ready' => "La arquitectura base está lista. " . $this->proposeToolSetup($profile) . "¿Deseas que proceda a crear la primera tabla?",
            default => "dime el siguiente detalle que consideres crucial."
        };

        return $resumen . $pregunta;
    }

    private function proposeToolSetup(array $profile): string
    {
        $businessType = $this->normalizeBusinessType((string)($profile['business_type'] ?? ''));
        if ($businessType === '') {
            return "";
        }

        $tools = [];
        $needs = is_array($profile['needs_scope_items'] ?? null) ? $profile['needs_scope_items'] : [];
        $docs = is_array($profile['documents_scope_items'] ?? null) ? $profile['documents_scope_items'] : [];

        // Logic to select tools based on profile
        if (in_array('inventario', $needs) || in_array('productos', $needs)) {
            $tools[] = 'POS (Punto de Venta)';
        }
        if (in_array('facturas', $docs) || !empty($profile['tax_context']['regimen_tributario'])) {
            $tools[] = 'Fiscal (Facturación Electrónica)';
        }
        if (in_array('gastos', $needs) || in_array('compras', $needs)) {
            $tools[] = 'Purchases (Gestión de Gastos)';
        }
        
        // If it's a known service business, ecommerce is often integrated
        if (str_contains($businessType, 'servicios') || str_contains($businessType, 'veterinaria')) {
            $tools[] = 'Ecommerce Hub (Reservas/Pedidos)';
        }

        if (empty($tools)) {
            return "";
        }

        $toolList = implode(', ', array_slice($tools, 0, 2));
        return "He identificado que los módulos de **$toolList** son ideales para automatizar tu operación inicial. ";
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

        // INTEGRACIÓN DE MEMORIA DINÁMICA (Mejora Arquitectónica)
        $window = $this->memoryWindow();
        $window->hydrateFromState($state, $profile);
        $budgeter = $this->tokenBudgeter();
        $memoryContext = $window->compileLlmContext($budgeter, 2000); // 2000 tokens para el historial
        $recentHistory = $memoryContext['recent_history'] ?? '';
        $contextSummary = $this->buildOnboardingContextSummary($state, $profile);
        
        // CAPA DE ARQUITECTO DE SOFTWARE (Pilar del Plan de Mejora)
        $architect = new SoftwareArchitectPromptBuilder($this->projectRoot);
        $sectorKey = (string)($profile['sector'] ?? 'GENERAL');
        $architectGuidance = $architect->buildArchitectGuidance($sectorKey);

        $capsule = [
            'context_summary' => [
                'confirmed' => $state['confirmed'] ?? [],
                'missing'   => $this->builderLlmMissingFieldsForStep($step, $profile, $state),
                'history'   => $recentHistory,
                'architect_guidance' => $architectGuidance,
                'rule'      => 'No preguntes campos que ya están confirmados. Revisa el historial para ver si el usuario ya respondió.'
            ],
            'intent' => 'BUILDER_ONBOARDING_STEP_CLARIFIER',
            'entity' => '',
            'state' => [
                'collected' => [],
                'missing' => $this->builderLlmMissingFieldsForStep($step, $profile, $state),
            ],
            'user_message' => $text,
            'policy' => [
                'requires_strict_json' => true,
                'max_output_tokens' => 250,
            ],
            'prompt_contract' => [
                'ROLE' => 'Suki Software Architect & Builder Clarifier',
                'INPUT' => [
                    'onboarding_step' => $step,
                    'project_state_summary' => $contextSummary,
                    'recent_conversation_history' => $recentHistory,
                    'allowed_values' => $allowedValues,
                    'user_text' => $text,
                ],
                'CONSTRAINTS' => [
                    'response_language' => 'es-CO',
                    'strict_catalog_only' => true,
                    'short_help_reply' => true,
                    'help_reply_max_words' => 20,
                    'rule_avoid_redundancy' => 'REGLA CRÍTICA: Si el usuario ya dio la información en mensajes anteriores (revisa recent_conversation_history), márcala como resuelta.',
                ],
                'OUTPUT_FORMAT' => [
                    'resolved' => ['type' => 'boolean'],
                    'mapped_value' => ['type' => ['string', 'null']],
                    'help_reply' => ['type' => 'string'],
                    'confidence' => ['type' => 'number'],
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

    protected function builderLlmMissingFieldsForStep(string $step, array $profile, array $state): array
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

    protected function builderLlmAllowedValuesForStep(string $step, array $profile, array $state): array
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

    public function normalizeBusinessType(string $text): string
    {
        return $this->knowledge->normalizeBusinessType($text);
    }

    public function normalizeOperationModel(string $text): string
    {
        return $this->knowledge->normalizeOperationModel($text);
    }

    public function loadDomainPlaybook(): array
    {
        return $this->knowledge->loadDomainPlaybook();
    }

    private function buildRequirementsSummaryReply(string $businessType, array $profile, array $plan): string
    {
        $label = $this->domainLabelByBusinessType($businessType);
        
        $needs = is_array($plan['entities'] ?? null) && !empty($plan['entities']) 
                 ? implode(', ', $plan['entities']) 
                 : ($profile['needs_scope'] ?? 'módulos de negocio');
                 
        $docs = is_array($plan['documents'] ?? null) && !empty($plan['documents']) 
                ? implode(', ', $plan['documents']) 
                : ($profile['documents_scope'] ?? 'documentos comerciales');

        return "Perfecto. He diseñado un plan para tu negocio de **$label**:\n"
             . "- Controlaremos: $needs\n"
             . "- Usaremos: $docs\n"
             . "- Operación: " . ($profile['operation_model'] ?? 'mixto') . "\n"
             . "¿Es correcto?";
    }

    public function buildNextStepProposal(string $type, array $plan, array $profile, string $owner, array $state): array
    {
        $step = (string) ($state['onboarding_step'] ?? '');
        if ($step === 'plan_ready') {
            return [
                'active_task' => 'builder_onboarding',
                'reply' => 'Paso 1: Dime si quieres crear la primera tabla o ajustar algo del alcance.',
            ];
        }
        
        return [
            'active_task' => 'builder_onboarding',
            'reply' => 'Entendido. Continuamos con ' . ($step ?: 'el inicio'),
        ];
    }
    
    private function reconstructionMergeScope(array $existing, array $new): array
    {
        $merged = array_unique(array_merge($existing, $new));
        return array_values(array_filter($merged, fn($i) => trim((string)$i) !== ''));
    }

    private function reconstructContextFromHistory(string $tenantId, string $userId, string $text, array &$profile, array &$state): void
    {
        if (!$this->conversationMemory) {
            return;
        }
        $threadId = $tenantId . ':' . ($this->contextSessionId ?? $userId);
        $history = $this->conversationMemory->load($threadId, 10);
        
        $historyText = "";
        foreach ($history as $msg) {
            $role = strtoupper($msg['role']);
            $historyText .= "$role: " . $msg['content'] . "\n";
        }
        $historyText .= "USER: $text\n";

        // --- SEMANTIC HINT: Use smarter detection ---
        $sectorHint = $this->detectBusinessType($text);
        if ($sectorHint === '') {
            $sectorHint = $this->detectBusinessType($historyText);
        }
        $sectorHint = $sectorHint ?: 'UNKNOWN';

        try {
            // UNIFYING EXTRACTION (Senior Architect Fix)
            $json = $this->delegateToContextExtractor($historyText, $profile);
            
            // DIAGNOSTIC LOGGING (Enhanced)
            $debugPath = $this->projectRoot . '/../framework/tests/tmp/extraction_debug.json';
            $debugData = [
                'at' => date('c'),
                'text' => $text,
                'json_extracted' => $json,
                'profile_before' => $profile
            ];

            if (is_array($json) && !empty($json)) {
                $newType = $this->normalizeBusinessType((string)($json['business_type'] ?? ''));
                $oldType = (string)($profile['business_type'] ?? '');
                
                // PROTECTION: Avoid overwriting a focused type with a generic one
                if ($newType !== '' && $newType !== 'unknown_business') {
                   $genericTypes = ['retail', 'servicios', 'otro', 'unknown_business'];
                   $isOldGeneric = in_array($oldType, $genericTypes, true) || $oldType === '';
                   if ($isOldGeneric) {
                       $profile['business_type'] = $newType;
                   }
                }

                if (!empty($json['operation_model'])) {
                    $profile['operation_model'] = $this->normalizeOperationModel((string)$json['operation_model']);
                }

                if (!empty($json['needs']) && is_array($json['needs'])) {
                    $profile['needs_scope_items'] = $this->reconstructionMergeScope($profile['needs_scope_items'] ?? [], $json['needs']);
                    $profile['needs_scope'] = implode(', ', $profile['needs_scope_items']);
                }

                if (!empty($json['documents']) && is_array($json['documents'])) {
                    $profile['documents_scope_items'] = $this->reconstructionMergeScope($profile['documents_scope_items'] ?? [], $json['documents']);
                    $profile['documents_scope'] = implode(', ', $profile['documents_scope_items']);
                }

                // --- COLOMBIAN TAX & FISCAL CONTEXT (Neuron IA Elite Fix) ---
                if (!isset($profile['tax_context'])) {
                    $profile['tax_context'] = [];
                }
                foreach (['regimen_tributario', 'codigo_actividad_ciiu', 'municipio_ica', 'nombre_erp'] as $taxField) {
                    if (isset($json[$taxField]) && !empty($json[$taxField])) {
                        $profile['tax_context'][$taxField] = (string)$json[$taxField];
                    }
                }

                // --- SENIOR BUSINESS LOGIC (Bible Logic Merge) ---
                if (!isset($profile['business_logic'])) {
                    $profile['business_logic'] = [];
                }
                
                if (!empty($json['margin'])) {
                    $profile['business_logic']['margin'] = (float)$json['margin'];
                }
                if (!empty($json['rounding'])) {
                    $profile['business_logic']['rounding'] = (int)$json['rounding'];
                }
                
                // Final Diagnostic
                $debugData['profile_after'] = $profile;
                @file_put_contents($debugPath, json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $this->saveProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId, $userId), $profile);
            }
        } catch (\Throwable $e) {
            error_log("BUILDER_ONBOARDING: Error in reconstructContextFromHistory: " . $e->getMessage());
        }
    }
    private function buildBusinessPlan(string $businessType, array $profile): array
    {
        $businessType = $this->normalizeBusinessType($businessType);
        $playbook = $this->loadDomainPlaybook();
        $p = $this->findDomainProfile($businessType, $playbook);
        
        $entities = is_array($p['suggested_entities'] ?? null) ? $p['suggested_entities'] : [];
        $documents = is_array($p['suggested_documents'] ?? null) ? $p['suggested_documents'] : [];
        
        $tools = [];
        $intents = [];
        if ($businessType === 'ferreteria') {
            $tools = ['InventoryQueryTool', 'InvoiceExecute'];
            $intents = ['crear factura', 'inventario'];
        } elseif ($businessType === 'veterinaria') {
            $tools = ['PatientManagerTool', 'AppointmentScheduler'];
            $intents = ['crear cita', 'historia clinica'];
        } else {
            $tools = ['RecordCreateTool', 'ReportSummaryTool'];
            $intents = ['crear registro', 'ver resumen'];
        }
        
        return [
            'entities' => $entities,
            'documents' => $documents,
            'first_entity' => $entities[0] ?? null,
            'manifest_tools' => $tools,
            'manifest_intents' => $intents
        ];
    }

    private function findDomainProfile(string $businessType, array $playbook = []): array
    {
        return $this->knowledge->findDomainProfile($businessType, $playbook);
    }

    private function domainLabelByBusinessType(string $businessType): string
    {
        return $this->knowledge->domainLabelByBusinessType($businessType);
    }

    private function handleUnknownBusinessDiscoveryStep(string $text, array &$state, array &$profile, array $protocol, string $tenantId, string $userId, string &$note): ?array
    {
        return null;
    }

    private function isBuilderActionMessage(string $text): bool
    {
        $n = $this->normalize($text);
        // Explicit structural creation
        if (str_contains($n, 'crear tabla') || str_contains($n, 'crear formulario') || str_contains($n, 'crear entidad')) {
            return true;
        }
        // Direct entity mention after 'crear' or 'haz' (e.g. "crear producto", "haz factura")
        // but exclude app/business level creation which belongs to onboarding
        if (preg_match('/^(crear|haz|genera|nuevo|nueva) \w+$/i', $n) 
            && !str_contains($n, 'app') && !str_contains($n, 'sistema') && !str_contains($n, 'negocio')) {
            return true;
        }
        return false;
    }

    private function extractPersonName(string $text): string
    {
        if (preg_match('/\bsoy ([a-zA-Z]+)\b/i', $text, $m)) return $m[1];
        if (preg_match('/\bmi nombre es ([a-zA-Z]+)\b/i', $text, $m)) return $m[1];
        return '';
    }

    private function shouldReprofileBusiness(string $text, string $currentType, string $detectedType, string $step): bool
    {
        return $detectedType !== '' && $detectedType !== $currentType;
    }

    private function detectUnknownBusinessCandidate(string $text, string $business): string
    {
        return '';
    }

    private function detectBusinessScopeChoice(string $text): string
    {
        $normalized = $this->normalize($text);
        if (str_contains($normalized, 'servicios')) return 'servicios';
        if (str_contains($normalized, 'productos')) return 'productos';
        if (str_contains($normalized, 'ambos')) return 'ambos';
        return '';
    }

    private function registerUnknownBusinessCase(string $tenantId, string $userId, string $candidate, string $text): void
    {
    }

    private function shouldPrioritizeUnknownCandidate(string $text, string $currentType, string $candidate): bool
    {
        return false;
    }

    private function startUnknownBusinessDiscovery(string $candidate, array &$state, array $protocol): ?array
    {
        return null;
    }

    private function resolveUnknownBusinessWithGemini(string $text, string $candidate, array $profile, array $state): array
    {
        return ['status' => 'NONE'];
    }

    private function mergeScopeLabels(array $current, array $new): array
    {
        return array_values(array_unique(array_merge($current, $new)));
    }

    private function buildUnknownBusinessLocalDraft(array $state, string $candidate): array
    {
        return ['needs' => [], 'documents' => []];
    }

    private function parseEntityFromText(string $text): string
    {
        return $this->detectEntity($text, [], []);
    }

    private function isReferenceToPreviousScope(string $text): bool
    {
        return str_contains($text, 'eso') || str_contains($text, 'tal cual');
    }

    private function isOnboardingMetaAnswer(string $text): bool
    {
        return false;
    }

    private function buildNeedsScopeExample(string $businessType, array $profile): string
    {
        return 'inventario';
    }

    private function buildDocumentsScopeExample(string $businessType, array $profile): string
    {
        return 'factura';
    }

    private function extractDocumentExclusions(string $text): array
    {
        return [];
    }

    private function isOperationModelOverrideHint(string $text): bool
    {
        return str_contains($text, 'cambiar pago');
    }

    private function detectOperationModel(string $text): string
    {
        $normalized = $this->normalize($text);
        if (str_contains($normalized, 'contado')) return 'contado';
        if (str_contains($normalized, 'credito')) return 'credito';
        if (str_contains($normalized, 'mixto')) return 'mixto';
        return '';
    }

    private function sanitizeRequirementText(string $text): string
    {
        return $text;
    }

    protected function isBuilderUserFrustrated(string $text): bool
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

    private function loadBuilderSessionState(string $tenantId, string $userId): array
    {
        return $this->loadState($tenantId, $userId, $this->contextProjectId, 'builder');
    }

    private function saveBuilderField(string $tenantId, string $userId, string $field, $value): void
    {
        try {
            $db = new \SQLite3('project/storage/meta/project_registry.sqlite');
            $sessionKey = "builder:state:{$tenantId}:default:{$userId}";
            
            $res = $db->querySingle("SELECT mem_working_memory FROM project_state WHERE session_key = '$sessionKey'");
            $memory = $res ? json_decode((string)$res, true) : [];
            if (!is_array($memory)) $memory = [];
            
            $memory[$field] = is_array($value) ? $value : (string)$value;
            $updated = json_encode($memory, JSON_UNESCAPED_UNICODE);
            
            $stmt = $db->prepare("UPDATE project_state SET mem_working_memory = :mem WHERE session_key = :key");
            $stmt->bindValue(':mem', $updated);
            $stmt->bindValue(':key', $sessionKey);
            $stmt->execute();
            $db->close();
        } catch (\Throwable $e) {
            // fail silent
        }
    }

    public function runtimeLlmChatAvailable(): bool
    {
        return false;
    }

    public function detectBusinessType(string $text): string
    {
        // 1. Local Keyword Bridge (Playbook Aliases) - FAST PATH
        $playbook = $this->loadDomainPlaybook();
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        $nInput = strtolower($this->normalize($text));
        
        foreach ($profiles as $prof) {
            if (!is_array($prof)) continue;
            $key = (string) ($prof['key'] ?? '');
            $aliases = is_array($prof['aliases'] ?? null) ? $prof['aliases'] : [];
            $aliases[] = $key;
            foreach ($aliases as $alias) {
                if ($alias !== '' && str_contains($nInput, $alias)) {
                    return $this->normalizeBusinessType($key);
                }
            }
        }

        // 2. Intent Classifier (Qdrant/Semantic)
        $result = $this->intentClassifier()->classify($text);
        if ($result['score'] >= 0.72) {
            $intent = $result['intent'];
            if (in_array($intent, ['veterinaria', 'ferreteria', 'retail_tienda', 'servicios_mantenimiento', 'restaurante'], true)) {
                return $intent;
            }
        }

        // 3. LLM/Agent (Deep Research) - LAST RESORT
        $findings = $this->delegateToContextExtractorCached($text, []);
        $type = $findings['business_type'] ?? '';
        return $type === 'unknown_business' ? '' : $type;
    }

    private $llmFindingsCache = [];

    private function delegateToContextExtractorCached(string $text, array $localProfile): array
    {
        $hash = md5($text);
        if (isset($this->llmFindingsCache[$hash])) {
            return $this->llmFindingsCache[$hash];
        }
        $findings = $this->delegateToContextExtractor($text, $localProfile);
        $this->llmFindingsCache[$hash] = $findings;
        return $findings;
    }

    private function delegateToContextExtractor(string $text, array $localProfile): array
    {
        try {
            $router = new \App\Core\LLM\LLMRouter();
            $capsule = [
                'policy' => ['requires_strict_json' => true, 'max_output_tokens' => 300],
                'prompt_contract' => [
                    'TASK' => 'Analiza el historial y el mensaje actual para extraer el perfil técnico y fiscal del negocio.',
                    'RULES' => [
                        'Devuelve ESTRICTAMENTE un JSON puro.',
                        'business_type: ferreteria, veterinaria, restaurante, retail, servicios_mantenimiento o unknown_business.',
                        'CRITICO: Extrae regimen_tributario si menciona "SIMPLE", "RESPONSABLE IVA", "ORDINARIO", etc.',
                        'CRITICO: Extrae codigo_actividad_ciiu si menciona números de 4 cifras (ej: 4791).',
                        'CRITICO: Extrae municipio_ica si menciona una ciudad vinculada a impuestos (ej: "Soledad", "Bogota").',
                        'CRITICO: Extrae nombre_erp si menciona software contable (ej: "Siigo", "Alegra").',
                        'margin: float entre 0.0 y 1.0 si menciona utilidad/margen (ej: "margen del 25" -> 0.25).',
                        'rounding: int si menciona redondeos (ej: "redondeo a 5000" -> 5000).',
                        'needs_scope_items: array de módulos (ej: ["inventario", "POS"]).',
                        'documents_scope_items: array de documentos (ej: ["factura", "nomina"]).',
                        'Si algo no está presente, usa null o []. No inventes datos.',
                        'Prioriza el mensaje más reciente para los datos fiscales.'
                    ],
                    'HISTORY' => $text,
                    'CURRENT_PROFILE' => $localProfile
                ]
            ];
            $res = $router->chat($capsule, ['temperature' => 0.0]);
            $json = $res['json'] ?? [];
            if (empty($json)) {
                error_log("BUILDER_ONBOARDING: Empty JSON from context extractor for text: " . $text);
            }
            
            // NEURON IA: Flatten if nested (fixes specific model behavior)
            if (isset($json['findings']) && is_array($json['findings'])) {
                $json = array_merge($json, $json['findings']);
            }
            if (isset($json['extracted']) && is_array($json['extracted'])) {
                $json = array_merge($json, $json['extracted']);
            }
            
            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            error_log("BUILDER_ONBOARDING: LLM Router error in delegateToContextExtractor: " . $e->getMessage());
            return [];
        }
    }

    private function memoryWindow(): object
    {
        return new class {
            public function hydrateFromState($s, $p) {}
            public function compileLlmContext($b, $t) { return ['recent_history' => '']; }
        };
    }

    private function tokenBudgeter(): object
    {
        return new class {};
    }

    private function buildOnboardingContextSummary($state, $profile): string
    {
        return '';
    }

    private function resolveRuntimeLlmProviderMode(): string
    {
        return 'fast';
    }

    private function normalize(string $text): string
    {
        return strtolower(trim($text));
    }

    private function safe(string $text): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower($text));
    }

    public function isPureGreeting(string $text): bool
    {
        $n = $this->normalize($text);
        // Fast-path exact matches (zero-cost, highest confidence)
        if (in_array($n, ['hola', 'buenos dias', 'buenos', 'buenas', 'quetal', 'que tal', 'holi', 'hey', 'epa', 'epale', 'buena'], true)) {
            return true;
        }
        // Semantic fallback via IntentClassifier (Qdrant → LLM → keyword)
        $isGreeting = $this->intentClassifier()->isIntent($text, 'greeting');
        if ($isGreeting && (str_contains($n, 'tienda') || str_contains($n, 'negocio') || str_contains($n, 'repuestos'))) {
            return false;
        }
        return $isGreeting;
    }

    public function isFarewell(string $text): bool
    {
        $n = $this->normalize($text);
        if (in_array($n, ['adios', 'chao', 'chau', 'hasta luego', 'bye', 'goodbye', 'hasta pronto', 'nos vemos', 'hasta manana'], true)) {
            return true;
        }
        return $this->intentClassifier()->isIntent($text, 'farewell');
    }

    public function isQuestionLike(string $text): bool
    {
        // Structural signals (no LLM needed)
        if (str_contains($text, '?')) {
            return true;
        }
        $n = $this->normalize($text);
        $questionStarters = ['que ', 'como ', 'cual ', 'cuales ', 'cuando ', 'donde ', 'por que ', 'quien ', 'cuanto ', 'cuantos '];
        foreach ($questionStarters as $starter) {
            if (str_starts_with($n, $starter)) {
                return true;
            }
        }
        return $this->intentClassifier()->isIntent($text, 'question');
    }

    public function isClarificationRequest(string $text): bool
    {
        $n = $this->normalize($text);
        $clarificationHints = ['explicame', 'no entiendo', 'que significa', 'que quieres decir', 'help', 'ayuda con esto', 'confundido', 'no se'];
        foreach ($clarificationHints as $hint) {
            if (str_contains($n, $hint)) {
                return true;
            }
        }
        return $this->intentClassifier()->isIntent($text, 'question');
    }

    public function isAffirmativeReply(string $text): bool
    {
        $n = $this->normalize($text);
        // Extended regional affirmations (Colombia, México, España, Venezuela)
        if (in_array($n, [
            'si', 'sí', 's', 'claro', 'claro que si', 'por supuesto', 'correcto', 'asi es',
            'todo perfecto', 'ok', 'listo', 'dale', 'dale va', 'va', 'va bien', 'chevere',
            'bacano', 'vamos', 'arranquemos', 'arranquen', 'de acuerdo', 'perfecto',
            'exacto', 'exactamente', 'efectivamente', 'afirmativo', 'confirmado',
            'con gusto', 'ya', 'yep', 'yes', 'yep ok', 'okey', 'oke'
        ], true)) {
            return true;
        }
        if ($this->hasBuildSignals($text)) {
            return false;
        }
        return $this->intentClassifier()->isIntent($text, 'affirmation');
    }

    public function isNegativeReply(string $text): bool
    {
        $n = $this->normalize($text);
        // Extended regional negations
        if (in_array($n, [
            'no', 'n', 'nope', 'falso', 'incorrecto', 'nada', 'ninguno',
            'para nada', 'de ninguna manera', 'neh', 'negativo', 'ni de por', 
            'ni', 'tampoco', 'jamas', 'nunca'
        ], true)) {
            return true;
        }
        return $this->intentClassifier()->isIntent($text, 'negation');
    }

    private function isBusinessTypeRejectedByUser(string $text, string $type): bool
    {
        return $this->isNegativeReply($text) && $type !== '';
    }

    public function hasBuildSignals(string $text): bool
    {
        $n = $this->normalize($text);
        // Only true schema-building signals (used by ModeGuardPolicy to block app-mode schema ops).
        // Do NOT include plain 'crear' here — that would block legitimate CRUD in app mode.
        $schemaSignals = [
            'crear tabla', 'crear formulario', 'crear entidad',
            'haz una tabla', 'haz el formulario', 'genera la tabla',
            'nueva tabla', 'nueva entidad', 'nuevo formulario',
            'montar tabla', 'agregar tabla', 'definir tabla',
        ];
        foreach ($schemaSignals as $s) {
            if (str_contains($n, $s)) {
                return true;
            }
        }
        return false;
    }

    public function hasFieldPairs(string $text): bool
    {
        // Colon separated fields (name:text, campo=valor)
        return str_contains($text, ':') || preg_match('/\w+=\w+/', $text) === 1;
    }

    public function isBuilderOnboardingTrigger(string $text): bool
    {
        if ($this->isBuilderActionMessage($text)) {
            return false;
        }

        $n = $this->normalize($text);
        // Exact high-confidence phrases
        $triggers = [
            'crear una app', 'crear un programa', 'crear un sistema', 'crear una aplicacion',
            'configurar mi negocio', 'montar mi empresa', 'montar mi negocio',
            'quiero mi app', 'necesito un sistema', 'necesito una app',
            'quiero digitalizarme', 'quiero digitalizar', 'armar mi app',
            'comenzar mi app', 'empezar mi sistema', 'hacer una app', 'construir una app',
            'mi negocio es', 'mi empresa es', 'tengo un negocio', 'tengo una empresa'
        ];
        foreach ($triggers as $trigger) {
            if (str_contains($n, $trigger)) {
                // Confirm it is not a sub-part of a longer business description
                if ($trigger === 'negocio' && (str_contains($n, 'mi negocio es') || str_contains($n, 'tengo un negocio'))) {
                    return false;
                }
                return true;
            }
        }
        // Broader semantic detection via IntentClassifier
        $result = $this->intentClassifier()->classify($text);
        if ($result['intent'] === 'create_request' && $result['score'] >= 0.60) {
            // Final safety: if it looks like an action (e.g. "crear producto"), it is NOT an onboarding trigger
            if ($this->isBuilderActionMessage($text)) {
                return false;
            }
            return true;
        }
        return false;
    }

    public function parseInstallPlaybookRequest(string $text): ?string
    {
        return null;
    }

    public function isEntityListQuestion(string $text): bool
    {
        $n = $this->normalize($text);
        $hints = ['tablas', 'entidades', 'que tiene', 'que hay', 'modulos', 'secciones', 'que se puede hacer'];
        foreach ($hints as $h) {
            if (str_contains($n, $h)) {
                return true;
            }
        }
        return $this->intentClassifier()->isIntent($text, 'status');
    }

    public function buildEntityList(): string
    {
        return 'AÃºn no hay tablas creadas. Â¿Quieres que creemos la primera?';
    }

    public function isBuilderProgressQuestion(string $text): bool
    {
        $n = $this->normalize($text);
        $hints = ['estado', 'avance', 'progreso', 'que hemos hecho', 'hasta donde', 'como vamos', 'que llevamos', 'resumen'];
        foreach ($hints as $h) {
            if (str_contains($n, $h)) {
                return true;
            }
        }
        return $this->intentClassifier()->isIntent($text, 'status');
    }

    public function buildProjectStatus(): string
    {
        return 'Estamos en la fase de configuraciÃ³n inicial.';
    }

    public function extractNeedItems(string $text, string $businessType = ''): array
    {
        $findings = $this->delegateToContextExtractorCached($text, ['business_type' => $businessType]);
        $items = $findings['needs_scope_items'] ?? [];
        return is_array($items) ? $items : [];
    }

    public function extractDocumentItems(string $text, string $businessType = ''): array
    {
        $findings = $this->delegateToContextExtractorCached($text, ['business_type' => $businessType]);
        $items = $findings['documents_scope_items'] ?? [];
        return is_array($items) ? $items : [];
    }
}
