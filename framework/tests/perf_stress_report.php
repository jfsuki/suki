<?php
// framework/tests/perf_stress_report.php

declare(strict_types=1);

/**
 * Run dedicated stress suites and aggregate p95/p99 report.
 * This script is intended for on-demand performance gate (not default fast unit gate).
 */

$chatCmd = 'php framework/tests/chat_stress.php';
$channelsCmd = 'php framework/tests/channels_stress.php';
$repoRoot = realpath(__DIR__ . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "No se pudo resolver framework root\n");
    exit(1);
}
$cwd = dirname($repoRoot);

$chatOut = runCommand($chatCmd, $cwd, $chatExit);
$chatJson = parseLastJson($chatOut);

$channelsOut = runCommand($channelsCmd, $cwd, $channelsExit);
$channelsJson = parseLastJson($channelsOut);

$failures = [];
if ($chatExit !== 0 || !is_array($chatJson)) {
    $failures[] = 'chat_stress failed';
}
if ($channelsExit !== 0 || !is_array($channelsJson)) {
    $failures[] = 'channels_stress failed';
}

$result = [
    'ok' => empty($failures),
    'generated_at' => date('c'),
    'chat_stress' => is_array($chatJson) ? $chatJson : ['raw' => trim($chatOut)],
    'channels_stress' => is_array($channelsJson) ? $channelsJson : ['raw' => trim($channelsOut)],
    'summary' => [
        'chat_p95_ms' => (int) (($chatJson['metrics']['p95_latency_ms'] ?? 0)),
        'chat_p99_ms' => (int) (($chatJson['metrics']['p99_latency_ms'] ?? 0)),
        'channels_p95_ms' => (int) (($channelsJson['metrics']['global']['p95_latency_ms'] ?? 0)),
        'channels_p99_ms' => (int) (($channelsJson['metrics']['global']['p99_latency_ms'] ?? 0)),
    ],
    'failures' => $failures,
];

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
$reportPath = $tmpDir . '/perf_stress_report.json';
file_put_contents($reportPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$result['report'] = $reportPath;

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

function runCommand(string $cmd, string $cwd, ?int &$exitCode = null): string
{
    $spec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $spec, $pipes, $cwd);
    if (!is_resource($proc)) {
        $exitCode = 1;
        return '';
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    return (string) $stdout . (string) $stderr;
}

function parseLastJson(string $output): ?array
{
    $candidate = trim($output);
    if ($candidate === '') {
        return null;
    }
    $decoded = json_decode($candidate, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $pos = strrpos($candidate, '{');
    if ($pos === false) {
        return null;
    }
    $decoded = json_decode(substr($candidate, $pos), true);
    return is_array($decoded) ? $decoded : null;
}

