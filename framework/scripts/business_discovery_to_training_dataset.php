<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\BusinessDiscoveryDatasetCompiler;
use App\Core\TrainingDatasetValidator;

$inputPath = null;
$outputPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        printHelp();
        exit(0);
    }
    if (str_starts_with($arg, '--in=')) {
        $inputPath = substr($arg, strlen('--in='));
        continue;
    }
    if (str_starts_with($arg, '--out=')) {
        $outputPath = substr($arg, strlen('--out='));
        continue;
    }
    if (!str_starts_with($arg, '--') && $inputPath === null) {
        $inputPath = $arg;
    }
}

if ($inputPath === null || trim($inputPath) === '') {
    $inputPath = PROJECT_ROOT . '/contracts/knowledge/business_discovery_template.json';
}

if (!is_file($inputPath)) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'Business discovery file not found: ' . $inputPath,
    ], 2);
}

$payload = readJsonFile($inputPath);
if (!is_array($payload)) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'Business discovery JSON invalido: ' . $inputPath,
    ], 2);
}

if ($outputPath === null || trim($outputPath) === '') {
    $outputPath = deriveOutputPath($inputPath, $payload);
}

try {
    $compiled = BusinessDiscoveryDatasetCompiler::compile($payload);
} catch (Throwable $e) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'input' => realpath($inputPath) ?: $inputPath,
        'error' => 'No se pudo compilar business discovery: ' . $e->getMessage(),
    ], 2);
}

$dataset = is_array($compiled['dataset'] ?? null) ? $compiled['dataset'] : [];
$stats = is_array($compiled['stats'] ?? null) ? $compiled['stats'] : [];
$validation = TrainingDatasetValidator::validate($dataset, [
    'min_explicit' => 1,
    'min_implicit' => 1,
    'min_hard_negatives' => 1,
    'min_dialogues' => 1,
    'min_qa_cases' => 1,
]);

if (($validation['ok'] ?? false) !== true) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'input' => realpath($inputPath) ?: $inputPath,
        'output' => $outputPath,
        'error' => 'Dataset compilado no valida contra training_dataset_ingest.',
        'validation' => $validation,
    ], 1);
}

$encoded = json_encode($dataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'input' => realpath($inputPath) ?: $inputPath,
        'output' => $outputPath,
        'error' => 'No se pudo serializar dataset compilado.',
    ], 2);
}

$dir = dirname($outputPath);
if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'input' => realpath($inputPath) ?: $inputPath,
        'output' => $outputPath,
        'error' => 'No se pudo crear directorio de salida.',
    ], 2);
}

if (file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'input' => realpath($inputPath) ?: $inputPath,
        'output' => $outputPath,
        'error' => 'No se pudo escribir dataset compilado.',
    ], 2);
}

writeReportAndExit([
    'ok' => true,
    'action' => 'compiled',
    'input' => realpath($inputPath) ?: $inputPath,
    'output' => realpath($outputPath) ?: $outputPath,
    'dataset' => [
        'batch_id' => (string) ($dataset['batch_id'] ?? ''),
        'source_type' => (string) ($dataset['source_type'] ?? ''),
        'memory_type' => (string) ($dataset['memory_type'] ?? ''),
        'sector' => (string) ($dataset['sector'] ?? ''),
        'sector_key' => (string) ($dataset['sector_key'] ?? ''),
        'sector_label' => (string) ($dataset['sector_label'] ?? ''),
        'country_or_regulation' => (string) ($dataset['country_or_regulation'] ?? ''),
        'publication_status' => (string) ($dataset['publication']['status'] ?? ''),
    ],
    'stats' => $stats,
    'validation' => [
        'ok' => true,
        'errors_count' => 0,
        'warnings_count' => count((array) ($validation['warnings'] ?? [])),
        'quality_score' => (float) (($validation['stats']['quality_score'] ?? 0.0)),
    ],
], 0);

/**
 * @return array<string, mixed>|null
 */
function readJsonFile(string $path): ?array
{
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $payload
 */
function deriveOutputPath(string $inputPath, array $payload): string
{
    $baseDir = dirname($inputPath);
    $sectorKey = strtolower(trim((string) ($payload['sector_key'] ?? 'sector_unknown')));
    $sectorKey = preg_replace('/[^a-z0-9_]+/', '_', $sectorKey) ?? $sectorKey;
    $filename = 'training_dataset_' . trim($sectorKey, '_') . '_from_discovery.json';
    return $baseDir . DIRECTORY_SEPARATOR . $filename;
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
    echo "Usage:\n";
    echo "  php framework/scripts/business_discovery_to_training_dataset.php [discovery.json]\n";
    echo "      [--in=<discovery.json>] [--out=<dataset.json>]\n\n";
    echo "Default input:\n";
    echo "  project/contracts/knowledge/business_discovery_template.json\n";
}
