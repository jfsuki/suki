<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;
use App\Core\SqlMemoryRepository;

$failures = [];
$results = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/builder_ambiguity_guard_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);
$dbPath = $tmpDir . '/builder_ambiguity_guard.sqlite';
$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $memory = new SqlMemoryRepository($pdo);
    $gateway = new ConversationGateway(PROJECT_ROOT, $memory);
    $tenantId = 'tenant_builder_ambiguity_guard';
    $projectId = 'default';

    $cases = [
        [
            'message' => 'me ayudas a crear una app',
            'expected_action' => 'ask_user',
            'expected_classification' => 'builder_clarify',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'A que se dedica tu negocio',
            'reply_not_contains' => 'tabla',
        ],
        [
            'message' => 'me ayduas a crear una pp',
            'expected_action' => 'ask_user',
            'expected_classification' => 'builder_clarify',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'A que se dedica tu negocio',
            'reply_not_contains' => 'tabla',
        ],
        [
            'message' => 'ayudame a crear algo',
            'expected_action' => 'ask_user',
            'expected_classification' => 'builder_clarify',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'Que quieres crear primero',
            'reply_not_contains' => 'crearemos la tabla',
        ],
        [
            'message' => 'quiero crear una tabla productos',
            'expected_action' => 'ask_user',
            'expected_classification' => 'build',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'tabla productos',
        ],
        [
            'message' => 'no es ferreteria',
            'profile' => ['business_type' => 'ferreteria_minorista'],
            'expected_action' => 'ask_user',
            'expected_classification' => 'builder_context_reset',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'A que se dedica tu negocio',
            'expect_profile_cleared' => true,
        ],
        [
            'message' => 'no te he dicho que hago',
            'profile' => ['business_type' => 'ferreteria_minorista'],
            'state' => [
                'active_task' => 'create_table',
                'entity' => 'productos',
                'missing' => ['nombre'],
                'collected' => [],
                'builder_pending_command' => [
                    'command' => 'CreateEntity',
                    'entity' => 'productos',
                    'fields' => [['name' => 'nombre', 'type' => 'text']],
                ],
            ],
            'expected_action' => 'ask_user',
            'expected_classification' => 'builder_context_reset',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'A que se dedica tu negocio',
            'expect_profile_cleared' => true,
            'expect_pending_cleared' => true,
        ],
        [
            'message' => 'vendo herramientas',
            'expected_action' => 'send_to_llm',
            'expected_classification' => 'llm',
            'expected_route_path' => ['cache', 'rules', 'skills', 'rag', 'llm'],
            'expected_route_reason' => 'builder_continues_to_router',
            'expected_local' => false,
        ],
        [
            'message' => 'me interesa hacr mi progama para mi empresa',
            'profile' => [
                'business_type' => 'ferreteria_minorista',
                'operation_model' => 'contado',
            ],
            'state' => [
                'active_task' => 'builder_onboarding',
                'onboarding_step' => 'needs_scope',
            ],
            'expected_action' => 'send_to_llm',
            'expected_classification' => 'llm',
            'expected_route_path' => ['cache', 'rules', 'skills', 'rag', 'llm'],
            'expected_route_reason' => 'builder_continues_to_router',
            'expected_local' => false,
            'reply_not_contains' => 'Paso 4',
            'expect_scope_empty' => ['needs_scope'],
        ],
        [
            'message' => 'no entendi todo eso q escribes',
            'profile' => [
                'business_type' => 'ferreteria_minorista',
                'operation_model' => 'contado',
            ],
            'state' => [
                'active_task' => 'builder_onboarding',
                'onboarding_step' => 'needs_scope',
            ],
            'expected_action' => 'ask_user',
            'expected_classification' => 'builder_onboarding',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'Voy mas simple',
            'expect_scope_empty' => ['needs_scope'],
        ],
        [
            'message' => 'por donde vas q es es',
            'profile' => [
                'business_type' => 'ferreteria_minorista',
                'operation_model' => 'contado',
            ],
            'state' => [
                'active_task' => 'builder_onboarding',
                'onboarding_step' => 'needs_scope',
            ],
            'expected_action' => 'send_to_llm',
            'expected_classification' => 'llm',
            'expected_route_path' => ['cache', 'rules', 'skills', 'rag', 'llm'],
            'expected_route_reason' => 'builder_continues_to_router',
            'expected_local' => false,
            'expect_scope_empty' => ['needs_scope'],
        ],
        [
            'message' => 'pero hablas por hablas no me estas entenidnedo',
            'profile' => [
                'business_type' => 'ferreteria_minorista',
                'operation_model' => 'contado',
                'needs_scope' => 'inventario, facturacion',
                'needs_scope_items' => ['inventario', 'facturacion'],
            ],
            'state' => [
                'active_task' => 'builder_onboarding',
                'onboarding_step' => 'documents_scope',
            ],
            'expected_action' => 'ask_user',
            'expected_classification' => 'builder_onboarding',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'Voy mas simple',
            'expect_scope_empty' => ['documents_scope'],
        ],
        [
            'message' => 'vendo pulidoras y taladros',
            'profile' => [
                'business_type' => 'ferreteria_minorista',
                'operation_model' => 'contado',
            ],
            'state' => [
                'active_task' => 'builder_onboarding',
                'onboarding_step' => 'needs_scope',
            ],
            'expected_action' => 'send_to_llm',
            'expected_classification' => 'llm',
            'expected_route_path' => ['cache', 'rules', 'skills', 'rag', 'llm'],
            'expected_route_reason' => 'builder_continues_to_router',
            'expected_local' => false,
            'expect_scope_empty' => ['needs_scope'],
        ],
        [
            'message' => 'chao',
            'expected_action' => 'respond_local',
            'expected_classification' => 'farewell',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'Cuando quieras seguimos',
        ],
        [
            'message' => 'quiero crear una app',
            'expected_action' => 'ask_user',
            'expected_classification' => 'builder_onboarding',
            'expected_route_path' => ['cache', 'rules'],
            'reply_contains' => 'Paso 1',
        ],
    ];

    foreach ($cases as $index => $case) {
        $userId = 'user_builder_ambiguity_' . $index;
        $profileKey = $projectId . '__builder__' . $userId;
        if (!empty($case['profile']) && is_array($case['profile'])) {
            $memory->saveUserMemory($tenantId, $profileKey, 'profile', (array) $case['profile']);
        }
        if (!empty($case['state']) && is_array($case['state'])) {
            $memory->saveUserMemory($tenantId, $userId, 'state::default::builder', (array) $case['state']);
        }

        $result = $gateway->handle($tenantId, $userId, (string) $case['message'], 'builder', $projectId);
        $telemetry = is_array($result['telemetry'] ?? null) ? (array) $result['telemetry'] : [];
        $routingHintSteps = is_array($telemetry['routing_hint_steps'] ?? null)
            ? array_values((array) $telemetry['routing_hint_steps'])
            : [];
        $reply = (string) ($result['reply'] ?? '');
        $profile = $memory->getUserMemory($tenantId, $profileKey, 'profile', []);
        $state = $memory->getUserMemory($tenantId, $userId, 'state::default::builder', []);

        $record = [
            'message' => $case['message'],
            'action' => (string) ($result['action'] ?? ''),
            'classification' => (string) ($telemetry['classification'] ?? ''),
            'route_reason' => (string) ($telemetry['route_reason'] ?? ''),
            'route_path' => $routingHintSteps,
            'resolved_locally' => (bool) ($telemetry['resolved_locally'] ?? false),
            'reply' => $reply,
            'profile_business_type' => $profile['business_type'] ?? null,
        ];
        $results[] = $record;

        if (isset($case['expected_action']) && $record['action'] !== $case['expected_action']) {
            $failures[] = 'Unexpected action for "' . $case['message'] . '": ' . $record['action'];
        }
        if (isset($case['expected_classification']) && $record['classification'] !== $case['expected_classification']) {
            $failures[] = 'Unexpected classification for "' . $case['message'] . '": ' . $record['classification'];
        }
        if (isset($case['expected_route_path']) && $routingHintSteps !== $case['expected_route_path']) {
            $failures[] = 'Unexpected route_path for "' . $case['message'] . '".';
        }
        if (isset($case['expected_route_reason']) && $record['route_reason'] !== $case['expected_route_reason']) {
            $failures[] = 'Unexpected route_reason for "' . $case['message'] . '": ' . $record['route_reason'];
        }
        if (isset($case['expected_local']) && $record['resolved_locally'] !== $case['expected_local']) {
            $failures[] = 'Unexpected resolved_locally for "' . $case['message'] . '".';
        }
        if (isset($case['reply_contains']) && !str_contains($reply, (string) $case['reply_contains'])) {
            $failures[] = 'Reply for "' . $case['message'] . '" should contain "' . $case['reply_contains'] . '".';
        }
        if (isset($case['reply_not_contains']) && str_contains($reply, (string) $case['reply_not_contains'])) {
            $failures[] = 'Reply for "' . $case['message'] . '" should not contain "' . $case['reply_not_contains'] . '".';
        }
        if (!empty($case['blocked_classifications']) && in_array($record['classification'], (array) $case['blocked_classifications'], true)) {
            $failures[] = 'Classification for "' . $case['message'] . '" should not stay in early onboarding.';
        }
        if (!empty($case['expect_scope_empty'])) {
            foreach ((array) $case['expect_scope_empty'] as $scopeKey) {
                if (trim((string) ($profile[(string) $scopeKey] ?? '')) !== '') {
                    $failures[] = 'Profile key "' . $scopeKey . '" should remain empty for "' . $case['message'] . '".';
                }
            }
        }
        if (!empty($case['expect_profile_cleared']) && (string) ($profile['business_type'] ?? '') !== '') {
            $failures[] = 'Profile business_type should be cleared for "' . $case['message'] . '".';
        }
        if (!empty($case['expect_pending_cleared']) && !empty($state['builder_pending_command'])) {
            $failures[] = 'Pending builder command should be cleared for "' . $case['message'] . '".';
        }
    }
} catch (Throwable $e) {
    $failures[] = 'Builder ambiguity guard test should not throw: ' . $e->getMessage();
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
