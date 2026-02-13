<?php
// app/Core/InvoiceMapper.php

namespace App\Core;

final class InvoiceMapper
{
    public function buildPayload(array $invoiceContract, array $record, array $gridItems = []): array
    {
        $mapping = $invoiceContract['mapping'] ?? [];
        $payload = [];

        foreach ($mapping as $key => $value) {
            if ($key === 'items') {
                continue;
            }
            $payload[$key] = $this->mapNode($value, $record, null);
        }

        if (isset($mapping['items']) && is_array($mapping['items'])) {
            $itemsCfg = $mapping['items'];
            $itemMap = $itemsCfg['map'] ?? [];
            $items = [];
            foreach ($gridItems as $item) {
                $items[] = $this->mapNode($itemMap, $record, $item);
            }
            $payload['items'] = $items;
        }

        return $payload;
    }

    private function mapNode($node, array $record, ?array $item)
    {
        if (is_array($node)) {
            $result = [];
            foreach ($node as $key => $value) {
                $result[$key] = $this->mapNode($value, $record, $item);
            }
            return $result;
        }

        if (is_string($node)) {
            return $this->resolveValue($node, $record, $item);
        }

        return $node;
    }

    private function resolveValue(string $value, array $record, ?array $item)
    {
        if (str_starts_with($value, 'field:')) {
            $path = substr($value, 6);
            return $this->getByPath($record, $path);
        }
        if (str_starts_with($value, 'item:')) {
            $path = substr($value, 5);
            return $item ? $this->getByPath($item, $path) : null;
        }
        if (str_starts_with($value, 'fixed:')) {
            return substr($value, 6);
        }
        if (str_starts_with($value, 'env:')) {
            $env = substr($value, 4);
            return getenv($env) ?: null;
        }
        return $value;
    }

    private function getByPath(array $data, string $path)
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        $segments = explode('.', $path);
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }
}
