<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditValidator;
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
        'available_artifacts' => array_values(AuditValidator::defaultArtifactPaths()),
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

    if (is_array($payload) && isset($payload['artifact_type'])) {
        $mode = 'catalog';
    } elseif (is_array($payload) && isset($payload['agent_id'])) {
        $mode = 'agent_config';
    } elseif (is_array($payload) && isset($payload['alert_id'])) {
        $mode = 'alert';
    } else {
        emitJson([
            'ok' => false,
            'strict' => $strictMode,
            'error' => 'Unsupported audit artifact: ' . $inputPath,
        ], 2);
    }
}

try {
    if ($mode === 'agent_config') {
        $report = AuditValidator::validateAgentConfig(is_array($payload) ? $payload : []);
    } elseif ($mode === 'alert') {
        $report = AuditValidator::validateAlert(is_array($payload) ? $payload : []);
    } else {
        $report = $inputPath === null
            ? AuditValidator::validateCatalog()
            : AuditValidator::validateCatalogFiles([$inputPath]);
    }
} catch (Throwable $e) {
    emitJson([
        'ok' => false,
        'strict' => $strictMode,
        'error' => $e->getMessage(),
        'available_artifacts' => array_values(AuditValidator::defaultArtifactPaths()),
    ], 2);
}

$stats = is_array($report['stats'] ?? null) ? $report['stats'] : [];
$output = [
    'ok' => ($report['ok'] ?? false) && (!$strictMode || empty($report['warnings'] ?? [])),
    'strict' => $strictMode,
    'mode' => $mode,
    'schema' => match ($mode) {
        'agent_config' => FRAMEWORK_ROOT . '/contracts/schemas/audit_agent.schema.json',
        'alert' => FRAMEWORK_ROOT . '/contracts/schemas/audit_alert.schema.json',
        default => FRAMEWORK_ROOT . '/contracts/schemas/audit_catalog.schema.json',
    },
    'catalog_schema' => FRAMEWORK_ROOT . '/contracts/schemas/audit_catalog.schema.json',
    'inputs' => $inputPath === null
        ? array_values(AuditValidator::defaultArtifactPaths())
        : [realpath($inputPath) ?: $inputPath],
    'artifacts' => $report['artifacts'] ?? [],
    'stats' => $stats,
    'counts' => [
        'rules' => (int) ($stats['rules'] ?? 0),
        'patterns' => (int) ($stats['patterns'] ?? 0),
        'errors' => count(is_array($report['errors'] ?? null) ? $report['errors'] : []),
        'warnings' => count(is_array($report['warnings'] ?? null) ? $report['warnings'] : []),
    ],
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
    echo "  php framework/scripts/validate_audit_contracts.php [artifact-or-payload.json] [--strict]\n\n";
    echo "Default artifacts:\n";
    echo "  framework/audit/audit_rules.json\n";
    echo "  framework/audit/anomaly_patterns_extended.json\n";
    echo "Supported payloads:\n";
    echo "  audit agent config json\n";
    echo "  audit alert json\n";
}
