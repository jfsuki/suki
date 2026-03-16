<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$php = PHP_BINARY ?: 'php';
$validateScript = FRAMEWORK_ROOT . '/scripts/validate_erp_training_dataset.php';
$prepareScript = FRAMEWORK_ROOT . '/scripts/prepare_erp_training_dataset.php';
$exampleDataset = FRAMEWORK_ROOT . '/training/erp_training_dataset_example.json';
$failures = [];

$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/erp_training_dataset_cli_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal para CLI guardrails.';
}

foreach ([$validateScript, $prepareScript, $exampleDataset] as $requiredFile) {
    if (!is_file($requiredFile)) {
        $failures[] = 'Falta archivo requerido: ' . $requiredFile;
    }
}

if ($failures === []) {
    $helpRun = runCommand($php, $validateScript, ['--help']);
    if ($helpRun['code'] !== 0) {
        $failures[] = 'El --help del validador debe salir con code 0.';
    }
    if (!str_contains($helpRun['output'], 'framework/training/erp_training_dataset_example.json')) {
        $failures[] = 'El --help del validador debe incluir el ejemplo real del repo.';
    }

    $validateRun = runCommand($php, $validateScript, [$exampleDataset, '--strict']);
    if ($validateRun['code'] !== 0) {
        $failures[] = 'El ejemplo ERP versionado debe validar en modo estricto.';
    } elseif (!is_array($validateRun['json']) || (($validateRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'La validacion estricta del ejemplo debe devolver ok=true.';
    }

    $missingRun = runCommand($php, $validateScript, ['framework/training/missing_erp_dataset.json']);
    if ($missingRun['code'] === 0) {
        $failures[] = 'La validacion con path inexistente debe fallar.';
    } elseif (!is_array($missingRun['json'])) {
        $failures[] = 'La validacion con path inexistente debe devolver JSON guiado.';
    } else {
        $candidates = is_array($missingRun['json']['suggested_json_candidates'] ?? null)
            ? $missingRun['json']['suggested_json_candidates']
            : [];
        if (!in_array('framework/training/erp_training_dataset_example.json', $candidates, true)) {
            $failures[] = 'La validacion con path inexistente debe sugerir el ejemplo ERP real.';
        }
    }

    $suspiciousRun = runCommand($php, $prepareScript, [$exampleDataset, '--out-dir=framework']);
    if ($suspiciousRun['code'] === 0) {
        $failures[] = 'La preparacion con out-dir sospechoso debe fallar.';
    } elseif (!is_array($suspiciousRun['json']) || !str_contains((string) ($suspiciousRun['json']['error'] ?? ''), 'Suspicious output directory blocked')) {
        $failures[] = 'La preparacion con out-dir sospechoso debe explicar el bloqueo.';
    }

    $outputDir = $tmpDir . '/out';
    $prepareRun = runCommand($php, $prepareScript, [$exampleDataset, '--out-dir=' . $outputDir, '--strict']);
    if ($prepareRun['code'] !== 0) {
        $failures[] = 'La preparacion del ejemplo ERP debe pasar en modo estricto.';
    } elseif (!is_array($prepareRun['json']) || (($prepareRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'La preparacion del ejemplo ERP debe devolver ok=true.';
    } else {
        if ((int) ($prepareRun['json']['artifacts_count'] ?? 0) !== 5) {
            $failures[] = 'La preparacion del ejemplo ERP debe reportar 5 artefactos.';
        }
        $artifacts = is_array($prepareRun['json']['artifacts'] ?? null) ? $prepareRun['json']['artifacts'] : [];
        foreach (['erp_intents_catalog.json', 'erp_training_samples.json', 'erp_hard_cases.json', 'erp_vectorization_prep.json', 'erp_pipeline_report.json'] as $artifactName) {
            $path = (string) ($artifacts[$artifactName] ?? '');
            if ($path === '' || !is_file($path)) {
                $failures[] = 'Falta artefacto preparado: ' . $artifactName;
            }
        }
    }
}

rrmdir($tmpDir);

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

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        @rmdir($dir);
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}
