<?php
declare(strict_types=1);

// framework/scripts/qa_gate.php
// Usage:
//   php framework/scripts/qa_gate.php pre
//   php framework/scripts/qa_gate.php post

const STATUS_OK = 0;
const STATUS_FAIL = 1;

$mode = strtolower(trim((string) ($argv[1] ?? 'post')));
if (!in_array($mode, ['pre', 'post'], true)) {
    fwrite(STDERR, "Invalid mode. Use: pre | post" . PHP_EOL);
    exit(STATUS_FAIL);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Cannot resolve framework root." . PHP_EOL);
    exit(STATUS_FAIL);
}
$repo = realpath($root . '/..');
if ($repo === false) {
    fwrite(STDERR, "Cannot resolve repo root." . PHP_EOL);
    exit(STATUS_FAIL);
}

$steps = [
    ['name' => 'run', 'cmd' => 'php framework/tests/run.php', 'parser' => 'run'],
    ['name' => 'db_health', 'cmd' => 'php framework/tests/db_health.php', 'parser' => 'db_health'],
];
if ($mode === 'post') {
    $steps = [
        ['name' => 'run', 'cmd' => 'php framework/tests/run.php', 'parser' => 'run'],
        ['name' => 'chat_acid', 'cmd' => 'php framework/tests/chat_acid.php', 'parser' => 'chat_acid'],
        ['name' => 'chat_golden', 'cmd' => 'php framework/tests/chat_golden.php', 'parser' => 'chat_golden'],
        ['name' => 'chat_real_20', 'cmd' => 'php framework/tests/chat_real_20.php', 'parser' => 'chat_real_20'],
        ['name' => 'db_health', 'cmd' => 'php framework/tests/db_health.php', 'parser' => 'db_health'],
    ];
}

$report = [
    'mode' => $mode,
    'ok' => true,
    'steps' => [],
    'failed_steps' => [],
    'generated_at' => date('c'),
];

foreach ($steps as $step) {
    $cmd = $step['cmd'];
    $output = runCommand($cmd, $repo, $exitCode);
    $ok = ($exitCode === 0) && parseStepOutput($step['parser'], $output);

    $entry = [
        'name' => $step['name'],
        'command' => $cmd,
        'exit_code' => $exitCode,
        'ok' => $ok,
    ];
    if (!$ok) {
        $entry['output'] = trim($output);
        $report['ok'] = false;
        $report['failed_steps'][] = $step['name'];
    }
    $report['steps'][] = $entry;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? STATUS_OK : STATUS_FAIL);

function runCommand(string $command, string $cwd, ?int &$exitCode = null): string
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        $exitCode = 1;
        return 'Failed to start process: ' . $command;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    return (string) $stdout . (string) $stderr;
}

function parseStepOutput(string $parser, string $output): bool
{
    $json = parseLastJsonObject($output);
    if (!is_array($json)) {
        return false;
    }

    if ($parser === 'run') {
        $summary = $json['summary'] ?? null;
        return is_array($summary)
            && ((int) ($summary['failed'] ?? 1) === 0);
    }

    if ($parser === 'chat_acid') {
        return ((int) ($json['failed'] ?? 1) === 0);
    }

    if ($parser === 'chat_golden') {
        $summary = $json['summary'] ?? null;
        return is_array($summary) && (($summary['ok'] ?? false) === true);
    }

    if ($parser === 'chat_real_20') {
        $summary = $json['summary'] ?? null;
        return is_array($summary) && (($summary['ok'] ?? false) === true);
    }

    if ($parser === 'db_health') {
        return (($json['ok'] ?? false) === true);
    }

    return false;
}

function parseLastJsonObject(string $output): ?array
{
    $output = trim($output);
    if ($output === '') {
        return null;
    }

    $candidate = $output;
    $decoded = json_decode($candidate, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strrpos($output, '{');
    if ($start === false) {
        return null;
    }
    $candidate = substr($output, $start);
    $decoded = json_decode($candidate, true);
    return is_array($decoded) ? $decoded : null;
}
