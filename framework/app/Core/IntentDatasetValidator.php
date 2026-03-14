<?php
// app/Core/IntentDatasetValidator.php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class IntentDatasetValidator
{
    private const DEFAULT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/intent_dataset.schema.json';
    private const DEFAULT_SKILLS_CATALOG = FRAMEWORK_ROOT . '/../docs/contracts/skills_catalog.json';
    private const MIN_INTENTS = 25;
    private const MIN_UTTERANCES = 10;
    private const MAX_UTTERANCES = 15;
    private const MAX_UTTERANCE_LENGTH = 120;

    /**
     * @param array<string,mixed> $payload
     */
    public static function supports(array $payload): bool
    {
        if (($payload['dataset_type'] ?? null) === 'intent_dataset') {
            return true;
        }

        $sourceMetadata = is_array($payload['source_metadata'] ?? null) ? $payload['source_metadata'] : [];
        if (($sourceMetadata['type'] ?? null) === 'intent_dataset') {
            return true;
        }

        return is_array($payload['entries'] ?? null);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok: bool, errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>, stats: array<string, mixed>}
     */
    public static function validate(array $payload, ?string $schemaPath = null): array
    {
        $schemaPath = $schemaPath ?? self::DEFAULT_SCHEMA;
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema de intent dataset no existe: ' . $schemaPath);
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema de intent dataset invalido.');
        }

        $errors = [];
        $warnings = [];

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload intent dataset no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Intent dataset invalido por schema.';
            $errors[] = ['path' => '$', 'message' => $message];

            return [
                'ok' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'stats' => self::buildStats($payload, $errors, $warnings),
            ];
        }

        self::validateSemanticRules($payload, $errors, $warnings);

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => self::buildStats($payload, $errors, $warnings),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     */
    private static function validateSemanticRules(array $payload, array &$errors, array &$warnings): void
    {
        $sourceMetadata = is_array($payload['source_metadata'] ?? null) ? $payload['source_metadata'] : [];
        if (trim((string) ($payload['source_type'] ?? '')) !== 'agent_training') {
            self::addError($errors, '$.source_type', 'Intent dataset debe usar source_type=agent_training.');
        }
        if (trim((string) ($payload['memory_type'] ?? '')) !== 'agent_training') {
            self::addError($errors, '$.memory_type', 'Intent dataset debe usar memory_type=agent_training.');
        }
        if (trim((string) ($sourceMetadata['collection'] ?? '')) !== 'agent_training') {
            self::addError($errors, '$.source_metadata.collection', 'La coleccion canonica debe ser agent_training.');
        }
        if (trim((string) ($sourceMetadata['dataset'] ?? '')) !== 'intents_erp_base') {
            self::addWarning($warnings, '$.source_metadata.dataset', 'Dataset recomendado: intents_erp_base.');
        }
        if (trim((string) ($sourceMetadata['domain'] ?? '')) !== 'erp') {
            self::addError($errors, '$.source_metadata.domain', 'Intent dataset ERP debe usar domain=erp.');
        }
        if (trim((string) ($sourceMetadata['embedding_model'] ?? '')) !== 'gemini-embedding-001') {
            self::addError(
                $errors,
                '$.source_metadata.embedding_model',
                'El embedding model requerido es gemini-embedding-001.'
            );
        }
        if ((int) ($sourceMetadata['embedding_dimension'] ?? 0) !== 768) {
            self::addError(
                $errors,
                '$.source_metadata.embedding_dimension',
                'La dimension requerida es 768.'
            );
        }

        $skillsCatalog = self::loadSkillsCatalog();
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        if (count($entries) < self::MIN_INTENTS) {
            self::addError(
                $errors,
                '$.entries',
                'El dataset requiere al menos ' . self::MIN_INTENTS . ' intents.'
            );
        }

        $seenIntents = [];
        $seenUtterancesGlobal = [];

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                self::addError($errors, '$.entries[' . $index . ']', 'La entrada no es un objeto.');
                continue;
            }

            $path = '$.entries[' . $index . ']';
            $intent = trim((string) ($entry['intent'] ?? ''));
            $intentKey = self::toKey($intent);
            if ($intentKey !== '' && isset($seenIntents[$intentKey])) {
                self::addError($errors, $path . '.intent', 'Intent duplicado: ' . $intent);
            }
            if ($intentKey !== '') {
                $seenIntents[$intentKey] = true;
            }

            $skill = trim((string) ($entry['skill'] ?? ''));
            if ($skill !== '' && !isset($skillsCatalog[$skill])) {
                self::addError($errors, $path . '.skill', 'Skill inexistente en catalogo: ' . $skill);
            }

            $confidence = trim((string) ($entry['confidence'] ?? ''));
            if (!in_array($confidence, ['high', 'medium'], true)) {
                self::addError($errors, $path . '.confidence', 'confidence debe ser high o medium.');
            }

            if (trim((string) ($entry['domain'] ?? '')) !== 'erp') {
                self::addError($errors, $path . '.domain', 'Todas las entradas deben tener domain=erp.');
            }

            $utterances = self::stringList($entry['utterances'] ?? []);
            if (count($utterances) < self::MIN_UTTERANCES || count($utterances) > self::MAX_UTTERANCES) {
                self::addError(
                    $errors,
                    $path . '.utterances',
                    'Cada intent debe tener entre ' . self::MIN_UTTERANCES . ' y ' . self::MAX_UTTERANCES . ' utterances.'
                );
            }

            $seenLocal = [];
            foreach ($utterances as $utteranceIndex => $utterance) {
                if (self::strLen($utterance) > self::MAX_UTTERANCE_LENGTH) {
                    self::addError(
                        $errors,
                        $path . '.utterances[' . $utteranceIndex . ']',
                        'La utterance supera ' . self::MAX_UTTERANCE_LENGTH . ' caracteres.'
                    );
                }

                if (self::containsNoise($utterance)) {
                    self::addWarning(
                        $warnings,
                        $path . '.utterances[' . $utteranceIndex . ']',
                        'Utterance potencialmente ruidosa/no operativa.'
                    );
                }

                $utteranceKey = self::toKey($utterance);
                if ($utteranceKey === '') {
                    self::addError($errors, $path . '.utterances[' . $utteranceIndex . ']', 'Utterance vacia.');
                    continue;
                }
                if (isset($seenLocal[$utteranceKey])) {
                    self::addError($errors, $path . '.utterances', 'Utterance duplicada dentro del intent: ' . $utterance);
                }
                if (isset($seenUtterancesGlobal[$utteranceKey])) {
                    self::addError(
                        $errors,
                        $path . '.utterances[' . $utteranceIndex . ']',
                        'Utterance duplicada globalmente: ' . $utterance
                    );
                }
                $seenLocal[$utteranceKey] = true;
                $seenUtterancesGlobal[$utteranceKey] = true;
            }
        }

        $declaredSkills = self::stringList($sourceMetadata['skills_referenced'] ?? []);
        foreach ($declaredSkills as $declaredSkill) {
            if (!isset($skillsCatalog[$declaredSkill])) {
                self::addError(
                    $errors,
                    '$.source_metadata.skills_referenced',
                    'skills_referenced contiene una skill inexistente: ' . $declaredSkill
                );
            }
        }
    }

    /**
     * @return array<string,bool>
     */
    private static function loadSkillsCatalog(): array
    {
        if (!is_file(self::DEFAULT_SKILLS_CATALOG)) {
            throw new RuntimeException('No existe skills_catalog.json para validar el dataset.');
        }

        $raw = file_get_contents(self::DEFAULT_SKILLS_CATALOG);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('skills_catalog.json esta vacio.');
        }

        $decoded = json_decode($raw, true);
        $catalog = is_array($decoded['catalog'] ?? null) ? $decoded['catalog'] : [];
        $result = [];
        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $result[$name] = true;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     * @return array<string,mixed>
     */
    private static function buildStats(array $payload, array $errors = [], array $warnings = []): array
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $utterancesTotal = 0;
        $uniqueUtterances = [];
        $skills = [];
        $utteranceCoverage = 0.0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $utterances = self::stringList($entry['utterances'] ?? []);
            $utterancesTotal += count($utterances);
            $utteranceCoverage += min(1.0, count($utterances) / self::MIN_UTTERANCES);

            $skill = trim((string) ($entry['skill'] ?? ''));
            if ($skill !== '') {
                $skills[$skill] = true;
            }

            foreach ($utterances as $utterance) {
                $key = self::toKey($utterance);
                if ($key !== '') {
                    $uniqueUtterances[$key] = true;
                }
            }
        }

        $intentCount = count($entries);
        $intentCoverage = min(1.0, $intentCount / self::MIN_INTENTS);
        $utteranceCoverage = $intentCount > 0 ? round($utteranceCoverage / $intentCount, 4) : 0.0;
        $coverageRatio = round(($intentCoverage + $utteranceCoverage) / 2, 4);

        $uniqueCount = count($uniqueUtterances);
        $uniquenessRatio = $utterancesTotal > 0 ? round($uniqueCount / $utterancesTotal, 4) : 1.0;

        $baseQuality = (0.6 * $coverageRatio) + (0.4 * $uniquenessRatio);
        $errorPenalty = min(0.6, count($errors) * 0.03);
        $warningPenalty = min(0.2, count($warnings) * 0.01);
        $qualityScore = max(0.0, min(1.0, round($baseQuality - $errorPenalty - $warningPenalty, 4)));

        return [
            'entries' => $intentCount,
            'utterances_total' => $utterancesTotal,
            'unique_utterances' => $uniqueCount,
            'skills_referenced' => count($skills),
            'coverage_ratio' => $coverageRatio,
            'uniqueness_ratio' => $uniquenessRatio,
            'quality_score' => $qualityScore,
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return $result;
    }

    private static function containsNoise(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $patterns = [
            '/\b(lorem ipsum|dummy|placeholder|texto de relleno)\b/iu',
            '/\b(compra ahora|oferta limitada|haz clic|promo|promocion)\b/iu',
            '/^\s*(web|telegram|whatsapp)\s*:/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function toKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        $value = is_string($value) ? trim($value) : '';

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private static function strLen(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
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
}
