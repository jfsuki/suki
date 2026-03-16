<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class ErpDatasetValidator
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array{ok: bool, errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>, stats: array<string, mixed>}
     */
    public static function validate(array $payload, array $options = []): array
    {
        $errors = [];
        $warnings = [];
        $rawMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $datasetShape = self::detectDatasetShape($payload);

        $metadata = ErpDatasetSupport::resolveMetadata($payload);
        $intents = ErpDatasetSupport::resolveBlock($payload, 'intents_catalog');
        $samples = ErpDatasetSupport::resolveBlock($payload, 'training_samples');
        $hardCases = ErpDatasetSupport::resolveBlock($payload, 'hard_cases');

        if ($datasetShape === 'legacy_intent_dataset') {
            self::addError(
                $errors,
                '$',
                'El archivo parece un intent_dataset legacy. Este pipeline ERP espera metadata + BLOQUE_A_intents_catalog + BLOQUE_B_training_samples + BLOQUE_C_hard_cases.'
            );
        }
        if ($datasetShape === 'prepared_erp_artifact') {
            self::addError(
                $errors,
                '$',
                'El archivo parece un artefacto ERP ya preparado. Usa el dataset fuente original con metadata + BLOQUE_A/B/C, no erp_training_samples.json ni erp_hard_cases.json.'
            );
        }

        if ($rawMetadata === []) {
            self::addError($errors, '$.metadata', 'metadata es obligatorio.');
        }
        if (ErpDatasetSupport::stringValue($rawMetadata, ['dataset_id', 'dataset', 'batch_id']) === '') {
            self::addError($errors, '$.metadata.dataset_id', 'dataset_id es obligatorio.');
        }
        if (ErpDatasetSupport::stringValue($rawMetadata, ['dataset_version', 'version']) === '') {
            self::addError($errors, '$.metadata.dataset_version', 'dataset_version es obligatorio.');
        }
        if (ErpDatasetSupport::stringValue($rawMetadata, ['domain']) === '') {
            self::addError($errors, '$.metadata.domain', 'domain es obligatorio.');
        } elseif ($metadata['domain'] !== 'erp') {
            self::addError($errors, '$.metadata.domain', 'El pipeline ERP requiere domain=erp.');
        }
        if (ErpDatasetSupport::stringValue($rawMetadata, ['locale', 'language']) === '') {
            self::addWarning($warnings, '$.metadata.locale', 'locale no informado; se normalizara a es-CO.');
        }
        if (($payload['BLOQUE_A_intents_catalog'] ?? null) !== null && !is_array($payload['BLOQUE_A_intents_catalog'])) {
            self::addError($errors, '$.BLOQUE_A_intents_catalog', 'BLOQUE_A_intents_catalog debe ser array.');
        }
        if (($payload['BLOQUE_B_training_samples'] ?? null) !== null && !is_array($payload['BLOQUE_B_training_samples'])) {
            self::addError($errors, '$.BLOQUE_B_training_samples', 'BLOQUE_B_training_samples debe ser array.');
        }
        if (($payload['BLOQUE_C_hard_cases'] ?? null) !== null && !is_array($payload['BLOQUE_C_hard_cases'])) {
            self::addError($errors, '$.BLOQUE_C_hard_cases', 'BLOQUE_C_hard_cases debe ser array.');
        }
        if ($intents === []) {
            self::addError($errors, '$.BLOQUE_A_intents_catalog', 'Se requiere BLOQUE_A_intents_catalog con al menos un intent.');
        }
        if ($samples === []) {
            self::addError($errors, '$.BLOQUE_B_training_samples', 'Se requiere BLOQUE_B_training_samples con al menos un sample.');
        }
        if ($hardCases === []) {
            self::addError($errors, '$.BLOQUE_C_hard_cases', 'Se requiere BLOQUE_C_hard_cases con al menos un hard case.');
        }

        $skillsCatalog = ErpDatasetSupport::loadSkillsCatalog();
        $actionCatalog = ErpDatasetSupport::loadActionCatalog();
        $supervisorFlags = array_flip(ErpDatasetSupport::loadSupervisorFlags());

        $intentMap = [];
        foreach ($intents as $index => $entry) {
            $path = '$.BLOQUE_A_intents_catalog[' . $index . ']';
            [$intentKey, $targetSkill, $requiredAction] = self::validateCatalogEntry(
                $entry,
                $path,
                $skillsCatalog['skills'],
                $actionCatalog['actions'],
                $errors,
                $warnings
            );

            if ($intentKey === '') {
                continue;
            }
            if (isset($intentMap[$intentKey])) {
                self::addError($errors, $path . '.intent_key', 'Intent duplicado en catalogo: ' . $intentKey);
                continue;
            }
            $intentMap[$intentKey] = [
                'target_skill' => $targetSkill,
                'required_action' => $requiredAction,
                'skill_type' => self::resolveSkillType($entry, $skillsCatalog['skills'], $targetSkill),
                'risk_level' => self::resolveRiskLevel($entry, $actionCatalog['actions'], $requiredAction),
                'needs_clarification' => ErpDatasetSupport::boolValue($entry['needs_clarification'] ?? false),
            ];
        }

        foreach ($samples as $index => $entry) {
            $path = '$.BLOQUE_B_training_samples[' . $index . ']';
            self::validateSampleEntry(
                $entry,
                $path,
                $intentMap,
                $skillsCatalog['skills'],
                $actionCatalog['actions'],
                $errors,
                $warnings
            );
        }

        foreach ($hardCases as $index => $entry) {
            $path = '$.BLOQUE_C_hard_cases[' . $index . ']';
            self::validateHardCaseEntry(
                $entry,
                $path,
                $intentMap,
                $skillsCatalog['skills'],
                $actionCatalog['actions'],
                $supervisorFlags,
                $errors,
                $warnings
            );
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => [
                'dataset_shape' => $datasetShape,
                'dataset_id' => $metadata['dataset_id'],
                'dataset_version' => $metadata['dataset_version'],
                'intents_catalog' => count($intents),
                'training_samples' => count($samples),
                'hard_cases' => count($hardCases),
                'skills_catalog_version' => $skillsCatalog['version'],
                'action_catalog_version' => $actionCatalog['version'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public static function validateOrFail(array $payload, array $options = []): void
    {
        $report = self::validate($payload, $options);
        if (($report['ok'] ?? false) === true) {
            return;
        }

        $first = $report['errors'][0]['message'] ?? 'Dataset ERP invalido.';
        throw new RuntimeException($first);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, errors: array<int, array<string, string>>}
     */
    public static function validateArtifact(array $payload, string $schemaPath): array
    {
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema ERP no existe: ' . $schemaPath);
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema ERP invalido: ' . $schemaPath);
        }

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Artifacto ERP no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if ($result->isValid()) {
            return ['ok' => true, 'errors' => []];
        }

        $error = $result->error();
        return [
            'ok' => false,
            'errors' => [[
                'path' => '$',
                'message' => $error ? $error->message() : 'Artifacto ERP invalido por schema.',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateArtifactOrFail(array $payload, string $schemaPath): void
    {
        $report = self::validateArtifact($payload, $schemaPath);
        if (($report['ok'] ?? false) === true) {
            return;
        }

        $first = $report['errors'][0]['message'] ?? 'Artifacto ERP invalido.';
        throw new RuntimeException($first);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $skillsCatalog
     * @param array<string, array<string, mixed>> $actionCatalog
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     * @return array{0: string, 1: string, 2: string}
     */
    private static function validateCatalogEntry(
        array $entry,
        string $path,
        array $skillsCatalog,
        array $actionCatalog,
        array &$errors,
        array &$warnings
    ): array {
        $intentKey = ErpDatasetSupport::stringValue($entry, ['intent_key', 'intent', 'name']);
        if ($intentKey === '') {
            self::addError($errors, $path . '.intent_key', 'intent_key es obligatorio.');
        }

        $targetSkill = ErpDatasetSupport::stringValue($entry, ['target_skill', 'skill']);
        if ($targetSkill === '') {
            self::addError($errors, $path . '.target_skill', 'target_skill es obligatorio.');
        } elseif (!isset($skillsCatalog[$targetSkill])) {
            self::addError($errors, $path . '.target_skill', 'Skill inexistente en skills_catalog: ' . $targetSkill);
        }

        $skillType = ErpDatasetSupport::stringValue($entry, ['skill_type', 'execution_mode']);
        if ($skillType !== '' && !in_array(strtolower($skillType), ErpDatasetSupport::SKILL_TYPES, true)) {
            self::addError($errors, $path . '.skill_type', 'skill_type invalido: ' . $skillType);
        }
        if ($targetSkill !== '' && isset($skillsCatalog[$targetSkill]) && $skillType !== '') {
            $catalogType = strtolower((string) ($skillsCatalog[$targetSkill]['execution_mode'] ?? ''));
            if ($catalogType !== '' && strtolower($skillType) !== $catalogType) {
                self::addError(
                    $errors,
                    $path . '.skill_type',
                    'skill_type no coincide con skills_catalog para ' . $targetSkill . '.'
                );
            }
        }

        $requiredAction = ErpDatasetSupport::stringValue($entry, ['required_action']);
        if ($requiredAction !== '' && !isset($actionCatalog[$requiredAction])) {
            self::addError($errors, $path . '.required_action', 'required_action no permitido: ' . $requiredAction);
        }

        $riskLevel = ErpDatasetSupport::stringValue($entry, ['risk_level']);
        if ($riskLevel !== '' && !in_array(strtolower($riskLevel), ErpDatasetSupport::RISK_LEVELS, true)) {
            self::addError($errors, $path . '.risk_level', 'risk_level invalido: ' . $riskLevel);
        }

        self::validateFlagsAndNumbers($entry, $path, $errors, $warnings);

        return [$intentKey, $targetSkill, $requiredAction];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $intentMap
     * @param array<string, array<string, mixed>> $skillsCatalog
     * @param array<string, array<string, mixed>> $actionCatalog
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     */
    private static function validateSampleEntry(
        array $entry,
        string $path,
        array $intentMap,
        array $skillsCatalog,
        array $actionCatalog,
        array &$errors,
        array &$warnings
    ): void {
        $intentKey = ErpDatasetSupport::stringValue($entry, ['intent_key', 'intent']);
        if ($intentKey === '' || !isset($intentMap[$intentKey])) {
            self::addError($errors, $path . '.intent_key', 'intent_key inexistente en catalogo.');
        }

        $utterance = ErpDatasetSupport::stringValue($entry, ['utterance', 'text']);
        if ($utterance === '') {
            self::addError($errors, $path . '.utterance', 'utterance es obligatorio.');
        } elseif (ErpDatasetSupport::isExtremeGarbage($utterance)) {
            self::addWarning($warnings, $path . '.utterance', 'Sample potencialmente vacio o basura extrema.');
        }

        self::validateSkillAndActionConsistency(
            $entry,
            $path,
            $intentKey,
            $intentMap,
            $skillsCatalog,
            $actionCatalog,
            $errors,
            $warnings
        );
        self::validateFlagsAndNumbers($entry, $path, $errors, $warnings);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $intentMap
     * @param array<string, array<string, mixed>> $skillsCatalog
     * @param array<string, array<string, mixed>> $actionCatalog
     * @param array<string, int> $supervisorFlags
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     */
    private static function validateHardCaseEntry(
        array $entry,
        string $path,
        array $intentMap,
        array $skillsCatalog,
        array $actionCatalog,
        array $supervisorFlags,
        array &$errors,
        array &$warnings
    ): void {
        self::validateSampleEntry($entry, $path, $intentMap, $skillsCatalog, $actionCatalog, $errors, $warnings);

        $expectedResolution = ErpDatasetSupport::stringValue($entry, ['expected_resolution', 'expected_behavior', 'expected_status']);
        if ($expectedResolution === '' || !in_array(strtolower($expectedResolution), ErpDatasetSupport::EXPECTED_RESOLUTIONS, true)) {
            self::addError($errors, $path . '.expected_resolution', 'expected_resolution invalido.');
        }

        $expectedRouteStage = ErpDatasetSupport::stringValue($entry, ['expected_route_stage'], 'unknown');
        if (!in_array(strtolower($expectedRouteStage), ErpDatasetSupport::ROUTE_STAGES, true)) {
            self::addError($errors, $path . '.expected_route_stage', 'expected_route_stage invalido.');
        }

        foreach (ErpDatasetSupport::stringList($entry['expected_supervisor_flags'] ?? []) as $flag) {
            if (!isset($supervisorFlags[$flag])) {
                self::addWarning($warnings, $path . '.expected_supervisor_flags', 'Supervisor flag no catalogado: ' . $flag);
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $intentMap
     * @param array<string, array<string, mixed>> $skillsCatalog
     * @param array<string, array<string, mixed>> $actionCatalog
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     */
    private static function validateSkillAndActionConsistency(
        array $entry,
        string $path,
        string $intentKey,
        array $intentMap,
        array $skillsCatalog,
        array $actionCatalog,
        array &$errors,
        array &$warnings
    ): void {
        $targetSkill = ErpDatasetSupport::stringValue($entry, ['target_skill', 'skill']);
        $catalogSkill = (string) ($intentMap[$intentKey]['target_skill'] ?? '');
        if ($targetSkill !== '' && !isset($skillsCatalog[$targetSkill])) {
            self::addError($errors, $path . '.target_skill', 'Skill inexistente en skills_catalog: ' . $targetSkill);
        }
        if ($targetSkill !== '' && $catalogSkill !== '' && $targetSkill !== $catalogSkill) {
            self::addError($errors, $path . '.target_skill', 'target_skill no coincide con el catalogo del intent.');
        }

        $skillType = ErpDatasetSupport::stringValue($entry, ['skill_type', 'execution_mode']);
        if ($skillType !== '' && !in_array(strtolower($skillType), ErpDatasetSupport::SKILL_TYPES, true)) {
            self::addError($errors, $path . '.skill_type', 'skill_type invalido: ' . $skillType);
        }

        $effectiveSkill = $targetSkill !== '' ? $targetSkill : $catalogSkill;
        if ($skillType !== '' && $effectiveSkill !== '' && isset($skillsCatalog[$effectiveSkill])) {
            $catalogType = strtolower((string) ($skillsCatalog[$effectiveSkill]['execution_mode'] ?? ''));
            if ($catalogType !== '' && strtolower($skillType) !== $catalogType) {
                self::addError($errors, $path . '.skill_type', 'skill_type no coincide con el catalogo de la skill.');
            }
        }

        $requiredAction = ErpDatasetSupport::stringValue($entry, ['required_action']);
        if ($requiredAction !== '' && !isset($actionCatalog[$requiredAction])) {
            self::addError($errors, $path . '.required_action', 'required_action no permitido: ' . $requiredAction);
        }
        $catalogAction = (string) ($intentMap[$intentKey]['required_action'] ?? '');
        if ($requiredAction !== '' && $catalogAction !== '' && $requiredAction !== $catalogAction) {
            self::addError($errors, $path . '.required_action', 'required_action no coincide con el catalogo del intent.');
        }

        $riskLevel = ErpDatasetSupport::stringValue($entry, ['risk_level']);
        if ($riskLevel !== '' && !in_array(strtolower($riskLevel), ErpDatasetSupport::RISK_LEVELS, true)) {
            self::addError($errors, $path . '.risk_level', 'risk_level invalido: ' . $riskLevel);
        }
        if ($requiredAction !== '' && isset($actionCatalog[$requiredAction])) {
            $catalogRisk = strtolower((string) ($actionCatalog[$requiredAction]['risk_level'] ?? ''));
            if ($riskLevel !== '' && $catalogRisk !== '' && strtolower($riskLevel) !== $catalogRisk) {
                self::addWarning($warnings, $path . '.risk_level', 'risk_level no coincide con action_catalog.');
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     */
    private static function validateFlagsAndNumbers(array $entry, string $path, array &$errors, array &$warnings): void
    {
        $needsClarification = array_key_exists('needs_clarification', $entry)
            ? ErpDatasetSupport::boolValue($entry['needs_clarification'])
            : false;
        $flags = ErpDatasetSupport::stringList($entry['ambiguity_flags'] ?? []);
        foreach ($flags as $flag) {
            if (!in_array($flag, ErpDatasetSupport::AMBIGUITY_FLAGS, true)) {
                self::addError($errors, $path . '.ambiguity_flags', 'Ambiguity flag invalido: ' . $flag);
            }
        }
        if ($needsClarification && $flags === []) {
            self::addWarning($warnings, $path . '.ambiguity_flags', 'needs_clarification=true sin ambiguity_flags.');
        }

        foreach (['sample_weight', 'ambiguity_score', 'confidence_score'] as $field) {
            if (array_key_exists($field, $entry) && ErpDatasetSupport::floatOrNull($entry[$field]) === null) {
                self::addError($errors, $path . '.' . $field, 'Campo numerico invalido.');
            }
        }

        $numericHints = is_array($entry['numeric_hints'] ?? null) ? $entry['numeric_hints'] : [];
        foreach ($numericHints as $field => $value) {
            if (!is_string($field)) {
                continue;
            }
            if (ErpDatasetSupport::floatOrNull($value) === null) {
                self::addError($errors, $path . '.numeric_hints.' . $field, 'numeric_hints invalido.');
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $skillsCatalog
     */
    private static function resolveSkillType(array $entry, array $skillsCatalog, string $targetSkill): string
    {
        $provided = ErpDatasetSupport::stringValue($entry, ['skill_type', 'execution_mode']);
        if ($provided !== '') {
            return strtolower($provided);
        }
        return strtolower((string) ($skillsCatalog[$targetSkill]['execution_mode'] ?? 'tool')) ?: 'tool';
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $actionCatalog
     */
    private static function resolveRiskLevel(array $entry, array $actionCatalog, string $requiredAction): string
    {
        $provided = ErpDatasetSupport::stringValue($entry, ['risk_level']);
        if ($provided !== '' && in_array(strtolower($provided), ErpDatasetSupport::RISK_LEVELS, true)) {
            return strtolower($provided);
        }
        $catalogRisk = strtolower((string) ($actionCatalog[$requiredAction]['risk_level'] ?? 'medium'));
        if (in_array($catalogRisk, ErpDatasetSupport::RISK_LEVELS, true)) {
            return $catalogRisk;
        }
        return 'medium';
    }

    /**
     * @param array<int, array<string, string>> $errors
     */
    private static function addError(array &$errors, string $path, string $message): void
    {
        $errors[] = ['path' => $path, 'message' => $message];
    }

    /**
     * @param array<int, array<string, string>> $warnings
     */
    private static function addWarning(array &$warnings, string $path, string $message): void
    {
        $warnings[] = ['path' => $path, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function detectDatasetShape(array $payload): string
    {
        if (is_string($payload['artifact_type'] ?? null)) {
            return 'prepared_erp_artifact';
        }

        if (
            ($payload['dataset_type'] ?? null) === 'intent_dataset'
            || ($payload['source_type'] ?? null) === 'agent_training'
            || is_array($payload['entries'] ?? null)
        ) {
            return 'legacy_intent_dataset';
        }

        if (
            is_array($payload['metadata'] ?? null)
            && is_array($payload['BLOQUE_A_intents_catalog'] ?? null)
            && is_array($payload['BLOQUE_B_training_samples'] ?? null)
            && is_array($payload['BLOQUE_C_hard_cases'] ?? null)
        ) {
            return 'erp_source_dataset';
        }

        return 'unknown';
    }
}
