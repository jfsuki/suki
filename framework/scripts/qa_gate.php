<?php
declare(strict_types=1);

// framework/scripts/qa_gate.php
// Usage:
//   php framework/scripts/qa_gate.php pre
//   php framework/scripts/qa_gate.php post
//   php framework/scripts/qa_gate.php incremental --change="short label" --test="php framework/tests/some_test.php"

const STATUS_OK = 0;
const STATUS_FAIL = 1;
const GATE_STATUS_PASS = 'PASS';
const GATE_STATUS_PASS_WITH_WARNING = 'PASS_WITH_WARNING';
const GATE_STATUS_FAIL_BLOCKING = 'FAIL_BLOCKING';

$mode = strtolower(trim((string) ($argv[1] ?? 'post')));
$options = parseCliOptions(array_slice($argv, 2));
if (!in_array($mode, ['pre', 'post', 'incremental'], true)) {
    fwrite(STDERR, "Invalid mode. Use: pre | post | incremental" . PHP_EOL);
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
    $includeKpiGate = (string) (getenv('QA_INCLUDE_KPI_GATE') ?: '1') === '1';
    $steps = [
        ['name' => 'run', 'cmd' => 'php framework/tests/run.php', 'parser' => 'run'],
        ['name' => 'chat_acid', 'cmd' => 'php framework/tests/chat_acid.php', 'parser' => 'chat_acid'],
        ['name' => 'chat_golden', 'cmd' => 'php framework/tests/chat_golden.php', 'parser' => 'chat_golden'],
        ['name' => 'chat_real_20', 'cmd' => 'php framework/tests/chat_real_20.php', 'parser' => 'chat_real_20'],
        ['name' => 'db_health', 'cmd' => 'php framework/tests/db_health.php', 'parser' => 'db_health'],
    ];
    if ($includeKpiGate || (string) (getenv('QA_INCLUDE_CHAT_REAL_100') ?: '0') === '1') {
        $steps[] = ['name' => 'chat_real_100', 'cmd' => 'php framework/tests/chat_real_100.php', 'parser' => 'chat_real_100'];
    }
    if ($includeKpiGate) {
        $steps[] = ['name' => 'conversation_kpi_gate', 'cmd' => 'php framework/tests/conversation_kpi_gate.php', 'parser' => 'conversation_kpi_gate'];
    }
    if ((string) (getenv('QA_INCLUDE_STRESS') ?: '0') === '1') {
        $steps[] = ['name' => 'perf_stress', 'cmd' => 'php framework/tests/perf_stress_report.php', 'parser' => 'perf_stress'];
    }
    if ((string) (getenv('QA_INCLUDE_LLM_SMOKE') ?: '0') === '1') {
        $steps[] = ['name' => 'llm_smoke', 'cmd' => 'php framework/tests/llm_smoke.php', 'parser' => 'llm_smoke'];
    }
    if ((string) (getenv('QA_INCLUDE_LLM_GEMINI_SMOKE') ?: '0') === '1') {
        $steps[] = ['name' => 'llm_gemini_staging_smoke', 'cmd' => 'php framework/tests/llm_gemini_staging_smoke.php', 'parser' => 'llm_smoke'];
    }
}
if ($mode === 'incremental') {
    $steps = buildIncrementalSteps($options);
}

$report = [
    'mode' => $mode,
    'qa_agent' => [
        'name' => 'qa_gate',
        'protocol' => 'change_minimum -> minimal_test -> review -> evidence -> next_change',
    ],
    'status' => GATE_STATUS_PASS,
    'ok' => true,
    'steps' => [],
    'failed_steps' => [],
    'warning_steps' => [],
    'warnings' => [],
    'evidence' => [],
    'generated_at' => date('c'),
];
if ($mode === 'incremental') {
    $report['change_label'] = trim((string) ($options['change'] ?? getenv('QA_INCREMENTAL_CHANGE') ?: ''));
    $report['post_change_audit'] = [
        'required_sequence' => ['change_minimum', 'minimal_test', 'review', 'evidence'],
        'minimal_test_required' => true,
        'review_required' => true,
        'evidence_required' => true,
    ];
}

if ($mode === 'incremental') {
    $changeLabel = trim((string) ($report['change_label'] ?? ''));
    if ($changeLabel === '') {
        $report['status'] = GATE_STATUS_FAIL_BLOCKING;
        $report['ok'] = false;
        $report['warnings'][] = 'Missing required --change label for incremental audit.';
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(STATUS_FAIL);
    }
    if ($steps === []) {
        $report['status'] = GATE_STATUS_FAIL_BLOCKING;
        $report['ok'] = false;
        $report['warnings'][] = 'Missing required --test command for incremental audit.';
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(STATUS_FAIL);
    }
}

foreach ($steps as $step) {
    $cmd = $step['cmd'];
    $output = runCommand($cmd, $repo, $exitCode);
    $parser = (string) ($step['parser'] ?? detectParserFromCommand($cmd));
    $evaluation = evaluateStepResult($parser, $output, $exitCode);

    $entry = [
        'name' => $step['name'],
        'command' => $cmd,
        'exit_code' => $exitCode,
        'status' => $evaluation['status'],
        'ok' => $evaluation['status'] !== GATE_STATUS_FAIL_BLOCKING,
    ];
    if ($evaluation['evidence'] !== '') {
        $entry['evidence'] = $evaluation['evidence'];
        $report['evidence'][] = [
            'step' => $step['name'],
            'summary' => $evaluation['evidence'],
        ];
    }
    if ($evaluation['status'] === GATE_STATUS_FAIL_BLOCKING) {
        $entry['output'] = trim($output);
        $report['failed_steps'][] = $step['name'];
    } elseif ($evaluation['status'] === GATE_STATUS_PASS_WITH_WARNING) {
        $report['warning_steps'][] = $step['name'];
        if ($evaluation['warning'] !== '') {
            $report['warnings'][] = $step['name'] . ': ' . $evaluation['warning'];
        }
    }
    $report['steps'][] = $entry;
}

$report['status'] = computeGateStatus($report['steps']);
$report['ok'] = $report['status'] !== GATE_STATUS_FAIL_BLOCKING;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['status'] === GATE_STATUS_FAIL_BLOCKING ? STATUS_FAIL : STATUS_OK);

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

/**
 * @return array{status:string,evidence:string,warning:string}
 */
function evaluateStepResult(string $parser, string $output, int $exitCode): array
{
    if ($exitCode !== 0) {
        return [
            'status' => GATE_STATUS_FAIL_BLOCKING,
            'evidence' => shortenEvidence($output),
            'warning' => '',
        ];
    }

    $json = parseLastJsonObject($output);
    if ($json === null) {
        return [
            'status' => GATE_STATUS_PASS_WITH_WARNING,
            'evidence' => shortenEvidence($output),
            'warning' => 'Command passed without structured JSON evidence.',
        ];
    }

    if ($parser === 'run') {
        $summary = $json['summary'] ?? null;
        if (!is_array($summary)) {
            return warningResult($output, 'Run output missing summary.');
        }
        if ((int) ($summary['failed'] ?? 1) !== 0) {
            return failResult($output, 'run failed');
        }
        return passResult(
            'run passed=' . (int) ($summary['passed'] ?? 0) . ' failed=' . (int) ($summary['failed'] ?? 0)
        );
    }

    if ($parser === 'chat_acid') {
        if ((int) ($json['failed'] ?? 1) !== 0) {
            return failResult($output, 'chat_acid failed');
        }
        return passResult(
            'chat_acid passed=' . (int) ($json['passed'] ?? 0) . ' failed=' . (int) ($json['failed'] ?? 0)
        );
    }

    if ($parser === 'chat_golden') {
        $summary = $json['summary'] ?? null;
        if (!is_array($summary)) {
            return warningResult($output, 'chat_golden output missing summary.');
        }
        if (($summary['ok'] ?? false) !== true) {
            return failResult($output, 'chat_golden failed');
        }
        return passResult(
            'chat_golden passed=' . (int) ($summary['passed'] ?? 0) . ' failed=' . (int) ($summary['failed'] ?? 0)
        );
    }

    if ($parser === 'chat_real_20') {
        $summary = $json['summary'] ?? null;
        if (!is_array($summary)) {
            return warningResult($output, 'chat_real_20 output missing summary.');
        }
        if (($summary['ok'] ?? false) !== true) {
            return failResult($output, 'chat_real_20 failed');
        }
        return passResult(
            'chat_real_20 passed=' . (int) ($summary['passed'] ?? 0) . ' failed=' . (int) ($summary['failed'] ?? 0)
        );
    }

    if ($parser === 'chat_real_100') {
        $summary = $json['summary'] ?? null;
        if (!is_array($summary)) {
            return warningResult($output, 'chat_real_100 output missing summary.');
        }
        if (($summary['ok'] ?? false) !== true) {
            return failResult($output, 'chat_real_100 failed');
        }
        return passResult(
            'chat_real_100 passed=' . (int) ($summary['passed'] ?? 0) . ' failed=' . (int) ($summary['failed'] ?? 0)
        );
    }

    if ($parser === 'db_health') {
        if (($json['ok'] ?? false) !== true) {
            return failResult($output, 'db_health failed');
        }
        return passResult(
            'db_health driver=' . (string) ($json['driver'] ?? 'unknown') . ' ok=true'
        );
    }

    if ($parser === 'perf_stress') {
        return (($json['ok'] ?? false) === true)
            ? passResult('perf_stress ok=true')
            : failResult($output, 'perf_stress failed');
    }

    if ($parser === 'llm_smoke') {
        return (($json['ok'] ?? false) === true)
            ? passResult('llm_smoke ok=true')
            : failResult($output, 'llm_smoke failed');
    }

    if ($parser === 'conversation_kpi_gate') {
        return (($json['ok'] ?? false) === true)
            ? passResult('conversation_kpi_gate ok=true')
            : failResult($output, 'conversation_kpi_gate failed');
    }

    if (($json['ok'] ?? null) === true) {
        $summary = shortenEvidence($output);
        return passResult($summary !== '' ? $summary : 'ok=true');
    }
    $summary = $json['summary'] ?? null;
    if (is_array($summary) && ((int) ($summary['failed'] ?? 0) === 0)) {
        return passResult(shortenEvidence($output));
    }
    if (is_array($summary) && (($summary['ok'] ?? false) === true)) {
        return passResult(shortenEvidence($output));
    }

    return warningResult($output, 'Command passed but structured evidence was weak or unknown.');
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

function detectParserFromCommand(string $command): string
{
    $command = strtolower(trim($command));
    return match (true) {
        str_contains($command, 'framework/tests/run.php') => 'run',
        str_contains($command, 'framework/tests/chat_acid.php') => 'chat_acid',
        str_contains($command, 'framework/tests/chat_golden.php') => 'chat_golden',
        str_contains($command, 'framework/tests/chat_real_20.php') => 'chat_real_20',
        str_contains($command, 'framework/tests/chat_real_100.php') => 'chat_real_100',
        str_contains($command, 'framework/tests/db_health.php') => 'db_health',
        str_contains($command, 'framework/tests/perf_stress_report.php') => 'perf_stress',
        str_contains($command, 'framework/tests/llm_smoke.php') => 'llm_smoke',
        str_contains($command, 'framework/tests/conversation_kpi_gate.php') => 'conversation_kpi_gate',
        default => 'generic',
    };
}

/**
 * @param array<int,array{name:string,cmd:string,parser:string}> $steps
 */
function computeGateStatus(array $steps): string
{
    $hasWarning = false;
    foreach ($steps as $step) {
        $status = (string) ($step['status'] ?? GATE_STATUS_FAIL_BLOCKING);
        if ($status === GATE_STATUS_FAIL_BLOCKING) {
            return GATE_STATUS_FAIL_BLOCKING;
        }
        if ($status === GATE_STATUS_PASS_WITH_WARNING) {
            $hasWarning = true;
        }
    }

    return $hasWarning ? GATE_STATUS_PASS_WITH_WARNING : GATE_STATUS_PASS;
}

/**
 * @param array<string,mixed> $options
 * @return array<int,array{name:string,cmd:string,parser:string}>
 */
function buildIncrementalSteps(array $options): array
{
    $tests = listOptionValues($options, 'test');
    if ($tests === []) {
        $envTests = trim((string) (getenv('QA_INCREMENTAL_TESTS') ?: ''));
        if ($envTests !== '') {
            $tests = array_values(array_filter(array_map('trim', preg_split('/[;\r\n]+/', $envTests) ?: [])));
        }
    }

    $steps = [];
    foreach ($tests as $index => $command) {
        $steps[] = [
            'name' => 'minimal_test_' . ($index + 1),
            'cmd' => $command,
            'parser' => detectParserFromCommand($command),
        ];
    }

    return $steps;
}

/**
 * @param array<int,string> $args
 * @return array<string,mixed>
 */
function parseCliOptions(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $raw = substr($arg, 2);
        [$key, $value] = array_pad(explode('=', $raw, 2), 2, '1');
        $key = trim($key);
        if ($key === '') {
            continue;
        }
        if (array_key_exists($key, $options)) {
            if (!is_array($options[$key])) {
                $options[$key] = [$options[$key]];
            }
            $options[$key][] = $value;
            continue;
        }
        $options[$key] = $value;
    }

    return $options;
}

/**
 * @param array<string,mixed> $options
 * @return string[]
 */
function listOptionValues(array $options, string $key): array
{
    if (!array_key_exists($key, $options)) {
        return [];
    }
    $value = $options[$key];
    if (is_array($value)) {
        return array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $value)));
    }

    $single = trim((string) $value);
    return $single !== '' ? [$single] : [];
}

/**
 * @return array{status:string,evidence:string,warning:string}
 */
function passResult(string $evidence): array
{
    return [
        'status' => GATE_STATUS_PASS,
        'evidence' => shortenEvidence($evidence),
        'warning' => '',
    ];
}

/**
 * @return array{status:string,evidence:string,warning:string}
 */
function warningResult(string $output, string $warning): array
{
    return [
        'status' => GATE_STATUS_PASS_WITH_WARNING,
        'evidence' => shortenEvidence($output),
        'warning' => $warning,
    ];
}

/**
 * @return array{status:string,evidence:string,warning:string}
 */
function failResult(string $output, string $warning): array
{
    return [
        'status' => GATE_STATUS_FAIL_BLOCKING,
        'evidence' => shortenEvidence($output),
        'warning' => $warning,
    ];
}

function shortenEvidence(string $text): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }
    if (strlen($text) > 220) {
        $text = substr($text, 0, 220);
    }
    return $text;
}
