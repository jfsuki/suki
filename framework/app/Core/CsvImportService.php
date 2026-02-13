<?php
// app/Core/CsvImportService.php

namespace App\Core;

use RuntimeException;

final class CsvImportService
{
    private EntityMigrator $migrator;

    public function __construct(?EntityMigrator $migrator = null)
    {
        $this->migrator = $migrator ?? new EntityMigrator();
    }

    public function import(array $payload): array
    {
        $entityName = $this->sanitizeName($payload['entityName'] ?? $payload['entity'] ?? '');
        $tableName = $this->sanitizeName($payload['tableName'] ?? $payload['table'] ?? $entityName);
        if ($entityName === '' || $tableName === '') {
            throw new RuntimeException('entityName y tableName requeridos.');
        }

        $label = (string) ($payload['entityLabel'] ?? $payload['label'] ?? $entityName);
        $primaryKey = $this->sanitizeName($payload['primaryKey'] ?? 'id') ?: 'id';
        $tenantScoped = (bool) ($payload['tenantScoped'] ?? true);
        $timestamps = (bool) ($payload['timestamps'] ?? true);
        $softDelete = (bool) ($payload['softDelete'] ?? false);
        $columns = $payload['columns'] ?? [];
        if (!is_array($columns) || empty($columns)) {
            throw new RuntimeException('columns requeridos.');
        }

        $fields = [];
        $fields[] = [
            'name' => $primaryKey,
            'type' => 'int',
            'primary' => true,
            'source' => 'system',
        ];
        if ($tenantScoped) {
            $fields[] = [
                'name' => 'tenant_id',
                'type' => 'int',
                'source' => 'system',
            ];
        }

        foreach ($columns as $col) {
            if (!is_array($col)) {
                continue;
            }
            $name = $this->sanitizeName($col['name'] ?? '');
            if ($name === '' || $name === $primaryKey) {
                continue;
            }
            $fields[] = [
                'name' => $name,
                'type' => $this->mapType((string) ($col['type'] ?? 'string')),
                'label' => $col['label'] ?? $name,
                'required' => (bool) ($col['required'] ?? false),
                'source' => 'form',
            ];
        }

        $entity = [
            'type' => 'entity',
            'name' => $entityName,
            'label' => $label,
            'version' => '1.0',
            'table' => [
                'name' => $tableName,
                'primaryKey' => $primaryKey,
                'timestamps' => $timestamps,
                'softDelete' => $softDelete,
                'tenantScoped' => $tenantScoped,
            ],
            'fields' => $fields,
            'permissions' => [
                'read' => ['admin'],
                'create' => ['admin'],
                'update' => ['admin'],
                'delete' => ['admin'],
            ],
        ];

        $result = $this->migrator->migrateEntity($entity, true);

        return [
            'entity' => $entity,
            'migration' => $result,
        ];
    }

    private function sanitizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name);
        $name = trim($name, '_');
        return $name;
    }

    private function mapType(string $type): string
    {
        $type = strtolower(trim($type));
        return match ($type) {
            'int', 'integer' => 'int',
            'decimal', 'float', 'double', 'number', 'money', 'currency' => 'decimal',
            'date' => 'date',
            'datetime' => 'datetime',
            'bool', 'boolean' => 'bool',
            default => 'string',
        };
    }
}
