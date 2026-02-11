<?php
// app/Core/EntityMigrator.php

namespace App\Core;

use PDO;
use RuntimeException;

class EntityMigrator
{
    private EntityRegistry $registry;
    private MigrationStore $store;
    private DbTypeMapper $mapper;
    private PDO $db;

    public function __construct(
        ?EntityRegistry $registry = null,
        ?MigrationStore $store = null,
        ?DbTypeMapper $mapper = null,
        ?PDO $db = null
    ) {
        $this->registry = $registry ?? new EntityRegistry();
        $this->db = $db ?? Database::connection();
        $this->store = $store ?? new MigrationStore($this->db);
        $this->mapper = $mapper ?? new DbTypeMapper();
    }

    public function migrateAll(): array
    {
        $this->store->ensureTable();

        $results = [];
        foreach ($this->registry->all() as $entity) {
            $results[] = $this->migrateEntity($entity);
        }
        return $results;
    }

    public function migrateEntity(array $entity): array
    {
        $name = (string) ($entity['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Entidad sin name.');
        }

        $checksum = hash('sha256', json_encode($entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $current = $this->store->getChecksum($name);

        $sqls = $this->buildCreateSql($entity);
        foreach ($sqls as $sql) {
            $this->db->exec($sql);
        }

        $this->store->upsert($name, $checksum);

        return [
            'entity' => $name,
            'applied' => $current !== $checksum,
            'checksum' => $checksum,
            'sql' => $sqls,
        ];
    }

    private function buildCreateSql(array $entity): array
    {
        $table = (string) ($entity['table']['name'] ?? '');
        if ($table === '') {
            throw new RuntimeException('Entidad sin table.name.');
        }
        $table = $this->sanitizeIdentifier($table);

        $primaryKey = (string) ($entity['table']['primaryKey'] ?? 'id');
        $timestamps = (bool) ($entity['table']['timestamps'] ?? false);
        $softDelete = (bool) ($entity['table']['softDelete'] ?? false);
        $tenantScoped = (bool) ($entity['table']['tenantScoped'] ?? false);

        $fields = is_array($entity['fields'] ?? null) ? $entity['fields'] : [];
        $columns = [];
        $used = [];
        $primaryDefined = false;

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $name = $this->sanitizeIdentifier($name);

            $isGrid = isset($field['grid']) || (isset($field['source']) && str_starts_with((string) $field['source'], 'grid:'));
            if ($isGrid) {
                continue;
            }

            $definition = $this->columnDefinition($field, $primaryKey);
            $columns[] = $definition['sql'];
            $used[$name] = true;
            if ($definition['primary']) {
                $primaryDefined = true;
            }
        }

        $primaryKey = $this->sanitizeIdentifier($primaryKey);
        if (!$primaryDefined) {
            $columns[] = "{$primaryKey} INT AUTO_INCREMENT PRIMARY KEY";
            $used[$primaryKey] = true;
        }

        if ($tenantScoped && !isset($used['tenant_id'])) {
            $columns[] = "tenant_id INT NOT NULL";
        }
        if ($timestamps) {
            if (!isset($used['created_at'])) {
                $columns[] = "created_at DATETIME NULL";
            }
            if (!isset($used['updated_at'])) {
                $columns[] = "updated_at DATETIME NULL";
            }
        }
        if ($softDelete && !isset($used['deleted_at'])) {
            $columns[] = "deleted_at DATETIME NULL";
        }

        $sqls = [];
        $sqls[] = "CREATE TABLE IF NOT EXISTS {$table} (\n  " . implode(",\n  ", $columns) . "\n);";

        $grids = is_array($entity['grids'] ?? null) ? $entity['grids'] : [];
        foreach ($grids as $grid) {
            $gridName = (string) ($grid['name'] ?? '');
            if ($gridName === '') {
                continue;
            }
            $gridTable = (string) ($grid['table'] ?? "{$table}__{$gridName}");
            $fk = (string) ($grid['relation']['fk'] ?? "{$table}_id");
            $gridTable = $this->sanitizeIdentifier($gridTable);
            $fk = $this->sanitizeIdentifier($fk);

            $gridColumns = [];
            $gridUsed = [];
            $gridColumns[] = "id INT AUTO_INCREMENT PRIMARY KEY";
            $gridColumns[] = "{$fk} INT NOT NULL";
            $gridUsed['id'] = true;
            $gridUsed[$fk] = true;

            if ($tenantScoped && !isset($gridUsed['tenant_id'])) {
                $gridColumns[] = "tenant_id INT NOT NULL";
            }

            foreach ($fields as $field) {
                $name = $field['name'] ?? null;
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $name = $this->sanitizeIdentifier($name);
                $fieldGrid = $field['grid'] ?? null;
                $source = (string) ($field['source'] ?? '');
                $isGrid = $fieldGrid === $gridName || $source === "grid:{$gridName}";
                if (!$isGrid) {
                    continue;
                }
                if (isset($gridUsed[$name])) {
                    continue;
                }
                $gridUsed[$name] = true;
                $gridColumns[] = $this->columnDefinition($field, '', false)['sql'];
            }

            if ($timestamps) {
                $gridColumns[] = "created_at DATETIME NULL";
                $gridColumns[] = "updated_at DATETIME NULL";
            }
            if ($softDelete) {
                $gridColumns[] = "deleted_at DATETIME NULL";
            }

            $sqls[] = "CREATE TABLE IF NOT EXISTS {$gridTable} (\n  " . implode(",\n  ", $gridColumns) . "\n);";
        }

        return $sqls;
    }

    private function columnDefinition(array $field, string $primaryKey, bool $respectPrimary = true): array
    {
        $name = $field['name'] ?? '';
        $name = $this->sanitizeIdentifier($name);
        $sqlType = $this->mapper->toSql($field);
        $nullable = array_key_exists('nullable', $field) ? (bool) $field['nullable'] : true;
        $required = (bool) ($field['required'] ?? false);
        $unique = (bool) ($field['unique'] ?? false);
        $primary = $respectPrimary && ((bool) ($field['primary'] ?? false) || ($name === $primaryKey));

        $parts = [$name, $sqlType];
        if ($required || !$nullable || $primary) {
            $parts[] = 'NOT NULL';
        } else {
            $parts[] = 'NULL';
        }
        if ($unique) {
            $parts[] = 'UNIQUE';
        }
        if ($primary) {
            $parts[] = 'PRIMARY KEY';
        }

        return ['sql' => implode(' ', $parts), 'primary' => $primary];
    }

    private function sanitizeIdentifier(string $name): string
    {
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException("Identificador invalido: {$name}");
        }
        return $name;
    }
}
