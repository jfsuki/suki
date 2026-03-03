<?php
// framework/tests/api_security_guard_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ApiSecurityGuard;
use App\Core\SecurityStateRepository;

$tmpDir = __DIR__ . '/tmp/security_guard_' . time();
@mkdir($tmpDir, 0775, true);

$previousAllow = getenv('ALLOW_RUNTIME_SCHEMA');
$previousAppEnv = getenv('APP_ENV');
putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');

$repo = new SecurityStateRepository($tmpDir . '/security_state.sqlite');
$guard = new ApiSecurityGuard($repo);

putenv('API_SECURITY_STRICT=1');
putenv('API_CSRF_ENFORCE');
putenv('API_RATE_LIMIT_CHAT_PER_MIN=3');

$failures = [];

$res1 = $guard->enforce('entity/save', 'POST', ['REMOTE_ADDR' => '203.0.113.10'], [], [], $tmpDir);
if ((bool) ($res1['ok'] ?? true)) {
    $failures[] = 'Should require auth for protected route.';
}

$session = ['auth_user' => ['id' => 'u1', 'tenant_id' => 'default'], 'csrf_token' => 'abc123'];
$res2 = $guard->enforce('entity/save', 'POST', ['REMOTE_ADDR' => '203.0.113.10'], $session, [], $tmpDir);
if ((bool) ($res2['ok'] ?? true)) {
    $failures[] = 'CSRF should be mandatory in strict mode.';
}

$res3 = $guard->enforce('entity/save', 'POST', ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_CSRF_TOKEN' => 'abc123'], $session, [], $tmpDir);
if (!(bool) ($res3['ok'] ?? false)) {
    $failures[] = 'Valid auth + csrf should pass.';
}

for ($i = 0; $i < 4; $i++) {
    $res = (new ApiSecurityGuard(new SecurityStateRepository($tmpDir . '/security_state.sqlite')))
        ->enforce('chat/message', 'POST', ['REMOTE_ADDR' => '203.0.113.10'], [], [], $tmpDir);
}
if ((bool) ($res['ok'] ?? true)) {
    $failures[] = 'Central rate limit should block chat/message after threshold.';
}

$ok = empty($failures);
echo json_encode(['ok' => $ok, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

putenv('API_SECURITY_STRICT');
putenv('API_RATE_LIMIT_CHAT_PER_MIN');
if ($previousAllow === false) {
    putenv('ALLOW_RUNTIME_SCHEMA');
} else {
    putenv('ALLOW_RUNTIME_SCHEMA=' . $previousAllow);
}
if ($previousAppEnv === false) {
    putenv('APP_ENV');
} else {
    putenv('APP_ENV=' . $previousAppEnv);
}
exit($ok ? 0 : 1);
