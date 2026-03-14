<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class POSCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'CreatePOSDraft',
        'GetPOSDraft',
        'AddPOSDraftLine',
        'AddPOSLineByReference',
        'RemovePOSDraftLine',
        'AttachPOSDraftCustomer',
        'ListPOSOpenDrafts',
        'FindPOSProduct',
        'GetPOSProductCandidates',
        'RepricePOSDraft',
        'FinalizePOSSale',
        'GetPOSSale',
        'ListPOSSales',
        'BuildPOSReceipt',
        'GetPOSSaleByNumber',
        'CancelPOSSale',
        'CreatePOSReturn',
        'GetPOSReturn',
        'ListPOSReturns',
        'BuildPOSReturnReceipt',
        'OpenPOSCashRegister',
        'GetPOSOpenCashSession',
        'ClosePOSCashRegister',
        'BuildPOSCashSummary',
        'ListPOSCashSessions',
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
                'Estas en modo creador. Usa el chat de la app para operar borradores POS.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }

        $service = $context['pos_service'] ?? null;
        if (!$service instanceof POSService) {
            $service = new POSService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'CreatePOSDraft' => $this->handleCreateDraft($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPOSDraft' => $this->handleGetDraft($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'AddPOSDraftLine' => $this->handleAddLine($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'AddPOSLineByReference' => $this->handleAddLineByReference($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'RemovePOSDraftLine' => $this->handleRemoveLine($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'AttachPOSDraftCustomer' => $this->handleAttachCustomer($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'ListPOSOpenDrafts' => $this->handleListOpenDrafts($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'FindPOSProduct' => $this->handleFindProduct($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPOSProductCandidates' => $this->handleGetProductCandidates($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'RepricePOSDraft' => $this->handleRepriceDraft($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'FinalizePOSSale' => $this->handleFinalizeSale($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPOSSale' => $this->handleGetSale($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'ListPOSSales' => $this->handleListSales($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'BuildPOSReceipt' => $this->handleBuildReceipt($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPOSSaleByNumber' => $this->handleGetSaleByNumber($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'CancelPOSSale' => $this->handleCancelSale($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'CreatePOSReturn' => $this->handleCreateReturn($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPOSReturn' => $this->handleGetReturn($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'ListPOSReturns' => $this->handleListReturns($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'BuildPOSReturnReceipt' => $this->handleBuildReturnReceipt($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'OpenPOSCashRegister' => $this->handleOpenCashRegister($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPOSOpenCashSession' => $this->handleGetOpenCashSession($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'ClosePOSCashRegister' => $this->handleCloseCashRegister($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'BuildPOSCashSummary' => $this->handleBuildCashSummary($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'ListPOSCashSessions' => $this->handleListCashSessions($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                default => throw new RuntimeException('COMMAND_NOT_SUPPORTED'),
            };
        } catch (Throwable $e) {
            return $this->withReplyText($reply(
                $this->humanizeError((string) $e->getMessage()),
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData([
                    'result_status' => 'error',
                ])
            ));
        }
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleCreateDraft(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draft = $service->createDraft($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);

        return $this->withReplyText($reply(
            'Borrador POS creado: draft_id=' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'create_draft',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleGetDraft(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draftId = $this->draftId($command);
        $draft = $service->getDraft($tenantId, $draftId, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Borrador cargado: draft_id=' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'get_draft',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleAddLine(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draft = $service->addLineToDraft($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);
        $lines = is_array($draft['lines'] ?? null) ? (array) $draft['lines'] : [];
        $lastLine = is_array(end($lines)) ? (array) end($lines) : [];

        return $this->withReplyText($reply(
            'Linea agregada al borrador ' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'add_draft_line',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'product_id' => (string) ($lastLine['product_id'] ?? ''),
                'matched_product_id' => (string) ($lastLine['product_id'] ?? ''),
                'matched_by' => (string) (($lastLine['metadata']['resolved_product']['matched_by'] ?? '') ?: ''),
                'product_query' => trim((string) ($command['query'] ?? $command['barcode'] ?? $command['sku'] ?? '')),
                'ambiguity_count' => 0,
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleAddLineByReference(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $resolution = $service->resolveProductForPOS($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);
        $productQuery = (string) ($resolution['product_query'] ?? '');
        $candidates = is_array($resolution['candidates'] ?? null) ? (array) $resolution['candidates'] : [];
        if (!(bool) ($resolution['resolved'] ?? false)) {
            $text = $candidates === []
                ? 'No encontre un producto valido dentro del tenant actual.'
                : "Encontre varios productos posibles. Indica cual usar:\n" . implode("\n", array_map([$this, 'formatProductLine'], $candidates));

            return $this->withReplyText($reply(
                $text,
                $channel,
                $sessionId,
                $userId,
                'success',
                $this->moduleData([
                    'pos_action' => 'add_line_by_reference',
                    'draft_id' => $this->draftId($command),
                    'product_query' => $productQuery,
                    'matched_product_id' => '',
                    'matched_by' => (string) ($resolution['matched_by'] ?? ''),
                    'ambiguity_count' => count($candidates),
                    'needs_clarification' => $candidates !== [],
                    'candidates' => $candidates,
                    'items' => $candidates,
                    'result_status' => (string) ($resolution['result_status'] ?? 'not_found'),
                ])
            ));
        }

        $resolvedProduct = is_array($resolution['result'] ?? null) ? (array) $resolution['result'] : [];
        $draft = $service->addLineByProductReference($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
            'product_id' => (string) ($resolvedProduct['entity_id'] ?? ''),
        ]);
        $lines = is_array($draft['lines'] ?? null) ? (array) $draft['lines'] : [];
        $lastLine = is_array(end($lines)) ? (array) end($lines) : [];

        return $this->withReplyText($reply(
            'Linea agregada al borrador ' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'add_line_by_reference',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'product_id' => (string) ($lastLine['product_id'] ?? ''),
                'matched_product_id' => (string) ($lastLine['product_id'] ?? ''),
                'matched_by' => (string) ($resolution['matched_by'] ?? ''),
                'product_query' => $productQuery,
                'ambiguity_count' => 0,
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleRemoveLine(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draftId = $this->draftId($command);
        $lineId = trim((string) ($command['line_id'] ?? $command['id'] ?? ''));
        if ($lineId === '') {
            throw new RuntimeException('POS_LINE_ID_REQUIRED');
        }

        $draft = $service->removeLineFromDraft($tenantId, $draftId, $lineId, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Linea eliminada del borrador ' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'remove_draft_line',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleAttachCustomer(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draft = $service->attachCustomerToDraft($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);

        return $this->withReplyText($reply(
            'Cliente asociado al borrador ' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'attach_customer',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleListOpenDrafts(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $items = $service->listOpenDrafts(
            $tenantId,
            $appId !== '' ? $appId : null,
            max(1, (int) ($command['limit'] ?? 10))
        );
        $text = $items === []
            ? 'No hay borradores POS abiertos.'
            : "Borradores abiertos:\n" . implode("\n", array_map([$this, 'formatDraftLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'list_open_drafts',
                'items' => $items,
                'result_count' => count($items),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleFindProduct(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $result = $service->resolveProductForPOS($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);
        $resolved = (bool) ($result['resolved'] ?? false);
        $selected = is_array($result['result'] ?? null) ? (array) $result['result'] : [];
        $candidates = is_array($result['candidates'] ?? null) ? (array) $result['candidates'] : [];
        $text = $resolved && $selected !== []
            ? 'Producto resuelto: ' . $this->formatProductLabel($selected) . '.'
            : ($candidates !== []
                ? "Encontre varios productos posibles. Indica cual usar:\n" . implode("\n", array_map([$this, 'formatProductLine'], $candidates))
                : 'No encontre un producto valido dentro del tenant actual.');

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'find_product',
                'result' => $resolved ? $selected : null,
                'item' => $resolved ? $selected : null,
                'items' => $resolved ? [$selected] : $candidates,
                'candidates' => $resolved ? [] : $candidates,
                'product_id' => (string) ($result['matched_product_id'] ?? ''),
                'matched_product_id' => (string) ($result['matched_product_id'] ?? ''),
                'matched_by' => (string) ($result['matched_by'] ?? ''),
                'product_query' => (string) ($result['product_query'] ?? ''),
                'ambiguity_count' => $resolved ? 0 : count($candidates),
                'resolved' => $resolved,
                'needs_clarification' => (bool) ($result['needs_clarification'] ?? false),
                'result_count' => (int) ($result['result_count'] ?? ($resolved ? 1 : count($candidates))),
                'result_status' => (string) ($result['result_status'] ?? 'not_found'),
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleGetProductCandidates(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $result = $service->getProductCandidatesForPOS($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);
        $items = is_array($result['items'] ?? null) ? (array) $result['items'] : [];
        $text = $items === []
            ? 'No encontre productos candidatos dentro del tenant actual.'
            : "Candidatos de producto:\n" . implode("\n", array_map([$this, 'formatProductLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'get_product_candidates',
                'items' => $items,
                'candidates' => $items,
                'result' => is_array($result['result'] ?? null) ? (array) $result['result'] : null,
                'matched_product_id' => (string) ($result['matched_product_id'] ?? ''),
                'product_id' => (string) ($result['matched_product_id'] ?? ''),
                'matched_by' => (string) ($result['matched_by'] ?? ''),
                'product_query' => (string) ($result['query'] ?? ''),
                'ambiguity_count' => (bool) ($result['resolved'] ?? false) ? 0 : count($items),
                'resolved' => (bool) ($result['resolved'] ?? false),
                'needs_clarification' => (bool) ($result['needs_clarification'] ?? false),
                'result_count' => (int) ($result['result_count'] ?? count($items)),
                'result_status' => (string) ($result['result_status'] ?? 'not_found'),
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleRepriceDraft(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draftId = $this->draftId($command);
        $draft = $service->repriceDraft(
            $tenantId,
            $draftId,
            $appId !== '' ? $appId : null,
            $command
        );

        return $this->withReplyText($reply(
            'Borrador POS recalculado: draft_id=' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'reprice_draft',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'product_query' => trim((string) ($command['query'] ?? $command['barcode'] ?? $command['sku'] ?? '')),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleFinalizeSale(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $result = $service->finalizeDraftSale($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);
        $sale = is_array($result['sale'] ?? null) ? (array) $result['sale'] : [];
        $draft = is_array($result['draft'] ?? null) ? (array) $result['draft'] : [];
        $this->recordUsageEvent([
            'tenant_id' => $tenantId,
            'project_id' => $appId !== '' ? $appId : null,
            'metric_key' => 'sales_created',
            'delta_value' => 1,
            'unit' => 'count',
            'source_module' => 'pos',
            'source_action' => 'finalize_sale',
            'source_ref' => (string) ($sale['id'] ?? ''),
        ]);

        return $this->withReplyText($reply(
            'Venta POS finalizada: sale_number=' . (string) ($sale['sale_number'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'finalize_sale',
                'sale' => $sale,
                'draft' => $draft,
                'item' => $sale,
                'draft_id' => (string) ($draft['id'] ?? $command['draft_id'] ?? ''),
                'sale_id' => (string) ($sale['id'] ?? ''),
                'sale_number' => (string) ($sale['sale_number'] ?? ''),
                'session_id' => (string) ($sale['session_id'] ?? ''),
                'line_count' => count((array) ($sale['lines'] ?? [])),
                'total' => (float) ($sale['total'] ?? 0),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleGetSale(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $saleId = $this->saleId($command);
        $sale = $service->getSale($tenantId, $saleId, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Venta POS cargada: sale_number=' . (string) ($sale['sale_number'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'get_sale',
                'sale' => $sale,
                'item' => $sale,
                'draft_id' => (string) ($sale['draft_id'] ?? ''),
                'sale_id' => (string) ($sale['id'] ?? ''),
                'sale_number' => (string) ($sale['sale_number'] ?? ''),
                'session_id' => (string) ($sale['session_id'] ?? ''),
                'line_count' => count((array) ($sale['lines'] ?? [])),
                'total' => (float) ($sale['total'] ?? 0),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleListSales(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $items = $service->listSales($tenantId, $command, $appId !== '' ? $appId : null);
        $text = $items === []
            ? 'No hay ventas POS para los filtros indicados.'
            : "Ventas POS:\n" . implode("\n", array_map([$this, 'formatSaleLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'list_sales',
                'items' => $items,
                'result_count' => count($items),
                'line_count' => array_sum(array_map(fn(array $sale): int => count((array) ($sale['lines'] ?? [])), $items)),
                'total' => array_sum(array_map(fn(array $sale): float => (float) ($sale['total'] ?? 0), $items)),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleBuildReceipt(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $sale = $this->resolveSaleFromCommand($service, $tenantId, $appId, $command);
        $receipt = $service->buildReceiptPayload($tenantId, (string) ($sale['id'] ?? ''), $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Ticket POS preparado: sale_number=' . (string) ($receipt['sale_number'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'build_receipt',
                'sale' => $sale,
                'receipt' => $receipt,
                'item' => $receipt,
                'draft_id' => (string) ($sale['draft_id'] ?? ''),
                'sale_id' => (string) ($sale['id'] ?? ''),
                'sale_number' => (string) ($sale['sale_number'] ?? ''),
                'session_id' => (string) ($sale['session_id'] ?? ''),
                'line_count' => count((array) ($sale['lines'] ?? [])),
                'total' => (float) ($sale['total'] ?? 0),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleGetSaleByNumber(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $saleNumber = trim((string) ($command['sale_number'] ?? $command['number'] ?? ''));
        if ($saleNumber === '') {
            throw new RuntimeException('POS_SALE_NUMBER_REQUIRED');
        }

        $sale = $service->getSaleByNumber($tenantId, $saleNumber, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Venta POS cargada: sale_number=' . (string) ($sale['sale_number'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'get_sale_by_number',
                'sale' => $sale,
                'item' => $sale,
                'draft_id' => (string) ($sale['draft_id'] ?? ''),
                'sale_id' => (string) ($sale['id'] ?? ''),
                'sale_number' => (string) ($sale['sale_number'] ?? ''),
                'session_id' => (string) ($sale['session_id'] ?? ''),
                'line_count' => count((array) ($sale['lines'] ?? [])),
                'total' => (float) ($sale['total'] ?? 0),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleCancelSale(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $result = $service->cancelSale($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);
        $sale = is_array($result['sale'] ?? null) ? (array) $result['sale'] : [];
        $receipt = is_array($result['receipt'] ?? null) ? (array) $result['receipt'] : [];

        return $this->withReplyText($reply(
            'Venta POS cancelada: sale_number=' . (string) ($sale['sale_number'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'cancel_sale',
                'sale' => $sale,
                'receipt' => $receipt,
                'item' => $sale,
                'sale_id' => (string) ($sale['id'] ?? ''),
                'sale_number' => (string) ($sale['sale_number'] ?? ''),
                'session_id' => (string) ($sale['session_id'] ?? ''),
                'line_count' => count((array) ($sale['lines'] ?? [])),
                'total' => (float) ($sale['total'] ?? 0),
                'reason' => (string) ($command['reason'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleCreateReturn(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $result = $service->createReturnFromSale($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);
        $return = is_array($result['return'] ?? null) ? (array) $result['return'] : [];
        $sale = is_array($result['sale'] ?? null) ? (array) $result['sale'] : [];
        $receipt = is_array($result['receipt'] ?? null) ? (array) $result['receipt'] : [];

        return $this->withReplyText($reply(
            'Devolucion POS creada: return_number=' . (string) ($return['return_number'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'create_return',
                'sale' => $sale,
                'return' => $return,
                'receipt' => $receipt,
                'item' => $return,
                'sale_id' => (string) ($sale['id'] ?? $return['sale_id'] ?? ''),
                'return_id' => (string) ($return['id'] ?? ''),
                'return_number' => (string) ($return['return_number'] ?? ''),
                'session_id' => (string) ($sale['session_id'] ?? ''),
                'line_count' => count((array) ($return['lines'] ?? [])),
                'total' => (float) ($return['total'] ?? 0),
                'reason' => (string) ($return['reason'] ?? $command['reason'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleGetReturn(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $returnId = $this->returnId($command);
        $return = $service->getReturn($tenantId, $returnId, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Devolucion POS cargada: return_number=' . (string) ($return['return_number'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'get_return',
                'return' => $return,
                'item' => $return,
                'sale_id' => (string) ($return['sale_id'] ?? ''),
                'return_id' => (string) ($return['id'] ?? ''),
                'return_number' => (string) ($return['return_number'] ?? ''),
                'line_count' => count((array) ($return['lines'] ?? [])),
                'total' => (float) ($return['total'] ?? 0),
                'reason' => (string) ($return['reason'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleListReturns(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $items = $service->listReturns($tenantId, $command, $appId !== '' ? $appId : null);
        $text = $items === []
            ? 'No hay devoluciones POS para los filtros indicados.'
            : "Devoluciones POS:\n" . implode("\n", array_map([$this, 'formatReturnLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'list_returns',
                'items' => $items,
                'result_count' => count($items),
                'line_count' => array_sum(array_map(fn(array $return): int => count((array) ($return['lines'] ?? [])), $items)),
                'total' => array_sum(array_map(fn(array $return): float => (float) ($return['total'] ?? 0), $items)),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleBuildReturnReceipt(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $returnId = $this->returnId($command);
        $return = $service->getReturn($tenantId, $returnId, $appId !== '' ? $appId : null);
        $receipt = $service->buildReturnReceiptPayload($tenantId, $returnId, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Ticket de devolucion POS preparado: return_number=' . (string) ($return['return_number'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'build_return_receipt',
                'return' => $return,
                'receipt' => $receipt,
                'item' => $receipt,
                'sale_id' => (string) ($return['sale_id'] ?? ''),
                'return_id' => (string) ($return['id'] ?? ''),
                'return_number' => (string) ($return['return_number'] ?? ''),
                'line_count' => count((array) ($return['lines'] ?? [])),
                'total' => (float) ($return['total'] ?? 0),
                'reason' => (string) ($return['reason'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleOpenCashRegister(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $session = $service->openCashRegister($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);

        return $this->withReplyText($reply(
            'Caja POS abierta: session_id=' . (string) ($session['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'open_cash_register',
                'cash_session' => $session,
                'item' => $session,
                'cash_register_id' => (string) ($session['cash_register_id'] ?? ''),
                'session_id' => (string) ($session['id'] ?? ''),
                'opening_amount' => (float) (($session['opening_amount'] ?? 0) ?: 0),
                'sales_count' => 0,
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleGetOpenCashSession(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $cashRegisterId = trim((string) ($command['cash_register_id'] ?? ''));
        if ($cashRegisterId === '') {
            throw new RuntimeException('POS_CASH_REGISTER_ID_REQUIRED');
        }

        $session = $service->getOpenCashSession($tenantId, $cashRegisterId, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Caja POS abierta encontrada: session_id=' . (string) ($session['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'get_open_cash_session',
                'cash_session' => $session,
                'item' => $session,
                'cash_register_id' => (string) ($session['cash_register_id'] ?? ''),
                'session_id' => (string) ($session['id'] ?? ''),
                'opening_amount' => (float) (($session['opening_amount'] ?? 0) ?: 0),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleCloseCashRegister(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $result = $service->closeCashRegister($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);
        $session = is_array($result['session'] ?? null) ? (array) $result['session'] : [];
        $summary = is_array($result['summary'] ?? null) ? (array) $result['summary'] : [];

        return $this->withReplyText($reply(
            'Caja POS cerrada: session_id=' . (string) ($session['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'close_cash_register',
                'cash_session' => $session,
                'cash_summary' => $summary,
                'item' => $session,
                'cash_register_id' => (string) ($session['cash_register_id'] ?? ''),
                'session_id' => (string) ($session['id'] ?? ''),
                'opening_amount' => (float) (($summary['opening_amount'] ?? 0) ?: 0),
                'counted_cash_amount' => $summary['counted_cash_amount'] ?? null,
                'difference_amount' => $summary['difference_amount'] ?? null,
                'sales_count' => (int) ($summary['sales_count'] ?? 0),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleBuildCashSummary(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $sessionIdValue = $this->sessionIdValue($command);
        $summary = $service->buildCashSummary($tenantId, $sessionIdValue, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Arqueo POS preparado: session_id=' . $sessionIdValue . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'build_cash_summary',
                'cash_summary' => $summary,
                'item' => $summary,
                'cash_register_id' => (string) ($summary['cash_register_id'] ?? ''),
                'session_id' => $sessionIdValue,
                'opening_amount' => (float) (($summary['opening_amount'] ?? 0) ?: 0),
                'counted_cash_amount' => $summary['counted_cash_amount'] ?? null,
                'difference_amount' => $summary['difference_amount'] ?? null,
                'sales_count' => (int) ($summary['sales_count'] ?? 0),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleListCashSessions(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $items = $service->listCashSessions($tenantId, $command, $appId !== '' ? $appId : null);
        $text = $items === []
            ? 'No hay sesiones de caja POS para los filtros indicados.'
            : "Sesiones de caja POS:\n" . implode("\n", array_map([$this, 'formatCashSessionLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'list_cash_sessions',
                'items' => $items,
                'result_count' => count($items),
                'cash_register_id' => trim((string) ($command['cash_register_id'] ?? '')),
                'result_status' => 'success',
            ])
        ));
    }

    private function draftId(array $command): string
    {
        $draftId = trim((string) ($command['draft_id'] ?? $command['sale_draft_id'] ?? ''));
        if ($draftId === '') {
            throw new RuntimeException('POS_DRAFT_ID_REQUIRED');
        }

        return $draftId;
    }

    private function sessionIdValue(array $command): string
    {
        $sessionId = trim((string) ($command['session_id'] ?? ''));
        if ($sessionId === '') {
            throw new RuntimeException('POS_SESSION_ID_REQUIRED');
        }

        return $sessionId;
    }

    private function saleId(array $command): string
    {
        $saleId = trim((string) ($command['sale_id'] ?? $command['id'] ?? ''));
        if ($saleId === '') {
            throw new RuntimeException('POS_SALE_ID_REQUIRED');
        }

        return $saleId;
    }

    private function returnId(array $command): string
    {
        $returnId = trim((string) ($command['return_id'] ?? $command['id'] ?? ''));
        if ($returnId === '') {
            throw new RuntimeException('POS_RETURN_ID_REQUIRED');
        }

        return $returnId;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSaleFromCommand(POSService $service, string $tenantId, string $appId, array $command): array
    {
        $saleId = trim((string) ($command['sale_id'] ?? $command['id'] ?? ''));
        if ($saleId !== '') {
            return $service->getSale($tenantId, $saleId, $appId !== '' ? $appId : null);
        }

        $saleNumber = trim((string) ($command['sale_number'] ?? $command['number'] ?? ''));
        if ($saleNumber !== '') {
            return $service->getSaleByNumber($tenantId, $saleNumber, $appId !== '' ? $appId : null);
        }

        throw new RuntimeException('POS_SALE_REFERENCE_REQUIRED');
    }

    private function replyCallable(array $context): callable
    {
        $callable = $context['reply'] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }

        return $callable;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function moduleData(array $overrides = []): array
    {
        return array_merge([
            'module_used' => 'pos',
            'pos_action' => 'none',
            'draft_id' => '',
            'sale_id' => '',
            'sale_number' => '',
            'return_id' => '',
            'return_number' => '',
            'session_id' => '',
            'cash_register_id' => '',
            'product_id' => '',
            'matched_product_id' => '',
            'matched_by' => '',
            'product_query' => '',
            'ambiguity_count' => 0,
            'line_count' => 0,
            'total' => 0,
            'reason' => '',
            'opening_amount' => 0,
            'counted_cash_amount' => null,
            'difference_amount' => null,
            'sales_count' => 0,
            'result_status' => 'success',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function withReplyText(array $response): array
    {
        if (!array_key_exists('reply', $response)) {
            $response['reply'] = (string) (($response['data']['reply'] ?? $response['message'] ?? ''));
        }

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
            // Usage metering is best effort and must not block POS operations.
        }
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function formatDraftLine(array $draft): string
    {
        return '- draft_id=' . (string) ($draft['id'] ?? '')
            . ' total=' . (string) ($draft['total'] ?? 0)
            . ' status=' . (string) ($draft['status'] ?? 'open');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatProductLine(array $item): string
    {
        $parts = ['- ' . $this->formatProductLabel($item)];
        if (trim((string) ($item['entity_id'] ?? $item['product_id'] ?? '')) !== '') {
            $parts[] = 'id=' . (string) ($item['entity_id'] ?? $item['product_id'] ?? '');
        }
        if (trim((string) ($item['matched_by'] ?? '')) !== '') {
            $parts[] = 'match=' . (string) $item['matched_by'];
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatProductLabel(array $item): string
    {
        $label = trim((string) ($item['label'] ?? $item['product_label'] ?? 'producto'));
        $subtitle = trim((string) ($item['subtitle'] ?? ''));
        if ($subtitle === '') {
            return $label;
        }

        return $label . ' | ' . $subtitle;
    }

    /**
     * @param array<string, mixed> $sale
     */
    private function formatSaleLine(array $sale): string
    {
        return '- sale_number=' . (string) ($sale['sale_number'] ?? '')
            . ' total=' . (string) ($sale['total'] ?? 0)
            . ' status=' . (string) ($sale['status'] ?? 'completed');
    }

    /**
     * @param array<string, mixed> $return
     */
    private function formatReturnLine(array $return): string
    {
        return '- return_number=' . (string) ($return['return_number'] ?? '')
            . ' total=' . (string) ($return['total'] ?? 0)
            . ' status=' . (string) ($return['status'] ?? 'completed');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function formatCashSessionLine(array $session): string
    {
        return '- session_id=' . (string) ($session['id'] ?? '')
            . ' cash_register_id=' . (string) ($session['cash_register_id'] ?? '')
            . ' status=' . (string) ($session['status'] ?? 'open')
            . ' opening=' . (string) (($session['opening_amount'] ?? 0) ?: 0);
    }

    private function humanizeError(string $message): string
    {
        $message = trim($message);

        return match ($message) {
            'POS_TENANT_ID_REQUIRED', 'Campo requerido faltante: tenant_id.' => 'Necesito el tenant actual para continuar.',
            'POS_DRAFT_ID_REQUIRED' => 'Indica `draft_id` para continuar con el borrador POS.',
            'POS_SESSION_ID_REQUIRED' => 'Indica `session_id` para continuar con la caja POS.',
            'POS_LINE_ID_REQUIRED' => 'Indica `line_id` para identificar la linea del borrador.',
            'POS_SESSION_NOT_FOUND' => 'No encontre esa sesion POS dentro del tenant actual.',
            'POS_SESSION_NOT_OPEN' => 'La sesion POS indicada no esta abierta.',
            'POS_DRAFT_NOT_FOUND' => 'No encontre ese borrador POS dentro del tenant actual.',
            'POS_DRAFT_NOT_OPEN' => 'Solo puedo modificar borradores POS en estado abierto.',
            'POS_DRAFT_EMPTY' => 'No puedo cerrar una venta POS sin productos.',
            'POS_DRAFT_TOTALS_INVALID' => 'Los totales del borrador POS no son consistentes. Recalcula antes de cerrar.',
            'POS_DRAFT_ALREADY_FINALIZED' => 'Ese borrador POS ya fue finalizado en una venta.',
            'POS_PRODUCT_REFERENCE_REQUIRED' => 'Indica un `product_id`, `sku`, `barcode` o `query` para agregar la linea.',
            'POS_PRODUCT_NOT_FOUND' => 'No encontre un producto valido dentro del tenant actual.',
            'POS_PRODUCT_AMBIGUOUS' => 'Encontre varios productos posibles. Indica el producto exacto.',
            'POS_PRODUCT_PRICE_UNAVAILABLE' => 'No pude resolver el precio del producto con seguridad.',
            'POS_OVERRIDE_PRICE_INVALID' => 'El `override_price` debe ser numerico.',
            'POS_CUSTOMER_REFERENCE_REQUIRED' => 'Indica `customer_id` o una referencia del cliente.',
            'POS_CUSTOMER_NOT_FOUND' => 'No encontre ese cliente dentro del tenant actual.',
            'POS_CUSTOMER_AMBIGUOUS' => 'Encontre varios clientes posibles. Indica el cliente exacto.',
            'POS_INVALID_QTY' => 'La cantidad debe ser mayor que cero.',
            'POS_DRAFT_LINE_NOT_FOUND' => 'No encontre esa linea dentro del borrador POS.',
            'POS_SALE_ID_REQUIRED' => 'Indica `sale_id` para continuar con la venta POS.',
            'POS_SALE_NUMBER_REQUIRED' => 'Indica `sale_number` para continuar con la venta POS.',
            'POS_SALE_REFERENCE_REQUIRED' => 'Indica `sale_id` o `sale_number` para ubicar la venta POS.',
            'POS_SALE_NOT_FOUND' => 'No encontre esa venta POS dentro del tenant actual.',
            'POS_SALE_ALREADY_CANCELED' => 'Esa venta POS ya fue cancelada.',
            'POS_SALE_NOT_CANCELABLE' => 'Solo puedo cancelar ventas POS completadas y sin devoluciones.',
            'POS_SALE_HAS_RETURNS' => 'No puedo cancelar una venta POS que ya tiene devoluciones registradas.',
            'POS_SALE_NOT_RETURNABLE' => 'Solo puedo registrar devoluciones sobre ventas POS completadas.',
            'POS_RETURN_ID_REQUIRED' => 'Indica `return_id` para continuar con la devolucion POS.',
            'POS_RETURN_NOT_FOUND' => 'No encontre esa devolucion POS dentro del tenant actual.',
            'POS_RETURN_EMPTY' => 'La devolucion POS no tiene lineas validas para procesar.',
            'POS_RETURN_LINE_REFERENCE_REQUIRED' => 'Indica `sale_line_id` o una referencia exacta de linea para devolver.',
            'POS_RETURN_LINE_NOT_FOUND' => 'No encontre la linea original de venta para esa devolucion POS.',
            'POS_RETURN_LINE_AMBIGUOUS' => 'La referencia de linea para la devolucion POS es ambigua. Indica `sale_line_id`.',
            'POS_RETURN_NO_REMAINING_QTY' => 'No queda cantidad disponible para devolver en esa linea POS.',
            'POS_RETURN_QTY_EXCEEDED' => 'La cantidad a devolver no puede superar la cantidad vendida pendiente.',
            'POS_CASH_REGISTER_ID_REQUIRED' => 'Indica `cash_register_id` para operar la caja POS.',
            'POS_OPENING_AMOUNT_REQUIRED' => 'Indica `opening_amount` para abrir la caja POS.',
            'POS_COUNTED_CASH_AMOUNT_REQUIRED' => 'Indica `counted_cash_amount` para cerrar la caja POS.',
            'POS_CASH_REGISTER_ALREADY_OPEN' => 'Ya existe una sesion de caja POS abierta para esa caja registradora.',
            'POS_OPEN_CASH_SESSION_NOT_FOUND' => 'No encontre una sesion de caja POS abierta para esa caja registradora.',
            'POS_CASH_SESSION_ALREADY_CLOSED' => 'Esa sesion de caja POS ya fue cerrada.',
            'POS_CASH_SESSION_NOT_OPEN' => 'Solo puedo cerrar sesiones de caja POS en estado abierto.',
            'COMMAND_NOT_SUPPORTED' => 'No pude ejecutar esa operacion POS.',
            default => $message !== '' ? $message : 'No pude procesar la operacion POS solicitada.',
        };
    }
}
