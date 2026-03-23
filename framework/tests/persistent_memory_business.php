<?php
declare(strict_types=1);

require_once __DIR__ . '/project/config/env_loader.php';

$frameworkRoot = __DIR__ . '/framework';
require_once $frameworkRoot . '/vendor/autoload.php';
require_once $frameworkRoot . '/app/autoload.php';

use App\Core\ChatAgent;

$agent = new ChatAgent();
$userId = "user_test_persistent_" . time(); // Unique user for this test
$projectId = "demo";

echo "--- STEP 1: Tell Suki the business name ---\n";
$payload1 = [
    'message' => "Mi negocio se llama Ferretería El Clavo Feliz",
    'user_id' => $userId,
    'session_id' => "sess_1_" . time(),
    'project_id' => $projectId,
    'channel' => 'testing',
    'test_mode' => true
];

$response1 = $agent->handle($payload1);
echo "Full Response 1: " . json_encode($response1, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Wait a bit
sleep(1);

echo "--- STEP 2: Ask Suki about the business name in a NEW session ---\n";
$payload2 = [
    'message' => "¿Cómo se llama mi negocio?",
    'user_id' => $userId,
    'session_id' => "sess_2_" . time(),
    'project_id' => $projectId,
    'channel' => 'testing',
    'test_mode' => true
];

$response2 = $agent->handle($payload2);
echo "Full Response 2: " . json_encode($response2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Check if the reply actually mentions the name
if (str_contains($response2['data']['reply'] ?? '', "El Clavo Feliz")) {
    echo "\nSUCCESS: Suki remembered the business name!\n";
} else {
    echo "\nFAILURE: Suki did NOT remember the business name.\n";
}
