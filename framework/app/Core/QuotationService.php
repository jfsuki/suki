<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Servicio de Cotizaciones.
 *
 * Ciclo:  draft → sent → approved → invoiced | canceled
 *                           ↓
 *                    [convertir a]
 *                   factura / remisión / OC
 *
 * Reglas:
 * - Solo cotizaciones 'approved' pueden convertirse.
 * - Una cotización solo se convierte UNA vez (converted_to_id).
 * - La cotización NO es documento fiscal. Solo cuando se emite factura electrónica
 *   (vía Alanube) se registra en contabilidad fiscal.
 */
final class QuotationService
{
    private const VALID_STATUSES = ['draft', 'sent', 'viewed', 'approved', 'rejected', 'invoiced', 'canceled', 'expired'];
    private const VALID_TRANSITIONS = [
        'draft'    => ['sent', 'canceled'],
        'sent'     => ['viewed', 'approved', 'rejected', 'canceled'],
        'viewed'   => ['approved', 'rejected', 'canceled'],
        'approved' => ['invoiced', 'canceled'],
        'rejected' => [],
        'invoiced' => [],
        'canceled' => [],
        'expired'  => ['canceled'],
    ];

    private QuotationRepository $repository;
    private EmailService $emailService;
    private AuditLogger $auditLogger;

    public function __construct(
        ?QuotationRepository $repository = null,
        ?EmailService $emailService = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->repository   = $repository   ?? new QuotationRepository();
        $this->emailService = $emailService ?? new EmailService();
        $this->auditLogger  = $auditLogger  ?? new AuditLogger();
    }

    // ─── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Crea una cotización en estado draft.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(string $tenantId, array $payload, string $userId = 'system'): array
    {
        $tenantId = $this->req($tenantId, 'tenant_id');

        // Calcular totales si vienen líneas
        $lines = is_array($payload['lines'] ?? null) ? (array)$payload['lines'] : [];
        [$subtotal, $taxTotal, $discTotal, $total] = $this->calculateTotals($lines, $payload);

        $quotation = $this->repository->createQuotation([
            ...$payload,
            'tenant_id'          => $tenantId,
            'created_by_user_id' => $userId,
            'subtotal'           => $subtotal,
            'tax_total'          => $taxTotal,
            'discount_total'     => $discTotal,
            'total'              => $total,
        ]);

        // Insertar líneas si vienen en el payload
        $qId = (string)($quotation['id'] ?? '');
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $this->repository->addLine($tenantId, $qId, $line);
        }

        // Recalcular desde DB para reflejar líneas guardadas
        if (!empty($lines)) {
            $this->recalculateTotals($tenantId, $qId);
        }

        $result = $this->repository->findQuotation($tenantId, $qId) ?? $quotation;

        $this->auditLogger->log('quotation.created', 'quotation', $qId, [
            'tenant_id'        => $tenantId,
            'quotation_number' => $result['quotation_number'] ?? '',
            'customer_name'    => $result['customer_name'] ?? '',
            'total'            => $result['total'] ?? 0,
            'user_id'          => $userId,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(string $tenantId, string $id, array $payload, string $userId = 'system'): array
    {
        $quotation = $this->getOrFail($tenantId, $id);

        if (!in_array((string)($quotation['status'] ?? ''), ['draft', 'sent'], true)) {
            throw new RuntimeException('QUOTATION_NOT_EDITABLE — solo draft o sent pueden editarse.');
        }

        $updated = $this->repository->updateQuotation($tenantId, $id, $payload);
        return $updated ?? $quotation;
    }

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $id): array
    {
        return $this->getOrFail($tenantId, $id);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(string $tenantId, array $filters = [], int $limit = 20): array
    {
        return $this->repository->listQuotations($tenantId, $filters, $limit);
    }

    // ─── Líneas ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $lineData
     * @return array<string, mixed>
     */
    public function addLine(string $tenantId, string $id, array $lineData): array
    {
        $this->getOrFail($tenantId, $id);
        $line = $this->repository->addLine($tenantId, $id, $lineData);
        $this->recalculateTotals($tenantId, $id);
        return $line;
    }

    public function removeLine(string $tenantId, string $id, string $lineId): bool
    {
        $this->getOrFail($tenantId, $id);
        $removed = $this->repository->removeLine($tenantId, $id, $lineId);
        $this->recalculateTotals($tenantId, $id);
        return $removed;
    }

    // ─── Transiciones de Estado ───────────────────────────────────────────────

    /** Marca como enviada al cliente */
    public function markSent(string $tenantId, string $id): array
    {
        return $this->transition($tenantId, $id, 'sent');
    }

    /** Aprueba la cotización (cliente acepta) */
    public function approve(string $tenantId, string $id, ?string $notes = null): array
    {
        $q = $this->transition($tenantId, $id, 'approved');
        if ($notes !== null) {
            $this->repository->updateQuotation($tenantId, $id, ['notes' => $notes]);
        }
        return $q;
    }

    /** Rechaza la cotización */
    public function reject(string $tenantId, string $id, ?string $reason = null): array
    {
        return $this->transition($tenantId, $id, 'rejected',
            $reason !== null ? ['metadata' => ['rejection_reason' => $reason]] : []
        );
    }

    /** Cancela la cotización */
    public function cancel(string $tenantId, string $id): array
    {
        return $this->transition($tenantId, $id, 'canceled');
    }

    // ─── Conversión Cotización → Documento ───────────────────────────────────

    /**
     * Convierte la cotización aprobada en una factura (no electrónica aún).
     * Para emitir FE, el admincontable debe pasar la factura por Alanube.
     *
     * @return array<string, mixed> ['quotation' => ..., 'fiscal_document' => ..., 'doc_url' => ...]
     */
    public function convertToInvoice(string $tenantId, string $id, array $params = []): array
    {
        $q = $this->getOrFail($tenantId, $id);

        if ((string)($q['status'] ?? '') !== 'approved') {
            throw new RuntimeException('QUOTATION_NOT_APPROVED — solo cotizaciones aprobadas se pueden convertir a factura.');
        }
        if (($q['has_been_converted'] ?? false) === true) {
            throw new RuntimeException('QUOTATION_ALREADY_CONVERTED — ya se generó un documento desde esta cotización.');
        }

        // Construir payload para FiscalEngineService
        $lines = $q['lines'] ?? [];
        $fiscalLines = array_map(fn($l) => [
            'description' => $l['description'] ?? '',
            'quantity'    => (float)($l['quantity'] ?? 1),
            'unit_price'  => (float)($l['unit_price'] ?? 0),
            'tax_rate'    => (float)($l['tax_rate'] ?? 0),
            'line_total'  => (float)($l['line_total'] ?? 0),
            'sku'         => $l['sku'] ?? '',
            'item_id'     => $l['item_id'] ?? null,
        ], $lines);

        $fiscalService = new FiscalEngineService();
        $doc = $fiscalService->createDocumentFromSource($tenantId, [
            'source_module'       => 'quotation',
            'source_entity_type'  => 'quotation',
            'source_id'           => $q['id'],
            'document_type'       => 'sales_invoice',
            'issue_date'          => date('Y-m-d'),
            'currency'            => $q['currency'] ?? 'COP',
            'subtotal'            => $q['subtotal'] ?? 0,
            'tax_total'           => $q['tax_total'] ?? 0,
            'discount_total'      => $q['discount_total'] ?? 0,
            'total'               => $q['total'] ?? 0,
            'notes'               => ($q['notes'] ?? '') . "\nOrigen: Cotización {$q['quotation_number']}",
            'receiver_party_id'   => $q['customer_id'] ?? $q['customer_name'] ?? '',
            'lines'               => $fiscalLines,
            'metadata'            => [
                'origin_quotation_id'     => $q['id'],
                'origin_quotation_number' => $q['quotation_number'] ?? '',
                'customer_name'           => $q['customer_name'] ?? '',
                'customer_email'          => $q['customer_email'] ?? '',
            ],
        ]);

        $docId = (string)($doc['id'] ?? '');

        // Marcar cotización como convertida
        $this->repository->updateQuotation($tenantId, $id, [
            'status'             => 'invoiced',
            'converted_to_type'  => 'invoice',
            'converted_to_id'    => $docId,
        ]);

        $q = $this->repository->findQuotation($tenantId, $id) ?? $q;

        $this->auditLogger->log('quotation.converted_to_invoice', 'quotation', $id, [
            'tenant_id'     => $tenantId,
            'doc_id'        => $docId,
            'quotation_num' => $q['quotation_number'] ?? '',
            'total'         => $q['total'] ?? 0,
        ]);

        return [
            'ok'             => true,
            'quotation'      => $q,
            'fiscal_document'=> $doc,
            'doc_type'       => 'invoice',
            'doc_id'         => $docId,
            'doc_url'        => "/api.php?route=doc/render&type=invoice&id={$docId}",
            'message'        => "Factura creada desde cotización {$q['quotation_number']}. Para emitir FE, envíala a la DIAN desde el módulo fiscal.",
        ];
    }

    /**
     * Convierte la cotización aprobada en una remisión de despacho.
     * @return array<string, mixed>
     */
    public function convertToRemision(string $tenantId, string $id, array $params = []): array
    {
        $q = $this->getOrFail($tenantId, $id);

        if ((string)($q['status'] ?? '') !== 'approved') {
            throw new RuntimeException('QUOTATION_NOT_APPROVED');
        }
        if (($q['has_been_converted'] ?? false) === true) {
            throw new RuntimeException('QUOTATION_ALREADY_CONVERTED');
        }

        // Crear borrador POS con los ítems de la cotización
        $posService = new POSService();
        $draftPayload = [
            'tenant_id'     => $tenantId,
            'customer_id'   => $q['customer_id'] ?? null,
            'payment_method'=> 'credito',
            'notes'         => "REMISIÓN - Cotización {$q['quotation_number']}",
            'metadata'      => [
                'origin_quotation_id'     => $q['id'],
                'origin_quotation_number' => $q['quotation_number'] ?? '',
                'doc_type'                => 'remision',
            ],
        ];
        $draft = $posService->createDraft($draftPayload);
        $draftId = (string)($draft['id'] ?? '');

        foreach ($items as $item) {
            try {
                $posService->addLineByProductReference([
                    'tenant_id'    => $tenantId,
                    'draft_id'     => $draftId,
                    'sku'          => $item['sku'] ?? '',
                    'item_id'      => $item['item_id'] ?? null,
                    'quantity'     => $item['quantity'] ?? 1,
                    'override_price' => $item['unit_price'] ?? 0,
                ]);
            } catch (\Throwable) {
                // Item no encontrado en catálogo — agregar como línea manual
            }
        }

        $sale = $posService->finalizeDraftSale([
            'tenant_id'      => $tenantId,
            'draft_id'       => $draftId,
            'payment_method' => 'credito',
            'notes'          => "REMISIÓN desde cotización {$q['quotation_number']}",
        ]);

        $saleId = (string)($sale['sale_id'] ?? $sale['id'] ?? $draftId);

        $this->repository->updateQuotation($tenantId, $id, [
            'status'            => 'invoiced',
            'converted_to_type' => 'remision',
            'converted_to_id'   => $saleId,
        ]);

        $q = $this->repository->findQuotation($tenantId, $id) ?? $q;

        return [
            'ok'        => true,
            'quotation' => $q,
            'remision'  => $sale,
            'doc_type'  => 'remision',
            'doc_id'    => $saleId,
            'doc_url'   => "/api.php?route=doc/render&type=remision&id={$saleId}",
            'message'   => "Remisión creada desde cotización {$q['quotation_number']}.",
        ];
    }

    // ─── Email ────────────────────────────────────────────────────────────────

    /**
     * Envía la cotización al cliente por email (link al documento).
     * @return array<string, mixed>
     */
    public function sendByEmail(string $tenantId, string $id, ?string $baseUrl = null): array
    {
        $q = $this->getOrFail($tenantId, $id);
        $email = (string)($q['customer_email'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('QUOTATION_CUSTOMER_EMAIL_MISSING — la cotización no tiene email válido del cliente.');
        }

        $qId   = (string)($q['id'] ?? '');
        $base  = $baseUrl ?? ((string)(getenv('APP_URL') ?: 'http://localhost'));
        $docUrl = rtrim($base, '/') . "/api.php?route=doc/render&type=quotation&id={$qId}";

        $result = $this->emailService->sendDocumentLink($tenantId, [
            'to'         => $email,
            'to_name'    => $q['customer_name'] ?? '',
            'doc_type'   => 'quotation',
            'doc_number' => $q['quotation_number'] ?? '',
            'doc_url'    => $docUrl,
            'message'    => $q['notes'] ?? '',
        ]);

        if ($result['ok'] ?? false) {
            $this->repository->updateQuotation($tenantId, $qId, [
                'status'        => 'sent',
                'email_sent_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            ...$result,
            'quotation_number' => $q['quotation_number'] ?? '',
            'sent_to'          => $email,
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function getOrFail(string $tenantId, string $id): array
    {
        $q = $this->repository->findQuotation($tenantId, $id);
        if (!is_array($q) || ($q['id'] ?? '') === '') {
            // Try by quotation_number
            $q = $this->repository->findByNumber($tenantId, $id);
        }
        if (!is_array($q) || ($q['id'] ?? '') === '') {
            throw new RuntimeException("QUOTATION_NOT_FOUND: {$id}");
        }
        return $q;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function transition(string $tenantId, string $id, string $newStatus, array $extra = []): array
    {
        $q = $this->getOrFail($tenantId, $id);
        $current = (string)($q['status'] ?? 'draft');
        $allowed = self::VALID_TRANSITIONS[$current] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new RuntimeException("QUOTATION_INVALID_TRANSITION: {$current} → {$newStatus}. Permitido: " . implode(', ', $allowed));
        }

        $updates = ['status' => $newStatus, ...$extra];
        $updated = $this->repository->updateQuotation($tenantId, $id, $updates);
        return $updated ?? $q;
    }

    private function recalculateTotals(string $tenantId, string $id): void
    {
        $lines = $this->repository->listLines($tenantId, $id);
        $sub = 0.0; $tax = 0.0; $disc = 0.0;

        foreach ($lines as $line) {
            $qty  = (float)($line['quantity'] ?? 1);
            $up   = (float)($line['unit_price'] ?? 0);
            $tr   = (float)($line['tax_rate'] ?? 0);
            $d    = (float)($line['discount'] ?? 0);
            $base = $qty * $up - $d;
            $sub += $base;
            $tax += $base * $tr / 100;
            $disc += $d;
        }

        $this->repository->updateQuotation($tenantId, $id, [
            'subtotal'      => round($sub, 4),
            'tax_total'     => round($tax, 4),
            'discount_total'=> round($disc, 4),
            'total'         => round($sub + $tax, 4),
        ]);
    }

    /**
     * @param array<int, mixed> $lines
     * @param array<string, mixed> $payload
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function calculateTotals(array $lines, array $payload): array
    {
        if (!empty($lines)) {
            $sub = 0.0; $tax = 0.0; $disc = 0.0;
            foreach ($lines as $line) {
                if (!is_array($line)) continue;
                $qty  = (float)($line['quantity'] ?? $line['qty'] ?? 1);
                $up   = (float)($line['unit_price'] ?? $line['price'] ?? 0);
                $tr   = (float)($line['tax_rate'] ?? $line['iva_rate'] ?? 0);
                $d    = (float)($line['discount'] ?? 0);
                $base = $qty * $up - $d;
                $sub += $base; $tax += $base * $tr / 100; $disc += $d;
            }
            return [round($sub, 4), round($tax, 4), round($disc, 4), round($sub + $tax, 4)];
        }

        $sub  = (float)($payload['subtotal'] ?? 0);
        $tax  = (float)($payload['tax_total'] ?? 0);
        $disc = (float)($payload['discount_total'] ?? 0);
        $tot  = (float)($payload['total'] ?? ($sub + $tax));
        return [$sub, $tax, $disc, $tot];
    }

    private function req(mixed $v, string $field): string
    {
        $v = trim((string)$v);
        if ($v === '') throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        return $v;
    }
}
