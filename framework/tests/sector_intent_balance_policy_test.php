<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\BusinessDiscoveryDatasetCompiler;
use App\Core\TrainingDatasetValidator;

$php = PHP_BINARY ?: 'php';
$gateScript = FRAMEWORK_ROOT . '/scripts/training_dataset_publication_gate.php';
$compileScript = FRAMEWORK_ROOT . '/scripts/business_discovery_to_training_dataset.php';
$templatePath = PROJECT_ROOT . '/contracts/knowledge/training_dataset_template.json';
$discoveryPath = PROJECT_ROOT . '/contracts/knowledge/business_discovery_template.json';
$sectorSeedPath = PROJECT_ROOT . '/contracts/knowledge/sector_seed_FERRETERIA_MINORISTA.sample.json';

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/sector_intent_balance_policy_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal.';
}

if (!is_file($templatePath) || !is_file($gateScript) || !is_file($compileScript) || !is_file($discoveryPath) || !is_file($sectorSeedPath)) {
    $failures[] = 'Faltan archivos base para la prueba de balance sectorial.';
}

if ($failures === []) {
    $balancedDataset = $tmpDir . '/balanced.json';
    copy($templatePath, $balancedDataset);
    $balancedRun = runScript($php, $gateScript, [
        '--in=' . $balancedDataset,
        '--min-explicit=1',
        '--min-implicit=1',
        '--min-hard-negatives=1',
        '--min-dialogues=1',
        '--min-qa=1',
        '--min-quality=0.10',
        '--min-coverage=0.10',
        '--min-intent-total=4',
        '--max-intent-ratio=2.5',
    ]);
    if ($balancedRun['code'] !== 0) {
        $failures[] = 'Dataset balanceado debe publicar.';
    } else {
        $balancedJson = $balancedRun['json'];
        $balanceAudit = is_array($balancedJson['balance_audit'] ?? null) ? (array) $balancedJson['balance_audit'] : [];
        if (($balanceAudit['ok'] ?? false) !== true) {
            $failures[] = 'Dataset balanceado debe reportar balance_audit ok=true.';
        }
    }

    $imbalancedDataset = $tmpDir . '/imbalanced.json';
    $imbalancedPayload = readJson($templatePath);
    if (!is_array($imbalancedPayload)) {
        $failures[] = 'No se pudo leer template para caso desbalanceado.';
    } else {
        foreach (($imbalancedPayload['intents_expansion'] ?? []) as $index => $intent) {
            if (!is_array($intent)) {
                continue;
            }
            if ($index === 0) {
                continue;
            }
            $imbalancedPayload['intents_expansion'][$index]['utterances_explicit'] = array_slice((array) ($intent['utterances_explicit'] ?? []), 0, 1);
            $imbalancedPayload['intents_expansion'][$index]['utterances_implicit'] = array_slice((array) ($intent['utterances_implicit'] ?? []), 0, 1);
        }
        writeJson($imbalancedDataset, $imbalancedPayload);

        $imbalancedRun = runScript($php, $gateScript, [
            '--in=' . $imbalancedDataset,
            '--min-explicit=1',
            '--min-implicit=1',
            '--min-hard-negatives=1',
            '--min-dialogues=1',
            '--min-qa=1',
            '--min-quality=0.10',
            '--min-coverage=0.10',
            '--min-intent-total=4',
            '--max-intent-ratio=2.5',
        ]);
        if ($imbalancedRun['code'] === 0) {
            $failures[] = 'Dataset desbalanceado debe bloquearse.';
        } else {
            $blockingReasons = is_array($imbalancedRun['json']['blocking_reasons'] ?? null)
                ? (array) $imbalancedRun['json']['blocking_reasons']
                : [];
            if (!in_array('intent_balance_failed', $blockingReasons, true)) {
                $failures[] = 'Dataset desbalanceado debe reportar intent_balance_failed.';
            }
        }
    }

    $compiledPath = $tmpDir . '/ferreteria_dataset.json';
    $compileRun = runScript($php, $compileScript, [
        '--in=' . $discoveryPath,
        '--out=' . $compiledPath,
        '--sector-seed=' . $sectorSeedPath,
    ]);
    if ($compileRun['code'] !== 0 || !is_file($compiledPath)) {
        $failures[] = 'Business Discovery FERRETERIA_MINORISTA debe compilar con sector seed.';
    } else {
        $compiledPayload = readJson($compiledPath);
        if (!is_array($compiledPayload)) {
            $failures[] = 'No se pudo leer dataset compilado de FERRETERIA_MINORISTA.';
        } else {
            $validation = TrainingDatasetValidator::validate($compiledPayload, [
                'min_explicit' => 1,
                'min_implicit' => 1,
                'min_hard_negatives' => 1,
                'min_dialogues' => 1,
                'min_qa_cases' => 1,
                'min_total_utterances_per_intent' => 4,
                'max_intent_dominance_ratio' => 2.5,
            ]);
            $balanceAudit = is_array($validation['stats']['intent_balance'] ?? null)
                ? (array) $validation['stats']['intent_balance']
                : [];
            if (($balanceAudit['ok'] ?? false) !== true) {
                $failures[] = 'FERRETERIA_MINORISTA debe quedar balanceado despues de compilar.';
            }

            $requiredNeighborSkills = ['inventory_check', 'product_lookup', 'customer_lookup'];
            $distribution = is_array($balanceAudit['distribution'] ?? null) ? (array) $balanceAudit['distribution'] : [];
            $totalsByAction = [];
            foreach ($distribution as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $totalsByAction[(string) ($row['action'] ?? '')] = (int) ($row['utterances_total'] ?? 0);
            }
            foreach ($requiredNeighborSkills as $skill) {
                if ((int) ($totalsByAction[$skill] ?? 0) < 4) {
                    $failures[] = 'FERRETERIA_MINORISTA debe dejar ' . $skill . ' con cobertura minima balanceada.';
                }
            }

            $ferreteriaGate = runScript($php, $gateScript, [
                '--in=' . $compiledPath,
                '--min-explicit=1',
                '--min-implicit=1',
                '--min-hard-negatives=1',
                '--min-dialogues=1',
                '--min-qa=1',
                '--min-quality=0.10',
                '--min-coverage=0.10',
                '--min-intent-total=4',
                '--max-intent-ratio=2.5',
            ]);
            if ($ferreteriaGate['code'] !== 0) {
                $failures[] = 'FERRETERIA_MINORISTA corregido debe publicar con umbrales bajos y politica de balance activa.';
            }
        }
    }
}

rrmdir($tmpDir);

$result = [
    'ok' => $failures === [],
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @param array<int, string> $args
 * @return array{code:int,output:string,json:array<string,mixed>|null}
 */
function runScript(string $php, string $script, array $args): array
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

/**
 * @return array<string, mixed>|null
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
 * @param array<string, mixed> $payload
 */
function writeJson(string $path, array $payload): void
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('No se pudo serializar dataset temporal.');
    }

    file_put_contents($path, $encoded . PHP_EOL);
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
