<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use Throwable;

final class MediaMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $attachments = $this->normalizeAttachments($context['attachments'] ?? []);
        $telemetry = ['module_used' => 'media_storage', 'media_action' => 'none'];
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'app_id' => trim((string) ($context['project_id'] ?? '')) ?: null,
            'uploaded_by_user_id' => trim((string) ($context['user_id'] ?? '')) ?: 'system',
            'metadata' => [
                'origin' => 'skill',
                'skill_name' => $skillName,
                'channel' => trim((string) ($context['channel'] ?? 'local')) ?: 'local',
                'message_id' => trim((string) ($context['message_id'] ?? '')),
                'attachments_count' => count($attachments),
            ],
        ];

        return match ($skillName) {
            'media_upload' => $this->parseUpload($message, $pairs, $attachments, $baseCommand, $telemetry),
            'media_list' => $this->parseList($message, $pairs, $baseCommand, $telemetry),
            'media_get' => $this->parseGet($pairs, $baseCommand, $telemetry),
            'media_delete' => $this->parseDelete($pairs, $baseCommand, $telemetry),
            default => ['kind' => 'ask_user', 'reply' => 'No pude interpretar la operacion de archivos.', 'telemetry' => $telemetry],
        };
    }

    /**
     * @param array<string, string> $pairs
     * @param array<int, array<string, mixed>> $attachments
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseUpload(string $message, array $pairs, array $attachments, array $baseCommand, array $telemetry): array
    {
        if ($attachments === []) {
            return $this->askUser('Necesito el archivo para subirlo.', $telemetry + ['media_action' => 'upload']);
        }

        $entityId = $this->entityId($pairs);
        if ($entityId === '') {
            return $this->askUser('Indica `entity_id` para vincular el archivo.', $telemetry + ['media_action' => 'upload']);
        }

        $attachment = $attachments[0];
        $command = $baseCommand + [
            'command' => 'UploadMedia',
            'entity_type' => $this->entityType($message, $pairs),
            'entity_id' => $entityId,
            'file' => $attachment,
            'source_path' => $this->first([$attachment['source_path'] ?? null, $attachment['tmp_path'] ?? null, $attachment['path'] ?? null]),
            'original_name' => $this->first([$attachment['original_name'] ?? null, $attachment['name'] ?? null]),
            'mime_type' => $this->first([$attachment['mime_type'] ?? null, $attachment['type'] ?? null]),
            'file_size' => $attachment['file_size'] ?? $attachment['size'] ?? null,
        ];

        return $this->commandResult($command, $telemetry + ['media_action' => 'upload']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseList(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $entityId = $this->entityId($pairs);
        if ($entityId === '') {
            return $this->askUser('Indica `entity_id` para listar los archivos.', $telemetry + ['media_action' => 'list']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListMedia',
            'entity_type' => $this->entityType($message, $pairs),
            'entity_id' => $entityId,
            'limit' => $this->int($pairs['limit'] ?? null, 50),
            'offset' => $this->int($pairs['offset'] ?? null, 0),
        ], $telemetry + ['media_action' => 'list']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGet(array $pairs, array $baseCommand, array $telemetry): array
    {
        $mediaId = $this->firstValue($pairs, ['media_id', 'id']);
        if ($mediaId === '') {
            return $this->askUser('Indica `media_id` para abrir el archivo.', $telemetry + ['media_action' => 'get']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetMedia',
            'media_id' => $mediaId,
            'variant' => $this->firstValue($pairs, ['variant', 'tamano']) ?: 'original',
        ], $telemetry + ['media_action' => 'get']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseDelete(array $pairs, array $baseCommand, array $telemetry): array
    {
        $mediaId = $this->firstValue($pairs, ['media_id', 'id']);
        if ($mediaId === '') {
            return $this->askUser('Indica `media_id` para eliminar el archivo.', $telemetry + ['media_action' => 'delete']);
        }
        if (!$this->confirmed($pairs)) {
            return $this->askUser('Confirma con `confirmar=si` para eliminar el archivo.', $telemetry + ['media_action' => 'delete']);
        }

        return $this->commandResult($baseCommand + [
            'command' => 'DeleteMedia',
            'media_id' => $mediaId,
            'confirmed' => true,
        ], $telemetry + ['media_action' => 'delete']);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function entityType(string $message, array $pairs): string
    {
        $explicit = strtolower(trim($this->firstValue($pairs, ['entity_type', 'tipo_entidad', 'entidad_tipo'])));
        if ($explicit !== '') {
            return $this->entityAlias($explicit);
        }

        $message = $this->text($message);
        foreach ([
            'product' => ['producto', 'productos', 'product', 'sku', 'item'],
            'purchase' => ['compra', 'compras', 'purchase', 'orden_compra'],
            'invoice' => ['factura', 'facturas', 'invoice', 'xml', 'dian'],
            'sale' => ['venta', 'ventas', 'sale', 'pedido'],
            'customer' => ['cliente', 'clientes', 'customer', 'tercero'],
            'supplier' => ['proveedor', 'proveedores', 'supplier'],
        ] as $entityType => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/(?:^|\\b)' . preg_quote($alias, '/') . '(?:$|\\b)/u', $message) === 1) {
                    return $entityType;
                }
            }
        }

        return 'general';
    }

    /**
     * @param array<string, string> $pairs
     */
    private function entityId(array $pairs): string
    {
        return $this->firstValue($pairs, ['entity_id', 'entidad_id', 'product_id', 'purchase_id', 'invoice_id', 'sale_id', 'customer_id', 'supplier_id']);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function confirmed(array $pairs): bool
    {
        return in_array(strtolower(trim($this->firstValue($pairs, ['confirmar', 'confirm', 'confirmado']))), ['1', 'si', 'sí', 'true', 'yes', 'ok'], true);
    }

    /**
     * @param mixed $attachments
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAttachments($attachments): array
    {
        if (!is_array($attachments)) {
            return [];
        }

        $normalized = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $sourcePath = $this->first([$attachment['source_path'] ?? null, $attachment['tmp_path'] ?? null, $attachment['path'] ?? null]);
            if ($sourcePath === '') {
                continue;
            }
            $normalized[] = [
                'source_path' => $sourcePath,
                'tmp_path' => $this->first([$attachment['tmp_path'] ?? null]),
                'path' => $this->first([$attachment['path'] ?? null, $sourcePath]),
                'original_name' => $this->first([$attachment['original_name'] ?? null, $attachment['name'] ?? null]),
                'name' => $this->first([$attachment['name'] ?? null, basename($sourcePath)]),
                'mime_type' => $this->first([$attachment['mime_type'] ?? null, $attachment['type'] ?? null]),
                'type' => $this->first([$attachment['type'] ?? null, $attachment['mime_type'] ?? null]),
                'file_size' => $this->int($attachment['file_size'] ?? $attachment['size'] ?? null, 0),
                'size' => $this->int($attachment['size'] ?? $attachment['file_size'] ?? null, 0),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+)=("([^"]*)"|\'([^\']*)\'|([^\\s]+))/u', $message, $matches, PREG_SET_ORDER);
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
     * @param array<int, mixed> $values
     */
    private function first(array $values): string
    {
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    private function entityAlias(string $value): string
    {
        return match ($value) {
            'producto', 'productos', 'product', 'sku', 'item' => 'product',
            'compra', 'compras', 'purchase', 'orden_compra' => 'purchase',
            'factura', 'facturas', 'invoice', 'xml', 'dian' => 'invoice',
            'venta', 'ventas', 'sale', 'pedido' => 'sale',
            'cliente', 'clientes', 'customer', 'tercero' => 'customer',
            'proveedor', 'proveedores', 'supplier' => 'supplier',
            default => in_array($value, ['product', 'purchase', 'invoice', 'sale', 'customer', 'supplier', 'general'], true) ? $value : 'general',
        };
    }

    private function text(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function int($value, int $default): int
    {
        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    private function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable(str_replace('T', ' ', $value));
        } catch (Throwable $e) {
            return null;
        }
        return $date->format('Y-m-d H:i:s');
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
