<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';
require_once __DIR__ . '/../scripts/generate_erp_training_dataset.php';

use App\Core\ErpDatasetSupport;

$php = PHP_BINARY ?: 'php';
$generateScript = FRAMEWORK_ROOT . '/scripts/generate_erp_training_dataset.php';
$validateScript = FRAMEWORK_ROOT . '/scripts/validate_erp_training_dataset.php';
$prepareScript = FRAMEWORK_ROOT . '/scripts/prepare_erp_training_dataset.php';
$outputDataset = FRAMEWORK_ROOT . '/training/output/erp_training_dataset_example/suki_erp_dataset.json';
$prepareOutDir = FRAMEWORK_ROOT . '/tests/tmp/erp_training_dataset_mass';

$failures = [];

foreach ([
    $generateScript,
    $validateScript,
    $prepareScript,
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

if (is_dir($prepareOutDir)) {
    deleteTree($prepareOutDir);
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
    } else {
        $payload = json_decode((string) file_get_contents($outputDataset), true);
        if (!is_array($payload)) {
            $failures[] = 'El dataset ERP generado debe ser JSON parseable.';
        } else {
            $samples = count((array) ($payload['BLOQUE_B_training_samples'] ?? []));
            $hardCases = count((array) ($payload['BLOQUE_C_hard_cases'] ?? []));
            if ($samples < ERP_GENERATOR_MIN_TRAINING_SAMPLES) {
                $failures[] = 'El generador ERP debe producir al menos ' . ERP_GENERATOR_MIN_TRAINING_SAMPLES . ' training samples.';
            }
            if ($hardCases < ERP_GENERATOR_MIN_HARD_CASES) {
                $failures[] = 'El generador ERP debe producir al menos ' . ERP_GENERATOR_MIN_HARD_CASES . ' hard cases.';
            }
        }
    }

    $validateRun = runCommand($php, $validateScript, [$outputDataset, '--strict']);
    if ($validateRun['code'] !== 0) {
        $failures[] = 'El dataset ERP generado debe validar en modo estricto.';
    } elseif (!is_array($validateRun['json']) || (($validateRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'La validacion estricta del dataset generado debe devolver ok=true.';
    } elseif (!empty($validateRun['json']['warnings'])) {
        $failures[] = 'La validacion estricta del dataset generado no debe producir warnings.';
    }

    $prepareRun = runCommand($php, $prepareScript, [$outputDataset, '--out-dir=' . $prepareOutDir, '--strict']);
    if ($prepareRun['code'] !== 0) {
        $failures[] = 'El dataset ERP generado debe preparar artefactos strict sin fallar.';
    } elseif (!is_array($prepareRun['json']) || (($prepareRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'prepare_erp_training_dataset.php debe devolver ok=true para el dataset generado.';
    }

    foreach ([
        'erp_intents_catalog.json',
        'erp_training_samples.json',
        'erp_hard_cases.json',
        'erp_vectorization_prep.json',
        'erp_pipeline_report.json',
    ] as $artifact) {
        $artifactPath = $prepareOutDir . DIRECTORY_SEPARATOR . $artifact;
        if (!is_file($artifactPath)) {
            $failures[] = 'Falta artefacto preparado: ' . $artifactPath;
        }
    }

    $invalidPayload = [
        'metadata' => [
            'dataset_id' => 'invalid_erp_dataset',
            'dataset_version' => '1.0.0',
            'domain' => 'crm',
        ],
        'BLOQUE_A_intents_catalog' => [],
        'BLOQUE_B_training_samples' => [],
        'BLOQUE_C_hard_cases' => [],
    ];
    $invalidOutput = FRAMEWORK_ROOT . '/tests/tmp/erp_training_dataset_invalid/no_write.json';
    if (is_file($invalidOutput)) {
        unlink($invalidOutput);
    }
    if (is_dir(dirname($invalidOutput))) {
        deleteTree(dirname($invalidOutput));
    }

    try {
        erpGeneratorWriteDatasetOrFail($invalidPayload, $invalidOutput);
        $failures[] = 'El generador no debe escribir dataset cuando la validacion falla.';
    } catch (Throwable $e) {
        if (is_file($invalidOutput)) {
            $failures[] = 'El generador escribio un output invalido cuando debio abortar.';
        }
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

    $output = [];
    $code = 0;
    exec(implode(' ', $parts) . ' 2>&1', $output, $code);
    $raw = trim(implode("\n", $output));
    $json = json_decode($raw, true);

    return [
        'code' => $code,
        'output' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

function deleteTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
            continue;
        }
        @unlink($item->getPathname());
    }

    @rmdir($path);
}
