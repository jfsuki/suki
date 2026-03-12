<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class EcommerceHubContractValidator
{
    private const STORE_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/ecommerce_store.schema.json';
    private const CREDENTIAL_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/ecommerce_credential.schema.json';
    private const SYNC_JOB_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/ecommerce_sync_job.schema.json';
    private const ORDER_REF_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/ecommerce_order_ref.schema.json';
    private const STORE_SETUP_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/ecommerce_store_setup.schema.json';

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateStore(array $payload): void
    {
        self::validate(self::STORE_SCHEMA, self::normalizePayload($payload), 'EcommerceStore');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateCredential(array $payload): void
    {
        self::validate(self::CREDENTIAL_SCHEMA, self::normalizePayload($payload), 'EcommerceCredential');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateSyncJob(array $payload): void
    {
        self::validate(self::SYNC_JOB_SCHEMA, self::normalizePayload($payload), 'EcommerceSyncJob');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateOrderRef(array $payload): void
    {
        self::validate(self::ORDER_REF_SCHEMA, self::normalizePayload($payload), 'EcommerceOrderRef');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateStoreSetup(array $payload): void
    {
        self::validate(self::STORE_SETUP_SCHEMA, self::normalizePayload($payload), 'EcommerceStoreSetup');
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
        foreach (['metadata', 'metadata_json', 'checks', 'hooks'] as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                $payload[$key] = self::normalizeObjectLikeArray((array) $payload[$key]);
            }
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
