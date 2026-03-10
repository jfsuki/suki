<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class EntitySearchService
{
    private EntitySearchRepository $repository;
    private AuditLogger $auditLogger;
    private EntitySearchEventLogger $eventLogger;

    public function __construct(
        ?EntitySearchRepository $repository = null,
        ?AuditLogger $auditLogger = null,
        ?EntitySearchEventLogger $eventLogger = null
    ) {
        $this->repository = $repository ?? new EntitySearchRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->eventLogger = $eventLogger ?? new EntitySearchEventLogger();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function search(string $tenantId, string $query, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        [$query, $filters] = $this->normalizeSearchRequest($query, $filters, $appId);
        $results = $this->repository->search($tenantId, $query, $filters);
        $summary = $this->summaryFromResults($results);
        $latencyMs = $this->latencyMs($startedAt);

        $payload = [
            'tenant_id' => $tenantId,
            'query' => $query,
            'filters' => $filters,
            'result_count' => count($results),
            'selected_entity_type' => $summary['selected_entity_type'],
            'selected_entity_id' => $summary['selected_entity_id'],
            'latency_ms' => $latencyMs,
        ];
        $this->auditLogger->log('entity_search', 'entity_search', null, $payload);
        $this->eventLogger->log('search', $tenantId, $payload);

        return [
            'query' => $query,
            'filters' => $filters,
            'results' => $results,
            'result_count' => count($results),
            'selected_entity_type' => $summary['selected_entity_type'],
            'selected_entity_id' => $summary['selected_entity_id'],
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function resolveBestMatch(string $tenantId, string $query, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        [$query, $filters] = $this->normalizeSearchRequest($query, $filters, $appId);
        $search = $this->search($tenantId, $query, $filters, $appId);
        $results = is_array($search['results'] ?? null) ? (array) $search['results'] : [];

        $resolved = false;
        $selected = null;
        if ($results !== []) {
            $top = is_array($results[0] ?? null) ? (array) $results[0] : [];
            $next = is_array($results[1] ?? null) ? (array) $results[1] : [];
            $topScore = (float) ($top['score'] ?? 0);
            $nextScore = (float) ($next['score'] ?? 0);
            $matchedBy = trim((string) ($top['matched_by'] ?? ''));
            $hasRecencyHint = trim((string) ($filters['recency_hint'] ?? '')) !== '';

            if ($hasRecencyHint || count($results) === 1) {
                $resolved = true;
                $selected = $top;
            } elseif ($topScore >= 900 && ($topScore - $nextScore) >= 40) {
                $resolved = true;
                $selected = $top;
            } elseif (str_contains($matchedBy, 'exact') && ($topScore - $nextScore) >= 20) {
                $resolved = true;
                $selected = $top;
            }
        }

        $latencyMs = $this->latencyMs($startedAt);
        $selectedEntityType = is_array($selected) ? (string) ($selected['entity_type'] ?? '') : '';
        $selectedEntityId = is_array($selected) ? (string) ($selected['entity_id'] ?? '') : '';
        $this->eventLogger->log('resolve', $tenantId, [
            'query' => $query,
            'filters' => $filters,
            'result_count' => count($results),
            'selected_entity_type' => $selectedEntityType,
            'selected_entity_id' => $selectedEntityId,
            'latency_ms' => $latencyMs,
        ]);

        return [
            'query' => $query,
            'filters' => $filters,
            'resolved' => $resolved,
            'result' => $resolved ? $selected : null,
            'candidates' => $resolved ? [] : array_slice($results, 0, $this->intFilter($filters['limit'] ?? null, 5, 1, 10)),
            'result_count' => count($results),
            'selected_entity_type' => $selectedEntityType !== '' ? $selectedEntityType : (string) ($search['selected_entity_type'] ?? ''),
            'selected_entity_id' => $selectedEntityId !== '' ? $selectedEntityId : (string) ($search['selected_entity_id'] ?? ''),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function getByReference(string $tenantId, string $entityType, string $entityId, array $filters = [], ?string $appId = null): ?array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $entityType = EntitySearchSupport::normalizeEntityType($entityType)
            ?? throw new RuntimeException('ENTITY_SEARCH_ENTITY_TYPE_INVALID');
        $entityId = $this->requireString($entityId, 'entity_id');
        $filters = $this->normalizeFiltersOnly($filters, $appId);

        $result = $this->repository->getByReference($tenantId, $entityType, $entityId, $filters);
        $this->eventLogger->log('get_by_reference', $tenantId, [
            'query' => $entityType . ':' . $entityId,
            'filters' => $filters,
            'result_count' => $result === null ? 0 : 1,
            'selected_entity_type' => $result['entity_type'] ?? '',
            'selected_entity_id' => $result['entity_id'] ?? '',
            'latency_ms' => 0,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function normalizeSearchRequest(string $query, array $filters, ?string $appId): array
    {
        $query = trim($query);
        $filters = $this->normalizeFiltersOnly($filters, $appId);

        $inferredType = EntitySearchSupport::inferEntityTypeFromText($query);
        if (($filters['entity_type'] ?? null) === null && $inferredType !== null) {
            $filters['entity_type'] = $inferredType;
        }

        $recency = EntitySearchSupport::inferRecencyFilters($query);
        if (($filters['date_from'] ?? null) === null && ($recency['date_from'] ?? null) !== null) {
            $filters['date_from'] = $recency['date_from'];
        }
        if (($filters['date_to'] ?? null) === null && ($recency['date_to'] ?? null) !== null) {
            $filters['date_to'] = $recency['date_to'];
        }
        if (($filters['recency_hint'] ?? null) === null && ($recency['recency_hint'] ?? null) !== null) {
            $filters['recency_hint'] = $recency['recency_hint'];
        }

        $query = $this->normalizeQueryText($query, (string) ($filters['entity_type'] ?? ''));
        if ($query === '' && ($filters['recency_hint'] ?? null) === null && ($filters['date_from'] ?? null) === null && ($filters['date_to'] ?? null) === null) {
            throw new RuntimeException('ENTITY_SEARCH_QUERY_REQUIRED');
        }

        return [$query, $filters];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFiltersOnly(array $filters, ?string $appId): array
    {
        $normalized = [
            'entity_type' => EntitySearchSupport::normalizeEntityType((string) ($filters['entity_type'] ?? '')),
            'date_from' => $this->normalizeDateTime($filters['date_from'] ?? null),
            'date_to' => $this->normalizeDateTime($filters['date_to'] ?? null, true),
            'limit' => $this->intFilter($filters['limit'] ?? null, 5, 1, 25),
            'only_open' => $this->boolFilter($filters['only_open'] ?? false),
            'only_pending' => $this->boolFilter($filters['only_pending'] ?? false),
            'recency_hint' => $this->nullableString($filters['recency_hint'] ?? null),
        ];

        $resolvedAppId = $this->nullableString($filters['app_id'] ?? $filters['project_id'] ?? $appId);
        if ($resolvedAppId !== null) {
            $normalized['app_id'] = $resolvedAppId;
        }

        return $normalized;
    }

    private function normalizeQueryText(string $query, string $entityType = ''): string
    {
        $normalized = EntitySearchSupport::normalizeText($query);
        if ($normalized === '') {
            return '';
        }

        $stopwords = [
            'busca', 'buscar', 'encuentra', 'encontrar', 'localiza', 'consulta', 'mostrar', 'muestra',
            'muestrame', 'mira', 'ver', 'abre', 'abrir', 'corrige', 'corregir', 'vende', 'vender',
            'ese', 'esa', 'esto', 'esta', 'este', 'el', 'la', 'los', 'las', 'de', 'del', 'al', 'un', 'una',
            'ultimo', 'ultima', 'ultimos', 'ultimas', 'latest', 'last', 'ayer', 'today', 'hoy',
        ];
        foreach (EntitySearchSupport::aliasesForType($entityType) as $alias) {
            $stopwords[] = $alias;
        }
        foreach (EntitySearchSupport::supportedTypes() as $type) {
            foreach (EntitySearchSupport::aliasesForType($type) as $alias) {
                $stopwords[] = $alias;
            }
        }

        $tokens = preg_split('/\s+/u', $normalized) ?: [];
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
     * @param array<int, array<string, mixed>> $results
     * @return array{selected_entity_type:string,selected_entity_id:string}
     */
    private function summaryFromResults(array $results): array
    {
        $first = is_array($results[0] ?? null) ? (array) $results[0] : [];
        return [
            'selected_entity_type' => (string) ($first['entity_type'] ?? ''),
            'selected_entity_id' => (string) ($first['entity_id'] ?? ''),
        ];
    }

    private function requireString($value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException('Campo requerido faltante: ' . $field . '.');
        }

        return $value;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function boolFilter($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'si', 'on'], true);
    }

    private function intFilter($value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function normalizeDateTime($value, bool $endOfDay = false): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable(str_replace('T', ' ', $value));
        } catch (\Throwable $e) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $date->format('Y-m-d') . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function latencyMs(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }
}
