<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class SectorSeedValidator
{
    private const DEFAULT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/sector_seed.schema.json';

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
}
