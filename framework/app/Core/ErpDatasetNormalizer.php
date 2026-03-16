<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ErpDatasetNormalizer
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function prepare(array $payload, array $options = []): array
    {
        $validation = ErpDatasetValidator::validate($payload, $options);
        if (($validation['ok'] ?? false) !== true) {
            $first = $validation['errors'][0]['message'] ?? 'Dataset ERP invalido.';
            throw new RuntimeException($first);
        }

        $metadata = ErpDatasetSupport::resolveMetadata($payload);
        $skillsCatalog = ErpDatasetSupport::loadSkillsCatalog();
        $actionCatalog = ErpDatasetSupport::loadActionCatalog();

        $artifactMetadata = [
            'dataset_id' => $metadata['dataset_id'],
            'dataset_version' => $metadata['dataset_version'],
            'domain' => $metadata['domain'],
            'subdomain' => $metadata['subdomain'],
            'locale' => $metadata['locale'],
            'recommended_memory_type' => $metadata['recommended_memory_type'],
            'generated_at' => gmdate('c'),
            'skills_catalog_version' => $skillsCatalog['version'],
            'action_catalog_version' => $actionCatalog['version'],
            'tenant_data_allowed' => false,
            'dataset_scope' => ErpDatasetSupport::SHARED_TRAINING_SCOPE,
        ];

        $sourceIntents = ErpDatasetSupport::resolveBlock($payload, 'intents_catalog');
        $sourceSamples = ErpDatasetSupport::resolveBlock($payload, 'training_samples');
        $sourceHardCases = ErpDatasetSupport::resolveBlock($payload, 'hard_cases');

        $intentDefaults = self::buildIntentDefaults($sourceIntents, $artifactMetadata, $skillsCatalog['skills'], $actionCatalog['actions']);
        $sampleResult = self::normalizeTrainingEntries(
            $sourceSamples,
            $intentDefaults,
            $artifactMetadata,
            $skillsCatalog['skills'],
            $actionCatalog['actions'],
            false
        );
        $hardCaseResult = self::normalizeTrainingEntries(
            $sourceHardCases,
            $intentDefaults,
            $artifactMetadata,
            $skillsCatalog['skills'],
            $actionCatalog['actions'],
            true
        );

        $intentsEntries = self::normalizeIntentEntries(
            $intentDefaults,
            $sourceIntents,
            $sampleResult['entries'],
            $hardCaseResult['entries'],
            $artifactMetadata
        );

        $intentsArtifact = [
            'artifact_type' => 'erp_intents_catalog',
            'schema_version' => ErpDatasetSupport::ARTIFACT_SCHEMA_VERSION,
            'metadata' => $artifactMetadata,
            'entries' => $intentsEntries,
            'hygiene_summary' => [
                'source_entries' => count($sourceIntents),
                'exported_entries' => count($intentsEntries),
                'exact_duplicates_removed' => 0,
                'near_duplicates_flagged' => 0,
                'suspicious_repetition_flagged' => 0,
                'garbage_rejected' => 0,
                'warnings_count' => count($validation['warnings'] ?? []),
            ],
        ];

        $samplesArtifact = [
            'artifact_type' => 'erp_training_samples',
            'schema_version' => ErpDatasetSupport::ARTIFACT_SCHEMA_VERSION,
            'metadata' => $artifactMetadata,
            'entries' => $sampleResult['entries'],
            'hygiene_summary' => $sampleResult['hygiene_summary'],
        ];

        $hardCasesArtifact = [
            'artifact_type' => 'erp_hard_cases',
            'schema_version' => ErpDatasetSupport::ARTIFACT_SCHEMA_VERSION,
            'metadata' => $artifactMetadata,
            'entries' => $hardCaseResult['entries'],
            'hygiene_summary' => $hardCaseResult['hygiene_summary'],
        ];

        ErpDatasetValidator::validateArtifactOrFail($intentsArtifact, ErpDatasetSupport::SCHEMA_INTENTS);
        ErpDatasetValidator::validateArtifactOrFail($samplesArtifact, ErpDatasetSupport::SCHEMA_SAMPLES);
        ErpDatasetValidator::validateArtifactOrFail($hardCasesArtifact, ErpDatasetSupport::SCHEMA_HARD_CASES);

        $vectorizationPrep = self::buildVectorizationPrep(
            $artifactMetadata,
            $sampleResult['entries'],
            $hardCaseResult['entries']
        );

        $report = [
            'ok' => true,
            'pipeline' => 'erp_training_dataset_prepare',
            'trace' => [
                'dataset_scope' => ErpDatasetSupport::SHARED_TRAINING_SCOPE,
                'router_boundary' => 'offline_pre_runtime',
                'generated_at' => gmdate('c'),
            ],
            'stats' => [
                'intents_catalog' => count($intentsEntries),
                'training_samples' => count($sampleResult['entries']),
                'hard_cases' => count($hardCaseResult['entries']),
                'vectorization_entries' => count($vectorizationPrep['entries']),
            ],
            'warnings' => $validation['warnings'] ?? [],
            'validation_stats' => $validation['stats'] ?? [],
            'hygiene' => [
                'training_samples' => $sampleResult['hygiene_summary'],
                'hard_cases' => $hardCaseResult['hygiene_summary'],
            ],
            'artifacts' => [
                'erp_intents_catalog.json',
                'erp_training_samples.json',
                'erp_hard_cases.json',
                'erp_vectorization_prep.json',
                'erp_pipeline_report.json',
            ],
        ];

        return [
            'metadata' => $artifactMetadata,
            'validation' => $validation,
            'erp_intents_catalog' => $intentsArtifact,
            'erp_training_samples' => $samplesArtifact,
            'erp_hard_cases' => $hardCasesArtifact,
            'erp_vectorization_prep' => $vectorizationPrep,
            'erp_pipeline_report' => $report,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $sourceIntents
     * @param array<string, mixed> $artifactMetadata
     * @param array<string, array<string, mixed>> $skillsCatalog
     * @param array<string, array<string, mixed>> $actionCatalog
     * @return array<string, array<string, mixed>>
     */
    private static function buildIntentDefaults(
        array $sourceIntents,
        array $artifactMetadata,
        array $skillsCatalog,
        array $actionCatalog
    ): array {
        $result = [];
        foreach ($sourceIntents as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $intentKey = ErpDatasetSupport::stringValue($entry, ['intent_key', 'intent', 'name']);
            if ($intentKey === '') {
                continue;
            }

            $targetSkill = ErpDatasetSupport::stringValue($entry, ['target_skill', 'skill']);
            $requiredAction = ErpDatasetSupport::stringValue($entry, ['required_action']);
            $skillType = self::resolveSkillType($entry, $targetSkill, $skillsCatalog);
            $riskLevel = self::resolveRiskLevel($entry, $requiredAction, $actionCatalog, 'medium');
            $flags = self::resolveAmbiguityFlags($entry, []);

            $result[$intentKey] = [
                'intent_key' => $intentKey,
                'intent_name' => ErpDatasetSupport::stringValue($entry, ['intent_name', 'title', 'name'], $intentKey),
                'description' => ErpDatasetSupport::stringValue($entry, ['description', 'summary']),
                'target_skill' => $targetSkill,
                'skill_type' => $skillType,
                'required_action' => $requiredAction,
                'risk_level' => $riskLevel,
                'needs_clarification' => array_key_exists('needs_clarification', $entry)
                    ? ErpDatasetSupport::boolValue($entry['needs_clarification'])
                    : ($flags !== []),
                'ambiguity_flags' => $flags,
                'domain' => ErpDatasetSupport::stringValue($entry, ['domain'], (string) $artifactMetadata['domain']),
                'subdomain' => ErpDatasetSupport::stringValue($entry, ['subdomain'], (string) $artifactMetadata['subdomain']),
                'locale' => ErpDatasetSupport::normalizeLocale(
                    ErpDatasetSupport::stringValue($entry, ['locale', 'language'], (string) $artifactMetadata['locale'])
                ),
                'source_index' => $index,
            ];
        }

        ksort($result);
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceEntries
     * @param array<string, array<string, mixed>> $intentDefaults
     * @param array<string, mixed> $artifactMetadata
     * @param array<string, array<string, mixed>> $skillsCatalog
     * @param array<string, array<string, mixed>> $actionCatalog
     * @return array{entries: array<int, array<string, mixed>>, hygiene_summary: array<string, int>}
     */
    private static function normalizeTrainingEntries(
        array $sourceEntries,
        array $intentDefaults,
        array $artifactMetadata,
        array $skillsCatalog,
        array $actionCatalog,
        bool $isHardCase
    ): array {
        $entries = [];
        $exactKeys = [];
        $byIntent = [];
        $hygiene = [
            'source_entries' => count($sourceEntries),
            'exported_entries' => 0,
            'exact_duplicates_removed' => 0,
            'near_duplicates_flagged' => 0,
            'suspicious_repetition_flagged' => 0,
            'garbage_rejected' => 0,
            'warnings_count' => 0,
        ];

        foreach ($sourceEntries as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalized = self::normalizeOperationalEntry(
                $entry,
                $index,
                $intentDefaults,
                $artifactMetadata,
                $skillsCatalog,
                $actionCatalog,
                $isHardCase
            );
            if ($normalized === null) {
                $hygiene['garbage_rejected']++;
                continue;
            }

            $intentKey = (string) $normalized['intent_key'];
            $dedupeKey = $intentKey . '|' . ErpDatasetSupport::dedupeKey((string) $normalized['utterance_normalized']);
            if (isset($exactKeys[$dedupeKey])) {
                $hygiene['exact_duplicates_removed']++;
                continue;
            }
            $exactKeys[$dedupeKey] = true;

            $priorIndexes = $byIntent[$intentKey] ?? [];
            foreach ($priorIndexes as $priorIndex) {
                $prior = $entries[$priorIndex] ?? null;
                if (!is_array($prior)) {
                    continue;
                }
                $score = ErpDatasetSupport::nearDuplicateScore(
                    (string) $prior['utterance_normalized'],
                    (string) $normalized['utterance_normalized']
                );
                if ($score >= 0.92) {
                    $normalized['near_duplicate_of'] = (string) ($prior[$isHardCase ? 'case_id' : 'sample_id'] ?? '');
                    $normalized['similarity_score'] = $score;
                    $hygiene['near_duplicates_flagged']++;
                    break;
                }
            }

            $entries[] = $normalized;
            $byIntent[$intentKey][] = array_key_last($entries);
        }

        self::flagSuspiciousRepetition($entries, $isHardCase, $hygiene);
        $hygiene['exported_entries'] = count($entries);

        return [
            'entries' => $entries,
            'hygiene_summary' => $hygiene,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $intentDefaults
     * @param array<string, mixed> $artifactMetadata
     * @param array<string, array<string, mixed>> $skillsCatalog
     * @param array<string, array<string, mixed>> $actionCatalog
     * @return array<string, mixed>|null
     */
    private static function normalizeOperationalEntry(
        array $entry,
        int $index,
        array $intentDefaults,
        array $artifactMetadata,
        array $skillsCatalog,
        array $actionCatalog,
        bool $isHardCase
    ): ?array {
        $intentKey = ErpDatasetSupport::stringValue($entry, ['intent_key', 'intent']);
        if ($intentKey === '' || !isset($intentDefaults[$intentKey])) {
            return null;
        }

        $intentDefinition = $intentDefaults[$intentKey];
        $utteranceOriginal = ErpDatasetSupport::originalStringValue($entry, ['utterance_original', 'utterance', 'text']);
        if ($utteranceOriginal === '' || ErpDatasetSupport::isExtremeGarbage($utteranceOriginal)) {
            return null;
        }

        $targetSkill = ErpDatasetSupport::stringValue($entry, ['target_skill', 'skill'], (string) $intentDefinition['target_skill']);
        $requiredAction = ErpDatasetSupport::stringValue($entry, ['required_action'], (string) $intentDefinition['required_action']);
        $flags = self::resolveAmbiguityFlags($entry, (array) ($intentDefinition['ambiguity_flags'] ?? []));
        $needsClarification = array_key_exists('needs_clarification', $entry)
            ? ErpDatasetSupport::boolValue($entry['needs_clarification'])
            : (ErpDatasetSupport::boolValue($intentDefinition['needs_clarification'] ?? false) || $flags !== []);

        $normalized = [
            $isHardCase ? 'case_id' : 'sample_id' => ErpDatasetSupport::stringValue(
                $entry,
                [$isHardCase ? 'case_id' : 'sample_id', 'id'],
                ErpDatasetSupport::stableId($isHardCase ? 'hardcase' : 'sample', $intentKey . '|' . $utteranceOriginal, $index)
            ),
            'intent_key' => $intentKey,
            'utterance_original' => $utteranceOriginal,
            'utterance_normalized' => ErpDatasetSupport::normalizeText($utteranceOriginal),
            'target_skill' => $targetSkill,
            'skill_type' => self::resolveSkillType($entry, $targetSkill, $skillsCatalog),
            'required_action' => $requiredAction,
            'risk_level' => self::resolveRiskLevel(
                $entry,
                $requiredAction,
                $actionCatalog,
                (string) ($intentDefinition['risk_level'] ?? 'medium')
            ),
            'needs_clarification' => $needsClarification,
            'ambiguity_flags' => $flags,
            'domain' => ErpDatasetSupport::stringValue($entry, ['domain'], (string) $intentDefinition['domain']),
            'subdomain' => ErpDatasetSupport::stringValue($entry, ['subdomain'], (string) $intentDefinition['subdomain']),
            'locale' => ErpDatasetSupport::normalizeLocale(
                ErpDatasetSupport::stringValue($entry, ['locale', 'language'], (string) $intentDefinition['locale'])
            ),
            'numeric_hints' => ErpDatasetSupport::normalizeNumericHints($entry['numeric_hints'] ?? []),
            'suspicious_repetition' => false,
        ];

        foreach (['sample_weight', 'ambiguity_score', 'confidence_score'] as $field) {
            if (!array_key_exists($field, $entry)) {
                continue;
            }
            $numeric = ErpDatasetSupport::floatOrNull($entry[$field]);
            if ($numeric !== null) {
                $normalized[$field] = $numeric;
            }
        }

        if ($isHardCase) {
            $normalized['expected_resolution'] = ErpDatasetSupport::normalizeEnum(
                ErpDatasetSupport::stringValue($entry, ['expected_resolution', 'expected_behavior', 'expected_status']),
                ErpDatasetSupport::EXPECTED_RESOLUTIONS,
                'clarify'
            );
            $normalized['expected_route_stage'] = ErpDatasetSupport::normalizeEnum(
                ErpDatasetSupport::stringValue($entry, ['expected_route_stage'], 'unknown'),
                ErpDatasetSupport::ROUTE_STAGES,
                'unknown'
            );
            $normalized['expected_supervisor_flags'] = ErpDatasetSupport::stringList($entry['expected_supervisor_flags'] ?? []);
            $normalized['regression_tags'] = ErpDatasetSupport::stringList($entry['regression_tags'] ?? ['router_regression']);
        }

        return self::stripEmptyOptionalStrings($normalized, ['required_action', 'near_duplicate_of']);
    }

    /**
     * @param array<string, array<string, mixed>> $intentDefaults
     * @param array<int, array<string, mixed>> $sourceIntents
     * @param array<int, array<string, mixed>> $trainingSamples
     * @param array<int, array<string, mixed>> $hardCases
     * @param array<string, mixed> $artifactMetadata
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeIntentEntries(
        array $intentDefaults,
        array $sourceIntents,
        array $trainingSamples,
        array $hardCases,
        array $artifactMetadata
    ): array {
        $sampleCounts = [];
        foreach ($trainingSamples as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $intentKey = (string) ($entry['intent_key'] ?? '');
            if ($intentKey !== '') {
                $sampleCounts[$intentKey] = ($sampleCounts[$intentKey] ?? 0) + 1;
            }
        }

        $hardCaseCounts = [];
        foreach ($hardCases as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $intentKey = (string) ($entry['intent_key'] ?? '');
            if ($intentKey !== '') {
                $hardCaseCounts[$intentKey] = ($hardCaseCounts[$intentKey] ?? 0) + 1;
            }
        }

        $normalized = [];
        foreach ($sourceIntents as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $intentKey = ErpDatasetSupport::stringValue($entry, ['intent_key', 'intent', 'name']);
            if ($intentKey === '' || !isset($intentDefaults[$intentKey])) {
                continue;
            }

            $definition = $intentDefaults[$intentKey];
            $normalized[] = [
                'intent_key' => $intentKey,
                'intent_name' => (string) $definition['intent_name'],
                'description' => (string) $definition['description'],
                'target_skill' => (string) $definition['target_skill'],
                'skill_type' => (string) $definition['skill_type'],
                'required_action' => (string) $definition['required_action'],
                'risk_level' => (string) $definition['risk_level'],
                'needs_clarification' => (bool) $definition['needs_clarification'],
                'ambiguity_flags' => (array) $definition['ambiguity_flags'],
                'domain' => (string) $definition['domain'],
                'subdomain' => (string) $definition['subdomain'],
                'locale' => (string) $definition['locale'],
                'sample_count' => (int) ($sampleCounts[$intentKey] ?? 0),
                'hard_case_count' => (int) ($hardCaseCounts[$intentKey] ?? 0),
            ];
            $lastIndex = array_key_last($normalized);
            if ($lastIndex !== null) {
                $normalized[$lastIndex] = self::stripEmptyOptionalStrings(
                    $normalized[$lastIndex],
                    ['description', 'required_action']
                );
            }
        }

        usort($normalized, static function (array $left, array $right): int {
            return strcmp((string) $left['intent_key'], (string) $right['intent_key']);
        });

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, int> $hygiene
     */
    private static function flagSuspiciousRepetition(array &$entries, bool $isHardCase, array &$hygiene): void
    {
        $prefixCounts = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $intentKey = (string) ($entry['intent_key'] ?? '');
            $prefix = ErpDatasetSupport::tokenPrefix((string) ($entry['utterance_normalized'] ?? ''), 3);
            if ($intentKey === '' || $prefix === '') {
                continue;
            }
            $prefixCounts[$intentKey . '|' . $prefix] = ($prefixCounts[$intentKey . '|' . $prefix] ?? 0) + 1;
        }

        $idField = $isHardCase ? 'case_id' : 'sample_id';
        $flagged = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $intentKey = (string) ($entry['intent_key'] ?? '');
            $prefix = ErpDatasetSupport::tokenPrefix((string) ($entry['utterance_normalized'] ?? ''), 3);
            if ($intentKey === '' || $prefix === '') {
                continue;
            }
            if (($prefixCounts[$intentKey . '|' . $prefix] ?? 0) < 3) {
                continue;
            }
            $entries[$index]['suspicious_repetition'] = true;
            $id = (string) ($entry[$idField] ?? $index);
            $flagged[$id] = true;
        }

        $hygiene['suspicious_repetition_flagged'] = count($flagged);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string> $defaults
     * @return array<int, string>
     */
    private static function resolveAmbiguityFlags(array $entry, array $defaults): array
    {
        $flags = ErpDatasetSupport::normalizeAmbiguityFlags($entry['ambiguity_flags'] ?? []);
        if ($flags !== []) {
            return $flags;
        }

        return ErpDatasetSupport::normalizeAmbiguityFlags($defaults);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $skillsCatalog
     */
    private static function resolveSkillType(array $entry, string $targetSkill, array $skillsCatalog): string
    {
        $provided = ErpDatasetSupport::normalizeEnum(
            ErpDatasetSupport::stringValue($entry, ['skill_type', 'execution_mode']),
            ErpDatasetSupport::SKILL_TYPES
        );
        if ($provided !== '') {
            return $provided;
        }

        return ErpDatasetSupport::normalizeEnum(
            (string) ($skillsCatalog[$targetSkill]['execution_mode'] ?? 'tool'),
            ErpDatasetSupport::SKILL_TYPES,
            'tool'
        );
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $actionCatalog
     */
    private static function resolveRiskLevel(array $entry, string $requiredAction, array $actionCatalog, string $default): string
    {
        $provided = ErpDatasetSupport::normalizeEnum(
            ErpDatasetSupport::stringValue($entry, ['risk_level']),
            ErpDatasetSupport::RISK_LEVELS
        );
        if ($provided !== '') {
            return $provided;
        }

        $catalogRisk = ErpDatasetSupport::normalizeEnum(
            (string) ($actionCatalog[$requiredAction]['risk_level'] ?? ''),
            ErpDatasetSupport::RISK_LEVELS
        );
        if ($catalogRisk !== '') {
            return $catalogRisk;
        }

        return ErpDatasetSupport::normalizeEnum($default, ErpDatasetSupport::RISK_LEVELS, 'medium');
    }

    /**
     * @param array<string, mixed> $artifactMetadata
     * @param array<int, array<string, mixed>> $trainingSamples
     * @param array<int, array<string, mixed>> $hardCases
     * @return array<string, mixed>
     */
    private static function buildVectorizationPrep(
        array $artifactMetadata,
        array $trainingSamples,
        array $hardCases
    ): array {
        $entries = [];
        foreach ($trainingSamples as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = [
                'vector_id' => 'train_' . (string) ($entry['sample_id'] ?? ''),
                'collection' => 'agent_training',
                'memory_type' => 'agent_training',
                'source_artifact' => 'erp_training_samples',
                'source_id' => (string) ($entry['sample_id'] ?? ''),
                'content' => (string) ($entry['utterance_original'] ?? ''),
                'metadata' => self::buildVectorMetadata($artifactMetadata, $entry, false),
            ];
        }

        foreach ($hardCases as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = [
                'vector_id' => 'hard_' . (string) ($entry['case_id'] ?? ''),
                'collection' => 'agent_training',
                'memory_type' => 'agent_training',
                'source_artifact' => 'erp_hard_cases',
                'source_id' => (string) ($entry['case_id'] ?? ''),
                'content' => (string) ($entry['utterance_original'] ?? ''),
                'metadata' => self::buildVectorMetadata($artifactMetadata, $entry, true),
            ];
        }

        return [
            'artifact_type' => 'erp_vectorization_prep',
            'schema_version' => ErpDatasetSupport::ARTIFACT_SCHEMA_VERSION,
            'metadata' => [
                'dataset_id' => $artifactMetadata['dataset_id'],
                'dataset_version' => $artifactMetadata['dataset_version'],
                'target_collection' => 'agent_training',
                'available_collections' => ErpDatasetSupport::MEMORY_TYPES,
                'dataset_scope' => ErpDatasetSupport::SHARED_TRAINING_SCOPE,
                'tenant_data_allowed' => false,
                'generated_at' => gmdate('c'),
            ],
            'entries' => $entries,
        ];
    }

    /**
     * @param array<string, mixed> $artifactMetadata
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function buildVectorMetadata(array $artifactMetadata, array $entry, bool $isHardCase): array
    {
        $metadata = [
            'dataset_id' => (string) $artifactMetadata['dataset_id'],
            'dataset_version' => (string) $artifactMetadata['dataset_version'],
            'domain' => (string) ($entry['domain'] ?? $artifactMetadata['domain']),
            'subdomain' => (string) ($entry['subdomain'] ?? $artifactMetadata['subdomain']),
            'locale' => (string) ($entry['locale'] ?? $artifactMetadata['locale']),
            'intent_key' => (string) ($entry['intent_key'] ?? ''),
            'target_skill' => (string) ($entry['target_skill'] ?? ''),
            'skill_type' => (string) ($entry['skill_type'] ?? ''),
            'required_action' => (string) ($entry['required_action'] ?? ''),
            'risk_level' => (string) ($entry['risk_level'] ?? 'medium'),
            'needs_clarification' => (bool) ($entry['needs_clarification'] ?? false),
            'ambiguity_flags' => (array) ($entry['ambiguity_flags'] ?? []),
            'tenant_data_allowed' => false,
            'dataset_scope' => ErpDatasetSupport::SHARED_TRAINING_SCOPE,
            'source_kind' => $isHardCase ? 'hard_case' : 'training_sample',
        ];

        if ($isHardCase) {
            $metadata['expected_resolution'] = (string) ($entry['expected_resolution'] ?? 'clarify');
            $metadata['expected_route_stage'] = (string) ($entry['expected_route_stage'] ?? 'unknown');
            $metadata['regression_tags'] = (array) ($entry['regression_tags'] ?? []);
        }

        return self::stripEmptyOptionalStrings($metadata, ['required_action']);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private static function stripEmptyOptionalStrings(array $entry, array $fields): array
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $entry)) {
                continue;
            }
            if (is_string($entry[$field]) && trim((string) $entry[$field]) === '') {
                unset($entry[$field]);
            }
        }

        return $entry;
    }
}
