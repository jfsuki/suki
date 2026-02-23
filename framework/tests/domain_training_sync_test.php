<?php
// framework/tests/domain_training_sync_test.php

declare(strict_types=1);

$php = PHP_BINARY ?: 'php';
$script = dirname(__DIR__) . '/scripts/sync_domain_training.php';

if (!is_file($script)) {
    fwrite(STDERR, "Falta script de sync: {$script}\n");
    exit(1);
}

$cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --check';
$output = [];
$code = 0;
exec($cmd, $output, $code);

if ($code !== 0) {
    fwrite(STDERR, "FAIL: drift detectado entre domain_playbooks y conversation_training_base.\n");
    if (!empty($output)) {
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
    }
    exit(1);
}

echo "PASS: domain/training sync sin drift\n";
exit(0);
