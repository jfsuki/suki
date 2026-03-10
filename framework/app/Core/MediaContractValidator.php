<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class MediaContractValidator
{
    private const FILE_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/media_file.schema.json';

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateFile(array $payload): void
    {
        if (!is_file(self::FILE_SCHEMA)) {
            throw new RuntimeException('Schema de MediaFile no existe.');
        }

        $schema = json_decode((string) file_get_contents(self::FILE_SCHEMA));
        if (!$schema) {
            throw new RuntimeException('Schema de MediaFile invalido.');
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
            throw new RuntimeException('Payload de MediaFile no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Contrato invalido para MediaFile.';
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizePayload(array $payload): array
    {
        if (array_key_exists('metadata', $payload) && is_array($payload['metadata'])) {
            $payload['metadata'] = self::normalizeObjectLikeArray((array) $payload['metadata']);
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
