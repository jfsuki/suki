<?php
// framework/tests/test_api_health.php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access Denied: CLI only.');
}

/**
 * Test: API Health
 * Verifies that all external LLM and Vector APIs are reachable and responsive.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\LLM\LLMRouter;
use App\Core\QdrantVectorStore;

echo "--- SUKI API Health Test ---" . PHP_EOL;

$allPassed = true;

// 1. Test LLM Providers
$providers = ['gemini', 'mistral', 'openrouter'];
try {
    $router = new LLMRouter();
    foreach ($providers as $provider) {
        echo "[TEST] External API: LLM provider ($provider)..." . PHP_EOL;
        try {
            $response = $router->complete([['role' => 'user', 'content' => 'ping']], ['provider_mode' => $provider, 'max_tokens' => 5]);
            if (!empty($response)) {
                echo "[PASS] $provider responded." . PHP_EOL;
            } else {
                echo "[FAIL] $provider returned empty response." . PHP_EOL;
                $allPassed = false;
            }
        } catch (\Throwable $e) {
            echo "[FAIL] $provider unreachable: " . $e->getMessage() . PHP_EOL;
            $allPassed = false;
        }
    }
} catch (\Throwable $e) {
    echo "[FAIL] LLMRouter initialization error: " . $e->getMessage() . PHP_EOL;
    $allPassed = false;
}

// 2. Test Qdrant Vector Store
echo "[TEST] Internal API: Qdrant Vector Store..." . PHP_EOL;
try {
    $qdrant = new QdrantVectorStore();
    $health = $qdrant->inspectCollection(); 
    if ($health['ok']) {
        echo "[PASS] Qdrant is healthy (Collection: " . $health['collection'] . ")." . PHP_EOL;
    } else {
        echo "[FAIL] Qdrant reported unhealthy status." . PHP_EOL;
        $allPassed = false;
    }
} catch (\Throwable $e) {
    echo "[FAIL] Qdrant connection error: " . $e->getMessage() . PHP_EOL;
    $allPassed = false;
}


if ($allPassed) {
    echo "--- RESULT: ALL APIs HEALTHY ---" . PHP_EOL;
    exit(0);
} else {
    echo "--- RESULT: API OUTAGE OR ISSUE FOUND ---" . PHP_EOL;
    exit(1);
}
