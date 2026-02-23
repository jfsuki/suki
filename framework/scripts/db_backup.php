<?php
declare(strict_types=1);

// framework/scripts/db_backup.php
// Creates a local backup bundle for SUKI runtime:
// - MySQL schema/data dump
// - project registry sqlite copy
// - manifest with metadata and freshness timestamp

const STATUS_OK = 0;
const STATUS_FAIL = 1;

$repoRoot = realpath(__DIR__ . '/..' . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Cannot resolve repository root." . PHP_EOL);
    exit(STATUS_FAIL);
}

$projectRoot = $repoRoot . DIRECTORY_SEPARATOR . 'project';
$envLoader = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env_loader.php';
if (!is_file($envLoader)) {
    fwrite(STDERR, "env_loader not found: {$envLoader}" . PHP_EOL);
    exit(STATUS_FAIL);
}
require_once $envLoader;

$driver = strtolower((string) getenv('DB_DRIVER'));
if ($driver === '') {
    $driver = 'mysql';
}

$backupRoot = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
$runId = date('Ymd_His');
$runDir = $backupRoot . DIRECTORY_SEPARATOR . $runId;
ensureDir($runDir);

$report = [
    'ok' => true,
    'run_id' => $runId,
    'driver' => $driver,
    'generated_at' => date('c'),
    'files' => [],
    'errors' => [],
];

if ($driver === 'mysql') {
    $dumpResult = backupMySql($runDir);
    $report['files'] = array_merge($report['files'], $dumpResult['files']);
    if (!$dumpResult['ok']) {
        $report['ok'] = false;
        $report['errors'] = array_merge($report['errors'], $dumpResult['errors']);
    }
} elseif ($driver === 'sqlite') {
    $report['errors'][] = 'sqlite backup not implemented in this script version';
    $report['ok'] = false;
} else {
    $report['errors'][] = 'unsupported DB_DRIVER: ' . $driver;
    $report['ok'] = false;
}

$registryPath = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meta' . DIRECTORY_SEPARATOR . 'project_registry.sqlite';
if (is_file($registryPath)) {
    $target = $runDir . DIRECTORY_SEPARATOR . 'project_registry.sqlite';
    if (@copy($registryPath, $target)) {
        $report['files'][] = rel($target, $repoRoot);
    } else {
        $report['ok'] = false;
        $report['errors'][] = 'failed to copy registry sqlite';
    }
}

$manifest = [
    'last_backup_at' => date('c'),
    'latest_run_id' => $runId,
    'latest_path' => rel($runDir, $repoRoot),
    'driver' => $driver,
    'ok' => $report['ok'],
    'files' => $report['files'],
    'errors' => $report['errors'],
];

$manifestPath = $backupRoot . DIRECTORY_SEPARATOR . 'manifest.json';
writeJson($manifestPath, $manifest);
$report['manifest'] = rel($manifestPath, $repoRoot);

cleanupOldBackups($backupRoot, 14);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? STATUS_OK : STATUS_FAIL);

function backupMySql(string $runDir): array
{
    $dbName = (string) getenv('DB_NAME');
    $dbUser = (string) getenv('DB_USER');
    $dbPass = (string) getenv('DB_PASS');
    $dbHost = (string) getenv('DB_HOST');
    $dbPort = (string) getenv('DB_PORT');

    $dbName = $dbName !== '' ? $dbName : 'suki_saas';
    $dbUser = $dbUser !== '' ? $dbUser : 'root';
    $dbHost = $dbHost !== '' ? $dbHost : '127.0.0.1';
    $dbPort = $dbPort !== '' ? $dbPort : '3306';

    $dumpExe = resolveMySqlDumpBinary();
    if ($dumpExe === null) {
        return [
            'ok' => false,
            'files' => [],
            'errors' => ['mysqldump binary not found'],
        ];
    }

    $dumpPath = $runDir . DIRECTORY_SEPARATOR . $dbName . '.sql';
    $args = [
        escapeshellarg($dumpExe),
        '--host=' . escapeshellarg($dbHost),
        '--port=' . escapeshellarg($dbPort),
        '--user=' . escapeshellarg($dbUser),
        '--single-transaction',
        '--triggers',
        '--routines',
        '--events',
        '--hex-blob',
        '--default-character-set=utf8mb4',
    ];

    if ($dbPass !== '') {
        $args[] = '--password=' . escapeshellarg($dbPass);
    }
    $args[] = escapeshellarg($dbName);

    $command = implode(' ', $args);
    $descriptors = [
        1 => ['file', $dumpPath, 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'files' => [],
            'errors' => ['failed to start mysqldump process'],
        ];
    }

    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || !is_file($dumpPath) || filesize($dumpPath) === 0) {
        return [
            'ok' => false,
            'files' => [],
            'errors' => ['mysqldump failed: ' . trim($stderr)],
        ];
    }

    return [
        'ok' => true,
        'files' => [rel($dumpPath, dirname(__DIR__, 2))],
        'errors' => [],
    ];
}

function resolveMySqlDumpBinary(): ?string
{
    $candidates = [
        getenv('MYSQLDUMP_BIN') ?: '',
        'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe',
        'C:/laragon/bin/mysql/mysql-9.4.0-winx64/bin/mysqldump.exe',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }
        $normalized = str_replace('\\', '/', $candidate);
        if (is_file($normalized)) {
            return $normalized;
        }
    }

    return null;
}

function ensureDir(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

function writeJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    @file_put_contents($path, $json);
}

function cleanupOldBackups(string $backupRoot, int $daysToKeep): void
{
    if (!is_dir($backupRoot)) {
        return;
    }

    $cutoff = time() - ($daysToKeep * 86400);
    $dirs = glob($backupRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
        $base = basename($dir);
        if (!preg_match('/^\d{8}_\d{6}$/', $base)) {
            continue;
        }
        if (filemtime($dir) !== false && filemtime($dir) < $cutoff) {
            rrmdir($dir);
        }
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function rel(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (strpos($path, $root . '/') === 0) {
        return substr($path, strlen($root) + 1);
    }
    return $path;
}
