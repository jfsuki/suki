<?php
// framework/app/Core/SalesLeadsRepository.php

namespace App\Core;

use PDO;

/**
 * SalesLeadsRepository
 * Almacena interacciones de ventas y puntos de dolor capturados.
 */
class SalesLeadsRepository
{
    private PDO $db;
    private string $table = 'sales_leads';

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->ensureSchema();
    }

    /**
     * Registra una interacción de venta.
     */
    public function logLead(string $tenantId, string $userId, string $message, string $intent): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} (tenant_id, user_id, message, intent, created_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $tenantId,
            $userId,
            $message,
            $intent,
            date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Obtiene los leads recientes para la Torre de Control.
     */
    public function getRecentLeads(int $limit = 50): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ensureSchema(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                message TEXT NOT NULL,
                intent TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
        ");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sales_leads_tenant ON {$this->table} (tenant_id)");
    }
}
