<?php
// app/Core/ReportEngine.php

namespace App\Core;

use App\Core\Contracts\ContractRepository;
use RuntimeException;

final class ReportEngine
{
    private ContractRepository $contracts;
    private CommandLayer $command;
    private SummaryCalculator $summary;

    public function __construct(
        ?ContractRepository $contracts = null,
        ?CommandLayer $command = null,
        ?SummaryCalculator $summary = null
    ) {
        $this->contracts = $contracts ?? new ContractRepository();
        $this->command = $command ?? new CommandLayer();
        $this->summary = $summary ?? new SummaryCalculator();
    }

    public function renderPreview(string $formKey, string $reportKey, $recordId, ?string $entity = null): string
    {
        [$form, $report, $record, $summaryValues] = $this->loadReportData($formKey, $reportKey, $recordId, $entity);
        return $this->buildHtml($form, $report, $record, $summaryValues);
    }

    public function renderPdf(string $formKey, string $reportKey, $recordId, ?string $entity = null): string
    {
        [$form, $report, $record, $summaryValues] = $this->loadReportData($formKey, $reportKey, $recordId, $entity);

        $layout = $this->normalizeLayout($report['layout'] ?? []);
        $fields = [];
        $fields = array_merge(
            $fields,
            $this->resolveFieldSection($form, $layout['meta']['fields'] ?? [], $layout['meta']['label'] ?? 'Documento'),
            $this->resolveFieldSection($form, $layout['issuer']['fields'] ?? [], $layout['issuer']['label'] ?? 'Emisor'),
            $this->resolveFieldSection($form, $layout['buyer']['fields'] ?? [], $layout['buyer']['label'] ?? 'Cliente'),
            $this->resolveFieldList($form, $layout)
        );
        $gridName = $layout['grid']['name'] ?? '';
        $gridColumns = $this->resolveGridColumnsForPdf($form, $layout, $gridName);
        $gridRows = $gridName !== '' ? ($record['_grids'][$gridName] ?? []) : [];
        $totals = $this->resolveTotals($form, $layout, $summaryValues);

        foreach ($fields as $index => $field) {
            $name = $field['name'] ?? '';
            $fields[$index]['value'] = $name !== '' ? (string) ($record[$name] ?? '') : '';
        }

        $pdf = new SimplePdf();
        return $pdf->renderReport([
            'title' => $report['name'] ?? $form['title'] ?? 'Reporte',
            'fields' => $fields,
            'grid' => [
                'columns' => $gridColumns,
                'rows' => $gridRows,
            ],
            'totals' => $totals,
        ]);
    }

    private function loadReportData(string $formKey, string $reportKey, $recordId, ?string $entity): array
    {
        $form = $this->contracts->getForm($formKey);
        $reports = $form['reports'] ?? [];
        if (!is_array($reports) || empty($reports)) {
            throw new RuntimeException('Formulario sin informes configurados.');
        }

        $report = $this->findReport($reports, $reportKey);
        if (!$report) {
            throw new RuntimeException('Informe no encontrado.');
        }

        $entityName = $entity ?: ($form['entity'] ?? '');
        if ($entityName === '') {
            throw new RuntimeException('Entidad requerida para el informe.');
        }
        if ($recordId === null || $recordId === '') {
            throw new RuntimeException('ID de registro requerido.');
        }

        $record = $this->command->readRecord($entityName, $recordId, true);
        $grids = $record['_grids'] ?? [];
        $summaryValues = $this->summary->calculate($form['summary'] ?? [], $record, is_array($grids) ? $grids : []);

        return [$form, $report, $record, $summaryValues];
    }

    private function findReport(array $reports, string $reportKey): ?array
    {
        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }
            if (($report['id'] ?? '') === $reportKey || ($report['name'] ?? '') === $reportKey) {
                return $report;
            }
        }
        return $reports[0] ?? null;
    }

    private function resolveFieldList(array $form, array $layout): array
    {
        $fields = $layout['fields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            $fields = array_map(fn($f) => $f['name'] ?? '', $form['fields'] ?? []);
        }
        $resolved = [];
        foreach ($fields as $fieldName) {
            if ($fieldName === '') {
                continue;
            }
            $resolved[] = [
                'label' => $this->fieldLabel($form, $fieldName),
                'value' => '',
                'name' => $fieldName,
            ];
        }
        return $resolved;
    }

    private function resolveGridColumns(array $form, array $layout, string $gridName): array
    {
        $gridColumns = $layout['grid']['columns'] ?? [];
        if (!is_array($gridColumns) || empty($gridColumns)) {
            $gridColumns = $this->gridColumnsFromForm($form, $gridName);
        }
        $labels = [];
        foreach ($gridColumns as $col) {
            $labels[] = $this->gridColumnLabel($form, $gridName, $col);
        }
        return $labels;
    }

    private function resolveGridColumnsForPdf(array $form, array $layout, string $gridName): array
    {
        $columns = $layout['grid']['columns'] ?? [];
        if (!is_array($columns) || empty($columns)) {
            $columns = $this->gridColumnsFromForm($form, $gridName);
        }
        $result = [];
        foreach ($columns as $col) {
            $key = is_array($col) ? (string) ($col['name'] ?? '') : (string) $col;
            if ($key === '') {
                continue;
            }
            $result[] = [
                'key' => $key,
                'label' => $this->gridColumnLabel($form, $gridName, $key),
            ];
        }
        return $result;
    }

    private function resolveTotals(array $form, array $layout, array $summaryValues): array
    {
        $totals = $layout['totals'] ?? [];
        if (!is_array($totals)) {
            $totals = [];
        }
        $result = [];
        foreach ($totals as $sumName) {
            $label = $this->summaryLabel($form, $sumName);
            $value = number_format((float) ($summaryValues[$sumName] ?? 0), 2, '.', ',');
            $result[] = ['label' => $label, 'value' => $value];
        }
        return $result;
    }

    private function buildHtml(array $form, array $report, array $record, array $summaryValues): string
    {
        $title = Html::e($report['name'] ?? $form['title'] ?? 'Reporte');
        $layout = $this->normalizeLayout($report['layout'] ?? []);
        $fields = $layout['fields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            $fields = array_map(fn($f) => $f['name'] ?? '', $form['fields'] ?? []);
        }

        $gridName = $layout['grid']['name'] ?? '';
        $gridColumns = $layout['grid']['columns'] ?? [];
        $gridRows = [];
        if ($gridName !== '' && isset($record['_grids'][$gridName])) {
            $gridRows = $record['_grids'][$gridName];
        }

        $totals = $layout['totals'] ?? [];

        $subtitle = Html::e((string) ($layout['header']['subtitle'] ?? ''));
        $html = "<!doctype html><html><head><meta charset='utf-8'>";
        $html .= "<title>{$title}</title>";
        $html .= "<style>body{font-family:Arial,sans-serif;margin:20px;color:#1f2937}h1{font-size:20px;margin:0}.subtitle{color:#6b7280;font-size:12px;margin-bottom:10px}.section{margin-bottom:16px}.grid{width:100%;border-collapse:collapse}th,td{border:1px solid #e5e7eb;padding:6px;font-size:12px}th{background:#f3f4f6}.totals{margin-top:12px;max-width:320px;margin-left:auto}.totals div{display:flex;justify-content:space-between;padding:4px 0}.print-actions{margin-bottom:12px}button{padding:6px 10px;font-size:12px}.pair{display:grid;grid-template-columns:1fr 1fr;gap:12px}.box{border:1px solid #e5e7eb;border-radius:6px;padding:10px;background:#f9fafb} .box h3{margin:0 0 6px;font-size:12px;text-transform:uppercase;color:#6b7280}</style>";
        $html .= "</head><body>";
        $html .= "<div class='print-actions'><button onclick='window.print()'>Imprimir / Guardar PDF</button></div>";
        $html .= "<h1>{$title}</h1>";
        if ($subtitle !== '') {
            $html .= "<div class='subtitle'>{$subtitle}</div>";
        }

        $metaFields = $layout['meta']['fields'] ?? [];
        $issuerFields = $layout['issuer']['fields'] ?? [];
        $buyerFields = $layout['buyer']['fields'] ?? [];

        if (!empty($metaFields)) {
            $html .= "<div class='section box'><h3>" . Html::e((string) ($layout['meta']['label'] ?? 'Documento')) . "</h3>";
            $html .= $this->renderFieldListHtml($form, $record, $metaFields);
            $html .= "</div>";
        }

        if (!empty($issuerFields) || !empty($buyerFields)) {
            $html .= "<div class='section pair'>";
            if (!empty($issuerFields)) {
                $html .= "<div class='box'><h3>" . Html::e((string) ($layout['issuer']['label'] ?? 'Emisor')) . "</h3>";
                $html .= $this->renderFieldListHtml($form, $record, $issuerFields);
                $html .= "</div>";
            }
            if (!empty($buyerFields)) {
                $html .= "<div class='box'><h3>" . Html::e((string) ($layout['buyer']['label'] ?? 'Cliente')) . "</h3>";
                $html .= $this->renderFieldListHtml($form, $record, $buyerFields);
                $html .= "</div>";
            }
            $html .= "</div>";
        }

        if (!empty($fields)) {
            $html .= "<div class='section'>";
            foreach ($fields as $fieldName) {
                if ($fieldName === '') {
                    continue;
                }
                $label = $this->fieldLabel($form, $fieldName);
                $value = Html::e($record[$fieldName] ?? '');
                $html .= "<div><strong>{$label}:</strong> {$value}</div>";
            }
            $html .= "</div>";
        }

        if ($gridName !== '' && is_array($gridRows)) {
            $html .= "<div class='section'><table class='grid'><thead><tr>";
            if (empty($gridColumns)) {
                $gridColumns = $this->gridColumnsFromForm($form, $gridName);
            }
            foreach ($gridColumns as $col) {
                $label = Html::e($this->gridColumnLabel($form, $gridName, $col));
                $html .= "<th>{$label}</th>";
            }
            $html .= "</tr></thead><tbody>";
            foreach ($gridRows as $row) {
                $html .= "<tr>";
                foreach ($gridColumns as $col) {
                    $val = Html::e($row[$col] ?? '');
                    $html .= "<td>{$val}</td>";
                }
                $html .= "</tr>";
            }
            $html .= "</tbody></table></div>";
        }

        if (!empty($totals)) {
            $html .= "<div class='totals'>";
            foreach ($totals as $sumName) {
                $label = Html::e($this->summaryLabel($form, $sumName));
                $value = number_format((float) ($summaryValues[$sumName] ?? 0), 2, '.', ',');
                $html .= "<div><span>{$label}</span><span>{$value}</span></div>";
            }
            $html .= "</div>";
        }

        $footerText = (string) ($layout['footer']['text'] ?? '');
        if ($footerText !== '') {
            $html .= "<div class='section' style='margin-top:20px;font-size:11px;color:#6b7280'>" . Html::e($footerText) . "</div>";
        }

        $html .= "</body></html>";
        return $html;
    }

    private function fieldLabel(array $form, string $fieldName): string
    {
        foreach (($form['fields'] ?? []) as $field) {
            if (($field['name'] ?? '') === $fieldName || ($field['id'] ?? '') === $fieldName) {
                return (string) ($field['label'] ?? $fieldName);
            }
        }
        return $fieldName;
    }

    private function summaryLabel(array $form, string $summaryName): string
    {
        foreach (($form['summary'] ?? []) as $item) {
            if (($item['name'] ?? '') === $summaryName) {
                return (string) ($item['label'] ?? $summaryName);
            }
        }
        return $summaryName;
    }

    private function gridColumnsFromForm(array $form, string $gridName): array
    {
        foreach (($form['grids'] ?? []) as $grid) {
            if (($grid['name'] ?? '') === $gridName) {
                return array_map(fn($c) => is_array($c) ? ($c['name'] ?? '') : (string) $c, $grid['columns'] ?? []);
            }
        }
        return [];
    }

    private function normalizeLayout(array $layout): array
    {
        if (!isset($layout['header']) || !is_array($layout['header'])) {
            $layout['header'] = ['title' => '', 'subtitle' => '', 'showLogo' => true];
        }
        if (!isset($layout['meta']) || !is_array($layout['meta'])) {
            $layout['meta'] = ['label' => 'Documento', 'fields' => []];
        }
        if (!isset($layout['issuer']) || !is_array($layout['issuer'])) {
            $layout['issuer'] = ['label' => 'Emisor', 'fields' => []];
        }
        if (!isset($layout['buyer']) || !is_array($layout['buyer'])) {
            $layout['buyer'] = ['label' => 'Cliente', 'fields' => []];
        }
        if (!isset($layout['grid']) || !is_array($layout['grid'])) {
            $layout['grid'] = ['name' => '', 'columns' => []];
        }
        if (!isset($layout['fields']) || !is_array($layout['fields'])) {
            $layout['fields'] = [];
        }
        if (!isset($layout['totals']) || !is_array($layout['totals'])) {
            $layout['totals'] = [];
        }
        return $layout;
    }

    private function resolveFieldSection(array $form, array $fieldNames, string $prefix): array
    {
        $resolved = [];
        foreach ($fieldNames as $fieldName) {
            if ($fieldName === '') {
                continue;
            }
            $label = $this->fieldLabel($form, $fieldName);
            if ($prefix !== '') {
                $label = $prefix . ': ' . $label;
            }
            $resolved[] = [
                'label' => $label,
                'value' => '',
                'name' => $fieldName,
            ];
        }
        return $resolved;
    }

    private function renderFieldListHtml(array $form, array $record, array $fieldNames): string
    {
        $html = '';
        foreach ($fieldNames as $fieldName) {
            if ($fieldName === '') {
                continue;
            }
            $label = $this->fieldLabel($form, (string) $fieldName);
            $value = Html::e($record[$fieldName] ?? '');
            $html .= "<div><strong>{$label}:</strong> {$value}</div>";
        }
        return $html;
    }

    private function gridColumnLabel(array $form, string $gridName, string $colName): string
    {
        foreach (($form['grids'] ?? []) as $grid) {
            if (($grid['name'] ?? '') !== $gridName) {
                continue;
            }
            foreach (($grid['columns'] ?? []) as $col) {
                if (($col['name'] ?? '') === $colName) {
                    return (string) ($col['label'] ?? $colName);
                }
            }
        }
        return $colName;
    }
}
