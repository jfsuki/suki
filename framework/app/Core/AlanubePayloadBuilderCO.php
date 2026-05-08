<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use App\Core\DocumentType;

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
    /**
     * Mapa retención → withholding_tax_id catálogo Alanube API v1 (CO).
     * Actualizar si Alanube publica nueva versión del catálogo.
     */
    private const WITHHOLDING_TAX_MAP = [
        'rete_fuente' => 3,  // Retención en la Fuente
        'rete_ica'    => 4,  // Retención ICA
        'rete_iva'    => 1,  // Retención IVA
    ];

    /** @var array<string,int> Mapa tipo-documento interno → catalog ID Alanube API v1 */
    private const DOCUMENT_TYPE_MAP = [
        DocumentType::SALES_INVOICE     => 1, // Alanube API v1 catalog — actualizar si cambia versión de API
        DocumentType::POS_TICKET_FISCAL => 1, // Alanube API v1 catalog — actualizar si cambia versión de API
        DocumentType::CREDIT_NOTE       => 4, // Alanube API v1 catalog — actualizar si cambia versión de API
        DocumentType::DEBIT_NOTE        => 5, // Alanube API v1 catalog — actualizar si cambia versión de API
        // export -> 6 (no mapeado en internal types: se deja manual)
    ];

    public const PROVIDER_NAME = 'Alanube';

    /** @var array<string,string> Mapa eventos webhook Alanube → status fiscal interno */
    public const EVENT_MAP = [
        'accepted'          => 'accepted',
        'document.accepted' => 'accepted',
        'rejected'          => 'rejected',
        'document.rejected' => 'rejected',
    ];

    private const DEFAULT_TAX_ID_IVA  = 1;  // Alanube API v1 catalog — actualizar si cambia versión de API
    private const DEFAULT_TAX_ID_IC   = 2;  // Alanube API v1 catalog — actualizar si cambia versión de API
    private const DEFAULT_TYPE_ITEM_IDENTIFICATION = 4; // referencia interna — Alanube API v1 catalog
    private const DEFAULT_PAYMENT_METHOD_CODE = '10';   // efectivo
    private const DEFAULT_CURRENCY = 'COP';
    private const FALLBACK_PRODUCT_CODE = 'SIN_CODIGO'; // Alanube acepta este valor para ítems sin SKU

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
        if (!isset(self::DOCUMENT_TYPE_MAP[$documentType])) {
            throw new RuntimeException(
                "AlanubePayloadBuilderCO: tipo de documento desconocido: '{$documentType}'. "
                . "Agregar al DOCUMENT_TYPE_MAP con el ID de catálogo Alanube API v1 correspondiente."
            );
        }
        $typeDocumentId = self::DOCUMENT_TYPE_MAP[$documentType];

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

        $withholdingTotals = $this->buildWithholdingTaxTotals($summary);

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

        if ($withholdingTotals !== []) {
            $payload['withholding_tax_totals'] = $withholdingTotals;
        }

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

        $metadata = is_array($internalPayload['metadata'] ?? null) ? (array) $internalPayload['metadata'] : [];
        $references = is_array($metadata['references'] ?? null) ? (array) $metadata['references'] : [];
        if (!isset($references['discrepancy_response_code'])) {
            throw new RuntimeException(
                'AlanubePayloadBuilderCO: discrepancy_response_code requerido para notas crédito. '
                . 'Valores DIAN: 1=devolución, 2=anulación, 3=descuento, 4=ajuste precio. '
                . 'Pasar en metadata.references.discrepancy_response_code.'
            );
        }
        $payload['discrepancy_response'] = [
            'reference'     => $originalAlanubeId,
            'response_code' => (int) $references['discrepancy_response_code'],
            'description'   => (string) ($references['reason'] ?? 'Anulacion del documento'),
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
                throw new RuntimeException(
                    "AlanubePayloadBuilderCO: línea de factura con qty inválido ({$qty}). "
                    . 'La cantidad debe ser mayor a 0.'
                );
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

            $unspscCode = trim((string) ($line['codigo_unspsc'] ?? $line['unspsc_code'] ?? ''));
            $internalCode = trim((string) ($line['product_id'] ?? $line['sku'] ?? self::FALLBACK_PRODUCT_CODE));

            $item = [
                'line_extension_amount' => round($lineExtensionAmount, 2),
                'tax_totals' => [],
                'description' => (string) ($line['description'] ?? 'Producto'),
                'code' => $internalCode,
                'type_item_identification_id' => self::DEFAULT_TYPE_ITEM_IDENTIFICATION,
                'price_amount' => round($unitAmount, 2),
                'base_quantity' => round($qty, 4),
            ];

            if ($unspscCode !== '') {
                // standardItemIdentification: código UNSPSC requerido por DIAN para FE
                $item['standard_item_identification'] = [
                    'identification' => $unspscCode,
                    'identification_type' => 'UNSPSC',
                ];
            } else {
                $warnings[] = 'line_missing_unspsc_code_product_' . $internalCode;
            }

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
     * @param array<string,mixed>            $summary
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function buildLegalMonetaryTotals(array $summary, array $items): array
    {
        $subtotal = $this->nullableFloat($summary['subtotal'] ?? null);
        $taxTotal = $this->nullableFloat($summary['tax_total'] ?? null);
        $total    = $this->nullableFloat($summary['total'] ?? null);

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

        $wb               = is_array($summary['withholding_breakdown'] ?? null) ? (array) $summary['withholding_breakdown'] : [];
        $totalWithholding = (float) ($wb['total_withholding'] ?? 0.0);
        $netPayable       = (float) ($wb['net_payable'] ?? ($wb['net_payable'] !== null ? $wb['net_payable'] : $total - $totalWithholding));
        if ($netPayable <= 0.0 || $totalWithholding === 0.0) {
            $netPayable = $total;
        }

        $result = [
            'line_extension_amount' => round($subtotal, 2),
            'tax_exclusive_amount'  => round($subtotal, 2),
            'tax_inclusive_amount'  => round($total, 2),
            'payable_amount'        => round($netPayable, 2),
        ];

        if ($totalWithholding > 0.0) {
            $result['allowance_total_amount'] = round($totalWithholding, 2);
        }

        return $result;
    }

    /**
     * Construye el array withholding_tax_totals para el payload Alanube (Resolución DIAN 0042/2020).
     *
     * @param array<string,mixed> $summary
     * @return array<int,array<string,mixed>>
     */
    private function buildWithholdingTaxTotals(array $summary): array
    {
        $wb = is_array($summary['withholding_breakdown'] ?? null) ? (array) $summary['withholding_breakdown'] : [];
        if ($wb === []) {
            return [];
        }

        $subtotal = (float) ($summary['subtotal'] ?? 0.0);
        $taxTotal = (float) ($summary['tax_total'] ?? 0.0);
        $totals   = [];

        foreach (self::WITHHOLDING_TAX_MAP as $key => $taxId) {
            $amount = (float) ($wb[$key] ?? 0.0);
            if ($amount <= 0.0) {
                continue;
            }
            $rate = (float) ($wb[$key . '_rate'] ?? 0.0);
            $base = ($key === 'rete_iva') ? $taxTotal : $subtotal;
            $totals[] = [
                'withholding_tax_id'    => $taxId,
                'withholding_tax_amount' => round($amount, 2),
                'percent'               => round($rate * 100, 4),
                'taxable_amount'        => round($base, 2),
            ];
        }

        return $totals;
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
