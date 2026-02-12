<?php
// app/Core/CommandLayer.php

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

class CommandLayer
{
    private EntityRegistry $registry;
    private EntityMigrator $migrator;
    private ?int $tenantId;

    public function __construct(
        ?EntityRegistry $registry = null,
        ?EntityMigrator $migrator = null,
        ?int $tenantId = null
    ) {
        $this->registry = $registry ?? new EntityRegistry();
        $this->migrator = $migrator ?? new EntityMigrator($this->registry);
        $this->tenantId = $tenantId ?? TenantContext::getTenantId();
    }

    public function createRecord(string $entityName, array $payload): array
    {
        $entity = $this->registry->get($entityName);
        $this->ensureTenant($entity);
        $this->migrator->migrateEntity($entity, true);

        [$data, $grids] = $this->splitPayload($entity, $payload);
        [$clean, $errors] = $this->validateAndNormalize($entity, $data, true);
        if ($errors) {
            throw new InvalidArgumentException(implode(' | ', $errors));
        }

        $repo = new BaseRepository($entity, null, $this->tenantId);
        $id = $repo->create($clean);

        $gridResult = $this->persistGrids($entity, $id, $grids);

        return [
            'id' => $id,
            'grids' => $gridResult,
        ];
    }

    public function queryRecords(string $entityName, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $entity = $this->registry->get($entityName);
        $this->ensureTenant($entity);

        $repo = new BaseRepository($entity, null, $this->tenantId);
        return $repo->list($filters, $limit, $offset);
    }

    public function readRecord(string $entityName, $id, bool $includeGrids = true): array
    {
        $entity = $this->registry->get($entityName);
        $this->ensureTenant($entity);

        $repo = new BaseRepository($entity, null, $this->tenantId);
        $record = $repo->find($id);

        if (!$record) {
            throw new RuntimeException('Registro no encontrado.');
        }

        if ($includeGrids) {
            $record['_grids'] = $this->fetchGrids($entity, (int) $id);
        }

        return $record;
    }

    public function updateRecord(string $entityName, $id, array $payload): array
    {
        $entity = $this->registry->get($entityName);
        $this->ensureTenant($entity);

        [$data, $grids] = $this->splitPayload($entity, $payload);
        [$clean, $errors] = $this->validateAndNormalize($entity, $data, false);
        if ($errors) {
            throw new InvalidArgumentException(implode(' | ', $errors));
        }

        $repo = new BaseRepository($entity, null, $this->tenantId);
        $affected = $repo->update($id, $clean);

        if (!empty($grids)) {
            $this->replaceGrids($entity, (int) $id, $grids);
        }

        return [
            'updated' => $affected,
        ];
    }

    public function deleteRecord(string $entityName, $id): array
    {
        $entity = $this->registry->get($entityName);
        $this->ensureTenant($entity);

        $repo = new BaseRepository($entity, null, $this->tenantId);
        $affected = $repo->delete($id);

        return [
            'deleted' => $affected,
        ];
    }

    private function ensureTenant(array $entity): void
    {
        $tenantScoped = (bool) ($entity['table']['tenantScoped'] ?? false);
        if ($tenantScoped && $this->tenantId === null) {
            throw new RuntimeException('tenant_id requerido para esta entidad.');
        }
    }

    private function splitPayload(array $entity, array $payload): array
    {
        $gridNames = [];
        foreach (($entity['grids'] ?? []) as $grid) {
            if (!empty($grid['name'])) {
                $gridNames[] = $grid['name'];
            }
        }

        $grids = [];
        if (isset($payload['grids']) && is_array($payload['grids'])) {
            foreach ($payload['grids'] as $gridName => $rows) {
                if (in_array($gridName, $gridNames, true) && is_array($rows)) {
                    $grids[$gridName] = array_values($rows);
                }
            }
        }

        foreach ($gridNames as $gridName) {
            if (isset($payload[$gridName]) && is_array($payload[$gridName])) {
                $grids[$gridName] = array_values($payload[$gridName]);
                unset($payload[$gridName]);
            }
        }

        $data = $payload;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
        }

        return [$data, $grids];
    }

    private function validateAndNormalize(array $entity, array $data, bool $isCreate): array
    {
        $errors = [];
        $fields = $this->mainFields($entity);
        $clean = [];

        foreach ($fields as $name => $field) {
            $required = (bool) ($field['required'] ?? false);
            $has = array_key_exists($name, $data);

            if ($isCreate && $required && (!$has || $data[$name] === '' || $data[$name] === null)) {
                $errors[] = "Campo requerido: {$name}";
                continue;
            }

            if ($has) {
                [$value, $err] = $this->normalizeValue($field, $data[$name]);
                if ($err) {
                    $errors[] = "Campo {$name}: {$err}";
                    continue;
                }
                $clean[$name] = $value;
            }
        }

        return [$clean, $errors];
    }

    private function normalizeValue(array $field, $value): array
    {
        $type = strtolower((string) ($field['type'] ?? 'string'));
        if (is_array($value)) {
            return [null, 'valor invalido'];
        }

        if ($value === '' || $value === null) {
            return [null, null];
        }

        switch ($type) {
            case 'int':
            case 'integer':
                if (!is_numeric($value)) {
                    return [null, 'debe ser numero'];
                }
                return [(int) $value, null];
            case 'number':
            case 'decimal':
            case 'float':
            case 'money':
                if (!is_numeric($value)) {
                    return [null, 'debe ser numero'];
                }
                return [(float) $value, null];
            case 'bool':
            case 'boolean':
                return [filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0, null];
            case 'email':
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return [null, 'email invalido'];
                }
                return [(string) $value, null];
            default:
                return [trim((string) $value), null];
        }
    }

    private function mainFields(array $entity): array
    {
        $fields = [];
        foreach (($entity['fields'] ?? []) as $field) {
            $name = $field['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            if ($this->isGridField($field)) {
                continue;
            }
            $fields[$name] = $field;
        }
        return $fields;
    }

    private function gridFields(array $entity): array
    {
        $map = [];
        foreach (($entity['fields'] ?? []) as $field) {
            if (!$this->isGridField($field)) {
                continue;
            }
            $gridName = $field['grid'] ?? null;
            $source = (string) ($field['source'] ?? '');
            if (!$gridName && str_starts_with($source, 'grid:')) {
                $gridName = substr($source, 5);
            }
            if (!$gridName) {
                continue;
            }
            $name = $field['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $map[$gridName][$name] = $field;
        }
        return $map;
    }

    private function isGridField(array $field): bool
    {
        if (isset($field['grid']) && $field['grid'] !== '') {
            return true;
        }
        $source = (string) ($field['source'] ?? '');
        return str_starts_with($source, 'grid:');
    }

    private function persistGrids(array $entity, int $parentId, array $grids): array
    {
        if (empty($grids)) {
            return [];
        }

        $results = [];
        $gridFields = $this->gridFields($entity);
        $tenantScoped = (bool) ($entity['table']['tenantScoped'] ?? false);
        $timestamps = (bool) ($entity['table']['timestamps'] ?? false);

        foreach (($entity['grids'] ?? []) as $gridDef) {
            $gridName = (string) ($gridDef['name'] ?? '');
            if ($gridName === '' || empty($grids[$gridName])) {
                continue;
            }

            $table = (string) ($gridDef['table'] ?? '');
            $fk = (string) ($gridDef['relation']['fk'] ?? ($entity['table']['name'] ?? '') . '_id');
            if ($table === '' || $fk === '') {
                continue;
            }

            $fields = $gridFields[$gridName] ?? [];
            $allowed = array_keys($fields);
            $allowed[] = 'id';
            $allowed[] = $fk;
            if ($tenantScoped) {
                $allowed[] = 'tenant_id';
            }
            if ($timestamps) {
                $allowed[] = 'created_at';
                $allowed[] = 'updated_at';
            }

            $qb = (new QueryBuilder(Database::connection(), $table))
                ->setAllowedColumns($allowed);

            $inserted = 0;
            foreach ($grids[$gridName] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $payload = [];
                foreach ($fields as $fieldName => $field) {
                    if (!array_key_exists($fieldName, $row)) {
                        continue;
                    }
                    [$value, $err] = $this->normalizeValue($field, $row[$fieldName]);
                    if ($err) {
                        continue;
                    }
                    $payload[$fieldName] = $value;
                }
                $payload[$fk] = $parentId;
                if ($tenantScoped && $this->tenantId !== null) {
                    $payload['tenant_id'] = $this->tenantId;
                }
                if ($timestamps) {
                    $now = date('Y-m-d H:i:s');
                    $payload['created_at'] = $now;
                    $payload['updated_at'] = $now;
                }
                if (!empty($payload)) {
                    $qb->insert($payload);
                    $inserted++;
                }
            }

            $results[$gridName] = ['inserted' => $inserted];
        }

        return $results;
    }

    private function replaceGrids(array $entity, int $parentId, array $grids): void
    {
        $tenantScoped = (bool) ($entity['table']['tenantScoped'] ?? false);
        foreach (($entity['grids'] ?? []) as $gridDef) {
            $gridName = (string) ($gridDef['name'] ?? '');
            if ($gridName === '' || !isset($grids[$gridName])) {
                continue;
            }

            $table = (string) ($gridDef['table'] ?? '');
            $fk = (string) ($gridDef['relation']['fk'] ?? ($entity['table']['name'] ?? '') . '_id');
            if ($table === '' || $fk === '') {
                continue;
            }

            $allowed = [$fk];
            if ($tenantScoped) {
                $allowed[] = 'tenant_id';
            }

            $qb = (new QueryBuilder(Database::connection(), $table))
                ->setAllowedColumns($allowed);
            $qb->where($fk, '=', $parentId);
            if ($tenantScoped && $this->tenantId !== null) {
                $qb->where('tenant_id', '=', $this->tenantId);
            }
            $qb->delete();

            $this->persistGrids($entity, $parentId, [$gridName => $grids[$gridName]]);
        }
    }

    private function fetchGrids(array $entity, int $parentId): array
    {
        $result = [];
        $gridFields = $this->gridFields($entity);
        $tenantScoped = (bool) ($entity['table']['tenantScoped'] ?? false);

        foreach (($entity['grids'] ?? []) as $gridDef) {
            $gridName = (string) ($gridDef['name'] ?? '');
            $table = (string) ($gridDef['table'] ?? '');
            $fk = (string) ($gridDef['relation']['fk'] ?? ($entity['table']['name'] ?? '') . '_id');
            if ($gridName === '' || $table === '' || $fk === '') {
                continue;
            }

            $fields = $gridFields[$gridName] ?? [];
            $allowed = array_keys($fields);
            $allowed[] = $fk;
            if ($tenantScoped) {
                $allowed[] = 'tenant_id';
            }

            $qb = (new QueryBuilder(Database::connection(), $table))
                ->setAllowedColumns($allowed)
                ->where($fk, '=', $parentId);

            if ($tenantScoped && $this->tenantId !== null) {
                $qb->where('tenant_id', '=', $this->tenantId);
            }

            $result[$gridName] = $qb->get();
        }

        return $result;
    }
}
