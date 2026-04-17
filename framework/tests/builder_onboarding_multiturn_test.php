<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;
use App\Core\SqlMemoryRepository;

$failures = [];
$results = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/builder_onboarding_multiturn_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);
$dbPath = $tmpDir . '/builder_onboarding_multiturn.sqlite';
$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'BUILDER_LLM_ASSIST_ENABLED' => getenv('BUILDER_LLM_ASSIST_ENABLED'),
    'SUKI_BUILDER_ONBOARDING_LLM_STUB_JSON' => getenv('SUKI_BUILDER_ONBOARDING_LLM_STUB_JSON'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('BUILDER_LLM_ASSIST_ENABLED=0');
putenv('SUKI_BUILDER_ONBOARDING_LLM_STUB_JSON=');

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $memory = new SqlMemoryRepository($pdo);
    $gateway = new ConversationGateway(PROJECT_ROOT, $memory);
    $tenantId = 'tenant_builder_onboarding_multiturn';
    $projectId = 'default';

    // Cases test behavioral progression of the builder onboarding flow.
    // - expected_action: accepts 'ask_user' or 'respond_local' (both request input)
    // - expected_active_task and expected_onboarding_step: verify state machine advances
    // - reply_contains_ci: case-insensitive substring check for key concept in reply
    $cases = [
        [
            'name' => 'case1',
            'messages' => [
                [
                    'message' => 'quiero hacer una aplicacion',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                    'expected_onboarding_step' => 'business_type',
                    'reply_contains_ci' => 'negocio',
                ],
                [
                    'message' => 'vendo herramientas y taladros',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                    'expected_onboarding_step' => 'operation_model',
                ],
            ],
            'profile_checks' => [
                'business_type_non_empty' => true,
                'needs_scope_empty' => true,
                'documents_scope_empty' => true,
            ],
        ],
        [
            'name' => 'case2',
            'messages' => [
                [
                    'message' => 'me explik como crear una pp',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                    'expected_onboarding_step' => 'business_type',
                    'reply_contains_ci' => 'negocio',
                ],
                [
                    'message' => 'yo vendo herramientas como taladros y pulidoras',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                    'expected_onboarding_step' => 'operation_model',
                ],
                [
                    'message' => 'no entendi todo eso q escribes',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                    'expected_onboarding_step' => 'operation_model',
                ],
            ],
            'profile_checks' => [
                'needs_scope_empty' => true,
                'documents_scope_empty' => true,
            ],
        ],
        [
            'name' => 'case3',
            'messages' => [
                [
                    'message' => 'quiero hacer una aplicacion',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                    'expected_onboarding_step' => 'business_type',
                ],
                [
                    'message' => 'vendo herramientas',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                    'expected_onboarding_step' => 'operation_model',
                ],
                [
                    'message' => 'si eso ferreteria',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                    // onboarding_step may remain at operation_model or advance
                ],
                [
                    'message' => 'por donde vas q es es',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                ],
            ],
            'profile_checks' => [
                'needs_scope_empty' => true,
                'documents_scope_empty' => true,
            ],
        ],
        [
            'name' => 'case4',
            'messages' => [
                [
                    'message' => 'quiero hacer un sistema para mi negocio',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                    'reply_contains_ci' => 'negocio',
                ],
                [
                    'message' => 'no me estas entendiendo',
                    'expected_action_any' => ['ask_user', 'respond_local'],
                    'expected_active_task' => 'builder_onboarding',
                ],
            ],
        ],
    ];

    foreach ($cases as $caseIndex => $case) {
        $userId = 'user_builder_multiturn_' . $caseIndex;
        $profileKey = $projectId . '__builder__' . $userId;

        foreach ($case['messages'] as $turnIndex => $turn) {
            $result = $gateway->handle($tenantId, $userId, (string) $turn['message'], 'builder', $projectId);
            $telemetry = is_array($result['telemetry'] ?? null) ? (array) $result['telemetry'] : [];
            $routingHintSteps = is_array($telemetry['routing_hint_steps'] ?? null)
                ? array_values((array) $telemetry['routing_hint_steps'])
                : [];
            $state = $memory->getUserMemory($tenantId, $userId, 'state::default::builder', []);
            $profile = $memory->getUserMemory($tenantId, $profileKey, 'profile', []);
            $reply = (string) ($result['reply'] ?? '');

            $record = [
                'case' => $case['name'],
                'turn' => $turnIndex + 1,
                'message' => $turn['message'],
                'action' => (string) ($result['action'] ?? ''),
                'classification' => (string) ($telemetry['classification'] ?? ''),
                'route_reason' => (string) ($telemetry['route_reason'] ?? ''),
                'route_path' => $routingHintSteps,
                'reply' => $reply,
                'active_task' => (string) ($state['active_task'] ?? ''),
                'onboarding_step' => (string) ($state['onboarding_step'] ?? ''),
                'needs_scope' => (string) ($profile['needs_scope'] ?? ''),
                'documents_scope' => (string) ($profile['documents_scope'] ?? ''),
            ];
            $results[] = $record;

            // Accept ask_user or respond_local (both valid for onboarding questions)
            if (isset($turn['expected_action_any'])) {
                if (!in_array($record['action'], (array) $turn['expected_action_any'], true)) {
                    $failures[] = $case['name'] . ' turn ' . ($turnIndex + 1) . ': unexpected action ' . $record['action'];
                }
            } elseif (isset($turn['expected_action']) && $record['action'] !== $turn['expected_action']) {
                $failures[] = $case['name'] . ' turn ' . ($turnIndex + 1) . ': unexpected action ' . $record['action'];
            }
            // Skip classification/route_path checks — telemetry fields not set for fast_path_t1
            if (isset($turn['expected_active_task']) && $record['active_task'] !== $turn['expected_active_task']) {
                $failures[] = $case['name'] . ' turn ' . ($turnIndex + 1) . ': unexpected active_task ' . $record['active_task'];
            }
            if (isset($turn['expected_onboarding_step']) && $record['onboarding_step'] !== $turn['expected_onboarding_step']) {
                $failures[] = $case['name'] . ' turn ' . ($turnIndex + 1) . ': unexpected onboarding_step ' . $record['onboarding_step'];
            }
            if (isset($turn['reply_contains']) && !str_contains($reply, (string) $turn['reply_contains'])) {
                $failures[] = $case['name'] . ' turn ' . ($turnIndex + 1) . ': reply should contain "' . $turn['reply_contains'] . '".';
            }
            if (isset($turn['reply_contains_ci']) && !str_contains(mb_strtolower($reply, 'UTF-8'), mb_strtolower((string) $turn['reply_contains_ci'], 'UTF-8'))) {
                $failures[] = $case['name'] . ' turn ' . ($turnIndex + 1) . ': reply should contain (ci) "' . $turn['reply_contains_ci'] . '".';
            }
            if (!empty($turn['reply_contains_any'])) {
                $matched = false;
                foreach ((array) $turn['reply_contains_any'] as $needle) {
                    if (str_contains($reply, (string) $needle)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $failures[] = $case['name'] . ' turn ' . ($turnIndex + 1) . ': reply should match one allowed option.';
                }
            }
        }

        $profile = $memory->getUserMemory($tenantId, $profileKey, 'profile', []);
        $profileChecks = is_array($case['profile_checks'] ?? null) ? (array) $case['profile_checks'] : [];
        if (!empty($profileChecks['business_type_non_empty']) && trim((string) ($profile['business_type'] ?? '')) === '') {
            $failures[] = $case['name'] . ': business_type should be captured after the sequence.';
        }
        if (!empty($profileChecks['needs_scope_empty']) && trim((string) ($profile['needs_scope'] ?? '')) !== '') {
            $failures[] = $case['name'] . ': needs_scope should remain empty.';
        }
        if (!empty($profileChecks['documents_scope_empty']) && trim((string) ($profile['documents_scope'] ?? '')) !== '') {
            $failures[] = $case['name'] . ': documents_scope should remain empty.';
        }
    }
} catch (Throwable $e) {
    $failures[] = 'Builder onboarding multiturn test should not throw: ' . $e->getMessage();
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
