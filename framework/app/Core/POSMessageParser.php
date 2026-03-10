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
            'pos_remove_draft_line' => $this->parseRemoveLine($pairs, $baseCommand, $telemetry),
            'pos_attach_customer' => $this->parseAttachCustomer($message, $pairs, $baseCommand, $telemetry),
            'pos_list_open_drafts' => $this->parseListOpenDrafts($pairs, $baseCommand, $telemetry),
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
