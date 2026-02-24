<?php
// framework/tests/api_security_guard_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ApiSecurityGuard;

$guard = new ApiSecurityGuard();
$tmpDir = __DIR__ . '/tmp/security_guard_' . time();
@mkdir($tmpDir, 0775, true);

$failures = [];

$res1 = $guard->enforce('entity/save', 'POST', ['REMOTE_ADDR' => '203.0.113.10'], [], [], $tmpDir);
if ((bool) ($res1['ok'] ?? true)) {
    $failures[] = 'Should require auth for protected route.';
}

$session = ['auth_user' => ['id' => 'u1', 'tenant_id' => 'default'], 'csrf_token' => 'abc123'];
$res2 = $guard->enforce('entity/save', 'POST', ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_CSRF_TOKEN' => 'abc123'], $session, [], $tmpDir);
if (!(bool) ($res2['ok'] ?? false)) {
    $failures[] = 'Valid auth + csrf should pass.';
}

for ($i = 0; $i < 95; $i++) {
    $res = $guard->enforce('chat/message', 'POST', ['REMOTE_ADDR' => '203.0.113.10'], [], [], $tmpDir);
}
if ((bool) ($res['ok'] ?? true)) {
    $failures[] = 'Rate limit should eventually block chat/message.';
}

$ok = empty($failures);
echo json_encode(['ok' => $ok, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
