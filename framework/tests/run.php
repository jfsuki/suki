<?php
// framework/tests/run.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\UnitTestRunner;

$cleanupPre = runTmpCleanup('pre');
$cleanupPost = [
    'ok' => true,
    'phase' => 'post',
    'skipped' => true,
];
$runner = new UnitTestRunner();
$runnerException = null;

try {
    $result = $runner->run();
} catch (Throwable $exception) {
    $runnerException = $exception;
    $result = [
        'ok' => false,
        'summary' => [
            'ok' => false,
        ],
        'errors' => [
            $exception->getMessage(),
        ],
        'exception' => get_class($exception),
    ];
} finally {
    $cleanupPost = runTmpCleanup('post');
}

$result['tmp_cleanup'] = [
    'pre' => $cleanupPre,
    'post' => $cleanupPost,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($runnerException instanceof Throwable ? 1 : 0);

function runTmpCleanup(string $phase): array
{
    $script = __DIR__ . '/../scripts/cleanup_test_tmp.php';
    if (!is_file($script)) {
        return [
            'ok' => false,
            'phase' => $phase,
            'error' => 'cleanup_script_missing',
        ];
    }

    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $command = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --apply ' . escapeshellarg('--phase=' . $phase);
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'phase' => $phase,
            'error' => 'cleanup_process_start_failed',
        ];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $decoded = json_decode(trim($stdout), true);
    if (is_array($decoded)) {
        $decoded['exit_code'] = $exitCode;
        if ($stderr !== '') {
            $decoded['stderr'] = trim($stderr);
        }
        return $decoded;
    }

    return [
        'ok' => $exitCode === 0,
        'phase' => $phase,
        'exit_code' => $exitCode,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
}
