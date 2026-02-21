<?php
// app/Core/Agents/AcidChatRunner.php

namespace App\Core\Agents;

final class AcidChatRunner
{
    private string $projectRoot;
    private string $trainingPath;
    private string $confusionPath;

    public function __construct(?string $projectRoot = null, ?string $trainingPath = null, ?string $confusionPath = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 4) . '/project');
        $this->trainingPath = $trainingPath
            ?? (defined('APP_ROOT') ? APP_ROOT . '/contracts/agents/conversation_training_base.json'
                : dirname(__DIR__, 3) . '/contracts/agents/conversation_training_base.json');
        $this->confusionPath = $confusionPath
            ?? (defined('APP_ROOT') ? APP_ROOT . '/contracts/agents/conversation_confusion_base.json'
                : dirname(__DIR__, 3) . '/contracts/agents/conversation_confusion_base.json');
    }

    public function run(string $tenantId = 'default', array $options = []): array
    {
        $tests = $options['tests'] ?? $this->defaultTests();
        $gateway = new ConversationGateway($this->projectRoot);

        $trainingRaw = is_file($this->trainingPath) ? file_get_contents($this->trainingPath) : '';
        $trainingRaw = $trainingRaw !== '' ? ltrim($trainingRaw, "\xEF\xBB\xBF") : '';
        $trainingData = $trainingRaw !== '' ? json_decode($trainingRaw, true) : null;
        $trainingError = $trainingRaw !== '' ? json_last_error_msg() : 'missing';
        $trainingIntents = is_array($trainingData) ? count($trainingData['intents'] ?? []) : 0;

        $results = [];
        $passed = 0;
        $failed = 0;

        $runId = (string) time();
        $userMap = [];
        foreach ($tests as $idx => $test) {
            $msg = $test['msg'];
            $mode = (string) ($test['mode'] ?? 'app');
            $userKey = trim((string) ($test['user'] ?? ''));
            if ($userKey !== '') {
                if (!isset($userMap[$userKey])) {
                    $userMap[$userKey] = 'acid_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userKey) . '_' . $runId;
                }
                $userId = $userMap[$userKey];
            } else {
                $userId = 'acid_user_' . ($idx + 1) . '_' . $runId;
            }
            $error = null;
            try {
                $out = $gateway->handle($tenantId, $userId, $msg, $mode);
                if (($out['action'] ?? '') === 'execute_command' && !empty($out['command']) && is_array($out['command'])) {
                    $commandName = (string) ($out['command']['command'] ?? '');
                    $mockResult = [];
                    if ($commandName === 'CreateRecord') {
                        $mockResult = ['id' => 1];
                    } elseif ($commandName === 'QueryRecords') {
                        $mockResult = [['id' => 1]];
                    } elseif ($commandName === 'UpdateRecord') {
                        $mockResult = ['updated' => 1];
                    } elseif ($commandName === 'DeleteRecord') {
                        $mockResult = ['deleted' => 1];
                    }
                    $gateway->rememberExecution($tenantId, $userId, 'default', $mode, (array) $out['command'], $mockResult, $msg, 'OK');
                }
            } catch (\Throwable $e) {
                $out = ['action' => 'error', 'reply' => 'exception', 'command' => []];
                $error = $e->getMessage();
            }
            $action = $out['action'] ?? '';
            $reply = (string) ($out['reply'] ?? '');
            $command = $out['command']['command'] ?? '';

            $ok = in_array($action, $test['expect'], true);
            if ($ok && !empty($test['command'])) {
                $ok = ($command === $test['command']);
            }
            if ($ok && !empty($test['contains'])) {
                $ok = (stripos($reply, $test['contains']) !== false);
            }

            $results[] = [
                'id' => $idx + 1,
                'message' => $msg,
                'action' => $action,
                'reply' => $reply,
                'command' => $command,
                'ok' => $ok,
                'error' => $error,
            ];
            if ($ok) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $summary = [
            'passed' => $passed,
            'failed' => $failed,
            'total' => count($tests),
            'training_intents' => $trainingIntents,
            'training_error' => $trainingError,
            'ran_at' => date('Y-m-d H:i:s'),
        ];

        $confusion = $this->loadConfusionBase();
        $confusionResult = $this->runConfusionScenarios($gateway, $tenantId, $runId, $confusion);
        $summary['confusion_cases_total'] = $confusionResult['total'];
        $summary['confusion_cases_passed'] = $confusionResult['passed'];
        $summary['confusion_cases_failed'] = $confusionResult['failed'];
        $summary['confusion_error'] = $confusionResult['error'];

        $report = [
            'summary' => $summary,
            'results' => $results,
            'confusion_cases' => $confusionResult['cases'],
        ];

        if (!empty($options['save'])) {
            $path = $options['path'] ?? $this->defaultReportPath($tenantId);
            $this->writeReport($path, $report);
        }

        return $report;
    }

    private function defaultTests(): array
    {
        return [
            ['msg' => 'hola', 'expect' => ['respond_local']],
            ['msg' => 'quiero crear una app', 'mode' => 'builder', 'expect' => ['ask_user'], 'contains' => 'Paso 1'],
            ['msg' => 'sabes sobre presidente petro?', 'mode' => 'builder', 'expect' => ['respond_local'], 'contains' => 'Google, ChatGPT o Gemini'],
            ['msg' => 'explicame como creo un cliente', 'expect' => ['respond_local'], 'contains' => 'crear'],
            ['msg' => 'que tablas?', 'expect' => ['respond_local'], 'contains' => 'Tablas'],
            ['msg' => 'que formularios?', 'expect' => ['respond_local'], 'contains' => 'Formularios'],
            ['msg' => 'que opciones puedo usar ahora', 'expect' => ['respond_local'], 'contains' => 'Puedo ayudarte'],
            ['msg' => 'dame el estado del proyecto', 'expect' => ['respond_local'], 'contains' => 'En esta app puedes trabajar'],
            ['msg' => 'crear tabla clientes nombre:texto nit:texto', 'mode' => 'builder', 'expect' => ['execute_command'], 'command' => 'CreateEntity'],
            ['msg' => 'crear tabla clientes', 'mode' => 'builder', 'expect' => ['ask_user']],
            ['msg' => 'crear formulario clientes', 'mode' => 'builder', 'expect' => ['ask_user'], 'contains' => 'tabla base'],
            ['msg' => 'crear producto', 'mode' => 'app', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'quiero crear una app', 'mode' => 'app', 'expect' => ['respond_local'], 'contains' => 'Creador de apps'],
            ['msg' => 'crear producto', 'mode' => 'builder', 'expect' => ['ask_user'], 'contains' => 'contado, credito o mixto'],
            ['msg' => 'quiero crear un programa para mi veterinaria', 'mode' => 'builder', 'expect' => ['ask_user'], 'contains' => 'Paso 2', 'user' => 'builder_switch'],
            ['msg' => 'mixto', 'mode' => 'builder', 'expect' => ['ask_user'], 'contains' => 'Paso 3', 'user' => 'builder_switch'],
            ['msg' => 'si crea la tabla pacientes', 'mode' => 'builder', 'expect' => ['ask_user'], 'contains' => 'tabla pacientes', 'user' => 'builder_switch'],
            ['msg' => 'si', 'mode' => 'builder', 'expect' => ['execute_command', 'ask_user', 'respond_local'], 'user' => 'builder_switch'],
            ['msg' => 'crear cliente nombre=Juan nit=123', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'listar cliente', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'dame una lista de clientes', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'actualizar cliente id=1 email=juan@mail.com', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'eliminar cliente id=1', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'crear cliente', 'expect' => ['respond_local'], 'contains' => 'no existe', 'user' => 'stateful_client'],
            ['msg' => 'Ana', 'expect' => ['respond_local'], 'contains' => 'accion concreta', 'user' => 'stateful_client'],
            ['msg' => 'que guardaste', 'expect' => ['respond_local'], 'contains' => 'Aun no he guardado', 'user' => 'stateful_client'],
            ['msg' => 'mi negocio es ferreteria', 'expect' => ['respond_local']],
            ['msg' => 'guarda estas expresiones para despues', 'expect' => ['respond_local']],
            ['msg' => 'crear usuario usuario=ana rol=vendedor clave=1234', 'expect' => ['execute_command'], 'command' => 'AuthCreateUser'],
            ['msg' => 'iniciar sesion usuario=ana clave=1234', 'expect' => ['execute_command'], 'command' => 'AuthLogin'],
            ['msg' => 'que tablas hay', 'expect' => ['respond_local'], 'contains' => 'Creador de apps'],
            ['msg' => 'que pantallas tengo', 'expect' => ['respond_local'], 'contains' => 'Formularios'],
            ['msg' => 'que hay hecho', 'expect' => ['respond_local'], 'contains' => 'En esta app puedes trabajar'],
            ['msg' => 'ayuda rapida', 'expect' => ['respond_local']],
        ];
    }

    private function loadConfusionBase(): array
    {
        if (!is_file($this->confusionPath)) {
            return [];
        }
        $raw = file_get_contents($this->confusionPath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function runConfusionScenarios(ConversationGateway $gateway, string $tenantId, string $runId, array $confusion): array
    {
        $cases = is_array($confusion['acid_conversation_cases'] ?? null) ? $confusion['acid_conversation_cases'] : [];
        if (empty($cases)) {
            return ['total' => 0, 'passed' => 0, 'failed' => 0, 'error' => 'no_cases', 'cases' => []];
        }

        $out = [];
        $pass = 0;
        $fail = 0;
        foreach ($cases as $idx => $case) {
            if (!is_array($case)) {
                continue;
            }
            $name = (string) ($case['name'] ?? ('case_' . ($idx + 1)));
            $turns = is_array($case['turns'] ?? null) ? $case['turns'] : [];
            if (empty($turns)) {
                continue;
            }
            $mode = (string) ($case['mode'] ?? '');
            if (!in_array($mode, ['app', 'builder'], true)) {
                $mode = str_starts_with($name, 'app_') ? 'app' : 'builder';
            }
            $userId = 'acid_conf_' . $idx . '_' . $runId;

            $trace = [];
            $ok = true;
            foreach ($turns as $turn) {
                $res = $gateway->handle($tenantId, $userId, (string) $turn, $mode, 'default');
                $state = is_array($res['state'] ?? null) ? $res['state'] : [];
                $trace[] = [
                    'user' => (string) $turn,
                    'action' => (string) ($res['action'] ?? ''),
                    'reply' => (string) ($res['reply'] ?? ''),
                    'entity' => (string) ($state['entity'] ?? ''),
                    'pending_entity' => is_array($state['builder_pending_command'] ?? null)
                        ? (string) (($state['builder_pending_command']['entity'] ?? ''))
                        : '',
                ];
            }

            $rules = is_array($case['pass_if'] ?? null) ? $case['pass_if'] : [];
            foreach ($rules as $rule) {
                $rule = (string) $rule;
                if ($rule === 'never_entity_equals_no_or_cuales') {
                    foreach ($trace as $step) {
                        $e = strtolower((string) ($step['entity'] ?? ''));
                        $pe = strtolower((string) ($step['pending_entity'] ?? ''));
                        if (in_array($e, ['no', 'cual', 'cuales'], true) || in_array($pe, ['no', 'cual', 'cuales'], true)) {
                            $ok = false;
                            break;
                        }
                    }
                } elseif ($rule === 'reply_contains_pending_entity_pacientes') {
                    $hit = false;
                    foreach ($trace as $step) {
                        if (stripos((string) ($step['reply'] ?? ''), 'pacientes') !== false) {
                            $hit = true;
                            break;
                        }
                    }
                    $ok = $ok && $hit;
                } elseif ($rule === 'first_reply_informs_creator_required') {
                    $first = $trace[0]['reply'] ?? '';
                    $ok = $ok && stripos((string) $first, 'creador') !== false;
                } elseif ($rule === 'second_reply_no_fake_create') {
                    $second = $trace[1]['reply'] ?? '';
                    $ok = $ok && stripos((string) $second, 'Registro creado') === false;
                } elseif ($rule === 'first_reply_asks_one_slot') {
                    $first = strtolower((string) ($trace[0]['reply'] ?? ''));
                    $ok = $ok && (
                        str_contains($first, 'me falta')
                        || str_contains($first, 'dime')
                        || str_contains($first, 'debe ser agregada por el creador')
                    );
                } elseif ($rule === 'second_turn_executes_create_or_asks_next_slot') {
                    $action = (string) ($trace[1]['action'] ?? '');
                    if (in_array($action, ['execute_command', 'ask_user'], true)) {
                        $ok = $ok && true;
                    } else {
                        $secondReply = strtolower((string) ($trace[1]['reply'] ?? ''));
                        $ok = $ok && (
                            $action === 'respond_local'
                            && (str_contains($secondReply, 'accion concreta') || str_contains($secondReply, 'creador'))
                        );
                    }
                }
                if (!$ok) {
                    break;
                }
            }

            $out[] = ['name' => $name, 'ok' => $ok, 'trace' => $trace];
            if ($ok) {
                $pass++;
            } else {
                $fail++;
            }
        }

        return [
            'total' => count($out),
            'passed' => $pass,
            'failed' => $fail,
            'error' => 'none',
            'cases' => $out,
        ];
    }

    private function defaultReportPath(string $tenantId): string
    {
        $safeTenant = $tenantId !== '' ? $tenantId : 'default';
        $safeTenant = preg_replace('/[^a-zA-Z0-9_-]/', '_', $safeTenant) ?? $safeTenant;
        $base = $this->projectRoot . '/storage/reports';
        return $base . '/chat_acid_' . $safeTenant . '.json';
    }

    private function writeReport(string $path, array $report): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('No se pudo crear directorio: ' . $dir);
            }
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('No se pudo serializar reporte.');
        }
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('No se pudo escribir reporte: ' . $path);
        }
    }
}
