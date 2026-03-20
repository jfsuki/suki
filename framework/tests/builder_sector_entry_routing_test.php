<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;
use App\Core\SqlMemoryRepository;

$failures = [];
$results = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/builder_sector_entry_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);
$dbPath = $tmpDir . '/builder_sector_entry.sqlite';
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
    $tenantId = 'tenant_builder_sector_entry';
    $projectId = 'default';

    $cases = [
        [
            'message' => "tengo una ferreter\u{00ED}a",
            'expected_action' => 'send_to_llm',
            'expected_classification' => 'llm',
            'expected_local' => false,
            'expected_route_reason' => 'builder_continues_to_router',
            'expected_route_path' => ['cache', 'rules', 'skills', 'rag', 'llm'],
        ],
        [
            'message' => 'vendo herramientas',
            'expected_action' => 'send_to_llm',
            'expected_classification' => 'llm',
            'expected_local' => false,
            'expected_route_reason' => 'builder_continues_to_router',
            'expected_route_path' => ['cache', 'rules', 'skills', 'rag', 'llm'],
        ],
        [
            'message' => "tengo una ferreter\u{00ED}a y vendo herramientas",
            'expected_action' => 'send_to_llm',
            'expected_classification' => 'llm',
            'expected_local' => false,
            'expected_route_reason' => 'builder_continues_to_router',
            'expected_route_path' => ['cache', 'rules', 'skills', 'rag', 'llm'],
        ],
        [
            'message' => "quiero una app para ferreter\u{00ED}a",
            'expected_action' => 'send_to_llm',
            'expected_classification' => 'llm',
            'expected_local' => false,
            'expected_route_reason' => 'builder_continues_to_router',
            'expected_route_path' => ['cache', 'rules', 'skills', 'rag', 'llm'],
        ],
        [
            'message' => 'quiero crear una app',
            'expected_action' => 'ask_user',
            'expected_classification' => 'builder_onboarding',
            'expected_local' => true,
            'expected_route_path' => ['cache', 'rules'],
        ],
    ];

    foreach ($cases as $index => $case) {
        $userId = 'user_builder_sector_entry_' . $index;
        $result = $gateway->handle(
            $tenantId,
            $userId,
            (string) $case['message'],
            'builder',
            $projectId
        );

        $telemetry = is_array($result['telemetry'] ?? null) ? (array) $result['telemetry'] : [];
        $routingHintSteps = is_array($telemetry['routing_hint_steps'] ?? null)
            ? array_values((array) $telemetry['routing_hint_steps'])
            : [];
        $record = [
            'message' => $case['message'],
            'action' => (string) ($result['action'] ?? ''),
            'classification' => (string) ($telemetry['classification'] ?? ''),
            'intent' => (string) ($telemetry['intent'] ?? ''),
            'resolved_locally' => (bool) ($telemetry['resolved_locally'] ?? false),
            'route_reason' => (string) ($telemetry['route_reason'] ?? ''),
            'route_path' => $routingHintSteps,
            'reply' => (string) ($result['reply'] ?? ''),
        ];
        $results[] = $record;

        if ($record['action'] !== $case['expected_action']) {
            $failures[] = 'Unexpected action for "' . $case['message'] . '": ' . $record['action'];
        }
        if ($record['classification'] !== $case['expected_classification']) {
            $failures[] = 'Unexpected classification for "' . $case['message'] . '": ' . $record['classification'];
        }
        if ($record['resolved_locally'] !== $case['expected_local']) {
            $failures[] = 'Unexpected resolved_locally for "' . $case['message'] . '".';
        }
        if ($routingHintSteps !== $case['expected_route_path']) {
            $failures[] = 'Unexpected route_path for "' . $case['message'] . '".';
        }
        if (isset($case['expected_route_reason']) && $record['route_reason'] !== $case['expected_route_reason']) {
            $failures[] = 'Unexpected route_reason for "' . $case['message'] . '": ' . $record['route_reason'];
        }
        if (in_array($record['classification'], ['builder_onboarding', 'training'], true) && $case['expected_classification'] === 'llm') {
            $failures[] = 'Sector entry should not be captured early for "' . $case['message'] . '".';
        }
    }
} catch (Throwable $e) {
    $failures[] = 'Builder sector entry routing test should not throw: ' . $e->getMessage();
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
