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

        if (isset($filters['is_electronic'])) {
            $qb->where('is_electronic', '=', (int) $filters['is_electronic']);
        }
        if (!empty($filters['doc_type'])) {
            $qb->where('doc_type', '=', $filters['doc_type']);
        }
        if (!empty($filters['fecha_from'])) {
            $qb->where('fecha', '>=', $filters['fecha_from']);
        }
        if (!empty($filters['fecha_to'])) {
            $qb->where('fecha', '<=', $filters['fecha_to']);
        }

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
                    is_electronic INTEGER NOT NULL DEFAULT 0,
                    cufe TEXT NOT NULL DEFAULT '',
                    doc_type TEXT NOT NULL DEFAULT 'manual',
                    created_at DATETIME,
                    updated_at DATETIME
                );
                CREATE INDEX IF NOT EXISTS idx_journal_tenant ON {$journalTable}(tenant_id, fecha);
                CREATE INDEX IF NOT EXISTS idx_journal_electronic ON {$journalTable}(tenant_id, is_electronic, fecha);

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
            
            // Seed PUC colombiano básico si la tabla está vacía
            // Basado en Decreto 2649/93 y estructura PUC Colombia (NIIF para PYMES)
            $count = (int) $this->db->query("SELECT COUNT(*) FROM {$accountTable}")->fetchColumn();
            if ($count === 0) {
                $pucSeed = [
                    // codigo, nombre, tipo, naturaleza
                    // CLASE 1 — ACTIVOS
                    ['1',    'ACTIVOS',                                     'ACTIVO',     'DEBITO'],
                    ['11',   'Efectivo y equivalentes de efectivo',          'ACTIVO',     'DEBITO'],
                    ['1105', 'Caja general',                                 'ACTIVO',     'DEBITO'],
                    ['1110', 'Bancos nacionales',                            'ACTIVO',     'DEBITO'],
                    ['1115', 'Bancos del exterior',                          'ACTIVO',     'DEBITO'],
                    ['1120', 'Cuentas de ahorro',                            'ACTIVO',     'DEBITO'],
                    ['13',   'Deudores',                                     'ACTIVO',     'DEBITO'],
                    ['1305', 'Clientes nacionales',                          'ACTIVO',     'DEBITO'],
                    ['1310', 'Clientes del exterior',                        'ACTIVO',     'DEBITO'],
                    ['1355', 'Anticipo de impuestos',                        'ACTIVO',     'DEBITO'],
                    ['1360', 'Retención en la fuente (anticipo)',            'ACTIVO',     'DEBITO'],
                    ['1365', 'IVA descontable',                              'ACTIVO',     'DEBITO'],
                    ['14',   'Inventarios',                                  'ACTIVO',     'DEBITO'],
                    ['1405', 'Materias primas',                              'ACTIVO',     'DEBITO'],
                    ['1430', 'Productos terminados',                         'ACTIVO',     'DEBITO'],
                    ['1435', 'Mercancías no fabricadas por la empresa',      'ACTIVO',     'DEBITO'],
                    ['15',   'Propiedad, planta y equipo',                   'ACTIVO',     'DEBITO'],
                    ['1504', 'Terrenos',                                     'ACTIVO',     'DEBITO'],
                    ['1516', 'Construcciones y edificaciones',               'ACTIVO',     'DEBITO'],
                    ['1524', 'Equipo de oficina',                            'ACTIVO',     'DEBITO'],
                    ['1528', 'Equipo de computación y comunicación',         'ACTIVO',     'DEBITO'],
                    ['1592', 'Depreciación acumulada (CR)',                  'ACTIVO',     'CREDITO'],
                    // CLASE 2 — PASIVOS
                    ['2',    'PASIVOS',                                      'PASIVO',     'CREDITO'],
                    ['21',   'Obligaciones financieras',                     'PASIVO',     'CREDITO'],
                    ['2105', 'Bancos nacionales (crédito)',                  'PASIVO',     'CREDITO'],
                    ['22',   'Proveedores',                                  'PASIVO',     'CREDITO'],
                    ['2205', 'Proveedores nacionales',                       'PASIVO',     'CREDITO'],
                    ['2210', 'Proveedores del exterior',                     'PASIVO',     'CREDITO'],
                    ['23',   'Cuentas por pagar',                            'PASIVO',     'CREDITO'],
                    ['2335', 'Costos y gastos por pagar',                    'PASIVO',     'CREDITO'],
                    ['2365', 'Retención en la fuente por pagar',             'PASIVO',     'CREDITO'],
                    ['2367', 'Retención ICA por pagar',                      'PASIVO',     'CREDITO'],
                    ['2368', 'Retención IVA por pagar',                      'PASIVO',     'CREDITO'],
                    ['24',   'Impuestos gravámenes y tasas',                 'PASIVO',     'CREDITO'],
                    ['2404', 'Impuesto de renta por pagar',                  'PASIVO',     'CREDITO'],
                    ['2408', 'IVA generado por pagar',                       'PASIVO',     'CREDITO'],
                    ['2412', 'ICA por pagar',                                'PASIVO',     'CREDITO'],
                    ['25',   'Obligaciones laborales',                       'PASIVO',     'CREDITO'],
                    ['2505', 'Salarios por pagar',                           'PASIVO',     'CREDITO'],
                    ['2510', 'Cesantías consolidadas',                       'PASIVO',     'CREDITO'],
                    ['2515', 'Intereses sobre cesantías',                    'PASIVO',     'CREDITO'],
                    ['2520', 'Prima de servicios',                           'PASIVO',     'CREDITO'],
                    ['2525', 'Vacaciones consolidadas',                      'PASIVO',     'CREDITO'],
                    // CLASE 3 — PATRIMONIO
                    ['3',    'PATRIMONIO',                                   'PATRIMONIO', 'CREDITO'],
                    ['31',   'Capital social',                               'PATRIMONIO', 'CREDITO'],
                    ['3105', 'Capital suscrito y pagado',                    'PATRIMONIO', 'CREDITO'],
                    ['33',   'Reservas',                                     'PATRIMONIO', 'CREDITO'],
                    ['3305', 'Reserva legal',                                'PATRIMONIO', 'CREDITO'],
                    ['3310', 'Reserva estatutaria',                          'PATRIMONIO', 'CREDITO'],
                    ['36',   'Resultados del ejercicio',                     'PATRIMONIO', 'CREDITO'],
                    ['3605', 'Utilidad del ejercicio',                       'PATRIMONIO', 'CREDITO'],
                    ['3610', 'Pérdida del ejercicio',                        'PATRIMONIO', 'DEBITO'],
                    // CLASE 4 — INGRESOS
                    ['4',    'INGRESOS',                                     'INGRESO',    'CREDITO'],
                    ['41',   'Ingresos operacionales',                       'INGRESO',    'CREDITO'],
                    ['4135', 'Comercio al por mayor y al por menor',         'INGRESO',    'CREDITO'],
                    ['4145', 'Servicios',                                    'INGRESO',    'CREDITO'],
                    ['42',   'Ingresos no operacionales',                    'INGRESO',    'CREDITO'],
                    ['4210', 'Financieros (intereses recibidos)',             'INGRESO',    'CREDITO'],
                    ['4220', 'Arrendamientos recibidos',                     'INGRESO',    'CREDITO'],
                    ['4245', 'Utilidad en venta de activos',                 'INGRESO',    'CREDITO'],
                    // CLASE 5 — GASTOS
                    ['5',    'GASTOS',                                       'GASTO',      'DEBITO'],
                    ['51',   'Gastos de administración',                     'GASTO',      'DEBITO'],
                    ['5105', 'Gastos de personal (administración)',           'GASTO',      'DEBITO'],
                    ['5110', 'Honorarios',                                   'GASTO',      'DEBITO'],
                    ['5115', 'Impuestos y tasas (gasto)',                    'GASTO',      'DEBITO'],
                    ['5120', 'Arrendamientos (gasto)',                       'GASTO',      'DEBITO'],
                    ['5135', 'Servicios públicos',                           'GASTO',      'DEBITO'],
                    ['5145', 'Mantenimiento y reparaciones',                 'GASTO',      'DEBITO'],
                    ['5160', 'Depreciaciones',                               'GASTO',      'DEBITO'],
                    ['5195', 'Otros gastos de administración',               'GASTO',      'DEBITO'],
                    ['52',   'Gastos de ventas',                             'GASTO',      'DEBITO'],
                    ['5205', 'Gastos de personal (ventas)',                  'GASTO',      'DEBITO'],
                    ['5225', 'Publicidad y propaganda',                      'GASTO',      'DEBITO'],
                    ['53',   'Gastos financieros',                           'GASTO',      'DEBITO'],
                    ['5305', 'Gastos bancarios',                             'GASTO',      'DEBITO'],
                    ['5310', 'Intereses de créditos',                        'GASTO',      'DEBITO'],
                    // CLASE 6 — COSTOS DE VENTAS
                    ['6',    'COSTOS DE VENTAS',                             'COSTO',      'DEBITO'],
                    ['61',   'Costo de ventas y de prestación de servicios', 'COSTO',      'DEBITO'],
                    ['6135', 'Mercancías vendidas',                          'COSTO',      'DEBITO'],
                    ['6145', 'Servicios prestados (costo)',                  'COSTO',      'DEBITO'],
                    // CLASE 7 — COSTOS DE PRODUCCIÓN
                    ['7',    'COSTOS DE PRODUCCIÓN O DE OPERACIÓN',          'COSTO',      'DEBITO'],
                    ['71',   'Materia prima y materiales',                   'COSTO',      'DEBITO'],
                    ['7105', 'Materias primas utilizadas',                   'COSTO',      'DEBITO'],
                    ['72',   'Mano de obra directa',                         'COSTO',      'DEBITO'],
                    ['7205', 'Sueldos y salarios (producción)',              'COSTO',      'DEBITO'],
                ];

                $stmt = $this->db->prepare(
                    "INSERT INTO {$accountTable} (tenant_id, codigo, nombre, tipo, naturaleza) VALUES (?, ?, ?, ?, ?)"
                );
                foreach ($pucSeed as $row) {
                    $stmt->execute(['default', $row[0], $row[1], $row[2], $row[3]]);
                }
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
                    is_electronic TINYINT(1) NOT NULL DEFAULT 0,
                    cufe VARCHAR(200) NOT NULL DEFAULT '',
                    doc_type VARCHAR(64) NOT NULL DEFAULT 'manual',
                    created_at DATETIME,
                    updated_at DATETIME,
                    INDEX idx_tenant (tenant_id),
                    INDEX idx_fecha (fecha),
                    INDEX idx_electronic (tenant_id, is_electronic, fecha)
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
