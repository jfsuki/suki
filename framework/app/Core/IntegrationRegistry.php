<?php
// app/Core/IntegrationRegistry.php

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Throwable;

final class IntegrationRegistry
{
    private string $frameworkRoot;
    private string $projectRoot;
    private string $schemaPath;
    private string $dir;
    private string $cacheFile;

    public function __construct(?string $frameworkRoot = null, ?string $projectRoot = null)
    {
        $this->frameworkRoot = $frameworkRoot
            ?? (defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 2));

        $workspaceRoot = dirname($this->frameworkRoot);
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : $workspaceRoot . '/project');

        $this->schemaPath = $this->frameworkRoot . '/contracts/schemas/integration.schema.json';
        $this->dir = $this->projectRoot . '/contracts/integrations';
        $this->cacheFile = $this->projectRoot . '/storage/cache/integrations.schema.cache.json';
    }

    public function all(): array
    {
        $files = $this->integrationFiles();
        if (!file_exists($this->schemaPath)) {
            throw new RuntimeException("Integration schema no existe: {$this->schemaPath}");
        }

        $schemaHash = hash_file('sha256', $this->schemaPath);
        $fileHashes = $this->hashFiles($files);
        $useCache = $this->isCacheFresh($schemaHash, $fileHashes);

        $schema = $this->readJsonObject($this->schemaPath);
        $validator = new Validator();

        $items = [];
        foreach ($files as $path) {
            $payload = $this->readJsonBoth($path);
            if (!$useCache) {
                $result = $validator->validate($payload['object'], $schema);
                if (!$result->isValid()) {
                    $error = $result->error();
                    $message = $error ? $error->message() : 'Error de validacion';
                    throw new RuntimeException("Integracion invalida en {$path}: {$message}");
                }
            }
            $items[] = $payload['array'];
        }

        if (!$useCache) {
            $this->writeCache($schemaHash, $fileHashes);
        }

        return $items;
    }

    public function get(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new RuntimeException('ID de integracion requerido.');
        }

        foreach ($this->all() as $item) {
            if (($item['id'] ?? '') === $id) {
                return $item;
            }
        }

        throw new RuntimeException("Integracion no encontrada: {$id}");
    }

    private function integrationFiles(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $files = glob($this->dir . '/*.json') ?: [];
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
