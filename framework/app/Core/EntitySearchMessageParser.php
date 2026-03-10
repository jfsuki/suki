<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use Throwable;

final class EntitySearchMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $telemetry = ['module_used' => 'entity_search', 'entity_search_action' => 'none'];
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'app_id' => trim((string) ($context['project_id'] ?? '')) ?: null,
            'requested_by_user_id' => trim((string) ($context['user_id'] ?? '')) ?: 'system',
        ];

        return match ($skillName) {
            'entity_search' => $this->parseSearch($message, $pairs, $baseCommand, $telemetry),
            'entity_resolve' => $this->parseResolve($message, $pairs, $baseCommand, $telemetry),
            default => ['kind' => 'ask_user', 'reply' => 'No pude interpretar la busqueda global.', 'telemetry' => $telemetry],
        };
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseSearch(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $filters = $this->filtersFromMessage($message, $pairs);
        $query = $this->queryText($message, $pairs);
        if ($query === '' && ($filters['recency_hint'] ?? null) === null && ($filters['date_from'] ?? null) === null) {
            return $this->askUser(
                'Indica una referencia corta para buscar. Ej: `SKU-001`, `arroz grande` o `ultima venta`.',
                $telemetry + ['entity_search_action' => 'search']
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'SearchEntities',
            'query' => $query,
            'filters' => $filters,
        ], $telemetry + ['entity_search_action' => 'search']);
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseResolve(string $message, array $pairs, array $baseCommand, array $telemetry): array
    {
        $filters = $this->filtersFromMessage($message, $pairs);
        $query = $this->queryText($message, $pairs);
        if ($query === '' && ($filters['recency_hint'] ?? null) === null && ($filters['date_from'] ?? null) === null) {
            return $this->askUser(
                'Necesito una referencia corta para resolver. Ej: `esa coca`, `ultima venta` o `factura de ayer`.',
                $telemetry + ['entity_search_action' => 'resolve']
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ResolveEntityReference',
            'query' => $query,
            'filters' => $filters,
        ], $telemetry + ['entity_search_action' => 'resolve']);
    }

    /**
     * @param array<string, string> $pairs
     * @return array<string, mixed>
     */
    private function filtersFromMessage(string $message, array $pairs): array
    {
        $entityType = EntitySearchSupport::normalizeEntityType(
            $this->firstValue($pairs, ['entity_type', 'tipo_entidad', 'entity', 'tipo'])
        );
        if ($entityType === null) {
            $entityType = EntitySearchSupport::inferEntityTypeFromText($message);
        }

        $recency = EntitySearchSupport::inferRecencyFilters($message);
        $dateFrom = $this->normalizeDateTime($this->firstValue($pairs, ['date_from', 'desde', 'from']));
        $dateTo = $this->normalizeDateTime($this->firstValue($pairs, ['date_to', 'hasta', 'to']), true);
        $recencyHint = trim((string) ($pairs['recency_hint'] ?? ''));
        if ($recencyHint === '') {
            $recencyHint = (string) ($recency['recency_hint'] ?? '');
        }
        if ($dateFrom === null) {
            $dateFrom = $recency['date_from'] ?? null;
        }
        if ($dateTo === null) {
            $dateTo = $recency['date_to'] ?? null;
        }

        return [
            'entity_type' => $entityType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'limit' => $this->int($this->firstValue($pairs, ['limit', 'top', 'max']), 5),
            'only_open' => $this->bool($this->firstValue($pairs, ['only_open', 'abiertas', 'abiertos']))
                || preg_match('/\b(abiertas|abiertos|open)\b/u', EntitySearchSupport::normalizeText($message)) === 1,
            'only_pending' => $this->bool($this->firstValue($pairs, ['only_pending', 'pendientes']))
                || preg_match('/\b(pendiente|pendientes|pending)\b/u', EntitySearchSupport::normalizeText($message)) === 1,
            'recency_hint' => $recencyHint !== '' ? $recencyHint : null,
        ];
    }

    /**
     * @param array<string, string> $pairs
     */
    private function queryText(string $message, array $pairs): string
    {
        $explicit = $this->firstValue($pairs, ['query', 'q', 'texto', 'text', 'referencia', 'reference', 'buscar']);
        if ($explicit !== '') {
            return trim($explicit);
        }

        $message = preg_replace('/([a-zA-Z_]+)=("([^"]*)"|\'([^\']*)\'|([^\s]+))/u', ' ', $message) ?? $message;
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;

        return trim($message);
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+)=("([^"]*)"|\'([^\']*)\'|([^\s]+))/u', $message, $matches, PREG_SET_ORDER);
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

    private function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'si', 'on'], true);
    }

    private function int($value, int $default): int
    {
        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    private function normalizeDateTime(?string $value, bool $endOfDay = false): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable(str_replace('T', ' ', $value));
        } catch (Throwable $e) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $date->format('Y-m-d') . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
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
