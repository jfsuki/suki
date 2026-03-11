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
     * @param array<string, mixed> $item
     */
    private function formatPurchaseLine(array $item): string
    {
        return '- ' . (string) ($item['purchase_number'] ?? ('purchase_id=' . ($item['id'] ?? '')))
            . ' | total=' . (string) ($item['total'] ?? 0)
            . ' | proveedor=' . (string) (($item['supplier_id'] ?? '') ?: 'sin_proveedor');
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
            'PURCHASE_NOT_FOUND' => 'No encontre esa compra.',
            default => $message,
        };
    }
}
