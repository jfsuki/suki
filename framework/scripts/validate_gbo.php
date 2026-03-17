<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GboValidator;

$inputPath = null;
$strictMode = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        printHelp();
        exit(0);
    }
    if ($arg === '--strict') {
        $strictMode = true;
        continue;
    }
    if (!str_starts_with($arg, '--') && $inputPath === null) {
        $inputPath = $arg;
    }
}

if ($inputPath !== null && !is_file($inputPath)) {
    emitJson([
        'ok' => false,
        'strict' => $strictMode,
        'error' => 'Artifact file not found: ' . $inputPath,
        'available_artifacts' => array_values(GboValidator::defaultArtifactPaths()),
    ], 2);
}

try {
    $report = $inputPath === null
        ? GboValidator::validateCatalog()
        : GboValidator::validateCatalogFiles([$inputPath]);
} catch (Throwable $e) {
    emitJson([
        'ok' => false,
        'strict' => $strictMode,
        'error' => $e->getMessage(),
        'available_artifacts' => array_values(GboValidator::defaultArtifactPaths()),
    ], 2);
}

$output = [
    'ok' => ($report['ok'] ?? false) && (!$strictMode || empty($report['warnings'] ?? [])),
    'strict' => $strictMode,
    'mode' => 'catalog',
    'schema' => FRAMEWORK_ROOT . '/contracts/schemas/gbo.schema.json',
    'inputs' => $inputPath === null
        ? array_values(GboValidator::defaultArtifactPaths())
        : [realpath($inputPath) ?: $inputPath],
    'artifacts' => $report['artifacts'] ?? [],
    'stats' => $report['stats'] ?? [],
    'errors' => $report['errors'] ?? [],
    'warnings' => $report['warnings'] ?? [],
];

emitJson($output, ($output['ok'] ?? false) ? 0 : 1);

/**
 * @param array<string, mixed> $payload
 */
function emitJson(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function printHelp(): void
{
    echo "Usage:\n";
    echo "  php framework/scripts/validate_gbo.php [artifact.json] [--strict]\n\n";
    echo "Default artifacts:\n";
    echo "  framework/ontology/gbo_universal_concepts.json\n";
    echo "  framework/ontology/gbo_business_events.json\n";
    echo "  framework/ontology/gbo_semantic_relationships.json\n";
    echo "  framework/ontology/gbo_base_aliases.json\n";
}
