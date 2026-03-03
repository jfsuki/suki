<?php
// app/Core/FilteredReportEngine.php

namespace App\Core;

use App\Core\Contracts\ContractRepository;
use RuntimeException;

final class FilteredReportEngine
{
    private ContractRepository $contracts;
    private EntityRegistry $entities;

    public function __construct(?ContractRepository $contracts = null, ?EntityRegistry $entities = null)
    {
        $this->contracts = $contracts ?? new ContractRepository();
        $this->entities = $entities ?? new EntityRegistry();
    }

    public function renderHtml(string $formKey, string $reportKey, array $filters = [], string $tenantId = 'default'): string
    {
        [$form, $report, $rows, $columns] = $this->resolveReportDataset($formKey, $reportKey, $filters, $tenantId);
        $title = (string) ($report['name'] ?? $form['name'] ?? $formKey);

        $html = "<!doctype html><html><head><meta charset='utf-8'>";
        $html .= '<title>' . Html::e($title) . '</title>';
        $html .= "<style>body{font-family:Arial,sans-serif;margin:20px;color:#1f2937}h1{font-size:20px;margin:0 0 12px}.meta{font-size:12px;color:#6b7280;margin-bottom:12px}.grid{width:100%;border-collapse:collapse}th,td{border:1px solid #e5e7eb;padding:6px;font-size:12px}th{background:#f3f4f6;text-align:left}.empty{padding:12px;border:1px solid #e5e7eb;background:#f9fafb}</style>";
        $html .= '</head><body>';
        $html .= '<h1>' . Html::e($title) . '</h1>';
        $html .= '<div class="meta">Filas: ' . count($rows) . '</div>';

        if (empty($rows)) {
            $html .= '<div class="empty">No hay datos para los filtros solicitados.</div></body></html>';
            return $html;
        }

        $html .= '<table class="grid"><thead><tr>';
        foreach ($columns as $column) {
            $html .= '<th>' . Html::e((string) $column['label']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $key = (string) $column['name'];
                $html .= '<td>' . Html::e((string) ($row[$key] ?? '')) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';
        return $html;
    }

    public function renderPdf(string $formKey, string $reportKey, array $filters = [], string $tenantId = 'default'): string
    {
        [$form, $report, $rows, $columns] = $this->resolveReportDataset($formKey, $reportKey, $filters, $tenantId);
        $title = (string) ($report['name'] ?? $form['name'] ?? $formKey);

        $pdfColumns = [];
        foreach ($columns as $column) {
            $pdfColumns[] = [
                'key' => (string) $column['name'],
                'label' => (string) $column['label'],
            ];
        }

        $totals = [
            ['label' => 'Registros', 'value' => (string) count($rows)],
        ];

        $fields = [];
        foreach ($filters as $key => $value) {
            if (!is_scalar($value) || $value === '') {
                continue;
            }
            $fields[] = [
                'label' => (string) $key,
                'value' => (string) $value,
            ];
        }

        return (new SimplePdf())->renderReport([
            'title' => $title,
            'fields' => $fields,
            'grid' => [
                'columns' => $pdfColumns,
                'rows' => $rows,
            ],
            'totals' => $totals,
        ]);
    }

    private function resolveReportDataset(string $formKey, string $reportKey, array $filters, string $tenantId): array
    {
        $form = $this->loadFormContract($formKey);
        $report = $this->findReport($form, $reportKey);
        $entityName = (string) ($form['entity'] ?? '');
        if ($entityName === '') {
            throw new RuntimeException('El formulario no define entidad.');
        }

        $entity = $this->entities->get($entityName);
        $tenant = $this->parseTenantId($tenantId);
        $repo = new BaseRepository($entity, null, $tenant);
        $rows = $repo->list([], 500, 0);
        $rows = $this->applyFilters($rows, $form, $filters);

        $columns = $this->resolveColumns($form, $report);
        if (empty($columns) && !empty($rows)) {
            foreach (array_keys((array) $rows[0]) as $name) {
                $columns[] = ['name' => (string) $name, 'label' => (string) $name];
            }
        }

        return [$form, $report, $rows, $columns];
    }

    private function loadFormContract(string $formKey): array
    {
        $formKey = trim($formKey);
        if ($formKey === '') {
            throw new RuntimeException('form requerido.');
        }

        try {
            return $this->contracts->getForm($formKey);
        } catch (\Throwable $e) {
            $fallback = $this->readFallbackFormContract($formKey);
            if ($fallback !== null) {
                return $fallback;
            }
            throw $e;
        }
    }

    private function readFallbackFormContract(string $formKey): ?array
    {
        $frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $workspaceRoot = dirname($frameworkRoot);
        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : ($workspaceRoot . '/project');

        $candidates = [
            $projectRoot . '/contracts/forms/' . $formKey . '.contract.json',
            $frameworkRoot . '/contracts/forms/' . $formKey . '.contract.json',
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function findReport(array $form, string $reportKey): array
    {
        $reports = is_array($form['reports'] ?? null) ? $form['reports'] : [];
        if (empty($reports)) {
            return [];
        }

        $reportKey = trim($reportKey);
        if ($reportKey === '') {
            return is_array($reports[0] ?? null) ? $reports[0] : [];
        }

        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }
            if (($report['id'] ?? '') === $reportKey || ($report['name'] ?? '') === $reportKey) {
                return $report;
            }
        }

        return is_array($reports[0] ?? null) ? $reports[0] : [];
    }

    private function resolveColumns(array $form, array $report): array
    {
        $layoutColumns = $report['layout']['grid']['columns'] ?? [];
        $columns = [];
        if (is_array($layoutColumns) && !empty($layoutColumns)) {
            foreach ($layoutColumns as $column) {
                $name = is_array($column) ? (string) ($column['name'] ?? '') : (string) $column;
                if ($name === '') {
                    continue;
                }
                $columns[] = [
                    'name' => $name,
                    'label' => $this->resolveFieldLabel($form, $name),
                ];
            }
            return $columns;
        }

        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string) ($field['name'] ?? $field['id'] ?? '');
            if ($name === '') {
                continue;
            }
            $columns[] = [
                'name' => $name,
                'label' => (string) ($field['label'] ?? $name),
            ];
        }
        return $columns;
    }

    private function resolveFieldLabel(array $form, string $name): string
    {
        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldName = (string) ($field['name'] ?? $field['id'] ?? '');
            if ($fieldName === $name) {
                return (string) ($field['label'] ?? $name);
            }
        }
        return $name;
    }

    private function applyFilters(array $rows, array $form, array $filters): array
    {
        $definitions = [];
        foreach ((array) ($form['filters'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $name = (string) ($filter['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $definitions[$name] = $filter;
        }

        $active = [];
        foreach ($filters as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $active[(string) $key] = $value;
        }

        if (empty($active)) {
            return $rows;
        }

        return array_values(array_filter($rows, function ($row) use ($active, $definitions): bool {
            if (!is_array($row)) {
                return false;
            }

            foreach ($active as $name => $rawValue) {
                $def = $definitions[$name] ?? [];
                $field = (string) ($def['field'] ?? $name);
                $operator = strtoupper((string) ($def['operator'] ?? '='));
                $current = $row[$field] ?? null;

                if ($operator === 'LIKE') {
                    if (stripos((string) $current, (string) $rawValue) === false) {
                        return false;
                    }
                    continue;
                }

                if ($operator === '>=' || $operator === '<=' || $operator === '>' || $operator === '<') {
                    if (!is_numeric($current) || !is_numeric($rawValue)) {
                        return false;
                    }
                    $left = (float) $current;
                    $right = (float) $rawValue;
                    if ($operator === '>=' && !($left >= $right)) {
                        return false;
                    }
                    if ($operator === '<=' && !($left <= $right)) {
                        return false;
                    }
                    if ($operator === '>' && !($left > $right)) {
                        return false;
                    }
                    if ($operator === '<' && !($left < $right)) {
                        return false;
                    }
                    continue;
                }

                if ((string) $current !== (string) $rawValue) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function parseTenantId(string $tenantId): ?int
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '' || !is_numeric($tenantId)) {
            return null;
        }
        return (int) $tenantId;
    }
}
