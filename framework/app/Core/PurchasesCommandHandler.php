<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class PurchasesCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'CreatePurchaseDraft',
        'GetPurchaseDraft',
        'AddPurchaseDraftLine',
        'RemovePurchaseDraftLine',
        'AttachPurchaseDraftSupplier',
        'FinalizePurchase',
        'GetPurchase',
        'ListPurchases',
        'GetPurchaseByNumber',
        'AttachPurchaseDraftDocument',
        'AttachPurchaseDocument',
        'ListPurchaseDocuments',
        'GetPurchaseDocument',
        'DetachPurchaseDocument',
        'RegisterPurchaseDocumentMetadata',
    ];

    public function supports(string $commandName): bool
    {
        return in_array($commandName, self::SUPPORTED, true);
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = strtolower(trim((string) ($context['mode'] ?? 'app')));
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');
        $tenantId = trim((string) ($command['tenant_id'] ?? $context['tenant_id'] ?? ''));
        $appId = trim((string) ($command['app_id'] ?? $context['project_id'] ?? ''));

        if ($mode === 'builder') {
            return $this->withReplyText($reply(
                'Estas en modo creador. Usa el chat de la app para operar compras.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }

        $service = $context['purchases_service'] ?? null;
        if (!$service instanceof PurchasesService) {
            $service = new PurchasesService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'CreatePurchaseDraft' => $this->withReplyText($reply(
                    'Borrador de compra creado: draft_id=' . (string) (($draft = $service->createDraft($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]))['id'] ?? '') . '.',
                    $channel,
                    $sessionId,
                    $userId,
                    'success',
                    $this->moduleData(['purchases_action' => 'create_draft', 'draft' => $draft, 'item' => $draft, 'draft_id' => (string) ($draft['id'] ?? ''), 'purchase_draft_id' => (string) ($draft['id'] ?? ''), 'supplier_id' => (string) ($draft['supplier_id'] ?? ''), 'total' => (float) ($draft['total'] ?? 0), 'line_count' => count((array) ($draft['lines'] ?? [])), 'result_status' => 'success'])
                )),
                'GetPurchaseDraft' => $this->withReplyText($reply(
                    'Borrador de compra cargado: draft_id=' . (string) (($draft = $service->getDraft($tenantId, $this->draftId($command), $appId !== '' ? $appId : null))['id'] ?? '') . '.',
                    $channel,
                    $sessionId,
                    $userId,
                    'success',
                    $this->moduleData(['purchases_action' => 'get_draft', 'draft' => $draft, 'item' => $draft, 'draft_id' => (string) ($draft['id'] ?? ''), 'purchase_draft_id' => (string) ($draft['id'] ?? ''), 'supplier_id' => (string) ($draft['supplier_id'] ?? ''), 'total' => (float) ($draft['total'] ?? 0), 'line_count' => count((array) ($draft['lines'] ?? [])), 'result_status' => 'success'])
                )),
                'AddPurchaseDraftLine' => $this->withReplyText($reply(
                    'Linea agregada al borrador de compra ' . (string) (($draft = $service->addLineToDraft($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]))['id'] ?? '') . '.',
                    $channel,
                    $sessionId,
                    $userId,
                    'success',
                    $this->moduleData(['purchases_action' => 'add_draft_line', 'draft' => $draft, 'item' => $draft, 'draft_id' => (string) ($draft['id'] ?? ''), 'purchase_draft_id' => (string) ($draft['id'] ?? ''), 'supplier_id' => (string) ($draft['supplier_id'] ?? ''), 'total' => (float) ($draft['total'] ?? 0), 'line_count' => count((array) ($draft['lines'] ?? [])), 'result_status' => 'success'])
                )),
                'RemovePurchaseDraftLine' => $this->withReplyText($reply(
                    'Linea eliminada del borrador de compra ' . (string) (($draft = $service->removeLineFromDraft($tenantId, $this->draftId($command), (string) ($command['line_id'] ?? $command['id'] ?? ''), $appId !== '' ? $appId : null))['id'] ?? '') . '.',
                    $channel,
                    $sessionId,
                    $userId,
                    'success',
                    $this->moduleData(['purchases_action' => 'remove_draft_line', 'draft' => $draft, 'item' => $draft, 'draft_id' => (string) ($draft['id'] ?? ''), 'purchase_draft_id' => (string) ($draft['id'] ?? ''), 'supplier_id' => (string) ($draft['supplier_id'] ?? ''), 'total' => (float) ($draft['total'] ?? 0), 'line_count' => count((array) ($draft['lines'] ?? [])), 'result_status' => 'success'])
                )),
                'AttachPurchaseDraftSupplier' => $this->withReplyText($reply(
                    'Proveedor asociado al borrador de compra ' . (string) (($draft = $service->attachSupplierToDraft($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]))['id'] ?? '') . '.',
                    $channel,
                    $sessionId,
                    $userId,
                    'success',
                    $this->moduleData(['purchases_action' => 'attach_supplier', 'draft' => $draft, 'item' => $draft, 'draft_id' => (string) ($draft['id'] ?? ''), 'purchase_draft_id' => (string) ($draft['id'] ?? ''), 'supplier_id' => (string) ($draft['supplier_id'] ?? ''), 'total' => (float) ($draft['total'] ?? 0), 'line_count' => count((array) ($draft['lines'] ?? [])), 'result_status' => 'success'])
                )),
                'FinalizePurchase' => $this->respondFinalize($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPurchase' => $this->withReplyText($reply(
                    'Compra cargada: purchase_number=' . (string) (($purchase = $service->getPurchase($tenantId, (string) ($command['purchase_id'] ?? $command['id'] ?? ''), $appId !== '' ? $appId : null))['purchase_number'] ?? '') . '.',
                    $channel,
                    $sessionId,
                    $userId,
                    'success',
                    $this->moduleData(['purchases_action' => 'get_purchase', 'purchase' => $purchase, 'item' => $purchase, 'purchase_id' => (string) ($purchase['id'] ?? ''), 'purchase_number' => (string) ($purchase['purchase_number'] ?? ''), 'supplier_id' => (string) ($purchase['supplier_id'] ?? ''), 'total' => (float) ($purchase['total'] ?? 0), 'line_count' => count((array) ($purchase['lines'] ?? [])), 'result_status' => 'success'])
                )),
                'ListPurchases' => $this->respondList($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPurchaseByNumber' => $this->withReplyText($reply(
                    'Compra cargada por numero: purchase_number=' . (string) (($purchase = $service->getPurchaseByNumber($tenantId, (string) ($command['purchase_number'] ?? $command['number'] ?? ''), $appId !== '' ? $appId : null))['purchase_number'] ?? '') . '.',
                    $channel,
                    $sessionId,
                    $userId,
                    'success',
                    $this->moduleData(['purchases_action' => 'get_by_number', 'purchase' => $purchase, 'item' => $purchase, 'purchase_id' => (string) ($purchase['id'] ?? ''), 'purchase_number' => (string) ($purchase['purchase_number'] ?? ''), 'supplier_id' => (string) ($purchase['supplier_id'] ?? ''), 'total' => (float) ($purchase['total'] ?? 0), 'line_count' => count((array) ($purchase['lines'] ?? [])), 'result_status' => 'success'])
                )),
                'AttachPurchaseDraftDocument' => $this->respondAttachDraftDocument($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'AttachPurchaseDocument' => $this->respondAttachDocument($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'ListPurchaseDocuments' => $this->respondListDocuments($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPurchaseDocument' => $this->respondGetDocument($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'DetachPurchaseDocument' => $this->respondDetachDocument($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'RegisterPurchaseDocumentMetadata' => $this->respondRegisterDocumentMetadata($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                default => throw new RuntimeException('COMMAND_NOT_SUPPORTED'),
            };
        } catch (Throwable $e) {
            return $this->withReplyText($reply(
                $this->humanizeError((string) $e->getMessage()),
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData(['result_status' => 'error'])
            ));
        }
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondFinalize(PurchasesService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $result = $service->finalizeDraft($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]);
        $purchase = is_array($result['purchase'] ?? null) ? (array) $result['purchase'] : [];
        $this->recordUsageEvent([
            'tenant_id' => $tenantId,
            'project_id' => $appId !== '' ? $appId : null,
            'metric_key' => 'purchases_created',
            'delta_value' => 1,
            'unit' => 'count',
            'source_module' => 'purchases',
            'source_action' => 'finalize',
            'source_ref' => (string) ($purchase['id'] ?? ''),
        ]);

        return $this->withReplyText($reply(
            'Compra registrada: purchase_number=' . (string) ($purchase['purchase_number'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'purchases_action' => 'finalize',
                'purchase' => $purchase,
                'draft' => $result['draft'] ?? null,
                'item' => $purchase,
                'draft_id' => (string) (($result['draft']['id'] ?? '') ?: ''),
                'purchase_draft_id' => (string) (($result['draft']['id'] ?? '') ?: ''),
                'purchase_id' => (string) ($purchase['id'] ?? ''),
                'purchase_number' => (string) ($purchase['purchase_number'] ?? ''),
                'supplier_id' => (string) ($purchase['supplier_id'] ?? ''),
                'line_count' => (int) ($result['line_count'] ?? count((array) ($purchase['lines'] ?? []))),
                'total' => (float) ($purchase['total'] ?? 0),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondList(PurchasesService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $items = $service->listPurchases(
            $tenantId,
            array_filter([
                'status' => $command['status'] ?? null,
                'supplier_id' => $command['supplier_id'] ?? null,
                'draft_id' => $command['draft_id'] ?? null,
                'purchase_number' => $command['purchase_number'] ?? null,
                'date_from' => $command['date_from'] ?? null,
                'date_to' => $command['date_to'] ?? null,
                'limit' => $command['limit'] ?? null,
            ], static fn($value): bool => $value !== null && $value !== ''),
            $appId !== '' ? $appId : null
        );
        $text = $items === []
            ? 'No hay compras registradas con esos filtros.'
            : "Compras:\n" . implode("\n", array_map([$this, 'formatPurchaseLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData(['purchases_action' => 'list', 'items' => $items, 'result_count' => count($items), 'line_count' => count($items), 'result_status' => 'success'])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondAttachDraftDocument(PurchasesService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $document = $service->attachDocumentToPurchaseDraft($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]);

        return $this->withReplyText($reply(
            'Documento asociado al borrador de compra ' . (string) ($document['purchase_draft_id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'purchases_action' => 'attach_document_to_draft',
                'document' => $document,
                'item' => $document,
                'purchase_document_id' => (string) ($document['id'] ?? ''),
                'purchase_draft_id' => (string) ($document['purchase_draft_id'] ?? ''),
                'media_file_id' => (string) ($document['media_file_id'] ?? ''),
                'supplier_id' => (string) ($document['supplier_id'] ?? ''),
                'document_type' => (string) ($document['document_type'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondAttachDocument(PurchasesService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $document = $service->attachDocumentToPurchase($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]);

        return $this->withReplyText($reply(
            'Documento asociado a la compra ' . (string) (($document['purchase_id'] ?? '') ?: ($document['purchase_draft_id'] ?? '')) . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'purchases_action' => 'attach_document',
                'document' => $document,
                'item' => $document,
                'purchase_document_id' => (string) ($document['id'] ?? ''),
                'purchase_id' => (string) ($document['purchase_id'] ?? ''),
                'purchase_draft_id' => (string) ($document['purchase_draft_id'] ?? ''),
                'media_file_id' => (string) ($document['media_file_id'] ?? ''),
                'supplier_id' => (string) ($document['supplier_id'] ?? ''),
                'document_type' => (string) ($document['document_type'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondListDocuments(PurchasesService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $items = $service->listPurchaseDocuments(
            $tenantId,
            array_filter([
                'purchase_id' => $command['purchase_id'] ?? null,
                'purchase_number' => $command['purchase_number'] ?? null,
                'purchase_query' => $command['purchase_query'] ?? null,
                'purchase_draft_id' => $command['purchase_draft_id'] ?? $command['draft_id'] ?? null,
                'media_file_id' => $command['media_file_id'] ?? null,
                'document_type' => $command['document_type'] ?? null,
                'supplier_id' => $command['supplier_id'] ?? null,
                'supplier_query' => $command['supplier_query'] ?? null,
                'document_number' => $command['document_number'] ?? null,
                'date_from' => $command['date_from'] ?? null,
                'date_to' => $command['date_to'] ?? null,
                'limit' => $command['limit'] ?? null,
            ], static fn($value): bool => $value !== null && $value !== ''),
            $appId !== '' ? $appId : null
        );
        $text = $items === []
            ? 'No hay documentos de compra con esos filtros.'
            : "Documentos de compra:\n" . implode("\n", array_map([$this, 'formatPurchaseDocumentLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'purchases_action' => 'list_documents',
                'items' => $items,
                'result_count' => count($items),
                'purchase_id' => (string) ($command['purchase_id'] ?? ''),
                'purchase_draft_id' => (string) ($command['purchase_draft_id'] ?? $command['draft_id'] ?? ''),
                'document_type' => (string) ($command['document_type'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondGetDocument(PurchasesService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $documentId = trim((string) ($command['purchase_document_id'] ?? $command['document_id'] ?? $command['id'] ?? ''));
        $document = $service->getPurchaseDocument($tenantId, $documentId, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Documento de compra cargado: purchase_document_id=' . (string) ($document['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'purchases_action' => 'get_document',
                'document' => $document,
                'item' => $document,
                'purchase_document_id' => (string) ($document['id'] ?? ''),
                'purchase_id' => (string) ($document['purchase_id'] ?? ''),
                'purchase_draft_id' => (string) ($document['purchase_draft_id'] ?? ''),
                'media_file_id' => (string) ($document['media_file_id'] ?? ''),
                'supplier_id' => (string) ($document['supplier_id'] ?? ''),
                'document_type' => (string) ($document['document_type'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondDetachDocument(PurchasesService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $documentId = trim((string) ($command['purchase_document_id'] ?? $command['document_id'] ?? $command['id'] ?? ''));
        $result = $service->detachPurchaseDocument($tenantId, $documentId, $appId !== '' ? $appId : null);
        $document = is_array($result['document'] ?? null) ? (array) $result['document'] : [];

        return $this->withReplyText($reply(
            'Documento de compra desvinculado: purchase_document_id=' . (string) ($document['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'purchases_action' => 'detach_document',
                'deleted' => true,
                'document' => $document,
                'item' => $document,
                'purchase_document_id' => (string) ($document['id'] ?? ''),
                'purchase_id' => (string) ($document['purchase_id'] ?? ''),
                'purchase_draft_id' => (string) ($document['purchase_draft_id'] ?? ''),
                'media_file_id' => (string) ($document['media_file_id'] ?? ''),
                'supplier_id' => (string) ($document['supplier_id'] ?? ''),
                'document_type' => (string) ($document['document_type'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondRegisterDocumentMetadata(PurchasesService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $document = $service->registerDocumentMetadata($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]);

        return $this->withReplyText($reply(
            'Metadata del documento de compra actualizada: purchase_document_id=' . (string) ($document['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'purchases_action' => 'register_document_metadata',
                'document' => $document,
                'item' => $document,
                'purchase_document_id' => (string) ($document['id'] ?? ''),
                'purchase_id' => (string) ($document['purchase_id'] ?? ''),
                'purchase_draft_id' => (string) ($document['purchase_draft_id'] ?? ''),
                'media_file_id' => (string) ($document['media_file_id'] ?? ''),
                'supplier_id' => (string) ($document['supplier_id'] ?? ''),
                'document_type' => (string) ($document['document_type'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    private function draftId(array $command): string
    {
        return trim((string) ($command['draft_id'] ?? $command['purchase_draft_id'] ?? ''));
    }

    private function replyCallable(array $context): callable
    {
        return $context['reply_callable'] ?? static function (string $text, string $channel, string $sessionId, string $userId, string $status, array $data = []): array {
            return ['ok' => $status === 'success', 'status' => $status, 'channel' => $channel, 'session_id' => $sessionId, 'user_id' => $userId, 'reply' => $text, 'data' => $data];
        };
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function moduleData(array $overrides = []): array
    {
        return $overrides + ['module_used' => 'purchases', 'purchases_action' => 'none', 'result_status' => 'success'];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function withReplyText(array $response): array
    {
        $response['text'] = (string) ($response['reply'] ?? '');

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordUsageEvent(array $payload): void
    {
        try {
            (new UsageMeteringService())->recordUsageEvent($payload);
        } catch (\Throwable $e) {
            // Usage metering is best effort and must not block purchase operations.
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatPurchaseLine(array $item): string
    {
        return '- ' . (string) ($item['purchase_number'] ?? ('purchase_id=' . ($item['id'] ?? '')))
            . ' | total=' . (string) ($item['total'] ?? 0)
            . ' | proveedor=' . (string) (($item['supplier_id'] ?? '') ?: 'sin_proveedor');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatPurchaseDocumentLine(array $item): string
    {
        return '- doc_id=' . (string) ($item['id'] ?? '')
            . ' | type=' . (string) ($item['document_type'] ?? 'general_attachment')
            . ' | media=' . (string) ($item['media_file_id'] ?? '')
            . ' | purchase=' . (string) (($item['purchase_id'] ?? '') ?: 'sin_purchase')
            . ' | draft=' . (string) (($item['purchase_draft_id'] ?? '') ?: 'sin_draft');
    }

    private function humanizeError(string $message): string
    {
        return match ($message) {
            'PURCHASE_DRAFT_NOT_FOUND' => 'No encontre ese borrador de compra.',
            'PURCHASE_DRAFT_NOT_OPEN' => 'Ese borrador de compra ya no esta abierto.',
            'PURCHASE_DRAFT_EMPTY' => 'El borrador de compra no tiene lineas.',
            'PURCHASE_DRAFT_ALREADY_FINALIZED' => 'Ese borrador de compra ya fue finalizado.',
            'PURCHASE_DRAFT_TOTALS_INVALID' => 'Los totales del borrador de compra no son validos.',
            'PURCHASE_DRAFT_LINE_NOT_FOUND' => 'No encontre esa linea del borrador de compra.',
            'PURCHASE_SUPPLIER_REFERENCE_REQUIRED' => 'Necesito una referencia del proveedor.',
            'PURCHASE_SUPPLIER_NOT_FOUND' => 'No encontre ese proveedor.',
            'PURCHASE_SUPPLIER_AMBIGUOUS' => 'Encontre varios proveedores posibles. Indica cual usar.',
            'PURCHASE_PRODUCT_LABEL_REQUIRED' => 'Necesito la descripcion del item.',
            'PURCHASE_PRODUCT_NOT_FOUND' => 'No encontre ese producto.',
            'PURCHASE_PRODUCT_AMBIGUOUS' => 'Encontre varios productos posibles. Indica cual usar.',
            'PURCHASE_QTY_REQUIRED', 'PURCHASE_QTY_INVALID' => 'La cantidad debe ser mayor que cero.',
            'PURCHASE_UNIT_COST_REQUIRED', 'PURCHASE_UNIT_COST_INVALID' => 'Indica un costo unitario valido.',
            'PURCHASE_TAX_RATE_INVALID' => 'La tasa de impuesto no es valida.',
            'PURCHASE_REFERENCE_REQUIRED' => 'Necesito `purchase_id`, `purchase_number` o `purchase_query` para ubicar la compra.',
            'PURCHASE_AMBIGUOUS' => 'Encontre varias compras posibles. Indica una referencia exacta.',
            'PURCHASE_NOT_FOUND' => 'No encontre esa compra.',
            'PURCHASE_DOCUMENT_NOT_FOUND' => 'No encontre ese documento de compra.',
            'PURCHASE_DOCUMENT_TYPE_INVALID' => 'El `document_type` no es valido para compras.',
            'PURCHASE_DOCUMENT_MEDIA_NOT_FOUND' => 'No encontre ese archivo en media/documentos.',
            'PURCHASE_DOCUMENT_ISSUE_DATE_INVALID' => 'La fecha del documento no es valida.',
            'PURCHASE_DOCUMENT_TOTAL_INVALID' => 'El total del documento no es valido.',
            default => $message,
        };
    }
}
