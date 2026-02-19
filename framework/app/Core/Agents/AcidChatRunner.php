<?php
// app/Core/Agents/AcidChatRunner.php

namespace App\Core\Agents;

final class AcidChatRunner
{
    private string $projectRoot;
    private string $trainingPath;

    public function __construct(?string $projectRoot = null, ?string $trainingPath = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 4) . '/project');
        $this->trainingPath = $trainingPath
            ?? (defined('APP_ROOT') ? APP_ROOT . '/contracts/agents/conversation_training_base.json'
                : dirname(__DIR__, 3) . '/contracts/agents/conversation_training_base.json');
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
        foreach ($tests as $idx => $test) {
            $msg = $test['msg'];
            $mode = (string) ($test['mode'] ?? 'app');
            $userId = 'acid_user_' . ($idx + 1) . '_' . $runId;
            $error = null;
            try {
                $out = $gateway->handle($tenantId, $userId, $msg, $mode);
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

        $report = [
            'summary' => $summary,
            'results' => $results,
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
            ['msg' => 'crear formulario clientes', 'mode' => 'builder', 'expect' => ['ask_user'], 'contains' => 'primero necesito la tabla'],
            ['msg' => 'crear producto', 'mode' => 'app', 'expect' => ['respond_local'], 'contains' => 'creador'],
            ['msg' => 'quiero crear una app', 'mode' => 'app', 'expect' => ['respond_local'], 'contains' => 'Creador de apps'],
            ['msg' => 'crear producto', 'mode' => 'builder', 'expect' => ['ask_user'], 'contains' => 'contado, credito o mixto'],
            ['msg' => 'crear cliente nombre=Juan nit=123', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'listar cliente', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'actualizar cliente id=1 email=juan@mail.com', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'eliminar cliente id=1', 'expect' => ['respond_local'], 'contains' => 'no existe'],
            ['msg' => 'mi negocio es ferreteria', 'expect' => ['respond_local']],
            ['msg' => 'guarda estas expresiones para despues', 'expect' => ['respond_local']],
            ['msg' => 'crear usuario usuario=ana rol=vendedor clave=1234', 'expect' => ['execute_command'], 'command' => 'AuthCreateUser'],
            ['msg' => 'iniciar sesion usuario=ana clave=1234', 'expect' => ['execute_command'], 'command' => 'AuthLogin'],
            ['msg' => 'que tablas hay', 'expect' => ['respond_local'], 'contains' => 'Tablas'],
            ['msg' => 'que pantallas tengo', 'expect' => ['respond_local'], 'contains' => 'Formularios'],
            ['msg' => 'que hay hecho', 'expect' => ['respond_local'], 'contains' => 'En esta app puedes trabajar'],
            ['msg' => 'ayuda rapida', 'expect' => ['respond_local']],
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
