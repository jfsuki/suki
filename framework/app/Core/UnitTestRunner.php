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
        $tests[] = $this->wrap('gateway_golden', fn() => $this->checkGatewayGolden());
        $tests[] = $this->wrap('mode_guard_policy', fn() => $this->checkModeGuardPolicy());
        $tests[] = $this->wrap('builder_onboarding_flow', fn() => $this->checkBuilderOnboardingFlow());

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

    private function checkGatewayGolden(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $user = 'golden_' . time();
        $projectId = 'golden_proj';

        $appBuild = $gateway->handle('default', $user, 'quiero crear una tabla clientes', 'app', $projectId);
        if (($appBuild['action'] ?? '') !== 'respond_local' || stripos((string) ($appBuild['reply'] ?? ''), 'Creador de apps') === false) {
            throw new \RuntimeException('Guard app/build no bloquea correctamente.');
        }

        $builderCrud = $gateway->handle('default', $user, 'crear cliente nombre=Ana', 'builder', $projectId);
        if (($builderCrud['action'] ?? '') !== 'respond_local' || stripos((string) ($builderCrud['reply'] ?? ''), 'chat de la app') === false) {
            throw new \RuntimeException('Guard builder/use no bloquea correctamente.');
        }

        $memory = new SqlMemoryRepository();
        $state = $memory->getUserMemory('default', $user, 'state::' . $projectId . '::builder', []);
        if (empty($state)) {
            throw new \RuntimeException('No se creo estado SQL por project+mode+user.');
        }
        $working = $memory->getUserMemory('default', $user, 'working_memory::' . $projectId . '::builder', []);
        if (empty($working)) {
            throw new \RuntimeException('No se guardo working memory SQL para builder.');
        }
    }

    private function checkModeGuardPolicy(): void
    {
        $policy = new ModeGuardPolicy();
        $buildGuard = $policy->evaluate('app', true, false, false);
        if ((string) ($buildGuard['telemetry'] ?? '') !== 'build_guard') {
            throw new \RuntimeException('ModeGuardPolicy no bloquea build en APP.');
        }

        $useGuard = $policy->evaluate('builder', false, true, false);
        if ((string) ($useGuard['telemetry'] ?? '') !== 'use_guard') {
            throw new \RuntimeException('ModeGuardPolicy no bloquea CRUD runtime en BUILDER.');
        }

        $playbookBypass = $policy->evaluate('builder', false, true, true);
        if ($playbookBypass !== null) {
            throw new \RuntimeException('ModeGuardPolicy debe permitir bypass para solicitud playbook.');
        }
    }

    private function checkBuilderOnboardingFlow(): void
    {
        $flow = new BuilderOnboardingFlow();
        $ops = [
            'parseInstallPlaybookRequest' => fn(string $text): array => ['matched' => false],
            'classifyWithPlaybookIntents' => fn(string $text, array $profile): array => [],
            'isBuilderOnboardingTrigger' => fn(string $text): bool => str_contains($text, 'crear una app'),
            'detectBusinessType' => fn(string $text): string => str_contains($text, 'ferreteria') ? 'ferreteria' : '',
            'isFormListQuestion' => fn(string $text): bool => str_contains($text, 'formularios'),
            'buildFormList' => fn(): string => 'Aun no hay formularios. Quieres crear uno?',
            'isEntityListQuestion' => fn(string $text): bool => str_contains($text, 'tablas'),
            'buildEntityList' => fn(): string => 'Tablas creadas: clientes.',
            'isBuilderProgressQuestion' => fn(string $text): bool => str_contains($text, 'estado del proyecto'),
            'buildProjectStatus' => fn(): string => 'Estado del proyecto: listo.',
        ];

        $delegated = 0;
        $core = function (
            string $text,
            array $state,
            array $profile,
            string $tenantId,
            string $userId,
            bool $isOnboarding,
            bool $trigger,
            bool $businessHint
        ) use (&$delegated): array {
            $delegated++;
            return ['action' => 'ask_user', 'reply' => 'delegated', 'state' => $state];
        };

        $list = $flow->handle('que formularios?', ['active_task' => 'builder_onboarding'], [], 'default', 'unit', $ops, $core);
        if ((string) ($list['action'] ?? '') !== 'respond_local') {
            throw new \RuntimeException('BuilderOnboardingFlow debe responder catalogo de formularios.');
        }

        $playbookOps = $ops;
        $playbookOps['classifyWithPlaybookIntents'] = fn(string $text, array $profile): array => [
            'action' => 'APPLY_PLAYBOOK_FERRETERIA',
            'confidence' => 0.95,
        ];
        $playbook = $flow->handle(
            'tengo una ferreteria y pierdo plata',
            ['active_task' => 'builder_onboarding'],
            [],
            'default',
            'unit',
            $playbookOps,
            $core
        );
        if ($playbook !== null) {
            throw new \RuntimeException('BuilderOnboardingFlow debe ceder paso a intents playbook confiables.');
        }

        $delegatedResult = $flow->handle('quiero crear una app', ['active_task' => 'builder_onboarding'], [], 'default', 'unit', $ops, $core);
        if ((string) ($delegatedResult['reply'] ?? '') !== 'delegated' || $delegated < 1) {
            throw new \RuntimeException('BuilderOnboardingFlow no delega al core handler.');
        }
    }
}
