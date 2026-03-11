<?php

declare(strict_types=1);

namespace App\Core;

final class POSMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $telemetry = ['module_used' => 'pos', 'pos_action' => 'none'];
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'app_id' => trim((string) ($context['project_id'] ?? '')) ?: null,
            'requested_by_user_id' => trim((string) ($context['user_id'] ?? '')) ?: 'system',
        ];

        return match ($skillName) {
            'pos_create_draft' => $this->parseCreateDraft($pairs, $baseCommand, $telemetry),
            'pos_get_draft' => $this->parseGetDraft($pairs, $baseCommand, $telemetry),
            'pos_add_draft_line' => $this->parseAddLine($message, $pairs, $baseCommand, $telemetry),
            'pos_add_line_by_reference' => $this->parseAddLineByReference($message, $pairs, $baseCommand, $telemetry),
            'pos_remove_draft_line' => $this->parseRemoveLine($pairs, $baseCommand, $telemetry),
            'pos_attach_customer' => $this->parseAttachCustomer($message, $pairs, $baseCommand, $telemetry),
            'pos_list_open_drafts' => $this->parseListOpenDrafts($pairs, $baseCommand, $telemetry),
            'pos_find_product' => $this->parseFindProduct($message, $pairs, $baseCommand, $telemetry),
            'pos_get_product_candidates' => $this->parseGetProductCandidates($message, $pairs, $baseCommand, $telemetry),
            'pos_reprice_draft' => $this->parseRepriceDraft($pairs, $baseCommand, $telemetry),
            'pos_finalize_sale' => $this->parseFinalizeSale($pairs, $baseCommand, $telemetry),
            'pos_get_sale' => $this->parseGetSale($pairs, $baseCommand, $telemetry),
            'pos_list_sales' => $this->parseListSales($message, $pairs, $baseCommand, $telemetry),
            'pos_build_receipt' => $this->parseBuildReceipt($pairs, $baseCommand, $telemetry),
            'pos_get_sale_by_number' => $this->parseGetSaleByNumber($pairs, $baseCommand, $telemetry),
            'pos_open_cash_register' => $this->parseOpenCashRegister($pairs, $baseCommand, $telemetry),
            'pos_get_open_cash_session' => $this->parseGetOpenCashSession($pairs, $baseCommand, $telemetry),
            'pos_close_cash_register' => $this->parseCloseCashRegister($pairs, $baseCommand, $telemetry),
            'pos_build_cash_summary' => $this->parseBuildCashSummary($pairs, $baseCommand, $telemetry),
            'pos_list_cash_sessions' => $this->parseListCashSessions($message, $pairs, $baseCommand, $telemetry),
            default => ['kind' => 'ask_user', 'reply' => 'No pude interpretar la operacion POS.', 'telemetry' => $telemetry],
        };
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCreateDraft(array $pairs, array $baseCommand, array $telemetry): array
    {
        return $this->commandResult($baseCommand + [
            'command' => 'CreatePOSDraft',
            'session_id' => $this->firstValue($pairs, ['session_id']),
            'customer_id' => $this->firstValue($pairs, ['customer_id']),
            'currency' => $this->firstValue($pairs, ['currency', 'moneda']),
        ], $telemetry + ['pos_action' => 'create_draft']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetDraft(array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->draftId($pairs);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para cargar el borrador POS.', $telemetry + ['pos_action' => 'get_draft']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetPOSDraft',
            'draft_id' => $draftId,
        ], $telemetry + ['pos_action' => 'get_draft']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseAddLine(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->draftId($pairs);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para agregar una linea POS.', $telemetry + ['pos_action' => 'add_draft_line']);
        }

        $productId = $this->firstValue($pairs, ['product_id']);
        $query = $this->productQuery($message, $pairs);
        if ($productId === '' && $query === '') {
            return $this->askUser('Indica `product_id`, `sku`, `barcode` o `query` para resolver el producto.', $telemetry + ['pos_action' => 'add_draft_line']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'AddPOSDraftLine',
            'draft_id' => $draftId,
            'product_id' => $productId !== '' ? $productId : null,
            'query' => $query !== '' ? $query : null,
            'sku' => $this->firstValue($pairs, ['sku']),
            'barcode' => $this->firstValue($pairs, ['barcode']),
            'qty' => $this->firstValue($pairs, ['qty', 'cantidad']) ?: '1',
        ], $telemetry + ['pos_action' => 'add_draft_line']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseAddLineByReference(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->draftId($pairs);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para agregar la linea por referencia.', $telemetry + ['pos_action' => 'add_line_by_reference']);
        }

        $query = $this->productQuery($message, $pairs);
        $productId = $this->firstValue($pairs, ['product_id']);
        if ($productId === '' && $query === '') {
            return $this->askUser('Indica `product_id`, `sku`, `barcode` o `query` para resolver el producto.', $telemetry + ['pos_action' => 'add_line_by_reference']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'AddPOSLineByReference',
            'draft_id' => $draftId,
            'product_id' => $productId !== '' ? $productId : null,
            'query' => $query !== '' ? $query : null,
            'sku' => $this->firstValue($pairs, ['sku']),
            'barcode' => $this->firstValue($pairs, ['barcode']),
            'qty' => $this->firstValue($pairs, ['qty', 'cantidad']) ?: '1',
            'override_price' => $this->firstValue($pairs, ['override_price', 'precio_override']),
        ], $telemetry + ['pos_action' => 'add_line_by_reference']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRemoveLine(array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->draftId($pairs);
        $lineId = $this->firstValue($pairs, ['line_id']);
        if ($draftId === '' || $lineId === '') {
            return $this->askUser('Indica `draft_id` y `line_id` para eliminar la linea POS.', $telemetry + ['pos_action' => 'remove_draft_line']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'RemovePOSDraftLine',
            'draft_id' => $draftId,
            'line_id' => $lineId,
        ], $telemetry + ['pos_action' => 'remove_draft_line']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseAttachCustomer(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->draftId($pairs);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para asociar el cliente.', $telemetry + ['pos_action' => 'attach_customer']);
        }

        $customerId = $this->firstValue($pairs, ['customer_id']);
        $query = $this->customerQuery($message, $pairs);
        if ($customerId === '' && $query === '') {
            return $this->askUser('Indica `customer_id` o `query` para resolver el cliente.', $telemetry + ['pos_action' => 'attach_customer']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'AttachPOSDraftCustomer',
            'draft_id' => $draftId,
            'customer_id' => $customerId !== '' ? $customerId : null,
            'query' => $query !== '' ? $query : null,
        ], $telemetry + ['pos_action' => 'attach_customer']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListOpenDrafts(array $pairs, array $baseCommand, array $telemetry): array
    {
        return $this->commandResult($baseCommand + [
            'command' => 'ListPOSOpenDrafts',
            'limit' => $this->firstValue($pairs, ['limit', 'top', 'max']) ?: '10',
        ], $telemetry + ['pos_action' => 'list_open_drafts']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseFindProduct(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $productId = $this->firstValue($pairs, ['product_id']);
        $query = $this->productQuery($message, $pairs);
        if ($productId === '' && $query === '') {
            return $this->askUser('Indica `product_id`, `sku`, `barcode` o `query` para buscar el producto.', $telemetry + ['pos_action' => 'find_product']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'FindPOSProduct',
            'product_id' => $productId !== '' ? $productId : null,
            'query' => $query !== '' ? $query : null,
            'sku' => $this->firstValue($pairs, ['sku']),
            'barcode' => $this->firstValue($pairs, ['barcode']),
            'limit' => $this->firstValue($pairs, ['limit', 'top', 'max']) ?: '5',
        ], $telemetry + ['pos_action' => 'find_product']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetProductCandidates(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $query = $this->productQuery($message, $pairs);
        if ($query === '') {
            return $this->askUser('Indica `sku`, `barcode` o `query` para listar candidatos.', $telemetry + ['pos_action' => 'get_product_candidates']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetPOSProductCandidates',
            'query' => $query,
            'sku' => $this->firstValue($pairs, ['sku']),
            'barcode' => $this->firstValue($pairs, ['barcode']),
            'limit' => $this->firstValue($pairs, ['limit', 'top', 'max']) ?: '5',
        ], $telemetry + ['pos_action' => 'get_product_candidates']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRepriceDraft(array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->draftId($pairs);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para recalcular el borrador POS.', $telemetry + ['pos_action' => 'reprice_draft']);
        }

        $lineId = $this->firstValue($pairs, ['line_id']);
        $qty = $this->firstValue($pairs, ['qty', 'cantidad']);
        $overridePrice = $this->firstValue($pairs, ['override_price', 'precio_override']);
        $taxRate = $this->firstValue($pairs, ['tax_rate', 'iva_rate']);

        return $this->commandResult($baseCommand + [
            'command' => 'RepricePOSDraft',
            'draft_id' => $draftId,
            'line_id' => $lineId !== '' ? $lineId : null,
            'qty' => $qty !== '' ? $qty : null,
            'override_price' => $overridePrice !== '' ? $overridePrice : null,
            'tax_rate' => $taxRate !== '' ? $taxRate : null,
        ], $telemetry + ['pos_action' => 'reprice_draft']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseFinalizeSale(array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->draftId($pairs);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para finalizar la venta POS.', $telemetry + ['pos_action' => 'finalize_sale']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'FinalizePOSSale',
            'draft_id' => $draftId,
        ], $telemetry + ['pos_action' => 'finalize_sale']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetSale(array $pairs, array $baseCommand, array $telemetry): array
    {
        $saleId = $this->saleId($pairs);
        if ($saleId === '') {
            return $this->askUser('Indica `sale_id` para cargar la venta POS.', $telemetry + ['pos_action' => 'get_sale']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetPOSSale',
            'sale_id' => $saleId,
        ], $telemetry + ['pos_action' => 'get_sale']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListSales(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $limit = $this->firstValue($pairs, ['limit', 'top', 'max']);
        if ($limit === '' && $this->containsAny($message, ['ultima', 'última', 'ultimo', 'último', 'last', 'latest', 'reciente'])) {
            $limit = '1';
        }

        $dateFrom = $this->firstValue($pairs, ['date_from', 'desde']);
        $dateTo = $this->firstValue($pairs, ['date_to', 'hasta']);
        if ($dateFrom === '' && $dateTo === '' && $this->containsAny($message, ['ayer'])) {
            $dateFrom = date('Y-m-d 00:00:00', strtotime('yesterday'));
            $dateTo = date('Y-m-d 23:59:59', strtotime('yesterday'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListPOSSales',
            'sale_number' => $this->saleNumber($pairs) ?: null,
            'status' => $this->firstValue($pairs, ['status', 'estado']) ?: null,
            'session_id' => $this->firstValue($pairs, ['session_id']) ?: null,
            'customer_id' => $this->firstValue($pairs, ['customer_id']) ?: null,
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to' => $dateTo !== '' ? $dateTo : null,
            'limit' => $limit !== '' ? $limit : '10',
        ], $telemetry + ['pos_action' => 'list_sales']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseBuildReceipt(array $pairs, array $baseCommand, array $telemetry): array
    {
        $saleId = $this->saleId($pairs);
        $saleNumber = $this->saleNumber($pairs);
        if ($saleId === '' && $saleNumber === '') {
            return $this->askUser('Indica `sale_id` o `sale_number` para preparar el ticket POS.', $telemetry + ['pos_action' => 'build_receipt']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'BuildPOSReceipt',
            'sale_id' => $saleId !== '' ? $saleId : null,
            'sale_number' => $saleNumber !== '' ? $saleNumber : null,
        ], $telemetry + ['pos_action' => 'build_receipt']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetSaleByNumber(array $pairs, array $baseCommand, array $telemetry): array
    {
        $saleNumber = $this->saleNumber($pairs);
        if ($saleNumber === '') {
            return $this->askUser('Indica `sale_number` para cargar la venta POS.', $telemetry + ['pos_action' => 'get_sale_by_number']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetPOSSaleByNumber',
            'sale_number' => $saleNumber,
        ], $telemetry + ['pos_action' => 'get_sale_by_number']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseOpenCashRegister(array $pairs, array $baseCommand, array $telemetry): array
    {
        $cashRegisterId = $this->cashRegisterId($pairs);
        if ($cashRegisterId === '') {
            return $this->askUser('Indica `cash_register_id` para abrir la caja POS.', $telemetry + ['pos_action' => 'open_cash_register']);
        }

        $openingAmount = $this->firstValue($pairs, ['opening_amount', 'monto_inicial']);
        if ($openingAmount === '') {
            return $this->askUser('Indica `opening_amount` para abrir la caja POS.', $telemetry + ['pos_action' => 'open_cash_register']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'OpenPOSCashRegister',
            'cash_register_id' => $cashRegisterId,
            'opening_amount' => $openingAmount,
            'store_id' => $this->firstValue($pairs, ['store_id']),
            'notes' => $this->firstValue($pairs, ['notes', 'nota']),
        ], $telemetry + ['pos_action' => 'open_cash_register']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetOpenCashSession(array $pairs, array $baseCommand, array $telemetry): array
    {
        $cashRegisterId = $this->cashRegisterId($pairs);
        if ($cashRegisterId === '') {
            return $this->askUser('Indica `cash_register_id` para consultar la caja POS abierta.', $telemetry + ['pos_action' => 'get_open_cash_session']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetPOSOpenCashSession',
            'cash_register_id' => $cashRegisterId,
        ], $telemetry + ['pos_action' => 'get_open_cash_session']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCloseCashRegister(array $pairs, array $baseCommand, array $telemetry): array
    {
        $sessionId = $this->sessionId($pairs);
        if ($sessionId === '') {
            return $this->askUser('Indica `session_id` para cerrar la caja POS.', $telemetry + ['pos_action' => 'close_cash_register']);
        }

        $countedCashAmount = $this->firstValue($pairs, ['counted_cash_amount', 'counted_amount', 'monto_contado']);
        if ($countedCashAmount === '') {
            return $this->askUser('Indica `counted_cash_amount` para cerrar la caja POS.', $telemetry + ['pos_action' => 'close_cash_register']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ClosePOSCashRegister',
            'session_id' => $sessionId,
            'counted_cash_amount' => $countedCashAmount,
            'notes' => $this->firstValue($pairs, ['notes', 'nota']),
        ], $telemetry + ['pos_action' => 'close_cash_register']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseBuildCashSummary(array $pairs, array $baseCommand, array $telemetry): array
    {
        $sessionId = $this->sessionId($pairs);
        if ($sessionId === '') {
            return $this->askUser('Indica `session_id` para preparar el arqueo POS.', $telemetry + ['pos_action' => 'build_cash_summary']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'BuildPOSCashSummary',
            'session_id' => $sessionId,
        ], $telemetry + ['pos_action' => 'build_cash_summary']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListCashSessions(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $status = $this->firstValue($pairs, ['status', 'estado']);
        if ($status === '') {
            if ($this->containsAny($message, ['abierta', 'abiertas', 'open'])) {
                $status = 'open';
            } elseif ($this->containsAny($message, ['cerrada', 'cerradas', 'closed'])) {
                $status = 'closed';
            }
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListPOSCashSessions',
            'cash_register_id' => $this->cashRegisterId($pairs) ?: null,
            'status' => $status !== '' ? $status : null,
            'date_from' => $this->firstValue($pairs, ['date_from', 'desde']) ?: null,
            'date_to' => $this->firstValue($pairs, ['date_to', 'hasta']) ?: null,
            'limit' => $this->firstValue($pairs, ['limit', 'top', 'max']) ?: '10',
        ], $telemetry + ['pos_action' => 'list_cash_sessions']);
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+)=(\"([^\"]*)\"|\'([^\']*)\'|([^\s]+))/u', $message, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = strtolower(trim((string) ($match[1] ?? '')));
            $value = '';
            foreach ([3, 4, 5] as $index) {
                if (isset($match[$index]) && $match[$index] !== '') {
                    $value = trim((string) $match[$index]);
                    break;
                }
            }
            if ($key !== '' && $value !== '') {
                $pairs[$key] = $value;
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, string> $pairs
     * @param array<int, string> $aliases
     */
    private function firstValue(array $pairs, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $alias = strtolower(trim($alias));
            if ($alias !== '' && array_key_exists($alias, $pairs)) {
                return trim((string) $pairs[$alias]);
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $pairs
     */
    private function draftId(array $pairs): string
    {
        return $this->firstValue($pairs, ['draft_id', 'sale_draft_id']);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function saleId(array $pairs): string
    {
        return $this->firstValue($pairs, ['sale_id', 'id']);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function saleNumber(array $pairs): string
    {
        return $this->firstValue($pairs, ['sale_number', 'number', 'numero']);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function sessionId(array $pairs): string
    {
        return $this->firstValue($pairs, ['session_id']);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function cashRegisterId(array $pairs): string
    {
        return $this->firstValue($pairs, ['cash_register_id', 'register_id', 'caja_id', 'codigo', 'code']);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function productQuery(string $message, array $pairs): string
    {
        foreach (['query', 'product_query', 'product_reference', 'sku', 'barcode', 'reference', 'product'] as $key) {
            $value = $this->firstValue($pairs, [$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return $this->freeReference($message, [
            'agrega', 'agregar', 'add', 'anade', 'añade', 'linea', 'line', 'borrador', 'draft',
            'carrito', 'pos', 'producto', 'product', 'item', 'qty', 'cantidad', 'venta',
        ]);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function customerQuery(string $message, array $pairs): string
    {
        foreach (['query', 'customer_query', 'customer_reference', 'customer'] as $key) {
            $value = $this->firstValue($pairs, [$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return $this->freeReference($message, [
            'asocia', 'asociar', 'attach', 'cliente', 'customer', 'borrador', 'draft', 'pos',
        ]);
    }

    /**
     * @param array<int, string> $stopwords
     */
    private function freeReference(string $message, array $stopwords): string
    {
        $message = preg_replace('/([a-zA-Z_]+)=(\"([^\"]*)\"|\'([^\']*)\'|([^\s]+))/u', ' ', $message) ?? $message;
        $message = mb_strtolower(trim($message), 'UTF-8');
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
        $tokens = preg_split('/\s+/u', $message) ?: [];
        $kept = [];
        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, $stopwords, true)) {
                continue;
            }
            $kept[] = $token;
        }

        return trim(implode(' ', $kept));
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $message, array $needles): bool
    {
        $message = mb_strtolower($message, 'UTF-8');
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($message, mb_strtolower($needle, 'UTF-8'))) {
                return true;
            }
        }

        return false;
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
}
