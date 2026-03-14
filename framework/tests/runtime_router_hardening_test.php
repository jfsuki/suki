<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;
use App\Core\ContractRegistry;
use App\Core\IntentRouter;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$gateway = new ConversationGateway();
$router = new IntentRouter();

$routeCases = [
    [
        'mode' => 'builder',
        'message' => 'estado del proyecto',
        'reply_contains' => 'Estado del proyecto',
    ],
    [
        'mode' => 'app',
        'message' => 'dame el estado del proyecto',
        'reply_contains' => 'En esta app puedes trabajar',
    ],
    [
        'mode' => 'builder',
        'message' => 'necesito convertir cajas a unidades',
        'reply_contains' => 'plantilla experta para FERRETERIA',
    ],
    [
        'mode' => 'builder',
        'message' => 'no se cuanto me cuesta cada plato',
        'reply_contains' => 'plantilla experta para RESTAURANTE',
    ],
];

foreach ($routeCases as $index => $case) {
    $suffix = (string) (time() + $index);
    $gatewayResult = $gateway->handle(
        'default',
        'runtime_router_hardening_' . $suffix,
        (string) $case['message'],
        (string) $case['mode'],
        'suki_erp'
    );
    $route = $router->route($gatewayResult, [
        'tenant_id' => 'default',
        'project_id' => 'suki_erp',
        'session_id' => 'runtime_router_hardening_session_' . $suffix,
        'user_id' => 'runtime_router_hardening_' . $suffix,
        'message_text' => (string) $case['message'],
        'channel' => 'test',
        'role' => 'admin',
        'mode' => (string) $case['mode'],
        'is_authenticated' => false,
        'chat_exec_auth_required' => false,
    ]);

    if (!str_contains($route->reply(), (string) $case['reply_contains'])) {
        $failures[] = 'El router no preservo la respuesta esperada para: ' . $case['message'];
    }
}

$clearCommandCases = [
    [
        'mode' => 'app',
        'message' => 'actualizar estado fiscal fiscal_document_id=123 status=submitted',
        'expected_skill' => 'fiscal_update_status',
    ],
    [
        'mode' => 'app',
        'message' => 'registrar snapshot pedido ecommerce store_id=1 external_order_id=wc-order-202 status=paid currency=COP total=88000',
        'expected_skill' => 'ecommerce_register_order_pull_snapshot',
    ],
];

foreach ($clearCommandCases as $index => $case) {
    $suffix = (string) (time() + 100 + $index);
    $gatewayResult = $gateway->handle(
        'default',
        'runtime_router_hardening_command_' . $suffix,
        (string) $case['message'],
        (string) $case['mode'],
        'suki_erp'
    );
    $route = $router->route($gatewayResult, [
        'tenant_id' => 'default',
        'project_id' => 'suki_erp',
        'session_id' => 'runtime_router_hardening_command_session_' . $suffix,
        'user_id' => 'runtime_router_hardening_' . $suffix,
        'message_text' => (string) $case['message'],
        'channel' => 'test',
        'role' => 'admin',
        'mode' => (string) $case['mode'],
        'is_authenticated' => false,
        'chat_exec_auth_required' => false,
    ]);

    $telemetry = $route->telemetry();
    if ((string) ($telemetry['skill_selected'] ?? '') !== (string) $case['expected_skill']) {
        $failures[] = 'La skill clara debe seguir detectandose para: ' . $case['message'];
    }
    if (($telemetry['project_status_route_preserved'] ?? false) === true) {
        $failures[] = 'El guard de estado del proyecto no debe bloquear: ' . $case['message'];
    }
    if (str_contains($route->reply(), 'En esta app puedes trabajar con:')) {
        $failures[] = 'La ayuda generica no debe reaparecer para: ' . $case['message'];
    }
}

$syntheticCollision = $router->route([
    'action' => 'respond_local',
    'reply' => 'Estado del proyecto: listo.',
    'telemetry' => [
        'classification' => 'builder_onboarding',
        'resolved_locally' => true,
        'intent' => 'PROJECT_STATUS',
        'routing_hint_steps' => ['cache', 'rules'],
        'cache_hit' => false,
        'rules_hit' => true,
        'rag_hit' => false,
    ],
], [
    'tenant_id' => 'default',
    'project_id' => 'suki_erp',
    'session_id' => 'runtime_router_hardening_collision',
    'user_id' => 'runtime_router_hardening_collision',
    'message_text' => 'mira este cliente',
    'channel' => 'test',
    'role' => 'admin',
    'mode' => 'builder',
    'is_authenticated' => false,
    'chat_exec_auth_required' => false,
]);

$syntheticTelemetry = $syntheticCollision->telemetry();
foreach (['parser_collision_detected', 'low_confidence_module_match', 'project_status_route_preserved'] as $field) {
    if (($syntheticTelemetry[$field] ?? false) !== true) {
        $failures[] = 'Falta telemetria `' . $field . '` en la colision sintetica controlada.';
    }
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $resolver = new SkillResolver();

    foreach ([
        'estado del proyecto',
        'no se cuanto me cuesta cada plato',
        'necesito convertir cajas a unidades',
    ] as $message) {
        $resolved = $resolver->resolve($message, $skillRegistry, []);
        $selected = (string) (($resolved['selected']['name'] ?? '') ?: '');
        if (in_array($selected, ['entity_search', 'entity_resolve', 'pos_list_cash_sessions'], true)) {
            $failures[] = 'SkillResolver no debe sobreajustar `' . $selected . '` para: ' . $message;
        }
    }

    $clearModuleCases = [
        'lista planes disponibles' => 'tenant_list_plans',
        'que herramientas puede usar este tenant' => 'agent_list_tool_groups',
        'lista mis tiendas' => 'ecommerce_list_stores',
    ];
    foreach ($clearModuleCases as $message => $expected) {
        $resolved = $resolver->resolve($message, $skillRegistry, []);
        $selected = (string) (($resolved['selected']['name'] ?? '') ?: '');
        if ($selected !== $expected) {
            $failures[] = 'SkillResolver debe conservar la ruta clara `' . $expected . '` para: ' . $message . ' (actual: ' . $selected . ')';
        }
    }
} catch (Throwable $e) {
    $failures[] = 'La verificacion de SkillResolver debe pasar: ' . $e->getMessage();
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
