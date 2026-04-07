<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Servicio de Contabilidad — versión ampliada con distinción FE / No-FE.
 *
 * REGLA FISCAL COLOMBIA:
 * ─────────────────────
 * - Factura Electrónica (FE): status='accepted' en fiscal_documents (validada por DIAN/Alanube).
 *   → Se registra en el libro fiscal oficial (asientos con is_electronic=1).
 *   → El CUFE es evidencia de la operación ante la DIAN.
 *
 * - Factura No Electrónica (No-FE): remisiones, ventas POS sin FE, cotizaciones, etc.
 *   → Se registra en libro auxiliar (is_electronic=0).
 *   → No tiene CUFE ni validez ante la DIAN como soporte fiscal.
 *   → Para efectos tributarios, estas ventas deben eventualmente emitir FE.
 *
 * El agente debe informar al usuario esta distinción cuando procesa ventas:
 *   "Esta venta se registró como No-FE. Para deducirla tributariamente emite la factura electrónica."
 */
final class AccountingService
{
    private AccountingRepository $repository;

    public function __construct(?AccountingRepository $repository = null)
    {
        $this->repository = $repository ?? new AccountingRepository();
    }

    // ─── Libro Fiscal (FE) ────────────────────────────────────────────────────

    /**
     * Registra venta de Factura Electrónica VALIDADA por la DIAN.
     * Solo llamar cuando fiscal_document.status = 'accepted' (respuesta Alanube).
     *
     * @param array<string, mixed> $fiscalDoc  — array completo del fiscal_document
     * @return array<string, mixed>
     */
    public function recordElectronicSale(string $tenantId, array $fiscalDoc, string $userId): array
    {
        $status = strtolower((string)($fiscalDoc['status'] ?? ''));
        if ($status !== 'accepted') {
            throw new RuntimeException(
                'ACCOUNTING_FE_NOT_ACCEPTED — Solo facturas aceptadas por Alanube/DIAN pueden contabilizarse como FE. '
                . "Estado actual: {$status}"
            );
        }

        $cufe   = (string) ($fiscalDoc['cufe'] ?? $fiscalDoc['metadata']['cufe'] ?? '');
        $docNum = (string) ($fiscalDoc['document_number'] ?? $fiscalDoc['id'] ?? '');
        $total  = (float)  ($fiscalDoc['total'] ?? 0);
        $taxAmt = (float)  ($fiscalDoc['tax_total'] ?? 0);
        $base   = $total - $taxAmt;

        $lines = [
            $this->line(1, $base,   0, "FE Venta Base - Ref {$docNum}"),  // Débito Caja/Cartera
            $this->line(2, $total,  0, "FE Ingreso Bruto - CUFE {$cufe}"), // ya se usa en línea 3
        ];

        // Partida doble correcta:
        // Débito: Caja/Clientes (1105/1305) por el TOTAL
        // Crédito: Ventas (4135) por BASE
        // Crédito: IVA por Pagar (2408) por IVA
        $lines = [
            ['cuenta_id' => 1,  'debe' => $total, 'haber' => 0,      'glosa_linea' => "FE Cobro - {$docNum}"],
            ['cuenta_id' => 2,  'debe' => 0,       'haber' => $base,  'glosa_linea' => "FE Venta - {$docNum}"],
            ['cuenta_id' => 99, 'debe' => 0,       'haber' => $taxAmt,'glosa_linea' => "FE IVA por pagar - {$docNum}"],
        ];

        // Ajustar si no hay IVA
        if ($taxAmt <= 0) {
            $lines = [
                ['cuenta_id' => 1, 'debe' => $total, 'haber' => 0,    'glosa_linea' => "FE - {$docNum}"],
                ['cuenta_id' => 2, 'debe' => 0,       'haber' => $total,'glosa_linea' => "FE Venta - {$docNum}"],
            ];
        }

        return $this->recordEntry($tenantId, [
            'fecha'        => $fiscalDoc['issue_date'] ?? date('Y-m-d'),
            'referencia'   => $docNum,
            'glosa'        => "Factura Electrónica {$docNum} — CUFE: {$cufe}",
            'is_electronic'=> 1,
            'cufe'         => $cufe,
            'doc_type'     => 'sales_invoice',
            'lines'        => $lines,
        ], $userId);
    }

    /**
     * Registra una venta NO electrónica (remisión, POS sin FE, cotización).
     * Va al LIBRO AUXILIAR — no es soporte fiscal ante la DIAN.
     *
     * @return array<string, mixed>
     */
    public function recordNonElectronicSale(string $tenantId, float $total, string $ref, string $docType, string $userId): array
    {
        $taxAmt  = $total * 0.19 / 1.19; // estima IVA incluido si existe; ajustar según contexto
        $base    = $total - $taxAmt;

        return $this->recordEntry($tenantId, [
            'fecha'        => date('Y-m-d'),
            'referencia'   => $ref,
            'glosa'        => "Venta No-FE ({$docType}) - Ref {$ref} — NOTA: No tiene validez fiscal ante DIAN",
            'is_electronic'=> 0,
            'cufe'         => '',
            'doc_type'     => $docType,
            'lines'        => [
                ['cuenta_id' => 1, 'debe' => $total, 'haber' => 0,      'glosa_linea' => "No-FE Cobro - {$ref}"],
                ['cuenta_id' => 2, 'debe' => 0,       'haber' => $total,  'glosa_linea' => "No-FE Venta - {$ref} (Libro Auxiliar)"],
            ],
        ], $userId);
    }

    // ─── API Original (compatibilidad) ────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function recordManualEntry(string $tenantId, array $data, string $userId): array
    {
        if (empty($data['lines']) || count($data['lines']) < 2) {
            throw new RuntimeException('Journal entry must have at least 2 lines (double entry bookkeeping).');
        }

        $totalDebe = 0.0;
        $totalHaber = 0.0;
        foreach ($data['lines'] as $line) {
            $totalDebe  += (float)($line['debe']  ?? 0);
            $totalHaber += (float)($line['haber'] ?? 0);
        }

        if (abs($totalDebe - $totalHaber) > 0.01) {
            throw new RuntimeException('Journal entry is not balanced (Debe vs Haber). Debe=' . $totalDebe . ' Haber=' . $totalHaber);
        }

        $header = [
            'tenant_id'     => $tenantId,
            'fecha'         => $data['fecha']      ?? date('Y-m-d'),
            'referencia'    => $data['referencia'] ?? '',
            'glosa'         => $data['glosa']      ?? '',
            'total_debe'    => $totalDebe,
            'total_haber'   => $totalHaber,
            'estado'        => 'CONTABILIZADO',
            'usuario_id'    => $userId,
            'is_electronic' => (int)($data['is_electronic'] ?? 0),
            'cufe'          => (string)($data['cufe'] ?? ''),
            'doc_type'      => (string)($data['doc_type'] ?? 'manual'),
        ];

        $id = $this->repository->createJournalEntry($header, $data['lines']);

        return [
            'id'            => $id,
            'status'        => 'SUCCESS',
            'total'         => $totalDebe,
            'is_electronic' => (bool)($data['is_electronic'] ?? false),
            'fiscal_note'   => (bool)($data['is_electronic'] ?? false)
                ? '✓ Asiento fiscal — Factura Electrónica validada por DIAN.'
                : '⚠ Asiento auxiliar — No tiene validez fiscal directa ante la DIAN.',
        ];
    }

    /**
     * Contabilización automática de venta (legacy — No-FE por defecto).
     * @return array<string, mixed>
     */
    public function recordSaleAccounting(string $tenantId, float $total, string $ref, string $userId): array
    {
        $lines = [
            ['cuenta_id' => 1, 'debe' => $total, 'haber' => 0,     'glosa_linea' => 'Venta Ref ' . $ref],
            ['cuenta_id' => 2, 'debe' => 0,       'haber' => $total, 'glosa_linea' => 'Venta Ref ' . $ref],
        ];

        return $this->recordManualEntry($tenantId, [
            'fecha'        => date('Y-m-d'),
            'referencia'   => $ref,
            'glosa'        => 'Contabilización automática de venta (No-FE)',
            'is_electronic'=> 0,
            'lines'        => $lines,
        ], $userId);
    }

    /** @return array<int, array<string, mixed>> */
    public function getBalanceSheet(string $tenantId): array
    {
        return $this->repository->listAccounts($tenantId);
    }

    /**
     * Lista asientos filtrando por tipo (FE / No-FE / todos).
     * @return array<int, array<string, mixed>>
     */
    public function listEntries(string $tenantId, string $filter = 'all', int $limit = 50): array
    {
        $filters = [];
        if ($filter === 'fe' || $filter === 'electronic') {
            $filters['is_electronic'] = 1;
        } elseif ($filter === 'no-fe' || $filter === 'non_electronic') {
            $filters['is_electronic'] = 0;
        }

        return $this->repository->listJournalEntries($tenantId, $filters, $limit);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function recordEntry(string $tenantId, array $data, string $userId): array
    {
        $lines = (array)($data['lines'] ?? []);
        $totalDebe  = array_sum(array_column($lines, 'debe'));
        $totalHaber = array_sum(array_column($lines, 'haber'));

        $header = [
            'tenant_id'     => $tenantId,
            'fecha'         => $data['fecha']      ?? date('Y-m-d'),
            'referencia'    => $data['referencia'] ?? '',
            'glosa'         => $data['glosa']      ?? '',
            'total_debe'    => $totalDebe,
            'total_haber'   => $totalHaber,
            'estado'        => 'CONTABILIZADO',
            'usuario_id'    => $userId,
            'is_electronic' => (int)($data['is_electronic'] ?? 0),
            'cufe'          => (string)($data['cufe'] ?? ''),
            'doc_type'      => (string)($data['doc_type'] ?? 'sale'),
        ];

        $id = $this->repository->createJournalEntry($header, $lines);

        return [
            'id'            => $id,
            'status'        => 'SUCCESS',
            'is_electronic' => (bool)($data['is_electronic'] ?? false),
            'total'         => $totalDebe,
            'fiscal_note'   => (bool)($data['is_electronic'] ?? false)
                ? '✓ Asiento fiscal — Factura Electrónica aceptada por DIAN.'
                : '⚠ Asiento en libro auxiliar — No tiene validez fiscal directa. Emite FE para soporte DIAN.',
        ];
    }

    /** @return array<string, mixed> */
    private function line(int $cuentaId, float $debe, float $haber, string $glosa): array
    {
        return ['cuenta_id' => $cuentaId, 'debe' => $debe, 'haber' => $haber, 'glosa_linea' => $glosa];
    }
}
