<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class POSContractValidator
{
    private const DRAFT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/sale_draft.schema.json';
    private const SESSION_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/pos_session.schema.json';
    private const SALE_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/pos_sale.schema.json';
    private const RECEIPT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/pos_receipt_payload.schema.json';
    private const CASH_SUMMARY_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/pos_cash_summary.schema.json';

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateDraft(array $payload): void
    {
        self::validate(self::DRAFT_SCHEMA, self::normalizePayload($payload), 'SaleDraft');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateSession(array $payload): void
    {
        self::validate(self::SESSION_SCHEMA, self::normalizePayload($payload), 'POSSession');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateSale(array $payload): void
    {
        self::validate(self::SALE_SCHEMA, self::normalizePayload($payload), 'POSSale');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateReceipt(array $payload): void
    {
        self::validate(self::RECEIPT_SCHEMA, self::normalizePayload($payload), 'POSReceiptPayload');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateCashSummary(array $payload): void
    {
        self::validate(self::CASH_SUMMARY_SCHEMA, self::normalizePayload($payload), 'POSCashSummary');
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
        foreach (['metadata', 'metadata_json'] as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                $payload[$key] = self::normalizeObjectLikeArray((array) $payload[$key]);
            }
        }

        if (is_array($payload['lines'] ?? null)) {
            $payload['lines'] = array_map(
                static function ($line): array {
                    $line = is_array($line) ? $line : [];
                    foreach (['metadata', 'metadata_json'] as $key) {
                        if (array_key_exists($key, $line) && is_array($line[$key])) {
                            $line[$key] = self::normalizeObjectLikeArray((array) $line[$key]);
                        }
                    }

                    return $line;
                },
                (array) $payload['lines']
            );
        }

        foreach (['header', 'totals', 'footer_hooks'] as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                $payload[$key] = self::normalizeObjectLikeArray((array) $payload[$key]);
            }
        }

        if (is_array($payload['items'] ?? null)) {
            $payload['items'] = array_map(
                static function ($item): array {
                    $item = is_array($item) ? $item : [];
                    foreach (['metadata', 'metadata_json'] as $key) {
                        if (array_key_exists($key, $item) && is_array($item[$key])) {
                            $item[$key] = self::normalizeObjectLikeArray((array) $item[$key]);
                        }
                    }

                    return $item;
                },
                (array) $payload['items']
            );
        }

        if (is_array($payload['sales'] ?? null)) {
            $payload['sales'] = array_map(
                static function ($sale): array {
                    return is_array($sale) ? $sale : [];
                },
                (array) $payload['sales']
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
