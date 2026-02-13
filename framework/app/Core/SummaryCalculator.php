<?php
// app/Core/SummaryCalculator.php

namespace App\Core;

final class SummaryCalculator
{
    private ExpressionEngine $engine;

    public function __construct(?ExpressionEngine $engine = null)
    {
        $this->engine = $engine ?? new ExpressionEngine();
    }

    public function calculate(array $summaryConfig, array $record, array $grids): array
    {
        $values = [];
        $context = $this->buildContext($record, $grids);

        foreach ($summaryConfig as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $type = strtolower((string) ($item['type'] ?? 'sum'));
            $value = 0.0;

            if ($type === 'sum') {
                $source = $item['source'] ?? [];
                $grid = $source['grid'] ?? null;
                $field = $source['field'] ?? null;
                if ($grid && $field) {
                    $value = $this->sumGridField($grids[$grid] ?? [], (string) $field);
                } elseif ($field) {
                    $value = isset($record[$field]) ? (float) $record[$field] : 0.0;
                }
            } elseif ($type === 'formula') {
                $expression = (string) ($item['expression'] ?? '');
                $value = $this->engine->evaluate($expression, $context + $values);
            }

            $values[$name] = $value;
        }

        return $values;
    }

    public function gridTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (!is_numeric($value)) {
                    continue;
                }
                $totals[$key] = ($totals[$key] ?? 0) + (float) $value;
            }
        }
        return $totals;
    }

    private function sumGridField(array $rows, string $field): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row[$field]) || !is_numeric($row[$field])) {
                continue;
            }
            $sum += (float) $row[$field];
        }
        return $sum;
    }

    private function buildContext(array $record, array $grids): array
    {
        $context = [];
        foreach ($record as $key => $value) {
            if (is_numeric($value)) {
                $context[$key] = (float) $value;
            }
        }
        foreach ($grids as $gridName => $rows) {
            $totals = $this->gridTotals(is_array($rows) ? $rows : []);
            foreach ($totals as $col => $val) {
                $context[$gridName . '_' . $col] = $val;
            }
        }
        return $context;
    }
}
