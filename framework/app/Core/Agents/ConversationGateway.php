<?php
// app/Core/Agents/ConversationGateway.php

namespace App\Core\Agents;

use App\Core\ContractsCatalog;
use App\Core\ChatMemoryStore;
use App\Core\EntityRegistry;
use RuntimeException;

final class ConversationGateway
{
    private string $projectRoot;
    private EntityRegistry $entities;
    private ContractsCatalog $catalog;
    private ChatMemoryStore $memory;
    private array $trainingBaseCache = [];
    private ?array $domainPlaybookCache = null;
    private ?array $accountingKnowledgeCache = null;
    private ?array $unspscCommonCache = null;
    private string $contextProjectId = 'default';
    private string $contextMode = 'app';

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
        $this->entities = new EntityRegistry();
        $this->catalog = new ContractsCatalog($this->projectRoot);
        $this->memory = new ChatMemoryStore($this->projectRoot);
    }

    public function handle(string $tenantId, string $userId, string $message, string $mode = 'app', string $projectId = 'default'): array
    {
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $userId = $userId !== '' ? $userId : 'anon';
        $mode = strtolower(trim($mode)) === 'builder' ? 'builder' : 'app';
        $this->contextProjectId = $projectId !== '' ? $projectId : 'default';
        $this->contextMode = $mode;

        $raw = trim($message);
        $training = $this->loadTrainingBase($tenantId);
        $normalizedBase = $this->normalize($raw);
        $normalized = $this->normalizeWithTraining($raw, $training);

        $state = $this->loadState($tenantId, $userId, $this->contextProjectId, $mode);
        $lexicon = $this->loadLexicon($tenantId);
        $glossary = $this->memory->getGlossary($tenantId);
        if (!empty($glossary)) {
            $lexicon = $this->mergeLexicon($lexicon, $glossary);
        }
        $profile = $this->memory->getProfile($tenantId, $userId);
        $policy = $this->loadPolicy($tenantId);

        if ($this->isPureGreeting($normalizedBase)) {
            $reply = 'Hola, soy Cami. Dime que necesitas crear o consultar.';
            $state = $this->updateState($state, $raw, $reply, null, null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('greeting', true));
        }

        if ($mode === 'builder' && $this->shouldResetBuilderFlow($normalizedBase, $state)) {
            unset($state['builder_pending_command']);
            $state['active_task'] = 'builder_onboarding';
            $state['entity'] = null;
            $state['collected'] = [];
            $state['missing'] = [];
            $state['requested_slot'] = null;
        }

        if ($this->isOutOfScopeQuestion($normalizedBase, $mode)) {
            if ($mode === 'builder') {
                unset($state['builder_pending_command']);
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

        if ($mode === 'builder' && !empty($state['builder_pending_command']) && is_array($state['builder_pending_command'])) {
            if ($this->isBuilderOnboardingTrigger($normalizedBase)) {
                unset($state['builder_pending_command']);
                $state['active_task'] = 'builder_onboarding';
                $state['missing'] = [];
                $state['requested_slot'] = null;
                $state['collected'] = [];
                $state['entity'] = null;
            } else {
                if ((str_contains($normalizedBase, 'crear') || str_contains($normalizedBase, 'hacer')) && (str_contains($normalizedBase, 'tabla') || str_contains($normalizedBase, 'entidad'))) {
                    $parsedOverride = $this->parseTableDefinition($normalizedBase);
                    $newEntity = $this->normalizeEntityForSchema((string) ($parsedOverride['entity'] ?? ''));
                    $currentEntity = $this->normalizeEntityForSchema((string) ($state['builder_pending_command']['entity'] ?? ''));
                    if ($newEntity !== '' && $newEntity !== $currentEntity) {
                        $proposal = $this->buildCreateTableProposal($newEntity, $profile);
                        $state['builder_pending_command'] = $proposal['command'];
                        $state['entity'] = $newEntity;
                        $reply = 'Listo, cambio la tabla propuesta a ' . $newEntity . '.' . "\n" . $proposal['reply'];
                        $state = $this->updateState($state, $raw, $reply, 'create', $newEntity, [], 'create_table');
                        $this->saveState($tenantId, $userId, $state);
                        return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                    }
                }
                if ($this->isEntityListQuestion($normalizedBase)) {
                    $reply = $this->buildEntityList() . "\n" . $this->buildPendingPreviewReply($state['builder_pending_command']);
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isPendingPreviewQuestion($normalizedBase)) {
                    $reply = $this->buildPendingPreviewReply($state['builder_pending_command']);
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isFrustrationMessage($normalizedBase)) {
                    unset($state['builder_pending_command']);
                    $reply = 'Te entiendo. Vamos a ordenar esto.' . "\n"
                        . 'Dime en una frase que programa quieres crear para tu negocio.';
                    $state = $this->updateState($state, $raw, $reply, 'builder_onboarding', null, [], 'builder_onboarding');
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
                    $reply = $this->buildPendingPreviewReply($state['builder_pending_command']);
                    $state = $this->updateState($state, $raw, $reply, 'create', (string) ($state['entity'] ?? 'clientes'), [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isAffirmativeReply($normalizedBase)) {
                    $command = $state['builder_pending_command'];
                    unset($state['builder_pending_command']);
                    $state = $this->updateState($state, $raw, 'OK', 'create', (string) ($command['entity'] ?? ($state['entity'] ?? null)), [], '', []);
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('execute_command', '', $command, null, $state, $this->telemetry('builder_confirm', true));
                }
                if ($this->isNegativeReply($normalizedBase)) {
                    $entity = (string) ($state['entity'] ?? 'clientes');
                    $entityFromText = $this->detectEntityKeywordInText($normalizedBase);
                    if ($entityFromText === '' && preg_match('/\b(tabla|entidad)\b/u', $normalizedBase) === 1) {
                        $entityFromText = $this->parseEntityFromCrudText($normalizedBase);
                    }
                    if ($entityFromText !== '') {
                        $entity = $this->normalizeEntityForSchema($entityFromText);
                    }
                    $entity = $this->adaptEntityToBusinessContext($entity, $profile, $normalizedBase);
                    unset($state['builder_pending_command']);
                    $reply = 'Perfecto. Cambiamos la tabla a ' . $entity . '.'
                        . "\n"
                        . 'Ahora dime que datos quieres guardar. Ejemplo: nombre:texto documento:texto telefono:texto';
                    $state = $this->updateState($state, $raw, $reply, 'create', $entity, [], 'create_table');
                    $this->saveState($tenantId, $userId, $state);
                    return $this->result('ask_user', $reply, null, null, $state, $this->telemetry('builder_confirm', true));
                }
                if (!$this->hasFieldPairs($normalizedBase) && !$this->isFieldHelpQuestion($normalizedBase) && !str_contains($normalizedBase, 'tabla')) {
                    $reply = 'Para continuar, responde "si" para crearla, "no" para cambiar nombre/campos, o "que vas a crear?" para ver el detalle.';
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
                    $state['builder_pending_command'] = $build['pending_command'];
                }
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $build['ask'], null, null, $state, $this->telemetry('build', true, $build));
            }
            if (!empty($build['command'])) {
                unset($state['builder_pending_command']);
                $state = $this->updateState($state, $raw, 'OK', null, $build['entity'] ?? null, $build['collected'] ?? [], '');
                $this->saveState($tenantId, $userId, $state);
                return $this->result('execute_command', '', $build['command'], null, $state, $this->telemetry('build', true, $build));
            }
        }

        if (in_array($classification, ['greeting', 'thanks', 'confirm', 'faq'], true)) {
            $reply = $this->localReply($classification);
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

        $capsule = $this->buildContextCapsule($normalized, $state, $lexicon, $policy, $classification);
        $state = $this->updateState($state, $raw, '', $capsule['intent'] ?? null, $capsule['entity'] ?? null, $capsule['state']['collected'] ?? [], $state['active_task'] ?? null);
        $this->saveState($tenantId, $userId, $state);

        return $this->result('send_to_llm', '', null, $capsule, $state, $this->telemetry('llm', false));
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
        foreach ($this->catalog->entities() as $entityPath) {
            $name = strtolower((string) basename($entityPath, '.entity.json'));
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
        $patterns = ['que vas a crear', 'que se va a crear', 'que vas a hacer', 'que hara', 'que va a crear', 'cual es esa tabla', 'que tabla es esa'];
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
        $patterns = ['no entiendo', 'explicame', 'explica', 'aclarame', 'aclara', 'no me quedo claro'];
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
                . '- Si quieres cambiar datos responde "no".';
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

    private function normalizeWithTraining(string $text, array $training): string
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
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
        return $text;
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

    private function localReply(string $type): string
    {
        switch ($type) {
            case 'greeting':
                return 'Hola, soy Cami. Dime que necesitas crear o consultar.';
            case 'thanks':
                return 'Con gusto. Estoy atenta.';
            case 'confirm':
                return 'Listo, continuo.';
            case 'status':
                return $this->buildProjectStatus();
            case 'faq':
            default:
                return $this->buildHelp();
        }
    }

    private function buildProjectStatus(): string
    {
        $entities = $this->catalog->entities();
        $forms = $this->catalog->forms();
        $viewsPath = $this->projectRoot . '/views';
        $views = [];
        if (is_dir($viewsPath)) {
            $views = array_values(array_filter(scandir($viewsPath) ?: [], fn($f) => is_string($f) && !in_array($f, ['.', '..'], true)));
        }

        $entityNames = array_map(fn($p) => basename($p, '.entity.json'), $entities);
        $formNames = array_map(fn($p) => basename($p, '.json'), $forms);

        $lines = [];
        $lines[] = 'Estado del proyecto:';
        $lines[] = '- Entidades: ' . count($entityNames);
        $lines[] = '- Formularios: ' . count($formNames);
        $lines[] = '- Vistas: ' . count($views);
        $lines[] = 'Ultimas entidades: ' . (count($entityNames) ? implode(', ', array_slice($entityNames, 0, 3)) : 'sin entidades');
        $lines[] = 'Ultimos formularios: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 3)) : 'sin formularios');
        return implode("\n", $lines);
    }

    private function buildHelp(): string
    {
        $entities = array_map(fn($p) => basename($p, '.entity.json'), $this->catalog->entities());
        $forms = array_map(fn($p) => basename($p, '.json'), $this->catalog->forms());

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
        $entities = array_map(fn($p) => basename($p, '.entity.json'), $this->catalog->entities());
        $forms = array_map(fn($p) => basename($p, '.json'), $this->catalog->forms());
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

    private function parseBuild(string $text, array $state, array $profile = []): array
    {
        $hasCreate = str_contains($text, 'crear');
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
            $entity = $this->parseEntityFromCrudText($text);
            if ($entity !== '') {
                if ($this->entityExists($entity)) {
                    return ['ask' => 'La tabla ' . $entity . ' ya existe. Quieres crear su formulario?', 'active_task' => 'create_form', 'entity' => $entity];
                }
                return ['ask' => 'Perfecto, vamos a crear la tabla ' . $entity . '. Dime los campos asi: nombre:texto precio:decimal', 'active_task' => 'create_table', 'entity' => $entity];
            }
        }

        if (($state['active_task'] ?? '') === 'create_table') {
            $currentEntity = (string) ($state['entity'] ?? '');
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
            $dependencyGuide = $this->buildDependencyGuidanceForBuilder($parsed['entity'], $profile);
            if (!empty($dependencyGuide)) {
                return $dependencyGuide;
            }
            if (empty($parsed['fields'])) {
                return ['ask' => 'Que campos quieres en ' . $parsed['entity'] . '? Ej: nombre:texto nit:texto', 'active_task' => 'create_table', 'entity' => $parsed['entity']];
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
        if ($business !== '') {
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
            $this->memory->saveProfile($tenantId, $userId, $localProfile);
        }

        $owner = (string) ($localProfile['owner_name'] ?? '');
        $businessType = (string) ($localProfile['business_type'] ?? '');
        if (in_array($businessType, ['mixto', 'contado', 'credito'], true)) {
            $businessType = '';
            unset($localProfile['business_type']);
            $this->memory->saveProfile($tenantId, $userId, $localProfile);
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
            $localState['active_task'] = 'builder_onboarding';
            $localState['onboarding_step'] = 'operation_model';
            $reply = 'Perfecto. Paso 2: como manejas pagos? contado, credito o mixto.';
            return ['action' => 'ask_user', 'reply' => $reply, 'state' => $localState];
        }

        $plan = $this->buildBusinessPlan($businessType, $localProfile);
        $localState['builder_plan'] = $plan;
        $localState['active_task'] = 'builder_onboarding';
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
            }
            if ($entityHint === '') {
                $entityHint = (string) ($plan['first_entity'] ?? 'clientes');
            }
            $proposal = $this->buildCreateTableProposal($entityHint, $localProfile);
            $localState['builder_pending_command'] = $proposal['command'];
            $localState['entity'] = $entityHint;
            $localState['active_task'] = 'create_table';
            return ['action' => 'ask_user', 'reply' => $proposal['reply'], 'state' => $localState];
        }

        if ($this->isNextStepQuestion($text) || $trigger || $businessHint || ($isOnboarding && !$this->isBuilderActionMessage($text))) {
            $proposal = $this->buildNextStepProposal($businessType, $plan, $localProfile, $owner);
            $localState['builder_pending_command'] = $proposal['command'];
            $localState['entity'] = $proposal['entity'];
            $localState['active_task'] = 'create_table';
            return ['action' => 'ask_user', 'reply' => $proposal['reply'], 'state' => $localState];
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

    private function isNextStepQuestion(string $text): bool
    {
        $patterns = ['que debo hacer', 'paso sigue', 'siguiente paso', 'que hago ahora', 'como sigo'];
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
            'crear entidad',
            'crear formulario',
            'crear form',
            'guardar tabla',
            'crear ',
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
        $yes = ['si', 'sí', 'ok', 'dale', 'confirmo', 'hagalo', 'hazlo', 'de una', 'claro', 'correcto'];
        $text = trim(preg_replace('/[!?.,;]+/u', '', $text) ?? $text);
        if ($text === '') {
            return false;
        }
        foreach ($yes as $candidate) {
            if ($text === $candidate) {
                return true;
            }
        }
        return false;
    }

    private function isNegativeReply(string $text): bool
    {
        $no = ['no', 'todavia no', 'aun no', 'ahora no', 'mejor no', 'detente', 'cancelar', 'cambiar'];
        $text = trim(preg_replace('/[!?.,;]+/u', '', $text) ?? $text);
        if ($text === '') {
            return false;
        }
        foreach ($no as $candidate) {
            if ($text === $candidate) {
                return true;
            }
        }
        return false;
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
        if (str_contains($text, 'mixto') || str_contains($text, 'misto') || str_contains($text, 'ambos') || str_contains($text, 'contado y credito') || str_contains($text, 'credito y contado')) {
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
        $existingEntities = array_map(fn($p) => basename($p, '.entity.json'), $this->catalog->entities());
        $forms = array_map(fn($p) => basename($p, '.json'), $this->catalog->forms());
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

    private function isFieldHelpQuestion(string $text): bool
    {
        $patterns = ['campo', 'campos', 'que debe tener', 'cual debe tener', 'cuales debe tener', 'que campos', 'debe llevar', 'que debe llevar', 'q debe', 'que lleva', 'ayudame', 'ayuda'];
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
        $preview = implode(', ', array_slice($fieldNames, 0, 6));
        $unspscHint = $this->entityShouldHaveUnspsc($entity)
            ? ' Incluiremos codigo_unspsc para facturacion electronica.'
            : '';

        $reply = 'Vamos paso a paso.' . "\n"
            . 'Paso 1: crearemos la tabla ' . $entity . '.' . "\n"
            . 'Se guardara esta informacion: ' . $preview . '.' . $unspscHint . "\n"
            . 'Quieres que la cree por ti ahora? Responde: si o no.';

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

    private function buildNextStepProposal(string $businessType, array $plan, array $profile = [], string $owner = ''): array
    {
        $businessType = $this->normalizeBusinessType($businessType);
        $domainProfile = $this->findDomainProfile($businessType);
        $businessLabel = (string) ($domainProfile['label'] ?? $businessType);

        $planEntities = is_array($plan['entities'] ?? null) ? $plan['entities'] : [];
        $existingEntities = array_map(fn($p) => basename($p, '.entity.json'), $this->catalog->entities());
        $nextEntity = '';
        foreach ($planEntities as $candidate) {
            $candidate = (string) $candidate;
            if ($candidate !== '' && !in_array($candidate, $existingEntities, true)) {
                $nextEntity = $candidate;
                break;
            }
        }
        if ($nextEntity === '') {
            $nextEntity = (string) ($plan['first_entity'] ?? 'clientes');
        }

        $proposal = $this->buildCreateTableProposal($nextEntity, $profile);
        $tablePreview = implode(', ', array_slice($planEntities, 0, 6));
        $ownerLine = $owner !== '' ? 'Perfecto, ' . $owner . '.' . "\n" : '';
        $proposal['reply'] = $ownerLine
            . 'Ruta para ' . $businessLabel . ':' . "\n"
            . '- Tablas base: ' . ($tablePreview !== '' ? $tablePreview : $nextEntity) . "\n"
            . '- Primer paso: crear tabla ' . $nextEntity . ".\n"
            . $proposal['reply'];

        return $proposal;
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
        ];

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
        $stop = ['de', 'del', 'la', 'el', 'un', 'una', 'para', 'que', 'quiero', 'necesito', 'ayudame', 'campos'];
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
        if (empty($collected) && $requestedSlot !== '' && $this->isLikelyValueReply($text)) {
            $collected[$requestedSlot] = trim($text);
        } elseif (empty($collected) && !empty($state['missing']) && $this->isLikelyValueReply($text)) {
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

        $entityNames = [];
        foreach ($this->catalog->entities() as $path) {
            $name = basename($path, '.entity.json');
            if ($name !== '') {
                $entityNames[] = $name;
            }
        }
        usort($entityNames, static fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
        foreach ($entityNames as $name) {
            $nameLower = mb_strtolower($name, 'UTF-8');
            if ($nameLower !== '' && preg_match('/\b' . preg_quote($nameLower, '/') . '\b/u', $textLower) === 1) {
                return $name;
            }
        }

        $entities = $this->catalog->entities();
        foreach ($entities as $path) {
            $name = (string) basename($path, '.entity.json');
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
        $entities = $this->catalog->entities();
        foreach ($entities as $path) {
            $name = strtolower((string) basename($path, '.entity.json'));
            if (in_array($name, $variants, true)) {
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
        $text = preg_replace('/^(crear|agregar|nuevo|listar|lista|mostrar|muestrame|dame|ver|buscar|actualizar|editar|eliminar|borrar|guardar|registrar|emitir|facturar)\\s+/i', '', $text) ?? $text;
        $text = preg_replace('/^(un|una|el|la|los|las|lista|lista\\s+de|registros|registro|datos)\\s+/i', '', $text) ?? $text;

        $tokens = preg_split('/\\s+/', trim($text)) ?: [];
        if (empty($tokens)) {
            return '';
        }

        $stopwords = [
            'que', 'q', 'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'lista', 'registros', 'registro',
            'datos', 'hay', 'estan', 'esta', 'guardados', 'guardado', 'actuales', 'mas', 'con', 'para', 'dame', 'muestrame', 'mostrar',
            'no', 'te', 'pedi', 'eso', 'bueno', 'quiero', 'puedo', 'necesito', 'ver', 'listar', 'crear', 'actualizar', 'eliminar',
            'en', 'mi', 'mis', 'app', 'aplicacion', 'sistema', 'ya', 'ayudame', 'campos', 'debe', 'tener', 'que', 'a',
            'tabla', 'tablas', 'entidad', 'entidades', 'sabes', 'sobre'
        ];

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

    private function buildEntityList(): string
    {
        $entities = array_map(fn($p) => basename($p, '.entity.json'), $this->catalog->entities());
        if (empty($entities)) {
            return 'Aun no hay tablas creadas. Quieres crear una?';
        }
        $list = implode(', ', array_slice($entities, 0, 6));
        return 'Tablas creadas: ' . $list . '. Quieres ver los campos de alguna?';
    }

    private function buildFormList(): string
    {
        $forms = array_map(fn($p) => basename($p, '.json'), $this->catalog->forms());
        if (empty($forms)) {
            return 'Aun no hay formularios. Quieres crear uno?';
        }
        $list = implode(', ', array_slice($forms, 0, 6));
        return 'Formularios: ' . $list . '. Quieres abrir alguno?';
    }

    private function buildCapabilities(array $profile = [], array $training = [], string $mode = 'app'): string
    {
        $entities = array_map(fn($p) => basename($p, '.entity.json'), $this->catalog->entities());
        $forms = array_map(fn($p) => basename($p, '.json'), $this->catalog->forms());

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
                $actions[] = 'crear tabla ordenes_trabajo numero:texto fecha:fecha estado:texto';
                $actions[] = 'crear formulario ordenes_trabajo';
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

    private function routeTraining(string $text, array $training, array $profile = [], string $tenantId = 'default', string $userId = 'anon', array $state = [], array $lexicon = [], string $mode = 'app'): array
    {
        if (empty($training) || empty($training['intents'])) {
            return [];
        }
        $route = $this->classifyWithTraining($text, $training, $profile);
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
                    $detectedEntity = $this->detectEntity($text, $lexicon, $state);
                    $wantsCatalog = str_contains($text, 'tabla')
                        || str_contains($text, 'tablas')
                        || str_contains($text, 'entidad')
                        || str_contains($text, 'entidades');
                    if ($detectedEntity !== '' || $this->isDataListRequest($text) || !$wantsCatalog) {
                        return [];
                    }
                }
                return ['action' => 'respond_local', 'reply' => $this->buildEntityList(), 'intent' => $intentName];
            case 'PROJECT_FORM_LIST':
                if ($mode !== 'builder' && ($this->isDataListRequest($text) || str_contains($text, 'crear ') || str_contains($text, 'usar '))) {
                    return [];
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
        $text = preg_replace('/[^a-z0-9\\s]/u', ' ', $text) ?? $text;
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
        }
        if (str_contains($text, 'respuesta corta') || str_contains($text, 'breve')) {
            $updated['preferred_style'] = 'breve';
            $reply = 'Listo, usare respuestas mas cortas.';
        }
        $this->memory->saveProfile($tenantId, $userId, $updated);
        return ['profile' => $updated, 'reply' => $reply];
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
        $this->memory->saveProfile($tenantId, $userId, $updated);
        return ['profile' => $updated, 'reply' => 'Listo, lo guardo para entenderte mejor.'];
    }

    private function registerUnknownBusinessCase(string $tenantId, string $userId, string $candidate, string $sampleText): void
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return;
        }
        $this->memory->appendResearchTopic(
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
        $glossary = $this->memory->getGlossary($tenantId);
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
        $this->memory->saveGlossary($tenantId, $glossary);
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
        $overridePath = $this->trainingOverridesPath($tenantId);
        $baseMtime = is_file($path) ? (int) @filemtime($path) : 0;
        $overrideMtime = is_file($overridePath) ? (int) @filemtime($overridePath) : 0;
        $cacheKey = $this->safe($tenantId);
        if (isset($this->trainingBaseCache[$cacheKey])) {
            $cached = $this->trainingBaseCache[$cacheKey];
            if (($cached['base_mtime'] ?? 0) === $baseMtime && ($cached['override_mtime'] ?? 0) === $overrideMtime) {
                return $cached['data'] ?? [];
            }
        }
        if (!is_file($path)) {
            $this->trainingBaseCache[$cacheKey] = [
                'data' => [],
                'base_mtime' => $baseMtime,
                'override_mtime' => $overrideMtime
            ];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->trainingBaseCache[$cacheKey] = [
                'data' => [],
                'base_mtime' => $baseMtime,
                'override_mtime' => $overrideMtime
            ];
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $training = is_array($decoded) ? $decoded : [];
        $training = $this->applyTrainingOverrides($training, $overridePath);
        $this->trainingBaseCache[$cacheKey] = [
            'data' => $training,
            'base_mtime' => $baseMtime,
            'override_mtime' => $overrideMtime
        ];
        return $training;
    }

    private function trainingBasePath(): string
    {
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        return $frameworkRoot . '/contracts/agents/conversation_training_base.json';
    }

    private function trainingOverridesPath(string $tenantId): string
    {
        return $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/training_overrides.json';
    }

    private function applyTrainingOverrides(array $training, string $overridePath): array
    {
        if (!is_file($overridePath)) {
            return $training;
        }
        $overrides = $this->readJson($overridePath, []);
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
        $path = $this->statePath($tenantId, $projectId, $mode, $userId);
        $default = [
            'active_task' => null,
            'intent' => null,
            'entity' => null,
            'collected' => [],
            'missing' => [],
            'requested_slot' => null,
            'builder_pending_command' => null,
            'unknown_business_notice_sent' => false,
            'last_messages' => [],
            'summary' => null,
        ];

        if (is_file($path)) {
            return $this->readJson($path, $default);
        }

        $legacyPath = $this->legacyStatePath($tenantId, $userId);
        if (is_file($legacyPath)) {
            $legacy = $this->readJson($legacyPath, $default);
            $this->writeJson($path, $legacy);
            return $legacy;
        }

        return $this->readJson($path, $default);
    }

    private function saveState(string $tenantId, string $userId, array $state): void
    {
        $path = $this->statePath($tenantId, $this->contextProjectId, $this->contextMode, $userId);
        $this->writeJson($path, $state);
    }

    private function loadLexicon(string $tenantId): array
    {
        $path = $this->tenantPath($tenantId) . '/lexicon.json';
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
        return $this->readJson($path, $default);
    }

    private function loadPolicy(string $tenantId): array
    {
        $path = $this->tenantPath($tenantId) . '/dialog_policy.json';
        return $this->readJson($path, [
            'ask_style' => 'short',
            'confirm_delete' => true,
            'max_questions_before_llm' => 2,
            'latency_budget_ms' => 1200,
            'max_output_tokens' => 400,
            'question_templates' => [],
        ]);
    }

    private function loadDomainPlaybook(): array
    {
        if ($this->domainPlaybookCache !== null) {
            return $this->domainPlaybookCache;
        }
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $path = $frameworkRoot . '/contracts/agents/domain_playbooks.json';
        if (!is_file($path)) {
            $this->domainPlaybookCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->domainPlaybookCache = [];
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $this->domainPlaybookCache = is_array($decoded) ? $decoded : [];
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

    private function safe(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $value) ?? 'default';
        return trim($value, '_');
    }
}


