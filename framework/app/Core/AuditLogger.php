<?php
// app/Core/AuditLogger.php

namespace App\Core;

use PDO;

final class AuditLogger
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function log(string $action, string $entity, $recordId, array $payload = []): void
    {
        $this->ensureTable();
        $actorId = $this->getActorId();
        $actorLabel = $this->getActorLabel();
        $tenantId = TenantContext::getTenantId();

        $sql = 'INSERT INTO audit_log (action, entity, record_id, tenant_id, actor_id, actor_label, payload, created_at) VALUES (:action, :entity, :record_id, :tenant_id, :actor_id, :actor_label, :payload, :created_at)';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':entity', $entity);
        $stmt->bindValue(':record_id', $recordId !== null ? (string) $recordId : null);
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':actor_id', $actorId);
        $stmt->bindValue(':actor_label', $actorLabel);
        $stmt->bindValue(':payload', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'));
        $stmt->execute();
    }

    private function ensureTable(): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $payloadType = $driver === 'sqlite' ? 'TEXT' : 'JSON';
        $sql = "CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action VARCHAR(32) NOT NULL,
            entity VARCHAR(128) NOT NULL,
            record_id VARCHAR(64) NULL,
            tenant_id INT NULL,
            actor_id VARCHAR(64) NULL,
            actor_label VARCHAR(128) NULL,
            payload {$payloadType} NULL,
            created_at DATETIME NOT NULL
        )";
        if ($driver === 'mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(32) NOT NULL,
                entity VARCHAR(128) NOT NULL,
                record_id VARCHAR(64) NULL,
                tenant_id INT NULL,
                actor_id VARCHAR(64) NULL,
                actor_label VARCHAR(128) NULL,
                payload {$payloadType} NULL,
                created_at DATETIME NOT NULL
            )";
        }
        $this->db->exec($sql);
    }

    private function getActorId(): ?string
    {
        $ctx = RoleContext::getUserId();
        if ($ctx !== null) {
            return $ctx;
        }
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
            return (string) $_SESSION['user_id'];
        }
        return null;
    }

    private function getActorLabel(): ?string
    {
        $ctx = RoleContext::getUserLabel();
        if ($ctx !== null) {
            return $ctx;
        }
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_name'])) {
            return (string) $_SESSION['user_name'];
        }
        return null;
    }
}
