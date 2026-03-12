<?php

declare(strict_types=1);

namespace App\Core;

final class FiscalEngineMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $telemetry = ['module_used' => 'fiscal', 'fiscal_action' => 'none'];
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'app_id' => trim((string) ($context['project_id'] ?? '')) ?: null,
            'requested_by_user_id' => trim((string) ($context['user_id'] ?? '')) ?: 'system',
        ];

        return match ($skillName) {
            'fiscal_create_document' => $this->parseCreateDocument($pairs, $baseCommand, $telemetry),
            'fiscal_create_sales_invoice_from_sale' => $this->parseCreateSalesInvoice($pairs, $baseCommand, $telemetry),
            'fiscal_create_credit_note' => $this->parseCreateCreditNote($pairs, $baseCommand, $telemetry),
            'fiscal_create_support_document_from_purchase' => $this->parseCreateSupportDocument($pairs, $baseCommand, $telemetry),
            'fiscal_get_document' => $this->parseGetDocument($pairs, $baseCommand, $telemetry),
            'fiscal_list_documents' => $this->parseListDocuments($pairs, $baseCommand, $telemetry),
            'fiscal_list_documents_by_type' => $this->parseListDocumentsByType($pairs, $baseCommand, $telemetry),
            'fiscal_get_by_source' => $this->parseGetBySource($pairs, $baseCommand, $telemetry),
            'fiscal_build_document_payload' => $this->parseBuildDocumentPayload($pairs, $baseCommand, $telemetry),
            'fiscal_record_event' => $this->parseRecordEvent($pairs, $baseCommand, $telemetry),
            'fiscal_update_status' => $this->parseUpdateStatus($pairs, $baseCommand, $telemetry),
            default => ['kind' => 'ask_user', 'reply' => 'No pude interpretar la operacion fiscal.', 'telemetry' => $telemetry],
        };
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCreateDocument(array $pairs, array $baseCommand, array $telemetry): array
    {
        $sourceModule = $this->firstValue($pairs, ['source_module']);
        $sourceEntityType = $this->firstValue($pairs, ['source_entity_type']);
        $sourceEntityId = $this->firstValue($pairs, ['source_entity_id']);
        $documentType = $this->firstValue($pairs, ['document_type']);

        if ($sourceModule === '' || $sourceEntityType === '' || $sourceEntityId === '' || $documentType === '') {
            return $this->askUser('Indica `source_module`, `source_entity_type`, `source_entity_id` y `document_type` para crear el documento fiscal.', $this->telemetry($telemetry, 'create_document'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'CreateFiscalDocument',
            'source_module' => $sourceModule,
            'source_entity_type' => $sourceEntityType,
            'source_entity_id' => $sourceEntityId,
            'document_type' => $documentType,
            'document_number' => ($documentNumber = $this->firstValue($pairs, ['document_number', 'number'])) !== '' ? $documentNumber : null,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            'issuer_party_id' => ($issuerPartyId = $this->firstValue($pairs, ['issuer_party_id'])) !== '' ? $issuerPartyId : null,
            'receiver_party_id' => ($receiverPartyId = $this->firstValue($pairs, ['receiver_party_id'])) !== '' ? $receiverPartyId : null,
            'issue_date' => ($issueDate = $this->firstValue($pairs, ['issue_date', 'fecha'])) !== '' ? $issueDate : null,
            'currency' => ($currency = $this->firstValue($pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'external_provider' => ($provider = $this->firstValue($pairs, ['external_provider', 'provider'])) !== '' ? $provider : null,
            'external_reference' => ($reference = $this->firstValue($pairs, ['external_reference'])) !== '' ? $reference : null,
        ], $this->telemetry($telemetry, 'create_document'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCreateSalesInvoice(array $pairs, array $baseCommand, array $telemetry): array
    {
        $saleId = $this->firstValue($pairs, ['sale_id', 'source_entity_id']);
        $saleNumber = $this->firstValue($pairs, ['sale_number', 'number']);
        if ($saleId === '' && $saleNumber === '') {
            return $this->askUser('Indica `sale_id` o `sale_number` para preparar la factura electronica interna.', $this->telemetry($telemetry, 'create_sales_invoice_from_sale'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'CreateFiscalSalesInvoiceFromSale',
            'sale_id' => $saleId !== '' ? $saleId : null,
            'sale_number' => $saleNumber !== '' ? $saleNumber : null,
            'source_module' => 'pos',
            'source_entity_type' => 'sale',
            'source_entity_id' => $saleId !== '' ? $saleId : null,
            'document_type' => ($documentType = $this->firstValue($pairs, ['document_type'])) !== '' ? $documentType : null,
            'document_number' => ($documentNumber = $this->firstValue($pairs, ['document_number'])) !== '' ? $documentNumber : null,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
        ], $this->telemetry($telemetry, 'create_sales_invoice_from_sale'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCreateCreditNote(array $pairs, array $baseCommand, array $telemetry): array
    {
        $returnId = $this->firstValue($pairs, ['return_id']);
        $saleId = $this->firstValue($pairs, ['sale_id']);
        $saleNumber = $this->firstValue($pairs, ['sale_number', 'number']);
        if ($returnId === '' && $saleId === '' && $saleNumber === '') {
            return $this->askUser('Indica `return_id`, `sale_id` o `sale_number` para preparar la nota credito interna.', $this->telemetry($telemetry, 'create_credit_note'));
        }

        $sourceEntityType = $returnId !== '' ? 'return' : 'sale';
        $sourceEntityId = $returnId !== '' ? $returnId : ($saleId !== '' ? $saleId : null);

        return $this->commandResult($baseCommand + [
            'command' => 'CreateFiscalCreditNote',
            'return_id' => $returnId !== '' ? $returnId : null,
            'sale_id' => $saleId !== '' ? $saleId : null,
            'sale_number' => $saleNumber !== '' ? $saleNumber : null,
            'source_module' => 'pos',
            'source_entity_type' => $sourceEntityType,
            'source_entity_id' => $sourceEntityId,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            'reason' => ($reason = $this->firstValue($pairs, ['reason', 'motivo'])) !== '' ? $reason : null,
        ], $this->telemetry($telemetry, 'create_credit_note'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCreateSupportDocument(array $pairs, array $baseCommand, array $telemetry): array
    {
        $purchaseId = $this->firstValue($pairs, ['purchase_id', 'source_entity_id']);
        $purchaseNumber = $this->firstValue($pairs, ['purchase_number', 'number']);
        if ($purchaseId === '' && $purchaseNumber === '') {
            return $this->askUser('Indica `purchase_id` o `purchase_number` para preparar el documento soporte interno.', $this->telemetry($telemetry, 'create_support_document_from_purchase'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'CreateFiscalSupportDocumentFromPurchase',
            'purchase_id' => $purchaseId !== '' ? $purchaseId : null,
            'purchase_number' => $purchaseNumber !== '' ? $purchaseNumber : null,
            'source_module' => 'purchases',
            'source_entity_type' => 'purchase',
            'source_entity_id' => $purchaseId !== '' ? $purchaseId : null,
            'document_type' => ($documentType = $this->firstValue($pairs, ['document_type'])) !== '' ? $documentType : null,
            'document_number' => ($documentNumber = $this->firstValue($pairs, ['document_number'])) !== '' ? $documentNumber : null,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
        ], $this->telemetry($telemetry, 'create_support_document_from_purchase'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetDocument(array $pairs, array $baseCommand, array $telemetry): array
    {
        $documentId = $this->firstValue($pairs, ['fiscal_document_id', 'document_id', 'id']);
        if ($documentId === '') {
            return $this->askUser('Indica `fiscal_document_id` para cargar el documento fiscal.', $this->telemetry($telemetry, 'get_document'));
        }

        return $this->commandResult($baseCommand + ['command' => 'GetFiscalDocument', 'fiscal_document_id' => $documentId], $this->telemetry($telemetry, 'get_document'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListDocuments(array $pairs, array $baseCommand, array $telemetry): array
    {
        return $this->commandResult($baseCommand + [
            'command' => 'ListFiscalDocuments',
            'source_module' => ($sourceModule = $this->firstValue($pairs, ['source_module'])) !== '' ? $sourceModule : null,
            'source_entity_type' => ($sourceType = $this->firstValue($pairs, ['source_entity_type'])) !== '' ? $sourceType : null,
            'source_entity_id' => ($sourceId = $this->firstValue($pairs, ['source_entity_id'])) !== '' ? $sourceId : null,
            'document_type' => ($documentType = $this->firstValue($pairs, ['document_type'])) !== '' ? $documentType : null,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            'document_number' => ($documentNumber = $this->firstValue($pairs, ['document_number', 'number'])) !== '' ? $documentNumber : null,
            'date_from' => ($dateFrom = $this->firstValue($pairs, ['date_from', 'desde'])) !== '' ? $dateFrom : null,
            'date_to' => ($dateTo = $this->firstValue($pairs, ['date_to', 'hasta'])) !== '' ? $dateTo : null,
            'limit' => $this->firstValue($pairs, ['limit']) ?: '10',
        ], $this->telemetry($telemetry, 'list_documents'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListDocumentsByType(array $pairs, array $baseCommand, array $telemetry): array
    {
        $documentType = $this->firstValue($pairs, ['document_type']);
        if ($documentType === '') {
            return $this->askUser('Indica `document_type` para listar documentos fiscales por tipo.', $this->telemetry($telemetry, 'list_documents_by_type'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListFiscalDocumentsByType',
            'document_type' => $documentType,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            'date_from' => ($dateFrom = $this->firstValue($pairs, ['date_from', 'desde'])) !== '' ? $dateFrom : null,
            'date_to' => ($dateTo = $this->firstValue($pairs, ['date_to', 'hasta'])) !== '' ? $dateTo : null,
            'limit' => $this->firstValue($pairs, ['limit']) ?: '10',
        ], $this->telemetry($telemetry, 'list_documents_by_type'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetBySource(array $pairs, array $baseCommand, array $telemetry): array
    {
        $sourceModule = $this->firstValue($pairs, ['source_module']);
        $sourceEntityType = $this->firstValue($pairs, ['source_entity_type']);
        $sourceEntityId = $this->firstValue($pairs, ['source_entity_id']);
        if ($sourceModule === '' || $sourceEntityType === '' || $sourceEntityId === '') {
            return $this->askUser('Indica `source_module`, `source_entity_type` y `source_entity_id` para buscar el documento fiscal por origen.', $this->telemetry($telemetry, 'get_by_source'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetFiscalDocumentBySource',
            'source_module' => $sourceModule,
            'source_entity_type' => $sourceEntityType,
            'source_entity_id' => $sourceEntityId,
            'document_type' => ($documentType = $this->firstValue($pairs, ['document_type'])) !== '' ? $documentType : null,
            'limit' => $this->firstValue($pairs, ['limit']) ?: '10',
        ], $this->telemetry($telemetry, 'get_by_source'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseBuildDocumentPayload(array $pairs, array $baseCommand, array $telemetry): array
    {
        $documentId = $this->firstValue($pairs, ['fiscal_document_id', 'document_id', 'id']);
        if ($documentId === '') {
            return $this->askUser('Indica `fiscal_document_id` para preparar el payload fiscal.', $this->telemetry($telemetry, 'build_document_payload'));
        }

        return $this->commandResult($baseCommand + ['command' => 'BuildFiscalDocumentPayload', 'fiscal_document_id' => $documentId], $this->telemetry($telemetry, 'build_document_payload'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRecordEvent(array $pairs, array $baseCommand, array $telemetry): array
    {
        $documentId = $this->firstValue($pairs, ['fiscal_document_id', 'document_id', 'id']);
        $eventType = $this->firstValue($pairs, ['event_type']);
        if ($documentId === '' || $eventType === '') {
            return $this->askUser('Indica `fiscal_document_id` y `event_type` para registrar el evento fiscal.', $this->telemetry($telemetry, 'record_event'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'RecordFiscalEvent',
            'fiscal_document_id' => $documentId,
            'event_type' => $eventType,
            'event_status' => ($eventStatus = $this->firstValue($pairs, ['event_status'])) !== '' ? $eventStatus : 'recorded',
        ], $this->telemetry($telemetry, 'record_event'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseUpdateStatus(array $pairs, array $baseCommand, array $telemetry): array
    {
        $documentId = $this->firstValue($pairs, ['fiscal_document_id', 'document_id', 'id']);
        $status = $this->firstValue($pairs, ['status']);
        if ($documentId === '' || $status === '') {
            return $this->askUser('Indica `fiscal_document_id` y `status` para actualizar el estado fiscal.', $this->telemetry($telemetry, 'update_status'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'UpdateFiscalDocumentStatus',
            'fiscal_document_id' => $documentId,
            'status' => $status,
            'reason' => ($reason = $this->firstValue($pairs, ['reason', 'motivo'])) !== '' ? $reason : null,
        ], $this->telemetry($telemetry, 'update_status'));
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z0-9_]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s]+)/u', $message, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = strtolower(trim((string) ($match[1] ?? '')));
            $value = trim((string) ($match[2] ?? ''));
            $pairs[$key] = trim($value, "\"'");
        }

        return $pairs;
    }

    /**
     * @param array<string, string> $pairs
     */
    private function firstValue(array $pairs, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $value = trim((string) ($pairs[strtolower($alias)] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function commandResult(array $command, array $telemetry): array
    {
        return ['kind' => 'command', 'command' => $command, 'telemetry' => $telemetry];
    }

    /**
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function askUser(string $reply, array $telemetry): array
    {
        return ['kind' => 'ask_user', 'reply' => $reply, 'telemetry' => $telemetry];
    }

    /**
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function telemetry(array $telemetry, string $action): array
    {
        return $telemetry + ['fiscal_action' => $action];
    }
}
