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

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
        $this->entities = new EntityRegistry();
        $this->catalog = new ContractsCatalog($this->projectRoot);
        $this->memory = new ChatMemoryStore($this->projectRoot);
    }

    public function handle(string $tenantId, string $userId, string $message, string $mode = 'app'): array
    {
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $userId = $userId !== '' ? $userId : 'anon';

        $raw = trim($message);
        $training = $this->loadTrainingBase($tenantId);
        $normalized = $this->normalizeWithTraining($raw, $training);

        $state = $this->loadState($tenantId, $userId);
        $lexicon = $this->loadLexicon($tenantId);
        $glossary = $this->memory->getGlossary($tenantId);
        if (!empty($glossary)) {
            $lexicon = $this->mergeLexicon($lexicon, $glossary);
        }
        $profile = $this->memory->getProfile($tenantId, $userId);
        $policy = $this->loadPolicy($tenantId);

        $trainingRoute = $this->routeTraining($normalized, $training, $profile, $tenantId, $userId, $state, $lexicon);
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
                $state = $this->updateState($state, $raw, 'OK', $trainingRoute['intent'] ?? null, $trainingRoute['entity'] ?? null, $trainingRoute['collected'] ?? [], $trainingRoute['active_task'] ?? null);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('execute_command', '', $trainingRoute['command'], null, $state, $this->telemetry('training', true, $trainingRoute));
            }
        }

        if ($this->isCrudGuideRequest($normalized, $state, $training)) {
            $entity = $this->detectEntity($normalized, $lexicon, $state);
            if ($entity === '') {
                $reply = '¿De cuál lista? Ej: clientes, productos o facturas.';
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
        if (in_array($classification, ['greeting', 'thanks', 'confirm', 'faq'], true)) {
            $reply = $this->localReply($classification);
            $state = $this->updateState($state, $raw, $reply, null, null, [], null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry($classification, true));
        }

        if ($classification === 'status') {
            $reply = $this->buildProjectStatus();
            $state = $this->updateState($state, $raw, $reply, $state['intent'] ?? null, $state['entity'] ?? null, $state['collected'] ?? [], $state['active_task'] ?? null);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry('status', true));
        }

        if ($mode === 'builder') {
            $build = $this->parseBuild($normalized, $state);
            if (!empty($build['ask'])) {
                $state = $this->updateState($state, $raw, $build['ask'], null, $build['entity'] ?? null, $build['collected'] ?? [], $build['active_task'] ?? 'build');
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $build['ask'], null, null, $state, $this->telemetry('build', true, $build));
            }
            if (!empty($build['command'])) {
                $state = $this->updateState($state, $raw, 'OK', null, $build['entity'] ?? null, $build['collected'] ?? [], null);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('execute_command', '', $build['command'], null, $state, $this->telemetry('build', true, $build));
            }
        }

        $shouldCrud = $classification === 'crud' || (($state['active_task'] ?? '') === 'crud') || !empty($state['missing']);
        if ($shouldCrud) {
            $parsed = $this->parseCrud($normalized, $lexicon, $state, $mode);
            if (!empty($parsed['missing_entity'])) {
                $entityName = (string) ($parsed['entity'] ?? '');
                if ($mode === 'builder') {
                    $reply = 'No existe la tabla ' . $entityName . '. ¿Quieres crearla? Ej: crear tabla ' . $entityName . ' nombre:texto';
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
                $state = $this->updateState($state, $raw, 'OK', $parsed['intent'] ?? null, $parsed['entity'] ?? null, $parsed['collected'] ?? [], null, []);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('execute_command', '', $parsed['command'], null, $state, $this->telemetry('crud', true, $parsed));
            }
        }

        $capsule = $this->buildContextCapsule($normalized, $state, $lexicon, $policy, $classification);
        $state = $this->updateState($state, $raw, '', $capsule['intent'] ?? null, $capsule['entity'] ?? null, $capsule['state']['collected'] ?? [], $state['active_task'] ?? null);
        $this->saveState($tenantId, $userId, $state);

        return $this->result('send_to_llm', '', null, $capsule, $state, $this->telemetry('llm', false));
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
        $confirm = ['si', 'sí', 'confirmo', 'dale'];
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

        $crudVerbs = ['crear', 'agregar', 'nuevo', 'listar', 'ver', 'buscar', 'actualizar', 'editar', 'eliminar', 'borrar', 'guardar', 'registrar', 'emitir', 'facturar'];
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

    private function parseBuild(string $text, array $state): array
    {
        $hasCreate = str_contains($text, 'crear');
        $hasTable = str_contains($text, 'tabla') || str_contains($text, 'entidad');
        $hasForm = str_contains($text, 'formulario') || str_contains($text, 'form');

        if ($hasCreate && $hasTable) {
            $parsed = $this->parseTableDefinition($text);
            if ($parsed['entity'] === '') {
                return ['ask' => 'Como se llama la tabla? Ej: clientes nombre:texto nit:texto', 'active_task' => 'create_table'];
            }
            if (empty($parsed['fields'])) {
                return ['ask' => 'Que campos quieres? Ej: nombre:texto nit:texto', 'active_task' => 'create_table', 'entity' => $parsed['entity']];
            }
            return ['command' => ['command' => 'CreateEntity', 'entity' => $parsed['entity'], 'fields' => $parsed['fields']], 'entity' => $parsed['entity'], 'collected' => []];
        }

        if ($hasCreate && $hasForm) {
            $entity = $this->parseEntityFromText($text);
            if ($entity === '') {
                return ['ask' => 'De que tabla quieres el formulario? Ej: crear formulario clientes', 'active_task' => 'create_form'];
            }
            return ['command' => ['command' => 'CreateForm', 'entity' => $entity], 'entity' => $entity, 'collected' => []];
        }

        if (($state['active_task'] ?? '') === 'create_table') {
            $parsed = $this->parseTableDefinition($text);
            if ($parsed['entity'] === '' && !empty($state['entity'])) {
                $parsed['entity'] = (string) $state['entity'];
            }
            if ($parsed['entity'] === '') {
                return ['ask' => 'Necesito el nombre de la tabla. Ej: clientes nombre:texto nit:texto', 'active_task' => 'create_table'];
            }
            if (empty($parsed['fields'])) {
                return ['ask' => 'Que campos quieres en ' . $parsed['entity'] . '? Ej: nombre:texto nit:texto', 'active_task' => 'create_table', 'entity' => $parsed['entity']];
            }
            return ['command' => ['command' => 'CreateEntity', 'entity' => $parsed['entity'], 'fields' => $parsed['fields']], 'entity' => $parsed['entity'], 'collected' => []];
        }

        if (($state['active_task'] ?? '') === 'create_form') {
            $entity = $this->parseEntityFromText($text);
            if ($entity === '') {
                return ['ask' => 'Necesito la tabla para el formulario. Ej: clientes', 'active_task' => 'create_form'];
            }
            return ['command' => ['command' => 'CreateForm', 'entity' => $entity], 'entity' => $entity, 'collected' => []];
        }

        return [];
    }

    private function parseTableDefinition(string $text): array
    {
        $text = str_replace(',', ' ', $text);
        $text = preg_replace('/^(crear\\s+)?(tabla|entidad)\\s+/i', '', $text) ?? $text;
        $tokens = preg_split('/\\s+/', trim($text)) ?: [];
        $entity = '';
        $fields = [];

        foreach ($tokens as $idx => $token) {
            if ($idx === 0 && !str_contains($token, ':') && !str_contains($token, '=')) {
                $entity = $token;
                continue;
            }
            if (str_contains($token, ':') || str_contains($token, '=')) {
                $sep = str_contains($token, ':') ? ':' : '=';
                [$rawName, $rawType] = array_pad(explode($sep, $token, 2), 2, 'text');
                $name = trim($rawName);
                $type = trim($rawType ?: 'text');
                if ($name === '') continue;
                $fields[] = ['name' => $name, 'type' => $type];
            }
        }

        return ['entity' => $entity, 'fields' => $fields];
    }

    private function parseEntityFromText(string $text): string
    {
        $text = str_replace(',', ' ', $text);
        $text = preg_replace('/^(crear\\s+)?formulario\\s+/i', '', $text) ?? $text;
        $tokens = preg_split('/\\s+/', trim($text)) ?: [];
        return $tokens[0] ?? '';
    }

    private function parseCrud(string $text, array $lexicon, array $state, string $mode = 'app'): array
    {
        $collected = $this->extractFields($text, $lexicon);
        if (empty($collected) && !empty($state['missing']) && $this->isLikelyValueReply($text)) {
            $firstMissing = (string) ($state['missing'][0] ?? '');
            if ($firstMissing !== '') {
                $collected[$firstMissing] = trim($text);
            }
        }

        $intent = $this->detectIntent($text);
        if ($intent === '') {
            if (!empty($collected) && !empty($state['intent']) && !empty($state['entity'])) {
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
        $aliases = $lexicon['entity_aliases'] ?? [];
        foreach ($aliases as $alias => $entity) {
            if (str_contains($text, $alias)) {
                return (string) $entity;
            }
        }

        $entities = $this->catalog->entities();
        foreach ($entities as $path) {
            $name = basename($path, '.entity.json');
            if ($name !== '' && str_contains($text, $name)) {
                return $name;
            }
            if ($name !== '' && str_ends_with($name, 's')) {
                $singular = substr($name, 0, -1);
                if ($singular !== '' && str_contains($text, $singular)) {
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
        $entities = $this->catalog->entities();
        foreach ($entities as $path) {
            if (basename($path, '.entity.json') === $entity) {
                return true;
            }
        }
        return false;
    }

    private function hasBuildSignals(string $text): bool
    {
        $markers = ['tabla', 'entidad', 'formulario', 'form', 'campo', 'columnas'];
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function parseEntityFromCrudText(string $text): string
    {
        $text = preg_replace('/^(crear|agregar|nuevo|listar|ver|buscar|actualizar|editar|eliminar|borrar|guardar|registrar|emitir|facturar)\\s+/i', '', $text) ?? $text;
        $tokens = preg_split('/\\s+/', trim($text)) ?: [];
        if (empty($tokens)) {
            return '';
        }
        $candidate = $tokens[0];
        if ($candidate === '' || str_contains($candidate, '=') || str_contains($candidate, ':')) {
            return '';
        }
        return $candidate;
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
        return 'Me falta ' . $first . '. ¿Cuál es?';
    }

    private function buildEntityList(): string
    {
        $entities = array_map(fn($p) => basename($p, '.entity.json'), $this->catalog->entities());
        if (empty($entities)) {
            return 'Aun no hay tablas creadas. ¿Quieres crear una?';
        }
        $list = implode(', ', array_slice($entities, 0, 6));
        return 'Tablas creadas: ' . $list . '. ¿Quieres ver los campos de alguna?';
    }

    private function buildFormList(): string
    {
        $forms = array_map(fn($p) => basename($p, '.json'), $this->catalog->forms());
        if (empty($forms)) {
            return 'Aun no hay formularios. ¿Quieres crear uno?';
        }
        $list = implode(', ', array_slice($forms, 0, 6));
        return 'Formularios: ' . $list . '. ¿Quieres abrir alguno?';
    }

    private function buildCapabilities(array $profile = [], array $training = []): string
    {
        $help = $training['help']['app'] ?? [];
        $examples = $help['capabilities'] ?? [
            'Crear tablas y formularios por chat.',
            'Guardar datos (clientes, productos, facturas).',
            'Mostrar reportes y totales.',
        ];
        if (!empty($profile['business_type'])) {
            $examples[] = 'Adaptarme a tu negocio (' . $profile['business_type'] . ').';
        }
        return "Puedo ayudarte con:\n- " . implode("\n- ", array_slice($examples, 0, 4)) . "\n¿Quieres crear algo nuevo o usar lo que ya tienes?";
    }

    private function routeTraining(string $text, array $training, array $profile = [], string $tenantId = 'default', string $userId = 'anon', array $state = [], array $lexicon = []): array
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
                return ['action' => 'respond_local', 'reply' => $this->buildProjectStatus(), 'intent' => $intentName];
            case 'PROJECT_ENTITY_LIST':
                return ['action' => 'respond_local', 'reply' => $this->buildEntityList(), 'intent' => $intentName];
            case 'PROJECT_FORM_LIST':
                return ['action' => 'respond_local', 'reply' => $this->buildFormList(), 'intent' => $intentName];
            case 'APP_CAPABILITIES':
                return ['action' => 'respond_local', 'reply' => $this->buildCapabilities($profile, $training), 'intent' => $intentName];
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
                    return ['action' => 'ask_user', 'reply' => '¿De cuál lista? Ej: clientes, productos o facturas.', 'intent' => $intentName, 'active_task' => 'crud_guide'];
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

    private function tokenizeTraining(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^a-z0-9ñáéíóúü\\s]/u', ' ', $text) ?? $text;
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
        if (str_contains($text, 'ferreteria')) {
            $updated['business_type'] = 'ferreteria';
        } elseif (str_contains($text, 'restaurante')) {
            $updated['business_type'] = 'restaurante';
        } elseif (str_contains($text, 'tienda')) {
            $updated['business_type'] = 'tienda';
        }
        if (str_contains($text, 'respuesta corta') || str_contains($text, 'breve')) {
            $updated['preferred_style'] = 'breve';
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

    private function parseAuthLogin(string $text, array $state): array
    {
        $collected = $state['collected'] ?? [];
        $pairs = $this->parseKeyValues($text);
        $collected = array_merge($collected, $pairs);

        $user = $collected['usuario'] ?? $collected['user'] ?? $collected['user_id'] ?? null;
        $password = $collected['clave'] ?? $collected['password'] ?? $collected['codigo'] ?? null;

        if (!$user) {
            return ['ask' => '¿Cuál es tu usuario?', 'collected' => $collected];
        }
        if (!$password) {
            return ['ask' => '¿Cuál es tu clave o código?', 'collected' => $collected];
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
            return ['ask' => '¿Cómo se llamará el usuario?', 'collected' => $collected];
        }
        if (!$role) {
            return ['ask' => '¿Qué rol tendrá (admin, vendedor, contador)?', 'collected' => $collected];
        }
        if (!$password) {
            return ['ask' => '¿Qué clave tendrá?', 'collected' => $collected];
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
            $state['active_task'] = $activeTask;
        }
        if ($missing !== null) {
            $state['missing'] = $missing;
        }
        $state['collected'] = array_merge($state['collected'] ?? [], $collected);
        $state['last_messages'] = $history;
        if (empty($state['summary'])) {
            $state['summary'] = mb_substr($userText, 0, 120);
        }
        return $state;
    }

    private function loadState(string $tenantId, string $userId): array
    {
        $path = $this->tenantPath($tenantId) . '/agent_state/' . $this->safe($userId) . '.json';
        return $this->readJson($path, [
            'active_task' => null,
            'intent' => null,
            'entity' => null,
            'collected' => [],
            'missing' => [],
            'last_messages' => [],
            'summary' => null,
        ]);
    }

    private function saveState(string $tenantId, string $userId, array $state): void
    {
        $path = $this->tenantPath($tenantId) . '/agent_state/' . $this->safe($userId) . '.json';
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
