<?php
// framework/tests/run_all.php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access Denied: CLI only.');
}

$testFiles = [
    'test_security_routes.php',
    'test_api_health.php',
    'test_intent_recognition.php',
    'test_conversation_memory.php',
    'test_frustration_detection.php',
    'test_tool_execution.php',
];

echo "--- SUKI Unified Test Runner ---" . PHP_EOL;

$allPassed = true;

foreach ($testFiles as $testFile) {
    if (!file_exists(__DIR__ . '/' . $testFile)) {
        echo "[MISSING] $testFile (not yet implemented)" . PHP_EOL;
        continue;
    }

    echo "[RUNNING] $testFile..." . PHP_EOL;
    passthru('php ' . escapeshellarg(__DIR__ . '/' . $testFile), $returnStatus);
    
    if ($returnStatus !== 0) {
        echo "[FAILED] $testFile returned status $returnStatus" . PHP_EOL;
        $allPassed = false;
    } else {
        echo "[SUCCESS] $testFile passed." . PHP_EOL;
    }
    echo "--------------------------------" . PHP_EOL;
}

if ($allPassed) {
    echo "--- FINAL RESULT: ALL TESTS PASSED ---" . PHP_EOL;
    exit(0);
} else {
    echo "--- FINAL RESULT: SOME TESTS FAILED ---" . PHP_EOL;
    exit(1);
}
