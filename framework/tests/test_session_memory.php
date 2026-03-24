<?php
// framework/tests/test_session_memory.php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ChatAgent;

putenv('GEMINI_API_KEY=dummy_key_for_test_bypass');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('APP_ENV=local');

$agent = new ChatAgent();
$tenantId = 'test_tenant';
$userId = 'test_user';
$projectId = 'test_project';
$sessionId = 'test_session_' . uniqid();

$context = [
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'project_id' => $projectId,
    'session_id' => $sessionId,
    'channel' => 'web',
    'mode' => 'builder',
];

echo "GEMINI_API_KEY (mocked): " . (getenv('GEMINI_API_KEY') ? 'PRESENT' : 'MISSING') . "\n";

// Turn 1: Greeting
echo "Turn 1: Hola...\n";
$payload1 = array_merge($context, [
    'message' => 'Hola',
]);
$response1 = $agent->handle($payload1);
echo "Turn 1: step=" . ($response1['data']['state']['onboarding_step'] ?? 'unknown') . "\n";
echo "Reply 1: " . ($response1['data']['reply'] ?? 'EMPTY') . "\n\n";

// Turn 2: Providing business type
echo "Turn 2: Venta de productos...\n";
$payload2 = array_merge($context, [
    'message' => 'Venta de productos',
]);
$response2 = $agent->handle($payload2);
echo "Turn 2: step=" . ($response2['data']['state']['onboarding_step'] ?? 'unknown') . "\n";
echo "Reply 2: " . ($response2['data']['reply'] ?? 'EMPTY') . "\n\n";

// Turn 3: Check if it skips business_type and asks for operation_model
echo "Turn 3: ¿Qué sigue?\n";
$payload3 = array_merge($context, [
    'message' => '¿Qué sigue?',
]);
$response3 = $agent->handle($payload3);
echo "Turn 3: step=" . ($response3['data']['state']['onboarding_step'] ?? 'unknown') . "\n";
echo "Reply 3: " . ($response3['data']['reply'] ?? 'EMPTY') . "\n";

$step3 = $response3['data']['state']['onboarding_step'] ?? '';
if ($step3 === 'operation_model' || $step3 === 'needs_scope') {
    echo "SUCCESS: Onboarding skipped 'business_type' correctly.\n";
} else {
    echo "FAILURE: Onboarding stuck in '$step3'.\n";
}
