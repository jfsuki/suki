<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Repositorio de Cotizaciones.
 *
 * Ciclo de vida: draft → sent → viewed → approved → invoiced|canceled
 * Las cotizaciones NO son documentos fiscales — son promesas comerciales.
 * La conversión a factura electrónica ocurre vía FiscalEngineService.
 * La conversión a remisión ocurre vía POSService.
 */
final class QuotationRepository
{
    private const QUOTE_TABLE = 'quotations';
    private const LINE_TABLE  = 'quotation_lines';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->ensureSchema();
    }

    // ─── Cotizaciones ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createQuotation(array $record): array
    {
        $now  = date('Y-m-d H:i:s');
        $num  = $this->generateNumber((string)($record['tenant_id'] ?? ''));
        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::QUOTE_TABLE . '
             (tenant_id, app_id, quotation_number, customer_id, customer_name, customer_email,
              customer_phone, status, currency, subtotal, tax_total, discount_total, total,
              valid_days, valid_until, notes, terms, created_by_user_id, metadata_json, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $record['tenant_id'] ?? '',
            $record['app_id'] ?? null,
            $num,
            $record['customer_id'] ?? null,
            $record['customer_name'] ?? '',
            $record['customer_email'] ?? null,
            $record['customer_phone'] ?? null,
            'draft',
            $record['currency'] ?? 'COP',
            (float)($record['subtotal'] ?? 0),
            (float)($record['tax_total'] ?? 0),
            (float)($record['discount_total'] ?? 0),
            (float)($record['total'] ?? 0),
            (int)($record['valid_days'] ?? 30),
            $record['valid_until'] ?? date('Y-m-d', strtotime('+30 days')),
            $record['notes'] ?? null,
            $record['terms'] ?? null,
            $record['created_by_user_id'] ?? null,
            json_encode($record['metadata'] ?? [], JSON_UNESCAPED_UNICODE) ?: '{}',
            $now,
            $now,
        ]);

        $id = (string) $this->db->lastInsertId();
        return $this->findQuotation((string)($record['tenant_id'] ?? ''), $id) ?? [];
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function updateQuotation(string $tenantId, string $id, array $updates): ?array
    {
        $allowed = ['customer_id','customer_name','customer_email','customer_phone','status',
                    'currency','subtotal','tax_total','discount_total','total','valid_days',
                    'valid_until','notes','terms','converted_to_type','converted_to_id','metadata_json'];
        $sets = [];
        $vals = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $updates)) {
                $sets[] = "{$col} = ?";
                $vals[] = $col === 'metadata_json'
                    ? (is_array($updates['metadata'] ?? null) ? (json_encode($updates['metadata'], JSON_UNESCAPED_UNICODE) ?: '{}') : $updates[$col])
                    : $updates[$col];
            }
        }
        if (array_key_exists('metadata', $updates) && !array_key_exists('metadata_json', $updates)) {
            $sets[] = 'metadata_json = ?';
            $vals[] = json_encode($updates['metadata'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        if (empty($sets)) {
            return $this->findQuotation($tenantId, $id);
        }
        $sets[] = 'updated_at = ?';
        $vals[] = date('Y-m-d H:i:s');
        $vals[] = $tenantId;
        $vals[] = $id;
        $this->db->prepare(
            'UPDATE ' . self::QUOTE_TABLE . ' SET ' . implode(', ', $sets) . ' WHERE tenant_id = ? AND id = ?'
        )->execute($vals);
        return $this->findQuotation($tenantId, $id);
    }

    /** @return array<string, mixed>|null */
    public function findQuotation(string $tenantId, string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::QUOTE_TABLE . ' WHERE tenant_id = ? AND id = ? LIMIT 1');
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['lines'] = $this->listLines($tenantId, $id);
        return $this->normalizeRow($row);
    }

    /** @return array<string, mixed>|null */
    public function findByNumber(string $tenantId, string $number): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::QUOTE_TABLE . ' WHERE tenant_id = ? AND quotation_number = ? LIMIT 1');
        $stmt->execute([$tenantId, $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['lines'] = $this->listLines($tenantId, (string)($row['id'] ?? ''));
        return $this->normalizeRow($row);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listQuotations(string $tenantId, array $filters = [], int $limit = 20): array
    {
        $where = ['tenant_id = ?'];
        $vals  = [$tenantId];

        foreach (['status', 'customer_id', 'customer_email', 'converted_to_type'] as $col) {
            if (isset($filters[$col]) && $filters[$col] !== '') {
                $where[] = "{$col} = ?";
                $vals[]  = $filters[$col];
            }
        }
        if (!empty($filters['search'])) {
            $where[] = '(customer_name LIKE ? OR quotation_number LIKE ? OR customer_email LIKE ?)';
            $s = '%' . $filters['search'] . '%';
            $vals[] = $s; $vals[] = $s; $vals[] = $s;
        }

        $sql  = 'SELECT * FROM ' . self::QUOTE_TABLE . ' WHERE ' . implode(' AND ', $where)
              . ' ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($vals);
        return array_map(fn($r) => $this->normalizeRow($r), (array)$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ─── Líneas ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function addLine(string $tenantId, string $quotationId, array $record): array
    {
        $now  = date('Y-m-d H:i:s');
        $qty  = (float)($record['quantity'] ?? $record['qty'] ?? 1);
        $up   = (float)($record['unit_price'] ?? $record['price'] ?? 0);
        $tax  = (float)($record['tax_rate'] ?? $record['iva_rate'] ?? 0);
        $disc = (float)($record['discount'] ?? 0);
        $lt   = round($qty * $up * (1 + $tax / 100) - $disc, 4);

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::LINE_TABLE . '
             (tenant_id, quotation_id, item_id, sku, description, quantity, unit_price,
              tax_rate, discount, line_total, sort_order, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $tenantId,
            $quotationId,
            $record['item_id'] ?? null,
            $record['sku'] ?? null,
            $record['description'] ?? $record['name'] ?? '',
            $qty,
            $up,
            $tax,
            $disc,
            $lt,
            (int)($record['sort_order'] ?? 0),
            $now,
        ]);

        $lineId = (string)$this->db->lastInsertId();
        $row = $this->db->prepare('SELECT * FROM ' . self::LINE_TABLE . ' WHERE id = ? LIMIT 1');
        $row->execute([$lineId]);
        return (array)($row->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    public function removeLine(string $tenantId, string $quotationId, string $lineId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM ' . self::LINE_TABLE . ' WHERE tenant_id = ? AND quotation_id = ? AND id = ?'
        );
        $stmt->execute([$tenantId, $quotationId, $lineId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLines(string $tenantId, string $quotationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . self::LINE_TABLE . ' WHERE tenant_id = ? AND quotation_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$tenantId, $quotationId]);
        return (array)$stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function generateNumber(string $tenantId): string
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . self::QUOTE_TABLE . ' WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $count = (int) $stmt->fetchColumn();
        return 'COT-' . str_pad((string)($count + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $row */
    private function normalizeRow(array $row): array
    {
        $meta = $row['metadata_json'] ?? '{}';
        $decoded = is_string($meta) ? (json_decode($meta, true) ?? []) : ($meta ?: []);
        $row['metadata'] = is_array($decoded) ? $decoded : [];
        unset($row['metadata_json']);
        foreach (['subtotal','tax_total','discount_total','total','valid_days'] as $n) {
            $row[$n] = is_numeric($row[$n] ?? null) ? (float)$row[$n] : 0.0;
        }
        $row['id']   = (string)($row['id'] ?? '');
        $row['lines'] = is_array($row['lines'] ?? null) ? (array)$row['lines'] : [];

        // Indicadores de estado de ciclo de vida
        $status = (string)($row['status'] ?? 'draft');
        $row['is_convertible']    = in_array($status, ['approved'], true);
        $row['is_editable']       = in_array($status, ['draft', 'sent'], true);
        $row['is_expired']        = $status === 'draft' && ($row['valid_until'] ?? '') !== '' && $row['valid_until'] < date('Y-m-d');
        $row['has_been_converted']= ($row['converted_to_id'] ?? '') !== '';

        return $row;
    }

    private function ensureSchema(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS ' . self::QUOTE_TABLE . ' (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id           TEXT NOT NULL,
                app_id              TEXT NULL,
                quotation_number    TEXT NOT NULL,
                customer_id         TEXT NULL,
                customer_name       TEXT NOT NULL DEFAULT \'\',
                customer_email      TEXT NULL,
                customer_phone      TEXT NULL,
                status              TEXT NOT NULL DEFAULT \'draft\',
                currency            TEXT NOT NULL DEFAULT \'COP\',
                subtotal            REAL NOT NULL DEFAULT 0,
                tax_total           REAL NOT NULL DEFAULT 0,
                discount_total      REAL NOT NULL DEFAULT 0,
                total               REAL NOT NULL DEFAULT 0,
                valid_days          INTEGER NOT NULL DEFAULT 30,
                valid_until         TEXT NOT NULL,
                notes               TEXT NULL,
                terms               TEXT NULL,
                created_by_user_id  TEXT NULL,
                converted_to_type   TEXT NULL,
                converted_to_id     TEXT NULL,
                email_sent_at       TEXT NULL,
                metadata_json       TEXT NOT NULL DEFAULT \'{}\',
                created_at          TEXT NOT NULL,
                updated_at          TEXT NOT NULL
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_quotations_tenant_status ON ' . self::QUOTE_TABLE . ' (tenant_id, status, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_quotations_tenant_customer ON ' . self::QUOTE_TABLE . ' (tenant_id, customer_id, created_at)');
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_quotations_tenant_num ON ' . self::QUOTE_TABLE . ' (tenant_id, quotation_number)');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS ' . self::LINE_TABLE . ' (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id       TEXT NOT NULL,
                quotation_id    TEXT NOT NULL,
                item_id         TEXT NULL,
                sku             TEXT NULL,
                description     TEXT NOT NULL DEFAULT \'\',
                quantity        REAL NOT NULL DEFAULT 1,
                unit_price      REAL NOT NULL DEFAULT 0,
                tax_rate        REAL NOT NULL DEFAULT 0,
                discount        REAL NOT NULL DEFAULT 0,
                line_total      REAL NOT NULL DEFAULT 0,
                sort_order      INTEGER NOT NULL DEFAULT 0,
                created_at      TEXT NOT NULL
            )
        ');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_quotation_lines_quotation ON ' . self::LINE_TABLE . ' (tenant_id, quotation_id, sort_order)');
    }
}
