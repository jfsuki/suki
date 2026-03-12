<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class FiscalEngineContractValidator
{
    private const DOCUMENT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/fiscal_document.schema.json';
    private const EVENT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/fiscal_event.schema.json';
    private const PAYLOAD_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/fiscal_document_payload.schema.json';

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateDocument(array $payload): void
    {
        self::validate(self::DOCUMENT_SCHEMA, self::normalizePayload($payload), 'FiscalDocument');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateEvent(array $payload): void
    {
        self::validate(self::EVENT_SCHEMA, self::normalizePayload($payload), 'FiscalEvent');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validatePayload(array $payload): void
    {
        self::validate(self::PAYLOAD_SCHEMA, self::normalizePayload($payload), 'FiscalDocumentPayload');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function validate(string $schemaPath, array $payload, string $contractName): void
    {
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema de ' . $contractName . ' no existe.');
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema de ' . $contractName . ' invalido.');
        }

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload de ' . $contractName . ' no serializable.', 0, $e);
        }

        $validator = new Validator();
        $resolver = $validator->resolver();
        if ($resolver !== null) {
            $resolver->registerPrefix('schema:///', str_replace('\\', '/', dirname($schemaPath)));
        }

        $error = $validator->dataValidation(
            $payloadObject,
            $schema,
            null,
            null,
            'schema:///' . basename($schemaPath)
        );
        if ($error !== null) {
            $message = $error->message() ?: 'Contrato invalido para ' . $contractName . '.';
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizePayload(array $payload): array
    {
        foreach (['metadata', 'metadata_json', 'payload', 'payload_json'] as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                $payload[$key] = self::normalizeObjectLikeArray((array) $payload[$key]);
            }
        }

        foreach (['lines', 'events'] as $listKey) {
            if (!is_array($payload[$listKey] ?? null)) {
                continue;
            }
            $payload[$listKey] = array_map(
                static function ($item): array {
                    $item = is_array($item) ? $item : [];
                    foreach (['metadata', 'metadata_json', 'payload', 'payload_json'] as $key) {
                        if (array_key_exists($key, $item) && is_array($item[$key])) {
                            $item[$key] = self::normalizeObjectLikeArray((array) $item[$key]);
                        }
                    }

                    return $item;
                },
                (array) $payload[$listKey]
            );
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
