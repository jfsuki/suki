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

$exampleInput = ErpDatasetSupport::defaultExampleInputPath();
$legacyInput = ErpDatasetSupport::legacyIntentDatasetPath();

if ($inputPath === null || trim($inputPath) === '') {
    writeReportAndExit([
        'ok' => false,
        'error' => 'Dataset file not provided.',
        'how_to_fix' => 'Pass a source JSON file with metadata + BLOQUE_A/B/C.',
        'examples' => buildExamples($exampleInput, $legacyInput),
        'suggested_json_candidates' => ErpDatasetSupport::suggestJsonCandidates(),
    ], 2);
}

$inputPath = ErpDatasetSupport::normalizeCliPath($inputPath);
$inputCheck = ErpDatasetSupport::validateCliInputPath($inputPath);
if (($inputCheck['ok'] ?? false) !== true) {
    writeReportAndExit([
        'ok' => false,
        'error' => $inputCheck['error'] ?? 'Invalid input path.',
        'input' => $inputPath,
        'how_to_fix' => 'Use an existing .json source dataset. The ERP pipeline does not accept folders, missing files, or non-JSON inputs.',
        'examples' => buildExamples($exampleInput, $legacyInput),
        'suggested_json_candidates' => ErpDatasetSupport::suggestJsonCandidates(),
    ], 2);
}

$raw = file_get_contents($inputPath);
if (!is_string($raw) || trim($raw) === '') {
    writeReportAndExit([
        'ok' => false,
        'error' => 'Dataset file is empty: ' . $inputPath,
        'input' => ErpDatasetSupport::relativeToRepo($inputPath),
        'how_to_fix' => 'Write a valid JSON source dataset with metadata + BLOQUE_A/B/C.',
        'examples' => buildExamples($exampleInput, $legacyInput),
    ], 2);
}

try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    writeReportAndExit([
        'ok' => false,
        'error' => 'Invalid JSON dataset: ' . $inputPath,
        'json_error' => $e->getMessage(),
        'input' => ErpDatasetSupport::relativeToRepo($inputPath),
        'how_to_fix' => 'Fix JSON syntax before running the ERP dataset pipeline.',
        'examples' => buildExamples($exampleInput, $legacyInput),
    ], 2);
}

if (!is_array($payload)) {
    writeReportAndExit([
        'ok' => false,
        'error' => 'Invalid JSON dataset root: expected object at ' . $inputPath,
        'input' => ErpDatasetSupport::relativeToRepo($inputPath),
        'how_to_fix' => 'The source dataset root must be a JSON object.',
        'examples' => buildExamples($exampleInput, $legacyInput),
    ], 2);
}

try {
    $report = ErpDatasetValidator::validate($payload);
} catch (Throwable $e) {
    writeReportAndExit([
        'ok' => false,
        'error' => 'Validation runtime error: ' . $e->getMessage(),
        'input' => ErpDatasetSupport::relativeToRepo($inputPath),
        'how_to_fix' => 'Review the source dataset structure and catalog contracts.',
        'examples' => buildExamples($exampleInput, $legacyInput),
    ], 2);
}

$output = [
    'ok' => ($report['ok'] ?? false) && (!$strictMode || empty($report['warnings'] ?? [])),
    'strict' => $strictMode,
    'input' => realpath($inputPath) ?: $inputPath,
    'input_repo_relative' => ErpDatasetSupport::relativeToRepo($inputPath),
    'expected_source_shape' => [
        'metadata',
        'BLOQUE_A_intents_catalog',
        'BLOQUE_B_training_samples',
        'BLOQUE_C_hard_cases',
    ],
    'examples' => buildExamples($exampleInput, $legacyInput),
    'artifacts_schema' => [
        ErpDatasetSupport::SCHEMA_INTENTS,
        ErpDatasetSupport::SCHEMA_SAMPLES,
        ErpDatasetSupport::SCHEMA_HARD_CASES,
    ],
    'stats' => $report['stats'] ?? [],
    'errors' => $report['errors'] ?? [],
    'warnings' => $report['warnings'] ?? [],
];

writeReportAndExit($output, ($output['ok'] ?? false) ? 0 : 1);

/**
 * @return array<string, string>
 */
function buildExamples(string $exampleInput, string $legacyInput): array
{
    $examples = [];
    if (is_file($exampleInput)) {
        $examples['erp_source_example'] = ErpDatasetSupport::relativeToRepo($exampleInput);
    }
    if (is_file($legacyInput)) {
        $examples['legacy_intent_dataset_other_pipeline'] = ErpDatasetSupport::relativeToRepo($legacyInput);
    }

    return $examples;
}

/**
 * @param array<string, mixed> $report
 */
function writeReportAndExit(array $report, int $exitCode): void
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function printHelp(): void
{
    $exampleInput = ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::defaultExampleInputPath());
    $exampleOutput = ErpDatasetSupport::relativeToRepo(
        ErpDatasetSupport::defaultOutputDirForInput(ErpDatasetSupport::defaultExampleInputPath())
    );

    echo "Usage:\n";
    echo "  php framework/scripts/validate_erp_training_dataset.php <dataset.json> [--strict]\n";
    echo "  php framework/scripts/validate_erp_training_dataset.php --in=<dataset.json> [--strict]\n\n";
    echo "Expected source shape:\n";
    echo "  metadata + BLOQUE_A_intents_catalog + BLOQUE_B_training_samples + BLOQUE_C_hard_cases\n\n";
    echo "Real repo example:\n";
    echo "  php framework/scripts/validate_erp_training_dataset.php {$exampleInput} --strict\n\n";
    echo "Output directory used by prepare script for this repo:\n";
    echo "  {$exampleOutput}\n";
}
