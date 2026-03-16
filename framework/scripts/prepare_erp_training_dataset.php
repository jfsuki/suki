<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ErpDatasetNormalizer;
use App\Core\ErpDatasetSupport;

$inputPath = null;
$outputDir = null;
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
    if (str_starts_with($arg, '--in=')) {
        $inputPath = substr($arg, strlen('--in='));
        continue;
    }
    if (str_starts_with($arg, '--out-dir=')) {
        $outputDir = substr($arg, strlen('--out-dir='));
        continue;
    }
    if (!str_starts_with($arg, '--') && $inputPath === null) {
        $inputPath = $arg;
    }
}

if ($inputPath === null || trim($inputPath) === '') {
    fwrite(STDERR, "Dataset file not provided.\n");
    printHelp();
    exit(2);
}

if (!is_file($inputPath)) {
    fwrite(STDERR, "Dataset file not found: {$inputPath}\n");
    exit(2);
}

if ($outputDir === null || trim($outputDir) === '') {
    $baseName = pathinfo($inputPath, PATHINFO_FILENAME);
    $outputDir = dirname($inputPath) . DIRECTORY_SEPARATOR . $baseName . '_prepared';
}

$raw = file_get_contents($inputPath);
if (!is_string($raw) || trim($raw) === '') {
    fwrite(STDERR, "Dataset file is empty: {$inputPath}\n");
    exit(2);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid JSON dataset: {$inputPath}\n");
    exit(2);
}

try {
    $prepared = ErpDatasetNormalizer::prepare($payload);
    ErpDatasetSupport::ensureDirectory($outputDir);

    $artifacts = [
        'erp_intents_catalog.json' => $prepared['erp_intents_catalog'],
        'erp_training_samples.json' => $prepared['erp_training_samples'],
        'erp_hard_cases.json' => $prepared['erp_hard_cases'],
        'erp_vectorization_prep.json' => $prepared['erp_vectorization_prep'],
        'erp_pipeline_report.json' => $prepared['erp_pipeline_report'],
    ];

    $writtenPaths = [];
    foreach ($artifacts as $fileName => $artifact) {
        $path = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        ErpDatasetSupport::writeJsonFile($path, is_array($artifact) ? $artifact : []);
        $writtenPaths[$fileName] = realpath($path) ?: $path;
    }

    $warnings = is_array($prepared['validation']['warnings'] ?? null) ? $prepared['validation']['warnings'] : [];
    $ok = !$strictMode || $warnings === [];

    $result = [
        'ok' => $ok,
        'strict' => $strictMode,
        'input' => realpath($inputPath) ?: $inputPath,
        'output_dir' => realpath($outputDir) ?: $outputDir,
        'artifacts' => $writtenPaths,
        'stats' => $prepared['erp_pipeline_report']['stats'] ?? [],
        'warnings' => $warnings,
        'validation_stats' => $prepared['validation']['stats'] ?? [],
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "Prepare runtime error: {$e->getMessage()}\n");
    exit(2);
}

function printHelp(): void
{
    echo "Usage:\n";
    echo "  php framework/scripts/prepare_erp_training_dataset.php <dataset.json> [--out-dir=<dir>] [--strict]\n";
    echo "  php framework/scripts/prepare_erp_training_dataset.php --in=<dataset.json> [--out-dir=<dir>] [--strict]\n\n";
    echo "Outputs:\n";
    echo "  erp_intents_catalog.json\n";
    echo "  erp_training_samples.json\n";
    echo "  erp_hard_cases.json\n";
    echo "  erp_vectorization_prep.json\n";
    echo "  erp_pipeline_report.json\n";
}
