<?php
// framework/tests/security_state_repository_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\SecurityStateRepository;

$tmpDir = __DIR__ . '/tmp/security_state_' . time();
@mkdir($tmpDir, 0775, true);
$repo = new SecurityStateRepository($tmpDir . '/security_state.sqlite');

$failures = [];

$bucket = 'tenant::user::chat/message';
$a = $repo->consumeRateLimit($bucket, 2, 60);
$b = $repo->consumeRateLimit($bucket, 2, 60);
$c = $repo->consumeRateLimit($bucket, 2, 60);
if (!(bool) ($a['ok'] ?? false) || !(bool) ($b['ok'] ?? false) || (bool) ($c['ok'] ?? true)) {
    $failures[] = 'Rate limit central should allow first two and block third hit.';
}

$nonce = 'nonce-' . time();
$first = $repo->rememberReplayNonce('whatsapp', $nonce, 600);
$second = $repo->rememberReplayNonce('whatsapp', $nonce, 600);
if (!$first || $second) {
    $failures[] = 'Replay nonce should be accepted once and blocked on duplicate.';
}

$ok = empty($failures);
echo json_encode(['ok' => $ok, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

