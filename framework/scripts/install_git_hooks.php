<?php
declare(strict_types=1);

// framework/scripts/install_git_hooks.php
// Sets local hooks path to .githooks and ensures executable bit where possible.

$repo = realpath(__DIR__ . '/..' . '/..');
if ($repo === false) {
    fwrite(STDERR, "Cannot resolve repository root.\n");
    exit(1);
}

$hooksDir = $repo . '/.githooks';
if (!is_dir($hooksDir)) {
    fwrite(STDERR, "Missing .githooks directory.\n");
    exit(1);
}

$cmd = 'git config core.hooksPath .githooks';
$code = 0;
$out = runCommand($cmd, $repo, $code);
if ($code !== 0) {
    fwrite(STDERR, "Failed to set git hooks path.\n" . $out . "\n");
    exit(1);
}

foreach (['pre-commit', 'pre-merge-commit', 'pre-push'] as $hook) {
    $path = $hooksDir . '/' . $hook;
    if (is_file($path)) {
        @chmod($path, 0755);
    }
}

echo "Git hooks installed (core.hooksPath=.githooks)\n";
exit(0);

function runCommand(string $command, string $cwd, ?int &$exitCode = null): string
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        $exitCode = 1;
        return 'Failed to start: ' . $command;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    return (string) $stdout . (string) $stderr;
}
