<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;

final class EntitySearchRepository
{
    /** @var array<string, array<int, string>> */
    private const IDENTIFIER_FIELDS = [
        'product' => ['sku', 'codigo', 'code', 'referencia', 'reference', 'barcode', 'codigo_barras', 'ean', 'upc'],
        'sale' => ['numero', 'number', 'document_number', 'doc_number', 'consecutivo', 'codigo', 'referencia', 'reference'],
        'purchase' => ['numero', 'number', 'document_number', 'doc_number', 'consecutivo', 'codigo', 'referencia', 'reference'],
        'invoice' => ['numero', 'number', 'document_number', 'doc_number', 'invoice_number', 'consecutivo', 'codigo', 'referencia', 'reference'],
        'customer' => ['documento', 'document_number', 'nit', 'cedula', 'identificacion', 'codigo', 'code'],
        'supplier' => ['documento', 'document_number', 'nit', 'identificacion', 'codigo', 'code'],
        'order' => ['numero', 'number', 'document_number', 'doc_number', 'codigo', 'referencia', 'reference'],
    ];

    /** @var array<string, array<int, string>> */
    private const LABEL_FIELDS = [
        'product' => ['nombre', 'name', 'descripcion', 'description', 'titulo', 'title'],
        'sale' => ['numero', 'number', 'descripcion', 'description', 'titulo', 'title'],
        'purchase' => ['numero', 'number', 'descripcion', 'description', 'titulo', 'title'],
        'invoice' => ['numero', 'number', 'descripcion', 'description', 'titulo', 'title'],
        'customer' => ['nombre', 'name', 'razon_social', 'full_name'],
        'supplier' => ['nombre', 'name', 'razon_social', 'full_name'],
        'order' => ['numero', 'number', 'descripcion', 'description', 'titulo', 'title'],
    ];

    /** @var array<string, array<int, string>> */
    private const DATE_FIELDS = [
        'product' => ['updated_at', 'created_at'],
        'sale' => ['fecha', 'sale_date', 'created_at', 'updated_at'],
        'purchase' => ['fecha', 'purchase_date', 'created_at', 'updated_at'],
        'invoice' => ['fecha', 'fecha_emision', 'issued_at', 'created_at', 'updated_at'],
        'customer' => ['updated_at', 'created_at'],
        'supplier' => ['updated_at', 'created_at'],
        'order' => ['fecha', 'order_date', 'created_at', 'updated_at'],
    ];

    /** @var array<int, string> */
    private const STATUS_FIELDS = ['estado', 'status', 'situacion'];

    /** @var array<int, string> */
    private const ACTIVE_FIELDS = ['activo', 'active', 'is_active', 'habilitado', 'enabled'];

    private PDO $db;
    private EntityRegistry $registry;

    public function __construct(?PDO $db = null, ?EntityRegistry $registry = null)
    {
        $this->db = $db ?? Database::connection();
        $this->registry = $registry ?? new EntityRegistry();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function search(string $tenantId, string $query, array $filters = []): array
    {
        $normalizedType = EntitySearchSupport::normalizeEntityType((string) ($filters['entity_type'] ?? ''));
        $filters['entity_type'] = $normalizedType;

        $results = [];
        if ($normalizedType === null || $normalizedType !== 'media_file') {
            $results = array_merge($results, $this->searchDynamicEntities($tenantId, $query, $filters));
        }
        if ($normalizedType === null || $normalizedType === 'media_file') {
            $results = array_merge($results, $this->searchMediaFiles($tenantId, $query, $filters));
        }

        $deduped = [];
        foreach ($results as $result) {
            $metadata = is_array($result['metadata_json'] ?? null) ? (array) $result['metadata_json'] : [];
            $resultKey = implode('|', [
                (string) ($result['entity_type'] ?? ''),
                (string) ($result['entity_id'] ?? ''),
                (string) ($metadata['entity_contract'] ?? $result['source_module'] ?? ''),
            ]);
            if (!isset($deduped[$resultKey]) || (float) ($deduped[$resultKey]['score'] ?? 0) < (float) ($result['score'] ?? 0)) {
                $deduped[$resultKey] = $result;
            }
        }

        $results = array_values($deduped);
        usort($results, function (array $left, array $right): int {
            $scoreCompare = ((float) ($right['score'] ?? 0)) <=> ((float) ($left['score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            $leftDate = (string) (($left['metadata_json']['sort_date'] ?? '') ?: '');
            $rightDate = (string) (($right['metadata_json']['sort_date'] ?? '') ?: '');
            $dateCompare = strcmp($rightDate, $leftDate);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return array_slice($results, 0, $this->intFilter($filters['limit'] ?? null, 5, 1, 25));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    public function getByReference(string $tenantId, string $entityType, string $entityId, array $filters = []): ?array
    {
        $entityType = EntitySearchSupport::normalizeEntityType($entityType) ?? '';
        if ($entityType === '') {
            return null;
        }

        if ($entityType === 'media_file') {
            return $this->findMediaById($tenantId, $entityId, $filters);
        }

        foreach ($this->registry->all() as $contract) {
            if (!is_array($contract) || ($contract['type'] ?? '') !== 'entity') {
                continue;
            }

            $resolvedType = $this->inferContractType($contract);
            if ($resolvedType !== $entityType) {
                continue;
            }

            $result = $this->findDynamicById($tenantId, $contract, $entityId, $filters);
            if (is_array($result)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function searchDynamicEntities(string $tenantId, string $query, array $filters): array
    {
        $results = [];
        foreach ($this->registry->all() as $contract) {
            if (!is_array($contract) || ($contract['type'] ?? '') !== 'entity') {
                continue;
            }

            $entityType = $this->inferContractType($contract);
            if ($entityType === null) {
                continue;
            }
            if (($filters['entity_type'] ?? null) !== null && $filters['entity_type'] !== $entityType) {
                continue;
            }

            $table = $this->resolvedTableName($contract, $filters);
            if (!$this->tableExists($table)) {
                continue;
            }

            $results = array_merge($results, $this->searchDynamicContract($tenantId, $contract, $table, $entityType, $query, $filters));
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function searchDynamicContract(string $tenantId, array $contract, string $table, string $entityType, string $query, array $filters): array
    {
        $primaryKey = $this->primaryKey($contract);
        $fields = $this->fieldMap($contract);
        $identifierFields = $this->existingFields($fields, self::IDENTIFIER_FIELDS[$entityType] ?? []);
        $labelFields = $this->existingFields($fields, self::LABEL_FIELDS[$entityType] ?? []);
        $dateField = $this->firstExistingField($fields, self::DATE_FIELDS[$entityType] ?? []);
        $statusField = $this->firstExistingField($fields, self::STATUS_FIELDS);
        $activeField = $this->firstExistingField($fields, self::ACTIVE_FIELDS);
        $limit = $this->intFilter($filters['limit'] ?? null, 5, 1, 25);

        $results = [];
        $query = trim($query);
        if ($query !== '') {
            if (ctype_digit($query)) {
                $rows = $this->fetchRows($table, $tenantId, $filters, $primaryKey . ' = :exact_primary', [':exact_primary' => (int) $query], $dateField, 2);
                foreach ($rows as $row) {
                    $results[] = $this->buildDynamicResult($row, $contract, $entityType, $table, $primaryKey, $labelFields, $identifierFields, $dateField, $statusField, $activeField, 'primary_key_exact', 1000, $primaryKey, $query);
                }
            }

            foreach ($identifierFields as $field) {
                foreach ($this->fetchRows($table, $tenantId, $filters, 'LOWER(' . $field . ') = :match_exact', [':match_exact' => $this->normalizeMatchValue($query)], $dateField, 2) as $row) {
                    $results[] = $this->buildDynamicResult($row, $contract, $entityType, $table, $primaryKey, $labelFields, $identifierFields, $dateField, $statusField, $activeField, $field . '_exact', 980, $field, $query);
                }
                foreach ($this->fetchRows($table, $tenantId, $filters, 'LOWER(' . $field . ') LIKE :match_prefix', [':match_prefix' => $this->normalizeMatchValue($query) . '%'], $dateField, 2) as $row) {
                    $results[] = $this->buildDynamicResult($row, $contract, $entityType, $table, $primaryKey, $labelFields, $identifierFields, $dateField, $statusField, $activeField, $field . '_prefix', 900, $field, $query);
                }
            }

            foreach ($labelFields as $field) {
                foreach ($this->fetchRows($table, $tenantId, $filters, 'LOWER(' . $field . ') = :label_exact', [':label_exact' => $this->normalizeMatchValue($query)], $dateField, 2) as $row) {
                    $results[] = $this->buildDynamicResult($row, $contract, $entityType, $table, $primaryKey, $labelFields, $identifierFields, $dateField, $statusField, $activeField, $field . '_exact_name', 880, $field, $query);
                }
                foreach ($this->fetchRows($table, $tenantId, $filters, 'LOWER(' . $field . ') LIKE :label_prefix', [':label_prefix' => $this->normalizeMatchValue($query) . '%'], $dateField, 3) as $row) {
                    $results[] = $this->buildDynamicResult($row, $contract, $entityType, $table, $primaryKey, $labelFields, $identifierFields, $dateField, $statusField, $activeField, $field . '_prefix_name', 760, $field, $query);
                }
                if (mb_strlen($query, 'UTF-8') >= 3) {
                    foreach ($this->fetchRows($table, $tenantId, $filters, 'LOWER(' . $field . ') LIKE :label_contains', [':label_contains' => '%' . $this->normalizeMatchValue($query) . '%'], $dateField, 4) as $row) {
                        $results[] = $this->buildDynamicResult($row, $contract, $entityType, $table, $primaryKey, $labelFields, $identifierFields, $dateField, $statusField, $activeField, $field . '_contains_name', 680, $field, $query);
                    }
                }
            }
        }

        if (($filters['recency_hint'] ?? null) !== null || $query === '' || ($filters['date_from'] ?? null) !== null || ($filters['date_to'] ?? null) !== null) {
            foreach ($this->fetchRows($table, $tenantId, $filters, null, [], $dateField, $limit) as $row) {
                $results[] = $this->buildDynamicResult($row, $contract, $entityType, $table, $primaryKey, $labelFields, $identifierFields, $dateField, $statusField, $activeField, 'recency_latest', 720, $dateField ?? $primaryKey, $query);
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    private function findDynamicById(string $tenantId, array $contract, string $entityId, array $filters): ?array
    {
        $table = $this->resolvedTableName($contract, $filters);
        if (!$this->tableExists($table)) {
            return null;
        }

        $entityType = $this->inferContractType($contract);
        if ($entityType === null) {
            return null;
        }

        $primaryKey = $this->primaryKey($contract);
        $fields = $this->fieldMap($contract);
        $identifierFields = $this->existingFields($fields, self::IDENTIFIER_FIELDS[$entityType] ?? []);
        $labelFields = $this->existingFields($fields, self::LABEL_FIELDS[$entityType] ?? []);
        $dateField = $this->firstExistingField($fields, self::DATE_FIELDS[$entityType] ?? []);
        $statusField = $this->firstExistingField($fields, self::STATUS_FIELDS);
        $activeField = $this->firstExistingField($fields, self::ACTIVE_FIELDS);

        $rows = [];
        if (ctype_digit($entityId)) {
            $rows = $this->fetchRows($table, $tenantId, $filters, $primaryKey . ' = :id', [':id' => (int) $entityId], $dateField, 1);
        }
        if ($rows === []) {
            foreach ($identifierFields as $field) {
                $rows = $this->fetchRows($table, $tenantId, $filters, 'LOWER(' . $field . ') = :id_ref', [':id_ref' => $this->normalizeMatchValue($entityId)], $dateField, 1);
                if ($rows !== []) {
                    break;
                }
            }
        }

        if ($rows === []) {
            return null;
        }

        return $this->buildDynamicResult(
            $rows[0],
            $contract,
            $entityType,
            $table,
            $primaryKey,
            $labelFields,
            $identifierFields,
            $dateField,
            $statusField,
            $activeField,
            'reference_exact',
            1000,
            $primaryKey,
            $entityId
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function searchMediaFiles(string $tenantId, string $query, array $filters): array
    {
        if (!$this->tableExists('media_files')) {
            return [];
        }

        $window = max(20, min(150, $this->intFilter($filters['limit'] ?? null, 5, 1, 25) * 10));
        $where = ['tenant_id = :tenant_id'];
        $bindings = [':tenant_id' => $tenantId];
        $appId = trim((string) ($filters['app_id'] ?? ''));
        if ($appId !== '') {
            $where[] = 'app_id = :app_id';
            $bindings[':app_id'] = $appId;
        }

        $sql = 'SELECT * FROM media_files WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT ' . $window;
        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $queryNormalized = EntitySearchSupport::normalizeText($query);
        $results = [];
        foreach ($rows as $row) {
            $metadata = [];
            $rawMetadata = (string) ($row['metadata_json'] ?? '');
            if ($rawMetadata !== '') {
                $decoded = json_decode($rawMetadata, true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }

            $originalName = trim((string) ($metadata['original_name'] ?? ''));
            $haystack = EntitySearchSupport::normalizeText(implode(' ', [
                $originalName,
                (string) ($row['entity_type'] ?? ''),
                (string) ($row['entity_id'] ?? ''),
                (string) ($row['file_type'] ?? ''),
            ]));

            $matchKind = '';
            $score = 0;
            if ($queryNormalized === '') {
                $matchKind = 'recency_latest';
                $score = 700;
            } elseif ($originalName !== '' && EntitySearchSupport::normalizeText($originalName) === $queryNormalized) {
                $matchKind = 'media_name_exact';
                $score = 920;
            } elseif ($originalName !== '' && str_starts_with(EntitySearchSupport::normalizeText($originalName), $queryNormalized)) {
                $matchKind = 'media_name_prefix';
                $score = 820;
            } elseif ($queryNormalized !== '' && str_contains($haystack, $queryNormalized)) {
                $matchKind = 'media_name_contains';
                $score = 680;
            } else {
                continue;
            }

            $result = [
                'entity_type' => 'media_file',
                'entity_id' => (string) ($row['id'] ?? ''),
                'label' => $originalName !== '' ? $originalName : ('media ' . (string) ($row['id'] ?? '')),
                'subtitle' => implode(' | ', array_values(array_filter([
                    trim((string) ($row['file_type'] ?? '')),
                    trim((string) ($row['entity_type'] ?? '')),
                    trim((string) ($row['entity_id'] ?? '')),
                    trim((string) ($row['created_at'] ?? '')),
                ], static fn(string $value): bool => $value !== ''))),
                'score' => $score,
                'source_module' => 'media_storage',
                'matched_by' => $matchKind,
                'metadata_json' => [
                    'app_id' => isset($row['app_id']) && $row['app_id'] !== null ? (string) $row['app_id'] : null,
                    'file_type' => (string) ($row['file_type'] ?? ''),
                    'entity_type' => (string) ($row['entity_type'] ?? ''),
                    'linked_entity_id' => (string) ($row['entity_id'] ?? ''),
                    'sort_date' => (string) ($row['created_at'] ?? ''),
                    'original_name' => $originalName,
                ],
            ];
            EntitySearchContractValidator::validateResult($result);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    private function findMediaById(string $tenantId, string $entityId, array $filters): ?array
    {
        if (!$this->tableExists('media_files')) {
            return null;
        }

        $sql = 'SELECT * FROM media_files WHERE tenant_id = :tenant_id AND id = :id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':id', $entityId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $appId = trim((string) ($filters['app_id'] ?? ''));
        $rowAppId = trim((string) ($row['app_id'] ?? ''));
        if ($appId !== '' && $rowAppId !== '' && $appId !== $rowAppId) {
            return null;
        }

        $metadata = [];
        $rawMetadata = (string) ($row['metadata_json'] ?? '');
        if ($rawMetadata !== '') {
            $decoded = json_decode($rawMetadata, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $result = [
            'entity_type' => 'media_file',
            'entity_id' => (string) ($row['id'] ?? ''),
            'label' => trim((string) ($metadata['original_name'] ?? '')) ?: ('media ' . (string) ($row['id'] ?? '')),
            'subtitle' => implode(' | ', array_values(array_filter([
                trim((string) ($row['file_type'] ?? '')),
                trim((string) ($row['entity_type'] ?? '')),
                trim((string) ($row['entity_id'] ?? '')),
            ], static fn(string $value): bool => $value !== ''))),
            'score' => 1000,
            'source_module' => 'media_storage',
            'matched_by' => 'reference_exact',
            'metadata_json' => [
                'app_id' => $rowAppId !== '' ? $rowAppId : null,
                'file_type' => (string) ($row['file_type'] ?? ''),
                'entity_type' => (string) ($row['entity_type'] ?? ''),
                'linked_entity_id' => (string) ($row['entity_id'] ?? ''),
                'sort_date' => (string) ($row['created_at'] ?? ''),
                'original_name' => trim((string) ($metadata['original_name'] ?? '')),
            ],
        ];
        EntitySearchContractValidator::validateResult($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $filters
     */
    private function resolvedTableName(array $contract, array $filters): string
    {
        $logicalTable = trim((string) ($contract['table']['name'] ?? ''));
        if ($logicalTable === '') {
            throw new RuntimeException('ENTITY_TABLE_NAME_REQUIRED');
        }

        $appId = trim((string) ($filters['app_id'] ?? ''));
        return TableNamespace::resolve($logicalTable, $appId !== '' ? $appId : null);
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function inferContractType(array $contract): ?string
    {
        $contractSignals = EntitySearchSupport::normalizeText(implode(' ', [
            (string) ($contract['name'] ?? ''),
            (string) ($contract['label'] ?? ''),
            (string) ($contract['table']['name'] ?? ''),
        ]));

        foreach (EntitySearchSupport::supportedTypes() as $entityType) {
            if ($entityType === 'media_file') {
                continue;
            }
            foreach (EntitySearchSupport::aliasesForType($entityType) as $alias) {
                if (preg_match('/(?:^|\b)' . preg_quote($alias, '/') . '(?:$|\b)/u', $contractSignals) === 1) {
                    return $entityType;
                }
            }
        }

        $fields = $this->fieldMap($contract);
        if ($this->hasAnyField($fields, ['sku', 'barcode', 'codigo_barras'])) {
            return 'product';
        }
        if ($this->hasAnyField($fields, ['nit', 'documento']) && $this->hasAnyField($fields, ['razon_social', 'nombre'])) {
            return 'customer';
        }
        if ($this->hasAnyField($fields, ['numero']) && $this->hasAnyField($fields, ['total', 'subtotal'])) {
            return 'invoice';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, array<string, mixed>>
     */
    private function fieldMap(array $contract): array
    {
        $fields = [];
        foreach ((array) ($contract['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = strtolower(trim((string) ($field['name'] ?? '')));
            if ($name !== '') {
                $fields[$name] = $field;
            }
        }

        if ((bool) ($contract['table']['timestamps'] ?? false)) {
            $fields['created_at'] = ['name' => 'created_at'];
            $fields['updated_at'] = ['name' => 'updated_at'];
        }

        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    private function existingFields(array $fields, array $candidates): array
    {
        $result = [];
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate !== '' && array_key_exists($candidate, $fields)) {
                $result[] = $candidate;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<int, string> $candidates
     */
    private function firstExistingField(array $fields, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate !== '' && array_key_exists($candidate, $fields)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<int, string> $candidates
     */
    private function hasAnyField(array $fields, array $candidates): bool
    {
        return $this->firstExistingField($fields, $candidates) !== null;
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function primaryKey(array $contract): string
    {
        $primaryKey = strtolower(trim((string) ($contract['table']['primaryKey'] ?? 'id')));
        return preg_match('/^[a-zA-Z0-9_]+$/', $primaryKey) === 1 ? $primaryKey : 'id';
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(
        string $table,
        string $tenantId,
        array $filters,
        ?string $extraWhere,
        array $bindings,
        ?string $dateField,
        int $limit
    ): array {
        $tenantScopedValue = $this->stableTenantInt($tenantId);
        $where = ['tenant_id = :tenant_scope_id'];
        $bindings = [':tenant_scope_id' => $tenantScopedValue] + $bindings;

        $appId = trim((string) ($filters['app_id'] ?? ''));
        if ($appId !== '' && StorageModel::isCanonical($appId) && $this->columnExists($table, 'app_id')) {
            $where[] = 'app_id = :app_id';
            $bindings[':app_id'] = $appId;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateField !== null && $dateFrom !== '') {
            $where[] = $dateField . ' >= :date_from';
            $bindings[':date_from'] = $dateFrom;
        }
        if ($dateField !== null && $dateTo !== '') {
            $where[] = $dateField . ' <= :date_to';
            $bindings[':date_to'] = $dateTo;
        }

        $statusField = $this->firstAvailableColumn($table, self::STATUS_FIELDS);
        if ($statusField !== null) {
            $statusValues = [];
            if ((bool) ($filters['only_open'] ?? false)) {
                $statusValues = ['open', 'abierta', 'abierto'];
            } elseif ((bool) ($filters['only_pending'] ?? false)) {
                $statusValues = ['pending', 'pendiente', 'in_progress', 'en_progreso'];
            }

            if ($statusValues !== []) {
                $placeholders = [];
                foreach ($statusValues as $index => $statusValue) {
                    $param = ':status_' . $index;
                    $placeholders[] = $param;
                    $bindings[$param] = $statusValue;
                }
                $where[] = 'LOWER(' . $statusField . ') IN (' . implode(', ', $placeholders) . ')';
            }
        }

        if ($extraWhere !== null && trim($extraWhere) !== '') {
            $where[] = $extraWhere;
        }

        $orderField = $dateField !== null
            ? $dateField
            : ($this->columnExists($table, 'created_at') ? 'created_at' : ($this->firstAvailableColumn($table, ['id']) ?? 'id'));
        $secondaryOrderField = $this->columnExists($table, 'id') ? 'id' : $orderField;
        $sql = 'SELECT * FROM ' . $this->sanitizeIdentifier($table)
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $this->sanitizeIdentifier($orderField) . ' DESC, ' . $this->sanitizeIdentifier($secondaryOrderField) . ' DESC LIMIT ' . max(1, $limit);

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $bindings);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $contract
     * @param array<int, string> $labelFields
     * @param array<int, string> $identifierFields
     * @return array<string, mixed>
     */
    private function buildDynamicResult(
        array $row,
        array $contract,
        string $entityType,
        string $table,
        string $primaryKey,
        array $labelFields,
        array $identifierFields,
        ?string $dateField,
        ?string $statusField,
        ?string $activeField,
        string $matchedBy,
        int $baseScore,
        string $matchedField,
        string $query
    ): array {
        $entityId = trim((string) ($row[$primaryKey] ?? ''));
        if ($entityId === '') {
            $entityId = trim((string) ($row['id'] ?? ''));
        }

        $label = '';
        foreach (array_merge($labelFields, $identifierFields, [$primaryKey]) as $field) {
            $candidate = trim((string) ($row[$field] ?? ''));
            if ($candidate !== '') {
                $label = $candidate;
                break;
            }
        }
        if ($label === '') {
            $label = (string) ($contract['label'] ?? $contract['name'] ?? $entityType) . ' #' . $entityId;
        }

        $subtitleParts = [];
        foreach (array_merge($identifierFields, [$statusField ?? '', $dateField ?? '']) as $field) {
            $field = trim((string) $field);
            if ($field === '' || $field === $matchedField) {
                continue;
            }
            $value = trim((string) ($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $subtitleParts[] = $value;
            if (count($subtitleParts) >= 3) {
                break;
            }
        }

        $score = $baseScore + $this->activeBonus($row, $activeField) + $this->recencyBonus((string) ($row[$dateField ?? ''] ?? ''));
        if ($query !== '' && $matchedField !== '' && trim((string) ($row[$matchedField] ?? '')) !== '' && EntitySearchSupport::normalizeText((string) ($row[$matchedField] ?? '')) === EntitySearchSupport::normalizeText($query)) {
            $score += 20;
        }

        $result = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'label' => $label,
            'subtitle' => implode(' | ', $subtitleParts),
            'score' => $score,
            'source_module' => (string) ($contract['name'] ?? 'dynamic_entity'),
            'matched_by' => $matchedBy,
            'metadata_json' => [
                'entity_contract' => (string) ($contract['name'] ?? ''),
                'table' => $table,
                'matched_field' => $matchedField,
                'sort_date' => (string) ($row[$dateField ?? ''] ?? ''),
                'status' => $statusField !== null ? (string) ($row[$statusField] ?? '') : '',
                'raw_identifier' => $this->firstNonEmpty($row, $identifierFields),
            ],
        ];

        EntitySearchContractValidator::validateResult($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $fields
     */
    private function firstNonEmpty(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function activeBonus(array $row, ?string $activeField): int
    {
        if ($activeField === null) {
            return 0;
        }

        $value = strtolower(trim((string) ($row[$activeField] ?? '')));
        return in_array($value, ['1', 'true', 'si', 'yes', 'active', 'activo'], true) ? 15 : 0;
    }

    private function recencyBonus(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        try {
            $date = new \DateTimeImmutable(str_replace('T', ' ', $value));
        } catch (\Throwable $e) {
            return 0;
        }

        $days = abs((int) ((new \DateTimeImmutable('now'))->diff($date)->format('%a')));
        if ($days <= 1) {
            return 20;
        }
        if ($days <= 7) {
            return 10;
        }
        if ($days <= 30) {
            return 5;
        }

        return 0;
    }

    private function normalizeMatchValue(string $value): string
    {
        return strtolower(trim($value));
    }

    private function stableTenantInt(string $tenantId): int
    {
        $hash = crc32((string) $tenantId);
        $unsigned = (int) sprintf('%u', $hash);
        $max = 2147483647;
        $value = $unsigned % $max;
        return $value > 0 ? $value : 1;
    }

    private function bindAll(PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $name => $value) {
            if (is_int($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($name, $value);
        }
    }

    private function intFilter($value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }

    private function tableExists(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
            );
            $stmt->execute([':table_name' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table_name LIMIT 1");
        $stmt->execute([':table_name' => $table]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '';
    }

    private function columnExists(string $table, string $column): bool
    {
        $table = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '') {
            return false;
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
            );
            $stmt->execute([':table_name' => $table, ':column_name' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        if ($safeTable === '') {
            return false;
        }

        $stmt = $this->db->query('PRAGMA table_info(' . $safeTable . ')');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $candidates
     */
    private function firstAvailableColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate !== '' && $this->columnExists($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function sanitizeIdentifier(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $value) !== 1) {
            throw new RuntimeException('Identificador invalido para search.');
        }

        return $value;
    }
}
