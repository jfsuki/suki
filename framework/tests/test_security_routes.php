<?php
// framework/tests/test_security_routes.php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access Denied: CLI only.');
}

/**
 * Test: Security Routes
 * Verifies that internal PHP files are protected against direct execution.
 */

echo "--- SUKI Security Route Test ---" . PHP_EOL;

$filesToTest = [
    'framework/tests/chat_acid.php',
    'framework/tests/chat_golden.php',
    'project/config/app.php',
    'project/app/controller/ClienteController.php',
];

$allPassed = true;

foreach ($filesToTest as $file) {
    $fullPath = __DIR__ . '/../../' . $file;
    if (!file_exists($fullPath)) {
        echo "[SKIP] $file (not found)" . PHP_EOL;
        continue;
    }

    $content = file_get_contents($fullPath);
    
    // Check for CLI guard or Execution Guard
    $hasGuard = str_contains($content, "php_sapi_name() !== 'cli'") || 
                str_contains($content, "defined('SUKI_EXEC')") ||
                str_contains($content, "defined('APP_ROOT')");

    if ($hasGuard) {
        echo "[PASS] $file has execution guard." . PHP_EOL;
    } else {
        echo "[FAIL] $file is unprotected!" . PHP_EOL;
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "--- RESULT: ALL PROTECTED ---" . PHP_EOL;
    exit(0);
} else {
    echo "--- RESULT: VULNERABILITIES FOUND ---" . PHP_EOL;
    exit(1);
}
