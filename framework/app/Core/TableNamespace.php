<?php
// app/Core/TableNamespace.php

namespace App\Core;

use RuntimeException;

final class TableNamespace
{
    public static function enabled(): bool
    {
        $raw = strtolower(trim((string) (getenv('DB_NAMESPACE_BY_PROJECT') ?: '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public static function resolve(string $logicalTable, ?string $projectId = null): string
    {
        $logical = self::sanitizeIdentifier($logicalTable);
        if (preg_match('/^p_[a-f0-9]{10}__/', $logical)) {
            return $logical;
        }
        if (!self::enabled()) {
            return $logical;
        }

        $projectHash = self::projectHash($projectId);
        $physical = "p_{$projectHash}__{$logical}";

        if (strlen($physical) <= 64) {
            return $physical;
        }

        $tableHash = substr(hash('sha256', $logical), 0, 8);
        $maxLogical = 64 - strlen("p_{$projectHash}__") - strlen("_{$tableHash}");
        if ($maxLogical < 8) {
            throw new RuntimeException('No se pudo resolver nombre fisico de tabla.');
        }

        $logicalShort = substr($logical, 0, $maxLogical);
        return "p_{$projectHash}__{$logicalShort}_{$tableHash}";
    }

    public static function migrationKey(string $entityName, ?string $projectId = null): string
    {
        $entity = self::sanitizeIdentifier($entityName);
        if (!self::enabled()) {
            return $entity;
        }

        $projectId = self::normalizedProjectId($projectId);

        return "{$projectId}::{$entity}";
    }

    public static function projectPrefix(?string $projectId = null): string
    {
        $projectHash = self::projectHash($projectId);
        return "p_{$projectHash}__";
    }

    public static function normalizedProjectId(?string $projectId = null): string
    {
        $projectId = $projectId ?? ProjectContext::getProjectId();
        $projectId = self::sanitizeIdentifier($projectId);
        return $projectId !== '' ? $projectId : 'default';
    }

    private static function projectHash(?string $projectId = null): string
    {
        $projectId = self::normalizedProjectId($projectId);
        return substr(hash('sha256', $projectId), 0, 10);
    }

    private static function sanitizeIdentifier(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^[a-z0-9_]+$/', $value)) {
            $value = preg_replace('/[^a-z0-9_]/', '_', $value) ?? '';
            $value = preg_replace('/_+/', '_', $value) ?? '';
            $value = trim($value, '_');
        }
        return $value;
    }
}
