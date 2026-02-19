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

    public function getResearchQueue(string $tenantId): array
    {
        $path = $this->researchPath($tenantId);
        if (!is_file($path)) {
            return ['topics' => []];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return ['topics' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['topics' => []];
        }
        if (!isset($decoded['topics']) || !is_array($decoded['topics'])) {
            $decoded['topics'] = [];
        }
        return $decoded;
    }

    public function appendResearchTopic(string $tenantId, string $topic, string $userId, string $sampleText): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return [];
        }

        $queue = $this->getResearchQueue($tenantId);
        $topics = is_array($queue['topics'] ?? null) ? $queue['topics'] : [];
        $key = mb_strtolower($topic, 'UTF-8');
        $now = date('c');
        $found = null;

        foreach ($topics as $idx => $entry) {
            $entryTopic = mb_strtolower((string) ($entry['topic'] ?? ''), 'UTF-8');
            if ($entryTopic === $key) {
                $found = $idx;
                break;
            }
        }

        if ($found === null) {
            $topics[] = [
                'topic' => $topic,
                'count' => 1,
                'status' => 'pending_research',
                'first_seen' => $now,
                'last_seen' => $now,
                'last_user' => $userId,
                'samples' => [$sampleText],
            ];
            $entry = $topics[array_key_last($topics)];
        } else {
            $entry = $topics[$found];
            $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
            $entry['status'] = (string) ($entry['status'] ?? 'pending_research');
            $entry['last_seen'] = $now;
            $entry['last_user'] = $userId;
            $samples = is_array($entry['samples'] ?? null) ? $entry['samples'] : [];
            if ($sampleText !== '' && !in_array($sampleText, $samples, true)) {
                $samples[] = $sampleText;
            }
            if (count($samples) > 5) {
                $samples = array_slice($samples, -5);
            }
            $entry['samples'] = $samples;
            $topics[$found] = $entry;
        }

        usort(
            $topics,
            static fn(array $a, array $b): int => ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0))
        );
        if (count($topics) > 200) {
            $topics = array_slice($topics, 0, 200);
        }

        $queue['topics'] = array_values($topics);
        $this->saveResearchQueue($tenantId, $queue);
        return $entry;
    }

    public function saveResearchQueue(string $tenantId, array $queue): void
    {
        $dir = dirname($this->researchPath($tenantId));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!isset($queue['topics']) || !is_array($queue['topics'])) {
            $queue['topics'] = [];
        }
        $payload = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload !== false) {
            file_put_contents($this->researchPath($tenantId), $payload, LOCK_EX);
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

    private function researchPath(string $tenantId): string
    {
        $tenant = $this->safe($tenantId);
        return $this->baseDir . '/research/' . $tenant . '.json';
    }

    private function safe(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $value) ?? 'default';
        return trim($value, '_');
    }
}
