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
$manifestPath = $backupRoot . DIRECTORY_SEPARATOR . 'manifest.json';
$policy = resolveBackupPolicy();
$runId = date('Ymd_His');
$runDir = $backupRoot . DIRECTORY_SEPARATOR . $runId;
ensureDir($runDir);

$report = [
    'ok' => true,
    'run_id' => $runId,
    'driver' => $driver,
    'generated_at' => date('c'),
    'policy' => $policy,
    'files' => [],
    'warnings' => [],
    'errors' => [],
];

if ($driver === 'mysql') {
    $dumpResult = backupMySql($runDir, $repoRoot, $policy);
    $report['files'] = array_merge($report['files'], $dumpResult['files']);
    $report['warnings'] = array_merge($report['warnings'], $dumpResult['warnings']);
    $report['backup_format'] = $dumpResult['format'];
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

$cleanup = [
    'deleted' => [],
    'kept' => [],
    'freed_bytes' => 0,
    'freed_mb' => 0.0,
    'warnings' => [],
    'skipped' => false,
];
if ($report['ok']) {
    $cleanup = cleanupOldBackups($backupRoot, $policy, $runId, $repoRoot);
} else {
    $cleanup['skipped'] = true;
    $cleanup['warnings'][] = 'cleanup_skipped_backup_not_ok';
}
$report['cleanup'] = $cleanup;
$report['warnings'] = array_merge($report['warnings'], $cleanup['warnings']);

$report['manifest_updated'] = false;
if ($report['ok']) {
    $manifest = [
        'last_backup_at' => date('c'),
        'latest_run_id' => $runId,
        'latest_path' => rel($runDir, $repoRoot),
        'driver' => $driver,
        'ok' => $report['ok'],
        'backup_format' => $report['backup_format'] ?? 'unknown',
        'policy' => $policy,
        'files' => $report['files'],
        'warnings' => $report['warnings'],
        'errors' => $report['errors'],
        'cleanup' => [
            'deleted' => $cleanup['deleted'],
            'kept' => $cleanup['kept'],
            'freed_bytes' => $cleanup['freed_bytes'],
            'freed_mb' => $cleanup['freed_mb'],
            'skipped' => $cleanup['skipped'],
        ],
    ];
    if (writeJson($manifestPath, $manifest)) {
        $report['manifest_updated'] = true;
    } else {
        $report['ok'] = false;
        $report['errors'][] = 'failed to write backup manifest';
    }
} else {
    $report['warnings'][] = 'manifest_not_updated_backup_not_ok';
}

$report['manifest'] = rel($manifestPath, $repoRoot);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? STATUS_OK : STATUS_FAIL);

function backupMySql(string $runDir, string $repoRoot, array $policy): array
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
            'warnings' => [],
            'format' => 'none',
            'errors' => ['mysqldump binary not found'],
        ];
    }

    $dumpPathTmp = $runDir . DIRECTORY_SEPARATOR . $dbName . '.sql.tmp';
    $dumpPath = $runDir . DIRECTORY_SEPARATOR . $dbName . '.sql';
    $gzipPath = $runDir . DIRECTORY_SEPARATOR . $dbName . '.sql.gz';
    $gzipTempPath = $gzipPath . '.tmp';
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
        1 => ['file', $dumpPathTmp, 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'files' => [],
            'warnings' => [],
            'format' => 'none',
            'errors' => ['failed to start mysqldump process'],
        ];
    }

    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || !is_file($dumpPathTmp) || filesize($dumpPathTmp) === 0) {
        @unlink($dumpPathTmp);
        return [
            'ok' => false,
            'files' => [],
            'warnings' => [],
            'format' => 'none',
            'errors' => ['mysqldump failed: ' . trim($stderr)],
        ];
    }

    $warnings = [];
    $format = 'sql';
    $backupFile = $dumpPath;

    if ((bool) ($policy['compress'] ?? true)) {
        $compression = compressFileToGzip($dumpPathTmp, $gzipTempPath);
        if ($compression['ok']) {
            if (!moveFile($gzipTempPath, $gzipPath)) {
                @unlink($gzipTempPath);
                if (!moveFile($dumpPathTmp, $dumpPath)) {
                    @unlink($dumpPathTmp);
                    return [
                        'ok' => false,
                        'files' => [],
                        'warnings' => [],
                        'format' => 'none',
                        'errors' => ['gzip compression succeeded but final file move failed and plain sql fallback could not be preserved'],
                    ];
                }
                $warnings[] = 'gzip_move_failed_plain_sql_kept';
            } else {
                @unlink($dumpPathTmp);
                $backupFile = $gzipPath;
                $format = 'sql.gz';
            }
        } else {
            @unlink($gzipTempPath);
            if (!moveFile($dumpPathTmp, $dumpPath)) {
                @unlink($dumpPathTmp);
                return [
                    'ok' => false,
                    'files' => [],
                    'warnings' => [],
                    'format' => 'none',
                    'errors' => ['compression failed and plain sql fallback could not be preserved: ' . $compression['error']],
                ];
            }
            $warnings[] = 'compression_failed_plain_sql_kept: ' . $compression['error'];
        }
    } else {
        if (!moveFile($dumpPathTmp, $dumpPath)) {
            @unlink($dumpPathTmp);
            return [
                'ok' => false,
                'files' => [],
                'warnings' => [],
                'format' => 'none',
                'errors' => ['failed to finalize plain sql backup'],
            ];
        }
    }

    return [
        'ok' => true,
        'files' => [rel($backupFile, $repoRoot)],
        'warnings' => $warnings,
        'format' => $format,
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

function writeJson(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json) !== false;
}

function cleanupOldBackups(string $backupRoot, array $policy, string $latestRunId, string $repoRoot): array
{
    $result = [
        'deleted' => [],
        'kept' => [],
        'freed_bytes' => 0,
        'freed_mb' => 0.0,
        'warnings' => [],
        'skipped' => false,
    ];

    if (!is_dir($backupRoot)) {
        return $result;
    }

    $dirs = glob($backupRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
    $runs = [];
    foreach ($dirs as $dir) {
        if (!preg_match('/^\d{8}_\d{6}$/', basename($dir))) {
            continue;
        }
        $runs[] = $dir;
    }

    usort($runs, static function (string $left, string $right): int {
        return strcmp(basename($right), basename($left));
    });

    $retentionRuns = max(1, (int) ($policy['retention_runs'] ?? 1));
    $retentionDays = max(0, (int) ($policy['retention_days'] ?? 0));
    $cutoff = $retentionDays > 0 ? time() - ($retentionDays * 86400) : null;

    foreach ($runs as $index => $dir) {
        $base = basename($dir);
        $mtime = filemtime($dir);
        $isNewest = $index === 0;
        $withinRunLimit = $index < $retentionRuns;
        $withinDayLimit = $cutoff === null || ($mtime !== false && $mtime >= $cutoff);
        $shouldKeep = $base === $latestRunId || $isNewest || ($withinRunLimit && $withinDayLimit);
        $relative = rel($dir, $repoRoot);

        if ($shouldKeep) {
            $result['kept'][] = $relative;
            continue;
        }

        $sizeBytes = dirSize($dir);
        if (rrmdir($dir)) {
            $result['deleted'][] = $relative;
            $result['freed_bytes'] += $sizeBytes;
        } else {
            $result['kept'][] = $relative;
            $result['warnings'][] = 'failed_to_delete_backup_dir: ' . $relative;
        }
    }

    $result['freed_mb'] = round($result['freed_bytes'] / 1048576, 2);
    return $result;
}

function rrmdir(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }

    $ok = true;
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $ok = rrmdir($path) && $ok;
        } else {
            $ok = @unlink($path) && $ok;
        }
    }
    return @rmdir($dir) && $ok;
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

function resolveBackupPolicy(): array
{
    $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('SUKI_ENV') ?: 'dev')));
    $defaults = [
        'compress' => true,
        'retention_runs' => 3,
        'retention_days' => 3,
    ];

    if (in_array($appEnv, ['production', 'prod'], true)) {
        $defaults['retention_runs'] = 7;
        $defaults['retention_days'] = 7;
    } elseif ($appEnv === 'staging') {
        $defaults['retention_runs'] = 5;
        $defaults['retention_days'] = 5;
    }

    return [
        'app_env' => $appEnv !== '' ? $appEnv : 'dev',
        'compress' => envBool('BACKUP_COMPRESS', $defaults['compress']),
        'retention_runs' => envInt('BACKUP_RETENTION_RUNS', $defaults['retention_runs'], 1),
        'retention_days' => envInt('BACKUP_RETENTION_DAYS', $defaults['retention_days'], 0),
    ];
}

function envBool(string $name, bool $default): bool
{
    $raw = getenv($name);
    if ($raw === false) {
        return $default;
    }

    $value = strtolower(trim((string) $raw));
    if ($value === '') {
        return $default;
    }

    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

function envInt(string $name, int $default, int $min): int
{
    $raw = getenv($name);
    if ($raw === false || trim((string) $raw) === '') {
        return $default;
    }

    $value = (int) $raw;
    return $value >= $min ? $value : $default;
}

function compressFileToGzip(string $sourcePath, string $targetPath): array
{
    if (!extension_loaded('zlib') || !function_exists('gzopen')) {
        return [
            'ok' => false,
            'error' => 'zlib extension not available',
        ];
    }

    $input = @fopen($sourcePath, 'rb');
    if (!is_resource($input)) {
        return [
            'ok' => false,
            'error' => 'failed to open source dump for compression',
        ];
    }

    $output = @gzopen($targetPath, 'wb9');
    if (!is_resource($output)) {
        fclose($input);
        return [
            'ok' => false,
            'error' => 'failed to open gzip target',
        ];
    }

    $ok = true;
    while (!feof($input)) {
        $chunk = fread($input, 1024 * 1024);
        if ($chunk === false) {
            $ok = false;
            break;
        }
        if ($chunk === '') {
            continue;
        }
        if (gzwrite($output, $chunk) === false) {
            $ok = false;
            break;
        }
    }

    fclose($input);
    gzclose($output);

    if (!$ok || !is_file($targetPath) || filesize($targetPath) === 0) {
        @unlink($targetPath);
        return [
            'ok' => false,
            'error' => 'gzip write failed',
        ];
    }

    return [
        'ok' => true,
        'error' => '',
    ];
}

function moveFile(string $source, string $target): bool
{
    if (@rename($source, $target)) {
        return true;
    }

    if (@copy($source, $target)) {
        @unlink($source);
        return true;
    }

    return false;
}

function dirSize(string $path): int
{
    if (is_file($path)) {
        return (int) filesize($path);
    }
    if (!is_dir($path)) {
        return 0;
    }

    $size = 0;
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $size += dirSize($path . DIRECTORY_SEPARATOR . $item);
    }

    return $size;
}
