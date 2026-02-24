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
        $tests[] = $this->wrap('workflow_contract', fn() => $this->checkWorkflowContract());
        $tests[] = $this->wrap('entity_registry', fn() => (new EntityRegistry())->all());
        $tests[] = $this->wrap('form_contracts', fn() => (new ContractsCatalog())->forms());
        $tests[] = $this->wrap('db_connection', fn() => Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $tests[] = $this->wrap('llm_config', fn() => $this->checkLlmConfig());
        $tests[] = $this->wrap('chat_parser', fn() => $this->checkParser());
        $tests[] = $this->wrap('gateway_local', fn() => $this->checkGateway());
        $tests[] = $this->wrap('gateway_golden', fn() => $this->checkGatewayGolden());
        $tests[] = $this->wrap('mode_context_isolation', fn() => $this->checkModeContextIsolation());
        $tests[] = $this->wrap('business_reprofile_mid_flow', fn() => $this->checkBusinessReprofileMidFlow());
        $tests[] = $this->wrap('mode_guard_policy', fn() => $this->checkModeGuardPolicy());
        $tests[] = $this->wrap('builder_onboarding_flow', fn() => $this->checkBuilderOnboardingFlow());
        $tests[] = $this->wrap('builder_guidance', fn() => $this->checkBuilderGuidance());
        $tests[] = $this->wrap('flow_control', fn() => $this->checkFlowControl());
        $tests[] = $this->wrap('domain_training_sync', fn() => $this->checkDomainTrainingSync());
        $tests[] = $this->wrap('intent_router', fn() => $this->checkIntentRouter());
        $tests[] = $this->wrap('command_bus', fn() => $this->checkCommandBus());
        $tests[] = $this->wrap('observability_metrics', fn() => $this->checkObservabilityMetrics());
        $tests[] = $this->wrap('canonical_storage_new_project', fn() => $this->checkCanonicalStorageNewProject());

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

    private function checkWorkflowContract(): void
    {
        $valid = [
            'meta' => [
                'id' => 'wf_unit_v1',
                'name' => 'Unit workflow',
                'status' => 'draft',
                'revision' => 1,
            ],
            'nodes' => [
                [
                    'id' => 'n_input',
                    'type' => 'input',
                    'title' => 'Capture',
                    'runPolicy' => [
                        'timeout_ms' => 10000,
                        'retry_max' => 0,
                        'token_budget' => 0,
                    ],
                ],
            ],
            'edges' => [],
            'assets' => [],
            'theme' => ['presetName' => 'clean_business'],
            'versioning' => [
                'revision' => 1,
                'historyPointers' => [],
            ],
        ];

        WorkflowValidator::validateOrFail($valid);

        $invalid = $valid;
        $invalid['nodes'][0]['runPolicy']['timeout_ms'] = 0;
        try {
            WorkflowValidator::validateOrFail($invalid);
        } catch (\Throwable $e) {
            return;
        }

        throw new \RuntimeException('WorkflowValidator debe bloquear timeout_ms invalido.');
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

    private function checkModeContextIsolation(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $memory = new SqlMemoryRepository();
        $tenantId = 'default';
        $user = 'iso_' . time();
        $projectId = 'iso_proj';

        $gateway->handle($tenantId, $user, 'mi negocio es una ferreteria', 'builder', $projectId);
        $gateway->handle($tenantId, $user, 'crear cliente nombre=Ana', 'app', $projectId);

        $builderState = $memory->getUserMemory($tenantId, $user, 'state::' . $projectId . '::builder', []);
        $appState = $memory->getUserMemory($tenantId, $user, 'state::' . $projectId . '::app', []);

        if (empty($builderState)) {
            throw new \RuntimeException('No se encontro estado builder para mismo usuario.');
        }
        if (empty($appState)) {
            throw new \RuntimeException('No se encontro estado app para mismo usuario.');
        }
        if ((string) ($builderState['active_task'] ?? '') !== 'builder_onboarding') {
            throw new \RuntimeException('Estado builder no quedo en flujo de creador.');
        }
        if (($appState['active_task'] ?? null) !== null) {
            throw new \RuntimeException('Estado app no debe heredar task activa del builder.');
        }

        $builderProfile = $memory->getUserMemory($tenantId, $projectId . '__builder__' . $user, 'profile', []);
        $appProfile = $memory->getUserMemory($tenantId, $projectId . '__app__' . $user, 'profile', []);

        if ((string) ($builderProfile['business_type'] ?? '') === '') {
            throw new \RuntimeException('Perfil builder no guardo business_type.');
        }
        if (!empty($appProfile['business_type'] ?? '')) {
            throw new \RuntimeException('Perfil app no debe mezclar business_type de builder.');
        }
    }

    private function checkBusinessReprofileMidFlow(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $tenantId = 'default';
        $user = 'reprofile_' . time();
        $projectId = 'reprofile_proj';

        $gateway->handle($tenantId, $user, 'mi negocio es una ferreteria', 'builder', $projectId);
        $gateway->handle($tenantId, $user, 'mixto', 'builder', $projectId);
        $gateway->handle($tenantId, $user, 'inventario, facturacion y pagos', 'builder', $projectId);
        $gateway->handle($tenantId, $user, 'factura, cotizacion', 'builder', $projectId);

        $switch = $gateway->handle(
            $tenantId,
            $user,
            'hola necesito hacer una aplicacion para un almacen de corte laser',
            'builder',
            $projectId
        );
        $switchReply = mb_strtolower((string) ($switch['reply'] ?? ''), 'UTF-8');
        if (str_contains($switchReply, 'negocio: ferreteria')) {
            throw new \RuntimeException('No se permitio cambiar de negocio en flujo de confirmacion.');
        }
        $allowedSignals = ['corte laser', 'servicios de corte laser', 'paso 3', 'paso 2', 'productos, servicios o ambos', 'en este paso necesito'];
        $matched = false;
        foreach ($allowedSignals as $signal) {
            if (str_contains($switchReply, $signal)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new \RuntimeException('Cambio de negocio en confirmacion no produjo una salida valida.');
        }

        $reject = $gateway->handle($tenantId, $user, 'no soy ferrteria', 'builder', $projectId);
        $rejectReply = mb_strtolower((string) ($reject['reply'] ?? ''), 'UTF-8');
        if (str_contains($rejectReply, 'negocio: ferreteria')) {
            throw new \RuntimeException('La correccion "no soy <negocio>" no limpio el resumen previo.');
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

    private function checkBuilderGuidance(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $user = 'guidance_' . time();
        $projectId = 'guidance_proj';

        $money = $gateway->handle('default', $user, 'un campo para el precio', 'builder', $projectId);
        if ((string) ($money['action'] ?? '') !== 'ask_user') {
            throw new \RuntimeException('Builder guidance debe responder en modo ask_user.');
        }
        if (stripos((string) ($money['reply'] ?? ''), 'decimal') === false) {
            throw new \RuntimeException('Builder guidance de precio debe recomendar decimal.');
        }

        $relation = $gateway->handle('default', $user, 'conectar clientes con ventas', 'builder', $projectId);
        $relationReply = (string) ($relation['reply'] ?? '');
        if (stripos($relationReply, 'clientes') === false || stripos($relationReply, 'ventas') === false) {
            throw new \RuntimeException('Builder guidance de relaciones debe interpolar tablas.');
        }
        $relationPending = is_array($relation['state']['builder_pending_command'] ?? null)
            ? (array) $relation['state']['builder_pending_command']
            : [];
        if ((string) ($relationPending['command'] ?? '') !== 'CreateRelation') {
            throw new \RuntimeException('Builder guidance de relaciones debe crear pending command transaccional.');
        }

        $relationConfirm = $gateway->handle('default', $user, 'si', 'builder', $projectId);
        if ((string) ($relationConfirm['action'] ?? '') !== 'execute_command') {
            throw new \RuntimeException('Confirmacion de guidance de relaciones debe ejecutar comando.');
        }
        if ((string) ($relationConfirm['command']['command'] ?? '') !== 'CreateRelation') {
            throw new \RuntimeException('Confirmacion de guidance de relaciones debe ejecutar CreateRelation.');
        }

        $performance = $gateway->handle('default', $user, 'la busqueda es muy lenta en tabla clientes por nombre', 'builder', $projectId);
        $performancePending = is_array($performance['state']['builder_pending_command'] ?? null)
            ? (array) $performance['state']['builder_pending_command']
            : [];
        if ((string) ($performancePending['command'] ?? '') !== 'CreateIndex') {
            throw new \RuntimeException('Builder guidance de performance debe crear pending command transaccional.');
        }

        $performanceConfirm = $gateway->handle('default', $user, 'si', 'builder', $projectId);
        if ((string) ($performanceConfirm['action'] ?? '') !== 'execute_command') {
            throw new \RuntimeException('Confirmacion de guidance de performance debe ejecutar comando.');
        }
        if ((string) ($performanceConfirm['command']['command'] ?? '') !== 'CreateIndex') {
            throw new \RuntimeException('Confirmacion de guidance de performance debe ejecutar CreateIndex.');
        }
    }

    private function checkFlowControl(): void
    {
        $gateway = new \App\Core\Agents\ConversationGateway();
        $user = 'flow_' . time();
        $projectId = 'flow_proj';

        $start = $gateway->handle('default', $user, 'quiero crear una app', 'builder', $projectId);
        $startReply = (string) ($start['reply'] ?? '');
        if (stripos($startReply, 'Paso 1') === false) {
            throw new \RuntimeException('Flow control test requiere onboarding inicial.');
        }

        $cancel = $gateway->handle('default', $user, 'cancelar', 'builder', $projectId);
        if ((string) ($cancel['action'] ?? '') !== 'respond_local') {
            throw new \RuntimeException('Flow cancel debe responder localmente.');
        }
        if (($cancel['state']['active_task'] ?? null) !== null) {
            throw new \RuntimeException('Flow cancel debe pausar tarea activa.');
        }

        $resume = $gateway->handle('default', $user, 'retomar', 'builder', $projectId);
        if ((string) ($resume['action'] ?? '') !== 'ask_user') {
            throw new \RuntimeException('Flow resume debe retomar como pregunta guiada.');
        }
        if (stripos((string) ($resume['reply'] ?? ''), 'Retomamos') === false) {
            throw new \RuntimeException('Flow resume debe informar retoma de paso.');
        }

        $restart = $gateway->handle('default', $user, 'reiniciar', 'builder', $projectId);
        if ((string) ($restart['action'] ?? '') !== 'ask_user') {
            throw new \RuntimeException('Flow restart debe guiar desde paso 1.');
        }
        if ((string) ($restart['state']['onboarding_step'] ?? '') !== 'business_type') {
            throw new \RuntimeException('Flow restart debe volver a business_type.');
        }
    }

    private function checkDomainTrainingSync(): void
    {
        $php = PHP_BINARY ?: 'php';
        $script = dirname(__DIR__, 2) . '/scripts/sync_domain_training.php';
        if (!is_file($script)) {
            throw new \RuntimeException('Script de sync no encontrado.');
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --check';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException('Drift entre domain_playbooks y conversation_training_base.');
        }
    }

    private function checkIntentRouter(): void
    {
        $router = new IntentRouter();
        $local = $router->route(['action' => 'respond_local', 'reply' => 'ok']);
        if (!$local->isLocalResponse() || $local->reply() !== 'ok') {
            throw new \RuntimeException('IntentRouter no enruta respond_local.');
        }

        $cmd = $router->route(['action' => 'execute_command', 'command' => ['command' => 'CreateEntity']]);
        if (!$cmd->isCommand() || (string) (($cmd->command()['command'] ?? '')) !== 'CreateEntity') {
            throw new \RuntimeException('IntentRouter no enruta execute_command.');
        }

        $llm = $router->route(['action' => 'send_to_llm', 'llm_request' => ['messages' => []]]);
        if (!$llm->isLlmRequest()) {
            throw new \RuntimeException('IntentRouter no enruta send_to_llm.');
        }
    }

    private function checkCommandBus(): void
    {
        $bus = new CommandBus();
        $bus->register(new CreateEntityCommandHandler());
        $bus->register(new CreateFormCommandHandler());
        $bus->register(new CreateRelationCommandHandler());
        $bus->register(new CreateIndexCommandHandler());
        $bus->register(new InstallPlaybookCommandHandler());
        $bus->register(new CrudCommandHandler());
        $bus->register(new MapCommandHandler(['AuthLogin'], static fn(array $command, array $context): array => [
            'status' => 'success',
            'reply' => 'auth handler ok',
        ]));

        $reply = static fn(
            string $text,
            string $channel,
            string $sessionId,
            string $userId,
            string $status = 'success',
            array $data = []
        ): array => [
            'status' => $status,
            'reply' => $text,
            'data' => $data,
            'channel' => $channel,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ];

        $baseContext = [
            'channel' => 'test',
            'session_id' => 'sess',
            'user_id' => 'user',
            'reply' => $reply,
        ];

        $resEntity = $bus->dispatch(
            ['command' => 'CreateEntity', 'entity' => 'clientes'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resEntity['status'] ?? '') !== 'error') {
            throw new \RuntimeException('CreateEntity handler no respondio guard de modo app.');
        }

        $resForm = $bus->dispatch(
            ['command' => 'CreateForm', 'entity' => 'clientes'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resForm['status'] ?? '') !== 'error') {
            throw new \RuntimeException('CreateForm handler no respondio guard de modo app.');
        }

        $resRelation = $bus->dispatch(
            ['command' => 'CreateRelation', 'source_entity' => 'clientes', 'target_entity' => 'ventas'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resRelation['status'] ?? '') !== 'error') {
            throw new \RuntimeException('CreateRelation handler no respondio guard de modo app.');
        }

        $resIndex = $bus->dispatch(
            ['command' => 'CreateIndex', 'entity' => 'clientes', 'field' => 'nombre'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resIndex['status'] ?? '') !== 'error') {
            throw new \RuntimeException('CreateIndex handler no respondio guard de modo app.');
        }

        $resPlaybook = $bus->dispatch(
            ['command' => 'InstallPlaybook'],
            array_merge($baseContext, ['mode' => 'app'])
        );
        if ((string) ($resPlaybook['status'] ?? '') !== 'error') {
            throw new \RuntimeException('InstallPlaybook handler no respondio guard de modo app.');
        }

        $resCrud = $bus->dispatch(
            ['command' => 'CreateRecord', 'entity' => 'clientes', 'data' => []],
            array_merge($baseContext, ['mode' => 'builder'])
        );
        if ((string) ($resCrud['status'] ?? '') !== 'error') {
            throw new \RuntimeException('Crud handler no respondio guard de modo builder.');
        }

        $resCrudMissingEntity = $bus->dispatch(
            ['command' => 'CreateRecord', 'entity' => 'clientes', 'data' => []],
            array_merge($baseContext, [
                'mode' => 'app',
                'entity_exists' => static fn(string $entity): bool => false,
            ])
        );
        if ((string) ($resCrudMissingEntity['status'] ?? '') !== 'error') {
            throw new \RuntimeException('Crud handler no valida existencia de entidad.');
        }

        $resAuth = $bus->dispatch(
            ['command' => 'AuthLogin'],
            $baseContext
        );
        if ((string) ($resAuth['status'] ?? '') !== 'success') {
            throw new \RuntimeException('MapCommandHandler no mantiene compatibilidad para AuthLogin.');
        }

        try {
            $bus->dispatch(['command' => 'UnknownCommand'], []);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'COMMAND_NOT_SUPPORTED') {
                return;
            }
            throw new \RuntimeException('CommandBus lanzo error inesperado.');
        }

        throw new \RuntimeException('CommandBus debe fallar comando desconocido.');
    }

    private function checkObservabilityMetrics(): void
    {
        $tmpDir = dirname(__DIR__, 2) . '/tests/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $dbPath = $tmpDir . '/unit_observability.sqlite';
        if (is_file($dbPath)) {
            unlink($dbPath);
        }
        $repo = new SqlMetricsRepository(null, $dbPath);
        $service = new TelemetryService($repo);

        $service->recordIntentMetric([
            'tenant_id' => 'default',
            'project_id' => 'unit_obs',
            'mode' => 'builder',
            'intent' => 'APP_CREATE',
            'action' => 'ask_user',
            'latency_ms' => 70,
            'status' => 'success',
        ]);
        $service->recordCommandMetric([
            'tenant_id' => 'default',
            'project_id' => 'unit_obs',
            'mode' => 'app',
            'command_name' => 'CreateEntity',
            'latency_ms' => 25,
            'status' => 'error',
            'blocked' => 1,
        ]);
        $service->recordGuardrailEvent([
            'tenant_id' => 'default',
            'project_id' => 'unit_obs',
            'mode' => 'app',
            'guardrail' => 'mode_guard',
            'reason' => 'blocked by mode',
        ]);
        $service->recordTokenUsage([
            'tenant_id' => 'default',
            'project_id' => 'unit_obs',
            'provider' => 'gemini',
            'prompt_tokens' => 90,
            'completion_tokens' => 30,
            'total_tokens' => 120,
        ]);

        $summary = $service->summary('default', 'unit_obs', 7);
        if ((int) ($summary['intent_metrics']['count'] ?? 0) < 1) {
            throw new \RuntimeException('Observability: intent metric not persisted.');
        }
        if ((int) ($summary['command_metrics']['blocked'] ?? 0) < 1) {
            throw new \RuntimeException('Observability: blocked command metric missing.');
        }
        if ((int) ($summary['guardrail_events']['count'] ?? 0) < 1) {
            throw new \RuntimeException('Observability: guardrail event missing.');
        }
        if ((int) ($summary['token_usage']['total_tokens'] ?? 0) < 120) {
            throw new \RuntimeException('Observability: token usage not persisted.');
        }
    }

    private function checkCanonicalStorageNewProject(): void
    {
        $tmpDir = dirname(__DIR__, 2) . '/tests/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $registryPath = $tmpDir . '/unit_canonical_registry.sqlite';
        if (is_file($registryPath)) {
            unlink($registryPath);
        }

        putenv('PROJECT_REGISTRY_DB_PATH=' . $registryPath);
        putenv('DB_CANONICAL_NEW_PROJECTS=1');
        putenv('DB_NAMESPACE_BY_PROJECT=1');

        StorageModel::clearCache();
        TableNamespace::clearCache();

        $registry = new ProjectRegistry($registryPath);
        $registry->ensureProject('unit_can_app', 'Unit Canonical');
        $project = $registry->getProject('unit_can_app') ?? [];
        if ((string) ($project['storage_model'] ?? '') !== 'canonical') {
            throw new \RuntimeException('Canonical storage model was not persisted for new project.');
        }

        StorageModel::clearCache();
        TableNamespace::clearCache();
        $table = TableNamespace::resolve('clientes', 'unit_can_app');
        if ($table !== 'clientes') {
            throw new \RuntimeException('Canonical project should resolve logical table without namespace.');
        }
    }
}
