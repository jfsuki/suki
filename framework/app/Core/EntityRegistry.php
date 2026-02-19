<?php
// app/Core/EntityRegistry.php

namespace App\Core;

use JsonException;
use RuntimeException;
use Throwable;

class EntityRegistry
{
    private string $frameworkRoot;
    private string $projectRoot;
    private string $schemaPath;
    private string $entitiesDir;
    private string $cacheFile;

    public function __construct(?string $frameworkRoot = null, ?string $projectRoot = null)
    {
        $this->frameworkRoot = $frameworkRoot
            ?? (defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 2));

        $workspaceRoot = dirname($this->frameworkRoot);
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : $workspaceRoot . '/project');

        $this->schemaPath = $this->frameworkRoot . '/contracts/schemas/entity.schema.json';
        $this->entitiesDir = $this->projectRoot . '/contracts/entities';
        $this->cacheFile = $this->projectRoot . '/storage/cache/entities.schema.cache.json';
    }

    public function all(): array
    {
        $files = $this->entityFiles();
        if (!file_exists($this->schemaPath)) {
            throw new RuntimeException("Entity schema no existe: {$this->schemaPath}");
        }

        $schemaHash = hash_file('sha256', $this->schemaPath);
        $fileHashes = $this->hashFiles($files);
        $useCache = $this->isCacheFresh($schemaHash, $fileHashes);

        $schema = $this->readJsonObject($this->schemaPath);
        $validator = $this->buildValidator();
        $canValidate = $validator !== null;

        $entities = [];
        foreach ($files as $path) {
            $payload = $this->readJsonBoth($path);
            if (!$useCache && $canValidate) {
                $result = $validator->validate($payload['object'], $schema);
                if (!$result->isValid()) {
                    $error = $result->error();
                    $message = $error ? $error->message() : 'Error de validacion';
                    throw new RuntimeException("Entidad invalida en {$path}: {$message}");
                }
            }
            $entities[] = $payload['array'];
        }

        if (!$useCache) {
            $this->writeCache($schemaHash, $fileHashes);
        }

        return $entities;
    }

    private function buildValidator(): ?object
    {
        if (!class_exists('\\Opis\\JsonSchema\\Validator')) {
            return null;
        }
        $class = '\\Opis\\JsonSchema\\Validator';
        return new $class();
    }

    public function get(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Nombre de entidad requerido.');
        }

        foreach ($this->all() as $entity) {
            if (($entity['name'] ?? '') === $name) {
                return $entity;
            }
        }

        throw new RuntimeException("Entidad no encontrada: {$name}");
    }

    private function entityFiles(): array
    {
        if (!is_dir($this->entitiesDir)) {
            return [];
        }
        $files = glob($this->entitiesDir . '/*.json') ?: [];
        sort($files);
        return $files;
    }

    private function readJsonObject(string $path): object
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("No se pudo leer {$path}");
        }
        try {
            return json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("JSON invalido en {$path}: " . $e->getMessage());
        }
    }

    private function readJsonBoth(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("No se pudo leer {$path}");
        }

        try {
            $object = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $array = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("JSON invalido en {$path}: " . $e->getMessage());
        }

        if (!is_object($object) || !is_array($array)) {
            throw new RuntimeException("JSON root invalido en {$path}");
        }

        return ['object' => $object, 'array' => $array];
    }

    private function hashFiles(array $files): array
    {
        $hashes = [];
        foreach ($files as $path) {
            $hashes[$path] = hash_file('sha256', $path);
        }
        return $hashes;
    }

    private function isCacheFresh(string $schemaHash, array $fileHashes): bool
    {
        if (!is_file($this->cacheFile)) {
            return false;
        }

        try {
            $cache = json_decode(file_get_contents($this->cacheFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return false;
        }

        if (!is_array($cache)) {
            return false;
        }

        return ($cache['schema'] ?? '') === $schemaHash
            && ($cache['files'] ?? []) === $fileHashes;
    }

    private function writeCache(string $schemaHash, array $fileHashes): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = json_encode(
            ['schema' => $schemaHash, 'files' => $fileHashes],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if ($payload !== false) {
            file_put_contents($this->cacheFile, $payload, LOCK_EX);
        }
    }
}
