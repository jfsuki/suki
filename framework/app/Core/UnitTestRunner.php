<?php
// app/Core/UnitTestRunner.php

namespace App\Core;

use Throwable;

final class UnitTestRunner
{
    public function run(): array
    {
        $tests = [];
        $tests[] = $this->wrap('manifest', fn() => ManifestValidator::validateOrFail());
        $tests[] = $this->wrap('entity_registry', fn() => (new EntityRegistry())->all());
        $tests[] = $this->wrap('form_contracts', fn() => (new ContractsCatalog())->forms());
        $tests[] = $this->wrap('db_connection', fn() => Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $tests[] = $this->wrap('llm_config', fn() => $this->checkLlmConfig());
        $tests[] = $this->wrap('chat_parser', fn() => $this->checkParser());
        $tests[] = $this->wrap('gateway_local', fn() => $this->checkGateway());

        $summary = [
            'passed' => count(array_filter($tests, fn($t) => $t['status'] === 'pass')),
            'failed' => count(array_filter($tests, fn($t) => $t['status'] === 'fail')),
            'warned' => count(array_filter($tests, fn($t) => $t['status'] === 'warn')),
        ];

        return [
            'summary' => $summary,
            'tests' => $tests,
        ];
    }

    private function wrap(string $name, callable $fn): array
    {
        try {
            $fn();
            return ['name' => $name, 'status' => 'pass'];
        } catch (Throwable $e) {
            $status = $this->isWarning($name) ? 'warn' : 'fail';
            return ['name' => $name, 'status' => $status, 'error' => $e->getMessage()];
        }
    }

    private function isWarning(string $name): bool
    {
        return in_array($name, ['db_connection', 'llm_config'], true);
    }

    private function checkLlmConfig(): void
    {
        $groq = getenv('GROQ_API_KEY') ?: '';
        $gemini = getenv('GEMINI_API_KEY') ?: '';
        if ($groq === '' && $gemini === '') {
            throw new \RuntimeException('No hay API keys LLM configuradas.');
        }
    }

    private function checkParser(): void
    {
        $agent = new ChatAgent();
        $result = $agent->parseLocal('crear cliente nombre=Ana');
        if (($result['command'] ?? '') !== 'CreateRecord') {
            throw new \RuntimeException('Parser local no reconoce CreateRecord.');
        }
    }

    private function checkGateway(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $result = $gateway->handle('default', 'test', 'hola');
        if (($result['action'] ?? '') !== 'respond_local') {
            throw new \RuntimeException('Gateway no responde saludo local.');
        }
    }
}
