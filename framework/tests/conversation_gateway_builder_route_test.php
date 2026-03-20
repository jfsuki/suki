<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;
use App\Core\SqlMemoryRepository;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/conversation_gateway_builder_route_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);
$dbPath = $tmpDir . '/builder_route.sqlite';
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

    $tenantId = 'tenant_builder_route';
    $userId = 'user_builder_route';
    $projectId = 'default';
    $profileUserKey = $projectId . '__builder__' . $userId;

    $memory->saveUserMemory($tenantId, $profileUserKey, 'profile', [
        'business_type' => 'ferreteria_minorista',
        'operation_model' => 'mixto',
        'needs_scope' => 'inventario, facturacion',
        'documents_scope' => 'factura, cotizacion',
    ]);
    $memory->saveUserMemory($tenantId, $userId, 'state::default::builder', [
        'active_task' => null,
        'onboarding_step' => 'plan_ready',
        'missing' => [],
        'collected' => [],
        'builder_pending_command' => null,
    ]);

    $result = $gateway->handle(
        $tenantId,
        $userId,
        'analiza este contexto de negocio',
        'builder',
        $projectId
    );

    $telemetry = is_array($result['telemetry'] ?? null) ? (array) $result['telemetry'] : [];

    if ((string) ($result['action'] ?? '') !== 'send_to_llm') {
        $failures[] = 'Builder debe continuar al flujo normal y devolver send_to_llm.';
    }
    if ((string) ($telemetry['classification'] ?? '') !== 'llm') {
        $failures[] = 'Builder route normal debe dejar classification=llm.';
    }
    if ((bool) ($telemetry['builder_normal_flow'] ?? false) !== true) {
        $failures[] = 'Builder route normal debe marcar builder_normal_flow=true.';
    }
    if ((bool) ($telemetry['builder_fallback_disabled'] ?? false) !== true) {
        $failures[] = 'Builder route normal debe marcar builder_fallback_disabled=true.';
    }
    if ((string) ($telemetry['route_reason'] ?? '') !== 'builder_continues_to_router') {
        $failures[] = 'Builder route normal debe dejar route_reason=builder_continues_to_router.';
    }
    $routingHintSteps = is_array($telemetry['routing_hint_steps'] ?? null) ? (array) $telemetry['routing_hint_steps'] : [];
    if ($routingHintSteps !== ['cache', 'rules', 'skills', 'rag', 'llm']) {
        $failures[] = 'Builder route normal debe dejar routing_hint_steps completos hacia llm.';
    }
} catch (Throwable $e) {
    $failures[] = 'Builder route test no debe lanzar excepciones: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    if ($value === false) {
        putenv($key);
        continue;
    }
    putenv($key . '=' . $value);
}

rrmdir($tmpDir);

$result = [
    'ok' => $failures === [],
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

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
