<?php
// app/Core/BaseRepository.php

namespace App\Core;

use InvalidArgumentException;
use PDO;

class BaseRepository
{
    protected PDO $db;
    protected array $entity;
    protected string $table;
    protected string $logicalTable;
    protected string $primaryKey;
    protected bool $timestamps;
    protected bool $tenantScoped;
    protected bool $softDelete;
    protected ?int $tenantId;
    protected bool $canonicalScoped;
    protected string $appId;
    protected array $allowedColumns = [];

    public function __construct(array $entity, ?PDO $db = null, ?int $tenantId = null)
    {
        if (($entity['type'] ?? '') !== 'entity') {
            throw new InvalidArgumentException('Entidad invalida.');
        }

        $this->entity = $entity;
        $this->logicalTable = (string) ($entity['table']['name'] ?? '');
        $this->table = TableNamespace::resolve($this->logicalTable);
        $this->primaryKey = (string) ($entity['table']['primaryKey'] ?? 'id');
        $this->timestamps = (bool) ($entity['table']['timestamps'] ?? false);
        $this->tenantScoped = (bool) ($entity['table']['tenantScoped'] ?? false);
        $this->softDelete = (bool) ($entity['table']['softDelete'] ?? false);
        $this->tenantId = $tenantId ?? TenantContext::getTenantId();
        $this->canonicalScoped = $this->tenantScoped && StorageModel::isCanonical();
        $this->appId = StorageModel::appId();

        if ($this->logicalTable === '') {
            throw new InvalidArgumentException('Tabla de entidad requerida.');
        }

        $this->allowedColumns = $this->resolveColumns($entity['fields'] ?? []);
        $this->allowedColumns = $this->withSystemColumns($this->allowedColumns);
        $this->db = $db ?? Database::connection();
    }

    public function create(array $data): int
    {
        $this->guardTenant();
        $payload = $this->filterData($data);
        if ($this->tenantScoped && $this->tenantId !== null) {
            $payload['tenant_id'] = $this->tenantId;
        }
        if ($this->canonicalScoped) {
            $payload['app_id'] = $this->appId;
        }

        $qb = $this->newQuery();
        return $qb->insert($payload);
    }

    public function find($id): ?array
    {
        $this->guardTenant();
        $qb = $this->newQuery()
            ->where($this->primaryKey, '=', $id);

        $this->applyTenantScope($qb);
        return $qb->first();
    }

    public function update($id, array $data): int
    {
        $this->guardTenant();
        $payload = $this->filterData($data);
        if (empty($payload)) {
            throw new InvalidArgumentException('No hay datos permitidos para actualizar.');
        }

        $qb = $this->newQuery()
            ->where($this->primaryKey, '=', $id);

        $this->applyTenantScope($qb);
        return $qb->update($payload);
    }

    public function delete($id): int
    {
        $this->guardTenant();
        $qb = $this->newQuery()
            ->where($this->primaryKey, '=', $id);

        $this->applyTenantScope($qb);

        if ($this->softDelete) {
            return $qb->update(['deleted_at' => date('Y-m-d H:i:s')]);
        }

        return $qb->delete();
    }

    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $this->guardTenant();
        $qb = $this->newQuery();
        $this->applyTenantScope($qb);

        foreach ($filters as $column => $value) {
            if ($this->tenantScoped && $column === 'tenant_id') {
                continue;
            }
            if ($this->canonicalScoped && $column === 'app_id') {
                continue;
            }
            if (!in_array($column, $this->allowedColumns, true)) {
                continue;
            }
            $qb->where($column, '=', $value);
        }

        return $qb->limit($limit)->offset($offset)->get();
    }

    protected function newQuery(): QueryBuilder
    {
        return (new QueryBuilder($this->db, $this->table))
            ->setAllowedColumns($this->allowedColumns);
    }

    protected function applyTenantScope(QueryBuilder $qb): void
    {
        if ($this->tenantScoped && $this->tenantId !== null) {
            if (in_array('tenant_id', $this->allowedColumns, true)) {
                $qb->where('tenant_id', '=', $this->tenantId);
            }
        }
        if ($this->canonicalScoped && in_array('app_id', $this->allowedColumns, true)) {
            $qb->where('app_id', '=', $this->appId);
        }
    }

    protected function guardTenant(): void
    {
        if ($this->tenantScoped && $this->tenantId === null) {
            throw new InvalidArgumentException('tenant_id requerido para esta entidad.');
        }
    }

    protected function resolveColumns(array $fields): array
    {
        $columns = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $columns[] = $name;
        }
        return array_values(array_unique($columns));
    }

    protected function filterData(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if ($key === $this->primaryKey) {
                continue;
            }
            if ($this->tenantScoped && $key === 'tenant_id') {
                continue;
            }
            if ($this->canonicalScoped && $key === 'app_id') {
                continue;
            }
            if ($this->timestamps && ($key === 'created_at' || $key === 'updated_at')) {
                continue;
            }
            if ($this->softDelete && $key === 'deleted_at') {
                continue;
            }
            if (!in_array($key, $this->allowedColumns, true)) {
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    protected function withSystemColumns(array $columns): array
    {
        $columns[] = $this->primaryKey;
        if ($this->tenantScoped) {
            $columns[] = 'tenant_id';
            if ($this->canonicalScoped) {
                $columns[] = 'app_id';
            }
        }
        if ($this->timestamps) {
            $columns[] = 'created_at';
            $columns[] = 'updated_at';
        }
        if ($this->softDelete) {
            $columns[] = 'deleted_at';
        }

        return array_values(array_unique($columns));
    }
}
