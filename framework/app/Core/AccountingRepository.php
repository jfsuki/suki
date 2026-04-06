<?php
// framework/app/Core/AccountingRepository.php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AccountingRepository
{
    private const ACCOUNT_TABLE = 'cuentas_contables';
    private const JOURNAL_TABLE = 'asientos_contables';
    private const JOURNAL_LINE_TABLE = 'asiento_lineas';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'AccountingRepository',
            fn() => $this->ensureSchema(),
            [self::ACCOUNT_TABLE, self::JOURNAL_TABLE, self::JOURNAL_LINE_TABLE],
            [
                self::ACCOUNT_TABLE => ['idx_accounts_tenant', 'idx_accounts_code'],
                self::JOURNAL_TABLE => ['idx_journal_tenant', 'idx_journal_date']
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(string $tenantId): array
    {
        return QueryBuilder::table($this->db, self::ACCOUNT_TABLE)
            ->where('tenant_id', '=', $tenantId)
            ->orderBy('codigo', 'ASC')
            ->get();
    }

    public function createJournalEntry(array $header, array $lines): string
    {
        $this->db->beginTransaction();
        try {
            $header['created_at'] = date('Y-m-d H:i:s');
            $header['updated_at'] = $header['created_at'];
            
            $journalId = (string) QueryBuilder::table($this->db, self::JOURNAL_TABLE)
                ->insert($header);

            foreach ($lines as $line) {
                $line['asiento_id'] = $journalId;
                $line['tenant_id'] = $header['tenant_id'];
                $line['created_at'] = $header['created_at'];
                
                QueryBuilder::table($this->db, self::JOURNAL_LINE_TABLE)
                    ->insert($line);
            }

            $this->db->commit();
            return $journalId;
        } catch (RuntimeException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listJournalEntries(string $tenantId, array $filters = [], int $limit = 50): array
    {
        $qb = QueryBuilder::table($this->db, self::JOURNAL_TABLE)
            ->where('tenant_id', '=', $tenantId)
            ->orderBy('fecha', 'DESC')
            ->limit(max(1, min(200, $limit)));

        return $qb->get();
    }

    private function ensureSchema(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $accountTable = TableNamespace::resolve(self::ACCOUNT_TABLE);
        $journalTable = TableNamespace::resolve(self::JOURNAL_TABLE);
        $lineTable = TableNamespace::resolve(self::JOURNAL_LINE_TABLE);
        
        if ($driver === 'sqlite') {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$accountTable} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id TEXT NOT NULL,
                    codigo TEXT NOT NULL,
                    nombre TEXT NOT NULL,
                    tipo TEXT NOT NULL,
                    naturaleza TEXT NOT NULL,
                    saldo_actual DECIMAL(15,2) DEFAULT 0,
                    created_at DATETIME
                );
                CREATE INDEX IF NOT EXISTS idx_accounts_tenant ON {$accountTable}(tenant_id);
                CREATE INDEX IF NOT EXISTS idx_accounts_code ON {$accountTable}(codigo);

                CREATE TABLE IF NOT EXISTS {$journalTable} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id TEXT NOT NULL,
                    fecha DATE NOT NULL,
                    referencia TEXT,
                    glosa TEXT,
                    total_debe DECIMAL(15,2) DEFAULT 0,
                    total_haber DECIMAL(15,2) DEFAULT 0,
                    estado TEXT DEFAULT 'BORRADOR',
                    usuario_id TEXT NOT NULL,
                    created_at DATETIME,
                    updated_at DATETIME
                );

                CREATE TABLE IF NOT EXISTS {$lineTable} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    asiento_id INTEGER NOT NULL,
                    tenant_id TEXT NOT NULL,
                    cuenta_id INTEGER NOT NULL,
                    debe DECIMAL(15,2) DEFAULT 0,
                    haber DECIMAL(15,2) DEFAULT 0,
                    glosa_linea TEXT,
                    created_at DATETIME
                );
            ");
            
            // Seed basic accounts if empty
            $count = (int) $this->db->query("SELECT COUNT(*) FROM {$accountTable}")->fetchColumn();
            if ($count === 0) {
                // Simplified seed
                $this->db->exec("
                    INSERT INTO {$accountTable} (tenant_id, codigo, nombre, tipo, naturaleza) VALUES 
                    ('default', '1105', 'Caja General', 'ACTIVO', 'DEBITO'),
                    ('default', '4135', 'Ventas de productos', 'INGRESO', 'CREDITO'),
                    ('default', '6135', 'Costo de ventas', 'COSTO', 'DEBITO'),
                    ('default', '1435', 'Inventarios', 'ACTIVO', 'DEBITO');
                ");
            }
        } else {
            // MySQL version
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$accountTable} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id VARCHAR(50) NOT NULL,
                    codigo VARCHAR(20) NOT NULL,
                    nombre VARCHAR(255) NOT NULL,
                    tipo VARCHAR(50) NOT NULL,
                    naturaleza ENUM('DEBITO', 'CREDITO') NOT NULL,
                    saldo_actual DECIMAL(15,2) DEFAULT 0,
                    created_at DATETIME,
                    INDEX idx_tenant (tenant_id),
                    INDEX idx_codigo (codigo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS {$journalTable} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id VARCHAR(50) NOT NULL,
                    fecha DATE NOT NULL,
                    referencia VARCHAR(255),
                    glosa TEXT,
                    total_debe DECIMAL(15,2) DEFAULT 0,
                    total_haber DECIMAL(15,2) DEFAULT 0,
                    estado ENUM('BORRADOR', 'CONTABILIZADO', 'ANULADO') DEFAULT 'BORRADOR',
                    usuario_id VARCHAR(50) NOT NULL,
                    created_at DATETIME,
                    updated_at DATETIME,
                    INDEX idx_tenant (tenant_id),
                    INDEX idx_fecha (fecha)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS {$lineTable} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    asiento_id INT NOT NULL,
                    tenant_id VARCHAR(50) NOT NULL,
                    cuenta_id INT NOT NULL,
                    debe DECIMAL(15,2) DEFAULT 0,
                    haber DECIMAL(15,2) DEFAULT 0,
                    glosa_linea TEXT,
                    created_at DATETIME
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
    }
}
