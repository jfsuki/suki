<?php
// app/Core/IntegrationValidator.php

namespace App\Core;

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

        $validator = new Validator();
        $result = $validator->validate($payload, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Integracion invalida';
            throw new RuntimeException($message);
        }
    }
}
