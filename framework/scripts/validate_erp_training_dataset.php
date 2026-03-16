<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ErpDatasetSupport;
use App\Core\ErpDatasetValidator;

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
    if (str_starts_with($arg, '--in=')) {
        $inputPath = substr($arg, strlen('--in='));
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
    $report = ErpDatasetValidator::validate($payload);
} catch (Throwable $e) {
    fwrite(STDERR, "Validation runtime error: {$e->getMessage()}\n");
    exit(2);
}

$output = [
    'ok' => ($report['ok'] ?? false) && (!$strictMode || empty($report['warnings'] ?? [])),
    'strict' => $strictMode,
    'input' => realpath($inputPath) ?: $inputPath,
    'artifacts_schema' => [
        ErpDatasetSupport::SCHEMA_INTENTS,
        ErpDatasetSupport::SCHEMA_SAMPLES,
        ErpDatasetSupport::SCHEMA_HARD_CASES,
    ],
    'stats' => $report['stats'] ?? [],
    'errors' => $report['errors'] ?? [],
    'warnings' => $report['warnings'] ?? [],
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($output['ok'] ?? false) ? 0 : 1);

function printHelp(): void
{
    echo "Usage:\n";
    echo "  php framework/scripts/validate_erp_training_dataset.php <dataset.json> [--strict]\n";
    echo "  php framework/scripts/validate_erp_training_dataset.php --in=<dataset.json> [--strict]\n";
}
