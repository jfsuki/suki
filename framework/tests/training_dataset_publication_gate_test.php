<?php
// framework/tests/training_dataset_publication_gate_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$php = PHP_BINARY ?: 'php';
$script = FRAMEWORK_ROOT . '/scripts/training_dataset_publication_gate.php';
$template = PROJECT_ROOT . '/contracts/knowledge/training_dataset_template.json';

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/training_dataset_publication_gate_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal para pruebas.';
}

if (!is_file($script)) {
    $failures[] = 'Falta script de publication gate: ' . $script;
}
if (!is_file($template)) {
    $failures[] = 'Falta template de dataset: ' . $template;
}

if (empty($failures)) {
    $passDataset = $tmpDir . '/dataset_pass.json';
    copy($template, $passDataset);

    $publishResult = runScript($php, $script, [
        '--in=' . $passDataset,
        '--min-explicit=1',
        '--min-implicit=1',
        '--min-hard-negatives=1',
        '--min-dialogues=1',
        '--min-qa=1',
        '--min-quality=0.10',
        '--min-coverage=0.10',
    ]);
    if ($publishResult['code'] !== 0) {
        $failures[] = 'Gate debe publicar dataset valido en umbrales bajos.';
    } else {
        $publishJson = $publishResult['json'];
        if (!is_array($publishJson) || (($publishJson['ok'] ?? false) !== true)) {
            $failures[] = 'Salida de gate publicada no reporta ok=true.';
        }
        if (($publishJson['publication_status'] ?? '') !== 'published') {
            $failures[] = 'Gate debe marcar publication.status=published.';
        }
        if (($publishJson['eligible_for_vectorization'] ?? false) !== true) {
            $failures[] = 'Dataset publicado debe quedar elegible para vectorizacion.';
        }
    }

    $savedPayload = readJson($passDataset);
    if (!is_array($savedPayload)) {
        $failures[] = 'No se pudo leer dataset publicado en archivo temporal.';
    } else {
        $status = (string) (($savedPayload['publication']['status'] ?? ''));
        $publishedAt = (string) (($savedPayload['publication']['published_at'] ?? ''));
        if ($status !== 'published' || $publishedAt === '') {
            $failures[] = 'Archivo publicado debe persistir status+published_at.';
        }
    }

    $requirePublishedResult = runScript($php, $script, [
        '--in=' . $passDataset,
        '--require-published',
    ]);
    if ($requirePublishedResult['code'] !== 0) {
        $failures[] = 'require-published debe pasar cuando status=published.';
    }

    $draftDataset = $tmpDir . '/dataset_draft.json';
    copy($template, $draftDataset);
    $draftCheck = runScript($php, $script, [
        '--in=' . $draftDataset,
        '--require-published',
    ]);
    if ($draftCheck['code'] === 0) {
        $failures[] = 'require-published debe fallar para status draft.';
    }

    $noisyDataset = $tmpDir . '/dataset_noise.json';
    $noisyPayload = readJson($template);
    if (!is_array($noisyPayload)) {
        $failures[] = 'No se pudo leer template para caso de ruido.';
    } else {
        $noisyPayload['support_faq'][0]['question'] = 'Web: compra ahora lorem ipsum';
        writeJson($noisyDataset, $noisyPayload);

        $noiseResult = runScript($php, $script, [
            '--in=' . $noisyDataset,
            '--min-explicit=1',
            '--min-implicit=1',
            '--min-hard-negatives=1',
            '--min-dialogues=1',
            '--min-qa=1',
            '--min-quality=0.10',
            '--min-coverage=0.10',
        ]);
        if ($noiseResult['code'] === 0) {
            $failures[] = 'Gate debe bloquear dataset con ruido detectado.';
        } else {
            $noiseJson = $noiseResult['json'];
            $blocking = is_array($noiseJson['blocking_reasons'] ?? null) ? $noiseJson['blocking_reasons'] : [];
            if (!in_array('noise_detected', $blocking, true)) {
                $failures[] = 'Bloqueo por ruido debe reportar reason noise_detected.';
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
    return [
        'code' => $code,
        'output' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

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
 * @param array<string,mixed> $data
 */
function writeJson(string $path, array $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
    );
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
