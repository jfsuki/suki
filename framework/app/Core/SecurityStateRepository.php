<?php
// app/Core/SecurityStateRepository.php

namespace App\Core;

use PDO;
use RuntimeException;

final class SecurityStateRepository
{
    private PDO $db;

    public function __construct(?string $dbPath = null)
    {
        $dbPath = $dbPath ?: $this->defaultPath();
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear directorio de seguridad: ' . $dir);
        }

        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA busy_timeout = 3000');
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->ensureSchema();
    }

    /**
     * @return array{ok:bool,remaining:int,retry_after:int,count:int}
     */
    public function consumeRateLimit(string $bucketKey, int $limitPerWindow, int $windowSeconds = 60): array
    {
        $bucketKey = trim($bucketKey);
        if ($bucketKey === '' || $limitPerWindow < 1 || $windowSeconds < 1) {
            return ['ok' => true, 'remaining' => 0, 'retry_after' => 0, 'count' => 0];
        }

        $now = time();
        $windowStart = (int) (floor($now / $windowSeconds) * $windowSeconds);

        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT window_start, request_count FROM api_rate_limits WHERE bucket_key = :key');
            $select->execute([':key' => $bucketKey]);
            $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;

            $count = 1;
            if (is_array($row)) {
                $storedWindow = (int) ($row['window_start'] ?? 0);
                $storedCount = (int) ($row['request_count'] ?? 0);
                if ($storedWindow === $windowStart) {
                    $count = $storedCount + 1;
                }
            }

            $upsert = $this->db->prepare('INSERT INTO api_rate_limits (bucket_key, window_start, request_count, updated_at)
                VALUES (:key, :window_start, :request_count, :updated_at)
                ON CONFLICT(bucket_key)
                DO UPDATE SET window_start = excluded.window_start,
                              request_count = excluded.request_count,
                              updated_at = excluded.updated_at');
            $upsert->execute([
                ':key' => $bucketKey,
                ':window_start' => $windowStart,
                ':request_count' => $count,
                ':updated_at' => $now,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $ok = $count <= $limitPerWindow;
        $remaining = max(0, $limitPerWindow - $count);
        $retryAfter = max(1, ($windowStart + $windowSeconds) - $now);

        return [
            'ok' => $ok,
            'remaining' => $remaining,
            'retry_after' => $ok ? 0 : $retryAfter,
            'count' => $count,
        ];
    }

    public function rememberReplayNonce(string $channel, string $nonce, int $ttlSeconds = 86400): bool
    {
        $channel = trim(strtolower($channel));
        $nonce = trim($nonce);
        if ($channel === '' || $nonce === '') {
            return false;
        }

        $now = time();
        $expiresAt = $now + max(60, $ttlSeconds);
        $this->purgeExpiredReplays($now);

        try {
            $insert = $this->db->prepare('INSERT INTO webhook_replay_guard (channel, nonce, created_at, expires_at)
                VALUES (:channel, :nonce, :created_at, :expires_at)');
            $insert->execute([
                ':channel' => $channel,
                ':nonce' => $nonce,
                ':created_at' => $now,
                ':expires_at' => $expiresAt,
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function purgeExpiredReplays(int $now): void
    {
        $delete = $this->db->prepare('DELETE FROM webhook_replay_guard WHERE expires_at <= :now');
        $delete->execute([':now' => $now]);
    }

    private function ensureSchema(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS api_rate_limits (
            bucket_key TEXT PRIMARY KEY,
            window_start INTEGER NOT NULL,
            request_count INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS webhook_replay_guard (
            channel TEXT NOT NULL,
            nonce TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            PRIMARY KEY (channel, nonce)
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_webhook_replay_expires_at ON webhook_replay_guard(expires_at)');
    }

    private function defaultPath(): string
    {
        $envPath = trim((string) (getenv('SECURITY_STATE_DB_PATH') ?: ''));
        if ($envPath !== '') {
            return $envPath;
        }
        return PROJECT_ROOT . '/storage/security/security_state.sqlite';
    }
}

