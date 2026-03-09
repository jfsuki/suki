<?php
// framework/tests/training_dataset_vectorize_command_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$php = PHP_BINARY ?: 'php';
$vectorizeScript = FRAMEWORK_ROOT . '/scripts/training_dataset_vectorize.php';
$publishGateScript = FRAMEWORK_ROOT . '/scripts/training_dataset_publication_gate.php';
$templatePath = PROJECT_ROOT . '/contracts/knowledge/training_dataset_template.json';

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/training_dataset_vectorize_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal.';
}

if (!is_file($vectorizeScript)) {
    $failures[] = 'Falta script de vectorizacion canonica.';
}
if (!is_file($publishGateScript)) {
    $failures[] = 'Falta script de publication gate.';
}
if (!is_file($templatePath)) {
    $failures[] = 'Falta dataset template.';
}

if (empty($failures)) {
    $draftDataset = $tmpDir . '/dataset_draft.json';
    copy($templatePath, $draftDataset);

    $draftRun = runScript($php, $vectorizeScript, [
        '--in=' . $draftDataset,
        '--tenant-id=tenant_test',
        '--dry-run',
    ]);
    if ($draftRun['code'] === 0) {
        $failures[] = 'No debe vectorizar dataset en draft.';
    } else {
        $draftJson = $draftRun['json'];
        if (!is_array($draftJson) || (($draftJson['ok'] ?? true) !== false)) {
            $failures[] = 'Bloqueo draft debe reportar ok=false.';
        }
    }

    $publishedDataset = $tmpDir . '/dataset_published.json';
    copy($templatePath, $publishedDataset);

    $publishRun = runScript($php, $publishGateScript, [
        '--in=' . $publishedDataset,
        '--min-explicit=1',
        '--min-implicit=1',
        '--min-hard-negatives=1',
        '--min-dialogues=1',
        '--min-qa=1',
        '--min-quality=0.10',
        '--min-coverage=0.10',
    ]);
    if ($publishRun['code'] !== 0) {
        $failures[] = 'No se pudo preparar dataset publicado para prueba de vectorizacion.';
    }

    $dryRun = runScript($php, $vectorizeScript, [
        '--in=' . $publishedDataset,
        '--tenant-id=tenant_test',
        '--app-id=app_test',
        '--max-chars=200',
        '--max-chunks=10',
        '--dry-run',
    ]);
    if ($dryRun['code'] !== 0) {
        $failures[] = 'Vectorizacion dry-run de dataset publicado debe pasar.';
    } else {
        $json = $dryRun['json'];
        if (!is_array($json) || (($json['ok'] ?? false) !== true)) {
            $failures[] = 'Dry-run debe devolver ok=true.';
        }
        if (($json['action'] ?? '') !== 'dry_run') {
            $failures[] = 'Dry-run debe reportar action=dry_run.';
        }
        if ((int) ($json['trace']['chunks_prepared'] ?? 0) < 1) {
            $failures[] = 'Dry-run debe preparar al menos 1 chunk.';
        }
        if ((int) ($json['chunks_vectorized'] ?? -1) !== 0) {
            $failures[] = 'Dry-run no debe vectorizar chunks.';
        }
        if (($json['dataset']['publication_status'] ?? '') !== 'published') {
            $failures[] = 'Dataset para vectorizacion debe estar en status published.';
        }
        $enabledLayers = is_array($json['vectorization_policy']['enabled_layers'] ?? null)
            ? $json['vectorization_policy']['enabled_layers']
            : [];
        if (in_array('intents_expansion', $enabledLayers, true)) {
            $failures[] = 'intents_expansion no debe estar habilitado por default.';
        }
        if ((int) ($json['trace']['layers']['intents_expansion']['chunks'] ?? -1) !== 0) {
            $failures[] = 'intents_expansion no debe generar chunks en modo default.';
        }
        $contractSample = is_array($json['contract_sample'] ?? null) ? (array) $json['contract_sample'] : [];
        foreach (['tenant_id', 'memory_type', 'app_id', 'agent_role', 'sector', 'source_type', 'source_id', 'source', 'content', 'tags', 'created_at', 'updated_at', 'metadata'] as $requiredField) {
            if (!array_key_exists($requiredField, $contractSample)) {
                $failures[] = 'contract_sample debe incluir ' . $requiredField . '.';
            }
        }
    }

    $withIntents = runScript($php, $vectorizeScript, [
        '--in=' . $publishedDataset,
        '--tenant-id=tenant_test',
        '--app-id=app_test',
        '--max-chars=200',
        '--max-chunks=0',
        '--include-intents-expansion',
        '--dry-run',
    ]);
    if ($withIntents['code'] !== 0) {
        $failures[] = 'Dry-run con flag include-intents-expansion debe pasar.';
    } else {
        $json = $withIntents['json'];
        $enabledLayers = is_array($json['vectorization_policy']['enabled_layers'] ?? null)
            ? $json['vectorization_policy']['enabled_layers']
            : [];
        if (!in_array('intents_expansion', $enabledLayers, true)) {
            $failures[] = 'Flag include-intents-expansion debe habilitar esa capa.';
        }
        if ((int) ($json['trace']['layers']['intents_expansion']['chunks'] ?? 0) < 1) {
            $failures[] = 'Con flag include-intents-expansion se esperan chunks de intents.';
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
