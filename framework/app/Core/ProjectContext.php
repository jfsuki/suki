<?php
// app/Core/ProjectContext.php

namespace App\Core;

final class ProjectContext
{
    private static ?string $manifestProjectId = null;

    public static function getProjectId(string $fallback = 'default'): string
    {
        $fromSession = self::sessionProjectId();
        if ($fromSession !== null) {
            return $fromSession;
        }

        $fromEnv = self::sanitize((string) (getenv('PROJECT_ID') ?: ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $fromManifest = self::manifestProjectId();
        if ($fromManifest !== '') {
            return $fromManifest;
        }

        $fallback = self::sanitize($fallback);
        return $fallback !== '' ? $fallback : 'default';
    }

    private static function sessionProjectId(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $id = $_SESSION['current_project_id'] ?? null;
        if (!is_string($id)) {
            return null;
        }

        $id = self::sanitize($id);
        return $id !== '' ? $id : null;
    }

    private static function manifestProjectId(): string
    {
        if (self::$manifestProjectId !== null) {
            return self::$manifestProjectId;
        }

        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 2);
        $workspaceRoot = dirname($frameworkRoot);
        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : $workspaceRoot . '/project';
        $manifestPath = $projectRoot . '/contracts/app.manifest.json';

        if (!is_file($manifestPath)) {
            self::$manifestProjectId = '';
            return '';
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            self::$manifestProjectId = '';
            return '';
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            self::$manifestProjectId = '';
            return '';
        }

        $id = self::sanitize((string) ($json['id'] ?? ''));
        self::$manifestProjectId = $id;
        return $id;
    }

    private static function sanitize(string $value): string
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
