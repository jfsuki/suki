<?php

declare(strict_types=1);

namespace App\Core;

final class PurchasesMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $telemetry = ['module_used' => 'purchases', 'purchases_action' => 'none'];
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'app_id' => trim((string) ($context['project_id'] ?? '')) ?: null,
            'requested_by_user_id' => trim((string) ($context['user_id'] ?? '')) ?: 'system',
        ];

        return match ($skillName) {
            'purchases_create_draft' => $this->commandResult($baseCommand + ['command' => 'CreatePurchaseDraft', 'supplier_id' => $this->firstValue($pairs, ['supplier_id']), 'currency' => $this->firstValue($pairs, ['currency', 'moneda']), 'notes' => $this->firstValue($pairs, ['notes', 'nota'])], $this->telemetry($telemetry, 'create_draft')),
            'purchases_get_draft' => $this->parseGetDraft($pairs, $baseCommand, $telemetry),
            'purchases_add_draft_line' => $this->parseAddLine($message, $pairs, $baseCommand, $telemetry),
            'purchases_remove_draft_line' => $this->parseRemoveLine($pairs, $baseCommand, $telemetry),
            'purchases_attach_supplier' => $this->parseAttachSupplier($message, $pairs, $baseCommand, $telemetry),
            'purchases_finalize' => $this->parseFinalize($pairs, $baseCommand, $telemetry),
            'purchases_get_purchase' => $this->parseGetPurchase($pairs, $baseCommand, $telemetry),
            'purchases_list' => $this->commandResult($baseCommand + ['command' => 'ListPurchases', 'status' => $this->firstValue($pairs, ['status']), 'supplier_id' => $this->firstValue($pairs, ['supplier_id']), 'purchase_number' => $this->firstValue($pairs, ['purchase_number', 'number']), 'date_from' => $this->firstValue($pairs, ['date_from', 'desde']), 'date_to' => $this->firstValue($pairs, ['date_to', 'hasta']), 'limit' => $this->firstValue($pairs, ['limit']) ?: '10'], $this->telemetry($telemetry, 'list')),
            'purchases_get_by_number' => $this->parseGetByNumber($pairs, $baseCommand, $telemetry),
            default => ['kind' => 'ask_user', 'reply' => 'No pude interpretar la operacion de compras.', 'telemetry' => $telemetry],
        };
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetDraft(array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->firstValue($pairs, ['draft_id', 'purchase_draft_id']);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para cargar el borrador de compra.', $this->telemetry($telemetry, 'get_draft'));
        }

        return $this->commandResult($baseCommand + ['command' => 'GetPurchaseDraft', 'draft_id' => $draftId], $this->telemetry($telemetry, 'get_draft'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseAddLine(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->firstValue($pairs, ['draft_id', 'purchase_draft_id']);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para agregar una linea de compra.', $this->telemetry($telemetry, 'add_draft_line'));
        }
        $productId = $this->firstValue($pairs, ['product_id']);
        $query = $this->firstValue($pairs, ['query', 'product_query', 'sku', 'supplier_sku']);
        if ($productId === '' && $query === '') {
            if ($this->firstValue($pairs, ['product_label', 'label', 'descripcion']) === '') {
                return $this->askUser('Indica `product_label`, `product_id`, `sku`, `supplier_sku` o `query` para la linea.', $this->telemetry($telemetry, 'add_draft_line'));
            }
        }
        $unitCost = $this->firstValue($pairs, ['unit_cost', 'cost', 'costo']);
        if ($unitCost === '') {
            return $this->askUser('Indica `unit_cost` para registrar la linea de compra.', $this->telemetry($telemetry, 'add_draft_line'));
        }
        $productLabel = $this->firstValue($pairs, ['product_label', 'label', 'descripcion']);

        return $this->commandResult($baseCommand + [
            'command' => 'AddPurchaseDraftLine',
            'draft_id' => $draftId,
            'product_id' => $productId !== '' ? $productId : null,
            'query' => $query !== '' ? $query : null,
            'sku' => ($sku = $this->firstValue($pairs, ['sku'])) !== '' ? $sku : null,
            'supplier_sku' => ($supplierSku = $this->firstValue($pairs, ['supplier_sku', 'sku_proveedor'])) !== '' ? $supplierSku : null,
            'product_label' => $productLabel !== '' ? $productLabel : null,
            'qty' => $this->firstValue($pairs, ['qty', 'cantidad']) ?: '1',
            'unit_cost' => $unitCost,
            'tax_rate' => ($taxRate = $this->firstValue($pairs, ['tax_rate', 'iva'])) !== '' ? $taxRate : null,
        ], $this->telemetry($telemetry, 'add_draft_line'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRemoveLine(array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->firstValue($pairs, ['draft_id', 'purchase_draft_id']);
        $lineId = $this->firstValue($pairs, ['line_id']);
        if ($draftId === '' || $lineId === '') {
            return $this->askUser('Indica `draft_id` y `line_id` para eliminar la linea de compra.', $this->telemetry($telemetry, 'remove_draft_line'));
        }

        return $this->commandResult($baseCommand + ['command' => 'RemovePurchaseDraftLine', 'draft_id' => $draftId, 'line_id' => $lineId], $this->telemetry($telemetry, 'remove_draft_line'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseAttachSupplier(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->firstValue($pairs, ['draft_id', 'purchase_draft_id']);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para asociar el proveedor.', $this->telemetry($telemetry, 'attach_supplier'));
        }
        $supplierId = $this->firstValue($pairs, ['supplier_id']);
        $query = $this->firstValue($pairs, ['query', 'supplier_query', 'supplier', 'name', 'nombre']);
        if ($supplierId === '' && $query === '') {
            $query = $this->freeReference($message);
        }
        if ($supplierId === '' && $query === '') {
            return $this->askUser('Indica `supplier_id` o `query` para resolver el proveedor.', $this->telemetry($telemetry, 'attach_supplier'));
        }

        return $this->commandResult($baseCommand + ['command' => 'AttachPurchaseDraftSupplier', 'draft_id' => $draftId, 'supplier_id' => $supplierId !== '' ? $supplierId : null, 'query' => $query !== '' ? $query : null], $this->telemetry($telemetry, 'attach_supplier'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseFinalize(array $pairs, array $baseCommand, array $telemetry): array
    {
        $draftId = $this->firstValue($pairs, ['draft_id', 'purchase_draft_id']);
        if ($draftId === '') {
            return $this->askUser('Indica `draft_id` para finalizar la compra.', $this->telemetry($telemetry, 'finalize'));
        }

        return $this->commandResult($baseCommand + ['command' => 'FinalizePurchase', 'draft_id' => $draftId], $this->telemetry($telemetry, 'finalize'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetPurchase(array $pairs, array $baseCommand, array $telemetry): array
    {
        $purchaseId = $this->firstValue($pairs, ['purchase_id', 'id']);
        if ($purchaseId === '') {
            return $this->askUser('Indica `purchase_id` para cargar la compra.', $this->telemetry($telemetry, 'get_purchase'));
        }

        return $this->commandResult($baseCommand + ['command' => 'GetPurchase', 'purchase_id' => $purchaseId], $this->telemetry($telemetry, 'get_purchase'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetByNumber(array $pairs, array $baseCommand, array $telemetry): array
    {
        $purchaseNumber = $this->firstValue($pairs, ['purchase_number', 'number']);
        if ($purchaseNumber === '') {
            return $this->askUser('Indica `purchase_number` para buscar la compra.', $this->telemetry($telemetry, 'get_by_number'));
        }

        return $this->commandResult($baseCommand + ['command' => 'GetPurchaseByNumber', 'purchase_number' => $purchaseNumber], $this->telemetry($telemetry, 'get_by_number'));
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

    private function freeReference(string $message): string
    {
        $clean = preg_replace('/([a-zA-Z0-9_]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s]+)/u', ' ', $message) ?? $message;
        $clean = trim(preg_replace('/\s+/u', ' ', strtolower($clean)) ?? '');

        return $clean;
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
        return array_merge($telemetry, ['purchases_action' => $action]);
    }
}
