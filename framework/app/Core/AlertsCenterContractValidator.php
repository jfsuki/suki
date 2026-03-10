<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class AlertsCenterContractValidator
{
    private const SCHEMAS = [
        'alert' => FRAMEWORK_ROOT . '/contracts/schemas/alerts_center_alert.schema.json',
        'task' => FRAMEWORK_ROOT . '/contracts/schemas/alerts_center_task.schema.json',
        'reminder' => FRAMEWORK_ROOT . '/contracts/schemas/alerts_center_reminder.schema.json',
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateAlert(array $payload): void
    {
        self::validate('alert', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateTask(array $payload): void
    {
        self::validate('task', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateReminder(array $payload): void
    {
        self::validate('reminder', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function validate(string $kind, array $payload): void
    {
        $schemaPath = self::SCHEMAS[$kind] ?? null;
        if (!is_string($schemaPath) || !is_file($schemaPath)) {
            throw new RuntimeException('Schema de Alerts Center no existe para ' . $kind . '.');
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema de Alerts Center invalido para ' . $kind . '.');
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
            throw new RuntimeException('Payload de Alerts Center no serializable para ' . $kind . '.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : ('Contrato invalido para ' . $kind . '.');
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
