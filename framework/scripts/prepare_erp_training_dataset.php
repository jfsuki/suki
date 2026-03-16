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

if ($outputDir === null || trim($outputDir) === '') {
    $outputDir = ErpDatasetSupport::defaultOutputDirForInput($inputPath);
}
$outputDir = ErpDatasetSupport::normalizeCliPath($outputDir);
$outputCheck = ErpDatasetSupport::validateCliOutputDir($outputDir);
if (($outputCheck['ok'] ?? false) !== true) {
    writeReportAndExit([
        'ok' => false,
        'error' => $outputCheck['error'] ?? 'Invalid output directory.',
        'input_repo_relative' => ErpDatasetSupport::relativeToRepo($inputPath),
        'output_dir' => $outputDir,
        'safe_example_output_dir' => ErpDatasetSupport::relativeToRepo(
            ErpDatasetSupport::defaultOutputDirForInput($exampleInput)
        ),
        'how_to_fix' => 'Use a dedicated output directory such as framework/training/output/<dataset_name> or framework/tests/tmp/<run_id>.',
    ], 2);
}

$raw = file_get_contents($inputPath);
if (!is_string($raw) || trim($raw) === '') {
    writeReportAndExit([
        'ok' => false,
        'error' => 'Dataset file is empty: ' . $inputPath,
        'input_repo_relative' => ErpDatasetSupport::relativeToRepo($inputPath),
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
        'input_repo_relative' => ErpDatasetSupport::relativeToRepo($inputPath),
        'how_to_fix' => 'Fix JSON syntax before running the ERP dataset pipeline.',
        'examples' => buildExamples($exampleInput, $legacyInput),
    ], 2);
}

if (!is_array($payload)) {
    writeReportAndExit([
        'ok' => false,
        'error' => 'Invalid JSON dataset root: expected object at ' . $inputPath,
        'input_repo_relative' => ErpDatasetSupport::relativeToRepo($inputPath),
        'how_to_fix' => 'The source dataset root must be a JSON object.',
        'examples' => buildExamples($exampleInput, $legacyInput),
    ], 2);
}

$outputDirAlreadyExists = is_dir($outputDir);

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
    $writtenPathsRelative = [];
    foreach ($artifacts as $fileName => $artifact) {
        $path = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        ErpDatasetSupport::writeJsonFile($path, is_array($artifact) ? $artifact : []);
        $resolved = realpath($path) ?: $path;
        $writtenPaths[$fileName] = $resolved;
        $writtenPathsRelative[$fileName] = ErpDatasetSupport::relativeToRepo($resolved);
    }

    $warnings = is_array($prepared['validation']['warnings'] ?? null) ? $prepared['validation']['warnings'] : [];
    $ok = !$strictMode || $warnings === [];

    $result = [
        'ok' => $ok,
        'strict' => $strictMode,
        'input' => realpath($inputPath) ?: $inputPath,
        'input_repo_relative' => ErpDatasetSupport::relativeToRepo($inputPath),
        'output_dir' => realpath($outputDir) ?: $outputDir,
        'output_dir_repo_relative' => ErpDatasetSupport::relativeToRepo($outputDir),
        'output_dir_created' => !$outputDirAlreadyExists,
        'artifacts_count' => count($writtenPaths),
        'artifacts' => $writtenPaths,
        'artifacts_repo_relative' => $writtenPathsRelative,
        'stats' => $prepared['erp_pipeline_report']['stats'] ?? [],
        'warnings' => $warnings,
        'validation_stats' => $prepared['validation']['stats'] ?? [],
        'notes' => [
            'Generated artifacts are operational outputs.',
            'Prefer framework/training/output/<dataset_name> for repo-local runs.',
            'Do not version generated outputs; .gitignore should exclude framework/training/output/.',
        ],
    ];

    writeReportAndExit($result, $ok ? 0 : 1);
} catch (Throwable $e) {
    writeReportAndExit([
        'ok' => false,
        'error' => 'Prepare runtime error: ' . $e->getMessage(),
        'input_repo_relative' => ErpDatasetSupport::relativeToRepo($inputPath),
        'output_dir_repo_relative' => ErpDatasetSupport::relativeToRepo($outputDir),
        'how_to_fix' => 'Validate the source dataset first and ensure the output directory is a dedicated folder.',
        'examples' => buildExamples($exampleInput, $legacyInput),
    ], 2);
}

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
    echo "  php framework/scripts/prepare_erp_training_dataset.php <dataset.json> [--out-dir=<dir>] [--strict]\n";
    echo "  php framework/scripts/prepare_erp_training_dataset.php --in=<dataset.json> [--out-dir=<dir>] [--strict]\n\n";
    echo "Expected source shape:\n";
    echo "  metadata + BLOQUE_A_intents_catalog + BLOQUE_B_training_samples + BLOQUE_C_hard_cases\n\n";
    echo "Real repo example:\n";
    echo "  php framework/scripts/prepare_erp_training_dataset.php {$exampleInput} --out-dir={$exampleOutput}\n\n";
    echo "Generated artifacts:\n";
    echo "  erp_intents_catalog.json\n";
    echo "  erp_training_samples.json\n";
    echo "  erp_hard_cases.json\n";
    echo "  erp_vectorization_prep.json\n";
    echo "  erp_pipeline_report.json\n\n";
    echo "Note:\n";
    echo "  Use dedicated output folders only. Generated artifacts under framework/training/output/ should stay unversioned.\n";
}
