<?php
// app/Core/ChatAgent.php

namespace App\Core;

use App\Core\Agents\ConversationGateway;
use App\Core\Agents\Telemetry;
use App\Core\LLM\LLMRouter;

use RuntimeException;

final class ChatAgent
{
    private ?CommandLayer $command = null;
    private EntityRegistry $entities;
    private ?EntityMigrator $migrator = null;
    private ?ConversationGateway $gateway = null;
    private ?LLMRouter $llmRouter = null;
    private ?Telemetry $telemetry = null;
    private FormWizard $wizard;
    private ContractWriter $writer;
    private EntityBuilder $builder;
    private ChatMemoryStore $memory;

    public function __construct()
    {
        $this->entities = new EntityRegistry();
        $this->wizard = new FormWizard();
        $this->writer = new ContractWriter();
        $this->builder = new EntityBuilder();
        $this->memory = new ChatMemoryStore();
    }

    public function handle(array $payload): array
    {
        $text = trim((string) ($payload['message'] ?? $payload['text'] ?? ''));
        $channel = (string) ($payload['channel'] ?? 'local');
        $sessionId = (string) ($payload['session_id'] ?? 'sess_' . time());
        $userId = (string) ($payload['user_id'] ?? 'anon');
        $tenantId = (string) ($payload['tenant_id'] ?? getenv('TENANT_ID') ?? 'default');
        $role = (string) ($payload['role'] ?? $payload['user_role'] ?? getenv('DEFAULT_ROLE') ?? 'admin');
        \App\Core\RoleContext::setRole($role);
        \App\Core\RoleContext::setUserId($userId);
        \App\Core\RoleContext::setUserLabel((string) ($payload['user_label'] ?? ''));

        if ($text === '' && empty($payload['meta'])) {
            return $this->reply('Mensaje vacio.', $channel, $sessionId, $userId, 'error');
        }

        if ($text === '' && !empty($payload['meta'])) {
            return $this->reply('Archivo recibido. Procesaremos OCR/Audio cuando este habilitado.', $channel, $sessionId, $userId);
        }

        $gateway = $this->gateway();
        $result = $gateway->handle($tenantId, $userId, $text);
        $action = $result['action'] ?? 'respond_local';
        $telemetry = $result['telemetry'] ?? [];

        if ($action === 'respond_local' || $action === 'ask_user') {
            $reply = $this->reply((string) ($result['reply'] ?? ''), $channel, $sessionId, $userId);
            $this->telemetry()->record($tenantId, array_merge($telemetry, [
                'message' => $text,
                'resolved_locally' => true,
            ]));
            return $reply;
        }

        if ($action === 'execute_command' && !empty($result['command'])) {
            $reply = $this->executeCommandPayload((array) $result['command'], $channel, $sessionId, $userId);
            $this->telemetry()->record($tenantId, array_merge($telemetry, [
                'message' => $text,
                'resolved_locally' => true,
            ]));
            return $reply;
        }

        if ($action === 'send_to_llm' && !empty($result['llm_request'])) {
            try {
                $llmResult = $this->llmRouter()->chat((array) $result['llm_request']);
            } catch (\Throwable $e) {
                return $this->reply('IA no disponible. Usa comandos simples.', $channel, $sessionId, $userId);
            }
            $provider = $llmResult['provider'] ?? 'llm';
            $this->telemetry()->record($tenantId, array_merge($telemetry, [
                'message' => $text,
                'provider_used' => $provider,
                'resolved_locally' => false,
            ]));

            $json = $llmResult['json'] ?? null;
            if (is_array($json)) {
                $reply = $this->executeLlmJson($json, $channel, $sessionId, $userId);
                return $reply;
            }

            $textReply = (string) ($llmResult['text'] ?? '');
            return $this->reply($textReply !== '' ? $textReply : 'Listo.', $channel, $sessionId, $userId);
        }

        return $this->reply('No entendi. Puedes decir: crear cliente nombre=Juan nit=123', $channel, $sessionId, $userId, 'error');
    }

    public function parseLocal(string $text): array
    {
        $tokens = $this->tokenize($text);
        if (!$tokens) {
            return [];
        }

        $first = strtolower($tokens[0]);
        if (in_array($first, ['probar', 'test', 'diagnostico', 'diagnosticar'], true)) {
            return ['command' => 'RunTests'];
        }

        if ($first === 'crear' && isset($tokens[1]) && in_array(strtolower($tokens[1]), ['tabla', 'entidad'], true)) {
            $entity = $tokens[2] ?? '';
            $fields = $this->parseFieldTokens(array_slice($tokens, 3));
            return ['command' => 'CreateEntity', 'entity' => $entity, 'fields' => $fields];
        }

        if ($first === 'crear' && isset($tokens[1]) && in_array(strtolower($tokens[1]), ['formulario', 'form'], true)) {
            $entity = $tokens[2] ?? '';
            return ['command' => 'CreateForm', 'entity' => $entity];
        }

        return $this->parseCrud($tokens);
    }

    private function executeLocal(array $parsed, string $channel, string $sessionId, string $userId): array
    {
        $cmd = $parsed['command'] ?? '';
        if ($cmd === 'RunTests') {
            $runner = new UnitTestRunner();
            $result = $runner->run();
            $summary = $result['summary'];
            $warns = array_filter($result['tests'], fn($t) => $t['status'] === 'warn');
            $fails = array_filter($result['tests'], fn($t) => $t['status'] === 'fail');
            $warnList = $warns ? implode(', ', array_map(fn($t) => $t['name'], $warns)) : '';
            $failList = $fails ? implode(', ', array_map(fn($t) => $t['name'], $fails)) : '';
            $reply = "Pruebas: {$summary['passed']} ok, {$summary['warned']} warn, {$summary['failed']} fail.";
            if ($warnList !== '') {
                $reply .= " Warn: {$warnList}.";
            }
            if ($failList !== '') {
                $reply .= " Fail: {$failList}.";
            }
            return $this->reply($reply, $channel, $sessionId, $userId, 'success', $result);
        }

        if ($cmd === 'CreateEntity') {
            $entityName = (string) ($parsed['entity'] ?? '');
            if ($entityName === '') {
                return $this->reply('Necesito el nombre de la tabla.', $channel, $sessionId, $userId, 'error');
            }
            $entity = $this->builder->build($entityName, $parsed['fields'] ?? []);
            $this->writer->writeEntity($entity);
            $this->migrator()->migrateEntity($entity, true);
            return $this->reply('Tabla creada: ' . $entity['name'], $channel, $sessionId, $userId, 'success', ['entity' => $entity]);
        }

        if ($cmd === 'CreateForm') {
            $entityName = (string) ($parsed['entity'] ?? '');
            if ($entityName === '') {
                return $this->reply('Necesito la entidad para el formulario.', $channel, $sessionId, $userId, 'error');
            }
            $entity = $this->entities->get($entityName);
            $form = $this->wizard->buildFromEntity($entity);
            $this->writer->writeForm($form);
            return $this->reply('Formulario creado para ' . $entityName, $channel, $sessionId, $userId, 'success', ['form' => $form]);
        }

        if (in_array($cmd, ['CreateRecord', 'QueryRecords', 'ReadRecord', 'UpdateRecord', 'DeleteRecord'], true)) {
            return $this->executeCrud($parsed, $channel, $sessionId, $userId);
        }

        return $this->reply('Comando no soportado.', $channel, $sessionId, $userId, 'error');
    }

    private function executeIntent(array $intent, string $channel, string $sessionId, string $userId): array
    {
        $actions = $intent['actions'] ?? [];
        if (!is_array($actions) || count($actions) === 0) {
            return $this->reply($this->buildHelpMessage(), $channel, $sessionId, $userId);
        }

        $results = [];
        $replyParts = [];
        foreach ($actions as $action) {
            $type = strtolower((string) ($action['type'] ?? 'help'));
            switch ($type) {
                case 'create_entity':
                    $entityName = (string) ($action['entity'] ?? '');
                    if ($entityName === '') {
                        $replyParts[] = 'Falta nombre de tabla.';
                        break;
                    }
                    $entity = $this->builder->build($entityName, $action['fields'] ?? [], ['label' => $action['label'] ?? null]);
                    $this->writer->writeEntity($entity);
                    $this->migrator()->migrateEntity($entity, true);
                    $results[] = ['entity' => $entity];
                    $replyParts[] = 'Tabla creada: ' . $entity['name'];
                    break;
                case 'add_field':
                    $replyParts[] = 'Agregar campos: pendiente.';
                    break;
                case 'create_form':
                    $entityName = (string) ($action['entity'] ?? '');
                    if ($entityName === '') {
                        $replyParts[] = 'Falta entidad para formulario.';
                        break;
                    }
                    $entity = $this->entities->get($entityName);
                    $form = $this->wizard->buildFromEntity($entity);
                    $this->writer->writeForm($form);
                    $results[] = ['form' => $form];
                    $replyParts[] = 'Formulario creado: ' . ($form['name'] ?? $entityName);
                    break;
                case 'create_record':
                    $results[] = $this->command()->createRecord((string) ($action['entity'] ?? ''), (array) ($action['data'] ?? []));
                    $replyParts[] = 'Registro creado.';
                    break;
                case 'query_records':
                    $results[] = $this->command()->queryRecords((string) ($action['entity'] ?? ''), (array) ($action['filters'] ?? []), 20, 0);
                    $replyParts[] = 'Consulta lista.';
                    break;
                case 'update_record':
                    $results[] = $this->command()->updateRecord((string) ($action['entity'] ?? ''), $action['id'] ?? null, (array) ($action['data'] ?? []));
                    $replyParts[] = 'Registro actualizado.';
                    break;
                case 'delete_record':
                    $results[] = $this->command()->deleteRecord((string) ($action['entity'] ?? ''), $action['id'] ?? null);
                    $replyParts[] = 'Registro eliminado.';
                    break;
                case 'run_tests':
                    $runner = new UnitTestRunner();
                    $result = $runner->run();
                    $results[] = $result;
                    $summary = $result['summary'];
                    $warns = array_filter($result['tests'], fn($t) => $t['status'] === 'warn');
                    $fails = array_filter($result['tests'], fn($t) => $t['status'] === 'fail');
                    $warnList = $warns ? implode(', ', array_map(fn($t) => $t['name'], $warns)) : '';
                    $failList = $fails ? implode(', ', array_map(fn($t) => $t['name'], $fails)) : '';
                    $line = "Pruebas: {$summary['passed']} ok, {$summary['warned']} warn, {$summary['failed']} fail.";
                    if ($warnList !== '') {
                        $line .= " Warn: {$warnList}.";
                    }
                    if ($failList !== '') {
                        $line .= " Fail: {$failList}.";
                    }
                    $replyParts[] = $line;
                    break;
                case 'help':
                default:
                    $replyParts[] = $this->buildHelpMessage();
                    break;
            }
        }

        $reply = implode("\n", $replyParts);
        return $this->reply($reply, $channel, $sessionId, $userId, 'success', ['actions' => $actions, 'results' => $results]);
    }

    private function executeCrud(array $parsed, string $channel, string $sessionId, string $userId): array
    {
        $cmd = $parsed['command'];
        $entity = (string) ($parsed['entity'] ?? '');
        if ($entity === '') {
            return $this->reply('Falta entidad.', $channel, $sessionId, $userId, 'error');
        }
        $data = [];
        switch ($cmd) {
            case 'CreateRecord':
                $data = $this->command()->createRecord($entity, $parsed['data'] ?? []);
                $reply = 'Registro creado en ' . $entity;
                break;
            case 'QueryRecords':
                $data = $this->command()->queryRecords($entity, $parsed['filters'] ?? [], 20, 0);
                $reply = 'Resultados para ' . $entity . ': ' . count($data);
                break;
            case 'ReadRecord':
                $data = $this->command()->readRecord($entity, $parsed['id'] ?? null, true);
                $reply = 'Registro: ' . $entity;
                break;
            case 'UpdateRecord':
                $data = $this->command()->updateRecord($entity, $parsed['id'] ?? null, $parsed['data'] ?? []);
                $reply = 'Registro actualizado en ' . $entity;
                break;
            case 'DeleteRecord':
                $data = $this->command()->deleteRecord($entity, $parsed['id'] ?? null);
                $reply = 'Registro eliminado en ' . $entity;
                break;
            default:
                return $this->reply('Comando no soportado.', $channel, $sessionId, $userId, 'error');
        }
        return $this->reply($reply, $channel, $sessionId, $userId, 'success', $data);
    }

    private function parseCrud(array $tokens): array
    {
        $verb = strtolower(array_shift($tokens));
        $verbMap = [
            'crear' => 'CreateRecord',
            'nuevo' => 'CreateRecord',
            'agregar' => 'CreateRecord',
            'add' => 'CreateRecord',
            'listar' => 'QueryRecords',
            'lista' => 'QueryRecords',
            'ver' => 'QueryRecords',
            'buscar' => 'QueryRecords',
            'consulta' => 'QueryRecords',
            'actualizar' => 'UpdateRecord',
            'editar' => 'UpdateRecord',
            'update' => 'UpdateRecord',
            'eliminar' => 'DeleteRecord',
            'borrar' => 'DeleteRecord',
            'delete' => 'DeleteRecord',
            'leer' => 'ReadRecord',
        ];

        if (!isset($verbMap[$verb])) {
            return [];
        }

        $entity = '';
        $data = [];
        $filters = [];
        $id = null;
        foreach ($tokens as $token) {
            if (strpos($token, '=') !== false || strpos($token, ':') !== false) {
                $sep = strpos($token, '=') !== false ? '=' : ':';
                [$rawKey, $rawVal] = array_pad(explode($sep, $token, 2), 2, '');
                $key = trim($rawKey);
                $val = trim($rawVal);
                if ($key === '') {
                    continue;
                }
                if (strtolower($key) === 'id') {
                    $id = $val;
                    continue;
                }
                $data[$key] = $val;
                $filters[$key] = $val;
                continue;
            }
            if ($entity === '') {
                $entity = $token;
            }
        }
        if ($entity === '') {
            return [];
        }

        $command = $verbMap[$verb];
        if ($command === 'QueryRecords' && $id !== null && $id !== '') {
            $command = 'ReadRecord';
        }

        return [
            'command' => $command,
            'entity' => $entity,
            'data' => $data,
            'filters' => $filters,
            'id' => $id,
        ];
    }

    private function parseFieldTokens(array $tokens): array
    {
        $fields = [];
        foreach ($tokens as $token) {
            if (!str_contains($token, ':') && !str_contains($token, '=')) {
                continue;
            }
            $sep = str_contains($token, ':') ? ':' : '=';
            [$rawName, $rawType] = array_pad(explode($sep, $token, 2), 2, 'string');
            $name = trim($rawName);
            $type = trim($rawType);
            if ($name === '') {
                continue;
            }
            $fields[] = ['name' => $name, 'type' => $type];
        }
        return $fields;
    }

    private function tokenize(string $message): array
    {
        $tokens = [];
        $len = strlen($message);
        $buf = '';
        $inQuote = false;
        $quoteChar = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $message[$i];
            if ($inQuote) {
                if ($ch === $quoteChar) {
                    $inQuote = false;
                    continue;
                }
                if ($ch === '\\' && $i + 1 < $len) {
                    $buf .= $message[$i + 1];
                    $i++;
                    continue;
                }
                $buf .= $ch;
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $inQuote = true;
                $quoteChar = $ch;
                continue;
            }
            if (ctype_space($ch)) {
                if ($buf !== '') {
                    $tokens[] = $buf;
                    $buf = '';
                }
                continue;
            }
            $buf .= $ch;
        }

        if ($buf !== '') {
            $tokens[] = $buf;
        }
        return $tokens;
    }

    private function isHelpIntent(string $text): bool
    {
        $text = trim(mb_strtolower($text));
        if ($text === '') return true;
        $keywords = ['hola', 'buenas', 'buenos', 'ayuda', 'help', 'menu', 'funciones', 'que puedes', 'que haces', 'cami'];
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function buildHelpMessage(): string
    {
        $catalog = new ContractsCatalog();
        $forms = $catalog->forms();
        $entities = $catalog->entities();

        $formNames = [];
        foreach ($forms as $path) {
            $data = json_decode((string) @file_get_contents($path), true);
            $name = is_array($data) ? ($data['title'] ?? $data['name'] ?? basename($path, '.json')) : basename($path, '.json');
            $formNames[] = $name;
        }
        $entityNames = [];
        foreach ($entities as $path) {
            $data = json_decode((string) @file_get_contents($path), true);
            $name = is_array($data) ? ($data['label'] ?? $data['name'] ?? basename($path, '.entity.json')) : basename($path, '.entity.json');
            $entityNames[] = $name;
        }

        $lines = [];
        $lines[] = 'Hola, soy Cami. Estoy lista para ayudarte.';
        $lines[] = 'Ejemplos rapidos:';
        $lines[] = '- crear cliente nombre=Juan nit=123';
        $lines[] = '- listar cliente';
        $lines[] = '- crear tabla productos nombre:texto precio:numero';
        $lines[] = '- crear formulario productos';
        $lines[] = '- probar sistema';
        $lines[] = 'Formularios activos: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 5)) : 'sin formularios');
        $lines[] = 'Entidades activas: ' . (count($entityNames) ? implode(', ', array_slice($entityNames, 0, 5)) : 'sin entidades');
        $lines[] = 'Puedes enviar archivos (audio/imagen/PDF). Se procesaran cuando el OCR/voz este habilitado.';
        return implode("\n", $lines);
    }

    private function buildContext(): array
    {
        $catalog = new ContractsCatalog();
        $entities = [];
        foreach ($catalog->entities() as $path) {
            $entities[] = basename($path, '.entity.json');
        }
        $forms = [];
        foreach ($catalog->forms() as $path) {
            $forms[] = basename($path, '.json');
        }
        return [
            'entities' => $entities,
            'forms' => $forms,
        ];
    }

    private function reply(string $text, string $channel, string $sessionId, string $userId, string $status = 'success', array $data = []): array
    {
        return [
            'status' => $status,
            'message' => $status === 'success' ? 'OK' : $text,
            'data' => array_merge([
                'reply' => $text,
                'channel' => $channel,
                'session_id' => $sessionId,
                'user_id' => $userId,
            ], $data),
        ];
    }

    private function command(): CommandLayer
    {
        if (!$this->command) {
            $this->command = new CommandLayer();
        }
        return $this->command;
    }

    private function migrator(): EntityMigrator
    {
        if (!$this->migrator) {
            $this->migrator = new EntityMigrator($this->entities);
        }
        return $this->migrator;
    }

    private function gateway(): ConversationGateway
    {
        if (!$this->gateway) {
            $this->gateway = new ConversationGateway();
        }
        return $this->gateway;
    }

    private function llmRouter(): LLMRouter
    {
        if (!$this->llmRouter) {
            $this->llmRouter = new LLMRouter();
        }
        return $this->llmRouter;
    }

    private function telemetry(): Telemetry
    {
        if (!$this->telemetry) {
            $this->telemetry = new Telemetry();
        }
        return $this->telemetry;
    }

    private function executeCommandPayload(array $command, string $channel, string $sessionId, string $userId): array
    {
        $cmd = (string) ($command['command'] ?? '');
        $entity = (string) ($command['entity'] ?? '');
        $id = $command['id'] ?? null;
        $data = (array) ($command['data'] ?? []);
        $filters = (array) ($command['filters'] ?? []);

        if ($cmd === '') {
            return $this->reply('Comando incompleto.', $channel, $sessionId, $userId, 'error');
        }

        switch ($cmd) {
            case 'CreateRecord':
                $result = $this->command()->createRecord($entity, $data);
                return $this->reply('Registro creado en ' . $entity, $channel, $sessionId, $userId, 'success', $result);
            case 'QueryRecords':
                $result = $this->command()->queryRecords($entity, $filters, 20, 0);
                return $this->reply('Resultados para ' . $entity . ': ' . count($result), $channel, $sessionId, $userId, 'success', $result);
            case 'ReadRecord':
                $result = $this->command()->readRecord($entity, $id, true);
                return $this->reply('Registro de ' . $entity, $channel, $sessionId, $userId, 'success', $result);
            case 'UpdateRecord':
                $result = $this->command()->updateRecord($entity, $id, $data);
                return $this->reply('Registro actualizado en ' . $entity, $channel, $sessionId, $userId, 'success', $result);
            case 'DeleteRecord':
                $result = $this->command()->deleteRecord($entity, $id);
                return $this->reply('Registro eliminado en ' . $entity, $channel, $sessionId, $userId, 'success', $result);
        }

        return $this->reply('Comando no soportado.', $channel, $sessionId, $userId, 'error');
    }

    private function executeLlmJson(array $json, string $channel, string $sessionId, string $userId): array
    {
        if (isset($json['command'])) {
            return $this->executeCommandPayload((array) $json['command'], $channel, $sessionId, $userId);
        }
        if (isset($json['actions']) && is_array($json['actions'])) {
            foreach ($json['actions'] as $action) {
                if (!is_array($action)) continue;
                $type = strtolower((string) ($action['type'] ?? ''));
                if ($type === 'create_record') {
                    return $this->executeCommandPayload([
                        'command' => 'CreateRecord',
                        'entity' => $action['entity'] ?? '',
                        'data' => $action['data'] ?? [],
                    ], $channel, $sessionId, $userId);
                }
                if ($type === 'query_records') {
                    return $this->executeCommandPayload([
                        'command' => 'QueryRecords',
                        'entity' => $action['entity'] ?? '',
                        'filters' => $action['filters'] ?? [],
                    ], $channel, $sessionId, $userId);
                }
            }
        }
        if (isset($json['reply'])) {
            return $this->reply((string) $json['reply'], $channel, $sessionId, $userId);
        }
        return $this->reply('Listo.', $channel, $sessionId, $userId);
    }

    private function storeMemory(string $sessionId, string $userText, string $replyText): void
    {
        if ($sessionId === '') {
            return;
        }
        $memory = $this->memory->getSession($sessionId);
        $history = $memory['history'] ?? [];
        $history[] = ['u' => $userText, 'a' => $replyText, 'ts' => time()];
        if (count($history) > 6) {
            $history = array_slice($history, -6);
        }
        $summary = $memory['summary'] ?? '';
        if ($summary === '' && $userText !== '') {
            $summary = mb_substr($userText, 0, 120);
        }
        $this->memory->saveSession($sessionId, [
            'summary' => $summary,
            'history' => $history,
        ]);
    }
}
