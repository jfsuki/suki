<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Throwable;

final class AuditValidator
{
    private const AGENT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/audit_agent.schema.json';
    private const ALERT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/audit_alert.schema.json';
    private const CATALOG_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/audit_catalog.schema.json';
    private const GBO_CONCEPTS = FRAMEWORK_ROOT . '/ontology/gbo_universal_concepts.json';
    private const BEG_EVENT_TYPES = FRAMEWORK_ROOT . '/events/beg_event_types.json';
    private const BEG_ANOMALY_PATTERNS = FRAMEWORK_ROOT . '/events/beg_anomaly_patterns.json';
    private const ACTION_CATALOG = PROJECT_ROOT . '/../docs/contracts/action_catalog.json';
    private const SKILLS_CATALOG = PROJECT_ROOT . '/../docs/contracts/skills_catalog.json';

    /** @var array<string, string> */
    private const DEFAULT_ARTIFACTS = [
        'audit_rules' => FRAMEWORK_ROOT . '/audit/audit_rules.json',
        'anomaly_patterns_extended' => FRAMEWORK_ROOT . '/audit/anomaly_patterns_extended.json',
    ];

    /** @var array<int, string> */
    private const RULE_CATEGORIES = [
        'deterministic_rules',
        'beg_rules',
        'kpi_rules',
        'crm_rules',
    ];

    /** @var array<int, string> */
    private const PATTERN_CATEGORIES = [
        'fraud_patterns',
        'data_quality_patterns',
        'beg_patterns',
        'behavioral_patterns',
    ];

    /**
     * @return array<string, string>
     */
    public static function defaultArtifactPaths(): array
    {
        return self::DEFAULT_ARTIFACTS;
    }

    /**
     * @param array<string, array<string, mixed>> $artifactOverrides
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function validateCatalog(array $artifactOverrides = [], array $options = []): array
    {
        $artifacts = self::loadDefaultArtifacts();
        foreach ($artifactOverrides as $artifactType => $payload) {
            $artifacts[$artifactType] = [
                'payload' => $payload,
                'path' => null,
                'source' => 'override',
            ];
        }

        return self::validateCatalogArtifacts($artifacts, $options);
    }

    /**
     * @param array<int, string> $paths
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function validateCatalogFiles(array $paths, array $options = []): array
    {
        $overrides = [];
        $errors = [];
        $inputs = [];

        foreach ($paths as $path) {
            $inputs[] = $path;
            try {
                $payload = self::loadJsonFile($path);
                $artifactType = trim((string) ($payload['artifact_type'] ?? ''));
                if ($artifactType === '' || !isset(self::DEFAULT_ARTIFACTS[$artifactType])) {
                    $errors[] = [
                        'path' => $path,
                        'message' => 'Artifacto audit invalido o no soportado.',
                    ];
                    continue;
                }
                $overrides[$artifactType] = $payload;
            } catch (Throwable $e) {
                $errors[] = [
                    'path' => $path,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $report = self::validateCatalog($overrides, $options);
        if ($inputs !== []) {
            $report['inputs'] = $inputs;
        }
        if ($errors !== []) {
            $report['ok'] = false;
            $report['errors'] = array_merge($errors, is_array($report['errors'] ?? null) ? $report['errors'] : []);
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function validateAgentConfig(array $payload, array $options = []): array
    {
        $errors = [];
        $warnings = [];

        $schemaError = self::validateSchema($payload, self::AGENT_SCHEMA, 'Audit Agent');
        if ($schemaError !== null) {
            self::addError($errors, '$', $schemaError);
            return [
                'ok' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'stats' => [
                    'allowed_anomaly_types' => 0,
                    'evaluation_modes' => 0,
                ],
            ];
        }

        $catalogReport = self::validateCatalog([], $options);
        $errors = array_merge($errors, is_array($catalogReport['errors'] ?? null) ? $catalogReport['errors'] : []);
        $warnings = array_merge($warnings, is_array($catalogReport['warnings'] ?? null) ? $catalogReport['warnings'] : []);
        $knownPatterns = self::indexList(is_array($catalogReport['catalog']['pattern_ids'] ?? null) ? $catalogReport['catalog']['pattern_ids'] : []);

        $allowedAnomalyTypes = is_array($payload['allowed_anomaly_types'] ?? null) ? $payload['allowed_anomaly_types'] : [];
        foreach ($allowedAnomalyTypes as $index => $anomalyType) {
            $anomalyKey = self::normalizeKey((string) $anomalyType);
            if ($anomalyKey === '' || !isset($knownPatterns[$anomalyKey])) {
                self::addError(
                    $errors,
                    '$.allowed_anomaly_types[' . $index . ']',
                    'allowed_anomaly_type fuera de catalogo audit: ' . (string) $anomalyType
                );
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => [
                'allowed_anomaly_types' => count($allowedAnomalyTypes),
                'evaluation_modes' => count(is_array($payload['evaluation_modes'] ?? null) ? $payload['evaluation_modes'] : []),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function validateAlert(array $payload, array $options = []): array
    {
        $errors = [];
        $warnings = [];

        $schemaError = self::validateSchema($payload, self::ALERT_SCHEMA, 'Audit Alert');
        if ($schemaError !== null) {
            self::addError($errors, '$', $schemaError);
            return [
                'ok' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'stats' => [
                    'related_events' => 0,
                    'related_entities' => 0,
                    'beg_trace' => 0,
                    'suggested_actions' => 0,
                ],
            ];
        }

        $catalogReport = self::validateCatalog([], $options);
        $errors = array_merge($errors, is_array($catalogReport['errors'] ?? null) ? $catalogReport['errors'] : []);
        $warnings = array_merge($warnings, is_array($catalogReport['warnings'] ?? null) ? $catalogReport['warnings'] : []);

        $dependencies = self::loadDependencyMaps($errors);
        $knownPatterns = self::indexList(is_array($catalogReport['catalog']['pattern_ids'] ?? null) ? $catalogReport['catalog']['pattern_ids'] : []);

        $tenantId = trim((string) ($payload['tenant_id'] ?? ''));
        $appId = trim((string) ($payload['app_id'] ?? ''));
        $anomalyType = self::normalizeKey((string) ($payload['anomaly_type'] ?? ''));
        if ($anomalyType === '' || !isset($knownPatterns[$anomalyType])) {
            self::addError($errors, '$.anomaly_type', 'anomaly_type fuera de catalogo audit: ' . (string) ($payload['anomaly_type'] ?? ''));
        }

        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        $begTrace = is_array($evidence['beg_trace'] ?? null) ? $evidence['beg_trace'] : [];
        foreach ($begTrace as $index => $eventRef) {
            self::validateEventReference(
                is_array($eventRef) ? $eventRef : [],
                '$.evidence.beg_trace[' . $index . ']',
                $tenantId,
                $appId,
                $dependencies['beg_event_types'],
                $errors
            );
        }

        $relatedEvents = is_array($payload['related_events'] ?? null) ? $payload['related_events'] : [];
        foreach ($relatedEvents as $index => $eventRef) {
            self::validateEventReference(
                is_array($eventRef) ? $eventRef : [],
                '$.related_events[' . $index . ']',
                $tenantId,
                $appId,
                $dependencies['beg_event_types'],
                $errors
            );
        }

        $relatedEntities = is_array($payload['related_entities'] ?? null) ? $payload['related_entities'] : [];
        foreach ($relatedEntities as $index => $entityRef) {
            if (!is_array($entityRef)) {
                self::addError($errors, '$.related_entities[' . $index . ']', 'related_entity invalida.');
                continue;
            }
            $entityType = self::normalizeKey((string) ($entityRef['entity_type'] ?? ''));
            if ($entityType === '' || !isset($dependencies['gbo_concepts'][$entityType])) {
                self::addError(
                    $errors,
                    '$.related_entities[' . $index . '].entity_type',
                    'entity_type fuera de GBO: ' . (string) ($entityRef['entity_type'] ?? '')
                );
            }
            self::validateTenantScope($entityRef, '$.related_entities[' . $index . ']', $tenantId, $appId, $errors);
        }

        $seenActions = [];
        $suggestedActions = is_array($payload['suggested_actions'] ?? null) ? $payload['suggested_actions'] : [];
        foreach ($suggestedActions as $index => $suggestedAction) {
            if (!is_array($suggestedAction)) {
                self::addError($errors, '$.suggested_actions[' . $index . ']', 'suggested_action invalida.');
                continue;
            }

            $actionId = self::normalizeKey((string) ($suggestedAction['action_id'] ?? ''));
            if ($actionId === '' || !isset($dependencies['action_ids'][$actionId])) {
                self::addError(
                    $errors,
                    '$.suggested_actions[' . $index . '].action_id',
                    'suggested_action referencia action_id inexistente: ' . (string) ($suggestedAction['action_id'] ?? '')
                );
            }

            $skillId = self::normalizeKey((string) ($suggestedAction['skill_id'] ?? ''));
            if ($skillId === '' || !isset($dependencies['skill_ids'][$skillId])) {
                self::addError(
                    $errors,
                    '$.suggested_actions[' . $index . '].skill_id',
                    'suggested_action referencia skill_id inexistente: ' . (string) ($suggestedAction['skill_id'] ?? '')
                );
            }

            $signature = $actionId . '|' . $skillId;
            if (isset($seenActions[$signature])) {
                self::addError($errors, '$.suggested_actions[' . $index . ']', 'suggested_action duplicada.');
            } else {
                $seenActions[$signature] = true;
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => [
                'related_events' => count($relatedEvents),
                'related_entities' => count($relatedEntities),
                'beg_trace' => count($begTrace),
                'suggested_actions' => count($suggestedActions),
            ],
        ];
    }

    /**
     * @param string $path
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function validateAgentConfigFile(string $path, array $options = []): array
    {
        $payload = self::loadJsonFile($path);
        $report = self::validateAgentConfig($payload, $options);
        $report['inputs'] = [$path];
        return $report;
    }

    /**
     * @param string $path
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function validateAlertFile(string $path, array $options = []): array
    {
        $payload = self::loadJsonFile($path);
        $report = self::validateAlert($payload, $options);
        $report['inputs'] = [$path];
        return $report;
    }

    /**
     * @param array<string, array{payload: array<string, mixed>, path: ?string, source: string}> $artifacts
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function validateCatalogArtifacts(array $artifacts, array $options = []): array
    {
        $errors = [];
        $warnings = [];
        $artifactReports = [];

        $rulesPayload = [];
        $patternsPayload = [];

        foreach (self::DEFAULT_ARTIFACTS as $artifactType => $defaultPath) {
            if (!isset($artifacts[$artifactType])) {
                self::addError($errors, '$.' . $artifactType, 'Falta artefacto audit requerido: ' . $artifactType);
                continue;
            }

            $payload = $artifacts[$artifactType]['payload'];
            $schemaError = self::validateSchema($payload, self::CATALOG_SCHEMA, 'Audit Catalog');
            if ($schemaError !== null) {
                self::addError($errors, '$.' . $artifactType, $schemaError);
                $artifactReports[$artifactType] = [
                    'ok' => false,
                    'path' => $artifacts[$artifactType]['path'] ?? $defaultPath,
                    'record_count' => 0,
                ];
                continue;
            }

            $artifactReports[$artifactType] = [
                'ok' => true,
                'path' => $artifacts[$artifactType]['path'] ?? $defaultPath,
                'record_count' => self::artifactRecordCount($payload),
            ];

            if ($artifactType === 'audit_rules') {
                $rulesPayload = $payload;
                continue;
            }
            if ($artifactType === 'anomaly_patterns_extended') {
                $patternsPayload = $payload;
            }
        }

        $dependencies = self::loadDependencyMaps($errors);
        $patternIndex = [
            'ids' => [],
            'categories' => [],
            'detection_types' => [],
            'beg_ids' => [],
        ];

        self::validatePatterns($patternsPayload, $dependencies, $errors, $patternIndex);
        self::validateRules($rulesPayload, $patternIndex, $errors);

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'artifacts' => $artifactReports,
            'stats' => [
                'rules' => self::countArtifactRecords($rulesPayload, self::RULE_CATEGORIES),
                'patterns' => self::countArtifactRecords($patternsPayload, self::PATTERN_CATEGORIES),
            ],
            'catalog' => [
                'pattern_ids' => array_keys($patternIndex['ids']),
                'beg_pattern_ids' => array_keys($patternIndex['beg_ids']),
            ],
            'dependencies' => [
                'gbo_concepts' => count($dependencies['gbo_concepts']),
                'beg_event_types' => count($dependencies['beg_event_types']),
                'beg_anomaly_patterns' => count($dependencies['beg_anomaly_patterns']),
                'action_catalog' => count($dependencies['action_ids']),
                'skills_catalog' => count($dependencies['skill_ids']),
            ],
        ];
    }

    /**
     * @return array<string, array{payload: array<string, mixed>, path: ?string, source: string}>
     */
    private static function loadDefaultArtifacts(): array
    {
        $artifacts = [];
        foreach (self::DEFAULT_ARTIFACTS as $artifactType => $path) {
            $artifacts[$artifactType] = [
                'payload' => self::loadJsonFile($path),
                'path' => $path,
                'source' => 'default',
            ];
        }
        return $artifacts;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Archivo audit no existe: ' . $path);
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Archivo audit vacio: ' . $path);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('JSON audit invalido: ' . $path, 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Payload audit invalido: raiz debe ser objeto JSON en ' . $path);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function validateSchema(array $payload, string $schemaPath, string $label): ?string
    {
        if (!is_file($schemaPath)) {
            return 'Schema ' . $label . ' no existe: ' . $schemaPath;
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            return 'Schema ' . $label . ' invalido: ' . $schemaPath;
        }

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            return 'Payload ' . $label . ' no serializable.';
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if ($result->isValid()) {
            return null;
        }

        $error = $result->error();
        return $error ? $error->message() : 'Payload ' . $label . ' invalido por schema.';
    }

    /**
     * @param array<int, array<string, string>> $errors
     * @return array<string, array<string, mixed>>
     */
    private static function loadDependencyMaps(array &$errors): array
    {
        $gboConcepts = [];
        $begEventTypes = [];
        $begAnomalyPatterns = [];
        $actionIds = [];
        $skillIds = [];

        try {
            $payload = self::loadJsonFile(self::GBO_CONCEPTS);
            $concepts = is_array($payload['concepts'] ?? null) ? $payload['concepts'] : [];
            foreach ($concepts as $concept) {
                if (!is_array($concept)) {
                    continue;
                }
                $conceptId = self::normalizeKey((string) ($concept['concept_id'] ?? ''));
                if ($conceptId !== '') {
                    $gboConcepts[$conceptId] = true;
                }
            }
        } catch (Throwable $e) {
            self::addError($errors, '$.gbo_dependency', $e->getMessage());
        }

        try {
            $payload = self::loadJsonFile(self::BEG_EVENT_TYPES);
            $eventTypes = is_array($payload['event_types'] ?? null) ? $payload['event_types'] : [];
            foreach ($eventTypes as $eventType) {
                if (!is_array($eventType)) {
                    continue;
                }
                $value = self::normalizeKey((string) ($eventType['event_type'] ?? ''));
                if ($value !== '') {
                    $begEventTypes[$value] = true;
                }
            }
        } catch (Throwable $e) {
            self::addError($errors, '$.beg_dependency.event_types', $e->getMessage());
        }

        try {
            $payload = self::loadJsonFile(self::BEG_ANOMALY_PATTERNS);
            $patterns = is_array($payload['anomaly_patterns'] ?? null) ? $payload['anomaly_patterns'] : [];
            foreach ($patterns as $pattern) {
                if (!is_array($pattern)) {
                    continue;
                }
                $patternId = self::normalizeKey((string) ($pattern['pattern_id'] ?? ''));
                if ($patternId === '') {
                    continue;
                }

                $eventTypes = [];
                $appliesTo = is_array($pattern['applies_to_event_types'] ?? null) ? $pattern['applies_to_event_types'] : [];
                foreach ($appliesTo as $eventType) {
                    $eventKey = self::normalizeKey((string) $eventType);
                    if ($eventKey !== '') {
                        $eventTypes[$eventKey] = true;
                    }
                }
                $begAnomalyPatterns[$patternId] = [
                    'event_types' => $eventTypes,
                ];
            }
        } catch (Throwable $e) {
            self::addError($errors, '$.beg_dependency.anomaly_patterns', $e->getMessage());
        }

        try {
            $payload = self::loadJsonFile(self::ACTION_CATALOG);
            $catalog = is_array($payload['catalog'] ?? null) ? $payload['catalog'] : [];
            foreach ($catalog as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = self::normalizeKey((string) ($item['name'] ?? ''));
                if ($name !== '') {
                    $actionIds[$name] = true;
                }
            }
        } catch (Throwable $e) {
            self::addError($errors, '$.action_catalog_dependency', $e->getMessage());
        }

        try {
            $payload = self::loadJsonFile(self::SKILLS_CATALOG);
            $catalog = is_array($payload['catalog'] ?? null) ? $payload['catalog'] : [];
            foreach ($catalog as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = self::normalizeKey((string) ($item['name'] ?? ''));
                if ($name !== '') {
                    $skillIds[$name] = true;
                }
            }
        } catch (Throwable $e) {
            self::addError($errors, '$.skills_catalog_dependency', $e->getMessage());
        }

        return [
            'gbo_concepts' => $gboConcepts,
            'beg_event_types' => $begEventTypes,
            'beg_anomaly_patterns' => $begAnomalyPatterns,
            'action_ids' => $actionIds,
            'skill_ids' => $skillIds,
        ];
    }

    /**
     * @param array<string, mixed> $patternsPayload
     * @param array<string, array<string, mixed>> $dependencies
     * @param array<int, array<string, string>> $errors
     * @param array<string, array<string, string|bool>> $patternIndex
     */
    private static function validatePatterns(array $patternsPayload, array $dependencies, array &$errors, array &$patternIndex): void
    {
        $allowedDetectionTypes = [
            'fraud_patterns' => ['deterministic' => true],
            'data_quality_patterns' => ['deterministic' => true],
            'beg_patterns' => ['beg_causal' => true],
            'behavioral_patterns' => ['behavioral' => true, 'kpi_threshold' => true],
        ];

        foreach (self::PATTERN_CATEGORIES as $category) {
            $patterns = is_array($patternsPayload[$category] ?? null) ? $patternsPayload[$category] : [];
            foreach ($patterns as $index => $pattern) {
                if (!is_array($pattern)) {
                    self::addError($errors, '$.' . $category . '[' . $index . ']', 'Pattern audit invalido.');
                    continue;
                }

                $patternId = self::normalizeKey((string) ($pattern['pattern_id'] ?? ''));
                if ($patternId === '') {
                    self::addError($errors, '$.' . $category . '[' . $index . '].pattern_id', 'pattern_id vacio.');
                    continue;
                }
                if (isset($patternIndex['ids'][$patternId])) {
                    self::addError($errors, '$.' . $category . '[' . $index . '].pattern_id', 'pattern_id duplicado: ' . $patternId);
                    continue;
                }

                $detectionType = self::normalizeKey((string) ($pattern['detection_type'] ?? ''));
                if (!isset($allowedDetectionTypes[$category][$detectionType])) {
                    self::addError(
                        $errors,
                        '$.' . $category . '[' . $index . '].detection_type',
                        'detection_type incompatible con categoria ' . $category . ': ' . (string) ($pattern['detection_type'] ?? '')
                    );
                }

                $relatedEventTypes = is_array($pattern['related_event_types'] ?? null) ? $pattern['related_event_types'] : [];
                foreach ($relatedEventTypes as $eventType) {
                    $eventKey = self::normalizeKey((string) $eventType);
                    if ($eventKey === '' || !isset($dependencies['beg_event_types'][$eventKey])) {
                        self::addError(
                            $errors,
                            '$.' . $category . '[' . $index . '].related_event_types',
                            'Pattern audit referencia event_type BEG inexistente: ' . (string) $eventType
                        );
                    }
                }

                if ($category === 'beg_patterns') {
                    if (!isset($dependencies['beg_anomaly_patterns'][$patternId])) {
                        self::addError(
                            $errors,
                            '$.' . $category . '[' . $index . '].pattern_id',
                            'beg_pattern no existe en catalogo BEG: ' . $patternId
                        );
                    } else {
                        $allowedEventTypes = is_array($dependencies['beg_anomaly_patterns'][$patternId]['event_types'] ?? null)
                            ? $dependencies['beg_anomaly_patterns'][$patternId]['event_types']
                            : [];
                        foreach ($relatedEventTypes as $eventType) {
                            $eventKey = self::normalizeKey((string) $eventType);
                            if ($eventKey !== '' && !isset($allowedEventTypes[$eventKey])) {
                                self::addError(
                                    $errors,
                                    '$.' . $category . '[' . $index . '].related_event_types',
                                    'beg_pattern usa event_type fuera de su definicion BEG: ' . (string) $eventType
                                );
                            }
                        }
                    }
                }

                $patternIndex['ids'][$patternId] = true;
                $patternIndex['categories'][$patternId] = $category;
                $patternIndex['detection_types'][$patternId] = $detectionType;
                if ($category === 'beg_patterns') {
                    $patternIndex['beg_ids'][$patternId] = true;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $rulesPayload
     * @param array<string, array<string, string|bool>> $patternIndex
     * @param array<int, array<string, string>> $errors
     */
    private static function validateRules(array $rulesPayload, array $patternIndex, array &$errors): void
    {
        $seenRuleIds = [];

        foreach (self::RULE_CATEGORIES as $category) {
            $rules = is_array($rulesPayload[$category] ?? null) ? $rulesPayload[$category] : [];
            foreach ($rules as $index => $rule) {
                if (!is_array($rule)) {
                    self::addError($errors, '$.' . $category . '[' . $index . ']', 'Rule audit invalida.');
                    continue;
                }

                $ruleId = self::normalizeKey((string) ($rule['rule_id'] ?? ''));
                if ($ruleId === '') {
                    self::addError($errors, '$.' . $category . '[' . $index . '].rule_id', 'rule_id vacio.');
                } elseif (isset($seenRuleIds[$ruleId])) {
                    self::addError($errors, '$.' . $category . '[' . $index . '].rule_id', 'rule_id duplicado: ' . $ruleId);
                } else {
                    $seenRuleIds[$ruleId] = true;
                }

                $anomalyType = self::normalizeKey((string) ($rule['anomaly_type'] ?? ''));
                if ($anomalyType === '' || !isset($patternIndex['ids'][$anomalyType])) {
                    self::addError(
                        $errors,
                        '$.' . $category . '[' . $index . '].anomaly_type',
                        'anomaly_type fuera de catalogo audit: ' . (string) ($rule['anomaly_type'] ?? '')
                    );
                    continue;
                }

                $requiredEvidence = is_array($rule['required_evidence'] ?? null) ? $rule['required_evidence'] : [];
                $patternCategory = (string) ($patternIndex['categories'][$anomalyType] ?? '');
                $detectionType = (string) ($patternIndex['detection_types'][$anomalyType] ?? '');

                if ($category === 'beg_rules') {
                    if ($patternCategory !== 'beg_patterns') {
                        self::addError($errors, '$.' . $category . '[' . $index . '].anomaly_type', 'beg_rule debe apuntar a beg_patterns.');
                    }
                    if (!in_array('beg_trace', $requiredEvidence, true)) {
                        self::addError($errors, '$.' . $category . '[' . $index . '].required_evidence', 'beg_rule debe requerir beg_trace.');
                    }
                }

                if ($category === 'kpi_rules') {
                    if ($detectionType !== 'kpi_threshold') {
                        self::addError($errors, '$.' . $category . '[' . $index . '].anomaly_type', 'kpi_rule debe apuntar a pattern kpi_threshold.');
                    }
                    if (!in_array('metrics_refs', $requiredEvidence, true)) {
                        self::addError($errors, '$.' . $category . '[' . $index . '].required_evidence', 'kpi_rule debe requerir metrics_refs.');
                    }
                }

                if ($category === 'crm_rules') {
                    if ($patternCategory !== 'behavioral_patterns') {
                        self::addError($errors, '$.' . $category . '[' . $index . '].anomaly_type', 'crm_rule debe apuntar a behavioral_patterns.');
                    }
                    if (!in_array('related_entities', $requiredEvidence, true)) {
                        self::addError($errors, '$.' . $category . '[' . $index . '].required_evidence', 'crm_rule debe requerir related_entities.');
                    }
                }

                if ($category === 'deterministic_rules' && $detectionType !== 'deterministic') {
                    self::addError(
                        $errors,
                        '$.' . $category . '[' . $index . '].anomaly_type',
                        'deterministic_rule debe apuntar a pattern deterministic.'
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $eventRef
     * @param array<string, true> $knownEventTypes
     * @param array<int, array<string, string>> $errors
     */
    private static function validateEventReference(
        array $eventRef,
        string $basePath,
        string $tenantId,
        string $appId,
        array $knownEventTypes,
        array &$errors
    ): void {
        $eventType = self::normalizeKey((string) ($eventRef['event_type'] ?? ''));
        if ($eventType === '' || !isset($knownEventTypes[$eventType])) {
            self::addError(
                $errors,
                $basePath . '.event_type',
                'event_type fuera de catalogo BEG: ' . (string) ($eventRef['event_type'] ?? '')
            );
        }

        self::validateTenantScope($eventRef, $basePath, $tenantId, $appId, $errors);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $errors
     */
    private static function validateTenantScope(array $payload, string $basePath, string $tenantId, string $appId, array &$errors): void
    {
        $refTenant = trim((string) ($payload['tenant_id'] ?? ''));
        if ($refTenant !== '' && $refTenant !== $tenantId) {
            self::addError($errors, $basePath . '.tenant_id', 'Cross-tenant reference bloqueada.');
        }

        $refApp = trim((string) ($payload['app_id'] ?? ''));
        if ($appId !== '' && $refApp !== '' && $refApp !== $appId) {
            self::addError($errors, $basePath . '.app_id', 'Cross-app reference bloqueada.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function artifactRecordCount(array $payload): int
    {
        $categories = ($payload['artifact_type'] ?? '') === 'audit_rules'
            ? self::RULE_CATEGORIES
            : self::PATTERN_CATEGORIES;

        return self::countArtifactRecords($payload, $categories);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $categories
     */
    private static function countArtifactRecords(array $payload, array $categories): int
    {
        $count = 0;
        foreach ($categories as $category) {
            $records = $payload[$category] ?? null;
            if (is_array($records)) {
                $count += count($records);
            }
        }
        return $count;
    }

    /**
     * @param array<int, string> $items
     * @return array<string, true>
     */
    private static function indexList(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $key = self::normalizeKey((string) $item);
            if ($key !== '') {
                $map[$key] = true;
            }
        }
        return $map;
    }

    private static function normalizeKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    /**
     * @param array<int, array<string, string>> $errors
     */
    private static function addError(array &$errors, string $path, string $message): void
    {
        $errors[] = [
            'path' => $path,
            'message' => $message,
        ];
    }
}
