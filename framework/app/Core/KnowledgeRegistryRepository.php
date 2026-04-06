<?php
// framework/app/Core/KnowledgeRegistryRepository.php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

class KnowledgeRegistryRepository
{
    private ?PDO $db = null;
    private string $dbPath;

    public function __construct(?string $dbPath = null)
    {
        $this->dbPath = $dbPath ?: PROJECT_ROOT . '/storage/meta/knowledge_catalog.sqlite';
    }

    private function connect(): PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        if (!is_file($this->dbPath)) {
            throw new RuntimeException("Knowledge catalog not found at: " . $this->dbPath);
        }

        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $this->db;
    }

    public function getAllNodes(): array
    {
        $stmt = $this->connect()->query("SELECT * FROM knowledge_nodes ORDER BY sector_key, domain, node_name");
        return $stmt->fetchAll();
    }

    public function getNodesBySector(string $sectorKey): array
    {
        $stmt = $this->connect()->prepare("SELECT * FROM knowledge_nodes WHERE sector_key = ? ORDER BY node_type, node_name");
        $stmt->execute([$sectorKey]);
        return $stmt->fetchAll();
    }

    public function getNodesGroupedBySector(): array
    {
        $nodes = $this->getAllNodes();
        $grouped = [];
        foreach ($nodes as $n) {
            $grouped[$n['sector_key']][] = $n;
        }
        return $grouped;
    }

    public function getSummaryByMaturity(): array
    {
        $stmt = $this->connect()->query("
            SELECT 
                sector_key as domain, 
                AVG(maturity) as avg_maturity, 
                COUNT(*) as node_count,
                SUM(CASE WHEN status = 'GAP' THEN 1 ELSE 0 END) as gap_count
            FROM knowledge_nodes 
            GROUP BY sector_key
        ");
        return $stmt->fetchAll();
    }

    public function getUserMemoryNodes(int $userId): array
    {
        try {
            $stmt = $this->connect()->prepare("SELECT * FROM user_memory_nodes WHERE user_id = ? ORDER BY updated_at DESC");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function updateMaturity(int $nodeId, int $maturity, string $status = 'TRAINED'): bool
    {
        $stmt = $this->connect()->prepare("
            UPDATE knowledge_nodes 
            SET maturity = ?, status = ?, last_trained = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        return $stmt->execute([$maturity, $status, $nodeId]);
    }
}
