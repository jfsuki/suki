<?php
// app/Core/StorageModel.php

namespace App\Core;

final class StorageModel
{
    public const LEGACY = 'legacy';
    public const CANONICAL = 'canonical';

    /** @var array<string, string> */
    private static array $cache = [];

    public static function normalize(string $model): string
    {
        $model = strtolower(trim($model));
        return $model === self::CANONICAL ? self::CANONICAL : self::LEGACY;
    }

    public static function current(?string $projectId = null): string
    {
        $projectId = self::sanitizeProjectId($projectId ?: ProjectContext::getProjectId());
        if ($projectId === '') {
            $projectId = 'default';
        }
        if (isset(self::$cache[$projectId])) {
            return self::$cache[$projectId];
        }

        $envModel = trim((string) (getenv('PROJECT_STORAGE_MODEL') ?: getenv('DB_STORAGE_MODEL') ?: ''));
        if ($envModel !== '') {
            $model = self::normalize($envModel);
            self::$cache[$projectId] = $model;
            return $model;
        }

        try {
            $registry = new ProjectRegistry();
            $project = $registry->getProject($projectId);
            if (is_array($project)) {
                $model = self::normalize((string) ($project['storage_model'] ?? ''));
                self::$cache[$projectId] = $model;
                return $model;
            }
        } catch (\Throwable $e) {
            // Fallback legacy when registry is unavailable.
        }

        self::$cache[$projectId] = self::LEGACY;
        return self::LEGACY;
    }

    public static function isCanonical(?string $projectId = null): bool
    {
        return self::current($projectId) === self::CANONICAL;
    }

    public static function appId(?string $projectId = null): string
    {
        $projectId = $projectId ?: ProjectContext::getProjectId();
        $projectId = self::sanitizeProjectId($projectId);
        return $projectId !== '' ? $projectId : 'default';
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function sanitizeProjectId(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^a-z0-9_]/', '_', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? '';
        return trim($value, '_');
    }
}
