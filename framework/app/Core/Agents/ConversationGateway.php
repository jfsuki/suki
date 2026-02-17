<?php
// app/Core/Agents/ConversationGateway.php

namespace App\Core\Agents;

use App\Core\ContractsCatalog;
use App\Core\EntityRegistry;
use RuntimeException;

final class ConversationGateway
{
    private string $projectRoot;
    private EntityRegistry $entities;
    private ContractsCatalog $catalog;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
        $this->entities = new EntityRegistry();
        $this->catalog = new ContractsCatalog($this->projectRoot);
    }

    public function handle(string $tenantId, string $userId, string $message): array
    {
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $userId = $userId !== '' ? $userId : 'anon';

        $raw = trim($message);
        $normalized = $this->normalize($raw);

        $state = $this->loadState($tenantId, $userId);
        $lexicon = $this->loadLexicon($tenantId);
        $policy = $this->loadPolicy($tenantId);

        $classification = $this->classify($normalized);
        if (in_array($classification, ['greeting', 'thanks', 'confirm', 'faq'], true)) {
            $reply = $this->localReply($classification);
            $state = $this->updateState($state, $raw, $reply, null, null, []);
            $this->saveState($tenantId, $userId, $state);
            return $this->result('respond_local', $reply, null, null, $state, $this->telemetry($classification, true));
        }

        if ($classification === 'crud') {
            $parsed = $this->parseCrud($normalized, $lexicon, $state);
            if (!empty($parsed['ask'])) {
                $state = $this->updateState($state, $raw, $parsed['ask'], $parsed['intent'] ?? null, $parsed['entity'] ?? null, $parsed['collected'] ?? []);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('ask_user', $parsed['ask'], null, null, $state, $this->telemetry('crud', true, $parsed));
            }

            if (!empty($parsed['command'])) {
                $state = $this->updateState($state, $raw, 'OK', $parsed['intent'] ?? null, $parsed['entity'] ?? null, $parsed['collected'] ?? []);
                $this->saveState($tenantId, $userId, $state);
                return $this->result('execute_command', '', $parsed['command'], null, $state, $this->telemetry('crud', true, $parsed));
            }
        }

        $capsule = $this->buildContextCapsule($normalized, $state, $lexicon, $policy);
        $state = $this->updateState($state, $raw, '', $capsule['intent'] ?? null, $capsule['entity'] ?? null, $capsule['state']['collected'] ?? []);
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

    private function classify(string $text): string
    {
        if ($text === '') {
            return 'faq';
        }
        $greetings = ['hola', 'buenas', 'buenos', 'hello', 'saludos'];
        $thanks = ['gracias', 'thank', 'ok', 'listo'];
        $confirm = ['si', 'sí', 'confirmo', 'dale'];
        $faq = ['ayuda', 'menu', 'funciones', 'que puedes', 'que haces'];

        foreach ($greetings as $w) {
            if (str_contains($text, $w)) return 'greeting';
        }
        foreach ($thanks as $w) {
            if (str_contains($text, $w)) return 'thanks';
        }
        foreach ($confirm as $w) {
            if ($text === $w) return 'confirm';
        }
        foreach ($faq as $w) {
            if (str_contains($text, $w)) return 'faq';
        }

        $crudVerbs = ['crear', 'agregar', 'nuevo', 'listar', 'ver', 'buscar', 'actualizar', 'editar', 'eliminar', 'borrar'];
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
            case 'faq':
            default:
                return "Puedes escribirme:\n- crear cliente nombre=Juan nit=123\n- listar cliente\n- crear tabla productos nombre:texto precio:numero\n- crear formulario productos";
        }
    }

    private function parseCrud(string $text, array $lexicon, array $state): array
    {
        $intent = $this->detectIntent($text);
        if ($intent === '') {
            return [];
        }

        $entity = $this->detectEntity($text, $lexicon, $state);
        $collected = $this->extractFields($text, $lexicon);

        if ($intent === 'update' || $intent === 'delete') {
            if (!isset($collected['id'])) {
                $ask = 'Necesito el id del registro.';
                return ['ask' => $ask, 'intent' => $intent, 'entity' => $entity, 'collected' => $collected];
            }
        }

        $missing = $this->missingRequired($entity, $collected, $intent);
        if (!empty($missing)) {
            $ask = 'Me falta: ' . implode(', ', $missing) . '.';
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
        }

        return (string) ($state['entity'] ?? '');
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

    private function missingRequired(string $entity, array $collected, string $intent): array
    {
        if ($intent === 'list') {
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

    private function buildContextCapsule(string $text, array $state, array $lexicon, array $policy): array
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
                'requires_strict_json' => true,
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

    private function updateState(array $state, string $userText, string $reply, ?string $intent, ?string $entity, array $collected): array
    {
        $history = $state['last_messages'] ?? [];
        $history[] = ['u' => $userText, 'a' => $reply, 'ts' => time()];
        if (count($history) > 4) {
            $history = array_slice($history, -4);
        }

        $state['intent'] = $intent ?? ($state['intent'] ?? null);
        $state['entity'] = $entity ?? ($state['entity'] ?? null);
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
