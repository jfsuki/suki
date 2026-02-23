<?php
declare(strict_types=1);

// framework/scripts/codex_self_check.php
// Usage:
//   php framework/scripts/codex_self_check.php
//   php framework/scripts/codex_self_check.php --strict

const STATUS_OK = 0;
const STATUS_FAIL = 1;

$strict = in_array('--strict', $argv, true);

$repoRoot = realpath(__DIR__ . '/..' . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Cannot resolve repository root." . PHP_EOL);
    exit(STATUS_FAIL);
}

$requiredFiles = [
    'AGENTS.md',
    'framework/docs/INDEX.md',
    'framework/docs/PROJECT_MEMORY.md',
    'framework/docs/PROJECT_MEMORY_CANONICAL.md',
    'framework/docs/CODEX_SELF_CHECKLIST.md',
    'framework/docs/AGENT_SKILLS_MATRIX.md',
    'framework/docs/DOMAIN_PLAYBOOKS_PROMPT_BASE.md',
    'framework/contracts/agents/WORKING_MEMORY_SCHEMA.json',
    'framework/contracts/agents/conversation_training_base.json',
    'framework/contracts/agents/domain_playbooks.json',
    'project/contracts/knowledge/domain_playbooks.json',
];

$missingFiles = [];
foreach ($requiredFiles as $file) {
    $abs = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
    if (!is_file($abs)) {
        $missingFiles[] = $file;
    }
}

$jsonCheck = validateJsonFiles($repoRoot);
$tempPolicy = checkTempArtifactPolicy($repoRoot);
$hookCheck = checkPrePushHook($repoRoot);
$backupCheck = checkBackupPolicy($repoRoot);

$report = [
    'ok' => true,
    'strict' => $strict,
    'generated_at' => date('c'),
    'required_files' => [
        'ok' => count($missingFiles) === 0,
        'missing' => $missingFiles,
    ],
    'json_validation' => $jsonCheck,
    'temp_artifact_policy' => $tempPolicy,
    'git_hooks' => $hookCheck,
    'backup_policy' => $backupCheck,
];

if (!empty($missingFiles) || !$jsonCheck['ok'] || !$tempPolicy['ok'] || !$hookCheck['ok'] || !$backupCheck['ok']) {
    $report['ok'] = false;
}

if ($strict) {
    $qa = runQaPreCheck($repoRoot);
    $report['qa_pre'] = $qa;
    if (!$qa['ok']) {
        $report['ok'] = false;
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? STATUS_OK : STATUS_FAIL);

function validateJsonFiles(string $repoRoot): array
{
    $errors = [];
    $checked = 0;

    $excludedDirs = [
        '.git',
        'vendor',
        'node_modules',
        'project/storage',
        'framework/tests/tmp',
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $rel = ltrim(str_replace(str_replace('\\', '/', $repoRoot), '', $path), '/');

        if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'json') {
            continue;
        }

        if (isExcluded($rel, $excludedDirs)) {
            continue;
        }

        $checked++;
        $raw = file_get_contents($file->getPathname());
        if ($raw === false) {
            $errors[] = ['file' => $rel, 'error' => 'cannot_read'];
            continue;
        }

        json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = [
                'file' => $rel,
                'error' => json_last_error_msg(),
            ];
        }
    }

    return [
        'ok' => count($errors) === 0,
        'checked_files' => $checked,
        'errors' => $errors,
    ];
}

function checkTempArtifactPolicy(string $repoRoot): array
{
    $testsDir = $repoRoot . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'tests';
    if (!is_dir($testsDir)) {
        return ['ok' => false, 'error' => 'framework/tests not found'];
    }

    $violations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $name = $file->getFilename();
        if (!preg_match('/_result\.json$/', $name)) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        if (strpos($path, '/framework/tests/tmp/') === false) {
            $violations[] = str_replace('\\', '/', substr($path, strlen(str_replace('\\', '/', $repoRoot)) + 1));
        }
    }

    return [
        'ok' => count($violations) === 0,
        'violations' => $violations,
    ];
}

function checkPrePushHook(string $repoRoot): array
{
    $hookPath = $repoRoot . DIRECTORY_SEPARATOR . '.githooks' . DIRECTORY_SEPARATOR . 'pre-push';
    if (!is_file($hookPath)) {
        return ['ok' => false, 'error' => 'pre-push hook not found'];
    }

    $content = file_get_contents($hookPath);
    if (!is_string($content)) {
        return ['ok' => false, 'error' => 'cannot read pre-push hook'];
    }

    $hasQaPost = strpos($content, 'framework/scripts/qa_gate.php post') !== false;
    return [
        'ok' => $hasQaPost,
        'contains_qa_post' => $hasQaPost,
    ];
}

function checkBackupPolicy(string $repoRoot): array
{
    $manifestPath = $repoRoot . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
        return [
            'ok' => false,
            'error' => 'backup manifest not found',
            'required' => 'run php framework/scripts/db_backup.php',
        ];
    }

    $raw = file_get_contents($manifestPath);
    if (!is_string($raw) || trim($raw) === '') {
        return [
            'ok' => false,
            'error' => 'backup manifest empty',
        ];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [
            'ok' => false,
            'error' => 'backup manifest invalid JSON',
        ];
    }

    $last = (string) ($data['last_backup_at'] ?? '');
    $ts = $last !== '' ? strtotime($last) : false;
    if ($ts === false) {
        return [
            'ok' => false,
            'error' => 'last_backup_at missing/invalid',
        ];
    }

    $ageHours = (time() - $ts) / 3600;
    $ok = $ageHours <= 24;

    return [
        'ok' => $ok,
        'last_backup_at' => $last,
        'age_hours' => round($ageHours, 2),
        'max_age_hours' => 24,
        'required' => 'run php framework/scripts/db_backup.php if stale',
    ];
}

function runQaPreCheck(string $repoRoot): array
{
    $qaScript = $repoRoot . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'qa_gate.php';
    if (!is_file($qaScript)) {
        return ['ok' => false, 'error' => 'qa_gate.php missing'];
    }

    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($qaScript) . ' pre';
    $output = runCommand($cmd, $repoRoot, $exitCode);

    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'command' => $cmd,
        'output' => trim($output),
    ];
}

function runCommand(string $command, string $cwd, ?int &$exitCode = null): string
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($proc)) {
        $exitCode = 1;
        return 'failed_to_start_process';
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);
    return (string) $stdout . (string) $stderr;
}

function isExcluded(string $relativePath, array $excludedDirs): bool
{
    $path = str_replace('\\', '/', $relativePath);
    foreach ($excludedDirs as $dir) {
        $prefix = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        if (strpos($path, $prefix) === 0) {
            return true;
        }
    }
    return false;
}
