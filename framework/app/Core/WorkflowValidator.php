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

        self::validateSemanticsOrFail($payload);
    }

    private static function validateSemanticsOrFail(array $payload): void
    {
        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        $edges = is_array($payload['edges'] ?? null) ? $payload['edges'] : [];

        $nodeMap = [];
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                throw new RuntimeException('Workflow invalido: nodo no es objeto en posicion ' . $index . '.');
            }
            $nodeId = trim((string) ($node['id'] ?? ''));
            if ($nodeId === '') {
                throw new RuntimeException('Workflow invalido: nodo sin id en posicion ' . $index . '.');
            }
            if (isset($nodeMap[$nodeId])) {
                throw new RuntimeException('Workflow invalido: id de nodo duplicado "' . $nodeId . '".');
            }
            $nodeMap[$nodeId] = true;
        }

        $adjacency = [];
        $inDegree = [];
        foreach (array_keys($nodeMap) as $nodeId) {
            $adjacency[$nodeId] = [];
            $inDegree[$nodeId] = 0;
        }

        foreach ($edges as $index => $edge) {
            if (!is_array($edge)) {
                throw new RuntimeException('Workflow invalido: edge no es objeto en posicion ' . $index . '.');
            }
            $from = trim((string) ($edge['from'] ?? ''));
            $to = trim((string) ($edge['to'] ?? ''));
            if (!isset($nodeMap[$from])) {
                throw new RuntimeException('Workflow invalido: edge[' . $index . '] from "' . $from . '" no existe en nodes.');
            }
            if (!isset($nodeMap[$to])) {
                throw new RuntimeException('Workflow invalido: edge[' . $index . '] to "' . $to . '" no existe en nodes.');
            }
            if ($from === $to) {
                throw new RuntimeException('Workflow invalido: edge[' . $index . '] no puede conectar nodo consigo mismo.');
            }
            $mapping = $edge['mapping'] ?? null;
            if (!is_array($mapping) || empty($mapping)) {
                throw new RuntimeException('Workflow invalido: edge[' . $index . '] requiere mapping no vacio.');
            }
            foreach ($mapping as $targetKey => $sourcePath) {
                $target = trim((string) $targetKey);
                $source = trim((string) $sourcePath);
                if ($target === '' || $source === '') {
                    throw new RuntimeException('Workflow invalido: edge[' . $index . '] tiene mapping incompleto.');
                }
            }

            $adjacency[$from][] = $to;
            $inDegree[$to]++;
        }

        $queue = [];
        foreach ($inDegree as $nodeId => $degree) {
            if ($degree === 0) {
                $queue[] = $nodeId;
            }
        }
        $visited = 0;
        while (!empty($queue)) {
            $nodeId = array_shift($queue);
            $visited++;
            foreach ($adjacency[$nodeId] as $to) {
                $inDegree[$to]--;
                if ($inDegree[$to] === 0) {
                    $queue[] = $to;
                }
            }
        }

        if ($visited !== count($nodeMap)) {
            throw new RuntimeException('Workflow invalido: se detecto ciclo en el grafo DAG.');
        }
    }
}
