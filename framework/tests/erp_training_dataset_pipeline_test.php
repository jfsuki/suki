<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ErpDatasetSupport;
use App\Core\ErpDatasetValidator;

$php = PHP_BINARY ?: 'php';
$validateScript = FRAMEWORK_ROOT . '/scripts/validate_erp_training_dataset.php';
$prepareScript = FRAMEWORK_ROOT . '/scripts/prepare_erp_training_dataset.php';

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/erp_training_dataset_pipeline_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal para ERP dataset pipeline.';
}

foreach ([$validateScript, $prepareScript, ErpDatasetSupport::SCHEMA_INTENTS, ErpDatasetSupport::SCHEMA_SAMPLES, ErpDatasetSupport::SCHEMA_HARD_CASES] as $requiredFile) {
    if (!is_file($requiredFile)) {
        $failures[] = 'Falta archivo requerido: ' . $requiredFile;
    }
}

$datasetPath = $tmpDir . '/erp_training_source.json';
$outputDir = $tmpDir . '/prepared';

if ($failures === []) {
    $dataset = buildDatasetFixture();
    writeJson($datasetPath, $dataset);

    $validateRun = runScript($php, $validateScript, [$datasetPath]);
    if ($validateRun['code'] !== 0) {
        $failures[] = 'La validacion base del dataset ERP debe pasar.';
    } elseif (!is_array($validateRun['json']) || (($validateRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'El validador base debe devolver ok=true.';
    }

    $strictRun = runScript($php, $validateScript, [$datasetPath, '--strict']);
    if ($strictRun['code'] === 0) {
        $failures[] = 'La validacion estricta debe bloquear warnings de higiene.';
    }

    $prepareRun = runScript($php, $prepareScript, [$datasetPath, '--out-dir=' . $outputDir]);
    if ($prepareRun['code'] !== 0) {
        $failures[] = 'La preparacion del dataset ERP debe pasar.';
    } elseif (!is_array($prepareRun['json']) || (($prepareRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'La preparacion del dataset ERP debe devolver ok=true.';
    }

    $intentsArtifact = readJson($outputDir . '/erp_intents_catalog.json');
    $samplesArtifact = readJson($outputDir . '/erp_training_samples.json');
    $hardCasesArtifact = readJson($outputDir . '/erp_hard_cases.json');
    $vectorPrep = readJson($outputDir . '/erp_vectorization_prep.json');
    $report = readJson($outputDir . '/erp_pipeline_report.json');

    foreach ([$intentsArtifact, $samplesArtifact, $hardCasesArtifact] as $artifact) {
        if (!is_array($artifact)) {
            $failures[] = 'Falta artefacto ERP preparado.';
            continue;
        }
    }

    if (is_array($intentsArtifact)) {
        $schemaReport = ErpDatasetValidator::validateArtifact($intentsArtifact, ErpDatasetSupport::SCHEMA_INTENTS);
        if (($schemaReport['ok'] ?? false) !== true) {
            $failures[] = 'erp_intents_catalog.json debe cumplir schema.';
        }
        if (count((array) ($intentsArtifact['entries'] ?? [])) !== 2) {
            $failures[] = 'erp_intents_catalog.json debe contener 2 intents.';
        }
    }

    if (is_array($samplesArtifact)) {
        $schemaReport = ErpDatasetValidator::validateArtifact($samplesArtifact, ErpDatasetSupport::SCHEMA_SAMPLES);
        if (($schemaReport['ok'] ?? false) !== true) {
            $failures[] = 'erp_training_samples.json debe cumplir schema.';
        }

        $entries = is_array($samplesArtifact['entries'] ?? null) ? $samplesArtifact['entries'] : [];
        if (count($entries) !== 5) {
            $failures[] = 'erp_training_samples.json debe exportar 5 samples tras hygiene.';
        }
        $hygiene = is_array($samplesArtifact['hygiene_summary'] ?? null) ? $samplesArtifact['hygiene_summary'] : [];
        if ((int) ($hygiene['exact_duplicates_removed'] ?? 0) < 1) {
            $failures[] = 'La higiene debe remover duplicados exactos.';
        }
        if ((int) ($hygiene['garbage_rejected'] ?? 0) < 1) {
            $failures[] = 'La higiene debe rechazar basura extrema.';
        }
        if ((int) ($hygiene['near_duplicates_flagged'] ?? 0) < 1) {
            $failures[] = 'La higiene debe detectar near duplicates simples.';
        }
        if ((int) ($hygiene['suspicious_repetition_flagged'] ?? 0) < 3) {
            $failures[] = 'La higiene debe marcar repeticion sospechosa.';
        }
    }

    if (is_array($hardCasesArtifact)) {
        $schemaReport = ErpDatasetValidator::validateArtifact($hardCasesArtifact, ErpDatasetSupport::SCHEMA_HARD_CASES);
        if (($schemaReport['ok'] ?? false) !== true) {
            $failures[] = 'erp_hard_cases.json debe cumplir schema.';
        }

        $entries = is_array($hardCasesArtifact['entries'] ?? null) ? $hardCasesArtifact['entries'] : [];
        if (count($entries) !== 2) {
            $failures[] = 'erp_hard_cases.json debe exportar 2 casos tras dedupe.';
        }
        $first = $entries[0] ?? [];
        if (($first['expected_route_stage'] ?? '') === '') {
            $failures[] = 'Los hard cases deben preservar expected_route_stage.';
        }
    }

    if (is_array($vectorPrep)) {
        $entries = is_array($vectorPrep['entries'] ?? null) ? $vectorPrep['entries'] : [];
        if (count($entries) !== 7) {
            $failures[] = 'erp_vectorization_prep.json debe reflejar samples + hard cases exportados.';
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['collection'] ?? '') !== 'agent_training') {
                $failures[] = 'La preparacion Qdrant debe apuntar a agent_training.';
                break;
            }
        }
    }

    if (is_array($report)) {
        if ((int) (($report['stats']['training_samples'] ?? 0)) !== 5) {
            $failures[] = 'El reporte debe reflejar la cantidad final de training samples.';
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
 * @return array<string, mixed>
 */
function buildDatasetFixture(): array
{
    return [
        'metadata' => [
            'dataset_id' => 'erp_sales_training_v1',
            'dataset_version' => '1.0.0',
            'domain' => 'erp',
            'subdomain' => 'sales',
            'locale' => 'es-CO',
            'recommended_memory_type' => 'agent_training',
        ],
        'BLOQUE_A_intents_catalog' => [
            [
                'intent_key' => 'sales.create_invoice',
                'intent_name' => 'Crear factura',
                'description' => 'Crear factura de venta',
                'target_skill' => 'create_invoice',
                'skill_type' => 'tool',
                'required_action' => 'invoice.create',
                'risk_level' => 'high',
            ],
            [
                'intent_key' => 'sales.customer_lookup',
                'intent_name' => 'Buscar cliente',
                'description' => 'Resolver referencia de cliente',
                'target_skill' => 'customer_lookup',
                'skill_type' => 'tool',
                'risk_level' => 'low',
            ],
        ],
        'BLOQUE_B_training_samples' => [
            [
                'intent_key' => 'sales.create_invoice',
                'utterance' => 'Crear factura para ACME por 5 baterias',
                'target_skill' => 'create_invoice',
                'skill_type' => 'tool',
                'required_action' => 'invoice.create',
                'numeric_hints' => ['quantity' => 5],
            ],
            [
                'intent_key' => 'sales.create_invoice',
                'utterance' => 'Crear factura para ACME por 5 baterias',
                'target_skill' => 'create_invoice',
                'skill_type' => 'tool',
                'required_action' => 'invoice.create',
            ],
            [
                'intent_key' => 'sales.create_invoice',
                'utterance' => 'Crear factura para ACME por 5 bateria',
                'target_skill' => 'create_invoice',
                'skill_type' => 'tool',
                'required_action' => 'invoice.create',
            ],
            [
                'intent_key' => 'sales.create_invoice',
                'utterance' => 'Crear factura para BETA por 2 baterias',
                'target_skill' => 'create_invoice',
                'skill_type' => 'tool',
                'required_action' => 'invoice.create',
            ],
            [
                'intent_key' => 'sales.create_invoice',
                'utterance' => 'Crear factura para GAMMA por 7 baterias',
                'target_skill' => 'create_invoice',
                'skill_type' => 'tool',
                'required_action' => 'invoice.create',
            ],
            [
                'intent_key' => 'sales.customer_lookup',
                'utterance' => 'Buscar cliente ACME',
                'target_skill' => 'customer_lookup',
                'skill_type' => 'tool',
                'risk_level' => 'low',
            ],
            [
                'intent_key' => 'sales.customer_lookup',
                'utterance' => 'dummy',
                'target_skill' => 'customer_lookup',
                'skill_type' => 'tool',
                'risk_level' => 'low',
            ],
        ],
        'BLOQUE_C_hard_cases' => [
            [
                'intent_key' => 'sales.create_invoice',
                'utterance' => 'Vende 5 baterias a ACME y factura neto 30',
                'target_skill' => 'create_invoice',
                'skill_type' => 'tool',
                'required_action' => 'invoice.create',
                'expected_resolution' => 'resolve',
                'expected_route_stage' => 'skills',
                'expected_supervisor_flags' => [],
                'regression_tags' => ['router', 'sales'],
                'numeric_hints' => ['quantity' => 5],
            ],
            [
                'intent_key' => 'sales.create_invoice',
                'utterance' => 'Facturale eso a ACME',
                'target_skill' => 'create_invoice',
                'skill_type' => 'tool',
                'required_action' => 'invoice.create',
                'needs_clarification' => true,
                'ambiguity_flags' => ['missing_scope', 'customer_reference'],
                'expected_resolution' => 'clarify',
                'expected_route_stage' => 'skills',
                'expected_supervisor_flags' => ['insufficient_evidence'],
                'regression_tags' => ['clarification'],
            ],
            [
                'intent_key' => 'sales.create_invoice',
                'utterance' => 'Facturale eso a ACME',
                'target_skill' => 'create_invoice',
                'skill_type' => 'tool',
                'required_action' => 'invoice.create',
                'needs_clarification' => true,
                'ambiguity_flags' => ['missing_scope', 'customer_reference'],
                'expected_resolution' => 'clarify',
                'expected_route_stage' => 'skills',
                'expected_supervisor_flags' => ['insufficient_evidence'],
                'regression_tags' => ['clarification'],
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function writeJson(string $path, array $payload): void
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('No se pudo serializar fixture ERP.');
    }
    file_put_contents($path, $encoded . PHP_EOL);
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
 * @param array<int, string> $args
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
 * @return array<string, mixed>|null
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
