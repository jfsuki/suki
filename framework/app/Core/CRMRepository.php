<?php
// framework/app/Core/CRMRepository.php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class CRMRepository
{
    private const CUSTOMER_TABLE = 'clientes';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'CRMRepository',
            fn() => $this->ensureSchema(),
            [self::CUSTOMER_TABLE],
            [self::CUSTOMER_TABLE => ['idx_clientes_tenant', 'idx_clientes_status']]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCustomer(string $tenantId, string $id): ?array
    {
        return QueryBuilder::table($this->db, self::CUSTOMER_TABLE)
            ->where('tenant_id', '=', $tenantId)
            ->where('id', '=', $id)
            ->first();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listCustomers(string $tenantId, array $filters = [], int $limit = 50): array
    {
        $qb = QueryBuilder::table($this->db, self::CUSTOMER_TABLE)
            ->where('tenant_id', '=', $tenantId)
            ->limit(max(1, min(200, $limit)));

        if (!empty($filters['nombre'])) {
            $qb->where('nombre', 'LIKE', '%' . $filters['nombre'] . '%');
        }
        if (!empty($filters['status'])) {
            $qb->where('status', '=', strtoupper($filters['status']));
        }
        if (!empty($filters['empresa'])) {
            $qb->where('empresa', 'LIKE', '%' . $filters['empresa'] . '%');
        }

        return $qb->get();
    }

    public function createCustomer(array $data): string
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        
        return (string) QueryBuilder::table($this->db, self::CUSTOMER_TABLE)
            ->insert($data);
    }

    public function updateCustomer(string $tenantId, string $id, array $updates): bool
    {
        $updates['updated_at'] = date('Y-m-d H:i:s');
        
        return QueryBuilder::table($this->db, self::CUSTOMER_TABLE)
            ->where('tenant_id', '=', $tenantId)
            ->where('id', '=', $id)
            ->update($updates) > 0;
    }

    public function deleteCustomer(string $tenantId, string $id): bool
    {
        return QueryBuilder::table($this->db, self::CUSTOMER_TABLE)
            ->where('tenant_id', '=', $tenantId)
            ->where('id', '=', $id)
            ->delete() > 0;
    }

    private function ensureSchema(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS " . self::CUSTOMER_TABLE . " (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id TEXT NOT NULL,
                    nombre TEXT NOT NULL,
                    empresa TEXT,
                    email TEXT,
                    telefono TEXT,
                    calificacion INTEGER DEFAULT 3,
                    status TEXT DEFAULT 'LEAD',
                    created_at DATETIME,
                    updated_at DATETIME,
                    deleted_at DATETIME
                );
                CREATE INDEX IF NOT EXISTS idx_clientes_tenant ON " . self::CUSTOMER_TABLE . "(tenant_id);
                CREATE INDEX IF NOT EXISTS idx_clientes_status ON " . self::CUSTOMER_TABLE . "(status);
            ");
        } else {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS " . self::CUSTOMER_TABLE . " (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id VARCHAR(50) NOT NULL,
                    nombre VARCHAR(255) NOT NULL,
                    empresa VARCHAR(255),
                    email VARCHAR(255),
                    telefono VARCHAR(50),
                    calificacion INT DEFAULT 3,
                    status ENUM('LEAD', 'PROSPECTO', 'CLIENTE', 'INACTIVO') DEFAULT 'LEAD',
                    created_at DATETIME,
                    updated_at DATETIME,
                    deleted_at DATETIME,
                    INDEX idx_tenant (tenant_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
    }
}
