<?php
// framework/app/Core/InventoryRepository.php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class InventoryRepository
{
    private const PRODUCT_TABLE = 'productos';
    private const KARDEX_TABLE = 'kardex';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        
        // Auto-bootstrap schema if missing (dev mode)
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'InventoryRepository',
            fn() => $this->ensureSchema(),
            [self::PRODUCT_TABLE, self::KARDEX_TABLE],
            [
                self::PRODUCT_TABLE => ['idx_productos_sku', 'idx_productos_tenant'],
                self::KARDEX_TABLE => ['idx_kardex_producto', 'idx_kardex_tenant']
            ],
            [
                self::PRODUCT_TABLE => ['id', 'tenant_id', 'sku', 'nombre', 'precio_venta', 'stock_actual'],
                self::KARDEX_TABLE => ['id', 'tenant_id', 'producto_id', 'tipo', 'cantidad']
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findProduct(string $tenantId, string $idOrSku): ?array
    {
        $productsTable = TableNamespace::resolve(self::PRODUCT_TABLE);
        $qb = QueryBuilder::table($this->db, $productsTable)
            ->where('tenant_id', '=', $tenantId);
        
        if (is_numeric($idOrSku)) {
            $qb->where('id', '=', $idOrSku);
        } else {
            $qb->where('sku', '=', $idOrSku);
        }

        $row = $qb->first();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listProducts(string $tenantId, array $filters = [], int $limit = 50): array
    {
        $qb = $this->productQuery($tenantId)
            ->limit(max(1, min(200, $limit)));

        if (!empty($filters['sku'])) {
            $qb->where('sku', 'LIKE', '%' . $filters['sku'] . '%');
        }
        if (!empty($filters['nombre'])) {
            $qb->where('nombre', 'LIKE', '%' . $filters['nombre'] . '%');
        }
        if (!empty($filters['categoria'])) {
            $qb->where('categoria', '=', $filters['categoria']);
        }
        if (isset($filters['low_stock']) && $filters['low_stock'] === true) {
            $qb->whereRaw('stock_actual <= stock_minimo');
        }

        return $qb->get();
    }

    /**
     * @param array<string, mixed> $data
     * @return string ID of the created product
     */
    public function createProduct(array $data): string
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        
        return (string) $this->insertRecord(self::PRODUCT_TABLE, array_keys($data), $data);
    }

    /**
     * @param array<string, mixed> $updates
     */
    public function updateProduct(string $tenantId, string $productId, array $updates): bool
    {
        $updates['updated_at'] = date('Y-m-d H:i:s');
        
        return $this->productQuery($tenantId)
            ->where('id', '=', $productId)
            ->update($updates) > 0;
    }

    /**
     * Records a movement and updates product stock atomically.
     * @param array<string, mixed> $movement
     */
    public function recordMovement(array $movement): string
    {
        $this->db->beginTransaction();
        try {
            $tenantId = (string) ($movement['tenant_id'] ?? '');
            $productId = (string) ($movement['producto_id'] ?? '');
            $cantidad = (float) ($movement['cantidad'] ?? 0);
            $tipo = strtoupper((string) ($movement['tipo'] ?? ''));

            // 1. Insert Kardex record
            $movement['created_at'] = date('Y-m-d H:i:s');
            $kardexId = (string) $this->insertRecord(self::KARDEX_TABLE, array_keys($movement), $movement);

            // 2. Calculate stock change
            $multiplier = in_array($tipo, ['ENTRADA', 'DEVOLUCION']) ? 1 : -1;
            $delta = $cantidad * $multiplier;

            // 3. Update Product stock
            $productsTable = TableNamespace::resolve(self::PRODUCT_TABLE);
            $this->db->prepare("
                UPDATE {$productsTable}
                SET stock_actual = stock_actual + :delta, updated_at = :now 
                WHERE id = :id AND tenant_id = :tenant
            ")->execute([
                ':delta' => $delta,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $productId,
                ':tenant' => $tenantId
            ]);

            $this->db->commit();
            return $kardexId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function productQuery(string $tenantId): QueryBuilder
    {
        $productsTable = TableNamespace::resolve(self::PRODUCT_TABLE);
        return QueryBuilder::table($this->db, $productsTable)
            ->where('tenant_id', '=', $tenantId);
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, mixed> $values
     */
    private function insertRecord(string $table, array $columns, array $values): string
    {
        $physicalTable = TableNamespace::resolve($table);
        return (string) QueryBuilder::table($this->db, $physicalTable)
            ->insert($values);
    }

    private function ensureSchema(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $productsTable = TableNamespace::resolve(self::PRODUCT_TABLE);
        $kardexTable = TableNamespace::resolve(self::KARDEX_TABLE);
        
        if ($driver === 'sqlite') {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$productsTable} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id TEXT NOT NULL,
                    sku TEXT NOT NULL,
                    nombre TEXT NOT NULL,
                    descripcion TEXT,
                    precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0,
                    stock_actual DECIMAL(10,2) NOT NULL DEFAULT 0,
                    stock_minimo DECIMAL(10,2) NOT NULL DEFAULT 5,
                    categoria TEXT,
                    created_at DATETIME,
                    updated_at DATETIME,
                    deleted_at DATETIME
                );
                CREATE INDEX IF NOT EXISTS idx_productos_sku ON {$productsTable}(sku);
                CREATE INDEX IF NOT EXISTS idx_productos_tenant ON {$productsTable}(tenant_id);

                CREATE TABLE IF NOT EXISTS {$kardexTable} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id TEXT NOT NULL,
                    producto_id INTEGER NOT NULL,
                    tipo TEXT NOT NULL,
                    cantidad DECIMAL(10,2) NOT NULL,
                    costo_unitario DECIMAL(10,2),
                    referencia_externa TEXT,
                    motivo TEXT,
                    usuario_id TEXT NOT NULL,
                    created_at DATETIME,
                    updated_at DATETIME
                );
                CREATE INDEX IF NOT EXISTS idx_kardex_producto ON {$kardexTable}(producto_id);
                CREATE INDEX IF NOT EXISTS idx_kardex_tenant ON {$kardexTable}(tenant_id);
            ");
        } else {
            // MySQL/MariaDB version
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$productsTable} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id VARCHAR(50) NOT NULL,
                    sku VARCHAR(100) NOT NULL,
                    nombre VARCHAR(255) NOT NULL,
                    descripcion TEXT,
                    precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0,
                    stock_actual DECIMAL(10,2) NOT NULL DEFAULT 0,
                    stock_minimo DECIMAL(10,2) NOT NULL DEFAULT 5,
                    categoria VARCHAR(100),
                    created_at DATETIME,
                    updated_at DATETIME,
                    deleted_at DATETIME,
                    INDEX idx_sku (sku),
                    INDEX idx_tenant (tenant_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS {$kardexTable} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id VARCHAR(50) NOT NULL,
                    producto_id INT NOT NULL,
                    tipo ENUM('ENTRADA', 'SALIDA', 'AJUSTE', 'DEVOLUCION') NOT NULL,
                    cantidad DECIMAL(10,2) NOT NULL,
                    costo_unitario DECIMAL(10,2),
                    referencia_externa VARCHAR(255),
                    motivo TEXT,
                    usuario_id VARCHAR(50) NOT NULL,
                    created_at DATETIME,
                    updated_at DATETIME,
                    INDEX idx_prod (producto_id),
                    INDEX idx_tenant (tenant_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
    }
}
