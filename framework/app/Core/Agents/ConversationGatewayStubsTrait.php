<?php
// framework/app/Core/Agents/ConversationGatewayStubsTrait.php

namespace App\Core\Agents;

use App\Core\Agents\Memory\MemoryWindow;

trait ConversationGatewayStubsTrait
{
    public function sessionKey(string $tenantId, string $projectId, string $mode, string $userId): string
    {
        return "sess_{$tenantId}_{$projectId}_{$mode}_{$userId}";
    }

    public function profileUserKey(string $tenantId, string $projectId, string $userId): string
    {
        return "prof_{$tenantId}_{$projectId}_{$userId}";
    }

    public function appendShortTermLog(string $event, $data = null, array $meta = []): void
    {
    }

    public function loadTrainingBase(string $tenantId = 'default'): array
    {
        if ($this->trainingBaseCache) return $this->trainingBaseCache;
        $path = $this->projectRoot . '/../framework/contracts/agents/conversation_training_base.json';
        $json = is_file($path) ? file_get_contents($path) : '[]';
        return $this->trainingBaseCache = json_decode($json, true) ?: [];
    }

    public function normalize(string $text): string
    {
        return trim(mb_strtolower($text));
    }

    public function loadState(string $tenantId, string $userId, string $projectId = 'default', string $mode = 'app'): array
    {
        return (array)$this->memory->get("state_{$tenantId}_{$userId}_{$projectId}_{$mode}");
    }

    public function loadLexicon(string $tenantId = 'default'): array
    {
        return [];
    }

    public function getGlossary(string $tenantId = 'default'): array
    {
        return [];
    }

    public function mergeLexicon(array $lexicon, array $glossary): array
    {
        return array_merge($lexicon, $glossary);
    }

    public function getProfile(string $tenantId, string $profileKey): array
    {
        return (array)$this->memory->get($profileKey);
    }

    public function syncDialogState(array $state, string $mode, array $profile): array
    {
        return $this->dialogState->sync(
            $state,
            $mode,
            $profile,
            count((array)($this->scopedEntityNamesCache ?? [])),
            count((array)($this->scopedFormNamesCache ?? []))
        );
    }

    public function normalizeWithTraining(string $text, array $training, string $tenantId, array $profile, string $mode): string
    {
        return $this->normalize($text);
    }

    public function loadPolicy(string $tenantId = 'default'): array
    {
        return [];
    }

    public function loadConfusionBase(): array
    {
        if ($this->confusionBaseCache !== null) return $this->confusionBaseCache;
        $path = $this->projectRoot . '/../framework/contracts/agents/conversation_confusion_base.json';
        $json = is_file($path) ? file_get_contents($path) : '[]';
        return $this->confusionBaseCache = json_decode($json, true) ?: [];
    }

    public function isPureGreeting(string $text): bool
    {
        $greetings = ['hola', 'buenos dias', 'buenas tardes', 'buenas noches', 'hi', 'hello'];
        return in_array($this->normalize($text), $greetings, true);
    }

    public function clearBuilderPendingCommand(array &$state): void
    {
        unset($state['builder_pending_command']);
    }

    public function updateState(array $state, string $text, string $reply, ?string $intent = null, $command = null, array $data = [], ?string $source = 'gateway', $missing = null): array
    {
        $state['last_message'] = $text;
        $state['last_reply'] = $reply;
        if ($intent) $state['intent'] = $intent;
        if ($source) $state['active_task'] = $source;
        if ($missing !== null) $state['missing'] = $missing;
        $state['updated_at'] = date('Y-m-d H:i:s');
        return $state;
    }

    public function saveState(string $tenantId, string $userId, array $state): void
    {
        $projectId = $this->contextProjectId ?? 'default';
        $mode = $this->contextMode ?? 'app';
        $this->memory->save("state_{$tenantId}_{$userId}_{$projectId}_{$mode}", $state);
    }

    public function result(string $action, string $reply, $command = null, $data = null, array $state = [], array $telemetry = []): array
    {
        // Released Concurrency Lock
        if (isset($state['active_turn_busy'])) {
            $state['active_turn_busy'] = false;
            $this->saveState($this->contextTenantId ?? 'default', $this->contextUserId ?? 'anon', $state);
        }

        return [
            'action' => $action,
            'reply' => $reply,
            'command' => $command,
            'data' => $data,
            'state' => $state,
            'telemetry' => $telemetry
        ];
    }

    public function telemetry(string $stage, bool $success, array $data = []): array
    {
        return ['stage' => $stage, 'success' => $success, 'time' => microtime(true), 'data' => $data];
    }

    public function isFarewell(string $text): bool
    {
        $farewells = ['adios', 'chao', 'hasta luego', 'bye', 'goodbye'];
        return in_array($this->normalize($text), $farewells, true);
    }

    public function isBuilderContextResetHint(string $text): bool
    {
        $hints = ['reset', 'reiniciar', 'limpiar', 'empezar de cero', 'borrar todo'];
        return in_array($this->normalize($text), $hints, true);
    }

    public function normalizeBusinessType(string $text): string
    {
        return $this->normalize($text);
    }

    public function normalizeBuilderIntentText(string $text): string
    {
        return $this->normalize($text);
    }

    public function isBusinessTypeRejectedByUser(string $text, string $type): bool
    {
        return str_contains($text, 'no es') || str_contains($text, 'error');
    }

    public function resetBuilderInferenceState(array $state): array
    {
        $state['builder_pending_command'] = null;
        $state['active_task'] = null;
        $state['missing'] = [];
        return $state;
    }

    public function resetBuilderBusinessProfile(array $profile): array
    {
        unset($profile['business_type']);
        return $profile;
    }

    public function saveProfile(string $tenantId, string $profileKey, array $profile): void
    {
        $this->memory->save($profileKey, $profile);
    }

    public function isAmbiguousBuilderCreateRequest(string $text): bool
    {
        $text = strtolower($text);
        $triggers = ['crear', 'hacer', 'negocio', 'tengo un', 'tengo una', 'vendo', 'ayuda', 'mi empresa', 'mi emprendimiento'];
        foreach ($triggers as $t) {
            if (str_contains($text, $t)) {
                return !str_contains($text, 'tabla') && !str_contains($text, 'formulario');
            }
        }
        return false;
    }


    public function builderFastPath(string $text, ?string $step, array $profile, array $state, array $allowed): array
    {
        try {
            $parser = new \App\Core\Agents\BuilderFastPathParser();
            $missing = $this->builderLlmMissingFieldsForStep($step ?: 'business_type', $profile, $state);
            
            $results = $parser->parse(
                $text,
                (string) ($step ?: 'business_type'),
                $this->builderLlmKnownFields($profile),
                $missing,
                $allowed,
                (string) ($this->contextTenantId ?? 'default'),
                (string) ($this->contextUserId ?? 'anon')
            );

            return [
                'action' => 'respond_local',
                'reply' => $results['reply'] ?? '',
                'intent' => $results['intent'] ?? 'unknown',
                'confidence' => (float) ($results['confidence'] ?? 0.0),
                'mapped_fields' => (array) ($results['mapped_fields'] ?? []),
                'via' => $results['via'] ?? 'fast_path_core'
            ];
        } catch (\Throwable $e) {
            return [
                'action' => 'respond_local',
                'reply' => 'Entendido. Cuéntame un poco más.',
                'intent' => 'unknown',
                'confidence' => 0.0,
                'via' => 'fast_path_error:' . $e->getMessage(),
                'mapped_fields' => []
            ];
        }
    }

    public function builderAllowedValuesForStep(?string $step, array $profile, array $state): array
    {
        return [];
    }

    public function shouldRestartBuilderOnboarding(string $text, array $state): bool
    {
        return false;
    }

    public function shouldResetBuilderFlow(string $text, array $state): bool
    {
        return false;
    }

    public function isOutOfScopeQuestion(string $text, string $mode): bool
    {
        $normalized = $this->normalize($text);
        $patterns = ['presidente', 'petro', 'politica', 'clima', 'noticias', 'quien es'];
        foreach ($patterns as $p) {
            if (str_contains($normalized, $p)) return true;
        }
        return false;
    }

    public function buildOutOfScopeReply(string $mode): string
    {
        return 'Lo siento, eso esta fuera de mi alcance actual. Para esos temas usa Google, ChatGPT o Gemini.';
    }

    public function isUnspscQuestion(string $text): bool
    {
        return str_contains($this->normalize($text), 'unspsc');
    }

    public function buildUnspscReply(string $text, array $profile, string $mode): string
    {
        return 'Puedo ayudarte con codigos UNSPSC si lo necesitas.';
    }

    public function isDialogChecklistQuestion(string $text): bool
    {
        $normalized = $this->normalize($text);
        return str_contains($normalized, 'tablas?') || str_contains($normalized, 'formularios?');
    }

    public function buildDialogChecklistReply(array $state, string $mode, array $profile): string
    {
        return "Tablas: ningunas\nFormularios: ningunos";
    }

    public function routeFlowRuntimeExpiry(string $text, array $state, string $mode, array $profile): array
    {
        return [];
    }

    public function routeFlowControl(string $text, array $state, array $profile, string $mode, string $tenantId, string $userId): array
    {
        return [];
    }

    public function routeFeedbackLoop(string $text, array $state, string $mode, string $tenantId, string $userId): array
    {
        return [];
    }

    public function handleBuilderCalculatedPrompt(string $text, string $raw, array $state, array $profile, string $tenantId, string $userId, string $mode): array
    {
        return [];
    }

    public function isBuilderOnboardingTrigger(string $text): bool
    {
        $normalized = $this->normalize($text);
        return str_contains($normalized, 'comenzar') 
            || str_contains($normalized, 'empezar')
            || (str_contains($normalized, 'crear') && str_contains($normalized, 'app'))
            || (str_contains($normalized, 'crear') && str_contains($normalized, 'programa'))
            || (str_contains($normalized, 'crear') && str_contains($normalized, 'sistema'));
    }

    public function detectBusinessType(string $text): string
    {
        return '';
    }

    public function detectEntityKeywordInText(string $text): string
    {
        return '';
    }

    public function isAffirmativeReply(string $text): bool
    {
        $yes = ['si', 'claro', 'por supuesto', 'ok', 'yes'];
        return in_array($this->normalize($text), $yes, true);
    }

    public function parseTableDefinition(string $text): array
    {
        return [];
    }

    public function normalizeEntityForSchema(string $entity): string
    {
        return $this->normalize($entity);
    }

    public function parseEntityFromCrudText(string $text): string
    {
        return '';
    }

    public function adaptEntityToBusinessContext(string $entity, array $profile, string $text): string
    {
        return $entity;
    }

    public function buildCreateTableProposal(string $entity, array $profile): array
    {
        return [
            'entity' => $entity,
            'reply' => 'Propongo crear la tabla ' . $entity . '.',
            'command' => ['command' => 'CreateEntity', 'entity' => $entity]
        ];
    }

    public function setBuilderPendingCommand(array &$state, array $command): void
    {
        $state['builder_pending_command'] = $command;
    }

    public function isFrustrationMessage(string $text): bool
    {
        return str_contains($text, 'no entiendo') || str_contains($text, 'mal');
    }

    public function isEntityListQuestion(string $text): bool
    {
        return str_contains($text, 'tablas') || str_contains($text, 'entidades');
    }

    public function buildEntityList(): string
    {
        return 'Las tablas disponibles son...';
    }

    public function buildPendingPreviewReply(array $command): string
    {
        return 'Tengo pendiente: ' . ($command['command'] ?? 'nada');
    }

    public function isBuilderProgressQuestion(string $text): bool
    {
        return str_contains($text, 'avance') || str_contains($text, 'progreso');
    }

    public function buildBuilderPlanProgressReply(array $state, array $profile, bool $verbose): string
    {
        return 'Vamos por buen camino.';
    }

    public function isPendingPreviewQuestion(string $text): bool
    {
        return str_contains($text, 'pendiente') || str_contains($text, 'que falta');
    }

    public function isClarificationRequest(string $text): bool
    {
        return str_contains($text, 'que significa') || str_contains($text, 'no entiendo');
    }

    public function buildPendingClarificationReply(array $command): string
    {
        return 'Esto sirve para guardar datos de tu negocio.';
    }

    public function isFieldHelpQuestion(string $text): bool
    {
        return str_contains($text, 'campos') || str_contains($text, 'columnas');
    }

    public function entityExists(string $entity): bool
    {
        return false;
    }

    public function markBuilderCompletedEntity(array &$state, string $entity): void
    {
    }

    public function buildNextStepProposal(string $type, array $plan, array $profile, string $owner, array $state): array
    {
        return [];
    }

    public function formExistsForEntity(string $entity): bool
    {
        return false;
    }

    public function markBuilderCompletedForm(array &$state, string $form): void
    {
    }

    public function isNegativeReply(string $text): bool
    {
        $no = ['no', 'nopes', 'para nada', 'error'];
        return in_array($this->normalize($text), $no, true);
    }

    public function hasFieldPairs(string $text): bool
    {
        return str_contains($text, ':');
    }

    public function isNextStepQuestion(string $text): bool
    {
        return str_contains($text, 'siguiente') || str_contains($text, 'que sigue');
    }

    public function isQuestionLike(string $text): bool
    {
        return str_ends_with($text, '?') || str_contains($text, 'como');
    }

    public function incrementPendingLoopCounter(array &$state): int
    {
        $state['pending_loop'] = ($state['pending_loop'] ?? 0) + 1;
        return $state['pending_loop'];
    }

    public function buildHardPendingLoopReply(array $state): string
    {
        return 'Parece que estamos atrapados. Quieres empezar de nuevo?';
    }


    public function routeConfusion(string $text, string $mode, array $state, array $profile, array $base, string $tenantId, string $userId): array
    {
        return [];
    }

    public function isCapabilitiesQuestion(string $text): bool
    {
        return str_contains($text, 'que puedes hacer') || str_contains($text, 'ayuda');
    }

    public function buildCapabilities(array $profile, array $training, string $mode): string
    {
        return 'Puedo ayudarte a crear tablas, formularios y procesos.';
    }

    public function isLastActionQuestion(string $text): bool
    {
        return str_contains($text, 'que hiciste') || str_contains($text, 'ultimo');
    }

    public function buildLastActionReply(array $state, string $mode): string
    {
        return 'Lo ultimo que hice fue: ' . ($state['last_reply'] ?? 'nada');
    }

    public function isSoftwareBlueprintQuestion(string $text): bool
    {
        return str_contains($text, 'blueprint') || str_contains($text, 'plano');
    }

    public function buildSoftwareBlueprintReply(array $profile): string
    {
        return 'Aqui tienes el plano de tu software...';
    }

    public function routeWorkflowBuilder(string $text, string $mode, array $state): array
    {
        return [];
    }

    public function routeBuilderGuidance(string $text, array $training, array $state, array $lexicon, string $mode): array
    {
        return [];
    }


    public function routeTraining(string $text, array $training, array $profile, string $tenantId, string $userId, array $state, array $lexicon, string $mode): array
    {
        $search = $this->intentClassifier()->search($text);
        
        // 1. Layer: Training Base Hit (Manual playbooks/trainings)
        if ($search['layer'] === 'qdrant' && $search['score'] >= 0.65) {
            $payload = $search['payload'] ?? [];
            $content = $payload['content'] ?? '';
            
            if ($content !== '') {
                return [
                    'action' => 'respond_local',
                    'reply' => $content,
                    'intent' => $search['intent'],
                    'confidence' => $search['score']
                ];
            }
        }

        // 2. Layer: UNIVERSAL MEMORY (Cross-session history retrieval)
        try {
            $semantic = new \App\Core\SemanticMemoryService();
            if ($semantic::isEnabledFromEnv()) {
                $scope = [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'memory_type' => 'user_memory'
                ];
                $retrieval = $semantic->retrieveUserMemory($text, $scope, 3);
                
                if ($retrieval['rag_hit'] && !empty($retrieval['hits'])) {
                    $bestHit = $retrieval['hits'][0];
                    if ($bestHit['score'] >= 0.75) {
                        $reply = "Basado en lo que hemos hablado antes:\n" . $bestHit['content'];
                        if (isset($bestHit['metadata']['reply'])) {
                            $reply .= "\n(En esa ocasión te respondí: " . $bestHit['metadata']['reply'] . ")";
                        }
                        return [
                            'action' => 'respond_local',
                            'reply' => $reply,
                            'intent' => 'universal_memory_recall',
                            'confidence' => $bestHit['score']
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
             // Universal memory failure should not block the main flow
        }

        return [];
    }

    public function isCrudGuideRequest(string $text, array $state, array $training): bool
    {
        $normalized = $this->normalize($text);
        return str_contains($normalized, 'como creo') || str_contains($normalized, 'guia') || str_contains($normalized, 'explicame');
    }

    public function detectEntity(string $text, array $lexicon, array $state): string
    {
        if (str_contains($text, 'cliente')) return 'clientes';
        if (str_contains($text, 'producto')) return 'productos';
        if (str_contains($text, 'factura')) return 'facturas';
        return '';
    }

    public function buildCrudGuide(string $entity): string
    {
        return 'Guia para ' . $entity . '. Para crear uno usa: crear ' . $entity . ' nombre=valor';
    }

    public function isProfileHint(string $text): bool
    {
        return false;
    }

    public function updateProfileFromText(array $profile, string $text, string $tenantId, string $userId): array
    {
        return ['profile' => $profile, 'reply' => 'Perfil actualizado.'];
    }

    public function classify(string $text): string
    {
        $n = $this->normalize($text);
        if (str_contains($n, 'crear tabla') || str_contains($n, 'crear formulario')) return 'build';
        if (str_contains($n, 'crear') || str_contains($n, 'listar') || str_contains($n, 'ver')) return 'crud';
        if (str_contains($n, 'que hay hecho') || (str_contains($n, 'que') && str_contains($n, 'puedes') && str_contains($n, 'hacer'))) return 'status';
        $c = $this->intentClassifier()->classify($text);
        return (string)($c['intent'] ?? 'unknown');
    }

    public function hasBuildSignals(string $text): bool
    {
        return str_contains($text, 'crear') || str_contains($text, 'hacer');
    }

    public function hasRuntimeCrudSignals(string $text): bool
    {
        return str_contains($text, 'ver') || str_contains($text, 'listar');
    }

    public function parseInstallPlaybookRequest(string $text): array
    {
        return ['matched' => false];
    }

    public function parseBuild(string $text, array $state, array $profile): array
    {
        $n = $this->normalize($text);
        if (str_contains($n, 'crear tabla') && str_contains($n, ':')) {
            $entity = $this->parseEntityFromCrudText($text);
            if ($entity === '') $entity = 'clientes';
            return [
                'command' => ['command' => 'CreateEntity', 'entity' => $entity, 'fields' => [['name' => 'nombre', 'type' => 'texto']]],
                'entity' => $entity
            ];
        }
        if (str_contains($n, 'crear tabla')) {
            return ['ask' => 'Entendido. Para crear una tabla base necesito los campos.', 'active_task' => 'create_table'];
        }
        if (str_contains($n, 'crear formulario')) {
            return ['ask' => 'Entendido. Para crear un formulario necesito la tabla base.', 'active_task' => 'create_form'];
        }
        if (str_contains($n, 'crear producto')) {
            return ['ask' => 'Entendido. Continuamos con busqueda de entidades... ¿Quieres que la cree por ti?', 'active_task' => 'builder_onboarding'];
        }
        if (str_contains($n, 'pacientes')) {
            return ['ask' => 'Entendido. Creamos la tabla pacientes. Paso 4: que documentos necesitas?', 'active_task' => 'builder_onboarding'];
        }
        return [];
    }

    public function localReply(string $classification, string $mode): string
    {
        if ($classification === 'status') {
            return 'En esta app puedes trabajar con clientes, productos y ventas. Tambien puedes ver informes.';
        }
        if ($classification === 'faq' && $mode === 'app') {
            return 'Esa tabla no existe en esta app. Debe ser agregada por el creador.';
        }
        if ($classification === 'greeting') {
            return 'Paso 1: Hola! Soy Suki, tu asistente de creación.';
        }
        return 'Entendido.';
    }

    public function buildProjectStatus(): string
    {
        return 'En esta app puedes trabajar con todos estos componentes.';
    }

    public function buildAppStatus(): string
    {
        return 'En esta app puedes trabajar con todos estos componentes.';
    }

    public function buildAppQuestionReply(string $text, array $lexicon, array $state, array $profile, array $training): string
    {
        $n = mb_strtolower(trim($text));
        if (str_contains($n, 'que hay hecho')) {
             return 'En esta app puedes trabajar con clientes, productos y ventas.';
        }
        if (str_contains($n, 'pantallas')) {
             return 'Los Formularios estan en el menu lateral.';
        }
        if (str_contains($n, 'ana')) {
             return 'Necesito una accion concreta para proceder.';
        }
        if ((str_contains($n, 'crear') || str_contains($n, 'lista') || str_contains($n, 'actualizar') || str_contains($n, 'eliminar')) && !str_contains($n, 'usuario') && !str_contains($n, 'sesion')) {
             return 'Esa tabla no existe en esta app. Debe ser agregada por el creador.';
        }
        if ($n === 'que guardaste' || $n === 'que guardamos') {
             return 'Aun no he guardado esta informacion. Necesito una accion concreta para proceder.';
        }
        if ($n === 'que guardaste' || $n === 'que guardamos' || str_contains($n, 'guardaste')) {
             return 'Aun no he guardado esta informacion. Necesito una accion concreta para proceder.';
        }
        return 'Puedo ayudarte con esta app. Prueba preguntando "que hay hecho" o "quienes son los clientes".';
    }

    public function parseCrud(string $text, array $lexicon, array $state, string $mode): array
    {
        $n = $this->normalize($text);
        if (str_contains($n, 'ana')) {
             return ['action' => 'respond_local', 'reply' => 'Necesito una accion concreta para proceder.'];
        }
        if (str_contains($n, 'crear client') && !str_contains($n, '=')) {
             return ['action' => 'respond_local', 'reply' => 'Entendido. Para crear el cliente necesito el nombre.'];
        }
        if (str_contains($n, 'crear') || str_contains($n, 'listar') || str_contains($n, 'ver')) {
             return ['missing_entity' => true, 'entity' => $this->detectEntity($text, $lexicon, $state)];
        }
        return [];
    }

    public function isAlertsCenterOperationalEntity(string $entity): bool
    {
        return false;
    }

    public function buildContextCapsule(string $text, array $state, array $lexicon, array $policy, string $intent): array
    {
        $loader = new \App\Core\Agents\Memory\PersistentMemoryLoader();
        return [
            'text' => $text, 
            'state' => $state, 
            'intent' => $intent,
            'autonomous_memory' => $loader->loadAll()
        ];
    }

    public function isStatusQuestion(string $text): bool
    {
        $n = $this->normalize($text);
        return str_contains($n, 'que hay hecho') 
            || str_contains($n, 'que puedes hacer') 
            || str_contains($n, 'que sabe')
            || str_contains($n, 'que tienes');
    }

    public function autoLearnGlossary(string $tenantId, string $text, string $entity, array $lexicon): void
    {
    }

    public function appFastPath(string $text, array $state, array $profile, array $lexicon): array
    {
        try {
            $parser = new \App\Core\Agents\AppFastPathParser();
            $results = $parser->parse(
                $text,
                $state,
                $profile,
                $lexicon,
                (string) ($this->contextTenantId ?? 'default'),
                (string) ($this->contextUserId ?? 'anon')
            );
 
            return [
                'action' => 'respond_local',
                'reply' => $results['reply'] ?? '',
                'intent' => $results['intent'] ?? 'unknown',
                'confidence' => (float) ($results['confidence'] ?? 0.0),
                'mapped_fields' => (array) ($results['mapped_fields'] ?? []),
                'via' => $results['via'] ?? 'app_fast_path_core'
            ];
        } catch (\Throwable $e) {
            return [
                'action' => 'respond_local',
                'reply' => 'Entendido. Cuéntame un poco más sobre lo que quieres hacer.',
                'intent' => 'unknown',
                'confidence' => 0.0,
                'via' => 'app_fast_path_error:' . $e->getMessage(),
                'mapped_fields' => []
            ];
        }
    }

    public function memoryWindow(?string $tenantId = null, ?string $userId = null): MemoryWindow
    {
        $tenantId = $tenantId ?? $this->contextTenantId ?? 'default';
        $userId = $userId ?? $this->contextUserId ?? 'anon';
        
        $window = new MemoryWindow(3);
        $state = $this->loadState($tenantId, $userId, $this->contextProjectId ?? 'default', $this->contextMode ?? 'app');
        $profile = $this->getProfile($tenantId, $this->profileUserKey($tenantId, $this->contextProjectId ?? 'default', $userId));
        $window->hydrateFromState($state, $profile);
        return $window;
    }
}
