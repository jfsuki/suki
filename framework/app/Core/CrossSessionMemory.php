<?php
declare(strict_types=1);

// app/Core/CrossSessionMemory.php

namespace App\Core;

/**
 * CrossSessionMemory
 *
 * Loads the N most-recent chat turns from `chat_log` for a given user
 * and formats them as a context block for injection into the system prompt.
 *
 * The table `chat_log` schema (verified):
 *   id, tenant_id, user_id, session_id, channel, direction, message, meta_json, created_at
 *
 * Tenant isolation is mandatory: every query filters by tenant_id.
 * When user_id is provided it further narrows results to that user's history.
 */
final class CrossSessionMemory
{
    private const DEFAULT_TURNS = 6;
    private const MAX_CHARS_PER_MSG = 300;

    private \PDO $pdo;
    private bool $userIdColumnExists;

    public function __construct()
    {
        $this->pdo = Database::connection();
        $this->userIdColumnExists = $this->checkUserIdColumn();
    }

    /**
     * Build a concise context block of recent exchanges across sessions.
     * Returns '' when there is nothing relevant to show.
     *
     * @param string $userId   Can be '' — falls back to tenant-wide history
     * @param int    $maxTurns Number of exchange pairs to include
     */
    public function buildHistoryContextBlock(
        string $tenantId,
        string $userId = '',
        int $maxTurns = self::DEFAULT_TURNS
    ): string {
        $rows = $this->loadRecentRows($tenantId, $userId, $maxTurns * 2);
        if (empty($rows)) {
            return '';
        }

        $lines = ['HISTORIAL RECIENTE (conversaciones anteriores):'];
        foreach ($rows as $row) {
            $dirLabel = ($row['direction'] ?? '') === 'in' ? 'Usuario' : 'SUKI';
            $snippet  = mb_substr(trim((string) ($row['message'] ?? '')), 0, self::MAX_CHARS_PER_MSG);
            if ($snippet === '') {
                continue;
            }
            $lines[] = "  [{$dirLabel}] {$snippet}";
        }

        if (count($lines) <= 1) {
            return '';
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function loadRecentRows(string $tenantId, string $userId, int $limit): array
    {
        $limit = max(1, min(50, $limit));

        if ($userId !== '' && $this->userIdColumnExists) {
            $stmt = $this->pdo->prepare(
                'SELECT direction, message, created_at
                 FROM chat_log
                 WHERE tenant_id = ? AND user_id = ?
                 ORDER BY created_at DESC
                 LIMIT ' . $limit
            );
            $stmt->execute([$tenantId, $userId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT direction, message, created_at
                 FROM chat_log
                 WHERE tenant_id = ?
                 ORDER BY created_at DESC
                 LIMIT ' . $limit
            );
            $stmt->execute([$tenantId]);
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        // Reverse so messages are in chronological order in the context block
        return array_reverse(is_array($rows) ? $rows : []);
    }

    private function checkUserIdColumn(): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM chat_log LIKE 'user_id'");
            return (bool) $stmt->fetch();
        } catch (\Throwable $ignored) {
            return false;
        }
    }
}
