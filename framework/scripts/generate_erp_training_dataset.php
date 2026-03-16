<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ErpDatasetSupport;
use App\Core\ErpDatasetValidator;
const GENERATOR_OUTPUT_FILE = FRAMEWORK_ROOT . '/training/output/erp_training_dataset_example/suki_erp_dataset.json';

$args = array_slice($argv, 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    printHelp();
    exit(0);
}

try {
    $contracts = loadGeneratorContracts();
    $dataset = buildDataset($contracts);
    $validation = ErpDatasetValidator::validate($dataset);
    if (($validation['ok'] ?? false) !== true) {
        throw new RuntimeException('Generated dataset failed ERP validation.');
    }
    if (!empty($validation['warnings'])) {
        throw new RuntimeException('Generated dataset produced ERP validation warnings.');
    }

    ErpDatasetSupport::ensureDirectory(dirname(GENERATOR_OUTPUT_FILE));
    ErpDatasetSupport::writeJsonFile(GENERATOR_OUTPUT_FILE, $dataset);

    $distribution = [];
    foreach ($dataset['BLOQUE_B_training_samples'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $intentKey = (string) ($entry['intent_key'] ?? '');
        if ($intentKey === '') {
            continue;
        }
        $distribution[$intentKey] = ($distribution[$intentKey] ?? 0) + 1;
    }
    ksort($distribution);

    writeReportAndExit([
        'ok' => true,
        'output_file' => GENERATOR_OUTPUT_FILE,
        'output_file_repo_relative' => ErpDatasetSupport::relativeToRepo(GENERATOR_OUTPUT_FILE),
        'contracts' => [
            'skills_catalog_version' => $contracts['skills_catalog_version'],
            'action_catalog_version' => $contracts['action_catalog_version'],
            'supported_execution_modes' => $contracts['skill_types'],
            'route_stages' => $contracts['route_stages'],
            'ambiguity_flags' => $contracts['ambiguity_flags'],
        ],
        'stats' => [
            'intents_catalog' => count($dataset['BLOQUE_A_intents_catalog']),
            'training_samples' => count($dataset['BLOQUE_B_training_samples']),
            'hard_cases' => count($dataset['BLOQUE_C_hard_cases']),
        ],
        'distribution' => $distribution,
        'validation' => [
            'ok' => true,
            'stats' => $validation['stats'] ?? [],
        ],
        'next_step' => 'php framework/scripts/validate_erp_training_dataset.php framework/training/output/erp_training_dataset_example/suki_erp_dataset.json --strict',
    ], 0);
} catch (Throwable $e) {
    writeReportAndExit([
        'ok' => false,
        'error' => $e->getMessage(),
        'required_inputs' => [
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::DEFAULT_SKILLS_CATALOG),
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::DEFAULT_ACTION_CATALOG),
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::SCHEMA_INTENTS),
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::SCHEMA_SAMPLES),
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::SCHEMA_HARD_CASES),
        ],
    ], 1);
}

/**
 * @return array<string, mixed>
 */
function loadGeneratorContracts(): array
{
    $skillsRaw = ErpDatasetSupport::loadJsonFile(ErpDatasetSupport::DEFAULT_SKILLS_CATALOG, 'skills_catalog');
    $intentSchema = ErpDatasetSupport::loadJsonFile(ErpDatasetSupport::SCHEMA_INTENTS, 'erp_intents_catalog.schema');
    $samplesSchema = ErpDatasetSupport::loadJsonFile(ErpDatasetSupport::SCHEMA_SAMPLES, 'erp_training_samples.schema');
    $hardCasesSchema = ErpDatasetSupport::loadJsonFile(ErpDatasetSupport::SCHEMA_HARD_CASES, 'erp_hard_cases.schema');

    $skillsCatalog = ErpDatasetSupport::loadSkillsCatalog();
    $actionCatalog = ErpDatasetSupport::loadActionCatalog();

    $skillTypes = ErpDatasetSupport::stringList($skillsRaw['supported_execution_modes'] ?? []);
    if ($skillTypes === []) {
        throw new RuntimeException('skills_catalog does not expose supported_execution_modes.');
    }

    $intentFlags = schemaEnum($intentSchema, ['definitions', 'ambiguity_flags', 'items', 'enum'], 'intents ambiguity_flags');
    $sampleFlags = schemaEnum($samplesSchema, ['definitions', 'ambiguity_flags', 'items', 'enum'], 'samples ambiguity_flags');
    $hardCaseFlags = schemaEnum($hardCasesSchema, ['definitions', 'ambiguity_flags', 'items', 'enum'], 'hard cases ambiguity_flags');
    if ($intentFlags !== $sampleFlags || $intentFlags !== $hardCaseFlags) {
        throw new RuntimeException('ERP schemas disagree on ambiguity_flags enum.');
    }

    $routeStages = schemaEnum(
        $hardCasesSchema,
        ['properties', 'entries', 'items', 'properties', 'expected_route_stage', 'enum'],
        'hard cases route stages'
    );
    $expectedResolutions = schemaEnum(
        $hardCasesSchema,
        ['properties', 'entries', 'items', 'properties', 'expected_resolution', 'enum'],
        'hard cases expected_resolution'
    );

    return [
        'skills' => $skillsCatalog['skills'],
        'actions' => $actionCatalog['actions'],
        'skills_catalog_version' => $skillsCatalog['version'],
        'action_catalog_version' => $actionCatalog['version'],
        'skill_types' => $skillTypes,
        'ambiguity_flags' => $intentFlags,
        'route_stages' => $routeStages,
        'expected_resolutions' => $expectedResolutions,
        'supervisor_flags' => ErpDatasetSupport::loadSupervisorFlags(),
    ];
}

/**
 * @param array<string, mixed> $contracts
 * @return array<string, mixed>
 */
function buildDataset(array $contracts): array
{
    $plan = buildIntentPlan();
    $metadata = [
        'dataset_id' => 'suki_erp_dataset',
        'dataset_version' => '1.0.0',
        'domain' => 'erp',
        'subdomain' => 'operations',
        'locale' => 'es-CO',
        'recommended_memory_type' => 'agent_training',
        'dataset_scope' => ErpDatasetSupport::SHARED_TRAINING_SCOPE,
        'tenant_data_allowed' => false,
        'generated_at' => gmdate('c'),
    ];

    $intents = [];
    $samples = [];
    $hardCases = [];

    foreach ($plan as $intentKey => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $validatedIntent = validateIntentDefinition($intentKey, $definition, $contracts);
        $intents[] = $validatedIntent['catalog_entry'];

        foreach ($validatedIntent['samples'] as $sample) {
            $samples[] = $sample;
        }
        foreach ($validatedIntent['hard_cases'] as $hardCase) {
            $hardCases[] = $hardCase;
        }
    }

    return [
        'metadata' => $metadata,
        'BLOQUE_A_intents_catalog' => $intents,
        'BLOQUE_B_training_samples' => $samples,
        'BLOQUE_C_hard_cases' => $hardCases,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function buildIntentPlan(): array
{
    return [
        'sales.create_invoice' => [
            'intent_name' => 'Crear factura',
            'description' => 'Crear factura de venta con datos operativos basicos.',
            'domain' => 'sales',
            'subdomain' => 'invoicing',
            'target_skill' => 'create_invoice',
            'required_action' => 'invoice.create',
            'samples' => [
                ['utterance' => 'Crear factura para ACME por 5 baterias', 'numeric_hints' => ['quantity' => 5]],
                ['utterance' => 'Emitir factura a BETA por 2 llantas', 'numeric_hints' => ['quantity' => 2]],
                ['utterance' => 'Generar invoice para Ferreteria Centro por 10 cables UTP', 'numeric_hints' => ['quantity' => 10]],
                ['utterance' => 'Facturar venta a Cliente Mostrador por 3 botellas de agua', 'numeric_hints' => ['quantity' => 3]],
            ],
            'hard_cases' => [
                [
                    'utterance' => 'Facturale eso a ACME',
                    'needs_clarification' => true,
                    'ambiguity_flags' => ['missing_scope', 'customer_reference'],
                    'expected_resolution' => 'clarify',
                    'expected_route_stage' => 'skills',
                    'expected_supervisor_flags' => ['insufficient_evidence'],
                    'regression_tags' => ['clarification', 'sales'],
                ],
                [
                    'utterance' => 'Vender 5 baterias a ACME y factura neto 30',
                    'ambiguity_flags' => ['payment_terms'],
                    'expected_resolution' => 'resolve',
                    'expected_route_stage' => 'skills',
                    'regression_tags' => ['router', 'sales'],
                    'numeric_hints' => ['quantity' => 5],
                ],
            ],
        ],
        'sales.send_invoice' => [
            'intent_name' => 'Enviar factura',
            'description' => 'Compartir una factura ya emitida por un canal permitido.',
            'domain' => 'sales',
            'subdomain' => 'delivery',
            'target_skill' => 'send_invoice',
            'samples' => [
                ['utterance' => 'Enviar factura F-1001 por correo a ACME'],
                ['utterance' => 'Mandar invoice FV-55 al cliente BETA'],
                ['utterance' => 'Comparte la factura FAC-77 por WhatsApp'],
            ],
            'hard_cases' => [
                [
                    'utterance' => 'Envia la factura de ayer',
                    'needs_clarification' => true,
                    'ambiguity_flags' => ['document_reference', 'time_reference'],
                    'expected_resolution' => 'clarify',
                    'expected_route_stage' => 'rag',
                    'expected_supervisor_flags' => ['rag_weak_result'],
                    'regression_tags' => ['delivery', 'time_reference'],
                ],
                [
                    'utterance' => 'Manda la factura a su canal habitual',
                    'needs_clarification' => true,
                    'ambiguity_flags' => ['document_reference', 'channel_reference'],
                    'expected_resolution' => 'clarify',
                    'expected_route_stage' => 'rag',
                    'expected_supervisor_flags' => ['insufficient_evidence'],
                    'regression_tags' => ['delivery', 'channel_reference'],
                ],
            ],
        ],
        'sales.customer_lookup' => [
            'intent_name' => 'Buscar cliente',
            'description' => 'Consultar un cliente o tercero dentro del tenant activo.',
            'domain' => 'sales',
            'subdomain' => 'customers',
            'target_skill' => 'customer_lookup',
            'samples' => [
                ['utterance' => 'Buscar cliente ACME por NIT'],
                ['utterance' => 'Muestrame el cliente BETA'],
                ['utterance' => 'Consultar tercero Ferreteria Centro'],
            ],
            'hard_cases' => [
                [
                    'utterance' => 'Busca el cliente de ayer',
                    'needs_clarification' => true,
                    'ambiguity_flags' => ['customer_reference', 'time_reference'],
                    'expected_resolution' => 'clarify',
                    'expected_route_stage' => 'rag',
                    'expected_supervisor_flags' => ['insufficient_evidence'],
                    'regression_tags' => ['lookup', 'customer_reference'],
                ],
            ],
        ],
        'inventory.stock_lookup' => [
            'intent_name' => 'Consultar inventario',
            'description' => 'Consultar stock disponible sin mutar inventario.',
            'domain' => 'inventory',
            'subdomain' => 'stock',
            'target_skill' => 'inventory_check',
            'samples' => [
                ['utterance' => 'Consulta inventario de baterias'],
                ['utterance' => 'Revisa stock disponible de llantas'],
                ['utterance' => 'Hay existencia de cables UTP'],
            ],
            'hard_cases' => [
                [
                    'utterance' => 'Cuanto queda en bodega',
                    'needs_clarification' => true,
                    'ambiguity_flags' => ['inventory_reference', 'product_reference'],
                    'expected_resolution' => 'clarify',
                    'expected_route_stage' => 'skills',
                    'expected_supervisor_flags' => ['insufficient_evidence'],
                    'regression_tags' => ['inventory', 'clarification'],
                ],
            ],
        ],
        'inventory.product_lookup' => [
            'intent_name' => 'Buscar producto',
            'description' => 'Resolver un producto por sku, codigo o referencia.',
            'domain' => 'inventory',
            'subdomain' => 'products',
            'target_skill' => 'product_lookup',
            'samples' => [
                ['utterance' => 'Buscar producto por sku BAT-01'],
                ['utterance' => 'Mostrar item bateria 12V'],
                ['utterance' => 'Consulta producto codigo CAB-UTP-5'],
            ],
            'hard_cases' => [
                [
                    'utterance' => 'Busca el producto azul',
                    'needs_clarification' => true,
                    'ambiguity_flags' => ['product_reference'],
                    'expected_resolution' => 'clarify',
                    'expected_route_stage' => 'rag',
                    'expected_supervisor_flags' => ['rag_weak_result'],
                    'regression_tags' => ['product_lookup', 'clarification'],
                ],
            ],
        ],
        'accounting.register_expense' => [
            'intent_name' => 'Registrar gasto',
            'description' => 'Registrar un gasto operativo para posterior contabilizacion.',
            'domain' => 'accounting',
            'subdomain' => 'expenses',
            'target_skill' => 'register_expense',
            'required_action' => 'crud.create',
            'samples' => [
                ['utterance' => 'Registrar gasto de 55000 por gasolina', 'numeric_hints' => ['amount' => 55000]],
                ['utterance' => 'Cargar gasto operativo de 120000 por internet', 'numeric_hints' => ['amount' => 120000]],
                ['utterance' => 'Captura egreso de 45000 por papeleria', 'numeric_hints' => ['amount' => 45000]],
            ],
            'hard_cases' => [
                [
                    'utterance' => 'Registra un gasto grande',
                    'needs_clarification' => true,
                    'ambiguity_flags' => ['numeric_reference', 'missing_scope'],
                    'expected_resolution' => 'clarify',
                    'expected_route_stage' => 'skills',
                    'expected_supervisor_flags' => ['insufficient_evidence'],
                    'regression_tags' => ['expense', 'clarification'],
                ],
            ],
        ],
        'accounting.post_entry' => [
            'intent_name' => 'Contabilizar asiento',
            'description' => 'Registrar un asiento o poliza dentro del flujo contable.',
            'domain' => 'accounting',
            'subdomain' => 'journal',
            'target_skill' => 'accounting_post',
            'required_action' => 'crud.create',
            'samples' => [
                ['utterance' => 'Contabilizar asiento por pago de arriendo'],
                ['utterance' => 'Registrar movimiento contable de nomina'],
                ['utterance' => 'Postear journal de cierre diario'],
            ],
            'hard_cases' => [
                [
                    'utterance' => 'Contabiliza eso en la poliza',
                    'needs_clarification' => true,
                    'ambiguity_flags' => ['document_reference', 'missing_scope'],
                    'expected_resolution' => 'clarify',
                    'expected_route_stage' => 'rag',
                    'expected_supervisor_flags' => ['insufficient_evidence'],
                    'regression_tags' => ['accounting', 'document_reference'],
                ],
            ],
        ],
        'analytics.generate_report' => [
            'intent_name' => 'Generar reporte',
            'description' => 'Consultar un reporte sin ejecutar mutaciones de negocio.',
            'domain' => 'analytics',
            'subdomain' => 'reporting',
            'target_skill' => 'generate_report',
            'required_action' => 'report.generate',
            'samples' => [
                ['utterance' => 'Generar reporte de ventas de hoy'],
                ['utterance' => 'Mostrar reporte financiero del mes'],
                ['utterance' => 'Sacar reporte de utilidad bruta'],
            ],
            'hard_cases' => [
                [
                    'utterance' => 'Genera el reporte del ultimo cierre raro',
                    'needs_clarification' => true,
                    'ambiguity_flags' => ['time_reference', 'missing_scope'],
                    'expected_resolution' => 'clarify',
                    'expected_route_stage' => 'rag',
                    'expected_supervisor_flags' => ['rag_weak_result'],
                    'regression_tags' => ['reporting', 'time_reference'],
                ],
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $definition
 * @param array<string, mixed> $contracts
 * @return array{catalog_entry: array<string, mixed>, samples: array<int, array<string, mixed>>, hard_cases: array<int, array<string, mixed>>}
 */
function validateIntentDefinition(string $intentKey, array $definition, array $contracts): array
{
    $targetSkill = requiredString($definition, 'target_skill', $intentKey);
    if (!isset($contracts['skills'][$targetSkill])) {
        throw new RuntimeException('Intent ' . $intentKey . ' references unknown skill: ' . $targetSkill);
    }

    $skillType = strtolower((string) ($contracts['skills'][$targetSkill]['execution_mode'] ?? ''));
    if ($skillType === '' || !in_array($skillType, $contracts['skill_types'], true)) {
        throw new RuntimeException('Intent ' . $intentKey . ' resolved invalid skill_type for ' . $targetSkill . '.');
    }

    $requiredAction = trim((string) ($definition['required_action'] ?? ''));
    if ($requiredAction !== '' && !isset($contracts['actions'][$requiredAction])) {
        throw new RuntimeException('Intent ' . $intentKey . ' references unknown required_action: ' . $requiredAction);
    }

    $riskLevel = 'low';
    if ($requiredAction !== '') {
        $riskLevel = strtolower((string) ($contracts['actions'][$requiredAction]['risk_level'] ?? 'low'));
        if (!in_array($riskLevel, ErpDatasetSupport::RISK_LEVELS, true)) {
            throw new RuntimeException('Intent ' . $intentKey . ' resolved invalid risk_level from action catalog.');
        }
    }

    $catalogEntry = [
        'intent_key' => $intentKey,
        'intent_name' => requiredString($definition, 'intent_name', $intentKey),
        'description' => requiredString($definition, 'description', $intentKey),
        'target_skill' => $targetSkill,
        'skill_type' => $skillType,
        'risk_level' => $riskLevel,
        'domain' => requiredString($definition, 'domain', $intentKey),
        'subdomain' => requiredString($definition, 'subdomain', $intentKey),
        'locale' => 'es-CO',
    ];
    if ($requiredAction !== '') {
        $catalogEntry['required_action'] = $requiredAction;
    }

    $samples = [];
    foreach (($definition['samples'] ?? []) as $index => $sampleDefinition) {
        if (!is_array($sampleDefinition)) {
            continue;
        }
        $samples[] = buildSourceSample(
            $intentKey,
            $sampleDefinition,
            $catalogEntry,
            $contracts,
            false,
            $index
        );
    }

    $hardCases = [];
    foreach (($definition['hard_cases'] ?? []) as $index => $hardCaseDefinition) {
        if (!is_array($hardCaseDefinition)) {
            continue;
        }
        $hardCases[] = buildSourceSample(
            $intentKey,
            $hardCaseDefinition,
            $catalogEntry,
            $contracts,
            true,
            $index
        );
    }

    if ($samples === []) {
        throw new RuntimeException('Intent ' . $intentKey . ' must generate at least one training sample.');
    }
    if ($hardCases === []) {
        throw new RuntimeException('Intent ' . $intentKey . ' must generate at least one hard case.');
    }

    return [
        'catalog_entry' => $catalogEntry,
        'samples' => $samples,
        'hard_cases' => $hardCases,
    ];
}

/**
 * @param array<string, mixed> $sampleDefinition
 * @param array<string, mixed> $catalogEntry
 * @param array<string, mixed> $contracts
 * @return array<string, mixed>
 */
function buildSourceSample(
    string $intentKey,
    array $sampleDefinition,
    array $catalogEntry,
    array $contracts,
    bool $isHardCase,
    int $index
): array {
    $entry = [
        'intent_key' => $intentKey,
        'utterance' => requiredString($sampleDefinition, 'utterance', $intentKey . '#' . $index),
        'target_skill' => $catalogEntry['target_skill'],
        'skill_type' => $catalogEntry['skill_type'],
        'risk_level' => $catalogEntry['risk_level'],
        'domain' => $catalogEntry['domain'],
        'subdomain' => $catalogEntry['subdomain'],
        'locale' => 'es-CO',
    ];

    if (isset($catalogEntry['required_action'])) {
        $entry['required_action'] = $catalogEntry['required_action'];
    }

    $ambiguityFlags = normalizeContractFlags((array) ($sampleDefinition['ambiguity_flags'] ?? []), $contracts['ambiguity_flags']);
    if ($ambiguityFlags !== []) {
        $entry['ambiguity_flags'] = $ambiguityFlags;
    }

    $needsClarification = (bool) ($sampleDefinition['needs_clarification'] ?? ($ambiguityFlags !== []));
    if ($needsClarification) {
        $entry['needs_clarification'] = true;
    }

    $numericHints = ErpDatasetSupport::normalizeNumericHints($sampleDefinition['numeric_hints'] ?? []);
    if ($numericHints !== []) {
        $entry['numeric_hints'] = $numericHints;
    }

    if ($isHardCase) {
        $expectedResolution = strtolower((string) ($sampleDefinition['expected_resolution'] ?? 'clarify'));
        if (!in_array($expectedResolution, $contracts['expected_resolutions'], true)) {
            throw new RuntimeException('Hard case ' . $intentKey . ' uses invalid expected_resolution: ' . $expectedResolution);
        }
        $expectedRouteStage = strtolower((string) ($sampleDefinition['expected_route_stage'] ?? 'unknown'));
        if (!in_array($expectedRouteStage, $contracts['route_stages'], true)) {
            throw new RuntimeException('Hard case ' . $intentKey . ' uses invalid expected_route_stage: ' . $expectedRouteStage);
        }

        $entry['expected_resolution'] = $expectedResolution;
        $entry['expected_route_stage'] = $expectedRouteStage;
        $entry['expected_supervisor_flags'] = normalizeSupervisorFlags(
            (array) ($sampleDefinition['expected_supervisor_flags'] ?? []),
            $contracts['supervisor_flags']
        );
        $entry['regression_tags'] = array_values(
            array_unique(ErpDatasetSupport::stringList($sampleDefinition['regression_tags'] ?? ['router_regression']))
        );
    }

    return $entry;
}

/**
 * @param array<int, mixed> $flags
 * @param array<int, string> $allowedFlags
 * @return array<int, string>
 */
function normalizeContractFlags(array $flags, array $allowedFlags): array
{
    $normalized = [];
    foreach ($flags as $flag) {
        if (!is_string($flag) && !is_numeric($flag)) {
            continue;
        }
        $candidate = strtolower(trim((string) $flag));
        if ($candidate === '' || !in_array($candidate, $allowedFlags, true)) {
            throw new RuntimeException('Generator uses invalid ambiguity_flag: ' . (string) $flag);
        }
        $normalized[] = $candidate;
    }

    return array_values(array_unique($normalized));
}

/**
 * @param array<int, mixed> $flags
 * @param array<int, string> $allowedFlags
 * @return array<int, string>
 */
function normalizeSupervisorFlags(array $flags, array $allowedFlags): array
{
    $normalized = [];
    foreach ($flags as $flag) {
        if (!is_string($flag) && !is_numeric($flag)) {
            continue;
        }
        $candidate = trim((string) $flag);
        if ($candidate === '' || !in_array($candidate, $allowedFlags, true)) {
            throw new RuntimeException('Generator uses unknown supervisor flag: ' . (string) $flag);
        }
        $normalized[] = $candidate;
    }

    return array_values(array_unique($normalized));
}

/**
 * @param array<string, mixed> $schema
 * @param array<int, string> $path
 * @return array<int, string>
 */
function schemaEnum(array $schema, array $path, string $label): array
{
    $value = nestedValue($schema, $path);
    if (!is_array($value)) {
        throw new RuntimeException('Missing enum in ' . $label . '.');
    }

    $result = [];
    foreach ($value as $item) {
        if (!is_string($item) && !is_numeric($item)) {
            continue;
        }
        $text = trim((string) $item);
        if ($text !== '') {
            $result[] = strtolower($text);
        }
    }

    $result = array_values(array_unique($result));
    if ($result === []) {
        throw new RuntimeException('Empty enum in ' . $label . '.');
    }

    return $result;
}

/**
 * @param array<string, mixed> $source
 * @param array<int, string> $path
 * @return mixed
 */
function nestedValue(array $source, array $path)
{
    $current = $source;
    foreach ($path as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return null;
        }
        $current = $current[$segment];
    }

    return $current;
}

/**
 * @param array<string, mixed> $source
 */
function requiredString(array $source, string $field, string $context): string
{
    $value = trim((string) ($source[$field] ?? ''));
    if ($value === '') {
        throw new RuntimeException('Missing required field ' . $field . ' for ' . $context . '.');
    }

    return $value;
}

/**
 * @param array<string, mixed> $report
 */
function writeReportAndExit(array $report, int $exitCode): void
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function printHelp(): void
{
    echo "Usage:\n";
    echo "  php framework/scripts/generate_erp_training_dataset.php\n\n";
    echo "Output:\n";
    echo '  ' . ErpDatasetSupport::relativeToRepo(GENERATOR_OUTPUT_FILE) . "\n\n";
    echo "Contracts loaded at startup:\n";
    echo '  - ' . ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::DEFAULT_SKILLS_CATALOG) . "\n";
    echo '  - ' . ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::DEFAULT_ACTION_CATALOG) . "\n";
    echo '  - ' . ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::SCHEMA_INTENTS) . "\n";
    echo '  - ' . ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::SCHEMA_SAMPLES) . "\n";
    echo '  - ' . ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::SCHEMA_HARD_CASES) . "\n";
}
