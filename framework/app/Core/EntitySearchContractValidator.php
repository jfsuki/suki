<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class EntitySearchContractValidator
{
    private const RESULT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/entity_search_result.schema.json';

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateResult(array $payload): void
    {
        if (!is_file(self::RESULT_SCHEMA)) {
            throw new RuntimeException('Schema de EntitySearchResult no existe.');
        }

        $schema = json_decode((string) file_get_contents(self::RESULT_SCHEMA));
        if (!$schema) {
            throw new RuntimeException('Schema de EntitySearchResult invalido.');
        }

        $payload = self::normalizePayload($payload);

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload de EntitySearchResult no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Contrato invalido para EntitySearchResult.';
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizePayload(array $payload): array
    {
        if (array_key_exists('metadata_json', $payload) && is_array($payload['metadata_json'])) {
            $payload['metadata_json'] = self::normalizeObjectLikeArray((array) $payload['metadata_json']);
        }

        return $payload;
    }

    /**
     * @param array<string|int, mixed> $value
     * @return object|array<string|int, mixed>
     */
    private static function normalizeObjectLikeArray(array $value)
    {
        if ($value === []) {
            return (object) [];
        }

        if (array_is_list($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalizeObjectLikeArray($item);
            }
        }

        return $value;
    }
}
