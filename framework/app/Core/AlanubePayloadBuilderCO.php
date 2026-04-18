<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * AlanubePayloadBuilderCO
 *
 * Convierte el payload interno fiscal (fiscal_document_payload.schema.json)
 * al formato que Alanube Colombia espera para emitir documentos DIAN (UBL 2.1).
 *
 * NO realiza HTTP, NO firma, NO calcula CUFE: solo construye el JSON que
 * Alanube transformara al XML firmado segun Resolucion 0042 de 2020.
 *
 * Los datos del seller/emisor se toman del tenantProfile; si faltan datos
 * criticos se aplican placeholders y se registra advertencia en el payload
 * (metadata._warnings) para que el operador detecte la configuracion faltante.
 */
final class AlanubePayloadBuilderCO
{
    /** @var array<string,int> */
    private const DOCUMENT_TYPE_MAP = [
        'sales_invoice' => 1,
        'pos_ticket_fiscal_hook' => 1,
        'credit_note' => 4,
        'debit_note' => 5,
        // export -> 6 (no mapeado en internal types: se deja manual)
    ];

    private const DEFAULT_TAX_ID_IVA = 1;
    private const DEFAULT_TAX_ID_IC = 2;
    private const DEFAULT_TYPE_ITEM_IDENTIFICATION = 4; // referencia interna
    private const DEFAULT_PAYMENT_METHOD_CODE = '10';   // efectivo
    private const DEFAULT_CURRENCY = 'COP';

    /**
     * @param array<string,mixed> $internalPayload Payload validado por FiscalEngineService::buildDocumentPayload()
     * @param array<string,mixed> $tenantProfile   Perfil fiscal del emisor (NIT, razon social, direccion, municipio)
     * @return array<string,mixed>
     */
    public function build(array $internalPayload, array $tenantProfile = []): array
    {
        $warnings = [];
        $header = is_array($internalPayload['header'] ?? null) ? (array) $internalPayload['header'] : [];
        $summary = is_array($internalPayload['summary'] ?? null) ? (array) $internalPayload['summary'] : [];
        $lines = is_array($internalPayload['lines'] ?? null) ? (array) $internalPayload['lines'] : [];
        $metadata = is_array($internalPayload['metadata'] ?? null) ? (array) $internalPayload['metadata'] : [];

        $documentType = strtolower((string) ($internalPayload['document_type'] ?? ''));
        $typeDocumentId = self::DOCUMENT_TYPE_MAP[$documentType] ?? 1;

        $issueDate = $this->normalizeDate((string) ($header['issue_date'] ?? ''));
        $dueDate = $this->normalizeDate((string) ($metadata['due_date'] ?? $header['issue_date'] ?? ''));
        $currency = trim((string) ($header['currency'] ?? '')) !== ''
            ? strtoupper((string) $header['currency'])
            : self::DEFAULT_CURRENCY;

        $documentNumber = trim((string) ($header['document_number'] ?? ''));
        if ($documentNumber === '') {
            $documentNumber = 'SUKI-' . substr((string) ($internalPayload['fiscal_document_id'] ?? 'DOC'), 0, 12);
            $warnings[] = 'document_number_missing_auto_generated';
        }

        $customer = $this->buildCustomer($header, $metadata, $warnings);
        $seller = $this->buildSeller($tenantProfile, $warnings);
        $items = $this->buildItems($lines, $warnings);

        $payload = [
            'number' => $documentNumber,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'currency' => $currency,
            'type_document_id' => $typeDocumentId,
            'customer' => $customer,
            'seller' => $seller,
            'payment_method_code' => (string) ($metadata['payment_method_code'] ?? self::DEFAULT_PAYMENT_METHOD_CODE),
            'payment_due_date' => $dueDate,
            'notes' => (string) ($metadata['notes'] ?? ''),
            'items' => $items,
            'legal_monetary_totals' => $this->buildLegalMonetaryTotals($summary, $items),
        ];

        $internalWarnings = is_array($metadata['_warnings'] ?? null) ? (array) $metadata['_warnings'] : [];
        if ($warnings !== [] || $internalWarnings !== []) {
            $payload['metadata'] = [
                '_warnings' => array_values(array_unique(array_merge($internalWarnings, $warnings))),
                'fiscal_document_id' => (string) ($internalPayload['fiscal_document_id'] ?? ''),
            ];
        } else {
            $payload['metadata'] = [
                'fiscal_document_id' => (string) ($internalPayload['fiscal_document_id'] ?? ''),
            ];
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $internalPayload
     * @param array<string,mixed> $tenantProfile
     * @return array<string,mixed>
     */
    public function buildCreditNote(array $internalPayload, string $originalAlanubeId, array $tenantProfile = []): array
    {
        $originalAlanubeId = trim($originalAlanubeId);
        if ($originalAlanubeId === '') {
            throw new RuntimeException('ORIGINAL_ALANUBE_ID_REQUIRED');
        }

        $payload = $this->build($internalPayload, $tenantProfile);
        // Forzar type_document_id = 4 (nota credito)
        $payload['type_document_id'] = self::DOCUMENT_TYPE_MAP['credit_note'];

        // Referencia al documento original (billing_reference en UBL)
        $payload['billing_reference'] = [
            'number' => $originalAlanubeId,
            'uuid' => $originalAlanubeId,
            'issue_date' => $payload['issue_date'],
        ];

        // Discrepancy response por defecto: 2=anulacion
        $metadata = is_array($internalPayload['metadata'] ?? null) ? (array) $internalPayload['metadata'] : [];
        $references = is_array($metadata['references'] ?? null) ? (array) $metadata['references'] : [];
        $payload['discrepancy_response'] = [
            'reference' => $originalAlanubeId,
            'response_code' => (int) ($references['discrepancy_response_code'] ?? 2),
            'description' => (string) ($references['reason'] ?? 'Anulacion del documento'),
        ];

        return $payload;
    }

    /**
     * @param array<string,mixed> $header
     * @param array<string,mixed> $metadata
     * @param array<int,string>   $warnings
     * @return array<string,mixed>
     */
    private function buildCustomer(array $header, array $metadata, array &$warnings): array
    {
        $snapshot = is_array($metadata['source_snapshot'] ?? null) ? (array) $metadata['source_snapshot'] : [];
        $customerMeta = is_array($metadata['customer'] ?? null) ? (array) $metadata['customer'] : [];

        $identification = trim((string) (
            $customerMeta['identification_number']
            ?? $customerMeta['document_number']
            ?? $header['receiver_party_id']
            ?? ''
        ));
        if ($identification === '') {
            $identification = '222222222222';
            $warnings[] = 'customer_identification_missing_placeholder_consumidor_final';
        }

        $name = trim((string) ($customerMeta['name'] ?? $customerMeta['razon_social'] ?? 'Consumidor Final'));
        $email = trim((string) ($customerMeta['email'] ?? ''));
        $address = trim((string) ($customerMeta['address'] ?? ''));

        $customer = [
            'identification_number' => $identification,
            'name' => $name,
            'email' => $email,
            'address' => $address,
            'type_document_identification_id' => (int) ($customerMeta['type_document_identification_id'] ?? 3), // 3=CC default
            'type_organization_id' => (int) ($customerMeta['type_organization_id'] ?? 2),                    // 2=persona natural
            'type_liability_id' => (int) ($customerMeta['type_liability_id'] ?? 117),                        // 117=no responsable IVA
            'type_regime_id' => (int) ($customerMeta['type_regime_id'] ?? 2),                               // 2=simplificado
            'municipality_id' => (int) ($customerMeta['municipality_id'] ?? 149),                           // 149=Bogota default
        ];

        if (isset($snapshot['customer_id']) && $snapshot['customer_id'] !== '') {
            $customer['reference_id'] = (string) $snapshot['customer_id'];
        }

        return $customer;
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<int,string>   $warnings
     * @return array<string,mixed>
     */
    private function buildSeller(array $profile, array &$warnings): array
    {
        $nit = trim((string) ($profile['nit'] ?? $profile['identification_number'] ?? getenv('COMPANY_NIT') ?: ''));
        if ($nit === '') {
            $nit = '000000000';
            $warnings[] = 'seller_nit_missing_set_COMPANY_NIT_env';
        }

        $name = trim((string) ($profile['name'] ?? $profile['razon_social'] ?? getenv('COMPANY_NAME') ?: ''));
        if ($name === '') {
            $name = 'EMISOR PENDIENTE CONFIGURACION';
            $warnings[] = 'seller_name_missing_set_COMPANY_NAME_env';
        }

        $address = trim((string) ($profile['address'] ?? getenv('COMPANY_ADDRESS') ?: ''));
        $municipalityId = (int) ($profile['municipality_id'] ?? getenv('COMPANY_MUNICIPALITY_ID') ?: 149);

        return [
            'identification_number' => $nit,
            'name' => $name,
            'address' => $address,
            'municipality_id' => $municipalityId,
            'type_document_identification_id' => (int) ($profile['type_document_identification_id'] ?? 6), // 6=NIT
            'type_organization_id' => (int) ($profile['type_organization_id'] ?? 1),                     // 1=juridica
            'type_liability_id' => (int) ($profile['type_liability_id'] ?? 7),                           // 7=gran contribuyente fallback
            'type_regime_id' => (int) ($profile['type_regime_id'] ?? 1),                                // 1=responsable IVA
        ];
    }

    /**
     * @param array<int,mixed> $lines
     * @param array<int,string> $warnings
     * @return array<int,array<string,mixed>>
     */
    private function buildItems(array $lines, array &$warnings): array
    {
        if ($lines === []) {
            $warnings[] = 'items_empty';
            return [];
        }

        $items = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (float) ($line['qty'] ?? 1.0);
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $unitAmount = (float) ($line['unit_amount'] ?? 0.0);
            $lineTotal = (float) ($line['line_total'] ?? ($qty * $unitAmount));
            $taxRate = (float) ($line['tax_rate'] ?? 0.0);

            // subtotal linea sin impuesto
            $pricing = is_array(($line['metadata'] ?? [])['pricing_snapshot'] ?? null)
                ? (array) $line['metadata']['pricing_snapshot']
                : [];
            $lineExtensionAmount = isset($pricing['line_subtotal'])
                ? (float) $pricing['line_subtotal']
                : ($taxRate > 0 ? round($lineTotal / (1 + ($taxRate / 100)), 4) : $lineTotal);
            $taxAmount = isset($pricing['line_tax'])
                ? (float) $pricing['line_tax']
                : round($lineTotal - $lineExtensionAmount, 4);

            $item = [
                'line_extension_amount' => round($lineExtensionAmount, 2),
                'tax_totals' => [],
                'description' => (string) ($line['description'] ?? 'Producto'),
                'code' => (string) ($line['product_id'] ?? 'SIN_CODIGO'),
                'type_item_identification_id' => self::DEFAULT_TYPE_ITEM_IDENTIFICATION,
                'price_amount' => round($unitAmount, 2),
                'base_quantity' => round($qty, 4),
            ];

            if ($taxRate > 0 || $taxAmount > 0) {
                $item['tax_totals'][] = [
                    'tax_id' => self::DEFAULT_TAX_ID_IVA,
                    'tax_amount' => round($taxAmount, 2),
                    'percent' => round($taxRate, 2),
                    'taxable_amount' => round($lineExtensionAmount, 2),
                ];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string,mixed>          $summary
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function buildLegalMonetaryTotals(array $summary, array $items): array
    {
        $subtotal = $this->nullableFloat($summary['subtotal'] ?? null);
        $taxTotal = $this->nullableFloat($summary['tax_total'] ?? null);
        $total = $this->nullableFloat($summary['total'] ?? null);

        if ($subtotal === null) {
            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += (float) ($item['line_extension_amount'] ?? 0.0);
            }
        }
        if ($taxTotal === null) {
            $taxTotal = 0.0;
            foreach ($items as $item) {
                foreach ((array) ($item['tax_totals'] ?? []) as $tax) {
                    $taxTotal += (float) ($tax['tax_amount'] ?? 0.0);
                }
            }
        }
        if ($total === null) {
            $total = $subtotal + $taxTotal;
        }

        return [
            'line_extension_amount' => round($subtotal, 2),
            'tax_exclusive_amount' => round($subtotal, 2),
            'tax_inclusive_amount' => round($total, 2),
            'payable_amount' => round($total, 2),
        ];
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return date('Y-m-d');
        }
        // aceptar YYYY-MM-DD o YYYY-MM-DD HH:MM:SS o ISO 8601
        $ts = strtotime($value);
        if ($ts === false) {
            return date('Y-m-d');
        }
        return date('Y-m-d', $ts);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
