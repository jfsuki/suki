<?php

declare(strict_types=1);

$failures = [];
$results = [];
$tmpRoot = __DIR__ . '/tmp';
$tmpDir = $tmpRoot . '/qa_gate_incremental_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);

try {
    $passScript = $tmpDir . '/pass_case.php';
    $warningScript = $tmpDir . '/warning_case.php';
    $failScript = $tmpDir . '/fail_case.php';

    file_put_contents($passScript, "<?php echo json_encode(['ok' => true, 'summary' => ['failed' => 0, 'passed' => 1]], JSON_UNESCAPED_SLASHES), PHP_EOL;");
    file_put_contents($warningScript, "<?php echo 'plain ok', PHP_EOL;");
    file_put_contents($failScript, "<?php fwrite(STDERR, 'boom' . PHP_EOL); exit(1);");

    $repoRoot = dirname(__DIR__, 2);

    $pass = runGate('incremental protocol pass', 'php ' . str_replace('\\', '/', $passScript), $repoRoot, $passExit);
    $passJson = json_decode($pass, true);
    $results[] = ['case' => 'pass', 'exit_code' => $passExit, 'report' => $passJson];
    if ($passExit !== 0) {
        $failures[] = 'PASS case should exit 0.';
    }
    if (!is_array($passJson) || ($passJson['status'] ?? '') !== 'PASS') {
        $failures[] = 'PASS case should return status PASS.';
    }
    if (empty($passJson['evidence'][0]['summary'] ?? '')) {
        $failures[] = 'PASS case should include short evidence.';
    }

    $warning = runGate('incremental protocol warning', 'php ' . str_replace('\\', '/', $warningScript), $repoRoot, $warningExit);
    $warningJson = json_decode($warning, true);
    $results[] = ['case' => 'pass_with_warning', 'exit_code' => $warningExit, 'report' => $warningJson];
    if ($warningExit !== 0) {
        $failures[] = 'PASS_WITH_WARNING case should still exit 0.';
    }
    if (!is_array($warningJson) || ($warningJson['status'] ?? '') !== 'PASS_WITH_WARNING') {
        $failures[] = 'Warning case should return status PASS_WITH_WARNING.';
    }
    if (empty($warningJson['warnings'])) {
        $failures[] = 'Warning case should expose warnings.';
    }

    $blocking = runGate('incremental protocol fail', 'php ' . str_replace('\\', '/', $failScript), $repoRoot, $blockingExit);
    $blockingJson = json_decode($blocking, true);
    $results[] = ['case' => 'fail_blocking', 'exit_code' => $blockingExit, 'report' => $blockingJson];
    if ($blockingExit === 0) {
        $failures[] = 'FAIL_BLOCKING case should exit non-zero.';
    }
    if (!is_array($blockingJson) || ($blockingJson['status'] ?? '') !== 'FAIL_BLOCKING') {
        $failures[] = 'Fail case should return status FAIL_BLOCKING.';
    }
    if (empty($blockingJson['failed_steps'])) {
        $failures[] = 'Fail case should list failed_steps.';
    }
} catch (Throwable $e) {
    $failures[] = 'qa_gate_incremental_test should not throw: ' . $e->getMessage();
}

rrmdir($tmpDir);

$ok = $failures === [];
echo json_encode([
    'ok' => $ok,
    'results' => $results,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

function runGate(string $changeLabel, string $testCommand, string $cwd, ?int &$exitCode = null): string
{
    $command = 'php framework/scripts/qa_gate.php incremental --change="' . addcslashes($changeLabel, '"') . '" --test="' . addcslashes($testCommand, '"') . '"';
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        $exitCode = 1;
        return '';
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    return trim((string) $stdout . (string) $stderr);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        @rmdir($dir);
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}
