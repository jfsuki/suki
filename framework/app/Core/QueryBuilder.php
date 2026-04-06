<?php
// app/Core/QueryBuilder.php

namespace App\Core;

use InvalidArgumentException;
use PDO;

class QueryBuilder
{
    private PDO $db;
    private string $table;
    private array $select = ['*'];
    private array $wheres = [];
    private array $bindings = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $allowedColumns = [];

    public function __construct(PDO $db, string $table)
    {
        $this->db = $db;
        $this->table = $this->sanitizeIdentifier(TableNamespace::resolve($table));
    }

    public static function table(PDO $db, string $table): self
    {
        return new self($db, $table);
    }

    public function setAllowedColumns(array $columns): self
    {
        $this->allowedColumns = array_values(array_filter($columns, function ($col) {
            return $this->isValidIdentifier($col);
        }));
        return $this;
    }

    public function select(array $columns = ['*']): self
    {
        if ($columns === ['*']) {
            $this->select = ['*'];
            return $this;
        }

        $clean = [];
        foreach ($columns as $col) {
            $clean[] = $this->sanitizeColumn($col);
        }
        $this->select = $clean;
        return $this;
    }

    public function where(string $column, string $operator, $value): self
    {
        $operator = strtoupper(trim($operator));
        $allowed = ['=', '!=', '<', '>', '<=', '>=', 'LIKE'];
        if (!in_array($operator, $allowed, true)) {
            throw new InvalidArgumentException('Operador no permitido.');
        }

        $col = $this->sanitizeColumn($column);
        $param = $this->newParamName($col);
        $this->wheres[] = "{$col} {$operator} :{$param}";
        $this->bindings[$param] = $value;
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = $sql;
        foreach ($bindings as $key => $value) {
            $param = ltrim($key, ':');
            $this->bindings[$param] = $value;
        }
        return $this;
    }

    public function whereIn(string $column, array $values, bool $not = false): self
    {
        $col = $this->sanitizeColumn($column);
        if (count($values) === 0) {
            $this->wheres[] = $not ? '1=1' : '1=0';
            return $this;
        }

        $params = [];
        foreach ($values as $val) {
            $param = $this->newParamName($col);
            $params[] = ':' . $param;
            $this->bindings[$param] = $val;
        }

        $in = $not ? 'NOT IN' : 'IN';
        $this->wheres[] = "{$col} {$in} (" . implode(',', $params) . ")";
        return $this;
    }

    public function applyTenant(int $tenantId, string $column = 'tenant_id'): self
    {
        return $this->where($column, '=', $tenantId);
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $dir = strtoupper(trim($direction));
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Direccion de orden invalida.');
        }
        $this->orderBy[] = $this->sanitizeColumn($column) . ' ' . $dir;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function get(): array
    {
        $sql = $this->buildSelectSql();
        $stmt = $this->db->prepare($sql);
        foreach ($this->bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function first(): ?array
    {
        $this->limit(1);
        $rows = $this->get();
        return $rows[0] ?? null;
    }

    public function count(): int
    {
        $sql = "SELECT COUNT(*) AS aggregate_count FROM {$this->table}" . $this->buildWhereSql();
        $stmt = $this->db->prepare($sql);
        foreach ($this->bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function insert(array $data): int
    {
        $data = $this->filterAllowed($data);
        if (empty($data)) {
            throw new InvalidArgumentException('No hay datos para insertar.');
        }

        $cols = array_keys($data);
        $params = array_map(function ($col) {
            return ':' . $this->newParamName($col);
        }, $cols);

        $sql = "INSERT INTO {$this->table} (" . implode(',', $cols) . ") VALUES (" . implode(',', $params) . ")";
        $stmt = $this->db->prepare($sql);

        $i = 0;
        foreach ($data as $value) {
            $param = ltrim($params[$i], ':');
            $stmt->bindValue(':' . $param, $value);
            $i++;
        }

        $stmt->execute();
        return (int) $this->db->lastInsertId();
    }

    public function update(array $data): int
    {
        $data = $this->filterAllowed($data);
        if (empty($data)) {
            throw new InvalidArgumentException('No hay datos para actualizar.');
        }
        if (empty($this->wheres)) {
            throw new InvalidArgumentException('Update requiere condiciones where.');
        }

        $sets = [];
        foreach ($data as $col => $value) {
            $col = $this->sanitizeColumn($col);
            $param = $this->newParamName($col);
            $sets[] = "{$col} = :{$param}";
            $this->bindings[$param] = $value;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . $this->buildWhereSql();
        $stmt = $this->db->prepare($sql);
        foreach ($this->bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        if (empty($this->wheres)) {
            throw new InvalidArgumentException('Delete requiere condiciones where.');
        }

        $sql = "DELETE FROM {$this->table}" . $this->buildWhereSql();
        $stmt = $this->db->prepare($sql);
        foreach ($this->bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function buildSelectSql(): string
    {
        $sql = "SELECT " . implode(',', $this->select) . " FROM {$this->table}";
        $sql .= $this->buildWhereSql();

        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY " . implode(', ', $this->orderBy);
        }
        if ($this->limit !== null) {
            $sql .= " LIMIT " . (int) $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= " OFFSET " . (int) $this->offset;
        }
        return $sql;
    }

    private function buildWhereSql(): string
    {
        if (empty($this->wheres)) {
            return '';
        }
        return ' WHERE ' . implode(' AND ', $this->wheres);
    }

    private function filterAllowed(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (!$this->isValidIdentifier($key)) {
                continue;
            }
            if (!empty($this->allowedColumns) && !in_array($key, $this->allowedColumns, true)) {
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    private function sanitizeColumn(string $column): string
    {
        if (!empty($this->allowedColumns) && !in_array($column, $this->allowedColumns, true)) {
            throw new InvalidArgumentException("Columna no permitida: {$column}");
        }
        return $this->sanitizeIdentifier($column);
    }

    private function sanitizeIdentifier(string $identifier): string
    {
        if (!$this->isValidIdentifier($identifier)) {
            throw new InvalidArgumentException('Identificador invalido.');
        }
        return $identifier;
    }

    private function isValidIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $identifier);
    }

    private function newParamName(string $column): string
    {
        return $column . '_' . count($this->bindings);
    }
}
