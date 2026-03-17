<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$php = PHP_BINARY ?: 'php';
$gboScript = FRAMEWORK_ROOT . '/scripts/validate_gbo.php';
$begScript = FRAMEWORK_ROOT . '/scripts/validate_beg_contracts.php';
$failures = [];

$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/gbo_beg_cli_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal para pruebas CLI GBO/BEG.';
}

foreach ([$gboScript, $begScript] as $requiredFile) {
    if (!is_file($requiredFile)) {
        $failures[] = 'Falta archivo requerido: ' . $requiredFile;
    }
}

if ($failures === []) {
    $gboHelp = runCommand($php, $gboScript, ['--help']);
    if ($gboHelp['code'] !== 0) {
        $failures[] = 'validate_gbo.php --help debe salir con code 0.';
    }
    if (!str_contains($gboHelp['output'], 'framework/ontology/gbo_universal_concepts.json')) {
        $failures[] = 'validate_gbo.php --help debe exponer los artefactos base.';
    }

    $gboRun = runCommand($php, $gboScript, ['--strict']);
    if ($gboRun['code'] !== 0 || !is_array($gboRun['json']) || (($gboRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'validate_gbo.php --strict debe validar el bundle base.';
    }

    $invalidGbo = loadJson(FRAMEWORK_ROOT . '/ontology/gbo_universal_concepts.json');
    $invalidGbo['concepts'][] = $invalidGbo['concepts'][0];
    $invalidGboPath = $tmpDir . '/invalid_gbo.json';
    file_put_contents($invalidGboPath, json_encode($invalidGbo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $invalidGboRun = runCommand($php, $gboScript, [$invalidGboPath, '--strict']);
    if ($invalidGboRun['code'] === 0) {
        $failures[] = 'validate_gbo.php debe fallar ante conceptos duplicados.';
    } elseif (!is_array($invalidGboRun['json']) || empty($invalidGboRun['json']['errors'] ?? [])) {
        $failures[] = 'validate_gbo.php debe devolver errores JSON utiles.';
    }

    $begHelp = runCommand($php, $begScript, ['--help']);
    if ($begHelp['code'] !== 0) {
        $failures[] = 'validate_beg_contracts.php --help debe salir con code 0.';
    }
    if (!str_contains($begHelp['output'], 'framework/events/beg_event_types.json')) {
        $failures[] = 'validate_beg_contracts.php --help debe exponer los artefactos base.';
    }

    $begRun = runCommand($php, $begScript, ['--strict']);
    if ($begRun['code'] !== 0 || !is_array($begRun['json']) || (($begRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'validate_beg_contracts.php --strict debe validar el bundle base.';
    }

    $invalidBegPayload = [
        'event_id' => 'evt_bad_001',
        'event_type' => 'sale_event',
        'occurred_at' => date('c'),
        'tenant_id' => 'tenant_cli',
        'app_id' => 'app_cli',
        'related_entities' => [
            [
                'entity_type' => 'customer',
                'entity_id' => 'cust_cli_001',
                'tenant_id' => 'tenant_cli',
                'app_id' => 'app_cli',
            ],
        ],
        'related_documents' => [],
        'source_skill' => null,
        'source_module' => 'cli_test',
        'status' => 'recorded',
        'causal_parent_ids' => ['evt_parent_001'],
        'metadata' => ['source' => 'cli'],
        'event_relationships' => [
            [
                'relationship_type' => 'teleports_to',
                'target_event_id' => 'evt_target_001',
            ],
        ],
    ];
    $invalidBegPath = $tmpDir . '/invalid_beg_payload.json';
    file_put_contents($invalidBegPath, json_encode($invalidBegPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $invalidBegRun = runCommand($php, $begScript, [$invalidBegPath, '--strict']);
    if ($invalidBegRun['code'] === 0) {
        $failures[] = 'validate_beg_contracts.php debe fallar ante relationship_type invalido.';
    } elseif (!is_array($invalidBegRun['json']) || (($invalidBegRun['json']['mode'] ?? '') !== 'event_payload')) {
        $failures[] = 'validate_beg_contracts.php debe detectar modo event_payload.';
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

/**
 * @return array<string, mixed>
 */
function loadJson(string $path): array
{
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
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
