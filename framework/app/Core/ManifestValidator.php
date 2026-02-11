<?php
// app/Core/ManifestValidator.php

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Throwable;

class ManifestValidator
{
    private const MANIFEST_PATH = '/contracts/app.manifest.json';
    private const SCHEMA_PATH = '/contracts/schemas/app.manifest.schema.json';
    private const CACHE_FILE = '/storage/cache/manifest.schema.cache.json';

    public static function validateOrFail(
        ?string $manifestPath = null,
        ?string $schemaPath = null,
        bool $useCache = true
    ): void {
        $manifestPath = $manifestPath ?? PROJECT_ROOT . self::MANIFEST_PATH;
        $schemaPath = $schemaPath ?? FRAMEWORK_ROOT . self::SCHEMA_PATH;

        if (!file_exists($manifestPath)) {
            throw new RuntimeException("App manifest no existe: {$manifestPath}");
        }
        if (!file_exists($schemaPath)) {
            throw new RuntimeException("Schema no existe: {$schemaPath}");
        }

        $manifestHash = hash_file('sha256', $manifestPath);
        $schemaHash = hash_file('sha256', $schemaPath);
        $cachePath = PROJECT_ROOT . self::CACHE_FILE;

        if ($useCache && self::isCacheFresh($cachePath, $manifestHash, $schemaHash)) {
            return;
        }

        try {
            $manifest = json_decode(file_get_contents($manifestPath), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("App manifest JSON invalido: " . $e->getMessage());
        }

        try {
            $schema = json_decode(file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Schema JSON invalido: " . $e->getMessage());
        }

        $validator = new Validator();
        $result = $validator->validate($manifest, $schema);

        if (!$result->isValid()) {
            $error = $result->error();
            $path = '';

            if ($error && $error->data()) {
                $path = self::formatPath($error->data()->fullPath());
            }

            $message = $error ? $error->message() : 'Error de validacion de schema';
            throw new RuntimeException("App manifest invalido{$path}: {$message}");
        }

        self::writeCache($cachePath, $manifestHash, $schemaHash);
    }

    private static function isCacheFresh(string $cachePath, string $manifestHash, string $schemaHash): bool
    {
        if (!is_file($cachePath)) {
            return false;
        }

        try {
            $payload = json_decode(file_get_contents($cachePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return false;
        }

        if (!is_array($payload)) {
            return false;
        }

        return ($payload['manifest'] ?? '') === $manifestHash
            && ($payload['schema'] ?? '') === $schemaHash;
    }

    private static function writeCache(string $cachePath, string $manifestHash, string $schemaHash): void
    {
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = json_encode(
            [
                'manifest' => $manifestHash,
                'schema' => $schemaHash,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if ($payload !== false) {
            file_put_contents($cachePath, $payload, LOCK_EX);
        }
    }

    private static function formatPath(array $path): string
    {
        if (!$path) {
            return '';
        }

        $segments = array_map(static function ($segment) {
            $segment = (string) $segment;
            $segment = str_replace('~', '~0', $segment);
            return str_replace('/', '~1', $segment);
        }, $path);

        return ' at /' . implode('/', $segments);
    }
}
