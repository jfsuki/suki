<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Throwable;

final class BegValidator
{
    private const CATALOG_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/beg.schema.json';
    private const PAYLOAD_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/beg_event_payload.schema.json';
    private const GBO_EVENTS = FRAMEWORK_ROOT . '/ontology/gbo_business_events.json';

    /** @var array<string, string> */
    private const DEFAULT_ARTIFACTS = [
        'beg_event_types' => FRAMEWORK_ROOT . '/events/beg_event_types.json',
        'beg_relationship_types' => FRAMEWORK_ROOT . '/events/beg_relationship_types.json',
        'beg_anomaly_patterns' => FRAMEWORK_ROOT . '/events/beg_anomaly_patterns.json',
        'beg_projection_rules' => FRAMEWORK_ROOT . '/events/beg_projection_rules.json',
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
                if ($artifactType === '') {
                    $errors[] = [
                        'path' => $path,
                        'message' => 'Artifacto BEG sin artifact_type.',
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
    public static function validateEventPayload(array $payload, array $options = []): array
    {
        $errors = [];
        $warnings = [];

        $schemaError = self::validateSchema($payload, self::PAYLOAD_SCHEMA);
        if ($schemaError !== null) {
            self::addError($errors, '$', $schemaError);
            return [
                'ok' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'stats' => [
                    'related_entities' => 0,
                    'related_documents' => 0,
                    'causal_parent_ids' => 0,
                    'event_relationships' => 0,
                ],
            ];
        }

        $catalogArtifacts = self::loadDefaultArtifacts();
        $catalogMaps = self::buildCatalogMaps($catalogArtifacts, $errors);

        $eventType = self::normalizeKey((string) ($payload['event_type'] ?? ''));
        if ($eventType === '' || !isset($catalogMaps['event_types'][$eventType])) {
            self::addError($errors, '$.event_type', 'event_type fuera de catalogo BEG: ' . (string) ($payload['event_type'] ?? ''));
        }

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $tenantId = trim((string) ($payload['tenant_id'] ?? ''));
        $appId = trim((string) ($payload['app_id'] ?? ''));

        $relatedEntities = is_array($payload['related_entities'] ?? null) ? $payload['related_entities'] : [];
        foreach ($relatedEntities as $index => $entityRef) {
            if (!is_array($entityRef)) {
                continue;
            }
            $refTenant = trim((string) ($entityRef['tenant_id'] ?? ''));
            $refApp = trim((string) ($entityRef['app_id'] ?? ''));
            if ($refTenant !== '' && $refTenant !== $tenantId) {
                self::addError($errors, '$.related_entities[' . $index . '].tenant_id', 'Cross-tenant reference bloqueada en related_entities.');
            }
            if ($refApp !== '' && $refApp !== $appId) {
                self::addError($errors, '$.related_entities[' . $index . '].app_id', 'Cross-app reference bloqueada en related_entities.');
            }
        }

        $relatedDocuments = is_array($payload['related_documents'] ?? null) ? $payload['related_documents'] : [];
        foreach ($relatedDocuments as $index => $documentRef) {
            if (!is_array($documentRef)) {
                continue;
            }
            $refTenant = trim((string) ($documentRef['tenant_id'] ?? ''));
            $refApp = trim((string) ($documentRef['app_id'] ?? ''));
            if ($refTenant !== '' && $refTenant !== $tenantId) {
                self::addError($errors, '$.related_documents[' . $index . '].tenant_id', 'Cross-tenant reference bloqueada en related_documents.');
            }
            if ($refApp !== '' && $refApp !== $appId) {
                self::addError($errors, '$.related_documents[' . $index . '].app_id', 'Cross-app reference bloqueada en related_documents.');
            }
        }

        $parentIds = is_array($payload['causal_parent_ids'] ?? null) ? $payload['causal_parent_ids'] : [];
        foreach ($parentIds as $index => $parentId) {
            $parentValue = trim((string) $parentId);
            if ($parentValue === '') {
                self::addError($errors, '$.causal_parent_ids[' . $index . ']', 'causal_parent_id vacio.');
                continue;
            }
            if ($parentValue === $eventId) {
                self::addError($errors, '$.causal_parent_ids[' . $index . ']', 'Evento no puede referenciarse a si mismo como parent.');
            }
        }

        $status = trim((string) ($payload['status'] ?? ''));
        if (in_array($status, ['reversed', 'cancelled'], true) && $parentIds === []) {
            self::addError($errors, '$.causal_parent_ids', 'Eventos reversed/cancelled requieren causal_parent_ids.');
        }

        $seenEdges = [];
        $eventRelationships = is_array($payload['event_relationships'] ?? null) ? $payload['event_relationships'] : [];
        foreach ($eventRelationships as $index => $relationship) {
            if (!is_array($relationship)) {
                continue;
            }
            $relationshipType = self::normalizeKey((string) ($relationship['relationship_type'] ?? ''));
            if ($relationshipType === '' || !isset($catalogMaps['relationship_types'][$relationshipType])) {
                self::addError(
                    $errors,
                    '$.event_relationships[' . $index . '].relationship_type',
                    'relationship_type no permitido: ' . (string) ($relationship['relationship_type'] ?? '')
                );
                continue;
            }

            $targetEventId = trim((string) ($relationship['target_event_id'] ?? ''));
            if ($targetEventId === '') {
                self::addError($errors, '$.event_relationships[' . $index . '].target_event_id', 'target_event_id vacio.');
                continue;
            }
            if ($targetEventId === $eventId) {
                self::addError($errors, '$.event_relationships[' . $index . '].target_event_id', 'Causalidad ciclica: target_event_id no puede ser el mismo event_id.');
            }

            $edgeSignature = $relationshipType . '>' . $targetEventId;
            if (isset($seenEdges[$edgeSignature])) {
                self::addError($errors, '$.event_relationships[' . $index . ']', 'Relacion BEG duplicada hacia el mismo target_event_id.');
                continue;
            }
            $seenEdges[$edgeSignature] = true;
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => [
                'related_entities' => count($relatedEntities),
                'related_documents' => count($relatedDocuments),
                'causal_parent_ids' => count($parentIds),
                'event_relationships' => count($eventRelationships),
            ],
        ];
    }

    /**
     * @param string $path
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function validateEventPayloadFile(string $path, array $options = []): array
    {
        $payload = self::loadJsonFile($path);
        $report = self::validateEventPayload($payload, $options);
        $report['inputs'] = [$path];
        return $report;
    }

    /**
     * @param array<string, array<string, mixed>> $artifactOverrides
     * @param array<string, mixed> $options
     */
    public static function validateCatalogOrFail(array $artifactOverrides = [], array $options = []): void
    {
        $report = self::validateCatalog($artifactOverrides, $options);
        if (($report['ok'] ?? false) !== true) {
            $first = $report['errors'][0]['message'] ?? 'BEG invalido.';
            throw new RuntimeException((string) $first);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public static function validateEventPayloadOrFail(array $payload, array $options = []): void
    {
        $report = self::validateEventPayload($payload, $options);
        if (($report['ok'] ?? false) !== true) {
            $first = $report['errors'][0]['message'] ?? 'Payload BEG invalido.';
            throw new RuntimeException((string) $first);
        }
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

        $eventTypes = [];
        $relationshipTypes = [];
        $anomalyPatterns = [];
        $projectionRules = [];

        foreach (self::DEFAULT_ARTIFACTS as $artifactType => $defaultPath) {
            if (!isset($artifacts[$artifactType])) {
                self::addError($errors, '$.' . $artifactType, 'Falta artefacto BEG requerido: ' . $artifactType);
                continue;
            }

            $payload = $artifacts[$artifactType]['payload'];
            $schemaError = self::validateSchema($payload, self::CATALOG_SCHEMA);
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

            if ($artifactType === 'beg_event_types') {
                $eventTypes = is_array($payload['event_types'] ?? null) ? $payload['event_types'] : [];
                continue;
            }
            if ($artifactType === 'beg_relationship_types') {
                $relationshipTypes = is_array($payload['relationship_types'] ?? null) ? $payload['relationship_types'] : [];
                continue;
            }
            if ($artifactType === 'beg_anomaly_patterns') {
                $anomalyPatterns = is_array($payload['anomaly_patterns'] ?? null) ? $payload['anomaly_patterns'] : [];
                continue;
            }
            if ($artifactType === 'beg_projection_rules') {
                $projectionRules = is_array($payload['projection_rules'] ?? null) ? $payload['projection_rules'] : [];
            }
        }

        $gboEventTypes = self::loadGboEventTypes($errors);
        $eventTypeMap = [];
        self::validateEventTypes($eventTypes, $gboEventTypes, $errors, $eventTypeMap);

        $relationshipTypeMap = [];
        self::validateRelationshipTypes($relationshipTypes, $errors, $relationshipTypeMap);

        self::validateAnomalyPatterns($anomalyPatterns, $eventTypeMap, $errors);
        self::validateProjectionRules($projectionRules, $eventTypeMap, $relationshipTypeMap, $errors);

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'artifacts' => $artifactReports,
            'stats' => [
                'event_types' => count($eventTypes),
                'relationship_types' => count($relationshipTypes),
                'anomaly_patterns' => count($anomalyPatterns),
                'projection_rules' => count($projectionRules),
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
     * @return array<string, array<string, true>>
     */
    private static function buildCatalogMaps(array $artifacts, array &$errors): array
    {
        $eventTypes = [];
        $relationshipTypes = [];

        $begEventPayload = $artifacts['beg_event_types']['payload']['event_types'] ?? [];
        if (is_array($begEventPayload)) {
            foreach ($begEventPayload as $record) {
                if (!is_array($record)) {
                    continue;
                }
                $eventType = self::normalizeKey((string) ($record['event_type'] ?? ''));
                if ($eventType !== '') {
                    $eventTypes[$eventType] = true;
                }
            }
        }

        $begRelationshipPayload = $artifacts['beg_relationship_types']['payload']['relationship_types'] ?? [];
        if (is_array($begRelationshipPayload)) {
            foreach ($begRelationshipPayload as $record) {
                if (!is_array($record)) {
                    continue;
                }
                $relationshipType = self::normalizeKey((string) ($record['relationship_type'] ?? ''));
                if ($relationshipType !== '') {
                    $relationshipTypes[$relationshipType] = true;
                }
            }
        }

        return [
            'event_types' => $eventTypes,
            'relationship_types' => $relationshipTypes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Archivo BEG no existe: ' . $path);
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Archivo BEG vacio: ' . $path);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('JSON BEG invalido: ' . $path, 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Payload BEG invalido: raiz debe ser objeto JSON en ' . $path);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function validateSchema(array $payload, string $schemaPath): ?string
    {
        if (!is_file($schemaPath)) {
            return 'Schema BEG no existe: ' . $schemaPath;
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            return 'Schema BEG invalido: ' . $schemaPath;
        }

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            return 'Payload BEG no serializable.';
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if ($result->isValid()) {
            return null;
        }

        $error = $result->error();
        return $error ? $error->message() : 'Payload BEG invalido por schema.';
    }

    /**
     * @param array<int, array<string, string>> $errors
     * @return array<string, true>
     */
    private static function loadGboEventTypes(array &$errors): array
    {
        try {
            $payload = self::loadJsonFile(self::GBO_EVENTS);
        } catch (Throwable $e) {
            self::addError($errors, '$.gbo_dependency', $e->getMessage());
            return [];
        }

        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
        $map = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $eventType = self::normalizeKey((string) ($event['event_type'] ?? ''));
            if ($eventType !== '') {
                $map[$eventType] = true;
            }
        }
        return $map;
    }

    /**
     * @param array<int, mixed> $eventTypes
     * @param array<string, true> $gboEventTypes
     * @param array<int, array<string, string>> $errors
     * @param array<string, true> $eventTypeMap
     */
    private static function validateEventTypes(array $eventTypes, array $gboEventTypes, array &$errors, array &$eventTypeMap): void
    {
        $seenNames = [];
        foreach ($eventTypes as $index => $eventType) {
            if (!is_array($eventType)) {
                self::addError($errors, '$.beg_event_types.event_types[' . $index . ']', 'BEG event_type no es objeto.');
                continue;
            }

            $value = self::normalizeKey((string) ($eventType['event_type'] ?? ''));
            if ($value === '') {
                self::addError($errors, '$.beg_event_types.event_types[' . $index . '].event_type', 'event_type vacio.');
                continue;
            }
            if (isset($eventTypeMap[$value])) {
                self::addError($errors, '$.beg_event_types.event_types[' . $index . '].event_type', 'event_type duplicado: ' . $value);
            }
            $eventTypeMap[$value] = true;

            $canonicalName = self::normalizeKey((string) ($eventType['canonical_name'] ?? ''));
            if ($canonicalName === '') {
                self::addError($errors, '$.beg_event_types.event_types[' . $index . '].canonical_name', 'canonical_name vacio.');
            } elseif (isset($seenNames[$canonicalName])) {
                self::addError($errors, '$.beg_event_types.event_types[' . $index . '].canonical_name', 'canonical_name duplicado: ' . $canonicalName);
            } else {
                $seenNames[$canonicalName] = true;
            }

            $gboEventType = self::normalizeKey((string) ($eventType['gbo_event_type'] ?? ''));
            if ($gboEventType === '' || !isset($gboEventTypes[$gboEventType])) {
                self::addError(
                    $errors,
                    '$.beg_event_types.event_types[' . $index . '].gbo_event_type',
                    'gbo_event_type no alineado con GBO: ' . (string) ($eventType['gbo_event_type'] ?? '')
                );
            }

            $requiredScopeFields = is_array($eventType['required_scope_fields'] ?? null) ? $eventType['required_scope_fields'] : [];
            foreach (['tenant_id', 'app_id', 'occurred_at'] as $requiredField) {
                if (!in_array($requiredField, $requiredScopeFields, true)) {
                    self::addError(
                        $errors,
                        '$.beg_event_types.event_types[' . $index . '].required_scope_fields',
                        'required_scope_fields debe incluir ' . $requiredField . '.'
                    );
                }
            }
        }
    }

    /**
     * @param array<int, mixed> $relationshipTypes
     * @param array<int, array<string, string>> $errors
     * @param array<string, true> $relationshipTypeMap
     */
    private static function validateRelationshipTypes(array $relationshipTypes, array &$errors, array &$relationshipTypeMap): void
    {
        foreach ($relationshipTypes as $index => $relationshipType) {
            if (!is_array($relationshipType)) {
                self::addError($errors, '$.beg_relationship_types.relationship_types[' . $index . ']', 'BEG relationship_type no es objeto.');
                continue;
            }

            $value = self::normalizeKey((string) ($relationshipType['relationship_type'] ?? ''));
            if ($value === '') {
                self::addError($errors, '$.beg_relationship_types.relationship_types[' . $index . '].relationship_type', 'relationship_type vacio.');
                continue;
            }
            if (isset($relationshipTypeMap[$value])) {
                self::addError(
                    $errors,
                    '$.beg_relationship_types.relationship_types[' . $index . '].relationship_type',
                    'relationship_type duplicado: ' . $value
                );
            }
            $relationshipTypeMap[$value] = true;
        }
    }

    /**
     * @param array<int, mixed> $anomalyPatterns
     * @param array<string, true> $eventTypeMap
     * @param array<int, array<string, string>> $errors
     */
    private static function validateAnomalyPatterns(array $anomalyPatterns, array $eventTypeMap, array &$errors): void
    {
        $seenPatternIds = [];
        foreach ($anomalyPatterns as $index => $pattern) {
            if (!is_array($pattern)) {
                self::addError($errors, '$.beg_anomaly_patterns.anomaly_patterns[' . $index . ']', 'BEG anomaly_pattern no es objeto.');
                continue;
            }

            $patternId = self::normalizeKey((string) ($pattern['pattern_id'] ?? ''));
            if ($patternId === '') {
                self::addError($errors, '$.beg_anomaly_patterns.anomaly_patterns[' . $index . '].pattern_id', 'pattern_id vacio.');
            } elseif (isset($seenPatternIds[$patternId])) {
                self::addError($errors, '$.beg_anomaly_patterns.anomaly_patterns[' . $index . '].pattern_id', 'pattern_id duplicado: ' . $patternId);
            } else {
                $seenPatternIds[$patternId] = true;
            }

            $appliesTo = is_array($pattern['applies_to_event_types'] ?? null) ? $pattern['applies_to_event_types'] : [];
            foreach ($appliesTo as $eventType) {
                $eventKey = self::normalizeKey((string) $eventType);
                if ($eventKey === '' || !isset($eventTypeMap[$eventKey])) {
                    self::addError(
                        $errors,
                        '$.beg_anomaly_patterns.anomaly_patterns[' . $index . '].applies_to_event_types',
                        'Anomalia referencia event_type inexistente: ' . (string) $eventType
                    );
                }
            }
        }
    }

    /**
     * @param array<int, mixed> $projectionRules
     * @param array<string, true> $eventTypeMap
     * @param array<string, true> $relationshipTypeMap
     * @param array<int, array<string, string>> $errors
     */
    private static function validateProjectionRules(
        array $projectionRules,
        array $eventTypeMap,
        array $relationshipTypeMap,
        array &$errors
    ): void {
        $seenRuleIds = [];
        foreach ($projectionRules as $index => $rule) {
            if (!is_array($rule)) {
                self::addError($errors, '$.beg_projection_rules.projection_rules[' . $index . ']', 'BEG projection_rule no es objeto.');
                continue;
            }

            $ruleId = self::normalizeKey((string) ($rule['rule_id'] ?? ''));
            if ($ruleId === '') {
                self::addError($errors, '$.beg_projection_rules.projection_rules[' . $index . '].rule_id', 'rule_id vacio.');
            } elseif (isset($seenRuleIds[$ruleId])) {
                self::addError($errors, '$.beg_projection_rules.projection_rules[' . $index . '].rule_id', 'rule_id duplicado: ' . $ruleId);
            } else {
                $seenRuleIds[$ruleId] = true;
            }

            $supportedEventTypes = is_array($rule['supported_event_types'] ?? null) ? $rule['supported_event_types'] : [];
            foreach ($supportedEventTypes as $eventType) {
                $eventKey = self::normalizeKey((string) $eventType);
                if ($eventKey === '' || !isset($eventTypeMap[$eventKey])) {
                    self::addError(
                        $errors,
                        '$.beg_projection_rules.projection_rules[' . $index . '].supported_event_types',
                        'Projection rule referencia event_type inexistente: ' . (string) $eventType
                    );
                }
            }

            $supportedRelationshipTypes = is_array($rule['supported_relationship_types'] ?? null) ? $rule['supported_relationship_types'] : [];
            foreach ($supportedRelationshipTypes as $relationshipType) {
                $relationshipKey = self::normalizeKey((string) $relationshipType);
                if ($relationshipKey === '' || !isset($relationshipTypeMap[$relationshipKey])) {
                    self::addError(
                        $errors,
                        '$.beg_projection_rules.projection_rules[' . $index . '].supported_relationship_types',
                        'Projection rule referencia relationship_type inexistente: ' . (string) $relationshipType
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function artifactRecordCount(array $payload): int
    {
        foreach (['event_types', 'relationship_types', 'anomaly_patterns', 'projection_rules'] as $field) {
            $value = $payload[$field] ?? null;
            if (is_array($value)) {
                return count($value);
            }
        }
        return 0;
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
