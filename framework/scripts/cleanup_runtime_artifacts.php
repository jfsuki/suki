<?php
declare(strict_types=1);

// framework/scripts/cleanup_runtime_artifacts.php
// Limpieza reproducible de artefactos runtime/test previo a release.
//
// Uso:
//   php framework/scripts/cleanup_runtime_artifacts.php --check
//   php framework/scripts/cleanup_runtime_artifacts.php --apply
//   php framework/scripts/cleanup_runtime_artifacts.php --apply --include-runtime-state --include-generated-contracts

const STATUS_OK = 0;
const STATUS_FAIL = 1;

$apply = in_array('--apply', $argv, true);
$check = in_array('--check', $argv, true) || !$apply;
$includeRuntimeState = in_array('--include-runtime-state', $argv, true);
$includeGeneratedContracts = in_array('--include-generated-contracts', $argv, true);
$protectedPaths = [
    'framework/tests/tmp/README.md',
];

$repoRoot = realpath(__DIR__ . '/..' . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Cannot resolve repository root." . PHP_EOL);
    exit(STATUS_FAIL);
}

$groups = [
    [
        'name' => 'tests_tmp',
        'description' => 'Artefactos temporales de pruebas',
        'patterns' => [
            'framework/tests/tmp/*',
        ],
    ],
    [
        'name' => 'tests_result_files',
        'description' => 'Resultados de pruebas *_result.json',
        'patterns' => [
            'framework/tests/*_result.json',
        ],
    ],
    [
        'name' => 'runtime_cache_contracts',
        'description' => 'Cache de contratos runtime',
        'patterns' => [
            'project/storage/cache/contracts/*',
        ],
    ],
    [
        'name' => 'runtime_cache_entities_schema',
        'description' => 'Cache de esquema de entidades',
        'patterns' => [
            'project/storage/cache/entities.schema.cache.json',
        ],
    ],
];

if ($includeRuntimeState) {
    $groups[] = [
        'name' => 'runtime_state_workflows',
        'description' => 'Estado runtime de workflows',
        'patterns' => [
            'project/storage/workflows/*',
        ],
    ];
    $groups[] = [
        'name' => 'runtime_state_security',
        'description' => 'Estado runtime de seguridad (nonce/rate-limit)',
        'patterns' => [
            'project/storage/security/*',
        ],
    ];
}

if ($includeGeneratedContracts) {
    $groups[] = [
        'name' => 'generated_workflow_contracts',
        'description' => 'Contratos de workflow generados en project/',
        'patterns' => [
            'project/contracts/workflows/*.workflow.contract.json',
            'project/contracts/workflows/*.workflow.template.json',
        ],
    ];
}

$entries = [];
foreach ($groups as $group) {
    $matches = [];
    foreach ($group['patterns'] as $pattern) {
        $absPattern = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pattern);
        foreach (glob($absPattern, GLOB_NOSORT) ?: [] as $path) {
            $matches[] = $path;
        }
    }
    $matches = array_values(array_unique($matches));

    foreach ($matches as $path) {
        $stat = collectStats($path, $repoRoot);
        if (in_array($stat['relative_path'], $protectedPaths, true)) {
            continue;
        }
        $stat['group'] = $group['name'];
        $entries[] = $stat;
    }
}

$totalSize = 0;
foreach ($entries as $entry) {
    $totalSize += (int) ($entry['size_bytes'] ?? 0);
}

$report = [
    'ok' => true,
    'mode' => $apply ? 'apply' : 'check',
    'generated_at' => date('c'),
    'options' => [
        'include_runtime_state' => $includeRuntimeState,
        'include_generated_contracts' => $includeGeneratedContracts,
    ],
    'summary' => [
        'items' => count($entries),
        'size_bytes' => $totalSize,
    ],
    'entries' => array_map(static function (array $entry): array {
        return [
            'group' => $entry['group'],
            'path' => $entry['relative_path'],
            'type' => $entry['type'],
            'files' => $entry['files'],
            'dirs' => $entry['dirs'],
            'size_bytes' => $entry['size_bytes'],
        ];
    }, $entries),
    'deleted' => [],
    'errors' => [],
];

if ($apply) {
    foreach ($entries as $entry) {
        $absPath = (string) ($entry['absolute_path'] ?? '');
        if ($absPath === '' || !file_exists($absPath)) {
            continue;
        }
        $ok = removePath($absPath);
        if ($ok) {
            $report['deleted'][] = $entry['relative_path'];
        } else {
            $report['ok'] = false;
            $report['errors'][] = 'No se pudo eliminar: ' . $entry['relative_path'];
        }
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? STATUS_OK : STATUS_FAIL);

function collectStats(string $path, string $repoRoot): array
{
    $path = realpath($path) ?: $path;
    $isFile = is_file($path);
    $isDir = is_dir($path);

    $size = 0;
    $files = 0;
    $dirs = 0;

    if ($isFile) {
        $size = (int) (filesize($path) ?: 0);
        $files = 1;
    } elseif ($isDir) {
        $dirs = 1;
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
    }

    $relative = str_replace('\\', '/', ltrim(str_replace(str_replace('\\', '/', $repoRoot), '', str_replace('\\', '/', $path)), '/'));

    return [
        'absolute_path' => $path,
        'relative_path' => $relative,
        'type' => $isFile ? 'file' : ($isDir ? 'dir' : 'missing'),
        'files' => $files,
        'dirs' => $dirs,
        'size_bytes' => $size,
    ];
}

function removePath(string $path): bool
{
    if (is_file($path) || is_link($path)) {
        return @unlink($path);
    }
    if (!is_dir($path)) {
        return true;
    }
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
    return @rmdir($path);
}
