<?php
// framework/tests/strict_failure_report.php
// Builds a strict-enforcement failure report by joining chat test outputs with AgentOps telemetry.

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tmpDir = __DIR__ . '/tmp';
$goldenPath = $tmpDir . '/chat_golden_result.json';
$realPath = $tmpDir . '/chat_real_100_result.json';
$telemetryDir = $root . '/project/storage/tenants/default/telemetry';
$outputPath = $tmpDir . '/strict_failure_report.json';

$report = [
    'generated_at' => date('c'),
    'inputs' => [
        'chat_golden' => is_file($goldenPath),
        'chat_real_100' => is_file($realPath),
        'telemetry_dir' => $telemetryDir,
    ],
    'summary' => [
        'total_failures' => 0,
        'top_causes' => [],
    ],
    'failures' => [],
];

$failures = [];
$golden = readJsonIfExists($goldenPath);
if ($golden !== null) {
    $failures = array_merge($failures, collectGoldenFailures($golden));
}
$real = readJsonIfExists($realPath);
if ($real !== null) {
    $failures = array_merge($failures, collectRealFailures($real));
}

if (empty($failures)) {
    writeJson($outputPath, $report);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$telemetry = loadTelemetryRows($telemetryDir);
$causeCounter = [];

foreach ($failures as $failure) {
    $matched = findTelemetryMatch($telemetry, $failure['session_id'], $failure['message']);
    $gateResults = is_array($matched['gate_results'] ?? null) ? (array) $matched['gate_results'] : [];
    $failedGates = [];
    foreach ($gateResults as $gate) {
        if (!is_array($gate)) {
            continue;
        }
        if (!empty($gate['required']) && empty($gate['passed'])) {
            $failedGates[] = [
                'name' => (string) ($gate['name'] ?? 'unknown_gate'),
                'reason' => (string) ($gate['reason'] ?? ''),
            ];
        }
    }

    $violations = is_array($matched['contract_violations'] ?? null) ? (array) $matched['contract_violations'] : [];
    $evidenceMissing = [];
    foreach ($violations as $violation) {
        $violation = (string) $violation;
        if (str_starts_with($violation, 'minimum_evidence_missing:')) {
            $evidenceMissing[] = substr($violation, strlen('minimum_evidence_missing:'));
        }
    }

    $primaryCause = 'unknown';
    if (!empty($violations)) {
        $primaryCause = (string) $violations[0];
    } elseif (!empty($failedGates)) {
        $primaryCause = 'gate_failed:' . (string) $failedGates[0]['name'];
    }
    $causeCounter[$primaryCause] = (int) ($causeCounter[$primaryCause] ?? 0) + 1;

    $report['failures'][] = [
        'suite' => $failure['suite'],
        'conversation' => $failure['conversation'],
        'step' => $failure['step'],
        'session_id' => $failure['session_id'],
        'message' => $failure['message'],
        'reply' => $failure['reply'],
        'route_path' => (string) ($matched['route_path'] ?? 'unknown'),
        'gate_decision' => (string) ($matched['gate_decision'] ?? 'unknown'),
        'gate_failed' => $failedGates,
        'evidence_missing' => array_values(array_unique($evidenceMissing)),
        'action_blocked' => str_contains(mb_strtolower($failure['reply'], 'UTF-8'), 'bloqueado por contrato'),
        'endpoint' => 'chat/local',
        'primary_cause' => $primaryCause,
    ];
}

arsort($causeCounter);
$topCauses = [];
foreach (array_slice($causeCounter, 0, 5, true) as $cause => $count) {
    $topCauses[] = [
        'cause' => $cause,
        'count' => $count,
    ];
}

$report['summary']['total_failures'] = count($report['failures']);
$report['summary']['top_causes'] = $topCauses;

writeJson($outputPath, $report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

/**
 * @return array<string, mixed>|null
 */
function readJsonIfExists(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $golden
 * @return array<int, array<string, mixed>>
 */
function collectGoldenFailures(array $golden): array
{
    $results = is_array($golden['results'] ?? null) ? (array) $golden['results'] : [];
    $runId = trim((string) (($golden['summary']['run_id'] ?? '') ?: ''));
    $rows = [];

    foreach ($results as $row) {
        if (!is_array($row) || !empty($row['ok'])) {
            continue;
        }
        $step = (int) ($row['step'] ?? 0);
        $session = resolveGoldenSessionId($runId, $step);
        $rows[] = [
            'suite' => 'chat_golden',
            'conversation' => 'golden',
            'step' => $step,
            'session_id' => $session,
            'message' => (string) ($row['message'] ?? ''),
            'reply' => (string) ($row['reply'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @param array<string, mixed> $real
 * @return array<int, array<string, mixed>>
 */
function collectRealFailures(array $real): array
{
    $results = is_array($real['results'] ?? null) ? (array) $real['results'] : [];
    $runId = trim((string) (($real['summary']['run_id'] ?? '') ?: ''));
    $rows = [];

    foreach ($results as $i => $conversation) {
        if (!is_array($conversation) || !empty($conversation['ok'])) {
            continue;
        }
        $steps = is_array($conversation['steps'] ?? null) ? (array) $conversation['steps'] : [];
        $sessionId = 'real100_session_' . ((int) $i + 1) . '_' . $runId;
        $name = (string) ($conversation['name'] ?? ('conversation_' . ((int) $i + 1)));
        foreach ($steps as $stepRow) {
            if (!is_array($stepRow) || !empty($stepRow['ok'])) {
                continue;
            }
            $rows[] = [
                'suite' => 'chat_real_100',
                'conversation' => $name,
                'step' => (int) ($stepRow['step'] ?? 0),
                'session_id' => $sessionId,
                'message' => (string) ($stepRow['message'] ?? ''),
                'reply' => (string) ($stepRow['reply'] ?? ''),
            ];
        }
    }

    return $rows;
}

function resolveGoldenSessionId(string $runId, int $step): string
{
    if ($runId === '') {
        return 'unknown';
    }
    if ($step >= 1 && $step <= 12) {
        return 'golden_session_' . $runId;
    }
    if ($step >= 13 && $step <= 17) {
        return 'golden_correction_sess_' . $runId;
    }
    if ($step >= 18 && $step <= 19) {
        return 'golden_integration_sess_' . $runId;
    }
    if ($step >= 20 && $step <= 22) {
        return 'golden_unknown_sess_' . $runId;
    }
    return 'golden_workflow_sess_' . $runId;
}

/**
 * @return array<int, array<string, mixed>>
 */
function loadTelemetryRows(string $telemetryDir): array
{
    if (!is_dir($telemetryDir)) {
        return [];
    }
    $files = glob($telemetryDir . '/*.log.jsonl');
    if (!is_array($files) || empty($files)) {
        return [];
    }
    sort($files);

    $rows = [];
    foreach ($files as $file) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
    }
    return $rows;
}

/**
 * @param array<int, array<string, mixed>> $telemetry
 * @return array<string, mixed>
 */
function findTelemetryMatch(array $telemetry, string $sessionId, string $message): array
{
    $found = [];
    foreach ($telemetry as $row) {
        $rowSession = trim((string) ($row['session_id'] ?? ''));
        if ($rowSession !== $sessionId) {
            continue;
        }
        $rowMessage = trim((string) ($row['message'] ?? ''));
        if ($rowMessage !== $message) {
            continue;
        }
        $found = $row;
    }
    return $found;
}

/**
 * @param array<string, mixed> $payload
 */
function writeJson(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }
    @file_put_contents($path, $json . PHP_EOL);
}

