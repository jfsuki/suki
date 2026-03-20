<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class SectorSeedValidator
{
    private const DEFAULT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/sector_seed.schema.json';
    private const SKILLS_CATALOG = FRAMEWORK_ROOT . '/../docs/contracts/skills_catalog.json';
    private const GBO_CONCEPTS = FRAMEWORK_ROOT . '/ontology/gbo_universal_concepts.json';

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array{ok: bool, errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
     */
    public static function validate(array $payload, array $options = [], ?string $schemaPath = null): array
    {
        $schemaPath = $schemaPath ?? self::DEFAULT_SCHEMA;
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema de sector seed no existe: ' . $schemaPath);
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema de sector seed invalido.');
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
            throw new RuntimeException('Payload sector seed no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Sector seed invalido por schema.';
            $errors[] = ['path' => '$', 'message' => $message];
            return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        $expectedSectorKey = strtoupper(trim((string) ($options['expected_sector_key'] ?? '')));
        $seedSectorKey = strtoupper(trim((string) ($payload['sector_key'] ?? '')));
        if ($expectedSectorKey !== '' && $seedSectorKey !== '' && $expectedSectorKey !== $seedSectorKey) {
            $errors[] = [
                'path' => '$.sector_key',
                'message' => 'sector_key del seed no coincide con el business discovery.',
            ];
        }

        $expectedSectorLabel = trim((string) ($options['expected_sector_label'] ?? ''));
        $seedSectorLabel = trim((string) ($payload['sector_label'] ?? ''));
        if ($expectedSectorLabel !== '' && $seedSectorLabel !== '' && strcasecmp($expectedSectorLabel, $seedSectorLabel) !== 0) {
            $warnings[] = [
                'path' => '$.sector_label',
                'message' => 'sector_label del seed difiere del business discovery.',
            ];
        }

        $expectedCountry = trim((string) ($options['expected_country_or_regulation'] ?? ''));
        $seedCountry = trim((string) ($payload['country_or_regulation'] ?? ''));
        if ($expectedCountry !== '' && $seedCountry !== '' && strcasecmp($expectedCountry, $seedCountry) !== 0) {
            $warnings[] = [
                'path' => '$.country_or_regulation',
                'message' => 'country_or_regulation del seed difiere del business discovery.',
            ];
        }

        self::validateKnownSkills($payload, $errors);
        self::validateGboReferences($payload, $errors);

        $seenSkills = [];
        foreach ((array) ($payload['skill_overrides'] ?? []) as $index => $override) {
            if (!is_array($override)) {
                continue;
            }
            $skill = trim((string) ($override['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }
            if (isset($seenSkills[$skill])) {
                $errors[] = [
                    'path' => '$.skill_overrides[' . $index . '].skill',
                    'message' => 'skill duplicada en skill_overrides: ' . $skill,
                ];
                continue;
            }
            $seenSkills[$skill] = true;
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public static function validateOrFail(array $payload, array $options = [], ?string $schemaPath = null): void
    {
        $report = self::validate($payload, $options, $schemaPath);
        if (($report['ok'] ?? false) === true) {
            return;
        }

        $first = $report['errors'][0]['message'] ?? 'Sector seed invalido.';
        throw new RuntimeException($first);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $errors
     */
    private static function validateKnownSkills(array $payload, array &$errors): void
    {
        $catalog = self::loadJsonFile(self::SKILLS_CATALOG, 'skills_catalog');
        $skillNames = [];
        foreach ((array) ($catalog['catalog'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $skillNames[$name] = true;
            }
        }

        foreach ((array) ($payload['terminology_seed'] ?? []) as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $skill = trim((string) ($entry['related_skill'] ?? ''));
            if ($skill === '' || isset($skillNames[$skill])) {
                continue;
            }
            $errors[] = [
                'path' => '$.terminology_seed[' . $index . '].related_skill',
                'message' => 'related_skill inexistente en skills_catalog: ' . $skill,
            ];
        }

        foreach ((array) ($payload['skill_overrides'] ?? []) as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $skill = trim((string) ($entry['skill'] ?? ''));
            if ($skill === '' || isset($skillNames[$skill])) {
                continue;
            }
            $errors[] = [
                'path' => '$.skill_overrides[' . $index . '].skill',
                'message' => 'skill inexistente en skills_catalog: ' . $skill,
            ];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $errors
     */
    private static function validateGboReferences(array $payload, array &$errors): void
    {
        $gbo = self::loadJsonFile(self::GBO_CONCEPTS, 'gbo_universal_concepts');
        $conceptIds = [];
        foreach ((array) ($gbo['concepts'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $conceptId = trim((string) ($entry['concept_id'] ?? ''));
            if ($conceptId !== '') {
                $conceptIds[$conceptId] = true;
            }
        }

        foreach ((array) ($payload['knowledge_stable_seed'] ?? []) as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            self::validateGboConceptList($entry['gbo_concepts'] ?? null, '$.knowledge_stable_seed[' . $index . '].gbo_concepts', $conceptIds, $errors);
        }

        foreach ((array) ($payload['support_faq_seed'] ?? []) as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            self::validateGboConceptList($entry['gbo_concepts'] ?? null, '$.support_faq_seed[' . $index . '].gbo_concepts', $conceptIds, $errors);
        }

        foreach ((array) ($payload['terminology_seed'] ?? []) as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $path = '$.terminology_seed[' . $index . ']';
            $conceptId = trim((string) ($entry['gbo_concept_id'] ?? ''));
            if ($conceptId !== '') {
                if (!preg_match('/^[a-z0-9_]+$/', $conceptId)) {
                    $errors[] = [
                        'path' => $path . '.gbo_concept_id',
                        'message' => 'gbo_concept_id invalido: usa clave canonica lowercase_underscore.',
                    ];
                } elseif (!isset($conceptIds[$conceptId])) {
                    $errors[] = [
                        'path' => $path . '.gbo_concept_id',
                        'message' => 'gbo_concept_id inexistente: ' . $conceptId,
                    ];
                }
            }

            $hasAliases = array_key_exists('gbo_aliases', $entry);
            if ($hasAliases && $conceptId === '') {
                $errors[] = [
                    'path' => $path . '.gbo_aliases',
                    'message' => 'gbo_aliases requiere gbo_concept_id.',
                ];
                continue;
            }

            $aliases = self::normalizeStringList($entry['gbo_aliases'] ?? []);
            $seenAliases = [];
            foreach ($aliases as $alias) {
                $aliasKey = self::normalizeKey($alias);
                if ($aliasKey === '') {
                    $errors[] = [
                        'path' => $path . '.gbo_aliases',
                        'message' => 'gbo_alias con valor vacio o invalido.',
                    ];
                    continue;
                }
                if (isset($seenAliases[$aliasKey])) {
                    $errors[] = [
                        'path' => $path . '.gbo_aliases',
                        'message' => 'gbo_alias duplicado: ' . $alias,
                    ];
                    continue;
                }
                $seenAliases[$aliasKey] = true;
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<string, bool> $conceptIds
     * @param array<int, array<string, string>> $errors
     */
    private static function validateGboConceptList($value, string $path, array $conceptIds, array &$errors): void
    {
        if (!is_array($value)) {
            return;
        }

        $seen = [];
        foreach ($value as $index => $item) {
            $conceptId = trim((string) $item);
            if ($conceptId === '') {
                $errors[] = [
                    'path' => $path . '[' . $index . ']',
                    'message' => 'Referencia GBO vacia.',
                ];
                continue;
            }
            if (!preg_match('/^[a-z0-9_]+$/', $conceptId)) {
                $errors[] = [
                    'path' => $path . '[' . $index . ']',
                    'message' => 'Referencia GBO invalida: usa clave canonica lowercase_underscore.',
                ];
                continue;
            }
            if (!isset($conceptIds[$conceptId])) {
                $errors[] = [
                    'path' => $path . '[' . $index . ']',
                    'message' => 'Concepto GBO inexistente: ' . $conceptId,
                ];
                continue;
            }
            if (isset($seen[$conceptId])) {
                $errors[] = [
                    'path' => $path . '[' . $index . ']',
                    'message' => 'Concepto GBO duplicado: ' . $conceptId,
                ];
                continue;
            }
            $seen[$conceptId] = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadJsonFile(string $path, string $label): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('No existe %s en %s.', $label, $path));
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException(sprintf('%s esta vacio.', $label));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('%s invalido.', $label));
        }

        return $decoded;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function normalizeStringList($value): array
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

    private static function normalizeKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = function_exists('iconv')
            ? (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value)
            : $value;
        $value = preg_replace('/\s+/u', '_', $value) ?? $value;
        $value = strtolower(trim($value, '_'));
        return $value;
    }
}
