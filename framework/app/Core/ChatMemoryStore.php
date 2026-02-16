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

    private function sessionPath(string $sessionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $sessionId) ?? 'session';
        return $this->baseDir . '/sess_' . $safe . '.json';
    }
}
