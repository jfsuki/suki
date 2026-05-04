<?php
// framework/app/Core/AccountingRepository.php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * AccountingRepository
 *
 * Tablas manejadas:
 *   puc_nacional               — Catálogo PUC nacional (read-only, compartido entre tenants)
 *   cuentas_contables          — Catálogo activado por tenant + auxiliares propios (7+ dígitos)
 *   parametros_contables_tenant — Mapeo rol semántico → cuenta real del tenant
 *   formas_pago_config         — Medio de pago → rol contable del tenant
 *   asientos_contables         — Libro diario
 *   asiento_lineas             — Líneas de asiento (partida doble)
 *
 * LEY ABSOLUTA (canon § 12.5):
 *   Nunca buscar cuentas por código PUC hardcodeado.
 *   Usar siempre findAccountByRole($tenantId, $rol).
 */
final class AccountingRepository
{
    private const PUC_NACIONAL_TABLE   = 'puc_nacional';
    private const ACCOUNT_TABLE        = 'cuentas_contables';
    private const ROLES_TABLE          = 'parametros_contables_tenant';
    private const PAYMENT_TABLE        = 'formas_pago_config';
    private const JOURNAL_TABLE        = 'asientos_contables';
    private const JOURNAL_LINE_TABLE   = 'asiento_lineas';

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
                self::JOURNAL_TABLE => ['idx_journal_tenant', 'idx_journal_date'],
            ]
        );
    }

    // ─── Catálogo Nacional PUC (read-only) ───────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function listPucNacional(?string $nivel = null): array
    {
        $table = TableNamespace::resolve(self::PUC_NACIONAL_TABLE);
        $sql = "SELECT * FROM {$table}";
        if ($nivel !== null) {
            $stmt = $this->db->prepare($sql . " WHERE nivel = ? ORDER BY codigo ASC");
            $stmt->execute([$nivel]);
        } else {
            $stmt = $this->db->prepare($sql . " ORDER BY codigo ASC");
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findPucNacionalByCodigo(string $codigo): ?array
    {
        $table = TableNamespace::resolve(self::PUC_NACIONAL_TABLE);
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE codigo = ? LIMIT 1");
        $stmt->execute([$codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ─── Catálogo Activo del Tenant (cuentas_contables) ─────────────────────

    /** @return array<int, array<string, mixed>> */
    public function listAccounts(string $tenantId, bool $soloActivas = true): array
    {
        $table = TableNamespace::resolve(self::ACCOUNT_TABLE);
        $sql = "SELECT * FROM {$table} WHERE tenant_id = ?";
        if ($soloActivas) {
            $sql .= " AND activa = 1";
        }
        $sql .= " ORDER BY codigo ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findAccountByCodigo(string $tenantId, string $codigo): ?array
    {
        $table = TableNamespace::resolve(self::ACCOUNT_TABLE);
        $stmt = $this->db->prepare(
            "SELECT * FROM {$table} WHERE tenant_id = ? AND codigo = ? LIMIT 1"
        );
        $stmt->execute([$tenantId, $codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function activateAccount(string $tenantId, string $codigo): bool
    {
        $puc = $this->findPucNacionalByCodigo($codigo);
        if ($puc === null) {
            return false;
        }
        $table = TableNamespace::resolve(self::ACCOUNT_TABLE);
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $orIgnore = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmt = $this->db->prepare(
            "{$orIgnore} INTO {$table}
             (tenant_id, codigo, nombre, tipo, naturaleza, parent_codigo, nivel, es_auxiliar, activa)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)"
        );
        $stmt->execute([
            $tenantId,
            $puc['codigo'],
            $puc['nombre'],
            $puc['tipo'],
            $puc['naturaleza'],
            $puc['parent'] ?? null,
            $puc['nivel'],
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Crea una cuenta auxiliar propia del tenant (dígito 7+) */
    public function createAuxiliary(string $tenantId, string $codigo, string $nombre, string $parentCodigo): string
    {
        $parent = $this->findAccountByCodigo($tenantId, $parentCodigo);
        if ($parent === null) {
            throw new RuntimeException("Cuenta padre '{$parentCodigo}' no está activada para tenant '{$tenantId}'.");
        }
        if (strlen($codigo) < 7) {
            throw new RuntimeException("Auxiliares deben tener 7 o más dígitos. Recibido: '{$codigo}'.");
        }
        $table = TableNamespace::resolve(self::ACCOUNT_TABLE);
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $orIgnore = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmt = $this->db->prepare(
            "{$orIgnore} INTO {$table}
             (tenant_id, codigo, nombre, tipo, naturaleza, parent_codigo, nivel, es_auxiliar, activa)
             VALUES (?, ?, ?, ?, ?, ?, 'auxiliar', 1, 1)"
        );
        $stmt->execute([
            $tenantId, $codigo, $nombre,
            $parent['tipo'], $parent['naturaleza'], $parentCodigo,
        ]);
        return $codigo;
    }

    // ─── Roles Semánticos → Cuenta Real del Tenant ───────────────────────────

    /**
     * Resuelve el ID de la cuenta contable configurada para un rol semántico.
     * Esta es la función que DEBE usar AccountingService — nunca hardcodear códigos.
     */
    public function findAccountByRole(string $tenantId, string $rol): ?int
    {
        $rolesTable = TableNamespace::resolve(self::ROLES_TABLE);
        $accountTable = TableNamespace::resolve(self::ACCOUNT_TABLE);

        $stmt = $this->db->prepare(
            "SELECT cc.id
             FROM {$rolesTable} r
             JOIN {$accountTable} cc ON cc.tenant_id = r.tenant_id AND cc.codigo = r.codigo_cuenta
             WHERE r.tenant_id = ? AND r.rol = ? AND cc.activa = 1
             LIMIT 1"
        );
        $stmt->execute([$tenantId, $rol]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** @return array<string, mixed>|null */
    public function getRoleConfig(string $tenantId, string $rol): ?array
    {
        $table = TableNamespace::resolve(self::ROLES_TABLE);
        $stmt = $this->db->prepare(
            "SELECT * FROM {$table} WHERE tenant_id = ? AND rol = ? LIMIT 1"
        );
        $stmt->execute([$tenantId, $rol]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listRoles(string $tenantId): array
    {
        $table = TableNamespace::resolve(self::ROLES_TABLE);
        $stmt = $this->db->prepare(
            "SELECT * FROM {$table} WHERE tenant_id = ? ORDER BY rol ASC"
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function setRole(string $tenantId, string $rol, string $codigoCuenta, string $descripcion = ''): void
    {
        $table = TableNamespace::resolve(self::ROLES_TABLE);
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = date('Y-m-d H:i:s');

        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare(
                "INSERT INTO {$table} (tenant_id, rol, codigo_cuenta, descripcion, updated_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(tenant_id, rol) DO UPDATE SET
                   codigo_cuenta = excluded.codigo_cuenta,
                   descripcion   = excluded.descripcion,
                   updated_at    = excluded.updated_at"
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO {$table} (tenant_id, rol, codigo_cuenta, descripcion, updated_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   codigo_cuenta = VALUES(codigo_cuenta),
                   descripcion   = VALUES(descripcion),
                   updated_at    = VALUES(updated_at)"
            );
        }
        $stmt->execute([$tenantId, $rol, $codigoCuenta, $descripcion, $now]);
    }

    /**
     * Siembra los roles por defecto para un nuevo tenant.
     * Los defaults vienen de framework/data/accounting_roles_defaults.json.
     * El tenant puede modificarlos luego desde la UI.
     */
    public function seedDefaultRolesForTenant(string $tenantId): int
    {
        $jsonPath = __DIR__ . '/../../data/accounting_roles_defaults.json';
        if (!file_exists($jsonPath)) {
            throw new RuntimeException("Accounting roles defaults file not found: {$jsonPath}");
        }
        $data = json_decode((string) file_get_contents($jsonPath), true);
        $roles = is_array($data['roles'] ?? null) ? (array) $data['roles'] : [];
        $table = TableNamespace::resolve(self::ROLES_TABLE);
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = date('Y-m-d H:i:s');
        $orIgnore = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        $stmt = $this->db->prepare(
            "{$orIgnore} INTO {$table} (tenant_id, rol, codigo_cuenta, descripcion, updated_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $inserted = 0;
        foreach ($roles as $role) {
            if (!isset($role['rol'], $role['codigo_default'])) {
                continue;
            }
            $stmt->execute([
                $tenantId,
                $role['rol'],
                $role['codigo_default'],
                $role['descripcion'] ?? '',
                $now,
            ]);
            $inserted += $stmt->rowCount();
        }

        // Activar automáticamente las cuentas default en el catálogo del tenant
        foreach ($roles as $role) {
            if (!isset($role['codigo_default'])) {
                continue;
            }
            $this->activateAccount($tenantId, $role['codigo_default']);
        }

        return $inserted;
    }

    // ─── Medios de Pago → Rol Contable ───────────────────────────────────────

    public function findRolByMedioPago(string $tenantId, string $medioPago): ?string
    {
        $table = TableNamespace::resolve(self::PAYMENT_TABLE);
        $stmt = $this->db->prepare(
            "SELECT rol_contable FROM {$table} WHERE tenant_id = ? AND medio_pago = ? LIMIT 1"
        );
        $stmt->execute([$tenantId, $medioPago]);
        $rol = $stmt->fetchColumn();
        return $rol !== false ? (string) $rol : null;
    }

    public function setMedioPago(string $tenantId, string $medioPago, string $rolContable, string $nombre = ''): void
    {
        $table = TableNamespace::resolve(self::PAYMENT_TABLE);
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = date('Y-m-d H:i:s');

        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare(
                "INSERT INTO {$table} (tenant_id, medio_pago, rol_contable, nombre, updated_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(tenant_id, medio_pago) DO UPDATE SET
                   rol_contable = excluded.rol_contable,
                   nombre       = excluded.nombre,
                   updated_at   = excluded.updated_at"
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO {$table} (tenant_id, medio_pago, rol_contable, nombre, updated_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   rol_contable = VALUES(rol_contable),
                   nombre       = VALUES(nombre),
                   updated_at   = VALUES(updated_at)"
            );
        }
        $stmt->execute([$tenantId, $medioPago, $rolContable, $nombre, $now]);
    }

    // ─── Libro Diario ────────────────────────────────────────────────────────

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
                $line['tenant_id']  = $header['tenant_id'];
                $line['created_at'] = $header['created_at'];
                QueryBuilder::table($this->db, self::JOURNAL_LINE_TABLE)->insert($line);
            }

            $this->db->commit();
            return $journalId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> */
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

    // ─── Schema ──────────────────────────────────────────────────────────────

    private function ensureSchema(): void
    {
        $driver       = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pucTable     = TableNamespace::resolve(self::PUC_NACIONAL_TABLE);
        $accountTable = TableNamespace::resolve(self::ACCOUNT_TABLE);
        $rolesTable   = TableNamespace::resolve(self::ROLES_TABLE);
        $paymentTable = TableNamespace::resolve(self::PAYMENT_TABLE);
        $journalTable = TableNamespace::resolve(self::JOURNAL_TABLE);
        $lineTable    = TableNamespace::resolve(self::JOURNAL_LINE_TABLE);

        if ($driver === 'sqlite') {
            // Catálogo nacional PUC (read-only, compartido)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$pucTable} (
                    id        INTEGER PRIMARY KEY AUTOINCREMENT,
                    codigo    TEXT NOT NULL UNIQUE,
                    nombre    TEXT NOT NULL,
                    tipo      TEXT NOT NULL,
                    naturaleza TEXT NOT NULL,
                    nivel     TEXT NOT NULL,
                    parent    TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_puc_codigo ON {$pucTable}(codigo);
                CREATE INDEX IF NOT EXISTS idx_puc_nivel  ON {$pucTable}(nivel);
            ");

            // Catálogo activo del tenant + auxiliares propios
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$accountTable} (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id    TEXT NOT NULL,
                    codigo       TEXT NOT NULL,
                    nombre       TEXT NOT NULL,
                    tipo         TEXT NOT NULL,
                    naturaleza   TEXT NOT NULL,
                    parent_codigo TEXT,
                    nivel        TEXT NOT NULL DEFAULT 'cuenta',
                    es_auxiliar  INTEGER NOT NULL DEFAULT 0,
                    activa       INTEGER NOT NULL DEFAULT 1,
                    saldo_actual DECIMAL(15,2) DEFAULT 0,
                    created_at   DATETIME,
                    UNIQUE(tenant_id, codigo)
                );
                CREATE INDEX IF NOT EXISTS idx_accounts_tenant ON {$accountTable}(tenant_id, activa);
                CREATE INDEX IF NOT EXISTS idx_accounts_code   ON {$accountTable}(tenant_id, codigo);
            ");

            // Roles semánticos → cuenta real del tenant
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$rolesTable} (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id    TEXT NOT NULL,
                    rol          TEXT NOT NULL,
                    codigo_cuenta TEXT NOT NULL,
                    descripcion  TEXT DEFAULT '',
                    updated_at   DATETIME,
                    UNIQUE(tenant_id, rol)
                );
                CREATE INDEX IF NOT EXISTS idx_roles_tenant ON {$rolesTable}(tenant_id, rol);
            ");

            // Medios de pago → rol contable del tenant
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$paymentTable} (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id   TEXT NOT NULL,
                    medio_pago  TEXT NOT NULL,
                    rol_contable TEXT NOT NULL,
                    nombre      TEXT DEFAULT '',
                    updated_at  DATETIME,
                    UNIQUE(tenant_id, medio_pago)
                );
                CREATE INDEX IF NOT EXISTS idx_payment_tenant ON {$paymentTable}(tenant_id, medio_pago);
            ");

            // Libro diario
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$journalTable} (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id    TEXT NOT NULL,
                    fecha        DATE NOT NULL,
                    referencia   TEXT,
                    glosa        TEXT,
                    total_debe   DECIMAL(15,2) DEFAULT 0,
                    total_haber  DECIMAL(15,2) DEFAULT 0,
                    estado       TEXT DEFAULT 'BORRADOR',
                    usuario_id   TEXT NOT NULL,
                    is_electronic INTEGER NOT NULL DEFAULT 0,
                    cufe         TEXT NOT NULL DEFAULT '',
                    doc_type     TEXT NOT NULL DEFAULT 'manual',
                    created_at   DATETIME,
                    updated_at   DATETIME
                );
                CREATE INDEX IF NOT EXISTS idx_journal_tenant     ON {$journalTable}(tenant_id, fecha);
                CREATE INDEX IF NOT EXISTS idx_journal_electronic ON {$journalTable}(tenant_id, is_electronic, fecha);

                CREATE TABLE IF NOT EXISTS {$lineTable} (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    asiento_id INTEGER NOT NULL,
                    tenant_id  TEXT NOT NULL,
                    cuenta_id  INTEGER NOT NULL,
                    debe       DECIMAL(15,2) DEFAULT 0,
                    haber      DECIMAL(15,2) DEFAULT 0,
                    glosa_linea TEXT,
                    created_at DATETIME
                );
                CREATE INDEX IF NOT EXISTS idx_lines_tenant ON {$lineTable}(tenant_id, asiento_id);
            ");

            // Poblar puc_nacional desde JSON si está vacío
            $count = (int) $this->db->query("SELECT COUNT(*) FROM {$pucTable}")->fetchColumn();
            if ($count === 0) {
                $this->loadPucNacionalFromJson();
            }

        } else {
            // MySQL
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS {$pucTable} (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    codigo     VARCHAR(16) NOT NULL UNIQUE,
                    nombre     VARCHAR(255) NOT NULL,
                    tipo       VARCHAR(50) NOT NULL,
                    naturaleza ENUM('DEBITO','CREDITO') NOT NULL,
                    nivel      ENUM('clase','grupo','cuenta','subcuenta') NOT NULL,
                    parent     VARCHAR(16) NULL,
                    INDEX idx_puc_codigo (codigo),
                    INDEX idx_puc_nivel  (nivel)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS {$accountTable} (
                    id            INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id     VARCHAR(120) NOT NULL,
                    codigo        VARCHAR(20) NOT NULL,
                    nombre        VARCHAR(255) NOT NULL,
                    tipo          VARCHAR(50) NOT NULL,
                    naturaleza    ENUM('DEBITO','CREDITO') NOT NULL,
                    parent_codigo VARCHAR(20) NULL,
                    nivel         VARCHAR(20) NOT NULL DEFAULT 'cuenta',
                    es_auxiliar   TINYINT(1) NOT NULL DEFAULT 0,
                    activa        TINYINT(1) NOT NULL DEFAULT 1,
                    saldo_actual  DECIMAL(15,2) DEFAULT 0,
                    created_at    DATETIME,
                    UNIQUE KEY uq_tenant_codigo (tenant_id, codigo),
                    INDEX idx_accounts_tenant (tenant_id, activa),
                    INDEX idx_accounts_code   (tenant_id, codigo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS {$rolesTable} (
                    id            INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id     VARCHAR(120) NOT NULL,
                    rol           VARCHAR(80) NOT NULL,
                    codigo_cuenta VARCHAR(20) NOT NULL,
                    descripcion   TEXT,
                    updated_at    DATETIME,
                    UNIQUE KEY uq_tenant_rol (tenant_id, rol),
                    INDEX idx_roles_tenant (tenant_id, rol)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS {$paymentTable} (
                    id           INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id    VARCHAR(120) NOT NULL,
                    medio_pago   VARCHAR(80) NOT NULL,
                    rol_contable VARCHAR(80) NOT NULL,
                    nombre       VARCHAR(120) DEFAULT '',
                    updated_at   DATETIME,
                    UNIQUE KEY uq_tenant_medio (tenant_id, medio_pago),
                    INDEX idx_payment_tenant (tenant_id, medio_pago)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS {$journalTable} (
                    id           INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id    VARCHAR(50) NOT NULL,
                    fecha        DATE NOT NULL,
                    referencia   VARCHAR(255),
                    glosa        TEXT,
                    total_debe   DECIMAL(15,2) DEFAULT 0,
                    total_haber  DECIMAL(15,2) DEFAULT 0,
                    estado       ENUM('BORRADOR','CONTABILIZADO','ANULADO') DEFAULT 'BORRADOR',
                    usuario_id   VARCHAR(50) NOT NULL,
                    is_electronic TINYINT(1) NOT NULL DEFAULT 0,
                    cufe         VARCHAR(200) NOT NULL DEFAULT '',
                    doc_type     VARCHAR(64) NOT NULL DEFAULT 'manual',
                    created_at   DATETIME,
                    updated_at   DATETIME,
                    INDEX idx_journal_tenant     (tenant_id, fecha),
                    INDEX idx_journal_electronic (tenant_id, is_electronic, fecha)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                CREATE TABLE IF NOT EXISTS {$lineTable} (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    asiento_id INT NOT NULL,
                    tenant_id  VARCHAR(50) NOT NULL,
                    cuenta_id  INT NOT NULL,
                    debe       DECIMAL(15,2) DEFAULT 0,
                    haber      DECIMAL(15,2) DEFAULT 0,
                    glosa_linea TEXT,
                    created_at DATETIME,
                    INDEX idx_lines_tenant (tenant_id, asiento_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            $count = (int) $this->db->query("SELECT COUNT(*) FROM {$pucTable}")->fetchColumn();
            if ($count === 0) {
                $this->loadPucNacionalFromJson();
            }
        }
    }

    private function loadPucNacionalFromJson(): void
    {
        $jsonPath = __DIR__ . '/../../data/puc_colombia_base.json';
        if (!file_exists($jsonPath)) {
            return;
        }
        $data = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            return;
        }
        $table = TableNamespace::resolve(self::PUC_NACIONAL_TABLE);
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $orIgnore = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmt = $this->db->prepare(
            "{$orIgnore} INTO {$table} (codigo, nombre, tipo, naturaleza, nivel, parent)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($data as $row) {
            if (!isset($row['codigo'], $row['nombre'], $row['tipo'], $row['naturaleza'], $row['nivel'])) {
                continue;
            }
            $stmt->execute([
                $row['codigo'],
                $row['nombre'],
                $row['tipo'],
                $row['naturaleza'],
                $row['nivel'],
                $row['parent'] ?? null,
            ]);
        }
    }
}
