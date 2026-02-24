<?php
// app/Core/IntegrationValidator.php

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class IntegrationValidator
{
    public static function validateOrFail(array $payload, ?string $schemaPath = null): void
    {
        $schemaPath = $schemaPath ?? FRAMEWORK_ROOT . '/contracts/schemas/integration.schema.json';
        if (!file_exists($schemaPath)) {
            throw new RuntimeException("Schema de integracion no existe: {$schemaPath}");
        }

        $schema = json_decode(file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema de integracion invalido.');
        }

        try {
            $payloadObject = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Integracion invalida: payload no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Integracion invalida';
            throw new RuntimeException($message);
        }
    }
}
