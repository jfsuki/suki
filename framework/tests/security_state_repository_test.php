<?php
// framework/tests/security_state_repository_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\SecurityStateRepository;

$runToken = (string) time() . (string) random_int(1000, 9999);
$tmpDir = __DIR__ . '/tmp/security_state_' . $runToken;
@mkdir($tmpDir, 0775, true);

$previousAllow = getenv('ALLOW_RUNTIME_SCHEMA');
$previousAppEnv = getenv('APP_ENV');
putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');

$repo = new SecurityStateRepository($tmpDir . '/security_state.sqlite');

$failures = [];

$bucket = 'tenant::user::chat/message';
$a = $repo->consumeRateLimit($bucket, 2, 60);
$b = $repo->consumeRateLimit($bucket, 2, 60);
$c = $repo->consumeRateLimit($bucket, 2, 60);
if (!(bool) ($a['ok'] ?? false) || !(bool) ($b['ok'] ?? false) || (bool) ($c['ok'] ?? true)) {
    $failures[] = 'Rate limit central should allow first two and block third hit.';
}

$nonce = 'nonce-' . $runToken;
$first = $repo->rememberReplayNonce('whatsapp', $nonce, 600);
$second = $repo->rememberReplayNonce('whatsapp', $nonce, 600);
if (!$first || $second) {
    $failures[] = 'Replay nonce should be accepted once and blocked on duplicate.';
}

$ok = empty($failures);
echo json_encode(['ok' => $ok, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

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
