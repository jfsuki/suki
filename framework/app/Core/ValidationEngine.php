<?php
// app/Core/ValidationEngine.php

namespace App\Core;

final class ValidationEngine
{
    public function validate(array $field, $value, bool $isCreate): array
    {
        $errors = [];
        $rules = is_array($field['validation'] ?? null) ? $field['validation'] : [];

        $required = (bool) ($field['required'] ?? $rules['required'] ?? false);
        $min = $field['min'] ?? $rules['min'] ?? null;
        $max = $field['max'] ?? $rules['max'] ?? null;
        $pattern = $field['pattern'] ?? $rules['pattern'] ?? null;
        $enum = $field['enum'] ?? $rules['enum'] ?? null;

        if ($isCreate && $required && ($value === null || $value === '')) {
            $errors[] = 'requerido';
            return $errors;
        }

        if ($value === null || $value === '') {
            return $errors;
        }

        $type = strtolower((string) ($field['type'] ?? 'string'));
        $isNumeric = in_array($type, ['int','integer','number','decimal','float','money','currency'], true);

        if ($isNumeric && !is_numeric($value)) {
            $errors[] = 'debe ser numero';
            return $errors;
        }

        if ($min !== null) {
            if ($isNumeric) {
                if ((float) $value < (float) $min) {
                    $errors[] = "min {$min}";
                }
            } elseif (is_string($value) && mb_strlen((string) $value) < (int) $min) {
                $errors[] = "min {$min}";
            }
        }

        if ($max !== null) {
            if ($isNumeric) {
                if ((float) $value > (float) $max) {
                    $errors[] = "max {$max}";
                }
            } elseif (is_string($value) && mb_strlen((string) $value) > (int) $max) {
                $errors[] = "max {$max}";
            }
        }

        if ($pattern) {
            $delimited = $this->normalizePattern((string) $pattern);
            if (@preg_match($delimited, (string) $value) !== 1) {
                $errors[] = 'patron invalido';
            }
        }

        if (is_array($enum) && !empty($enum)) {
            if (!in_array($value, $enum, true)) {
                $errors[] = 'valor no permitido';
            }
        }

        return $errors;
    }

    private function normalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return '/.*/';
        }
        $delimiter = $pattern[0];
        $last = $pattern[strlen($pattern) - 1];
        if ($delimiter === '/' && $last === '/') {
            return $pattern;
        }
        return '/' . str_replace('/', '\\/', $pattern) . '/';
    }
}
