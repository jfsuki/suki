<?php
// app/Core/ChatAgent.php

namespace App\Core;

use App\Core\Agents\ConversationGateway;
use App\Core\Agents\AcidChatRunner;
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
        $mode = strtolower((string) ($payload['mode'] ?? 'app'));
        $projectId = (string) ($payload['project_id'] ?? '');
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        if ($projectId === '') {
            $projectId = $manifest['id'] ?? 'default';
        }
        if (!defined('TENANT_ID')) {
            if (is_numeric($tenantId)) {
                define('TENANT_ID', (int) $tenantId);
                putenv('TENANT_ID=' . $tenantId);
            } else {
                $hash = abs(crc32((string) $tenantId));
                define('TENANT_ID', $hash);
                putenv('TENANT_ID=' . $hash);
                putenv('TENANT_KEY=' . $tenantId);
            }
        }
        $registry->ensureProject($projectId, $manifest['name'] ?? 'Proyecto', $manifest['status'] ?? 'draft', $manifest['tenant_mode'] ?? 'shared', $userId);
        $registry->touchUser($userId, $role, $mode === 'builder' ? 'creator' : 'app', $tenantId);
        $registry->assignUserToProject($projectId, $userId, $role);
        $registry->touchSession($sessionId, $userId, $projectId, $tenantId, $channel);
        \App\Core\RoleContext::setRole($role);
        \App\Core\RoleContext::setUserId($userId);
        \App\Core\RoleContext::setUserLabel((string) ($payload['user_label'] ?? ''));

        if ($text === '' && empty($payload['meta'])) {
            return $this->reply('Mensaje vacio.', $channel, $sessionId, $userId, 'error');
        }

        if ($text === '' && !empty($payload['meta'])) {
            return $this->reply('Archivo recibido. Procesaremos OCR/Audio cuando este habilitado.', $channel, $sessionId, $userId);
        }

        if ($this->isHelpIntent($text) && !$this->isCrudGuideTrigger($text)) {
            return $this->reply($this->buildHelpMessage($mode), $channel, $sessionId, $userId, 'success');
        }

        $local = $this->parseLocal($text);
        if (!empty($local['command']) && in_array($local['command'], ['RunTests', 'CreateEntity', 'CreateForm'], true)) {
            return $this->executeLocal($local, $channel, $sessionId, $userId, $mode, $tenantId);
        }

        $gateway = $this->gateway();
        $result = $gateway->handle($tenantId, $userId, $text, $mode);
        $action = $result['action'] ?? 'respond_local';
        $telemetry = $result['telemetry'] ?? [];

        if ($action === 'respond_local' || $action === 'ask_user') {
            $reply = $this->reply((string) ($result['reply'] ?? ''), $channel, $sessionId, $userId);
            $this->telemetry()->record($tenantId, array_merge($telemetry, [
                'message' => $text,
                'resolved_locally' => true,
                'mode' => $mode,
            ]));
            return $reply;
        }

        if ($action === 'execute_command' && !empty($result['command'])) {
            try {
                $reply = $this->executeCommandPayload((array) $result['command'], $channel, $sessionId, $userId, $mode);
            } catch (\Throwable $e) {
                $reply = $this->reply('No pude ejecutar ese paso. Revisa permisos o datos.', $channel, $sessionId, $userId, 'error', [
                    'error' => $e->getMessage(),
                ]);
            }
            $this->telemetry()->record($tenantId, array_merge($telemetry, [
                'message' => $text,
                'resolved_locally' => true,
                'mode' => $mode,
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
                'mode' => $mode,
            ]));

            $json = $llmResult['json'] ?? null;
            if (is_array($json)) {
                $reply = $this->executeLlmJson($json, $channel, $sessionId, $userId, $mode);
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

    private function executeLocal(array $parsed, string $channel, string $sessionId, string $userId, string $mode = 'app', string $tenantId = 'default'): array
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
            $acid = null;
            try {
                $acidRunner = new AcidChatRunner();
                $acid = $acidRunner->run($tenantId ?: 'default', ['save' => true]);
                $acidSummary = $acid['summary'] ?? [];
                $reply .= " Chat ácido: " . ($acidSummary['passed'] ?? 0) . " ok, " . ($acidSummary['failed'] ?? 0) . " fail.";
            } catch (\Throwable $e) {
                $reply .= " Chat ácido: error al ejecutar.";
            }
            return $this->reply($reply, $channel, $sessionId, $userId, 'success', [
                'unit' => $result,
                'acid' => $acid ?? null,
            ]);
        }

        if ($cmd === 'CreateEntity') {
            if ($mode === 'app') {
                return $this->reply('Estas en modo app. Usa el chat creador para crear tablas.', $channel, $sessionId, $userId, 'error');
            }
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
            if ($mode === 'app') {
                return $this->reply('Estas en modo app. Usa el chat creador para crear formularios.', $channel, $sessionId, $userId, 'error');
            }
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
            if ($mode === 'builder') {
                return $this->reply('Estas en modo creador. Usa el chat app para registrar datos.', $channel, $sessionId, $userId, 'error');
            }
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
                    try {
                        $tenantId = getenv('TENANT_KEY') ?: (getenv('TENANT_ID') ?: 'default');
                        $acidRunner = new AcidChatRunner();
                        $acid = $acidRunner->run((string) $tenantId, ['save' => true]);
                        $results[] = ['acid' => $acid];
                        $acidSummary = $acid['summary'] ?? [];
                        $line .= " Chat ácido: " . ($acidSummary['passed'] ?? 0) . " ok, " . ($acidSummary['failed'] ?? 0) . " fail.";
                    } catch (\Throwable $e) {
                        $line .= " Chat ácido: error al ejecutar.";
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

    public function buildHelpMessage(string $mode = 'app'): string
    {
        if ($mode === 'builder') {
            return $this->buildHelpMessageBuilder();
        }
        return $this->buildHelpMessageApp();
    }

    private function buildHelpMessageApp(): string
    {
        $help = $this->loadTrainingHelp();
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

        $stateKey = count($entityNames) === 0 ? 'empty' : 'ready';
        $lines = [];
        $lines[] = 'Hola, soy Cami. Estoy lista para ayudarte.';
        $lines = array_merge($lines, $help['app']['intro'] ?? []);
        $lines = array_merge($lines, $help['app']['steps'][$stateKey] ?? []);
        $lines[] = 'Ejemplos rapidos:';
        $examples = $this->buildCrudExamples($entityNames, $help['app']['examples'] ?? []);
        foreach ($examples as $ex) {
            $lines[] = '- ' . $ex;
        }
        $lines[] = 'Formularios activos: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 5)) : 'sin formularios');
        $lines[] = 'Entidades activas: ' . (count($entityNames) ? implode(', ', array_slice($entityNames, 0, 5)) : 'sin entidades');
        $question = $help['app']['next_questions'][$stateKey] ?? '';
        if ($question !== '') {
            $lines[] = $question;
        }
        $lines[] = 'Puedes enviar archivos (audio/imagen/PDF). Se procesaran cuando el OCR/voz este habilitado.';
        return implode("\n", $lines);
    }

    private function buildHelpMessageBuilder(): string
    {
        $help = $this->loadTrainingHelp();
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

        $stateKey = count($entityNames) === 0 ? 'empty' : (count($formNames) === 0 ? 'no_forms' : 'ready');
        $lines = [];
        $lines[] = 'Estas en el modo CREADOR.';
        $lines = array_merge($lines, $help['builder']['intro'] ?? []);
        $lines = array_merge($lines, $help['builder']['steps'][$stateKey] ?? []);
        $lines[] = 'Ejemplos rapidos:';
        $examples = $this->buildBuilderExamples($entityNames, $help['builder']['examples'] ?? []);
        foreach ($examples as $ex) {
            $lines[] = '- ' . $ex;
        }
        $lines[] = 'Formularios activos: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 5)) : 'sin formularios');
        $lines[] = 'Entidades activas: ' . (count($entityNames) ? implode(', ', array_slice($entityNames, 0, 5)) : 'sin entidades');
        $question = $help['builder']['next_questions'][$stateKey] ?? '';
        if ($question !== '') {
            $lines[] = $question;
        }
        return implode("\n", $lines);
    }

    private function buildCrudExamples(array $entityNames, array $fallback): array
    {
        if (empty($entityNames)) {
            return $fallback;
        }
        $entity = $this->slugEntity($entityNames[0]);
        return [
            'crear ' . $entity . ' nombre=Ana',
            'listar ' . $entity,
            'actualizar ' . $entity . ' id=1 campo=valor',
            'eliminar ' . $entity . ' id=1',
        ];
    }

    private function buildBuilderExamples(array $entityNames, array $fallback): array
    {
        if (empty($entityNames)) {
            return $fallback;
        }
        $entity = $this->slugEntity($entityNames[0]);
        return [
            'crear tabla ' . $entity . ' nombre:texto',
            'crear formulario ' . $entity,
            'probar sistema',
        ];
    }

    private function slugEntity(string $label): string
    {
        $label = mb_strtolower($label, 'UTF-8');
        $label = preg_replace('/[^a-z0-9áéíóúñü\\s_-]/u', '', $label) ?? $label;
        $label = preg_replace('/\\s+/', '_', trim($label)) ?? $label;
        return $label;
    }

    private function loadTrainingHelp(): array
    {
        $path = APP_ROOT . '/contracts/agents/conversation_training_base.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [];
        }
        return (array) ($json['help'] ?? []);
    }

    private function isHelpIntent(string $text): bool
    {
        $text = trim(mb_strtolower($text));
        if ($text === '') return true;
        $keywords = ['hola', 'buenas', 'buenos', 'ayuda', 'help', 'menu', 'funciones', 'que puedes', 'que haces', 'opciones', 'guia'];
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function isCrudGuideTrigger(string $text): bool
    {
        $text = trim(mb_strtolower($text));
        if ($text === '') {
            return false;
        }
        $patterns = [
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

    private function executeCommandPayload(array $command, string $channel, string $sessionId, string $userId, string $mode = 'app'): array
    {
        $cmd = (string) ($command['command'] ?? '');
        $entity = (string) ($command['entity'] ?? '');
        $id = $command['id'] ?? null;
        $data = (array) ($command['data'] ?? []);
        $filters = (array) ($command['filters'] ?? []);

        if ($cmd === '') {
            return $this->reply('Comando incompleto.', $channel, $sessionId, $userId, 'error');
        }

        if ($mode === 'builder' && !in_array($cmd, ['CreateEntity', 'CreateForm'], true)) {
            return $this->reply('Estas en modo creador. Usa el chat app para registrar datos.', $channel, $sessionId, $userId, 'error');
        }

        if (in_array($cmd, ['CreateRecord', 'QueryRecords', 'ReadRecord', 'UpdateRecord', 'DeleteRecord'], true)) {
            if ($entity === '' || !$this->entityExists($entity)) {
                if ($mode === 'builder') {
                    return $this->reply('No existe esa tabla. ¿Quieres crearla en el creador?', $channel, $sessionId, $userId, 'error');
                }
                return $this->reply('Esa tabla no existe en esta app. Debe ser agregada por el creador.', $channel, $sessionId, $userId, 'error');
            }
        }

        switch ($cmd) {
            case 'AuthLogin':
                $loginId = (string) ($command['user_id'] ?? $data['user_id'] ?? '');
                $password = (string) ($command['password'] ?? $data['password'] ?? '');
                if ($loginId === '' || $password === '') {
                    return $this->reply('Necesito usuario y clave para iniciar sesión.', $channel, $sessionId, $userId, 'error');
                }
                $registry = new ProjectRegistry();
                $manifest = $registry->resolveProjectFromManifest();
                $projectId = (string) ($command['project_id'] ?? $_SESSION['current_project_id'] ?? $manifest['id'] ?? 'default');
                $user = $registry->verifyAuthUser($projectId, $loginId, $password);
                if (!$user) {
                    return $this->reply('Usuario o clave incorrecta.', $channel, $sessionId, $userId, 'error');
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['auth_user'] = [
                        'id' => $user['id'],
                        'role' => $user['role'] ?? 'admin',
                        'tenant_id' => $user['tenant_id'] ?? 'default',
                        'project_id' => $projectId,
                        'label' => $user['label'] ?? $user['id'],
                    ];
                    $_SESSION['current_project_id'] = $projectId;
                }
                return $this->reply('Login listo. Ya puedes usar la app.', $channel, $sessionId, $userId, 'success', ['user' => $user]);
            case 'AuthCreateUser':
                $newId = (string) ($command['user_id'] ?? $data['user_id'] ?? '');
                $role = (string) ($command['role'] ?? $data['role'] ?? 'seller');
                $password = (string) ($command['password'] ?? $data['password'] ?? '');
                if ($newId === '' || $password === '') {
                    return $this->reply('Necesito usuario y clave para crear la cuenta.', $channel, $sessionId, $userId, 'error');
                }
                $registry = new ProjectRegistry();
                $manifest = $registry->resolveProjectFromManifest();
                $projectId = (string) ($command['project_id'] ?? $_SESSION['current_project_id'] ?? $manifest['id'] ?? 'default');
                $registry->createAuthUser($projectId, $newId, $password, $role, $command['tenant_id'] ?? 'default', $command['label'] ?? $newId);
                $registry->touchUser($newId, $role, 'auth', $command['tenant_id'] ?? 'default', $command['label'] ?? $newId);
                $registry->assignUserToProject($projectId, $newId, $role);
                return $this->reply('Usuario creado. ¿Quieres iniciar sesión ahora?', $channel, $sessionId, $userId, 'success');
            case 'CreateEntity':
                if ($mode === 'app') {
                    return $this->reply('Estas en modo app. Usa el chat creador para crear tablas.', $channel, $sessionId, $userId, 'error');
                }
                if ($entity === '') {
                    return $this->reply('Necesito el nombre de la tabla.', $channel, $sessionId, $userId, 'error');
                }
                $entityPayload = $this->builder->build($entity, $command['fields'] ?? []);
                $this->writer->writeEntity($entityPayload);
                $this->migrator()->migrateEntity($entityPayload, true);
                try {
                    $registry = new ProjectRegistry();
                    $manifest = $registry->resolveProjectFromManifest();
                    $registry->ensureProject($manifest['id'] ?? 'default', $manifest['name'] ?? 'Proyecto', $manifest['status'] ?? 'draft', $manifest['tenant_mode'] ?? 'shared', $userId);
                    $registry->registerEntity($manifest['id'] ?? 'default', $entityPayload['name'] ?? $entity, 'chat');
                } catch (\Throwable $e) {
                    // ignore registry errors
                }
                return $this->reply('Tabla creada: ' . $entityPayload['name'], $channel, $sessionId, $userId, 'success', ['entity' => $entityPayload]);
            case 'CreateForm':
                if ($mode === 'app') {
                    return $this->reply('Estas en modo app. Usa el chat creador para crear formularios.', $channel, $sessionId, $userId, 'error');
                }
                if ($entity === '') {
                    return $this->reply('Necesito la entidad para el formulario.', $channel, $sessionId, $userId, 'error');
                }
                $entityData = $this->entities->get($entity);
                $form = $this->wizard->buildFromEntity($entityData);
                $this->writer->writeForm($form);
                try {
                    $registry = new ProjectRegistry();
                    $manifest = $registry->resolveProjectFromManifest();
                    $registry->ensureProject($manifest['id'] ?? 'default', $manifest['name'] ?? 'Proyecto', $manifest['status'] ?? 'draft', $manifest['tenant_mode'] ?? 'shared', $userId);
                } catch (\Throwable $e) {
                    // ignore registry errors
                }
                return $this->reply('Formulario creado para ' . $entity, $channel, $sessionId, $userId, 'success', ['form' => $form]);
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

    private function executeLlmJson(array $json, string $channel, string $sessionId, string $userId, string $mode = 'app'): array
    {
        if (isset($json['command'])) {
            return $this->executeCommandPayload((array) $json['command'], $channel, $sessionId, $userId, $mode);
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
                    ], $channel, $sessionId, $userId, $mode);
                }
                if ($type === 'query_records') {
                    return $this->executeCommandPayload([
                        'command' => 'QueryRecords',
                        'entity' => $action['entity'] ?? '',
                        'filters' => $action['filters'] ?? [],
                    ], $channel, $sessionId, $userId, $mode);
                }
            }
        }
        if (isset($json['reply'])) {
            return $this->reply((string) $json['reply'], $channel, $sessionId, $userId);
        }
        return $this->reply('Listo.', $channel, $sessionId, $userId);
    }

    private function entityExists(string $entity): bool
    {
        if ($entity === '') {
            return false;
        }
        try {
            $this->entities->get($entity);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
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
