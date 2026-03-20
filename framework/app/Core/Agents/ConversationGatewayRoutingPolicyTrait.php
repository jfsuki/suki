<?php
declare(strict_types=1);
// app/Core/Agents/ConversationGatewayRoutingPolicyTrait.php

namespace App\Core\Agents;

trait ConversationGatewayRoutingPolicyTrait
{
    private function routeTraining(string $text, array $training, array $profile = [], string $tenantId = 'default', string $userId = 'anon', array $state = [], array $lexicon = [], string $mode = 'app'): array
    {
        $route = [];
        if ($mode === 'builder' && (string) ($state['active_task'] ?? '') === 'integration_setup') {
            if (preg_match('/https?:\/\/[a-z0-9\.\-\/_\?&=%#]+/i', $text, $m) === 1) {
                $docUrl = trim((string) ($m[0] ?? ''));
                $pairs = $this->parseKeyValues($text);
                $apiName = trim((string) ($pairs['api'] ?? $pairs['nombre'] ?? $pairs['integracion'] ?? 'api_externa'));
                if ($apiName === '') {
                    $apiName = 'api_externa';
                }
                $pending = [
                    'command' => 'ImportIntegrationOpenApi',
                    'api_name' => $this->normalizeEntityForSchema($apiName),
                    'doc_url' => $docUrl,
                    'provider' => ucfirst(str_replace('_', ' ', $this->normalizeEntityForSchema($apiName))),
                    'country' => (string) ($profile['country'] ?? 'CO'),
                    'environment' => 'sandbox',
                    'type' => 'custom',
                    'dry_run' => false,
                ];
                return [
                    'action' => 'ask_user',
                    'reply' => 'Perfecto. Ya tengo la documentacion: ' . $docUrl . "\n"
                        . 'Puedo importarla y crear el contrato de integracion automaticamente. Â¿La importo?',
                    'intent' => 'INTEGRATION_SETUP',
                    'active_task' => 'integration_setup',
                    'collected' => [
                        'doc_url' => $docUrl,
                        'api_name' => $apiName,
                    ],
                    'pending_command' => $pending,
                ];
            }
            return [
                'action' => 'ask_user',
                'reply' => 'Sigo en configuracion de integracion. Comparte la URL OpenAPI/Swagger (o archivo) y el nombre de la integracion.',
                'intent' => 'INTEGRATION_SETUP',
                'active_task' => 'integration_setup',
            ];
        }

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
        $normalizedRouteText = $this->normalize($text);
        $builderBusinessHint = '';
        if ($mode === 'builder') {
            $builderBusinessHint = $this->normalizeBusinessType($this->detectBusinessType($normalizedRouteText));
            if ($builderBusinessHint === '') {
                $builderBusinessHint = $this->normalizeBusinessType($normalizedRouteText);
            }
        }
        $action = (string) ($route['action'] ?? '');
        $isPlaybookAction = str_starts_with($action, 'APPLY_PLAYBOOK_');
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
            'USER_CORRECTION',
            'INTEGRATION_SETUP',
            'AUTH_LOGIN',
            'USER_CREATE',
            'PROJECT_SWITCH',
        ];
        if ($this->hasCrudSignals($text) && !$isPlaybookAction && !in_array($action, $allowedTrainingActions, true)) {
            
        

        return [];
        }

        if ($mode === 'builder' && $intentName === 'APP_CREATE' && $builderBusinessHint !== '') {
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

        if ($isPlaybookAction) {
            return $this->routePlaybookAction($action, $intentName, $text, $profile, $tenantId, $userId, $mode, $state);
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
            case 'USER_CORRECTION':
                return [
                    'action' => 'ask_user',
                    'reply' => 'Entendido, corrijamos el tipo de negocio. Dime en una frase que vendes o fabricas.',
                    'intent' => $intentName,
                    'active_task' => 'builder_onboarding',
                    'state_patch' => [
                        'active_task' => 'builder_onboarding',
                        'onboarding_step' => 'business_type',
                        'analysis_approved' => null,
                        'proposed_profile' => null,
                        'dynamic_playbook_proposal' => null,
                        'unknown_business_discovery' => null,
                        'unknown_business_force_research' => false,
                        'resolution_attempts' => (int) (($state['resolution_attempts'] ?? 0) + 1),
                    ],
                ];
            case 'INTEGRATION_SETUP':
                if ($mode !== 'builder') {
                    return [
                        'action' => 'respond_local',
                        'reply' => 'La configuracion de integraciones se hace en el Creador de apps.',
                        'intent' => $intentName,
                    ];
                }
                if (preg_match('/https?:\/\/[a-z0-9\.\-\/_\?&=%#]+/i', $text, $m) === 1) {
                    $docUrl = trim((string) ($m[0] ?? ''));
                    $pairs = $this->parseKeyValues($text);
                    $apiName = trim((string) ($pairs['api'] ?? $pairs['nombre'] ?? $pairs['integracion'] ?? 'api_externa'));
                    if ($apiName === '') {
                        $apiName = 'api_externa';
                    }
                    $pending = [
                        'command' => 'ImportIntegrationOpenApi',
                        'api_name' => $this->normalizeEntityForSchema($apiName),
                        'doc_url' => $docUrl,
                        'provider' => ucfirst(str_replace('_', ' ', $this->normalizeEntityForSchema($apiName))),
                        'country' => (string) ($profile['country'] ?? 'CO'),
                        'environment' => 'sandbox',
                        'type' => 'custom',
                        'dry_run' => false,
                    ];
                    $reply = 'Perfecto. Ya tengo la documentacion: ' . $docUrl . "\n"
                        . 'Puedo importarla y crear el contrato de integracion automaticamente. Â¿La importo?';
                    return [
                        'action' => 'ask_user',
                        'reply' => $reply,
                        'intent' => $intentName,
                        'active_task' => 'integration_setup',
                        'collected' => [
                            'doc_url' => $docUrl,
                            'api_name' => $apiName,
                        ],
                        'pending_command' => $pending,
                    ];
                }
                return [
                    'action' => 'ask_user',
                    'reply' => 'Para conectar una API, comparte la URL de la documentacion OpenAPI/Swagger (o archivo) y el nombre de la integracion.',
                    'intent' => $intentName,
                    'active_task' => 'integration_setup',
                ];
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
            case 'FLOW_CANCEL':
            case 'FLOW_RESTART':
            case 'FLOW_BACK':
            case 'FLOW_RESUME':
                $flowText = match ($action) {
                    'FLOW_CANCEL' => 'cancelar',
                    'FLOW_RESTART' => 'reiniciar',
                    'FLOW_BACK' => 'atras',
                    default => 'retomar',
                };
                $flowState = $state;
                $flowRoute = $this->routeFlowControl($flowText, $flowState, $profile, $mode, $tenantId, $userId);
                if (empty($flowRoute)) {
                    
        

        return [];
                }
                $flowRoute['intent'] = $intentName;
                $flowRoute['state_patch'] = $flowState;
                return $flowRoute;
            default:
                
        

        return [];
        }
    }

    private function routeWorkflowBuilder(string $text, string $mode, array $state): array
    {
        if ($mode !== 'builder') {
            return [];
        }
        if (!empty($state['builder_pending_command']) && is_array($state['builder_pending_command'])) {
            return [];
        }

        $hasWorkflowKeyword = preg_match('/\b(workflow|flujo|dag|nodos)\b/u', $text) === 1;
        $hasBuildVerb = preg_match('/\b(crear|compilar|armar|disenar|diseÃ±ar|generar)\b/u', $text) === 1;
        if (!$hasWorkflowKeyword || !$hasBuildVerb) {
            return [];
        }

        $workflowId = $this->deriveWorkflowId($text);
        $pendingCommand = [
            'command' => 'CompileWorkflow',
            'workflow_id' => $workflowId,
            'text' => $text,
            'apply' => true,
        ];

        return [
            'action' => 'ask_user',
            'intent' => 'WORKFLOW_COMPILE',
            'active_task' => 'workflow_builder',
            'reply' => 'Puedo compilar este flujo en un contrato workflow y guardarlo como borrador. Â¿Lo compilo ahora?',
            'pending_command' => $pendingCommand,
            'collected' => [
                'workflow_id' => $workflowId,
            ],
        ];
    }

}
