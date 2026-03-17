<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\BegValidator;

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
        'available_artifacts' => array_values(BegValidator::defaultArtifactPaths()),
    ], 2);
}

$mode = 'catalog';
$payload = null;
if ($inputPath !== null) {
    try {
        $raw = file_get_contents($inputPath);
        $payload = is_string($raw)
            ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR)
            : null;
    } catch (JsonException $e) {
        emitJson([
            'ok' => false,
            'strict' => $strictMode,
            'error' => 'Invalid JSON file: ' . $inputPath,
        ], 2);
    }

    if (is_array($payload) && isset($payload['event_id']) && !isset($payload['artifact_type'])) {
        $mode = 'event_payload';
    }
}

try {
    if ($mode === 'event_payload') {
        $report = BegValidator::validateEventPayload(is_array($payload) ? $payload : []);
    } else {
        $report = $inputPath === null
            ? BegValidator::validateCatalog()
            : BegValidator::validateCatalogFiles([$inputPath]);
    }
} catch (Throwable $e) {
    emitJson([
        'ok' => false,
        'strict' => $strictMode,
        'error' => $e->getMessage(),
        'available_artifacts' => array_values(BegValidator::defaultArtifactPaths()),
    ], 2);
}

$output = [
    'ok' => ($report['ok'] ?? false) && (!$strictMode || empty($report['warnings'] ?? [])),
    'strict' => $strictMode,
    'mode' => $mode,
    'schema' => $mode === 'event_payload'
        ? FRAMEWORK_ROOT . '/contracts/schemas/beg_event_payload.schema.json'
        : FRAMEWORK_ROOT . '/contracts/schemas/beg.schema.json',
    'catalog_schema' => FRAMEWORK_ROOT . '/contracts/schemas/beg.schema.json',
    'inputs' => $inputPath === null
        ? array_values(BegValidator::defaultArtifactPaths())
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
    echo "  php framework/scripts/validate_beg_contracts.php [artifact-or-event.json] [--strict]\n\n";
    echo "Default artifacts:\n";
    echo "  framework/events/beg_event_types.json\n";
    echo "  framework/events/beg_relationship_types.json\n";
    echo "  framework/events/beg_anomaly_patterns.json\n";
    echo "  framework/events/beg_projection_rules.json\n";
}
