<?php
// framework/tests/test_session_memory.php
require_once __DIR__ . '/../vendor/autoload.php';

// Mock environment for test
putenv('GEMINI_API_KEY=dummy_key_for_test_bypass');
putenv('EMBEDDING_MODEL=gemini-embedding-001');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('APP_ENV=local');

if (!defined('FRAMEWORK_ROOT')) {
    define('FRAMEWORK_ROOT', dirname(__DIR__));
}
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__, 2));
}

use App\Core\ChatAgent;
use App\Core\ChatMemoryStore;

// Clean session for reproducibility
$tenantId = 'test_tenant';
$userId = 'test_user_' . uniqid();
$projectId = 'test_project';

$agent = new ChatAgent();
$sessionKey = "chat_session:{$tenantId}:{$projectId}:app:{$userId}";

echo "--- Turn 1: Setting context ---\n";
$msg1 = "Mi nombre es Carlos y tengo una ferretería.";
$response1 = $agent->handle([
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'message' => $msg1,
    'mode' => 'app',
    'project_id' => $projectId
]);
var_dump($response1);
echo "User: $msg1\n";
echo "Suki: " . $response1['reply'] . "\n\n";

echo "--- Turn 2: Verifying recall ---\n";
$msg2 = "¿Cómo me llamo?";
$response2 = $agent->handle([
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'message' => $msg2,
    'mode' => 'app',
    'project_id' => $projectId
]);
echo "User: $msg2\n";
echo "Suki: " . $response2['reply'] . "\n\n";

$lowerReply = mb_strtolower($response2['reply']);
if (str_contains($lowerReply, 'carlos')) {
    echo "SUCCESS: Agent remembered the name 'Carlos' from history.\n";
} else {
    echo "FAILURE: Agent forgot the name 'Carlos'.\n";
}

echo "\n--- Turn 3: Verifying business recall ---\n";
$msg3 = "¿De qué es mi negocio?";
$response3 = $agent->handle([
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'message' => $msg3,
    'mode' => 'app',
    'project_id' => $projectId
]);
echo "User: $msg3\n";
echo "Suki: " . $response3['reply'] . "\n\n";

$lowerReplyBusiness = mb_strtolower($response3['reply']);
if (str_contains($lowerReplyBusiness, 'ferretería') || str_contains($lowerReplyBusiness, 'ferreteria')) {
    echo "SUCCESS: Agent remembered 'ferretería' from history.\n";
} else {
    echo "FAILURE: Agent forgot the business type.\n";
}
