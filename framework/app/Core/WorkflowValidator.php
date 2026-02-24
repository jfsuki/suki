<?php
// app/Core/WorkflowValidator.php

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class WorkflowValidator
{
    public static function validateOrFail(array $payload, ?string $schemaPath = null): void
    {
        $schemaPath = $schemaPath ?? FRAMEWORK_ROOT . '/contracts/schemas/workflow.schema.json';
        if (!file_exists($schemaPath)) {
            throw new RuntimeException("Schema de workflow no existe: {$schemaPath}");
        }

        $schema = json_decode(file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema de workflow invalido.');
        }

        try {
            $payloadObject = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Workflow invalido: payload no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Workflow invalido';
            throw new RuntimeException($message);
        }
    }
}
