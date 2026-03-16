<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ErpDatasetSupport;

$php = PHP_BINARY ?: 'php';
$generateScript = FRAMEWORK_ROOT . '/scripts/generate_erp_training_dataset.php';
$validateScript = FRAMEWORK_ROOT . '/scripts/validate_erp_training_dataset.php';
$outputDataset = FRAMEWORK_ROOT . '/training/output/erp_training_dataset_example/suki_erp_dataset.json';

$failures = [];

foreach ([
    $generateScript,
    $validateScript,
    ErpDatasetSupport::DEFAULT_SKILLS_CATALOG,
    ErpDatasetSupport::DEFAULT_ACTION_CATALOG,
    ErpDatasetSupport::SCHEMA_INTENTS,
    ErpDatasetSupport::SCHEMA_SAMPLES,
    ErpDatasetSupport::SCHEMA_HARD_CASES,
] as $requiredFile) {
    if (!is_file($requiredFile)) {
        $failures[] = 'Falta archivo requerido: ' . $requiredFile;
    }
}

if ($failures === []) {
    $generateRun = runCommand($php, $generateScript, []);
    if ($generateRun['code'] !== 0) {
        $failures[] = 'El generador ERP debe ejecutarse con code 0.';
    } elseif (!is_array($generateRun['json']) || (($generateRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'El generador ERP debe devolver ok=true.';
    }

    if (!is_file($outputDataset)) {
        $failures[] = 'El generador ERP debe escribir suki_erp_dataset.json en framework/training/output/erp_training_dataset_example/.';
    }

    $validateRun = runCommand($php, $validateScript, [$outputDataset, '--strict']);
    if ($validateRun['code'] !== 0) {
        $failures[] = 'El dataset ERP generado debe validar en modo estricto.';
    } elseif (!is_array($validateRun['json']) || (($validateRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'La validacion estricta del dataset generado debe devolver ok=true.';
    }
}

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @param array<int, string> $args
 * @return array{code:int,output:string,json:array<string,mixed>|null}
 */
function runCommand(string $php, string $script, array $args): array
{
    $parts = [escapeshellarg($php), escapeshellarg($script)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg($arg);
    }
    $command = implode(' ', $parts);
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $raw = trim(implode("\n", $output));
    $json = json_decode($raw, true);

    return [
        'code' => $code,
        'output' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}
