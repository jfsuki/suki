<?php
declare(strict_types=1);

// framework/scripts/cleanup_test_tmp.php
// Prune generated artifacts in framework/tests/tmp without touching manual debug helpers.
//
// Usage:
//   php framework/scripts/cleanup_test_tmp.php --check
//   php framework/scripts/cleanup_test_tmp.php --apply
//   php framework/scripts/cleanup_test_tmp.php --apply --phase=post

const STATUS_OK = 0;
const STATUS_FAIL = 1;

$apply = in_array('--apply', $argv, true);
$phase = 'manual';
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--phase=')) {
        $phase = substr((string) $arg, 8) ?: 'manual';
    }
}

$repoRoot = realpath(__DIR__ . '/..' . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Cannot resolve repository root." . PHP_EOL);
    exit(STATUS_FAIL);
}

$projectRoot = $repoRoot . DIRECTORY_SEPARATOR . 'project';
$envLoader = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env_loader.php';
if (is_file($envLoader)) {
    require_once $envLoader;
}

$tmpRoot = $repoRoot . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
$policy = resolveTestTmpPolicy();
$report = cleanupTestTmp($tmpRoot, $repoRoot, $policy, $apply, $phase);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($report['ok'] ?? false) === true ? STATUS_OK : STATUS_FAIL);

function cleanupTestTmp(string $tmpRoot, string $repoRoot, array $policy, bool $apply, string $phase): array
{
    $report = [
        'ok' => true,
        'mode' => $apply ? 'apply' : 'check',
        'phase' => $phase,
        'generated_at' => date('c'),
        'tmp_root' => rel($tmpRoot, $repoRoot),
        'policy' => $policy,
        'summary' => [
            'candidates' => 0,
            'protected' => 0,
            'kept' => 0,
            'deleted' => 0,
            'freed_bytes' => 0,
            'freed_mb' => 0.0,
        ],
        'kept' => [],
        'deleted' => [],
        'protected' => [],
        'errors' => [],
    ];

    if (!is_dir($tmpRoot)) {
        return $report;
    }

    $entries = [];
    $protected = [];
    $items = scandir($tmpRoot) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $tmpRoot . DIRECTORY_SEPARATOR . $item;
        $classification = classifyTmpEntry($path, $repoRoot);
        if ($classification === null) {
            continue;
        }
        if (($classification['generated'] ?? false) !== true) {
            $protected[] = $classification;
            continue;
        }
        $entries[] = $classification;
    }

    usort($entries, static function (array $left, array $right): int {
        $mtimeCompare = (int) ($right['mtime'] ?? 0) <=> (int) ($left['mtime'] ?? 0);
        if ($mtimeCompare !== 0) {
            return $mtimeCompare;
        }

        return strcmp((string) ($left['relative_path'] ?? ''), (string) ($right['relative_path'] ?? ''));
    });

    $report['summary']['candidates'] = count($entries);
    $report['summary']['protected'] = count($protected);
    $report['protected'] = array_values(array_map(
        static fn(array $entry): string => (string) ($entry['relative_path'] ?? ''),
        $protected
    ));

    $cutoff = $policy['retention_hours'] > 0
        ? time() - ($policy['retention_hours'] * 3600)
        : null;

    foreach ($entries as $index => $entry) {
        $relative = (string) ($entry['relative_path'] ?? '');
        $mtime = (int) ($entry['mtime'] ?? 0);
        $withinRecentCount = $index < $policy['keep_recent'];
        $isFreshEnough = $cutoff === null || $mtime >= $cutoff;
        $keep = $index < $policy['minimum_keep'] || ($withinRecentCount && $isFreshEnough);

        if ($keep) {
            $report['kept'][] = $relative;
            continue;
        }

        if ($apply) {
            $sizeBytes = (int) ($entry['size_bytes'] ?? 0);
            if (removePath((string) ($entry['absolute_path'] ?? ''))) {
                $report['deleted'][] = $relative;
                $report['summary']['freed_bytes'] += $sizeBytes;
            } else {
                $report['ok'] = false;
                $report['errors'][] = 'No se pudo eliminar: ' . $relative;
                $report['kept'][] = $relative;
            }
            continue;
        }

        $report['deleted'][] = $relative;
        $report['summary']['freed_bytes'] += (int) ($entry['size_bytes'] ?? 0);
    }

    $report['summary']['kept'] = count($report['kept']);
    $report['summary']['deleted'] = count($report['deleted']);
    $report['summary']['freed_mb'] = round(((int) $report['summary']['freed_bytes']) / 1048576, 2);

    return $report;
}

function classifyTmpEntry(string $path, string $repoRoot): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $name = basename($path);
    $relative = rel($path, $repoRoot);
    $isDir = is_dir($path);
    $generated = false;
    $rule = 'protected';

    if (strcasecmp($name, 'README.md') === 0) {
        $generated = false;
        $rule = 'protected_readme';
    } elseif ($isDir && (bool) preg_match('/(?:^|_)\d{10,}(?:_\d+)?$/', $name)) {
        $generated = true;
        $rule = 'timestamped_tmp_dir';
    } elseif ($isDir && in_array($name, ['workflow_repo_project', 'workflow_repo_project_bus'], true)) {
        $generated = true;
        $rule = 'workflow_repo_dir';
    } elseif (!$isDir && (bool) preg_match('/(?:_result|_report)\.json$/', $name)) {
        $generated = true;
        $rule = 'generated_json_report';
    } elseif (!$isDir && $name === 'unknown_business_quality_daily_report.json') {
        $generated = true;
        $rule = 'generated_json_report';
    } elseif (!$isDir && (bool) preg_match('/^unit_.*\.sqlite$/', $name)) {
        $generated = true;
        $rule = 'generated_sqlite';
    } elseif (!$isDir && $name === 'observability_metrics_test.sqlite') {
        $generated = true;
        $rule = 'generated_sqlite';
    } elseif (!$isDir && $name === 'llm_router_quota.sqlite') {
        $generated = true;
        $rule = 'generated_sqlite';
    } elseif (!$isDir && (bool) preg_match('/^operational_queue_schema_guard(?:_noallow)?_\d+\.sqlite$/', $name)) {
        $generated = true;
        $rule = 'generated_sqlite';
    } elseif (!$isDir && strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === 'php') {
        $generated = false;
        $rule = 'protected_php_debug';
    }

    $stats = collectStats($path, $repoRoot);
    $stats['generated'] = $generated;
    $stats['rule'] = $rule;
    return $stats;
}

function resolveTestTmpPolicy(): array
{
    $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('SUKI_ENV') ?: 'dev')));
    $defaults = [
        'keep_recent' => 120,
        'minimum_keep' => 3,
        'retention_hours' => 24,
    ];

    if ($appEnv === 'staging') {
        $defaults['keep_recent'] = 240;
        $defaults['retention_hours'] = 48;
    } elseif (in_array($appEnv, ['production', 'prod'], true)) {
        $defaults['keep_recent'] = 480;
        $defaults['retention_hours'] = 72;
    }

    return [
        'app_env' => $appEnv !== '' ? $appEnv : 'dev',
        'keep_recent' => envInt('TEST_TMP_KEEP_RECENT', $defaults['keep_recent'], 1),
        'minimum_keep' => envInt('TEST_TMP_MINIMUM_KEEP', $defaults['minimum_keep'], 1),
        'retention_hours' => envInt('TEST_TMP_RETENTION_HOURS', $defaults['retention_hours'], 0),
    ];
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

function collectStats(string $path, string $repoRoot): array
{
    $path = realpath($path) ?: $path;
    $isFile = is_file($path);
    $isDir = is_dir($path);
    $size = 0;
    $files = 0;
    $dirs = 0;
    $statsError = null;

    if ($isFile) {
        $size = (int) (filesize($path) ?: 0);
        $files = 1;
    } elseif ($isDir) {
        $dirs = 1;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    $dirs++;
                    continue;
                }
                $files++;
                $size += (int) ($item->getSize() ?: 0);
            }
        } catch (Throwable $exception) {
            $statsError = $exception->getMessage();
        }
    }

    $stats = [
        'absolute_path' => $path,
        'relative_path' => rel($path, $repoRoot),
        'type' => $isFile ? 'file' : ($isDir ? 'dir' : 'missing'),
        'mtime' => (int) (@filemtime($path) ?: 0),
        'files' => $files,
        'dirs' => $dirs,
        'size_bytes' => $size,
    ];
    if ($statsError !== null) {
        $stats['stats_error'] = $statsError;
    }

    return $stats;
}

function removePath(string $path): bool
{
    if ($path === '') {
        return false;
    }
    if (is_file($path) || is_link($path)) {
        return @unlink($path);
    }
    if (!is_dir($path)) {
        return true;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if ($item->isDir()) {
                if (!@rmdir($itemPath)) {
                    return false;
                }
                continue;
            }
            if (!@unlink($itemPath)) {
                return false;
            }
        }
    } catch (Throwable $exception) {
        return @rmdir($path);
    }

    return @rmdir($path);
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
