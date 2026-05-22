<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use App\Core\DocumentType;

/**
 * AccountingService — Contabilidad parametrizada por tenant.
 *
 * REGLA ABSOLUTA (canon § 12.5):
 *   Nunca hardcodear códigos PUC. Toda cuenta se resuelve vía
 *   $this->resolveByRole($tenantId, $rol) → parametros_contables_tenant.
 *   El código PUC lo decide el contador de la empresa, no el sistema.
 *
 * REGLA FISCAL COLOMBIA:
 *   - Factura Electrónica (FE, status='accepted'): libro fiscal oficial (is_electronic=1).
 *   - No-FE (remisiones, POS sin FE): libro auxiliar (is_electronic=0).
 *   El agente informa siempre esta distinción al usuario.
 */
final class AccountingService
{
    private const DEFAULT_MEDIO_PAGO = 'efectivo';
    private const BALANCE_TOLERANCE  = 0.001;

    private AccountingRepository $repository;

    public function __construct(?AccountingRepository $repository = null)
    {
        $this->repository = $repository ?? new AccountingRepository();
    }

    // ─── Libro Fiscal (FE) ────────────────────────────────────────────────────

    /**
     * Registra venta de Factura Electrónica VALIDADA por la DIAN.
     * Solo llamar cuando fiscal_document.status = 'accepted'.
     * El medio de cobro se pasa como $medioPago para resolver la cuenta de recaudo.
     *
     * @param array<string, mixed> $fiscalDoc
     * @return array<string, mixed>
     */
    public function recordElectronicSale(
        string $tenantId,
        array  $fiscalDoc,
        string $userId,
        string $medioPago = self::DEFAULT_MEDIO_PAGO
    ): array {
        $status = strtolower((string) ($fiscalDoc['status'] ?? ''));
        if ($status !== 'accepted') {
            throw new RuntimeException(
                'ACCOUNTING_FE_NOT_ACCEPTED — Solo facturas aceptadas por Alanube/DIAN pueden '
                . "contabilizarse como FE. Estado actual: {$status}"
            );
        }

        $cufe   = (string) ($fiscalDoc['cufe'] ?? $fiscalDoc['metadata']['cufe'] ?? '');
        $docNum = (string) ($fiscalDoc['document_number'] ?? $fiscalDoc['id'] ?? '');
        $total  = (float)  ($fiscalDoc['total'] ?? 0);
        $taxAmt = (float)  ($fiscalDoc['tax_total'] ?? 0);
        $base   = $total - $taxAmt;

        // Resolver cuentas desde parametros del tenant (nunca hardcodeadas)
        $rolRecaudo = $this->rolFromMedioPago($tenantId, $medioPago);
        $recaudo    = $this->resolveByRole($tenantId, $rolRecaudo);
        $ingresos   = $this->resolveByRole($tenantId, 'ingresos_ventas');
        $ivaVentas  = $this->resolveByRole($tenantId, 'iva_ventas');

        $lines = $taxAmt > 0
            ? [
                ['cuenta_id' => $recaudo,  'debe' => $total,   'haber' => 0,       'glosa_linea' => "FE Cobro - {$docNum}"],
                ['cuenta_id' => $ingresos, 'debe' => 0,         'haber' => $base,   'glosa_linea' => "FE Venta - {$docNum}"],
                ['cuenta_id' => $ivaVentas,'debe' => 0,         'haber' => $taxAmt, 'glosa_linea' => "FE IVA - {$docNum}"],
            ]
            : [
                ['cuenta_id' => $recaudo,  'debe' => $total,   'haber' => 0,     'glosa_linea' => "FE - {$docNum}"],
                ['cuenta_id' => $ingresos, 'debe' => 0,         'haber' => $total,'glosa_linea' => "FE Venta - {$docNum}"],
            ];

        return $this->recordEntry($tenantId, [
            'fecha'         => $fiscalDoc['issue_date'] ?? date('Y-m-d'),
            'referencia'    => $docNum,
            'glosa'         => "Factura Electrónica {$docNum} — CUFE: {$cufe}",
            'is_electronic' => 1,
            'cufe'          => $cufe,
            'doc_type'      => DocumentType::SALES_INVOICE,
            'lines'         => $lines,
        ], $userId);
    }

    /**
     * Registra una venta NO electrónica (remisión, POS sin FE, cotización).
     * Libro AUXILIAR — no tiene validez fiscal ante la DIAN.
     *
     * @return array<string, mixed>
     */
    public function recordNonElectronicSale(
        string $tenantId,
        float  $total,
        string $ref,
        string $docType,
        string $userId,
        string $medioPago = self::DEFAULT_MEDIO_PAGO
    ): array {
        $rolRecaudo = $this->rolFromMedioPago($tenantId, $medioPago);
        $recaudo    = $this->resolveByRole($tenantId, $rolRecaudo);
        $ingresos   = $this->resolveByRole($tenantId, 'ingresos_ventas');

        return $this->recordEntry($tenantId, [
            'fecha'         => date('Y-m-d'),
            'referencia'    => $ref,
            'glosa'         => "Venta No-FE ({$docType}) Ref {$ref} — sin validez fiscal DIAN",
            'is_electronic' => 0,
            'cufe'          => '',
            'doc_type'      => $docType,
            'lines'         => [
                ['cuenta_id' => $recaudo,  'debe' => $total, 'haber' => 0,      'glosa_linea' => "No-FE Cobro - {$ref}"],
                ['cuenta_id' => $ingresos, 'debe' => 0,       'haber' => $total, 'glosa_linea' => "No-FE Venta - {$ref}"],
            ],
        ], $userId);
    }

    // ─── API pública ─────────────────────────────────────────────────────────

    /**
     * Contabilización genérica de venta (compat. con SkillExecutor / legacy).
     * Siempre No-FE — el caller debe llamar recordElectronicSale para FE.
     *
     * @return array<string, mixed>
     */
    public function recordSaleAccounting(
        string $tenantId,
        float  $total,
        string $ref,
        string $userId,
        string $medioPago = self::DEFAULT_MEDIO_PAGO
    ): array {
        return $this->recordNonElectronicSale($tenantId, $total, $ref, DocumentType::POS_SALE, $userId, $medioPago);
    }

    /**
     * Asiento manual con líneas explícitas (el caller provee cuenta_id ya resuelto).
     * Valida partida doble antes de grabar.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function recordManualEntry(string $tenantId, array $data, string $userId): array
    {
        $lines = (array) ($data['lines'] ?? []);
        if (count($lines) < 2) {
            throw new RuntimeException('El asiento requiere mínimo 2 líneas (partida doble).');
        }

        $debe  = array_sum(array_column($lines, 'debe'));
        $haber = array_sum(array_column($lines, 'haber'));
        if (abs($debe - $haber) > self::BALANCE_TOLERANCE) {
            throw new RuntimeException("Asiento no cuadra. Debe={$debe} Haber={$haber}");
        }

        $id = $this->repository->createJournalEntry([
            'tenant_id'     => $tenantId,
            'fecha'         => $data['fecha']       ?? date('Y-m-d'),
            'referencia'    => $data['referencia']  ?? '',
            'glosa'         => $data['glosa']       ?? '',
            'total_debe'    => $debe,
            'total_haber'   => $haber,
            'estado'        => 'CONTABILIZADO',
            'usuario_id'    => $userId,
            'is_electronic' => (int)  ($data['is_electronic'] ?? 0),
            'cufe'          => (string)($data['cufe']         ?? ''),
            'doc_type'      => (string)($data['doc_type']     ?? DocumentType::MANUAL),
        ], $lines);

        $isFe = (bool) ($data['is_electronic'] ?? false);
        return [
            'id'          => $id,
            'status'      => 'SUCCESS',
            'total'       => $debe,
            'is_electronic' => $isFe,
            'fiscal_note' => $isFe
                ? '✓ Asiento fiscal — Factura Electrónica aceptada por DIAN.'
                : '⚠ Asiento en libro auxiliar — Sin validez fiscal directa. Emite FE para soporte DIAN.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getBalanceSheet(string $tenantId): array
    {
        return $this->repository->listAccounts($tenantId);
    }

    /** @return array<int, array<string, mixed>> */
    public function listEntries(string $tenantId, string $filter = 'all', int $limit = 50): array
    {
        $filters = [];
        if (in_array($filter, ['fe', 'electronic'], true)) {
            $filters['is_electronic'] = 1;
        } elseif (in_array($filter, ['no-fe', 'non_electronic'], true)) {
            $filters['is_electronic'] = 0;
        }
        return $this->repository->listJournalEntries($tenantId, $filters, $limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function getRoles(string $tenantId): array
    {
        return $this->repository->listRoles($tenantId);
    }

    // ─── Resolución de cuentas por rol (ÚNICO punto de acceso a cuentas) ─────

    /**
     * Resuelve el ID de cuenta contable a partir de un rol semántico del tenant.
     * Si el rol no está configurado: auto-siembra defaults y lo intenta de nuevo.
     * Si sigue sin estar → lanza excepción clara para que el agente informe al usuario.
     */
    private function resolveByRole(string $tenantId, string $rol): int
    {
        $id = $this->repository->findAccountByRole($tenantId, $rol);
        if ($id === null) {
            $this->repository->seedDefaultRolesForTenant($tenantId);
            $id = $this->repository->findAccountByRole($tenantId, $rol);
        }
        if ($id === null) {
            throw new RuntimeException(
                "Rol contable '{$rol}' no configurado para tenant '{$tenantId}'. "
                . "El contador debe asignar una cuenta desde Configuración → Parámetros Contables."
            );
        }
        return $id;
    }

    /**
     * Resuelve el rol contable de recaudo según el medio de pago del tenant.
     * Si no hay config para ese medio → usa 'recaudo_efectivo' como fallback documentado
     * y emite WARNING para que el operador configure el medio de pago en Parámetros Contables.
     */
    private function rolFromMedioPago(string $tenantId, string $medioPago): string
    {
        $rol = $this->repository->findRolByMedioPago($tenantId, $medioPago);
        if ($rol === null) {
            error_log(
                "AccountingService [WARNING]: medio de pago '{$medioPago}' no configurado para tenant '{$tenantId}'. "
                . "Usando fallback 'recaudo_efectivo'. Configure el medio en Parámetros Contables → Medios de Pago."
            );
            return 'recaudo_efectivo';
        }
        return $rol;
    }

    // ─── Helper interno ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function recordEntry(string $tenantId, array $data, string $userId): array
    {
        $lines     = (array) ($data['lines'] ?? []);
        $totalDebe = (float) array_sum(array_column($lines, 'debe'));

        $id = $this->repository->createJournalEntry([
            'tenant_id'     => $tenantId,
            'fecha'         => $data['fecha']       ?? date('Y-m-d'),
            'referencia'    => $data['referencia']  ?? '',
            'glosa'         => $data['glosa']       ?? '',
            'total_debe'    => $totalDebe,
            'total_haber'   => $totalDebe,
            'estado'        => 'CONTABILIZADO',
            'usuario_id'    => $userId,
            'is_electronic' => (int)  ($data['is_electronic'] ?? 0),
            'cufe'          => (string)($data['cufe']         ?? ''),
            'doc_type'      => (string)($data['doc_type']     ?? DocumentType::POS_SALE),
        ], $lines);

        $isFe = (bool) ($data['is_electronic'] ?? false);
        return [
            'id'            => $id,
            'status'        => 'SUCCESS',
            'is_electronic' => $isFe,
            'total'         => $totalDebe,
            'fiscal_note'   => $isFe
                ? '✓ Asiento fiscal — Factura Electrónica aceptada por DIAN.'
                : '⚠ Asiento auxiliar — Sin validez fiscal. Emite FE para soporte DIAN.',
        ];
    }
}
