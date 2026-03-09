<?php
// framework/tests/business_discovery_template_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\BusinessDiscoveryDatasetCompiler;
use App\Core\TrainingDatasetValidator;

$php = PHP_BINARY ?: 'php';
$templatePath = PROJECT_ROOT . '/contracts/knowledge/business_discovery_template.json';
$compileScript = FRAMEWORK_ROOT . '/scripts/business_discovery_to_training_dataset.php';
$publishGateScript = FRAMEWORK_ROOT . '/scripts/training_dataset_publication_gate.php';
$vectorizeScript = FRAMEWORK_ROOT . '/scripts/training_dataset_vectorize.php';
$requiredSkills = [
    'create_invoice',
    'send_invoice',
    'register_expense',
    'accounting_post',
    'product_lookup',
    'inventory_check',
    'customer_lookup',
    'read_document',
    'extract_invoice_data',
    'generate_report',
    'business_explain',
    'dataset_lookup',
    'tax_accountant_advisor',
];

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/business_discovery_template_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal.';
}

if (!is_file($templatePath)) {
    $failures[] = 'Falta business_discovery_template.json.';
}
if (!is_file($compileScript)) {
    $failures[] = 'Falta business_discovery_to_training_dataset.php.';
}
if (!is_file($publishGateScript)) {
    $failures[] = 'Falta training_dataset_publication_gate.php.';
}
if (!is_file($vectorizeScript)) {
    $failures[] = 'Falta training_dataset_vectorize.php.';
}

if (empty($failures)) {
    $payload = readJson($templatePath);
    if (!is_array($payload)) {
        $failures[] = 'Template business discovery JSON invalido.';
    } else {
        try {
            BusinessDiscoveryDatasetCompiler::validateTemplate($payload);
        } catch (Throwable $e) {
            $failures[] = 'Schema business discovery invalido: ' . $e->getMessage();
        }

        try {
            $compiled = BusinessDiscoveryDatasetCompiler::compile($payload);
        } catch (Throwable $e) {
            $compiled = null;
            $failures[] = 'No se pudo compilar business discovery: ' . $e->getMessage();
        }

        if (is_array($compiled)) {
            $dataset = is_array($compiled['dataset'] ?? null) ? $compiled['dataset'] : [];
            $report = TrainingDatasetValidator::validate($dataset, [
                'min_explicit' => 1,
                'min_implicit' => 1,
                'min_hard_negatives' => 1,
                'min_dialogues' => 1,
                'min_qa_cases' => 1,
            ]);
            if (($report['ok'] ?? false) !== true) {
                $failures[] = 'Dataset compilado debe validar contra training_dataset_ingest.';
            }

            $actions = [];
            $globalSeen = [];
            $globalCount = 0;
            $channelPattern = '/^\s*(web|telegram|whatsapp)\s*:/iu';
            foreach ((array) ($dataset['intents_expansion'] ?? []) as $intent) {
                if (!is_array($intent)) {
                    continue;
                }
                $action = (string) ($intent['action'] ?? '');
                if ($action !== '') {
                    $actions[$action] = true;
                }
                foreach (['utterances_explicit', 'utterances_implicit', 'hard_negatives'] as $field) {
                    foreach ((array) ($intent[$field] ?? []) as $utterance) {
                        $text = trim((string) $utterance);
                        if ($text === '') {
                            continue;
                        }
                        $globalCount++;
                        if (preg_match($channelPattern, $text) === 1) {
                            $failures[] = 'Las utterances compiladas no deben conservar prefijos de canal.';
                            break 2;
                        }
                        $key = normalizeKey($text);
                        if (isset($globalSeen[$key])) {
                            $failures[] = 'No debe haber utterances globales duplicadas en el dataset compilado.';
                            break 2;
                        }
                        $globalSeen[$key] = true;
                    }
                }
            }

            if ($globalCount < 10) {
                $failures[] = 'El dataset compilado debe traer cobertura minima de utterances.';
            }

            $missingSkills = array_values(array_diff($requiredSkills, array_keys($actions)));
            if ($missingSkills !== []) {
                $failures[] = 'Faltan skills mapeadas: ' . implode(', ', $missingSkills);
            }

            $compiledPath = $tmpDir . '/compiled_dataset.json';
            $compileRun = runScript($php, $compileScript, [
                '--in=' . $templatePath,
                '--out=' . $compiledPath,
            ]);
            if ($compileRun['code'] !== 0) {
                $failures[] = 'El compilador CLI debe pasar.';
            } elseif (!is_file($compiledPath)) {
                $failures[] = 'El compilador CLI debe escribir el dataset de salida.';
            }

            if (is_file($compiledPath)) {
                $publishRun = runScript($php, $publishGateScript, [
                    '--in=' . $compiledPath,
                    '--min-explicit=1',
                    '--min-implicit=1',
                    '--min-hard-negatives=1',
                    '--min-dialogues=1',
                    '--min-qa=1',
                    '--min-quality=0.10',
                    '--min-coverage=0.10',
                ]);
                if ($publishRun['code'] !== 0) {
                    $failures[] = 'El dataset compilado debe publicar con umbrales bajos.';
                }

                $vectorizeRun = runScript($php, $vectorizeScript, [
                    '--in=' . $compiledPath,
                    '--tenant-id=tenant_test',
                    '--app-id=app_test',
                    '--max-chars=220',
                    '--dry-run',
                ]);
                if ($vectorizeRun['code'] !== 0) {
                    $failures[] = 'La vectorizacion dry-run del dataset compilado debe pasar.';
                } else {
                    $json = $vectorizeRun['json'];
                    if (!is_array($json) || (($json['ok'] ?? false) !== true)) {
                        $failures[] = 'La vectorizacion dry-run debe devolver ok=true.';
                    }
                    if ((int) ($json['trace']['chunks_prepared'] ?? 0) < 1) {
                        $failures[] = 'La vectorizacion del dataset compilado debe preparar chunks.';
                    }
                }
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
 * @return array<string,mixed>|null
 */
function readJson(string $path): ?array
{
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

function normalizeKey(string $value): string
{
    $value = function_exists('iconv')
        ? (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value)
        : $value;
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    return strtolower($value);
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
