<?php
// app/Core/MigrationStore.php

namespace App\Core;

use PDO;

class MigrationStore
{
    private PDO $db;
    private string $table = 'schema_migrations';

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function ensureTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id VARCHAR(191) PRIMARY KEY,
            checksum VARCHAR(64) NULL,
            applied_at DATETIME NOT NULL
        )";
        $this->db->exec($sql);
    }

    public function has(string $id): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    public function getChecksum(string $id): ?string
    {
        $stmt = $this->db->prepare("SELECT checksum FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return $value !== false ? (string) $value : null;
    }

    public function add(string $id, ?string $checksum = null): void
    {
        $stmt = $this->db->prepare("INSERT INTO {$this->table} (id, checksum, applied_at) VALUES (:id, :checksum, :applied_at)");
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':checksum', $checksum);
        $stmt->bindValue(':applied_at', date('Y-m-d H:i:s'));
        $stmt->execute();
    }

    public function upsert(string $id, ?string $checksum = null): void
    {
        if ($this->has($id)) {
            $stmt = $this->db->prepare("UPDATE {$this->table} SET checksum = :checksum, applied_at = :applied_at WHERE id = :id");
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':checksum', $checksum);
            $stmt->bindValue(':applied_at', date('Y-m-d H:i:s'));
            $stmt->execute();
            return;
        }

        $this->add($id, $checksum);
    }

    public function all(): array
    {
        $stmt = $this->db->query("SELECT id, checksum, applied_at FROM {$this->table} ORDER BY applied_at ASC");
        return $stmt->fetchAll();
    }
}
