<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ErpDatasetSupport;
use App\Core\ErpDatasetValidator;

const ERP_GENERATOR_OUTPUT_FILE = FRAMEWORK_ROOT . '/training/output/erp_training_dataset_example/suki_erp_dataset.json';
const ERP_GENERATOR_MIN_TRAINING_SAMPLES = 800;
const ERP_GENERATOR_MIN_HARD_CASES = 150;
const ERP_GENERATOR_MAX_ATTEMPTS_MULTIPLIER = 80;
const ERP_GENERATOR_MAX_PREFIX_OCCURRENCES = 10;
const ERP_GENERATOR_SEMANTIC_DUPLICATE_THRESHOLD = 0.965;
const ERP_GENERATOR_HARD_CASE_CATEGORIES = [
    'ambiguity',
    'missing_information',
    'implicit_reference',
    'context_dependence',
    'colloquial_request',
    'partial_instruction',
    'low_evidence',
    'clarification_required',
];

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(erpGeneratorMain($argv));
}

function erpGeneratorMain(array $argv): int
{
    $args = array_slice($argv, 1);
    if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
        echo erpGeneratorHelpText();
        return 0;
    }

    try {
        $bundle = erpGeneratorGenerateBundle();
        $write = erpGeneratorWriteDatasetOrFail(
            is_array($bundle['dataset'] ?? null) ? $bundle['dataset'] : [],
            ERP_GENERATOR_OUTPUT_FILE
        );

        erpGeneratorWriteReport([
            'ok' => true,
            'output_file' => ERP_GENERATOR_OUTPUT_FILE,
            'output_file_repo_relative' => ErpDatasetSupport::relativeToRepo(ERP_GENERATOR_OUTPUT_FILE),
            'contracts' => $bundle['contracts'] ?? [],
            'stats' => [
                'intents_catalog' => (int) (($bundle['audit']['intents_count'] ?? 0)),
                'training_samples' => (int) (($bundle['audit']['training_samples_count'] ?? 0)),
                'hard_cases' => (int) (($bundle['audit']['hard_cases_count'] ?? 0)),
            ],
            'distribution' => is_array($bundle['audit']['per_intent_distribution'] ?? null)
                ? $bundle['audit']['per_intent_distribution']
                : [],
            'audit' => $bundle['audit'] ?? [],
            'validation' => $write,
            'next_step' => 'php framework/scripts/validate_erp_training_dataset.php framework/training/output/erp_training_dataset_example/suki_erp_dataset.json --strict',
        ], 0);
    } catch (Throwable $e) {
        erpGeneratorWriteReport([
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

    return 0;
}

/**
 * @return array<string, mixed>
 */
function erpGeneratorGenerateBundle(): array
{
    $contracts = erpGeneratorLoadContracts();
    $plan = erpGeneratorBuildIntentPlan();
    $pools = erpGeneratorBasePools();

    $intents = [];
    $samples = [];
    $hardCases = [];
    $audit = [
        'duplicates_removed' => 0,
        'exact_duplicates_removed' => 0,
        'semantic_duplicates_removed' => 0,
        'suspicious_repetition_flagged' => 0,
        'invalid_candidates_discarded' => 0,
        'generation_attempts' => 0,
        'per_intent_distribution' => [],
        'hard_cases_by_category' => [],
        'contract_sources_used' => [
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::DEFAULT_SKILLS_CATALOG),
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::DEFAULT_ACTION_CATALOG),
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::SCHEMA_INTENTS),
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::SCHEMA_SAMPLES),
            ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::SCHEMA_HARD_CASES),
        ],
    ];

    foreach ($plan as $intentKey => $config) {
        if (!is_array($config)) {
            continue;
        }

        $expanded = erpGeneratorExpandIntent($intentKey, $config, $contracts, $pools);
        $intents[] = $expanded['catalog_entry'];

        foreach ($expanded['samples'] as $entry) {
            $samples[] = $entry;
        }
        foreach ($expanded['hard_cases'] as $entry) {
            $hardCases[] = $entry;
        }

        foreach (['duplicates_removed', 'exact_duplicates_removed', 'semantic_duplicates_removed', 'suspicious_repetition_flagged', 'invalid_candidates_discarded', 'generation_attempts'] as $metric) {
            $audit[$metric] += (int) ($expanded['audit'][$metric] ?? 0);
        }
        $audit['per_intent_distribution'][$intentKey] = [
            'training_samples' => count($expanded['samples']),
            'hard_cases' => count($expanded['hard_cases']),
        ];
        foreach ((array) ($expanded['audit']['hard_cases_by_category'] ?? []) as $category => $count) {
            $audit['hard_cases_by_category'][$category] = (int) ($audit['hard_cases_by_category'][$category] ?? 0) + (int) $count;
        }
    }

    ksort($audit['per_intent_distribution']);
    ksort($audit['hard_cases_by_category']);

    $dataset = [
        'metadata' => [
            'dataset_id' => 'suki_erp_dataset',
            'dataset_version' => '1.1.0',
            'domain' => 'erp',
            'subdomain' => 'operations',
            'locale' => 'es-CO',
            'recommended_memory_type' => 'agent_training',
            'dataset_scope' => ErpDatasetSupport::SHARED_TRAINING_SCOPE,
            'tenant_data_allowed' => false,
            'generated_at' => gmdate('c'),
        ],
        'BLOQUE_A_intents_catalog' => $intents,
        'BLOQUE_B_training_samples' => $samples,
        'BLOQUE_C_hard_cases' => $hardCases,
    ];

    erpGeneratorAssertThresholds($dataset, $audit, $plan);

    return [
        'dataset' => $dataset,
        'contracts' => [
            'skills_catalog_version' => $contracts['skills_catalog_version'],
            'action_catalog_version' => $contracts['action_catalog_version'],
            'supported_execution_modes' => $contracts['skill_types'],
            'ambiguity_flags' => $contracts['ambiguity_flags'],
            'route_stages' => $contracts['route_stages'],
            'expected_resolutions' => $contracts['expected_resolutions'],
            'contract_sources_used' => $audit['contract_sources_used'],
        ],
        'audit' => array_merge($audit, [
            'intents_count' => count($intents),
            'training_samples_count' => count($samples),
            'hard_cases_count' => count($hardCases),
        ]),
    ];
}

/**
 * @param array<string, mixed> $dataset
 * @return array<string, mixed>
 */
function erpGeneratorWriteDatasetOrFail(array $dataset, string $outputFile): array
{
    $validation = ErpDatasetValidator::validate($dataset);
    if (($validation['ok'] ?? false) !== true) {
        $first = $validation['errors'][0]['message'] ?? 'Generated dataset failed ERP validation.';
        throw new RuntimeException($first);
    }
    if (!empty($validation['warnings'])) {
        $first = $validation['warnings'][0]['message'] ?? 'Generated dataset produced ERP validation warnings.';
        throw new RuntimeException($first);
    }

    $directory = dirname($outputFile);
    $tmpFile = $directory . DIRECTORY_SEPARATOR . '.tmp_suki_erp_dataset_' . uniqid('', true) . '.json';
    ErpDatasetSupport::ensureDirectory($directory);

    try {
        ErpDatasetSupport::writeJsonFile($tmpFile, $dataset);
        if (!@rename($tmpFile, $outputFile)) {
            throw new RuntimeException('No se pudo mover el dataset generado a ' . $outputFile);
        }
    } catch (Throwable $e) {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
        throw $e;
    }

    return [
        'ok' => true,
        'stats' => $validation['stats'] ?? [],
        'output_file' => $outputFile,
        'output_file_repo_relative' => ErpDatasetSupport::relativeToRepo($outputFile),
    ];
}

/**
 * @return array<string, mixed>
 */
function erpGeneratorLoadContracts(): array
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

    $intentFlags = erpGeneratorSchemaEnum($intentSchema, ['definitions', 'ambiguity_flags', 'items', 'enum'], 'intents ambiguity_flags');
    $sampleFlags = erpGeneratorSchemaEnum($samplesSchema, ['definitions', 'ambiguity_flags', 'items', 'enum'], 'samples ambiguity_flags');
    $hardCaseFlags = erpGeneratorSchemaEnum($hardCasesSchema, ['definitions', 'ambiguity_flags', 'items', 'enum'], 'hard cases ambiguity_flags');
    if ($intentFlags !== $sampleFlags || $intentFlags !== $hardCaseFlags) {
        throw new RuntimeException('ERP schemas disagree on ambiguity_flags enum.');
    }

    return [
        'skills' => $skillsCatalog['skills'],
        'actions' => $actionCatalog['actions'],
        'skills_catalog_version' => $skillsCatalog['version'],
        'action_catalog_version' => $actionCatalog['version'],
        'skill_types' => $skillTypes,
        'ambiguity_flags' => $intentFlags,
        'route_stages' => erpGeneratorSchemaEnum($hardCasesSchema, ['properties', 'entries', 'items', 'properties', 'expected_route_stage', 'enum'], 'hard cases route stages'),
        'expected_resolutions' => erpGeneratorSchemaEnum($hardCasesSchema, ['properties', 'entries', 'items', 'properties', 'expected_resolution', 'enum'], 'hard cases expected_resolution'),
        'supervisor_flags' => ErpDatasetSupport::loadSupervisorFlags(),
    ];
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $contracts
 * @param array<string, array<int, mixed>> $pools
 * @return array<string, mixed>
 */
function erpGeneratorExpandIntent(string $intentKey, array $config, array $contracts, array $pools): array
{
    $targetSkill = erpGeneratorRequiredString($config, 'target_skill', $intentKey);
    if (!isset($contracts['skills'][$targetSkill])) {
        throw new RuntimeException('Intent ' . $intentKey . ' references unknown skill: ' . $targetSkill);
    }

    $skillType = strtolower((string) ($contracts['skills'][$targetSkill]['execution_mode'] ?? ''));
    if ($skillType === '' || !in_array($skillType, $contracts['skill_types'], true)) {
        throw new RuntimeException('Intent ' . $intentKey . ' resolved invalid skill_type.');
    }

    $requiredAction = trim((string) ($config['required_action'] ?? ''));
    if ($requiredAction !== '' && !isset($contracts['actions'][$requiredAction])) {
        throw new RuntimeException('Intent ' . $intentKey . ' references unknown required_action: ' . $requiredAction);
    }

    $riskLevel = 'low';
    if ($requiredAction !== '') {
        $riskLevel = strtolower((string) ($contracts['actions'][$requiredAction]['risk_level'] ?? 'low'));
        if (!in_array($riskLevel, ErpDatasetSupport::RISK_LEVELS, true)) {
            throw new RuntimeException('Intent ' . $intentKey . ' resolved invalid risk_level.');
        }
    }

    $catalogEntry = [
        'intent_key' => $intentKey,
        'intent_name' => erpGeneratorRequiredString($config, 'intent_name', $intentKey),
        'description' => erpGeneratorRequiredString($config, 'description', $intentKey),
        'target_skill' => $targetSkill,
        'skill_type' => $skillType,
        'risk_level' => $riskLevel,
        'domain' => erpGeneratorRequiredString($config, 'domain', $intentKey),
        'subdomain' => erpGeneratorRequiredString($config, 'subdomain', $intentKey),
        'locale' => 'es-CO',
    ];
    if ($requiredAction !== '') {
        $catalogEntry['required_action'] = $requiredAction;
    }

    $sampleResult = erpGeneratorGenerateEntriesForIntent(
        $intentKey,
        $catalogEntry,
        $config,
        $contracts,
        $pools,
        false
    );
    $hardCaseResult = erpGeneratorGenerateEntriesForIntent(
        $intentKey,
        $catalogEntry,
        $config,
        $contracts,
        $pools,
        true
    );

    return [
        'catalog_entry' => $catalogEntry,
        'samples' => $sampleResult['entries'],
        'hard_cases' => $hardCaseResult['entries'],
        'audit' => [
            'duplicates_removed' => (int) $sampleResult['audit']['duplicates_removed'] + (int) $hardCaseResult['audit']['duplicates_removed'],
            'exact_duplicates_removed' => (int) $sampleResult['audit']['exact_duplicates_removed'] + (int) $hardCaseResult['audit']['exact_duplicates_removed'],
            'semantic_duplicates_removed' => (int) $sampleResult['audit']['semantic_duplicates_removed'] + (int) $hardCaseResult['audit']['semantic_duplicates_removed'],
            'suspicious_repetition_flagged' => (int) $sampleResult['audit']['suspicious_repetition_flagged'] + (int) $hardCaseResult['audit']['suspicious_repetition_flagged'],
            'invalid_candidates_discarded' => (int) $sampleResult['audit']['invalid_candidates_discarded'] + (int) $hardCaseResult['audit']['invalid_candidates_discarded'],
            'generation_attempts' => (int) $sampleResult['audit']['generation_attempts'] + (int) $hardCaseResult['audit']['generation_attempts'],
            'hard_cases_by_category' => $hardCaseResult['audit']['hard_cases_by_category'],
        ],
    ];
}

/**
 * @param array<string, mixed> $catalogEntry
 * @param array<string, mixed> $config
 * @param array<string, mixed> $contracts
 * @param array<string, array<int, mixed>> $pools
 * @return array<string, mixed>
 */
function erpGeneratorGenerateEntriesForIntent(
    string $intentKey,
    array $catalogEntry,
    array $config,
    array $contracts,
    array $pools,
    bool $isHardCase
): array {
    $targetCount = (int) ($config[$isHardCase ? 'hard_case_target' : 'sample_target'] ?? 0);
    $templates = erpGeneratorTemplatesForIntent($config, $isHardCase);
    if ($targetCount <= 0 || $templates === []) {
        throw new RuntimeException('Intent ' . $intentKey . ' is missing generation targets/templates.');
    }

    $entries = [];
    $dedupeKeys = [];
    $prefixCounts = [];
    $audit = [
        'duplicates_removed' => 0,
        'exact_duplicates_removed' => 0,
        'semantic_duplicates_removed' => 0,
        'suspicious_repetition_flagged' => 0,
        'invalid_candidates_discarded' => 0,
        'generation_attempts' => 0,
        'hard_cases_by_category' => [],
    ];

    $maxAttempts = max($targetCount * ERP_GENERATOR_MAX_ATTEMPTS_MULTIPLIER, count($templates) * 50);
    for ($attempt = 0; $attempt < $maxAttempts && count($entries) < $targetCount; $attempt++) {
        $audit['generation_attempts']++;
        $template = $templates[$attempt % count($templates)] ?? null;
        if (!is_array($template)) {
            $audit['invalid_candidates_discarded']++;
            continue;
        }

        $seed = intdiv($attempt, count($templates));
        $candidate = erpGeneratorBuildSourceEntry($intentKey, $catalogEntry, $config, $template, $seed, $attempt, $contracts, $pools, $isHardCase);
        $utterance = ErpDatasetSupport::normalizeText((string) ($candidate['utterance'] ?? ''));
        if ($utterance === '' || ErpDatasetSupport::isExtremeGarbage($utterance)) {
            $audit['invalid_candidates_discarded']++;
            continue;
        }

        $dedupeKey = ErpDatasetSupport::dedupeKey($utterance);
        if ($dedupeKey === '') {
            $audit['invalid_candidates_discarded']++;
            continue;
        }
        if (isset($dedupeKeys[$dedupeKey])) {
            $audit['duplicates_removed']++;
            $audit['exact_duplicates_removed']++;
            continue;
        }

        $isNearDuplicate = false;
        foreach ($entries as $accepted) {
            if (!is_array($accepted)) {
                continue;
            }
            if (ErpDatasetSupport::nearDuplicateScore((string) ($accepted['utterance'] ?? ''), $utterance) >= ERP_GENERATOR_SEMANTIC_DUPLICATE_THRESHOLD) {
                $isNearDuplicate = true;
                break;
            }
        }
        if ($isNearDuplicate) {
            $audit['duplicates_removed']++;
            $audit['semantic_duplicates_removed']++;
            continue;
        }

        $prefix = ErpDatasetSupport::tokenPrefix($utterance, 3);
        if ($prefix !== '') {
            $prefixKey = $intentKey . '|' . $prefix;
            if ((int) ($prefixCounts[$prefixKey] ?? 0) >= ERP_GENERATOR_MAX_PREFIX_OCCURRENCES) {
                $audit['suspicious_repetition_flagged']++;
                continue;
            }
            $prefixCounts[$prefixKey] = (int) ($prefixCounts[$prefixKey] ?? 0) + 1;
        }

        if ($isHardCase) {
            $category = trim((string) ($template['category'] ?? ''));
            if ($category === '' || !in_array($category, ERP_GENERATOR_HARD_CASE_CATEGORIES, true)) {
                throw new RuntimeException('Hard case template for ' . $intentKey . ' uses invalid category.');
            }
            $audit['hard_cases_by_category'][$category] = (int) ($audit['hard_cases_by_category'][$category] ?? 0) + 1;
        }

        $entries[] = $candidate;
        $dedupeKeys[$dedupeKey] = true;
    }

    if (count($entries) < $targetCount) {
        throw new RuntimeException(sprintf(
            'Generator could not reach target for %s (%s). Expected %d, got %d.',
            $intentKey,
            $isHardCase ? 'hard_cases' : 'training_samples',
            $targetCount,
            count($entries)
        ));
    }

    return ['entries' => $entries, 'audit' => $audit];
}

/**
 * @param array<string, mixed> $catalogEntry
 * @param array<string, mixed> $config
 * @param array<string, mixed> $template
 * @param array<string, mixed> $contracts
 * @param array<string, array<int, mixed>> $pools
 * @return array<string, mixed>
 */
function erpGeneratorBuildSourceEntry(
    string $intentKey,
    array $catalogEntry,
    array $config,
    array $template,
    int $seed,
    int $attempt,
    array $contracts,
    array $pools,
    bool $isHardCase
): array {
    $context = erpGeneratorContextValues($config);
    $rendered = erpGeneratorRenderTemplate(
        erpGeneratorRequiredString($template, 'template', $intentKey . '#template'),
        $intentKey,
        $seed,
        $attempt,
        $context,
        $pools
    );

    $entry = [
        'intent_key' => $intentKey,
        'utterance' => $rendered['utterance'],
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
    if (($rendered['numeric_hints'] ?? []) !== []) {
        $entry['numeric_hints'] = $rendered['numeric_hints'];
    }

    $flags = erpGeneratorNormalizeContractFlags($template['ambiguity_flags'] ?? [], $contracts['ambiguity_flags']);
    if ($flags !== []) {
        $entry['ambiguity_flags'] = $flags;
    }
    if ((bool) ($template['needs_clarification'] ?? ($flags !== []))) {
        $entry['needs_clarification'] = true;
    }

    if ($isHardCase) {
        $expectedResolution = strtolower((string) ($template['expected_resolution'] ?? 'clarify'));
        if (!in_array($expectedResolution, $contracts['expected_resolutions'], true)) {
            throw new RuntimeException('Hard case ' . $intentKey . ' uses invalid expected_resolution.');
        }
        $expectedRouteStage = strtolower((string) ($template['expected_route_stage'] ?? 'unknown'));
        if (!in_array($expectedRouteStage, $contracts['route_stages'], true)) {
            throw new RuntimeException('Hard case ' . $intentKey . ' uses invalid expected_route_stage.');
        }

        $category = trim((string) ($template['category'] ?? ''));
        $tags = array_values(array_unique(array_merge(
            ErpDatasetSupport::stringList($template['regression_tags'] ?? []),
            [$category, (string) $catalogEntry['domain']]
        )));

        $entry['expected_resolution'] = $expectedResolution;
        $entry['expected_route_stage'] = $expectedRouteStage;
        $entry['expected_supervisor_flags'] = erpGeneratorNormalizeSupervisorFlags(
            $template['expected_supervisor_flags'] ?? [],
            $contracts['supervisor_flags']
        );
        $entry['regression_tags'] = $tags;
    }

    return $entry;
}

/**
 * @param array<string, mixed> $config
 * @return array<int, array<string, mixed>>
 */
function erpGeneratorTemplatesForIntent(array $config, bool $isHardCase): array
{
    $strategy = (string) ($config['strategy'] ?? '');
    return match ($strategy) {
        'document_create' => erpGeneratorTemplatesDocumentCreate($isHardCase),
        'document_send' => erpGeneratorTemplatesDocumentSend($isHardCase),
        'lookup_party' => erpGeneratorTemplatesLookupParty($isHardCase),
        'stock_lookup' => erpGeneratorTemplatesStockLookup($isHardCase),
        'product_lookup' => erpGeneratorTemplatesProductLookup($isHardCase),
        'amount_record' => erpGeneratorTemplatesAmountRecord($isHardCase),
        'post_entry' => erpGeneratorTemplatesPostEntry($isHardCase),
        'report_generate' => erpGeneratorTemplatesReportGenerate($isHardCase),
        'record_create' => erpGeneratorTemplatesRecordCreate($isHardCase),
        'record_list' => erpGeneratorTemplatesRecordList($isHardCase),
        'search_entity' => erpGeneratorTemplatesSearchEntity($isHardCase),
        'resolve_entity' => erpGeneratorTemplatesResolveEntity($isHardCase),
        default => throw new RuntimeException('Unsupported generation strategy: ' . $strategy),
    };
}

/**
 * @return array<int, array<string, mixed>>
 */
function erpGeneratorTemplatesDocumentCreate(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'crear {object_singular} para {subject} por {qty} {item}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'emitir {object_singular} a {subject} por {qty} {item}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'genera la {object_singular} de {subject}, van {qty} {item}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'necesito facturar {qty} {item} para {subject}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'armame una {object_alt} para {subject} con {qty} {item}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'saca el {object_group} de venta para {subject} por {qty} {item}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'deja lista la {object_singular} de {subject} con {qty} {item} {term}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'registra una {object_singular} de {qty} {item} para {subject}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'me haces la {object_singular} de {qty} {item} para {subject}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'porfa factura {qty} {item} a {subject}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'hay que emitir {object_singular} para {subject} por {qty} {item}', 'numeric_hints' => ['quantity' => 'qty']],
            ['template' => 'generar {object_alt} de {subject}, {qty} {item} {term}', 'numeric_hints' => ['quantity' => 'qty']],
        ];
    }

    return [
        ['category' => 'implicit_reference', 'template' => '{verb_soft} {object_singular} eso a {subject}', 'ambiguity_flags' => ['missing_scope', 'customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'missing_information', 'template' => 'haz la {object_singular} de {subject}', 'ambiguity_flags' => ['missing_scope', 'product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'emitime la misma {object_singular} de ayer para {subject}', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'colloquial_request', 'template' => 'dejame lista la vuelta de {subject} con {item}', 'ambiguity_flags' => ['product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'partial_instruction', 'template' => '{verb_short} {qty} {item}', 'ambiguity_flags' => ['customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence'], 'numeric_hints' => ['quantity' => 'qty']],
        ['category' => 'low_evidence', 'template' => 'saca la {object_singular} pendiente', 'ambiguity_flags' => ['document_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'clarification_required', 'template' => 'vende {qty} {item} y dejalo {term}', 'ambiguity_flags' => ['customer_reference', 'payment_terms'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence'], 'numeric_hints' => ['quantity' => 'qty']],
        ['category' => 'ambiguity', 'template' => 'cobrale {qty} de eso a {subject}', 'ambiguity_flags' => ['product_reference', 'customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence'], 'numeric_hints' => ['quantity' => 'qty']],
        ['category' => 'context_dependence', 'template' => 'hace la {object_singular} para el cliente de ayer', 'ambiguity_flags' => ['customer_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'implicit_reference', 'template' => 'la {object_singular} del ultimo pedido de {subject}', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'colloquial_request', 'template' => 'facturame eso pa hoy', 'ambiguity_flags' => ['missing_scope', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'clarification_required', 'template' => 'vender {qty} {item} a {subject} y {verb_pay} {term}', 'ambiguity_flags' => ['payment_terms'], 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills', 'numeric_hints' => ['quantity' => 'qty']],
    ];
}

function erpGeneratorTemplatesDocumentSend(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'enviar {object_singular} {reference} a {subject} por {channel}'],
            ['template' => 'manda la {object_singular} {reference} {channel_contact} de {subject}'],
            ['template' => 'comparte la {object_alt} {reference} con {subject} por {channel}'],
            ['template' => 'reenvia la {object_singular} {reference} a {subject}'],
            ['template' => 'manda el {object_group} {reference} por {channel} a {subject}'],
            ['template' => 'porfa envia la {object_singular} {reference} al cliente {subject}'],
            ['template' => 'necesito remitir la {object_singular} {reference} a {subject}'],
            ['template' => 'enviame la {object_singular} {reference} {channel_contact}'],
            ['template' => 'me ayudas a compartir {reference} con {subject}'],
            ['template' => 'deja enviada la {object_singular} {reference} a {subject}'],
        ];
    }

    return [
        ['category' => 'context_dependence', 'template' => '{verb_send} la {object_singular} de ayer', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'implicit_reference', 'template' => 'manda la {object_singular} del ultimo pedido de {subject}', 'ambiguity_flags' => ['document_reference', 'customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'partial_instruction', 'template' => 'reenviala por su canal usual', 'ambiguity_flags' => ['document_reference', 'channel_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'colloquial_request', 'template' => 'pasale eso al cliente {subject}', 'ambiguity_flags' => ['document_reference', 'customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'missing_information', 'template' => '{verb_send} la {object_singular} {reference}', 'ambiguity_flags' => ['channel_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'low_evidence', 'template' => 'manda la ultima {object_singular}', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'clarification_required', 'template' => 'compartime la de {subject}', 'ambiguity_flags' => ['document_reference', 'customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'ambiguity', 'template' => 'manda la {object_singular} del cierre por {channel}', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'context_dependence', 'template' => 'envia la {object_singular} que acabamos de hacer', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'partial_instruction', 'template' => 'manda la {reference} por el canal del cliente', 'ambiguity_flags' => ['channel_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'colloquial_request', 'template' => 'dejale caer la {object_singular} {reference} a {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
        ['category' => 'clarification_required', 'template' => 'compartir {reference} con {subject} por {channel}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
    ];
}

function erpGeneratorTemplatesLookupParty(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'buscar {object_singular} {subject}'],
            ['template' => 'muestrame el {object_singular} {subject}'],
            ['template' => 'consulta {object_alt} {subject}'],
            ['template' => 'necesito ver el contacto de {subject}'],
            ['template' => 'revisa la ficha de {subject}'],
            ['template' => 'busca al {object_singular} {subject} por nit'],
            ['template' => 'encuentra a {subject}'],
            ['template' => 'trae datos del {object_singular} {subject}'],
            ['template' => 'quiero ver el historial de {subject}'],
            ['template' => 'me ayudas a ubicar a {subject}'],
        ];
    }

    return [
        ['category' => 'context_dependence', 'template' => 'busca el {object_singular} de ayer', 'ambiguity_flags' => ['customer_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'ambiguity', 'template' => 'muestrame la cuenta de la ferreteria', 'ambiguity_flags' => ['entity_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'busca el {object_singular} que compro {item}', 'ambiguity_flags' => ['customer_reference', 'product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'implicit_reference', 'template' => 'necesito el {object_alt} del ultimo pago', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'missing_information', 'template' => 'busca un {object_singular}', 'ambiguity_flags' => ['customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'colloquial_request', 'template' => 'ubica al {object_singular} que me llamo temprano', 'ambiguity_flags' => ['customer_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'partial_instruction', 'template' => 'trae al {object_singular} del pedido 2045', 'ambiguity_flags' => ['customer_reference', 'document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'low_evidence', 'template' => 'busca el {object_singular} importante', 'ambiguity_flags' => ['customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'clarification_required', 'template' => 'busca la cuenta del {object_singular} de la manana', 'ambiguity_flags' => ['customer_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'ambiguity', 'template' => 'muestrame ese {object_singular} de {subject}', 'ambiguity_flags' => ['customer_reference', 'entity_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'clarification_required', 'template' => 'consulta {subject} por el NIT', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'colloquial_request', 'template' => 'sacame rapido la ficha de {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
    ];
}

function erpGeneratorTemplatesStockLookup(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'consulta {object_singular} de {item}'],
            ['template' => 'revisa {object_alt} de {item}'],
            ['template' => 'hay existencia de {item}'],
            ['template' => 'cuanto {object_singular} queda de {item}'],
            ['template' => 'muestrame disponible de {item} en {warehouse}'],
            ['template' => 'necesito saber si quedan {item}'],
            ['template' => 'checa el {object_alt} de {item}'],
            ['template' => 'dime el disponible de {item} para hoy'],
            ['template' => 'revisame la bodega de {item}'],
            ['template' => 'verifica si {item} sigue disponible'],
        ];
    }

    return [
        ['category' => 'missing_information', 'template' => 'cuanto queda en bodega', 'ambiguity_flags' => ['inventory_reference', 'product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'implicit_reference', 'template' => 'revisa si hay de eso', 'ambiguity_flags' => ['product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'hay {object_alt} del pedido de ayer', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'low_evidence', 'template' => 'dime si me alcanza para el despacho de hoy', 'ambiguity_flags' => ['inventory_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'clarification_required', 'template' => 'mirame si queda algo en {warehouse}', 'ambiguity_flags' => ['product_reference', 'inventory_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'ambiguity', 'template' => 'revisa el {object_alt} de la referencia que vendimos ayer', 'ambiguity_flags' => ['product_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'partial_instruction', 'template' => 'checa existencias en {warehouse}', 'ambiguity_flags' => ['product_reference', 'inventory_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'colloquial_request', 'template' => 'aun queda de ese producto en la bodega', 'ambiguity_flags' => ['product_reference', 'inventory_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'context_dependence', 'template' => 'revisa el inventario de lo que entro esta manana', 'ambiguity_flags' => ['product_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'clarification_required', 'template' => 'cuanto queda de {item} en {warehouse}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'partial_instruction', 'template' => 'hay {object_singular} para {qty} {item}', 'ambiguity_flags' => ['inventory_reference'], 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills', 'numeric_hints' => ['quantity' => 'qty']],
        ['category' => 'colloquial_request', 'template' => 'me revisas si aun hay {item} en {warehouse}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
    ];
}

function erpGeneratorTemplatesProductLookup(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'buscar {object_singular} por sku {reference}'],
            ['template' => 'mostrar {object_alt} {item}'],
            ['template' => 'consulta {object_singular} codigo {reference}'],
            ['template' => 'encuentra la referencia {reference}'],
            ['template' => 'busca el {object_singular} {item}'],
            ['template' => 'necesito ver el {object_alt} {item}'],
            ['template' => 'localiza el sku {reference}'],
            ['template' => 'quiero el {object_singular} {item} por referencia'],
            ['template' => 'me ayudas a ubicar {item}'],
            ['template' => 'buscame el codigo de {item}'],
        ];
    }

    return [
        ['category' => 'ambiguity', 'template' => 'busca el {object_singular} azul', 'ambiguity_flags' => ['product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'context_dependence', 'template' => 'encuentra el {object_alt} del ultimo pedido', 'ambiguity_flags' => ['document_reference', 'product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'implicit_reference', 'template' => 'muestrame ese cable', 'ambiguity_flags' => ['product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'busca la referencia que vendimos ayer', 'ambiguity_flags' => ['product_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'missing_information', 'template' => 'busca un {object_singular}', 'ambiguity_flags' => ['product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'partial_instruction', 'template' => 'ubica la referencia del router', 'ambiguity_flags' => ['product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'colloquial_request', 'template' => 'sacame el {object_alt} de esa bateria', 'ambiguity_flags' => ['product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'low_evidence', 'template' => 'necesito el {object_singular} que siempre pide {subject}', 'ambiguity_flags' => ['product_reference', 'customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'clarification_required', 'template' => 'busca el {object_alt} de la caja nueva', 'ambiguity_flags' => ['product_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'clarification_required', 'template' => 'localiza {reference}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'partial_instruction', 'template' => 'busca {object_singular} codigo {reference}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'colloquial_request', 'template' => 'me ubicas rapido {item}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
    ];
}

function erpGeneratorTemplatesAmountRecord(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'registrar {object_singular} de {amount} por {subject}', 'numeric_hints' => ['amount' => 'amount']],
            ['template' => 'carga {object_singular} operativo de {amount} por {subject}', 'numeric_hints' => ['amount' => 'amount']],
            ['template' => 'captura {object_alt} de {amount} por {subject}', 'numeric_hints' => ['amount' => 'amount']],
            ['template' => 'anota un {object_singular} de {amount} para {subject}', 'numeric_hints' => ['amount' => 'amount']],
            ['template' => 'registrame el {object_singular} de {subject} por {amount}', 'numeric_hints' => ['amount' => 'amount']],
            ['template' => 'sube el {object_alt} de {amount} por {subject}', 'numeric_hints' => ['amount' => 'amount']],
            ['template' => 'me ayudas a registrar {amount} de {subject}', 'numeric_hints' => ['amount' => 'amount']],
            ['template' => 'pasa un {object_singular} por {amount} de {subject}', 'numeric_hints' => ['amount' => 'amount']],
            ['template' => 'deja contabilizado un {object_singular} de {amount} por {subject}', 'numeric_hints' => ['amount' => 'amount']],
            ['template' => 'registra un {object_alt} de {amount} para {subject}', 'numeric_hints' => ['amount' => 'amount']],
        ];
    }

    return [
        ['category' => 'missing_information', 'template' => 'registra un {object_singular} grande', 'ambiguity_flags' => ['numeric_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'partial_instruction', 'template' => 'carga lo de {subject}', 'ambiguity_flags' => ['numeric_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'mete el {object_singular} de ayer', 'ambiguity_flags' => ['numeric_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'low_evidence', 'template' => 'registra el {object_singular} del proveedor de siempre', 'ambiguity_flags' => ['entity_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'partial_instruction', 'template' => 'carga {amount} y ya', 'ambiguity_flags' => ['missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence'], 'numeric_hints' => ['amount' => 'amount']],
        ['category' => 'colloquial_request', 'template' => 'meteme ese {object_singular} por {amount}', 'ambiguity_flags' => ['missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback'], 'numeric_hints' => ['amount' => 'amount']],
        ['category' => 'clarification_required', 'template' => 'sube el {object_singular} del cierre de ayer', 'ambiguity_flags' => ['missing_scope', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'ambiguity', 'template' => 'registra el {object_singular} del pedido 2045', 'ambiguity_flags' => ['document_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'implicit_reference', 'template' => 'carga eso como {object_singular}', 'ambiguity_flags' => ['document_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'clarification_required', 'template' => 'registra {object_singular} de {amount} por {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills', 'numeric_hints' => ['amount' => 'amount']],
        ['category' => 'colloquial_request', 'template' => 'subime el {object_singular} de {subject} por {amount}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm', 'numeric_hints' => ['amount' => 'amount']],
        ['category' => 'partial_instruction', 'template' => 'anota el {object_alt} {amount} de {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills', 'numeric_hints' => ['amount' => 'amount']],
    ];
}

function erpGeneratorTemplatesPostEntry(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'contabilizar {object_singular} por {subject}'],
            ['template' => 'registrar {object_alt} contable de {subject}'],
            ['template' => 'postear {object_group} de {subject}'],
            ['template' => 'genera {object_group} por {subject}'],
            ['template' => 'deja contabilizado el {object_singular} de {subject}'],
            ['template' => 'hacer {object_singular} contable por {subject}'],
            ['template' => 'armar {object_group} para {subject}'],
            ['template' => 'necesito postear el movimiento de {subject}'],
            ['template' => 'registrame el {object_singular} de {subject}'],
            ['template' => 'sube la {object_group} de {subject}'],
        ];
    }

    return [
        ['category' => 'implicit_reference', 'template' => 'contabiliza eso en la {object_group}', 'ambiguity_flags' => ['document_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'postea el movimiento de ayer', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'colloquial_request', 'template' => 'haz el {object_singular} de eso', 'ambiguity_flags' => ['missing_scope', 'document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'low_evidence', 'template' => 'contabiliza lo del cierre raro', 'ambiguity_flags' => ['missing_scope', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'ambiguity', 'template' => 'sube la {object_group} de la compra', 'ambiguity_flags' => ['document_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'partial_instruction', 'template' => 'postea ese {object_alt}', 'ambiguity_flags' => ['document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'missing_information', 'template' => 'contabiliza un {object_singular}', 'ambiguity_flags' => ['missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'clarification_required', 'template' => 'registra el movimiento de la manana', 'ambiguity_flags' => ['time_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'context_dependence', 'template' => 'arma la {object_group} del ultimo ajuste', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'clarification_required', 'template' => 'contabiliza {object_singular} por {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'partial_instruction', 'template' => 'postear {object_alt} de {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'colloquial_request', 'template' => 'dejame posteado el movimiento de {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
    ];
}

function erpGeneratorTemplatesReportGenerate(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'generar {object_singular} de {subject}'],
            ['template' => 'mostrar {object_singular} {subject} de {period}'],
            ['template' => 'sacame el {object_singular} de {subject}'],
            ['template' => 'quiero el {object_singular} {subject} del {period}'],
            ['template' => 'trae el {object_singular} de {subject}'],
            ['template' => 'necesito ver el {object_singular} {subject} hoy'],
            ['template' => 'dame el {object_alt} de {subject}'],
            ['template' => 'revisa el {object_singular} {subject} del mes'],
            ['template' => 'pasame el {object_singular} de {subject}'],
            ['template' => 'genera el {object_alt} de {subject} para {period}'],
        ];
    }

    return [
        ['category' => 'context_dependence', 'template' => 'genera el {object_singular} del ultimo cierre raro', 'ambiguity_flags' => ['time_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'implicit_reference', 'template' => 'saca el {object_singular} de eso', 'ambiguity_flags' => ['missing_scope', 'document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'quiero el {object_alt} del cierre de ayer', 'ambiguity_flags' => ['time_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'low_evidence', 'template' => 'pasame el {object_singular} mas importante', 'ambiguity_flags' => ['missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'partial_instruction', 'template' => 'muestrame el {object_singular} de la manana', 'ambiguity_flags' => ['time_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'clarification_required', 'template' => '{object_singular} de {period}', 'ambiguity_flags' => ['missing_scope', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'ambiguity', 'template' => 'saca el {object_alt} de la cartera de ayer', 'ambiguity_flags' => ['time_reference'], 'expected_resolution' => 'resolve', 'expected_route_stage' => 'rag'],
        ['category' => 'colloquial_request', 'template' => 'me sacas rapidito el {object_singular} de {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
        ['category' => 'partial_instruction', 'template' => 'quiero el {object_singular} de {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'rules'],
        ['category' => 'ambiguity', 'template' => 'necesito el {object_alt} del ultimo corte de {subject}', 'ambiguity_flags' => ['time_reference'], 'expected_resolution' => 'resolve', 'expected_route_stage' => 'rag'],
        ['category' => 'clarification_required', 'template' => 'genera {object_singular} {subject} {period}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'rules'],
        ['category' => 'colloquial_request', 'template' => 'pasame el {object_singular} de {subject} cuando puedas', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
    ];
}

function erpGeneratorTemplatesRecordCreate(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'crear {object_singular} para {subject}'],
            ['template' => 'agrega {object_singular} de {subject}'],
            ['template' => 'programa {object_alt} para {subject}'],
            ['template' => 'deja una {object_singular} para {subject} {qualifier}'],
            ['template' => 'anota un pendiente de {subject}'],
            ['template' => 'me ayudas a crear {object_singular} sobre {subject}'],
            ['template' => 'abre seguimiento para {subject}'],
            ['template' => 'pon {object_singular} de {subject} con {filter}'],
            ['template' => 'crea un pendiente de {subject}'],
            ['template' => 'necesito una {object_singular} de {subject} {qualifier}'],
        ];
    }

    return [
        ['category' => 'implicit_reference', 'template' => 'crea una {object_singular} para eso', 'ambiguity_flags' => ['document_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'programa el pendiente del cliente de ayer', 'ambiguity_flags' => ['customer_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'missing_information', 'template' => 'deja seguimiento urgente', 'ambiguity_flags' => ['missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'ambiguity', 'template' => 'crea {object_singular} para lo del inventario', 'ambiguity_flags' => ['inventory_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'partial_instruction', 'template' => 'agrega un {object_alt} para manana', 'ambiguity_flags' => ['missing_scope', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'low_evidence', 'template' => 'dejame una {object_singular} importante', 'ambiguity_flags' => ['missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'clarification_required', 'template' => 'programa seguimiento a {subject}', 'ambiguity_flags' => ['missing_scope', 'customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'crea la {object_singular} del pedido que llego hoy', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'colloquial_request', 'template' => 'ponme una {object_singular} para {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
        ['category' => 'partial_instruction', 'template' => 'crear {object_singular} de {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'ambiguity', 'template' => 'abre pendiente de {subject} {qualifier}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'colloquial_request', 'template' => 'dejame ese seguimiento de {subject}', 'ambiguity_flags' => ['document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
    ];
}

function erpGeneratorTemplatesRecordList(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'listar {object_plural}'],
            ['template' => 'ver {object_alt_plural} activas'],
            ['template' => 'mostrar {object_plural} abiertas'],
            ['template' => 'que {object_plural} hay {period}'],
            ['template' => 'pasame las {object_plural} de {filter}'],
            ['template' => 'consulta {object_plural} pendientes'],
            ['template' => 'quiero ver las {object_plural} del equipo'],
            ['template' => 'muestra {object_plural} recientes'],
            ['template' => 'revisa {object_plural} abiertas'],
            ['template' => 'dame las {object_plural} activas de {filter}'],
            ['template' => 'necesito las {object_plural} con foco {filter}'],
            ['template' => 'trae las {object_plural} que siguen activas'],
            ['template' => 'consultame las {object_plural} del {period}'],
            ['template' => 'quiero el tablero de {object_plural} abiertas'],
            ['template' => 'mostrame {object_plural} vigentes'],
            ['template' => 'filtra las {object_plural} por {filter}'],
            ['template' => 'ver {object_plural} pendientes del {period}'],
            ['template' => 'pasame las {object_plural} que estan vivas'],
        ];
    }

    return [
        ['category' => 'implicit_reference', 'template' => 'muestrame las {object_plural} de eso', 'ambiguity_flags' => ['document_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'context_dependence', 'template' => 'ver {object_plural} del cliente de ayer', 'ambiguity_flags' => ['customer_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'low_evidence', 'template' => 'cuales son las {object_plural} graves', 'ambiguity_flags' => ['missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'colloquial_request', 'template' => 'que {object_plural} siguen abiertas ahi', 'ambiguity_flags' => ['missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'partial_instruction', 'template' => 'lista las {object_plural} de la manana', 'ambiguity_flags' => ['time_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'ambiguity', 'template' => 'muestra {object_plural} activas de {filter}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'clarification_required', 'template' => 'listar {object_plural} recientes', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'rules'],
        ['category' => 'context_dependence', 'template' => 'quiero ver las {object_plural} del ultimo corte', 'ambiguity_flags' => ['time_reference', 'document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'partial_instruction', 'template' => 'ver {object_plural} abiertas', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'rules'],
        ['category' => 'colloquial_request', 'template' => 'pasame rapidito las {object_plural} de hoy', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
        ['category' => 'ambiguity', 'template' => 'muestra las {object_plural} del pedido de ayer', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'clarification_required', 'template' => 'quiero ver {object_alt_plural} activas', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'rules'],
    ];
}

function erpGeneratorTemplatesSearchEntity(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'busca {entity_kind} {reference}'],
            ['template' => 'encuentra {entity_kind} {reference}'],
            ['template' => 'consulta {entity_kind} por {search_term}'],
            ['template' => 'localiza {entity_kind} {reference} en el sistema'],
            ['template' => 'quiero buscar {entity_kind} {search_term}'],
            ['template' => 'me buscas {entity_kind} {reference}'],
            ['template' => 'revisa si existe {entity_kind} {reference}'],
            ['template' => 'busca {entity_kind} con dato {search_term}'],
            ['template' => 'necesito encontrar {entity_kind} {reference}'],
            ['template' => 'haz una busqueda de {entity_kind} {search_term}'],
        ];
    }

    return [
        ['category' => 'context_dependence', 'template' => 'busca eso de ayer', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'context_dependence', 'template' => 'encuentra el registro del cliente que llamo', 'ambiguity_flags' => ['customer_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'busca la ultima venta rara', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'ambiguity', 'template' => 'localiza ese archivo', 'ambiguity_flags' => ['entity_reference', 'document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'partial_instruction', 'template' => 'busca el pedido de la manana', 'ambiguity_flags' => ['time_reference', 'document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'missing_information', 'template' => 'haz una busqueda', 'ambiguity_flags' => ['missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'implicit_reference', 'template' => 'busca ese documento', 'ambiguity_flags' => ['document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'colloquial_request', 'template' => 'me rastreas {entity_kind} {reference}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
        ['category' => 'clarification_required', 'template' => 'buscar {entity_kind} {reference}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'low_evidence', 'template' => 'encuentra la cosa esa del cierre', 'ambiguity_flags' => ['missing_scope', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'ambiguity', 'template' => 'consulta {entity_kind} por {search_term}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'colloquial_request', 'template' => 'buscame rapidito {entity_kind} {search_term}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
    ];
}

function erpGeneratorTemplatesResolveEntity(bool $isHardCase): array
{
    if (!$isHardCase) {
        return [
            ['template' => 'abre la ultima {entity_kind}'],
            ['template' => 'resuelve {entity_kind} de {period}'],
            ['template' => 'muestra {entity_kind} de {subject}'],
            ['template' => 'trae {entity_kind} reciente de {subject}'],
            ['template' => 'quiero ver la {entity_kind} del ultimo despacho'],
            ['template' => 'resuelve la referencia {entity_kind} {reference}'],
            ['template' => 'abre el ultimo {entity_kind} de {subject}'],
            ['template' => 'muestrame esa {entity_kind} que vimos'],
            ['template' => 'ubica la {entity_kind} mas reciente'],
            ['template' => 'trae la {entity_kind} de {period}'],
        ];
    }

    return [
        ['category' => 'context_dependence', 'template' => 'abre la de ayer', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'implicit_reference', 'template' => 'muestrame esa del cliente', 'ambiguity_flags' => ['entity_reference', 'customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'context_dependence', 'template' => 'resuelve la ultima que quedo pendiente', 'ambiguity_flags' => ['document_reference', 'missing_scope'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'colloquial_request', 'template' => 'abre ese pedido raro', 'ambiguity_flags' => ['entity_reference', 'document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'context_dependence', 'template' => 'la compra de la semana pasada', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['rag_weak_result']],
        ['category' => 'missing_information', 'template' => 'abre esa factura', 'ambiguity_flags' => ['document_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'skills', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'ambiguity', 'template' => 'resuelve el ultimo documento de {subject}', 'ambiguity_flags' => ['document_reference', 'customer_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'rag', 'expected_supervisor_flags' => ['insufficient_evidence']],
        ['category' => 'partial_instruction', 'template' => 'trae la ultima {entity_kind}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'clarification_required', 'template' => 'abre el ultimo {entity_kind} de {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
        ['category' => 'colloquial_request', 'template' => 'mostrame la ultima {entity_kind} de {subject}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'llm'],
        ['category' => 'low_evidence', 'template' => 'quiero ver eso ultimo del cierre', 'ambiguity_flags' => ['document_reference', 'time_reference'], 'needs_clarification' => true, 'expected_resolution' => 'clarify', 'expected_route_stage' => 'llm', 'expected_supervisor_flags' => ['weak_safe_fallback']],
        ['category' => 'partial_instruction', 'template' => 'resuelve {entity_kind} de {period}', 'expected_resolution' => 'resolve', 'expected_route_stage' => 'skills'],
    ];
}

/**
 * @return array<string, string>
 */
function erpGeneratorContextValues(array $config): array
{
    $poolRefs = erpGeneratorDefaultPoolRefs($config);

    return [
        'object_singular' => (string) ($config['object_singular'] ?? ''),
        'object_plural' => (string) ($config['object_plural'] ?? ''),
        'object_alt' => (string) ($config['object_alt'] ?? ''),
        'object_alt_plural' => (string) ($config['object_alt_plural'] ?? ((string) ($config['object_plural'] ?? ''))),
        'object_group' => (string) ($config['object_group'] ?? (string) ($config['object_singular'] ?? '')),
        'verb_soft' => (string) ($config['verb_soft'] ?? 'facturale'),
        'verb_short' => (string) ($config['verb_short'] ?? 'factura'),
        'verb_pay' => (string) ($config['verb_pay'] ?? 'factura'),
        'verb_send' => (string) ($config['verb_send'] ?? 'envia'),
        'subject' => 'pool:' . (string) ($config['subject_pool'] ?? $poolRefs['subject']),
        'item' => 'pool:' . (string) ($config['item_pool'] ?? $poolRefs['item']),
        'qty' => 'pool:' . (string) ($config['qty_pool'] ?? $poolRefs['qty']),
        'amount' => 'pool:' . (string) ($config['amount_pool'] ?? $poolRefs['amount']),
        'term' => 'pool:' . (string) ($config['term_pool'] ?? $poolRefs['term']),
        'reference' => 'pool:' . (string) ($config['reference_pool'] ?? $poolRefs['reference']),
        'channel' => 'pool:' . (string) ($config['channel_pool'] ?? $poolRefs['channel']),
        'channel_contact' => 'pool:' . (string) ($config['channel_contact_pool'] ?? $poolRefs['channel_contact']),
        'warehouse' => 'pool:' . (string) ($config['warehouse_pool'] ?? $poolRefs['warehouse']),
        'period' => 'pool:' . (string) ($config['period_pool'] ?? $poolRefs['period']),
        'filter' => 'pool:' . (string) ($config['filter_pool'] ?? $poolRefs['filter']),
        'qualifier' => 'pool:' . (string) ($config['qualifier_pool'] ?? $poolRefs['qualifier']),
        'entity_kind' => 'pool:' . (string) ($config['entity_kind_pool'] ?? $poolRefs['entity_kind']),
        'search_term' => 'pool:' . (string) ($config['search_term_pool'] ?? $poolRefs['search_term']),
    ];
}

/**
 * @param array<string, string> $context
 * @param array<string, array<int, mixed>> $pools
 * @return array<string, mixed>
 */
function erpGeneratorRenderTemplate(string $template, string $intentKey, int $seed, int $attempt, array $context, array $pools): array
{
    $selected = [];
    $rendered = preg_replace_callback('/\{([a-z_]+)\}/', function (array $matches) use ($intentKey, $seed, $attempt, $context, $pools, &$selected): string {
        $placeholder = (string) ($matches[1] ?? '');
        if (isset($context[$placeholder])) {
            $value = $context[$placeholder];
            if (is_string($value) && str_starts_with($value, 'pool:')) {
                $poolName = substr($value, 5);
                $pool = $pools[$poolName] ?? null;
                if (!is_array($pool) || $pool === []) {
                    throw new RuntimeException('Missing pool for placeholder {' . $placeholder . '}');
                }
                $option = erpGeneratorSelectPoolOption($pool, $intentKey, $poolName, $seed, $attempt, count($selected));
                $selected[$placeholder] = $option;
                return (string) $option['text'];
            }

            return (string) $value;
        }
        $pool = $pools[$placeholder] ?? null;
        if (!is_array($pool) || $pool === []) {
            throw new RuntimeException('Missing pool for placeholder {' . $placeholder . '}');
        }
        $option = erpGeneratorSelectPoolOption($pool, $intentKey, $placeholder, $seed, $attempt, count($selected));
        $selected[$placeholder] = $option;
        return (string) $option['text'];
    }, $template);

    if (!is_string($rendered)) {
        throw new RuntimeException('Could not render template for ' . $intentKey);
    }

    $numericHints = [];
    foreach ($selected as $placeholder => $option) {
        $value = ErpDatasetSupport::floatOrNull($option['value'] ?? null);
        if ($value === null) {
            continue;
        }
        if ($placeholder === 'qty') {
            $numericHints['quantity'] = $value;
        }
        if ($placeholder === 'amount') {
            $numericHints['amount'] = $value;
        }
    }

    return [
        'utterance' => ErpDatasetSupport::normalizeText($rendered),
        'numeric_hints' => $numericHints,
    ];
}

/**
 * @param array<int, mixed> $pool
 * @return array{text:string, value:mixed}
 */
function erpGeneratorSelectPoolOption(array $pool, string $intentKey, string $placeholder, int $seed, int $attempt, int $position): array
{
    $index = erpGeneratorStableIndex($intentKey . '|' . $placeholder . '|' . $seed . '|' . $attempt . '|' . $position, count($pool));
    $option = $pool[$index] ?? '';
    if (is_array($option)) {
        return [
            'text' => trim((string) ($option['text'] ?? '')),
            'value' => $option['value'] ?? null,
        ];
    }

    return ['text' => trim((string) $option), 'value' => null];
}

/**
 * @return array<string, array<int, mixed>>
 */
function erpGeneratorBasePools(): array
{
    return [
        'subject' => [
            'ACME SAS',
            'Nova Industrial',
            'Ferreteria Delta',
            'Clinica Norte',
            'Alimentos Sierra',
            'Textiles Aurora',
            'Distribuciones Andina',
            'Comercial Sol Naciente',
            'Servicios Integrados 24',
            'Laboratorio Central',
            'Hotel Mirador',
            'Autopartes Prisma',
            'Casa Omega',
            'Maderas del Valle',
            'Agroinsumos del Sur',
            'Constructora Horizonte',
            'Panaderia El Molino',
            'Papeleria Metropoli',
            'TecnoRed SAS',
            'Mercados El Portal',
            'Lubricantes Orion',
            'Reciclajes del Caribe',
            'Farmacia Punto Vital',
            'Bodega San Martin',
            'Plastinorte',
            'Minimarket Las Palmas',
            'Carnicos del Centro',
            'Electrolinea SAS',
            'Eventos Prisma',
            'Clinica Santa Isabel',
            'Tienda La 70',
            'Cafeteria Punto Uno',
            'Insumos Bioseguridad',
            'Importadora Andina',
            'Cooperativa El Progreso',
            'Centro Logistico Sur',
            'Muebles Altavista',
            'Seguridad Atlas',
            'Empaques del Pacifico',
            'Repuestos Titan',
        ],
        'customer' => [
            'ACME SAS',
            'Nova Industrial',
            'Ferreteria Delta',
            'Clinica Norte',
            'Alimentos Sierra',
            'Textiles Aurora',
            'Distribuciones Andina',
            'Comercial Sol Naciente',
            'Laboratorio Central',
            'Hotel Mirador',
            'Autopartes Prisma',
            'Casa Omega',
            'Maderas del Valle',
            'Agroinsumos del Sur',
            'Constructora Horizonte',
            'Panaderia El Molino',
            'Papeleria Metropoli',
            'TecnoRed SAS',
            'Mercados El Portal',
            'Lubricantes Orion',
            'Farmacia Punto Vital',
            'Bodega San Martin',
            'Plastinorte',
            'Minimarket Las Palmas',
            'Carnicos del Centro',
            'Electrolinea SAS',
            'Eventos Prisma',
            'Clinica Santa Isabel',
            'Tienda La 70',
            'Cafeteria Punto Uno',
            'Importadora Andina',
            'Cooperativa El Progreso',
        ],
        'sales_item' => [
            'baterias 12v',
            'rollos de cable UTP',
            'cajas de guantes nitrilo',
            'filtros de aceite',
            'botellas termicas',
            'impresoras termicas',
            'sensores de humo',
            'bombillos led 18w',
            'paquetes de hojas carta',
            'adaptadores HDMI',
            'kits de limpieza',
            'cargadores rapidos',
            'discos SSD 480GB',
            'router dual band',
            'toners negro 85A',
            'etiquetas adhesivas',
            'botiquines tipo A',
            'cables de poder',
            'repuestos para impresora',
            'motores monofasicos',
            'canastas plasticas',
            'extintores de 10 lb',
            'lectores de codigo',
            'tablets 10 pulgadas',
            'balanzas digitales',
            'sillas ejecutivas',
            'estanterias metalicas',
            'kits de bioseguridad',
            'aspersores industriales',
            'cintas de empaque',
            'candados laminados',
            'tableros electricos',
        ],
        'inventory_item' => [
            'bateria 12v referencia BT12',
            'cable UTP categoria 6',
            'guantes nitrilo talla M',
            'filtro de aceite FO-33',
            'botella termica 750ml',
            'impresora termica TP-58',
            'sensor de humo SH-200',
            'bombillo led 18w luz fria',
            'resma carta 75 gramos',
            'adaptador HDMI a VGA',
            'kit de limpieza industrial',
            'cargador rapido USB-C',
            'SSD 480GB SATA',
            'router dual band AX1800',
            'toner 85A compatible',
            'etiqueta 50x25 termica',
            'botiquin tipo A pared',
            'cable poder universal',
            'rodillo para impresora zebra',
            'motor monofasico 2HP',
            'canasta plastica apilable',
            'extintor ABC 10lb',
            'lector codigo 2D',
            'tablet 10 pulgadas 64GB',
            'balanza digital 40kg',
            'silla ejecutiva negra',
            'estanteria metalica 5 niveles',
            'kit bioseguridad visitante',
            'aspersor industrial metalico',
            'cinta empaque transparente',
            'candado laminado 50mm',
            'tablero electrico 12 circuitos',
            'bomba periferica 1HP',
            'manguera industrial 1 pulgada',
            'valvula bola PVC 2 pulgadas',
        ],
        'item' => [],
        'qty' => [
            ['text' => '3', 'value' => 3], ['text' => '4', 'value' => 4], ['text' => '5', 'value' => 5], ['text' => '6', 'value' => 6],
            ['text' => '8', 'value' => 8], ['text' => '10', 'value' => 10], ['text' => '12', 'value' => 12], ['text' => '15', 'value' => 15],
            ['text' => '18', 'value' => 18], ['text' => '20', 'value' => 20], ['text' => '24', 'value' => 24], ['text' => '30', 'value' => 30],
            ['text' => '36', 'value' => 36], ['text' => '48', 'value' => 48], ['text' => '60', 'value' => 60], ['text' => '72', 'value' => 72],
        ],
        'amount' => [
            ['text' => '55000', 'value' => 55000], ['text' => '85 mil', 'value' => 85000], ['text' => '120000', 'value' => 120000],
            ['text' => '145.000', 'value' => 145000], ['text' => '210000', 'value' => 210000], ['text' => '285 mil', 'value' => 285000],
            ['text' => '320000', 'value' => 320000], ['text' => '450000', 'value' => 450000], ['text' => '680000', 'value' => 680000],
            ['text' => '1.200.000', 'value' => 1200000],
        ],
        'term' => ['neto 30', 'a credito', 'contra entrega', 'con pago parcial', 'para pago hoy', 'con transferencia', 'con saldo a ocho dias', 'a contado'],
        'payment_term' => ['neto 30', 'a credito', 'contra entrega', 'a 15 dias', 'a contado', 'con pago hoy', 'con saldo a 8 dias', 'con transferencia'],
        'reference' => ['REF-1001', 'REF-1048', 'DOC-2055', 'DOC-2098', 'REG-3301', 'REG-3388', 'MOV-4412', 'MOV-4490', 'DOC-5507', 'DOC-5599'],
        'invoice_reference' => ['FAC-1001', 'FAC-1048', 'FV-2055', 'FV-2098', 'INV-3301', 'INV-3388', 'FC-4412', 'FC-4490', 'FACT-5507', 'FACT-5599', 'FV-6021', 'FV-6104'],
        'sku_reference' => ['SKU-BT12', 'SKU-UTP6', 'SKU-GLV-M', 'SKU-FO33', 'SKU-SSD48', 'SKU-RT1800', 'SKU-LED18', 'SKU-ET5025', 'SKU-LC2D', 'SKU-TAB10', 'REF-MTR2HP', 'REF-EXT10'],
        'entity_reference' => ['FAC-1001', 'PED-2045', 'CMP-3301', 'VEN-4412', 'CLI-208', 'PRV-077', 'ALT-039', 'REM-118', 'TSK-204', 'DOC-991'],
        'channel' => ['correo', 'whatsapp', 'email', 'correo de compras', 'correo de cartera', 'whatsapp del cliente', 'mail del area administrativa', 'correo de recepcion'],
        'channel_contact' => ['al correo de cobranza', 'al whatsapp del comprador', 'al mail de administracion', 'al correo registrado', 'al canal del cliente', 'al email de facturacion'],
        'warehouse' => ['bodega principal', 'bodega norte', 'almacen piso 1', 'punto de venta centro', 'sucursal sur', 'bodega de repuestos'],
        'period' => ['hoy', 'esta semana', 'este mes', 'el cierre de ayer', 'el ultimo corte', 'el trimestre actual', 'la semana pasada', 'el mes pasado', 'la quincena', 'el ultimo cierre'],
        'filter' => ['alta', 'media', 'baja', 'urgente', 'ventas', 'cobranza', 'operaciones', 'inventario', 'compras', 'administracion', 'hoy', 'esta semana'],
        'task_filter' => ['urgente', 'comercial', 'cartera', 'inventario', 'compras', 'logistica', 'soporte', 'administracion', 'hoy', 'esta semana'],
        'reminder_filter' => ['cobros', 'seguimientos', 'vencimientos', 'visitas', 'llamadas', 'envios', 'esta semana', 'hoy'],
        'alert_filter' => ['criticas', 'preventivas', 'de inventario', 'de caja', 'de cartera', 'operativas', 'de seguridad', 'de hoy'],
        'qualifier' => ['para hoy', 'para manana', 'antes del cierre', 'en la tarde', 'al final del dia', 'el lunes a primera hora'],
        'due_phrase' => ['para hoy', 'para manana', 'antes del cierre', 'en la tarde', 'al final del dia', 'al iniciar la jornada'],
        'remind_at_phrase' => ['manana a primera hora', 'hoy al mediodia', 'antes de las 4 pm', 'al cierre', 'el viernes en la manana', 'el lunes a las 8'],
        'alert_qualifier' => ['con prioridad alta', 'para revision hoy', 'antes del cierre', 'con seguimiento inmediato', 'para el turno de tarde', 'para primera hora'],
        'severity' => ['alta', 'media', 'critica', 'preventiva'],
        'entity_kind' => ['factura', 'venta', 'compra', 'pedido', 'cliente', 'proveedor', 'documento', 'alerta'],
        'entity_kind_search' => ['factura', 'venta', 'compra', 'pedido', 'cliente', 'proveedor', 'alerta', 'recordatorio', 'tarea', 'documento'],
        'entity_kind_resolve' => ['factura', 'pedido', 'compra', 'venta', 'alerta', 'recordatorio', 'tarea', 'documento'],
        'search_term' => ['ACME SAS', 'BAT-12V-01', 'pedido 2045', 'cartera vencida', 'FV-2055', 'cable UTP', 'alerta de caja', 'venta 3301', 'compra 4412', 'documento soporte'],
        'search_term_entity' => ['ACME SAS', 'SKU-BT12', 'pedido 2045', 'cartera vencida', 'FAC-1001', 'cable UTP', 'alerta de caja', 'venta 3301', 'compra 4412', 'documento soporte', 'recordatorio de cobro', 'tarea de despacho'],
        'customer_reference' => ['cliente principal', 'cliente de ayer', 'cliente de contado', 'comprador del pedido 2045'],
        'expense_subject' => [
            'mantenimiento preventivo',
            'flete urbano',
            'servicio de internet',
            'energia de bodega',
            'papeleria administrativa',
            'arriendo de montacarga',
            'viaticos de reparto',
            'limpieza de oficinas',
            'soporte tecnico',
            'seguridad privada',
            'servicio de mensajeria',
            'combustible de reparto',
            'reparacion de nevera',
            'calibracion de balanzas',
            'mantenimiento de impresora',
            'insumos de aseo',
            'dotacion operativa',
            'servicio de hosting',
            'asesoria contable',
            'suscripcion de telefonia',
        ],
        'accounting_subject' => [
            'ajuste de cierre mensual',
            'causacion de nomina',
            'reclasificacion de cartera',
            'amortizacion de seguro',
            'ajuste de inventario',
            'nota interna de tesoreria',
            'reversion de provisiones',
            'costo del despacho 2045',
            'cierre de caja',
            'saldo de anticipo proveedor',
            'diferencia en conciliacion',
            'gasto de mantenimiento',
            'ingreso no operacional',
            'provision de vacaciones',
            'depreciacion mensual',
        ],
        'report_topic' => [
            'ventas por cliente',
            'cartera vencida',
            'stock critico',
            'rotacion de inventario',
            'compras por proveedor',
            'gastos por centro de costo',
            'alertas activas',
            'tareas pendientes',
            'recordatorios vigentes',
            'flujo de caja',
            'ventas del canal mayorista',
            'despachos demorados',
            'margen por linea',
            'recaudo diario',
            'productos sin movimiento',
        ],
        'task_subject' => [
            'llamar a ACME SAS',
            'confirmar despacho de baterias',
            'revisar cartera vencida',
            'coordinar entrega del pedido 2045',
            'validar devolucion de cliente',
            'programar visita tecnica',
            'ajustar inventario del pasillo 3',
            'cerrar pendiente de facturacion',
            'enviar soporte a tesoreria',
            'confirmar pago de proveedor',
            'hacer seguimiento al reporte diario',
            'revisar alerta de stock critico',
            'actualizar lista de precios',
            'llamar a proveedor de cable UTP',
            'gestionar novedad de caja',
        ],
        'reminder_subject' => [
            'cobro de FV-2055',
            'vencimiento de seguro',
            'llamada a proveedor logistico',
            'seguimiento a devolucion',
            'recordar corte de caja',
            'aviso de pago de arriendo',
            'visita a cliente mayorista',
            'revision del pedido 2045',
            'envio de reporte semanal',
            'renovacion de soporte tecnico',
            'cierre de cartera',
            'seguimiento a compra 4412',
        ],
        'alert_subject' => [
            'stock critico de baterias 12v',
            'caja por revisar',
            'pedido represado',
            'documento pendiente de envio',
            'factura vencida',
            'alerta de cartera',
            'inventario en bodega norte',
            'despacho fuera de tiempo',
            'caida de margen comercial',
            'diferencia de conteo',
            'tarea atrasada',
            'recordatorio vencido',
        ],
    ];
}

/**
 * @return array<string, string>
 */
function erpGeneratorDefaultPoolRefs(array $config): array
{
    $strategy = (string) ($config['strategy'] ?? '');
    $subdomain = (string) ($config['subdomain'] ?? '');

    $subjectPool = match ($strategy) {
        'document_create', 'document_send', 'lookup_party' => 'customer',
        'amount_record' => 'expense_subject',
        'post_entry' => 'accounting_subject',
        'report_generate' => 'report_topic',
        'record_create', 'record_list' => match ($subdomain) {
            'tasks' => 'task_subject',
            'reminders' => 'reminder_subject',
            'alerts' => 'alert_subject',
            default => 'subject',
        },
        'search_entity', 'resolve_entity' => 'customer',
        default => 'subject',
    };

    $itemPool = match ($strategy) {
        'document_create', 'lookup_party' => 'sales_item',
        'stock_lookup', 'product_lookup' => 'inventory_item',
        default => 'sales_item',
    };

    $referencePool = match ($strategy) {
        'document_send' => 'invoice_reference',
        'product_lookup' => 'sku_reference',
        'search_entity', 'resolve_entity' => 'entity_reference',
        default => 'reference',
    };

    $filterPool = match ($subdomain) {
        'tasks' => 'task_filter',
        'reminders' => 'reminder_filter',
        'alerts' => 'alert_filter',
        default => 'filter',
    };

    $qualifierPool = match ($subdomain) {
        'tasks' => 'due_phrase',
        'reminders' => 'remind_at_phrase',
        'alerts' => 'alert_qualifier',
        default => 'qualifier',
    };

    return [
        'subject' => $subjectPool,
        'item' => $itemPool,
        'qty' => 'qty',
        'amount' => 'amount',
        'term' => $strategy === 'document_create' ? 'payment_term' : 'term',
        'reference' => $referencePool,
        'channel' => 'channel',
        'channel_contact' => 'channel_contact',
        'warehouse' => 'warehouse',
        'period' => 'period',
        'filter' => $filterPool,
        'qualifier' => $qualifierPool,
        'entity_kind' => match ($strategy) {
            'search_entity' => 'entity_kind_search',
            'resolve_entity' => 'entity_kind_resolve',
            default => 'entity_kind',
        },
        'search_term' => match ($strategy) {
            'search_entity' => 'search_term_entity',
            default => 'search_term',
        },
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function erpGeneratorBuildIntentPlan(): array
{
    return [
        'sales.create_invoice' => ['strategy' => 'document_create', 'intent_name' => 'Crear factura', 'description' => 'Crear factura de venta con datos sinteticos y no operativos.', 'domain' => 'sales', 'subdomain' => 'invoicing', 'target_skill' => 'create_invoice', 'required_action' => 'invoice.create', 'sample_target' => 70, 'hard_case_target' => 12, 'object_singular' => 'factura', 'object_plural' => 'facturas', 'object_alt' => 'invoice', 'object_alt_plural' => 'invoices', 'object_group' => 'comprobante', 'verb_soft' => 'facturale', 'verb_short' => 'factura', 'verb_pay' => 'factura'],
        'sales.send_invoice' => ['strategy' => 'document_send', 'intent_name' => 'Enviar factura', 'description' => 'Compartir una factura emitida por un canal permitido.', 'domain' => 'sales', 'subdomain' => 'delivery', 'target_skill' => 'send_invoice', 'sample_target' => 55, 'hard_case_target' => 12, 'object_singular' => 'factura', 'object_plural' => 'facturas', 'object_alt' => 'invoice', 'object_alt_plural' => 'invoices', 'object_group' => 'comprobante'],
        'sales.customer_lookup' => ['strategy' => 'lookup_party', 'intent_name' => 'Buscar cliente', 'description' => 'Consultar un cliente o tercero dentro del tenant activo.', 'domain' => 'sales', 'subdomain' => 'customers', 'target_skill' => 'customer_lookup', 'sample_target' => 55, 'hard_case_target' => 12, 'object_singular' => 'cliente', 'object_plural' => 'clientes', 'object_alt' => 'tercero', 'object_alt_plural' => 'terceros', 'object_group' => 'cliente'],
        'inventory.stock_lookup' => ['strategy' => 'stock_lookup', 'intent_name' => 'Consultar inventario', 'description' => 'Consultar stock disponible sin mutar inventario.', 'domain' => 'inventory', 'subdomain' => 'stock', 'target_skill' => 'inventory_check', 'sample_target' => 55, 'hard_case_target' => 12, 'object_singular' => 'inventario', 'object_plural' => 'inventarios', 'object_alt' => 'stock', 'object_alt_plural' => 'stocks', 'object_group' => 'inventario'],
        'inventory.product_lookup' => ['strategy' => 'product_lookup', 'intent_name' => 'Buscar producto', 'description' => 'Resolver un producto por sku, codigo o referencia.', 'domain' => 'inventory', 'subdomain' => 'products', 'target_skill' => 'product_lookup', 'sample_target' => 55, 'hard_case_target' => 12, 'object_singular' => 'producto', 'object_plural' => 'productos', 'object_alt' => 'item', 'object_alt_plural' => 'items', 'object_group' => 'referencia'],
        'accounting.register_expense' => ['strategy' => 'amount_record', 'intent_name' => 'Registrar gasto', 'description' => 'Registrar un gasto operativo sintetico.', 'domain' => 'accounting', 'subdomain' => 'expenses', 'target_skill' => 'register_expense', 'required_action' => 'crud.create', 'sample_target' => 60, 'hard_case_target' => 12, 'object_singular' => 'gasto', 'object_plural' => 'gastos', 'object_alt' => 'egreso', 'object_alt_plural' => 'egresos', 'object_group' => 'gasto'],
        'accounting.post_entry' => ['strategy' => 'post_entry', 'intent_name' => 'Contabilizar asiento', 'description' => 'Registrar un asiento o poliza contable sintetica.', 'domain' => 'accounting', 'subdomain' => 'journal', 'target_skill' => 'accounting_post', 'required_action' => 'crud.create', 'sample_target' => 50, 'hard_case_target' => 12, 'object_singular' => 'asiento', 'object_plural' => 'asientos', 'object_alt' => 'journal', 'object_alt_plural' => 'journals', 'object_group' => 'poliza'],
        'analytics.generate_report' => ['strategy' => 'report_generate', 'intent_name' => 'Generar reporte', 'description' => 'Consultar un reporte informativo sin mutaciones.', 'domain' => 'analytics', 'subdomain' => 'reporting', 'target_skill' => 'generate_report', 'required_action' => 'report.generate', 'sample_target' => 55, 'hard_case_target' => 12, 'object_singular' => 'reporte', 'object_plural' => 'reportes', 'object_alt' => 'informe', 'object_alt_plural' => 'informes', 'object_group' => 'reporte'],
        'ops.create_task' => ['strategy' => 'record_create', 'intent_name' => 'Crear tarea', 'description' => 'Crear tareas operativas sinteticas.', 'domain' => 'operations', 'subdomain' => 'tasks', 'target_skill' => 'create_task', 'required_action' => 'ops.task.create', 'sample_target' => 50, 'hard_case_target' => 12, 'object_singular' => 'tarea', 'object_plural' => 'tareas', 'object_alt' => 'task', 'object_alt_plural' => 'tasks', 'object_group' => 'seguimiento'],
        'ops.list_pending_tasks' => ['strategy' => 'record_list', 'intent_name' => 'Listar tareas pendientes', 'description' => 'Listar tareas operativas pendientes.', 'domain' => 'operations', 'subdomain' => 'tasks', 'target_skill' => 'list_pending_tasks', 'required_action' => 'ops.task.list_pending', 'sample_target' => 45, 'hard_case_target' => 12, 'object_singular' => 'tarea', 'object_plural' => 'tareas', 'object_alt' => 'task', 'object_alt_plural' => 'tasks', 'object_group' => 'seguimiento'],
        'ops.create_reminder' => ['strategy' => 'record_create', 'intent_name' => 'Crear recordatorio', 'description' => 'Crear recordatorios sinteticos para operacion.', 'domain' => 'operations', 'subdomain' => 'reminders', 'target_skill' => 'create_reminder', 'required_action' => 'ops.reminder.create', 'sample_target' => 50, 'hard_case_target' => 12, 'object_singular' => 'recordatorio', 'object_plural' => 'recordatorios', 'object_alt' => 'reminder', 'object_alt_plural' => 'reminders', 'object_group' => 'recordatorio'],
        'ops.list_reminders' => ['strategy' => 'record_list', 'intent_name' => 'Listar recordatorios', 'description' => 'Listar recordatorios activos.', 'domain' => 'operations', 'subdomain' => 'reminders', 'target_skill' => 'list_reminders', 'required_action' => 'ops.reminder.list', 'sample_target' => 45, 'hard_case_target' => 12, 'object_singular' => 'recordatorio', 'object_plural' => 'recordatorios', 'object_alt' => 'reminder', 'object_alt_plural' => 'reminders', 'object_group' => 'recordatorio'],
        'ops.create_alert' => ['strategy' => 'record_create', 'intent_name' => 'Crear alerta', 'description' => 'Crear alertas operativas sinteticas.', 'domain' => 'operations', 'subdomain' => 'alerts', 'target_skill' => 'create_alert', 'required_action' => 'ops.alert.create', 'sample_target' => 50, 'hard_case_target' => 12, 'object_singular' => 'alerta', 'object_plural' => 'alertas', 'object_alt' => 'alert', 'object_alt_plural' => 'alerts', 'object_group' => 'alerta'],
        'ops.list_alerts' => ['strategy' => 'record_list', 'intent_name' => 'Listar alertas', 'description' => 'Listar alertas operativas.', 'domain' => 'operations', 'subdomain' => 'alerts', 'target_skill' => 'list_alerts', 'required_action' => 'ops.alert.list', 'sample_target' => 45, 'hard_case_target' => 12, 'object_singular' => 'alerta', 'object_plural' => 'alertas', 'object_alt' => 'alert', 'object_alt_plural' => 'alerts', 'object_group' => 'alerta'],
        'search.entity_search' => ['strategy' => 'search_entity', 'intent_name' => 'Buscar entidad', 'description' => 'Buscar entidades dentro del tenant actual.', 'domain' => 'search', 'subdomain' => 'entity_search', 'target_skill' => 'entity_search', 'required_action' => 'entity.search', 'sample_target' => 55, 'hard_case_target' => 12, 'object_singular' => 'entidad', 'object_plural' => 'entidades', 'object_alt' => 'registro', 'object_alt_plural' => 'registros', 'object_group' => 'entidad'],
        'search.entity_resolve' => ['strategy' => 'resolve_entity', 'intent_name' => 'Resolver referencia', 'description' => 'Resolver referencias ambiguas antes de actuar.', 'domain' => 'search', 'subdomain' => 'entity_resolve', 'target_skill' => 'entity_resolve', 'required_action' => 'entity.resolve', 'sample_target' => 45, 'hard_case_target' => 12, 'object_singular' => 'referencia', 'object_plural' => 'referencias', 'object_alt' => 'referencia', 'object_alt_plural' => 'referencias', 'object_group' => 'referencia'],
    ];
}

/**
 * @param array<string, mixed> $dataset
 * @param array<string, mixed> $audit
 * @param array<string, array<string, mixed>> $plan
 */
function erpGeneratorAssertThresholds(array $dataset, array $audit, array $plan): void
{
    if (count((array) ($dataset['BLOQUE_B_training_samples'] ?? [])) < ERP_GENERATOR_MIN_TRAINING_SAMPLES) {
        throw new RuntimeException('Generator produced insufficient training samples.');
    }
    if (count((array) ($dataset['BLOQUE_C_hard_cases'] ?? [])) < ERP_GENERATOR_MIN_HARD_CASES) {
        throw new RuntimeException('Generator produced insufficient hard cases.');
    }
    foreach ($plan as $intentKey => $config) {
        $distribution = $audit['per_intent_distribution'][$intentKey] ?? null;
        if (!is_array($distribution)) {
            throw new RuntimeException('Missing audit distribution for ' . $intentKey);
        }
        if ((int) ($distribution['training_samples'] ?? 0) < (int) ($config['sample_target'] ?? 0)) {
            throw new RuntimeException('Training sample target not met for ' . $intentKey);
        }
        if ((int) ($distribution['hard_cases'] ?? 0) < (int) ($config['hard_case_target'] ?? 0)) {
            throw new RuntimeException('Hard case target not met for ' . $intentKey);
        }
    }
    foreach (ERP_GENERATOR_HARD_CASE_CATEGORIES as $category) {
        if ((int) ($audit['hard_cases_by_category'][$category] ?? 0) <= 0) {
            throw new RuntimeException('Hard case category missing from audit: ' . $category);
        }
    }
}

/**
 * @param array<int, mixed> $flags
 * @param array<int, string> $allowedFlags
 * @return array<int, string>
 */
function erpGeneratorNormalizeContractFlags($flags, array $allowedFlags): array
{
    if (!is_array($flags)) {
        return [];
    }
    $normalized = [];
    foreach ($flags as $flag) {
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
function erpGeneratorNormalizeSupervisorFlags($flags, array $allowedFlags): array
{
    if (!is_array($flags)) {
        return [];
    }
    $normalized = [];
    foreach ($flags as $flag) {
        $candidate = trim((string) $flag);
        if ($candidate === '' || !in_array($candidate, $allowedFlags, true)) {
            throw new RuntimeException('Generator uses unknown supervisor flag: ' . (string) $flag);
        }
        $normalized[] = $candidate;
    }
    return array_values(array_unique($normalized));
}

function erpGeneratorSchemaEnum(array $schema, array $path, string $label): array
{
    $value = $schema;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            throw new RuntimeException('Missing enum in ' . $label . '.');
        }
        $value = $value[$segment];
    }
    if (!is_array($value) || $value === []) {
        throw new RuntimeException('Empty enum in ' . $label . '.');
    }
    return array_values(array_unique(array_map(static fn($item): string => strtolower(trim((string) $item)), $value)));
}

function erpGeneratorRequiredString(array $source, string $field, string $context): string
{
    $value = trim((string) ($source[$field] ?? ''));
    if ($value === '') {
        throw new RuntimeException('Missing required field ' . $field . ' for ' . $context . '.');
    }
    return $value;
}

function erpGeneratorStableIndex(string $seed, int $size): int
{
    return $size > 0 ? (int) (sprintf('%u', crc32($seed)) % $size) : 0;
}

/**
 * @param array<string, mixed> $report
 */
function erpGeneratorWriteReport(array $report, int $exitCode): void
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function erpGeneratorHelpText(): string
{
    return implode(PHP_EOL, [
        'Usage:',
        '  php framework/scripts/generate_erp_training_dataset.php',
        '',
        'Output:',
        '  ' . ErpDatasetSupport::relativeToRepo(ERP_GENERATOR_OUTPUT_FILE),
        '',
        'Mass dataset targets:',
        '  - training samples >= ' . ERP_GENERATOR_MIN_TRAINING_SAMPLES,
        '  - hard cases >= ' . ERP_GENERATOR_MIN_HARD_CASES,
    ]) . PHP_EOL;
}
