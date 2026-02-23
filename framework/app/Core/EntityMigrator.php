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

    public function migrateAll(bool $apply = true): array
    {
        $this->store->ensureTable();

        $results = [];
        foreach ($this->registry->all() as $entity) {
            $results[] = $this->migrateEntity($entity, $apply);
        }
        return $results;
    }

    public function migrateEntity(array $entity, bool $apply = true): array
    {
        $this->store->ensureTable();

        $name = (string) ($entity['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Entidad sin name.');
        }

        $checksum = hash('sha256', json_encode($entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $migrationKey = TableNamespace::migrationKey($name);
        $current = $this->store->getChecksum($migrationKey);
        $this->assertProjectTableLimit($entity);

        $sqls = $this->buildCreateSql($entity);
        if ($apply) {
            foreach ($sqls as $sql) {
                $this->db->exec($sql);
            }
            $this->store->upsert($migrationKey, $checksum);
        }

        return [
            'entity' => $name,
            'migration_key' => $migrationKey,
            'applied' => $apply ? ($current !== $checksum) : false,
            'checksum' => $checksum,
            'sql' => $sqls,
        ];
    }

    public function ensureField(string $entityName, array $field): array
    {
        $entityName = $this->sanitizeIdentifier($entityName);
        $entity = $this->registry->get($entityName);
        $table = $this->resolveEntityTable($entity);

        $column = $this->sanitizeIdentifier((string) ($field['name'] ?? ''));
        if ($column === '') {
            throw new RuntimeException('Campo invalido para ensureField.');
        }

        if (!$this->tableExists($table)) {
            return [
                'entity' => $entityName,
                'table' => $table,
                'field' => $column,
                'applied' => false,
                'reason' => 'table_missing',
            ];
        }
        if ($this->columnExists($table, $column)) {
            return [
                'entity' => $entityName,
                'table' => $table,
                'field' => $column,
                'applied' => false,
                'already_exists' => true,
            ];
        }

        $definition = $this->columnDefinition($field, '', false)['sql'];
        $sql = "ALTER TABLE {$table} ADD COLUMN {$definition};";
        $this->db->exec($sql);

        return [
            'entity' => $entityName,
            'table' => $table,
            'field' => $column,
            'applied' => true,
            'sql' => $sql,
        ];
    }

    public function ensureIndex(string $entityName, string $fieldName, bool $unique = false, ?string $indexName = null): array
    {
        $entityName = $this->sanitizeIdentifier($entityName);
        $fieldName = $this->sanitizeIdentifier($fieldName);
        $entity = $this->registry->get($entityName);
        $table = $this->resolveEntityTable($entity);
        $indexName = $this->sanitizeIdentifier($indexName !== null && $indexName !== '' ? $indexName : "idx_{$entityName}_{$fieldName}");

        if (!$this->tableExists($table)) {
            return [
                'entity' => $entityName,
                'table' => $table,
                'index' => $indexName,
                'field' => $fieldName,
                'applied' => false,
                'reason' => 'table_missing',
            ];
        }
        if (!$this->columnExists($table, $fieldName)) {
            return [
                'entity' => $entityName,
                'table' => $table,
                'index' => $indexName,
                'field' => $fieldName,
                'applied' => false,
                'reason' => 'field_missing',
            ];
        }
        if ($this->indexExists($table, $indexName)) {
            return [
                'entity' => $entityName,
                'table' => $table,
                'index' => $indexName,
                'field' => $fieldName,
                'applied' => false,
                'already_exists' => true,
            ];
        }

        $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$indexName} ON {$table} ({$fieldName});";
        $this->db->exec($sql);

        return [
            'entity' => $entityName,
            'table' => $table,
            'index' => $indexName,
            'field' => $fieldName,
            'applied' => true,
            'sql' => $sql,
        ];
    }

    private function buildCreateSql(array $entity): array
    {
        $logicalTable = (string) ($entity['table']['name'] ?? '');
        if ($logicalTable === '') {
            throw new RuntimeException('Entidad sin table.name.');
        }
        $logicalTable = $this->sanitizeIdentifier($logicalTable);
        $table = $this->sanitizeIdentifier(TableNamespace::resolve($logicalTable));

        $primaryKey = (string) ($entity['table']['primaryKey'] ?? 'id');
        $timestamps = (bool) ($entity['table']['timestamps'] ?? false);
        $softDelete = (bool) ($entity['table']['softDelete'] ?? false);
        $tenantScoped = (bool) ($entity['table']['tenantScoped'] ?? false);
        $canonicalScoped = $tenantScoped && StorageModel::isCanonical();

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
        if ($canonicalScoped && !isset($used['app_id'])) {
            $columns[] = "app_id VARCHAR(120) NOT NULL";
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
            $gridTableLogical = (string) ($grid['table'] ?? "{$logicalTable}__{$gridName}");
            $fk = (string) ($grid['relation']['fk'] ?? "{$logicalTable}_id");
            $gridTable = $this->sanitizeIdentifier(TableNamespace::resolve($gridTableLogical));
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
            if ($canonicalScoped && !isset($gridUsed['app_id'])) {
                $gridColumns[] = "app_id VARCHAR(120) NOT NULL";
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
            if (stripos($sqlType, 'int') !== false) {
                $parts[] = 'AUTO_INCREMENT';
            }
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

    private function resolveEntityTable(array $entity): string
    {
        $logicalTable = (string) ($entity['table']['name'] ?? '');
        if ($logicalTable === '') {
            throw new RuntimeException('Entidad sin table.name.');
        }
        $logicalTable = $this->sanitizeIdentifier($logicalTable);
        return $this->sanitizeIdentifier(TableNamespace::resolve($logicalTable));
    }

    private function columnExists(string $table, string $column): bool
    {
        $table = $this->sanitizeIdentifier($table);
        $column = $this->sanitizeIdentifier($column);
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table
                   AND column_name = :column'
            );
            $stmt->bindValue(':table', $table);
            $stmt->bindValue(':column', $column);
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        }

        if ($driver === 'sqlite') {
            $stmt = $this->db->query("PRAGMA table_info({$table})");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    private function indexExists(string $table, string $index): bool
    {
        $table = $this->sanitizeIdentifier($table);
        $index = $this->sanitizeIdentifier($index);
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = :table
                   AND index_name = :index_name'
            );
            $stmt->bindValue(':table', $table);
            $stmt->bindValue(':index_name', $index);
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        }

        if ($driver === 'sqlite') {
            $stmt = $this->db->query("PRAGMA index_list({$table})");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if ((string) ($row['name'] ?? '') === $index) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    private function assertProjectTableLimit(array $entity): void
    {
        if (!TableNamespace::enabled()) {
            return;
        }

        $limit = (int) (getenv('DB_MAX_TABLES_PER_PROJECT') ?: 0);
        if ($limit <= 0) {
            return;
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            return;
        }

        $logicalTable = (string) ($entity['table']['name'] ?? '');
        if ($logicalTable === '') {
            return;
        }
        $targetTable = TableNamespace::resolve($logicalTable);
        if ($this->tableExists($targetTable)) {
            return;
        }

        $prefix = TableNamespace::projectPrefix();
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name LIKE :prefix'
        );
        $stmt->bindValue(':prefix', $prefix . '%');
        $stmt->execute();
        $total = (int) ($stmt->fetchColumn() ?: 0);
        if ($total >= $limit) {
            throw new RuntimeException(
                "Limite de tablas por proyecto alcanzado ({$limit}). " .
                "Consolida entidades o migra a un plan superior."
            );
        }
    }

    private function tableExists(string $table): bool
    {
        $table = $this->sanitizeIdentifier($table);
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
            );
            $stmt->bindValue(':table', $table);
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        }

        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = :table");
            $stmt->bindValue(':table', $table);
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        }

        try {
            $this->db->query("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
