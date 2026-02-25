<?php
// framework/scripts/validate_training_dataset.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\TrainingDatasetValidator;

$inputPath = null;
$strictMode = false;
$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--strict') {
        $strictMode = true;
        continue;
    }
    if (str_starts_with($arg, '--min-explicit=')) {
        $options['min_explicit'] = (int) substr($arg, strlen('--min-explicit='));
        continue;
    }
    if (str_starts_with($arg, '--min-implicit=')) {
        $options['min_implicit'] = (int) substr($arg, strlen('--min-implicit='));
        continue;
    }
    if (str_starts_with($arg, '--min-hard-negatives=')) {
        $options['min_hard_negatives'] = (int) substr($arg, strlen('--min-hard-negatives='));
        continue;
    }
    if (str_starts_with($arg, '--min-dialogues=')) {
        $options['min_dialogues'] = (int) substr($arg, strlen('--min-dialogues='));
        continue;
    }
    if (str_starts_with($arg, '--min-qa=')) {
        $options['min_qa_cases'] = (int) substr($arg, strlen('--min-qa='));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        print_help();
        exit(0);
    }
    if (!str_starts_with($arg, '--') && $inputPath === null) {
        $inputPath = $arg;
    }
}

if ($inputPath === null) {
    $inputPath = PROJECT_ROOT . '/contracts/knowledge/training_dataset_template.json';
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
    $report = TrainingDatasetValidator::validate($payload, $options);
} catch (Throwable $e) {
    fwrite(STDERR, "Validation runtime error: {$e->getMessage()}\n");
    exit(2);
}

$reportOutput = [
    'ok' => $report['ok'] && (!$strictMode || empty($report['warnings'])),
    'strict' => $strictMode,
    'input' => realpath($inputPath) ?: $inputPath,
    'schema' => FRAMEWORK_ROOT . '/contracts/schemas/training_dataset_ingest.schema.json',
    'stats' => $report['stats'],
    'errors' => $report['errors'],
    'warnings' => $report['warnings'],
    'thresholds' => [
        'min_explicit' => (int) ($options['min_explicit'] ?? 40),
        'min_implicit' => (int) ($options['min_implicit'] ?? 40),
        'min_hard_negatives' => (int) ($options['min_hard_negatives'] ?? 40),
        'min_dialogues' => (int) ($options['min_dialogues'] ?? 10),
        'min_qa_cases' => (int) ($options['min_qa_cases'] ?? 10),
    ],
];

echo json_encode($reportOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($reportOutput['ok'] ? 0 : 1);

function print_help(): void
{
    echo "Usage:\n";
    echo "  php framework/scripts/validate_training_dataset.php [dataset.json] [--strict]\n";
    echo "      [--min-explicit=40] [--min-implicit=40] [--min-hard-negatives=40]\n";
    echo "      [--min-dialogues=10] [--min-qa=10]\n\n";
    echo "Default dataset:\n";
    echo "  project/contracts/knowledge/training_dataset_template.json\n";
}
