<?php
// framework/tests/test_frustration_detection.php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access Denied: CLI only.');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;
use App\Core\ProjectRegistry;

echo "--- SUKI Behavioral Test: Frustration Detection ---" . PHP_EOL;

$agent = new ChatAgent();
$registry = new ProjectRegistry();
$db = $registry->db();

// Ensure schema is up to date
$tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='support_tickets'")->fetch();
if (!$tableCheck) {
    echo "[!] Table 'support_tickets' not found. Applying migration..." . PHP_EOL;
    $migrationPath = __DIR__ . '/../../db/migrations/sqlite/20260403_026_support_tickets_auto_learning.sql';

    if (is_file($migrationPath)) {
        $sql = file_get_contents($migrationPath);
        $db->exec($sql);
        echo "[SUCCESS] Migration applied." . PHP_EOL;
    } else {
        echo "[FAIL] Migration file not found at $migrationPath" . PHP_EOL;
        exit(1);
    }
}


$testMessage = "Esto no sirve para nada, es una estafa.";
echo "[TEST] Sending frustrating message: '$testMessage'..." . PHP_EOL;

$payload = [
    'message' => $testMessage,
    'session_id' => 'test_sess_frustration',
    'user_id' => 'test_user_1',
    'tenant_id' => 'test_tenant',
    'project_id' => 'test_proj'
];

$response = $agent->handle($payload);

echo "[INFO] Agent Response Snippet: " . substr($response['data']['reply'] ?? $response['message'] ?? '', 0, 100) . "..." . PHP_EOL;

// Check if the apology note is in the response
$hasApology = str_contains($response['data']['reply'] ?? $response['message'] ?? '', 'NOTA DEL SISTEMA');

if ($hasApology) {
    echo "[PASS] System apology found in response." . PHP_EOL;
} else {
    echo "[FAIL] System apology NOT found in response." . PHP_EOL;
}

// Check database for ticket
$ticket = $db->query("SELECT * FROM support_tickets WHERE session_id = 'test_sess_frustration' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($ticket) {
    echo "[PASS] Support ticket created in database: " . $ticket['id'] . PHP_EOL;
    echo "       Subject: " . $ticket['subject'] . PHP_EOL;
    echo "       Sentiment: " . $ticket['sentiment'] . PHP_EOL;
} else {
    echo "[FAIL] No support ticket found in database for this session." . PHP_EOL;
}

if ($hasApology && $ticket) {
    echo "--- RESULT: FRUSTRATION DETECTION WORKING ---" . PHP_EOL;
    exit(0);
} else {
    echo "--- RESULT: TEST FAILED ---" . PHP_EOL;
    exit(1);
}
