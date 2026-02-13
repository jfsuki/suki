<?php
// app/Core/InvoiceValidator.php

namespace App\Core;

use Opis\JsonSchema\Validator;
use RuntimeException;

final class InvoiceValidator
{
    public static function validateOrFail(array $payload, ?string $schemaPath = null): void
    {
        $schemaPath = $schemaPath ?? FRAMEWORK_ROOT . '/contracts/schemas/invoice.schema.json';
        if (!file_exists($schemaPath)) {
            throw new RuntimeException("Schema invoice no existe: {$schemaPath}");
        }

        $schema = json_decode(file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema invoice invalido.');
        }

        $validator = new Validator();
        $result = $validator->validate($payload, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Invoice invalido';
            throw new RuntimeException($message);
        }
    }
}
