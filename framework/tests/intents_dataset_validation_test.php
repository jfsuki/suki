<?php
// framework/tests/intents_dataset_validation_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$php = PHP_BINARY ?: 'php';
$datasetPath = FRAMEWORK_ROOT . '/training/intents_erp_base.json';
$validatorScript = FRAMEWORK_ROOT . '/scripts/validate_intents_dataset.php';
$publicationGateScript = FRAMEWORK_ROOT . '/scripts/training_dataset_publication_gate.php';
$vectorizeScript = FRAMEWORK_ROOT . '/scripts/training_dataset_vectorize.php';
$skillsCatalogPath = dirname(FRAMEWORK_ROOT) . '/docs/contracts/skills_catalog.json';

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/intents_dataset_validation_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal.';
}

foreach ([$datasetPath, $validatorScript, $publicationGateScript, $vectorizeScript, $skillsCatalogPath] as $requiredFile) {
    if (!is_file($requiredFile)) {
        $failures[] = 'Falta archivo requerido: ' . $requiredFile;
    }
}

if (empty($failures)) {
    $payload = readJson($datasetPath);
    $skillsCatalog = readJson($skillsCatalogPath);
    if (!is_array($payload)) {
        $failures[] = 'Dataset ERP base invalido.';
    }
    if (!is_array($skillsCatalog)) {
        $failures[] = 'skills_catalog invalido.';
    }

    if (is_array($payload) && is_array($skillsCatalog)) {
        $catalogEntries = is_array($skillsCatalog['catalog'] ?? null) ? $skillsCatalog['catalog'] : [];
        $knownSkills = [];
        foreach ($catalogEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $knownSkills[$name] = true;
            }
        }

        foreach ((array) ($payload['entries'] ?? []) as $index => $entry) {
            if (!is_array($entry)) {
                $failures[] = 'Entrada invalida en index ' . $index . '.';
                continue;
            }
            $skill = trim((string) ($entry['skill'] ?? ''));
            if ($skill === '' || !isset($knownSkills[$skill])) {
                $failures[] = 'Skill no encontrada en catalogo: ' . ($skill !== '' ? $skill : 'vacia');
            }
        }
    }

    $validateRun = runScript($php, $validatorScript, [$datasetPath, '--strict']);
    if ($validateRun['code'] !== 0) {
        $failures[] = 'El validador de intents ERP debe pasar en modo estricto.';
    } else {
        $json = $validateRun['json'];
        if (!is_array($json) || (($json['ok'] ?? false) !== true)) {
            $failures[] = 'La salida del validador debe reportar ok=true.';
        }
    }

    $publishedDataset = $tmpDir . '/intents_erp_base_published.json';
    copy($datasetPath, $publishedDataset);

    $publishRun = runScript($php, $publicationGateScript, ['--in=' . $publishedDataset]);
    if ($publishRun['code'] !== 0) {
        $failures[] = 'El publication gate debe publicar el intent dataset.';
    } else {
        $json = $publishRun['json'];
        if (!is_array($json) || (($json['ok'] ?? false) !== true)) {
            $failures[] = 'La salida del publication gate debe reportar ok=true.';
        }
        if (($json['dataset_kind'] ?? '') !== 'intent_dataset') {
            $failures[] = 'El publication gate debe identificar dataset_kind=intent_dataset.';
        }
        if (($json['eligible_for_vectorization'] ?? false) !== true) {
            $failures[] = 'El intent dataset publicado debe quedar elegible para vectorizacion.';
        }
    }

    $vectorizeRun = runScript($php, $vectorizeScript, [
        '--in=' . $publishedDataset,
        '--tenant-id=tenant_test',
        '--app-id=app_test',
        '--dry-run',
    ]);
    if ($vectorizeRun['code'] !== 0) {
        $failures[] = 'La vectorizacion dry-run del intent dataset debe pasar.';
    } else {
        $json = $vectorizeRun['json'];
        if (!is_array($json) || (($json['ok'] ?? false) !== true)) {
            $failures[] = 'La salida de vectorizacion debe reportar ok=true.';
        }
        if (($json['memory_type'] ?? '') !== 'agent_training') {
            $failures[] = 'El intent dataset debe vectorizarse sobre agent_training.';
        }
        if ((int) ($json['trace']['chunks_prepared'] ?? 0) < 1) {
            $failures[] = 'La vectorizacion dry-run debe preparar chunks.';
        }
        if (($json['dataset']['dataset'] ?? '') !== 'intents_erp_base') {
            $failures[] = 'La vectorizacion debe preservar el nombre del dataset.';
        }
        if (($json['dataset']['domain'] ?? '') !== 'erp') {
            $failures[] = 'La vectorizacion debe preservar domain=erp.';
        }
        if ((int) ($json['chunks_vectorized'] ?? -1) !== 0) {
            $failures[] = 'El dry-run no debe upsertear embeddings.';
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
 * @return array<string,mixed>|null
 */
function readJson(string $path): ?array
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
 * @param array<int,string> $args
 * @return array{code:int,output:string,json:array<string,mixed>|null}
 */
function runScript(string $php, string $script, array $args): array
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
    if (!is_array($json)) {
        $json = parseLastJsonObject($raw);
    }

    return [
        'code' => $code,
        'output' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function parseLastJsonObject(string $output): ?array
{
    $output = trim($output);
    if ($output === '') {
        return null;
    }
    $start = strrpos($output, '{');
    if ($start === false) {
        return null;
    }
    $candidate = substr($output, $start);
    $decoded = json_decode($candidate, true);
    return is_array($decoded) ? $decoded : null;
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
