<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;
use App\Core\SqlMemoryRepository;

$failures = [];
$results = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/builder_llm_assisted_onboarding_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);
$dbPath = $tmpDir . '/builder_llm_assisted_onboarding.sqlite';
$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'BUILDER_LLM_ASSIST_ENABLED' => getenv('BUILDER_LLM_ASSIST_ENABLED'),
    'SUKI_BUILDER_ONBOARDING_LLM_STUB_JSON' => getenv('SUKI_BUILDER_ONBOARDING_LLM_STUB_JSON'),
    'OPENROUTER_ENABLED' => getenv('OPENROUTER_ENABLED'),
    'DEEPSEEK_ENABLED' => getenv('DEEPSEEK_ENABLED'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('BUILDER_LLM_ASSIST_ENABLED=1');
putenv('SUKI_BUILDER_ONBOARDING_LLM_STUB_JSON=');

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $memory = new SqlMemoryRepository($pdo);
    $gateway = new ConversationGateway(PROJECT_ROOT, $memory);
    $tenantId = 'tenant_builder_llm_assist';
    $projectId = 'default';

    $buildPlan = new ReflectionMethod(ConversationGateway::class, 'buildBusinessPlan');
    $buildPlan->setAccessible(true);
    $buildSummary = new ReflectionMethod(ConversationGateway::class, 'buildRequirementsSummaryReply');
    $buildSummary->setAccessible(true);

    $profileKey = static fn(string $userId): string => $projectId . '__builder__' . $userId;
    $routePath = static function (array $result): array {
        $telemetry = is_array($result['telemetry'] ?? null) ? (array) $result['telemetry'] : [];
        return is_array($telemetry['routing_hint_steps'] ?? null)
            ? array_values((array) $telemetry['routing_hint_steps'])
            : [];
    };
    $classification = static fn(array $result): string => (string) ((is_array($result['telemetry'] ?? null) ? $result['telemetry'] : [])['classification'] ?? '');
    $setStub = static function (array $payload): void {
        putenv('SUKI_BUILDER_ONBOARDING_LLM_STUB_JSON=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    };
    $clearStub = static function (): void {
        putenv('SUKI_BUILDER_ONBOARDING_LLM_STUB_JSON=');
    };

    // typo survival
    $userId = 'builder_assist_typo';
    $memory->saveUserMemory($tenantId, $profileKey($userId), 'profile', ['business_type' => 'ferreteria']);
    $memory->saveUserMemory($tenantId, $userId, 'state::default::builder', [
        'active_task' => 'builder_onboarding',
        'onboarding_step' => 'operation_model',
    ]);
    $setStub([
        'operation_model' => [
            'resolved' => true,
            'mapped_value' => 'mixto',
            'help_reply' => 'Entendi el modo de pago. Sigo con el control.',
            'confidence' => 0.94,
            'intent_type' => 'map_value',
        ],
    ]);
    $result = $gateway->handle($tenantId, $userId, 'vendo al contao y fiao', 'builder', $projectId);
    $state = $memory->getUserMemory($tenantId, $userId, 'state::default::builder', []);
    $profile = $memory->getUserMemory($tenantId, $profileKey($userId), 'profile', []);
    $results[] = [
        'case' => 'typo_survival',
        'action' => (string) ($result['action'] ?? ''),
        'classification' => $classification($result),
        'route_path' => $routePath($result),
        'reply' => (string) ($result['reply'] ?? ''),
        'active_task' => (string) ($state['active_task'] ?? ''),
        'onboarding_step' => (string) ($state['onboarding_step'] ?? ''),
        'operation_model' => (string) ($profile['operation_model'] ?? ''),
    ];
    if (($result['action'] ?? '') !== 'ask_user') {
        $failures[] = 'typo_survival debe seguir en ask_user.';
    }
    if ($classification($result) !== 'builder_onboarding') {
        $failures[] = 'typo_survival debe quedar en builder_onboarding.';
    }
    if ($routePath($result) !== ['cache', 'rules']) {
        $failures[] = 'typo_survival debe mantenerse en cache>rules.';
    }
    if ((string) ($profile['operation_model'] ?? '') !== 'mixto') {
        $failures[] = 'typo_survival debe mapear operation_model=mixto.';
    }
    if ((string) ($state['active_task'] ?? '') !== 'builder_onboarding' || (string) ($state['onboarding_step'] ?? '') !== 'needs_scope') {
        $failures[] = 'typo_survival debe avanzar a needs_scope sin perder contexto.';
    }
    if (!str_contains((string) ($result['reply'] ?? ''), 'Paso 3')) {
        $failures[] = 'typo_survival debe pedir el siguiente paso del onboarding.';
    }
    $clearStub();

    // frustration survival
    $userId = 'builder_assist_frustration';
    $memory->saveUserMemory($tenantId, $profileKey($userId), 'profile', [
        'business_type' => 'ferreteria',
        'operation_model' => 'contado',
    ]);
    $memory->saveUserMemory($tenantId, $userId, 'state::default::builder', [
        'active_task' => 'builder_onboarding',
        'onboarding_step' => 'needs_scope',
    ]);
    $setStub([
        'needs_scope' => [
            'resolved' => false,
            'mapped_value' => null,
            'help_reply' => 'Voy simple. Dime si quieres controlar inventario, facturacion o pagos.',
            'confidence' => 0.82,
            'intent_type' => 'frustration_help',
        ],
    ]);
    $result = $gateway->handle($tenantId, $userId, 'no entendi todo eso q escribes', 'builder', $projectId);
    $state = $memory->getUserMemory($tenantId, $userId, 'state::default::builder', []);
    $profile = $memory->getUserMemory($tenantId, $profileKey($userId), 'profile', []);
    $results[] = [
        'case' => 'frustration_survival',
        'action' => (string) ($result['action'] ?? ''),
        'classification' => $classification($result),
        'route_path' => $routePath($result),
        'reply' => (string) ($result['reply'] ?? ''),
        'active_task' => (string) ($state['active_task'] ?? ''),
        'onboarding_step' => (string) ($state['onboarding_step'] ?? ''),
        'needs_scope' => (string) ($profile['needs_scope'] ?? ''),
    ];
    if (($result['action'] ?? '') !== 'ask_user' || $classification($result) !== 'builder_onboarding') {
        $failures[] = 'frustration_survival debe mantenerse local en onboarding.';
    }
    if (!str_contains((string) ($result['reply'] ?? ''), 'inventario, facturacion o pagos')) {
        $failures[] = 'frustration_survival debe usar help_reply del assist.';
    }
    if ((string) ($state['onboarding_step'] ?? '') !== 'needs_scope') {
        $failures[] = 'frustration_survival no debe perder needs_scope.';
    }
    if (trim((string) ($profile['needs_scope'] ?? '')) !== '') {
        $failures[] = 'frustration_survival no debe guardar basura en needs_scope.';
    }
    $clearStub();

    // non-binary confirmation survival
    $userId = 'builder_assist_confirm';
    $confirmProfile = [
        'business_type' => 'ferreteria',
        'operation_model' => 'contado',
        'needs_scope' => 'inventario',
        'needs_scope_items' => ['inventario'],
        'documents_scope' => 'factura',
        'documents_scope_items' => ['factura'],
    ];
    $plan = $buildPlan->invoke($gateway, 'ferreteria', $confirmProfile);
    $summary = (string) $buildSummary->invoke($gateway, 'ferreteria', $confirmProfile, $plan);
    $memory->saveUserMemory($tenantId, $profileKey($userId), 'profile', $confirmProfile);
    $memory->saveUserMemory($tenantId, $userId, 'state::default::builder', [
        'active_task' => 'builder_onboarding',
        'onboarding_step' => 'confirm_scope',
        'confirm_scope_last_hash' => sha1($summary),
        'confirm_scope_repeats' => 2,
    ]);
    $setStub([
        'confirm_scope' => [
            'resolved' => true,
            'mapped_value' => 'documentos',
            'help_reply' => 'Listo. Ajustemos documentos. Dime cuales vas a usar.',
            'confidence' => 0.9,
            'intent_type' => 'map_value',
        ],
    ]);
    $result = $gateway->handle($tenantId, $userId, 'quiero revisar eso', 'builder', $projectId);
    $state = $memory->getUserMemory($tenantId, $userId, 'state::default::builder', []);
    $results[] = [
        'case' => 'non_binary_confirmation_survival',
        'action' => (string) ($result['action'] ?? ''),
        'classification' => $classification($result),
        'route_path' => $routePath($result),
        'reply' => (string) ($result['reply'] ?? ''),
        'active_task' => (string) ($state['active_task'] ?? ''),
        'onboarding_step' => (string) ($state['onboarding_step'] ?? ''),
    ];
    if (($result['action'] ?? '') !== 'ask_user' || $classification($result) !== 'builder_onboarding') {
        $failures[] = 'non_binary_confirmation_survival debe mantenerse en onboarding.';
    }
    if ((string) ($state['onboarding_step'] ?? '') !== 'documents_scope') {
        $failures[] = 'non_binary_confirmation_survival debe romper el loop y mover a documents_scope.';
    }
    if (!str_contains((string) ($result['reply'] ?? ''), 'Ajustemos documentos')) {
        $failures[] = 'non_binary_confirmation_survival debe responder con ayuda humana corta.';
    }
    $clearStub();

    // provider failure graceful fallback
    $userId = 'builder_assist_provider_failure';
    putenv('OPENROUTER_ENABLED=0');
    putenv('DEEPSEEK_ENABLED=0');
    $memory->saveUserMemory($tenantId, $profileKey($userId), 'profile', [
        'business_type' => 'ferreteria',
        'operation_model' => 'contado',
    ]);
    $memory->saveUserMemory($tenantId, $userId, 'state::default::builder', [
        'active_task' => 'builder_onboarding',
        'onboarding_step' => 'needs_scope',
    ]);
    $result = $gateway->handle($tenantId, $userId, 'me interesa hacr mi progama para mi empresa', 'builder', $projectId);
    $state = $memory->getUserMemory($tenantId, $userId, 'state::default::builder', []);
    $profile = $memory->getUserMemory($tenantId, $profileKey($userId), 'profile', []);
    $results[] = [
        'case' => 'provider_failure_graceful_fallback',
        'action' => (string) ($result['action'] ?? ''),
        'classification' => $classification($result),
        'route_path' => $routePath($result),
        'reply' => (string) ($result['reply'] ?? ''),
        'active_task' => (string) ($state['active_task'] ?? ''),
        'onboarding_step' => (string) ($state['onboarding_step'] ?? ''),
    ];
    if (($result['action'] ?? '') !== 'ask_user' || $classification($result) !== 'builder_onboarding') {
        $failures[] = 'provider_failure_graceful_fallback debe quedarse local.';
    }
    if (!str_contains((string) ($result['reply'] ?? ''), 'Que quieres controlar primero')) {
        $failures[] = 'provider_failure_graceful_fallback debe usar fallback humano corto.';
    }
    if (trim((string) ($profile['needs_scope'] ?? '')) !== '') {
        $failures[] = 'provider_failure_graceful_fallback no debe guardar basura.';
    }
    if ((string) ($state['onboarding_step'] ?? '') !== 'needs_scope') {
        $failures[] = 'provider_failure_graceful_fallback debe mantener needs_scope.';
    }
    putenv('OPENROUTER_ENABLED=' . (($previous['OPENROUTER_ENABLED'] !== false) ? (string) $previous['OPENROUTER_ENABLED'] : ''));
    putenv('DEEPSEEK_ENABLED=' . (($previous['DEEPSEEK_ENABLED'] !== false) ? (string) $previous['DEEPSEEK_ENABLED'] : ''));

    // multi-turn continuity
    $userId = 'builder_assist_multiturn';
    $clearStub();
    $turn1 = $gateway->handle($tenantId, $userId, 'quiero hacer una aplicacion', 'builder', $projectId);
    $turn2 = $gateway->handle($tenantId, $userId, 'vendo herramientas y taladros', 'builder', $projectId);
    $setStub([
        'operation_model' => [
            'resolved' => true,
            'mapped_value' => 'credito',
            'help_reply' => 'Entendi. Manejas credito.',
            'confidence' => 0.91,
            'intent_type' => 'map_value',
        ],
    ]);
    $turn3 = $gateway->handle($tenantId, $userId, 'lo manejo fiao', 'builder', $projectId);
    $state = $memory->getUserMemory($tenantId, $userId, 'state::default::builder', []);
    $profile = $memory->getUserMemory($tenantId, $profileKey($userId), 'profile', []);
    $results[] = [
        'case' => 'multi_turn_continuity',
        'turn1' => [
            'action' => (string) ($turn1['action'] ?? ''),
            'classification' => $classification($turn1),
            'route_path' => $routePath($turn1),
            'reply' => (string) ($turn1['reply'] ?? ''),
        ],
        'turn2' => [
            'action' => (string) ($turn2['action'] ?? ''),
            'classification' => $classification($turn2),
            'route_path' => $routePath($turn2),
            'reply' => (string) ($turn2['reply'] ?? ''),
        ],
        'turn3' => [
            'action' => (string) ($turn3['action'] ?? ''),
            'classification' => $classification($turn3),
            'route_path' => $routePath($turn3),
            'reply' => (string) ($turn3['reply'] ?? ''),
        ],
        'final_active_task' => (string) ($state['active_task'] ?? ''),
        'final_onboarding_step' => (string) ($state['onboarding_step'] ?? ''),
        'operation_model' => (string) ($profile['operation_model'] ?? ''),
    ];
    if (($turn1['action'] ?? '') !== 'ask_user' || $classification($turn1) !== 'builder_clarify') {
        $failures[] = 'multi_turn_continuity turn1 debe abrir builder_clarify.';
    }
    if (($turn2['action'] ?? '') !== 'ask_user' || $classification($turn2) !== 'builder_onboarding') {
        $failures[] = 'multi_turn_continuity turn2 debe seguir en builder_onboarding.';
    }
    if (($turn3['action'] ?? '') !== 'ask_user' || $classification($turn3) !== 'builder_onboarding') {
        $failures[] = 'multi_turn_continuity turn3 debe seguir en builder_onboarding.';
    }
    if ((string) ($state['active_task'] ?? '') !== 'builder_onboarding' || (string) ($state['onboarding_step'] ?? '') !== 'needs_scope') {
        $failures[] = 'multi_turn_continuity no debe perder contexto despues del assist.';
    }
    if ((string) ($profile['operation_model'] ?? '') !== 'credito') {
        $failures[] = 'multi_turn_continuity debe persistir operation_model mapeado.';
    }
    if (!str_contains((string) ($turn3['reply'] ?? ''), 'Paso 3')) {
        $failures[] = 'multi_turn_continuity debe continuar con el siguiente paso del onboarding.';
    }
    $clearStub();
} catch (Throwable $e) {
    $failures[] = 'Builder LLM assisted onboarding test should not throw: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    if ($value === false) {
        putenv($key);
        continue;
    }
    putenv($key . '=' . $value);
}

rrmdir($tmpDir);

$ok = $failures === [];
echo json_encode([
    'ok' => $ok,
    'results' => $results,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        @rmdir($dir);
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}
