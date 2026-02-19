<?php
// app/Core/EntityBuilder.php

namespace App\Core;

final class EntityBuilder
{
    public function build(string $name, array $fields, array $options = []): array
    {
        $name = $this->sanitizeName($name);
        $label = (string) ($options['label'] ?? ucfirst($name));
        $table = (string) ($options['table'] ?? $this->pluralize($name));
        $tenantScoped = (bool) ($options['tenantScoped'] ?? true);

        $entityFields = [
            ['name' => 'id', 'type' => 'int', 'primary' => true, 'source' => 'system'],
        ];

        foreach ($fields as $field) {
            $fname = $this->sanitizeName($field['name'] ?? '');
            if ($fname === '') {
                continue;
            }
            $entityFields[] = [
                'name' => $fname,
                'type' => $this->normalizeType($field['type'] ?? 'string'),
                'label' => (string) ($field['label'] ?? ucfirst($fname)),
                'required' => (bool) ($field['required'] ?? false),
                'source' => 'form',
            ];
        }

        return [
            'type' => 'entity',
            'name' => $name,
            'label' => $label,
            'version' => '1.0',
            'table' => [
                'name' => $table,
                'primaryKey' => 'id',
                'timestamps' => true,
                'softDelete' => false,
                'tenantScoped' => $tenantScoped,
            ],
            'fields' => $entityFields,
            'grids' => [],
            'relations' => [],
            'rules' => [],
            'permissions' => [
                'read' => ['admin', 'seller'],
                'create' => ['admin', 'seller'],
                'update' => ['admin', 'seller'],
                'delete' => ['admin'],
            ],
        ];
    }

    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $map = [
            'texto' => 'string',
            'string' => 'string',
            'text' => 'string',
            'email' => 'email',
            'numero' => 'decimal',
            'number' => 'decimal',
            'decimal' => 'decimal',
            'int' => 'int',
            'integer' => 'int',
            'bool' => 'bool',
            'boolean' => 'bool',
            'fecha' => 'date',
            'date' => 'date',
        ];
        return $map[$type] ?? 'string';
    }

    public function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? '';
        $name = strtolower($name);
        $name = preg_replace('/_+/', '_', $name) ?? $name;
        return trim($name, '_');
    }

    private function pluralize(string $name): string
    {
        if ($name === '') {
            return $name;
        }
        if (str_ends_with($name, 's')) {
            return $name;
        }
        return $name . 's';
    }
}
