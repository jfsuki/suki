<?php
// app/Core/DbTypeMapper.php

namespace App\Core;

class DbTypeMapper
{
    public function toSql(array $field): string
    {
        $type = strtolower((string) ($field['type'] ?? 'string'));
        $length = (int) ($field['length'] ?? 0);
        $precision = array_key_exists('precision', $field) ? (int) $field['precision'] : 12;
        $scale = array_key_exists('scale', $field) ? (int) $field['scale'] : 2;

        switch ($type) {
            case 'int':
            case 'integer':
                return 'INT';
            case 'bigint':
                return 'BIGINT';
            case 'bool':
            case 'boolean':
                return 'TINYINT(1)';
            case 'decimal':
            case 'currency':
            case 'number':
            case 'numeric':
                $precision = $precision > 0 ? $precision : 12;
                $scale = $scale < 0 ? 2 : $scale;
                return "DECIMAL({$precision},{$scale})";
            case 'float':
            case 'double':
                return 'DOUBLE';
            case 'date':
                return 'DATE';
            case 'time':
                return 'TIME';
            case 'datetime':
                return 'DATETIME';
            case 'text':
            case 'textarea':
                return 'TEXT';
            case 'string':
            default:
                $length = $length > 0 ? $length : 255;
                return "VARCHAR({$length})";
        }
    }
}
