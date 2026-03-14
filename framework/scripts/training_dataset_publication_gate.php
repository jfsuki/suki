<?php
// framework/scripts/training_dataset_publication_gate.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\IntentDatasetValidator;
use App\Core\TrainingDatasetValidator;

const DEFAULT_MIN_QUALITY_SCORE = 0.75;
const DEFAULT_MIN_COVERAGE_RATIO = 0.80;

$inputPath = null;
$outputPath = null;
$contractPath = dirname(FRAMEWORK_ROOT) . '/docs/contracts/sector_training_dataset_standard.json';
$requirePublished = false;
$failOnWarnings = false;

$thresholdOverrides = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        printHelp();
        exit(0);
    }
    if ($arg === '--require-published') {
        $requirePublished = true;
        continue;
    }
    if ($arg === '--fail-on-warnings') {
        $failOnWarnings = true;
        continue;
    }
    if (str_starts_with($arg, '--in=')) {
        $inputPath = substr($arg, strlen('--in='));
        continue;
    }
    if (str_starts_with($arg, '--out=')) {
        $outputPath = substr($arg, strlen('--out='));
        continue;
    }
    if (str_starts_with($arg, '--contract=')) {
        $contractPath = substr($arg, strlen('--contract='));
        continue;
    }
    if (str_starts_with($arg, '--min-explicit=')) {
        $thresholdOverrides['min_explicit'] = (int) substr($arg, strlen('--min-explicit='));
        continue;
    }
    if (str_starts_with($arg, '--min-implicit=')) {
        $thresholdOverrides['min_implicit'] = (int) substr($arg, strlen('--min-implicit='));
        continue;
    }
    if (str_starts_with($arg, '--min-hard-negatives=')) {
        $thresholdOverrides['min_hard_negatives'] = (int) substr($arg, strlen('--min-hard-negatives='));
        continue;
    }
    if (str_starts_with($arg, '--min-dialogues=')) {
        $thresholdOverrides['min_dialogues'] = (int) substr($arg, strlen('--min-dialogues='));
        continue;
    }
    if (str_starts_with($arg, '--min-qa=')) {
        $thresholdOverrides['min_qa_cases'] = (int) substr($arg, strlen('--min-qa='));
        continue;
    }
    if (str_starts_with($arg, '--min-quality=')) {
        $thresholdOverrides['min_quality_score'] = (float) substr($arg, strlen('--min-quality='));
        continue;
    }
    if (str_starts_with($arg, '--min-coverage=')) {
        $thresholdOverrides['min_coverage_ratio'] = (float) substr($arg, strlen('--min-coverage='));
        continue;
    }
    if (!str_starts_with($arg, '--') && $inputPath === null) {
        $inputPath = $arg;
    }
}

if ($inputPath === null || trim($inputPath) === '') {
    $inputPath = PROJECT_ROOT . '/contracts/knowledge/training_dataset_template.json';
}
if ($outputPath === null || trim($outputPath) === '') {
    $outputPath = $inputPath;
}

if (!is_file($inputPath)) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'Dataset file not found: ' . $inputPath,
    ], 2);
}

$payload = readJsonFile($inputPath);
if (!is_array($payload)) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'error' => 'Dataset JSON invalido: ' . $inputPath,
    ], 2);
}

$publishedStatus = (string) ((is_array($payload['publication'] ?? null) ? ($payload['publication']['status'] ?? '') : ''));
if ($requirePublished) {
    $isPublished = ($publishedStatus === 'published');
    writeReportAndExit([
        'ok' => $isPublished,
        'action' => $isPublished ? 'allow_vectorization' : 'blocked',
        'input' => realpath($inputPath) ?: $inputPath,
        'publication_status' => $publishedStatus !== '' ? $publishedStatus : 'missing',
        'eligible_for_vectorization' => $isPublished,
        'blocking_reasons' => $isPublished ? [] : ['dataset_not_published'],
    ], $isPublished ? 0 : 1);
}

$standardContract = readJsonFile($contractPath);
$thresholds = resolveThresholds($standardContract, $thresholdOverrides);

if (IntentDatasetValidator::supports($payload)) {
    publishIntentDataset($payload, $inputPath, $outputPath, $thresholds, $failOnWarnings);
}

$validationOptions = [
    'min_explicit' => $thresholds['min_explicit'],
    'min_implicit' => $thresholds['min_implicit'],
    'min_hard_negatives' => $thresholds['min_hard_negatives'],
    'min_dialogues' => $thresholds['min_dialogues'],
    'min_qa_cases' => $thresholds['min_qa_cases'],
];

try {
    $report = TrainingDatasetValidator::validate($payload, $validationOptions);
} catch (Throwable $e) {
    writeReportAndExit([
        'ok' => false,
        'action' => 'blocked',
        'input' => realpath($inputPath) ?: $inputPath,
        'error' => 'Validation runtime error: ' . $e->getMessage(),
    ], 2);
}

$errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
$warnings = is_array($report['warnings'] ?? null) ? $report['warnings'] : [];
$stats = is_array($report['stats'] ?? null) ? $report['stats'] : [];

$intentsCount = max(1, (int) ($stats['intents_expansion'] ?? 0));
$coverageChecks = [
    'utterances_explicit_total' => [
        'actual' => (int) ($stats['utterances_explicit_total'] ?? 0),
        'min_required' => $thresholds['min_explicit'] * $intentsCount,
    ],
    'utterances_implicit_total' => [
        'actual' => (int) ($stats['utterances_implicit_total'] ?? 0),
        'min_required' => $thresholds['min_implicit'] * $intentsCount,
    ],
    'hard_negatives_total' => [
        'actual' => (int) ($stats['hard_negatives_total'] ?? 0),
        'min_required' => $thresholds['min_hard_negatives'] * $intentsCount,
    ],
    'multi_turn_dialogues' => [
        'actual' => (int) ($stats['multi_turn_dialogues'] ?? 0),
        'min_required' => $thresholds['min_dialogues'],
    ],
    'qa_cases' => [
        'actual' => (int) ($stats['qa_cases'] ?? 0),
        'min_required' => $thresholds['min_qa_cases'],
    ],
];

$blockingReasons = [];

if (($report['ok'] ?? false) !== true) {
    $blockingReasons[] = 'validator_errors';
}

foreach ($coverageChecks as $key => &$check) {
    $check['ok'] = $check['actual'] >= $check['min_required'];
    if ($check['ok'] !== true) {
        $blockingReasons[] = 'coverage_min_failed:' . $key;
    }
}
unset($check);

$qualityScore = (float) ($stats['quality_score'] ?? 0.0);
if ($qualityScore < $thresholds['min_quality_score']) {
    $blockingReasons[] = 'quality_score_below_min';
}

$coverageRatio = (float) ($stats['coverage_ratio'] ?? 0.0);
if ($coverageRatio < $thresholds['min_coverage_ratio']) {
    $blockingReasons[] = 'coverage_ratio_below_min';
}

$noiseWarnings = array_values(array_filter($warnings, static function ($warning): bool {
    if (!is_array($warning)) {
        return false;
    }
    $message = strtolower((string) ($warning['message'] ?? ''));
    $path = strtolower((string) ($warning['path'] ?? ''));
    $noiseSignals = ['ruido', 'noise', 'placeholder', 'channel', 'canal'];
    foreach ($noiseSignals as $signal) {
        if (str_contains($message, $signal) || str_contains($path, $signal)) {
            return true;
        }
    }
    return false;
}));

if ($noiseWarnings !== []) {
    $blockingReasons[] = 'noise_detected';
}

if ($failOnWarnings && $warnings !== []) {
    $blockingReasons[] = 'warnings_blocked';
}

$blockingReasons = array_values(array_unique($blockingReasons));
$ok = ($blockingReasons === []);

if ($ok) {
    $publication = is_array($payload['publication'] ?? null) ? $payload['publication'] : [];
    $publication['status'] = 'published';
    $publication['published_at'] = gmdate('c');
    $payload['publication'] = $publication;

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        writeReportAndExit([
            'ok' => false,
            'action' => 'blocked',
            'input' => realpath($inputPath) ?: $inputPath,
            'error' => 'No se pudo serializar dataset publicado.',
        ], 2);
    }

    if (file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
        writeReportAndExit([
            'ok' => false,
            'action' => 'blocked',
            'input' => realpath($inputPath) ?: $inputPath,
            'output' => $outputPath,
            'error' => 'No se pudo escribir dataset publicado.',
        ], 2);
    }
}

$finalStatus = (string) ((is_array($payload['publication'] ?? null) ? ($payload['publication']['status'] ?? '') : ''));

$result = [
    'ok' => $ok,
    'action' => $ok ? 'published' : 'blocked',
    'input' => realpath($inputPath) ?: $inputPath,
    'output' => $ok ? (realpath($outputPath) ?: $outputPath) : null,
    'contract' => [
        'path' => $contractPath,
        'loaded' => is_array($standardContract),
        'version' => is_array($standardContract) ? (string) ($standardContract['version'] ?? 'unknown') : 'missing',
    ],
    'thresholds' => $thresholds,
    'validation' => [
        'ok' => ($report['ok'] ?? false) === true,
        'errors_count' => count($errors),
        'warnings_count' => count($warnings),
    ],
    'noise_warnings_count' => count($noiseWarnings),
    'quality_score' => $qualityScore,
    'coverage_ratio' => $coverageRatio,
    'coverage_checks' => $coverageChecks,
    'publication_status' => $finalStatus !== '' ? $finalStatus : 'missing',
    'eligible_for_vectorization' => $ok && $finalStatus === 'published',
    'blocking_reasons' => $blockingReasons,
];

writeReportAndExit($result, $ok ? 0 : 1);

/**
 * @return array<string,mixed>|null
 */
function readJsonFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string,mixed>|null $contract
 * @param array<string,int|float> $overrides
 * @return array<string,int|float>
 */
function resolveThresholds(?array $contract, array $overrides): array
{
    $qualityRules = is_array($contract['quality_rules'] ?? null) ? $contract['quality_rules'] : [];

    $minQualityFromEnv = envFloatOrNull('TRAINING_DATASET_MIN_QUALITY_SCORE');
    $minCoverageFromEnv = envFloatOrNull('TRAINING_DATASET_MIN_COVERAGE_RATIO');

    return [
        'min_explicit' => max(1, (int) ($overrides['min_explicit'] ?? $qualityRules['min_explicit_per_intent'] ?? 40)),
        'min_implicit' => max(1, (int) ($overrides['min_implicit'] ?? $qualityRules['min_implicit_per_intent'] ?? 40)),
        'min_hard_negatives' => max(1, (int) ($overrides['min_hard_negatives'] ?? $qualityRules['min_hard_negatives_per_intent'] ?? 40)),
        'min_dialogues' => max(1, (int) ($overrides['min_dialogues'] ?? $qualityRules['min_multi_turn_dialogues'] ?? 10)),
        'min_qa_cases' => max(1, (int) ($overrides['min_qa_cases'] ?? $qualityRules['min_qa_cases'] ?? 10)),
        'min_quality_score' => max(0.0, min(1.0, (float) ($overrides['min_quality_score'] ?? $minQualityFromEnv ?? DEFAULT_MIN_QUALITY_SCORE))),
        'min_coverage_ratio' => max(0.0, min(1.0, (float) ($overrides['min_coverage_ratio'] ?? $minCoverageFromEnv ?? DEFAULT_MIN_COVERAGE_RATIO))),
    ];
}

function envFloatOrNull(string $key): ?float
{
    $raw = getenv($key);
    if ($raw === false) {
        return null;
    }
    $value = trim((string) $raw);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

/**
 * @param array<string,mixed> $report
 */
function writeReportAndExit(array $report, int $exitCode): void
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function printHelp(): void
{
    echo "Usage:\n";
    echo "  php framework/scripts/training_dataset_publication_gate.php [dataset.json]\n";
    echo "      [--in=<dataset.json>] [--out=<dataset.json>] [--contract=<contract.json>]\n";
    echo "      [--min-explicit=40] [--min-implicit=40] [--min-hard-negatives=40]\n";
    echo "      [--min-dialogues=10] [--min-qa=10]\n";
    echo "      [--min-quality=0.75] [--min-coverage=0.80]\n";
    echo "      [--fail-on-warnings]\n";
    echo "      [--require-published]\n\n";
    echo "Modes:\n";
    echo "  default            Validate + enforce publication gate, publish when PASS.\n";
    echo "  --require-published  Check published status before vectorization/RAG ingest.\n";
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,int|float> $thresholds
 */
function publishIntentDataset(
    array $payload,
    string $inputPath,
    string $outputPath,
    array $thresholds,
    bool $failOnWarnings
): void {
    try {
        $report = IntentDatasetValidator::validate($payload);
    } catch (Throwable $e) {
        writeReportAndExit([
            'ok' => false,
            'action' => 'blocked',
            'input' => realpath($inputPath) ?: $inputPath,
            'error' => 'Validation runtime error: ' . $e->getMessage(),
        ], 2);
    }

    $errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
    $warnings = is_array($report['warnings'] ?? null) ? $report['warnings'] : [];
    $stats = is_array($report['stats'] ?? null) ? $report['stats'] : [];

    $qualityScore = (float) ($stats['quality_score'] ?? 0.0);
    $coverageRatio = (float) ($stats['coverage_ratio'] ?? 0.0);

    $noiseWarnings = array_values(array_filter($warnings, static function ($warning): bool {
        if (!is_array($warning)) {
            return false;
        }
        $message = strtolower((string) ($warning['message'] ?? ''));
        $path = strtolower((string) ($warning['path'] ?? ''));
        $noiseSignals = ['ruido', 'noise', 'placeholder', 'channel', 'canal'];
        foreach ($noiseSignals as $signal) {
            if (str_contains($message, $signal) || str_contains($path, $signal)) {
                return true;
            }
        }
        return false;
    }));

    $blockingReasons = [];
    if (($report['ok'] ?? false) !== true) {
        $blockingReasons[] = 'validator_errors';
    }
    if ($qualityScore < (float) ($thresholds['min_quality_score'] ?? DEFAULT_MIN_QUALITY_SCORE)) {
        $blockingReasons[] = 'quality_score_below_min';
    }
    if ($coverageRatio < (float) ($thresholds['min_coverage_ratio'] ?? DEFAULT_MIN_COVERAGE_RATIO)) {
        $blockingReasons[] = 'coverage_ratio_below_min';
    }
    if ($noiseWarnings !== []) {
        $blockingReasons[] = 'noise_detected';
    }
    if ($failOnWarnings && $warnings !== []) {
        $blockingReasons[] = 'warnings_blocked';
    }

    $blockingReasons = array_values(array_unique($blockingReasons));
    $ok = ($blockingReasons === []);

    if ($ok) {
        $publication = is_array($payload['publication'] ?? null) ? $payload['publication'] : [];
        $publication['status'] = 'published';
        $publication['published_at'] = gmdate('c');
        $payload['publication'] = $publication;

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            writeReportAndExit([
                'ok' => false,
                'action' => 'blocked',
                'input' => realpath($inputPath) ?: $inputPath,
                'error' => 'No se pudo serializar intent dataset publicado.',
            ], 2);
        }

        if (file_put_contents($outputPath, $encoded . PHP_EOL) === false) {
            writeReportAndExit([
                'ok' => false,
                'action' => 'blocked',
                'input' => realpath($inputPath) ?: $inputPath,
                'output' => $outputPath,
                'error' => 'No se pudo escribir intent dataset publicado.',
            ], 2);
        }
    }

    $finalStatus = (string) ((is_array($payload['publication'] ?? null) ? ($payload['publication']['status'] ?? '') : ''));
    $result = [
        'ok' => $ok,
        'action' => $ok ? 'published' : 'blocked',
        'dataset_kind' => 'intent_dataset',
        'input' => realpath($inputPath) ?: $inputPath,
        'output' => $ok ? (realpath($outputPath) ?: $outputPath) : null,
        'contract' => [
            'path' => FRAMEWORK_ROOT . '/contracts/schemas/intent_dataset.schema.json',
            'loaded' => true,
            'version' => '1.0.0',
        ],
        'thresholds' => $thresholds,
        'validation' => [
            'ok' => ($report['ok'] ?? false) === true,
            'errors_count' => count($errors),
            'warnings_count' => count($warnings),
        ],
        'noise_warnings_count' => count($noiseWarnings),
        'quality_score' => $qualityScore,
        'coverage_ratio' => $coverageRatio,
        'coverage_checks' => [
            'entries' => [
                'actual' => (int) ($stats['entries'] ?? 0),
                'min_required' => 25,
                'ok' => (int) ($stats['entries'] ?? 0) >= 25,
            ],
            'utterances_total' => [
                'actual' => (int) ($stats['utterances_total'] ?? 0),
                'min_required' => 250,
                'ok' => (int) ($stats['utterances_total'] ?? 0) >= 250,
            ],
        ],
        'publication_status' => $finalStatus !== '' ? $finalStatus : 'missing',
        'eligible_for_vectorization' => $ok && $finalStatus === 'published',
        'blocking_reasons' => $blockingReasons,
    ];

    writeReportAndExit($result, $ok ? 0 : 1);
}
