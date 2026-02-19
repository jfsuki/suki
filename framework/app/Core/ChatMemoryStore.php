<?php
// app/Core/ChatMemoryStore.php

namespace App\Core;

final class ChatMemoryStore
{
    private string $baseDir;

    public function __construct(?string $projectRoot = null)
    {
        $root = $projectRoot ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 2) . '/project');
        $this->baseDir = $root . '/storage/chat';
    }

    public function getSession(string $sessionId): array
    {
        $path = $this->sessionPath($sessionId);
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveSession(string $sessionId, array $data): void
    {
        $dir = $this->baseDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $this->sessionPath($sessionId);
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload !== false) {
            file_put_contents($path, $payload, LOCK_EX);
        }
    }

    public function getProfile(string $tenantId, string $userId): array
    {
        $path = $this->profilePath($tenantId, $userId);
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveProfile(string $tenantId, string $userId, array $profile): void
    {
        $dir = dirname($this->profilePath($tenantId, $userId));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $payload = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload !== false) {
            file_put_contents($this->profilePath($tenantId, $userId), $payload, LOCK_EX);
        }
    }

    public function getGlossary(string $tenantId): array
    {
        $path = $this->glossaryPath($tenantId);
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveGlossary(string $tenantId, array $glossary): void
    {
        $dir = dirname($this->glossaryPath($tenantId));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $payload = json_encode($glossary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload !== false) {
            file_put_contents($this->glossaryPath($tenantId), $payload, LOCK_EX);
        }
    }

    private function sessionPath(string $sessionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $sessionId) ?? 'session';
        return $this->baseDir . '/sess_' . $safe . '.json';
    }

    private function profilePath(string $tenantId, string $userId): string
    {
        $tenant = $this->safe($tenantId);
        $user = $this->safe($userId);
        return $this->baseDir . '/profiles/' . $tenant . '__' . $user . '.json';
    }

    private function glossaryPath(string $tenantId): string
    {
        $tenant = $this->safe($tenantId);
        return $this->baseDir . '/glossary/' . $tenant . '.json';
    }

    private function safe(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $value) ?? 'default';
        return trim($value, '_');
    }
}
