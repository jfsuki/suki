<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Throwable;

final class GboValidator
{
    private const DEFAULT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/gbo.schema.json';

    /** @var array<string, string> */
    private const DEFAULT_ARTIFACTS = [
        'gbo_universal_concepts' => FRAMEWORK_ROOT . '/ontology/gbo_universal_concepts.json',
        'gbo_business_events' => FRAMEWORK_ROOT . '/ontology/gbo_business_events.json',
        'gbo_semantic_relationships' => FRAMEWORK_ROOT . '/ontology/gbo_semantic_relationships.json',
        'gbo_base_aliases' => FRAMEWORK_ROOT . '/ontology/gbo_base_aliases.json',
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

        return self::validateArtifacts($artifacts, $options);
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
                        'message' => 'Artifacto GBO sin artifact_type.',
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
     * @param array<string, array<string, mixed>> $artifactOverrides
     * @param array<string, mixed> $options
     */
    public static function validateCatalogOrFail(array $artifactOverrides = [], array $options = []): void
    {
        $report = self::validateCatalog($artifactOverrides, $options);
        if (($report['ok'] ?? false) !== true) {
            $first = $report['errors'][0]['message'] ?? 'GBO invalido.';
            throw new RuntimeException((string) $first);
        }
    }

    /**
     * @param array<string, array{payload: array<string, mixed>, path: ?string, source: string}> $artifacts
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function validateArtifacts(array $artifacts, array $options = []): array
    {
        $errors = [];
        $warnings = [];
        $artifactReports = [];

        $concepts = [];
        $events = [];
        $relationships = [];
        $aliases = [];

        foreach (self::DEFAULT_ARTIFACTS as $artifactType => $defaultPath) {
            if (!isset($artifacts[$artifactType])) {
                self::addError($errors, '$.' . $artifactType, 'Falta artefacto GBO requerido: ' . $artifactType);
                continue;
            }

            $payload = $artifacts[$artifactType]['payload'];
            $schemaError = self::validateSchema($payload);
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

            if ($artifactType === 'gbo_universal_concepts') {
                $concepts = is_array($payload['concepts'] ?? null) ? $payload['concepts'] : [];
                continue;
            }
            if ($artifactType === 'gbo_business_events') {
                $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
                continue;
            }
            if ($artifactType === 'gbo_semantic_relationships') {
                $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
                continue;
            }
            if ($artifactType === 'gbo_base_aliases') {
                $aliases = is_array($payload['aliases'] ?? null) ? $payload['aliases'] : [];
            }
        }

        $conceptIds = [];
        $conceptNameTargets = [];
        $conceptReferences = [];
        self::validateConcepts($concepts, $errors, $conceptIds, $conceptNameTargets);

        $eventTypes = [];
        $eventNameTargets = [];
        self::validateEvents($events, $conceptIds, $errors, $eventTypes, $eventNameTargets, $conceptReferences);

        $knownTargets = $conceptIds + $eventTypes;
        self::validateRelationships($relationships, $knownTargets, $errors, $conceptIds, $conceptReferences);
        self::validateAliases($aliases, $conceptIds, $eventTypes, $conceptNameTargets, $eventNameTargets, $errors, $conceptReferences);

        $checkOrphans = ($options['check_orphans'] ?? true) !== false;
        if ($checkOrphans) {
            foreach (array_keys($conceptIds) as $conceptId) {
                if (!isset($conceptReferences[$conceptId])) {
                    self::addWarning($warnings, '$.gbo_universal_concepts.concepts', 'Concepto potencialmente huerfano: ' . $conceptId);
                }
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'artifacts' => $artifactReports,
            'stats' => [
                'concepts' => count($concepts),
                'events' => count($events),
                'relationships' => count($relationships),
                'aliases' => count($aliases),
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
            throw new RuntimeException('Archivo GBO no existe: ' . $path);
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Archivo GBO vacio: ' . $path);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('JSON GBO invalido: ' . $path, 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Payload GBO invalido: raiz debe ser objeto JSON en ' . $path);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function validateSchema(array $payload): ?string
    {
        if (!is_file(self::DEFAULT_SCHEMA)) {
            return 'Schema GBO no existe: ' . self::DEFAULT_SCHEMA;
        }

        $schema = json_decode((string) file_get_contents(self::DEFAULT_SCHEMA));
        if (!$schema) {
            return 'Schema GBO invalido.';
        }

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            return 'Payload GBO no serializable.';
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if ($result->isValid()) {
            return null;
        }

        $error = $result->error();
        return $error ? $error->message() : 'Payload GBO invalido por schema.';
    }

    /**
     * @param array<int, mixed> $concepts
     * @param array<int, array<string, string>> $errors
     * @param array<string, true> $conceptIds
     * @param array<string, string> $conceptNames
     */
    private static function validateConcepts(array $concepts, array &$errors, array &$conceptIds, array &$conceptNames): void
    {
        foreach ($concepts as $index => $concept) {
            if (!is_array($concept)) {
                self::addError($errors, '$.gbo_universal_concepts.concepts[' . $index . ']', 'Concepto GBO no es objeto.');
                continue;
            }

            $conceptId = trim((string) ($concept['concept_id'] ?? ''));
            $conceptKey = self::normalizeKey($conceptId);
            if ($conceptKey === '') {
                self::addError($errors, '$.gbo_universal_concepts.concepts[' . $index . '].concept_id', 'concept_id vacio.');
                continue;
            }
            if (isset($conceptIds[$conceptKey])) {
                self::addError($errors, '$.gbo_universal_concepts.concepts[' . $index . '].concept_id', 'Concepto duplicado: ' . $conceptId);
            }
            $conceptIds[$conceptKey] = true;

            $canonicalName = trim((string) ($concept['canonical_name'] ?? ''));
            $nameKey = self::normalizeKey($canonicalName);
            if ($nameKey === '') {
                self::addError($errors, '$.gbo_universal_concepts.concepts[' . $index . '].canonical_name', 'canonical_name vacio.');
                continue;
            }
            if (isset($conceptNames[$nameKey])) {
                self::addError(
                    $errors,
                    '$.gbo_universal_concepts.concepts[' . $index . '].canonical_name',
                    'canonical_name duplicado: ' . $canonicalName
                );
                continue;
            }
            $conceptNames[$nameKey] = $conceptKey;
        }
    }

    /**
     * @param array<int, mixed> $events
     * @param array<string, true> $conceptIds
     * @param array<int, array<string, string>> $errors
     * @param array<string, true> $eventTypes
     * @param array<string, string> $eventNames
     * @param array<string, true> $conceptReferences
     */
    private static function validateEvents(
        array $events,
        array $conceptIds,
        array &$errors,
        array &$eventTypes,
        array &$eventNames,
        array &$conceptReferences
    ): void {
        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                self::addError($errors, '$.gbo_business_events.events[' . $index . ']', 'Evento GBO no es objeto.');
                continue;
            }

            $eventType = trim((string) ($event['event_type'] ?? ''));
            $eventKey = self::normalizeKey($eventType);
            if ($eventKey === '') {
                self::addError($errors, '$.gbo_business_events.events[' . $index . '].event_type', 'event_type vacio.');
                continue;
            }
            if (isset($eventTypes[$eventKey])) {
                self::addError($errors, '$.gbo_business_events.events[' . $index . '].event_type', 'event_type duplicado: ' . $eventType);
            }
            $eventTypes[$eventKey] = true;

            $canonicalName = trim((string) ($event['canonical_name'] ?? ''));
            $nameKey = self::normalizeKey($canonicalName);
            if ($nameKey === '') {
                self::addError($errors, '$.gbo_business_events.events[' . $index . '].canonical_name', 'canonical_name vacio.');
            } elseif (isset($eventNames[$nameKey])) {
                self::addError($errors, '$.gbo_business_events.events[' . $index . '].canonical_name', 'canonical_name duplicado: ' . $canonicalName);
            } else {
                $eventNames[$nameKey] = $eventKey;
            }

            $relatedConcepts = is_array($event['related_concepts'] ?? null) ? $event['related_concepts'] : [];
            foreach ($relatedConcepts as $relatedConcept) {
                $conceptKey = self::normalizeKey((string) $relatedConcept);
                if ($conceptKey === '' || !isset($conceptIds[$conceptKey])) {
                    self::addError(
                        $errors,
                        '$.gbo_business_events.events[' . $index . '].related_concepts',
                        'related_concept no existe en GBO: ' . (string) $relatedConcept
                    );
                    continue;
                }
                $conceptReferences[$conceptKey] = true;
            }
        }
    }

    /**
     * @param array<int, mixed> $relationships
     * @param array<string, true> $knownTargets
     * @param array<int, array<string, string>> $errors
     * @param array<string, true> $conceptIds
     * @param array<string, true> $conceptReferences
     */
    private static function validateRelationships(
        array $relationships,
        array $knownTargets,
        array &$errors,
        array $conceptIds,
        array &$conceptReferences
    ): void {
        $seenRelationshipIds = [];
        foreach ($relationships as $index => $relationship) {
            if (!is_array($relationship)) {
                self::addError($errors, '$.gbo_semantic_relationships.relationships[' . $index . ']', 'Relacion GBO no es objeto.');
                continue;
            }

            $relationshipId = trim((string) ($relationship['relationship_id'] ?? ''));
            $relationshipKey = self::normalizeKey($relationshipId);
            if ($relationshipKey === '') {
                self::addError($errors, '$.gbo_semantic_relationships.relationships[' . $index . '].relationship_id', 'relationship_id vacio.');
            } elseif (isset($seenRelationshipIds[$relationshipKey])) {
                self::addError(
                    $errors,
                    '$.gbo_semantic_relationships.relationships[' . $index . '].relationship_id',
                    'relationship_id duplicado: ' . $relationshipId
                );
            } else {
                $seenRelationshipIds[$relationshipKey] = true;
            }

            foreach (['source_type', 'target_type'] as $field) {
                $value = trim((string) ($relationship[$field] ?? ''));
                $key = self::normalizeKey($value);
                if ($key === '' || !isset($knownTargets[$key])) {
                    self::addError(
                        $errors,
                        '$.gbo_semantic_relationships.relationships[' . $index . '].' . $field,
                        'Tipo GBO invalido en relacion: ' . $value
                    );
                    continue;
                }
                if (isset($conceptIds[$key])) {
                    $conceptReferences[$key] = true;
                }
            }
        }
    }

    /**
     * @param array<int, mixed> $aliases
     * @param array<string, true> $conceptIds
     * @param array<string, true> $eventTypes
     * @param array<string, string> $conceptNames
     * @param array<string, string> $eventNames
     * @param array<int, array<string, string>> $errors
     * @param array<string, true> $conceptReferences
     */
    private static function validateAliases(
        array $aliases,
        array $conceptIds,
        array $eventTypes,
        array $conceptNames,
        array $eventNames,
        array &$errors,
        array &$conceptReferences
    ): void {
        $seenAliasIds = [];
        $aliasTargets = [];
        $reservedCanonical = [];

        foreach ($conceptNames as $nameKey => $conceptKey) {
            $reservedCanonical[$nameKey] = 'concept:' . $conceptKey;
        }
        foreach ($eventNames as $nameKey => $eventKey) {
            $reservedCanonical[$nameKey] = 'event:' . $eventKey;
        }

        foreach ($aliases as $index => $alias) {
            if (!is_array($alias)) {
                self::addError($errors, '$.gbo_base_aliases.aliases[' . $index . ']', 'Alias GBO no es objeto.');
                continue;
            }

            $aliasId = trim((string) ($alias['alias_id'] ?? ''));
            $aliasIdKey = self::normalizeKey($aliasId);
            if ($aliasIdKey === '') {
                self::addError($errors, '$.gbo_base_aliases.aliases[' . $index . '].alias_id', 'alias_id vacio.');
            } elseif (isset($seenAliasIds[$aliasIdKey])) {
                self::addError($errors, '$.gbo_base_aliases.aliases[' . $index . '].alias_id', 'alias_id duplicado: ' . $aliasId);
            } else {
                $seenAliasIds[$aliasIdKey] = true;
            }

            $aliasValue = trim((string) ($alias['alias'] ?? ''));
            $aliasKey = self::normalizeKey($aliasValue);
            if ($aliasKey === '') {
                self::addError($errors, '$.gbo_base_aliases.aliases[' . $index . '].alias', 'alias vacio.');
                continue;
            }

            $targetType = trim((string) ($alias['target_type'] ?? ''));
            $canonicalTarget = trim((string) ($alias['canonical_target'] ?? ''));
            $targetKey = self::normalizeKey($canonicalTarget);
            $targetSignature = $targetType . ':' . $targetKey;

            if ($targetType === 'concept') {
                if ($targetKey === '' || !isset($conceptIds[$targetKey])) {
                    self::addError(
                        $errors,
                        '$.gbo_base_aliases.aliases[' . $index . '].canonical_target',
                        'Alias apunta a concepto inexistente: ' . $canonicalTarget
                    );
                    continue;
                }
                $conceptReferences[$targetKey] = true;
            } elseif ($targetType === 'event') {
                if ($targetKey === '' || !isset($eventTypes[$targetKey])) {
                    self::addError(
                        $errors,
                        '$.gbo_base_aliases.aliases[' . $index . '].canonical_target',
                        'Alias apunta a evento inexistente: ' . $canonicalTarget
                    );
                    continue;
                }
            } else {
                self::addError(
                    $errors,
                    '$.gbo_base_aliases.aliases[' . $index . '].target_type',
                    'target_type invalido: ' . $targetType
                );
                continue;
            }

            if (isset($aliasTargets[$aliasKey]) && $aliasTargets[$aliasKey] !== $targetSignature) {
                self::addError(
                    $errors,
                    '$.gbo_base_aliases.aliases[' . $index . '].alias',
                    'Alias conflictivo "' . $aliasValue . '" apunta a multiples destinos.'
                );
            } else {
                $aliasTargets[$aliasKey] = $targetSignature;
            }

            if (isset($reservedCanonical[$aliasKey]) && $reservedCanonical[$aliasKey] !== $targetSignature) {
                self::addError(
                    $errors,
                    '$.gbo_base_aliases.aliases[' . $index . '].alias',
                    'Alias colisiona con nombre canonico existente: ' . $aliasValue
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function artifactRecordCount(array $payload): int
    {
        foreach (['concepts', 'events', 'relationships', 'aliases'] as $field) {
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

    /**
     * @param array<int, array<string, string>> $warnings
     */
    private static function addWarning(array &$warnings, string $path, string $message): void
    {
        $warnings[] = [
            'path' => $path,
            'message' => $message,
        ];
    }
}
