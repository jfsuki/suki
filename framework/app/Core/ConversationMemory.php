<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * ConversationMemory
 * 
 * Persistent multi-turn history for SUKI chat agents.
 * Stores conversation threads in the project_registry.sqlite database.
 * Thread isolation is achieved via thread_id (tenant_id + session_id).
 */
class ConversationMemory
{
    private PDO $db;
    private int $limit;

    /**
     * @param PDO $db The database connection (usually from ProjectRegistry).
     * @param int $limit Maximum number of messages to keep in history per thread.
     */
    public function __construct(PDO $db, int $limit = 20)
    {
        $this->db = $db;
        $this->limit = $limit;
    }

    /**
     * Loads the conversation history for a specific thread.
     * 
     * @param string $threadId The unique identifier for the conversation thread.
     * @return array List of message capsules ['role' => ..., 'content' => ...].
     */
    public function load(string $threadId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT role, content 
                FROM conversation_memory 
                WHERE thread_id = :thread_id 
                ORDER BY id ASC 
                LIMIT :limit
            ");
            $stmt->bindValue(':thread_id', $threadId);
            $stmt->bindValue(':limit', $this->limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Log error but don't break the user flow
            error_log("ConversationMemory::load error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Appends a new message to the conversation history.
     * 
     * @param string $threadId The thread identifier.
     * @param string $role The role of the sender (user, assistant, system).
     * @param string $content The message content.
     */
    public function append(string $threadId, string $role, string $content): void
    {
        if (empty(trim($content))) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO conversation_memory (thread_id, role, content, created_at) 
                VALUES (:thread_id, :role, :content, :created_at)
            ");
            $stmt->execute([
                ':thread_id' => $threadId,
                ':role' => $role,
                ':content' => $content,
                ':created_at' => date('Y-m-d H:i:s')
            ]);

            $this->trim($threadId);
        } catch (\PDOException $e) {
            error_log("ConversationMemory::append error: " . $e->getMessage());
        }
    }

    /**
     * Clears all history for a specific thread.
     * 
     * @param string $threadId The thread identifier.
     */
    public function clear(string $threadId): void
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM conversation_memory WHERE thread_id = :thread_id");
            $stmt->execute([':thread_id' => $threadId]);
        } catch (\PDOException $e) {
            error_log("ConversationMemory::clear error: " . $e->getMessage());
        }
    }

    /**
     * Trims old messages from the thread if they exceed the limit.
     * 
     * @param string $threadId The thread identifier.
     */
    private function trim(string $threadId): void
    {
        try {
            // SQLite specific trick to delete everything except the last N rows for a specific criteria
            $stmt = $this->db->prepare("
                DELETE FROM conversation_memory 
                WHERE id IN (
                    SELECT id FROM conversation_memory 
                    WHERE thread_id = :thread_id 
                    ORDER BY id DESC 
                    LIMIT -1 OFFSET :limit
                )
            ");
            $stmt->bindValue(':thread_id', $threadId);
            $stmt->bindValue(':limit', $this->limit, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            error_log("ConversationMemory::trim error: " . $e->getMessage());
        }
    }
}
