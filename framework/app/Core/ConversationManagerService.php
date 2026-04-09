<?php

namespace App\Core;

use App\Core\ProjectRegistry;
use RuntimeException;

/**
 * ConversationManagerService manages the lifecycle of chat subjects (conversations).
 * It handles session multiplexing, automatic title generation, and history recovery.
 */
class ConversationManagerService
{
    private ProjectRegistry $registry;

    public function __construct(?ProjectRegistry $registry = null)
    {
        $this->registry = $registry ?: new ProjectRegistry();
    }

    /**
     * Starts a new conversation subject for a user.
     */
    public function startNewSubject(string $userId, string $projectId, string $tenantId, string $channel, string $title = 'Nueva Conversación'): string
    {
        if ($userId === '') {
            throw new RuntimeException("No se puede crear una conversación para un usuario anónimo.");
        }

        $sessionId = 'sess_' . bin2hex(random_bytes(8));
        $this->registry->touchSession($sessionId, $userId, $projectId, $tenantId, $channel);
        $this->registry->updateSessionTitle($sessionId, $title);
        
        return $sessionId;
    }

    /**
     * Lists recent conversations for a user.
     */
    public function getMyHistory(string $userId, int $limit = 15): array
    {
        return $this->registry->listUserSessions($userId, $limit);
    }

    /**
     * Generates an automatic title based on the first significant message.
     */
    public function autoGenerateTitle(string $sessionId, string $firstMessage): void
    {
        $text = trim($firstMessage);
        if ($text === '') return;

        // Simple extraction logic: First 5-7 words or 40 characters
        $words = explode(' ', $text);
        $title = implode(' ', array_slice($words, 0, 6));
        
        if (strlen($title) > 40) {
            $title = mb_substr($title, 0, 37) . '...';
        }

        // Only update if current title is default
        $session = $this->registry->getSession($sessionId);
        if ($session && empty($session['title'])) {
            $this->registry->updateSessionTitle($sessionId, $title);
        }
    }

    /**
     * Renames a conversation subject.
     */
    public function renameSubject(string $sessionId, string $newTitle): bool
    {
        return $this->registry->updateSessionTitle($sessionId, $newTitle);
    }

    /**
     * Archives a conversation.
     */
    public function archiveSubject(string $sessionId): bool
    {
        $stmt = $this->registry->db()->prepare('UPDATE chat_sessions SET is_archived = 1 WHERE session_id = :id');
        return $stmt->execute([':id' => $sessionId]);
    }
}
