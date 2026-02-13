<?php
// app/Core/FormWizard.php

namespace App\Core;

final class FormWizard
{
    public function buildFromEntity(array $entity, array $options = []): array
    {
        $name = (string) ($entity['name'] ?? 'entidad');
        $label = (string) ($entity['label'] ?? $name);
        $autoMasterDetail = $this->detectMasterDetail($entity);
        $masterDetail = (bool) ($options['master_detail'] ?? false) || $autoMasterDetail;
        $reportType = (string) ($options['report_type'] ?? '');
        $reportTemplate = (string) ($options['template'] ?? '');
        if ($reportType === '' || $reportType === 'auto') {
            $reportType = $this->guessReportType($name, $label, $masterDetail);
        }
        if ($reportTemplate === '' || $reportTemplate === 'auto') {
            $reportTemplate = in_array($reportType, ['invoice', 'quote'], true) ? 'fiscal' : 'basic';
        }

        $form = [
            'type' => 'form',
            'name' => $name . '.form',
            'title' => $label,
            'version' => '1.0',
            'mode' => 'create',
            'entity' => $name,
            'master_detail' => $masterDetail,
            'layout' => [
                'type' => 'sections',
                'gap' => '4',
                'sections' => [],
            ],
            'fields' => [],
            'grids' => [],
            'summary' => [],
        ];

        $fields = $this->entityFields($entity);
        foreach ($fields as $field) {
            $form['fields'][] = [
                'id' => $field['name'],
                'name' => $field['name'],
                'type' => $this->mapFieldType($field['type'] ?? 'string'),
                'label' => $field['label'] ?? $field['name'],
                'validation' => [
                    'required' => (bool) ($field['required'] ?? false),
                ],
            ];
        }

        if (!empty($form['fields'])) {
            $form['layout']['sections'][] = [
                'id' => 'encabezado',
                'title' => 'Encabezado',
                'columns' => 2,
                'fields' => array_map(fn($f) => $f['name'], $form['fields'])
            ];
        }

        $hasGrids = false;
        foreach (($entity['grids'] ?? []) as $grid) {
            if (!is_array($grid)) {
                continue;
            }
            $gridName = $grid['name'] ?? '';
            if ($gridName === '') {
                continue;
            }
            $hasGrids = true;
            $columns = $this->gridColumns($entity, $gridName);
            $form['grids'][] = [
                'name' => $gridName,
                'label' => $grid['label'] ?? $gridName,
                'columns' => $columns,
            ];

            $summaryCandidates = $this->summaryCandidates($columns, $gridName);
            foreach ($summaryCandidates as $candidate) {
                $form['summary'][] = $candidate;
            }
        }

        if ($hasGrids) {
            $issuerFields = $this->pickFieldsByHint($form['fields'], ['empresa', 'emisor', 'razon', 'nit', 'direccion', 'telefono', 'correo'], 5);
            $buyerFields = $this->pickFieldsByHint($form['fields'], ['cliente', 'comprador', 'razon', 'nit', 'documento', 'email', 'direccion', 'telefono'], 6);
            $metaFields = $this->pickFieldsByHint($form['fields'], ['fecha', 'numero', 'serie', 'prefijo'], 4);
            $form['reports'] = [
                [
                    'id' => 'rep_' . $name,
                    'name' => $this->guessReportName($label, $reportType),
                    'type' => $reportType,
                    'template' => $reportTemplate,
                    'description' => 'Reporte generado automaticamente',
                    'layout' => [
                        'header' => [
                            'title' => $label,
                            'subtitle' => $reportType === 'invoice' ? 'Factura' : ($reportType === 'quote' ? 'Cotizacion' : 'Reporte'),
                            'showLogo' => true
                        ],
                        'meta' => [
                            'label' => 'Documento',
                            'fields' => $metaFields,
                        ],
                        'issuer' => [
                            'label' => 'Emisor',
                            'fields' => $issuerFields,
                        ],
                        'buyer' => [
                            'label' => 'Cliente',
                            'fields' => $buyerFields,
                        ],
                        'fields' => array_map(fn($f) => $f['name'], $form['fields']),
                        'grid' => [
                            'name' => $form['grids'][0]['name'] ?? '',
                            'columns' => array_map(fn($c) => $c['name'], $form['grids'][0]['columns'] ?? []),
                        ],
                        'totals' => array_map(fn($s) => $s['name'], $form['summary']),
                        'footer' => [
                            'text' => '',
                        ],
                    ],
                ]
            ];
        }

        if (!empty($form['summary'])) {
            $widgets = [];
            foreach ($form['summary'] as $sum) {
                $widgets[] = [
                    'type' => 'kpi',
                    'label' => $sum['label'] ?? $sum['name'],
                    'source' => ['summary' => $sum['name']],
                ];
            }
            if (!empty($form['grids'])) {
                $firstGrid = $form['grids'][0];
                $firstColumn = $firstGrid['columns'][0]['name'] ?? '';
                $widgets[] = [
                    'type' => 'chart',
                    'label' => 'Distribucion',
                    'source' => ['grid' => $firstGrid['name'] ?? '', 'field' => $firstColumn],
                ];
            }
            $form['dashboards'] = [
                [
                    'id' => 'dash_' . $name,
                    'name' => 'Dashboard ' . $label,
                    'widgets' => $widgets,
                ]
            ];
        }

        return $form;
    }

    private function entityFields(array $entity): array
    {
        $fields = [];
        foreach (($entity['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            if ($this->isSystemField($field)) {
                continue;
            }
            if ($this->isGridField($field)) {
                continue;
            }
            $fields[] = $field;
        }
        return $fields;
    }

    private function gridColumns(array $entity, string $gridName): array
    {
        $columns = [];
        foreach (($entity['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $source = $field['source'] ?? '';
            $fieldGrid = $field['grid'] ?? null;
            $isGrid = $fieldGrid === $gridName || $source === "grid:{$gridName}";
            if (!$isGrid) {
                continue;
            }
            $columns[] = [
                'name' => $field['name'],
                'label' => $field['label'] ?? $field['name'],
                'input' => [
                    'type' => $this->mapFieldType($field['type'] ?? 'string'),
                ],
            ];
        }
        return $columns;
    }

    private function summaryCandidates(array $columns, string $gridName): array
    {
        $summary = [];
        foreach ($columns as $col) {
            $name = (string) ($col['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $isNumeric = in_array($col['input']['type'] ?? '', ['number', 'currency'], true);
            if ($isNumeric || preg_match('/total|subtotal|valor|importe/i', $name)) {
                $summary[] = [
                    'name' => 'sum_' . $name,
                    'label' => 'Total ' . ($col['label'] ?? $name),
                    'type' => 'sum',
                    'source' => ['grid' => $gridName, 'field' => $name],
                ];
            }
        }
        return $summary;
    }

    private function isGridField(array $field): bool
    {
        if (!empty($field['grid'])) {
            return true;
        }
        $source = (string) ($field['source'] ?? '');
        return str_starts_with($source, 'grid:');
    }

    private function isSystemField(array $field): bool
    {
        return ($field['source'] ?? '') === 'system' || ($field['primary'] ?? false) === true;
    }

    private function mapFieldType(string $type): string
    {
        $type = strtolower($type);
        return match ($type) {
            'int', 'integer', 'number', 'decimal', 'float', 'money', 'currency' => 'number',
            'bool', 'boolean' => 'checkbox',
            'date', 'datetime' => 'date',
            'text', 'textarea' => 'textarea',
            'select' => 'select',
            default => 'text',
        };
    }

    private function detectMasterDetail(array $entity): bool
    {
        if (!empty($entity['grids']) && is_array($entity['grids'])) {
            return true;
        }
        foreach (($entity['relations'] ?? []) as $rel) {
            if (!is_array($rel)) {
                continue;
            }
            if (strtolower((string) ($rel['type'] ?? '')) === 'hasmany') {
                return true;
            }
        }
        return false;
    }

    private function guessReportType(string $name, string $label, bool $masterDetail): string
    {
        $text = strtolower($name . ' ' . $label);
        if (str_contains($text, 'cotiz')) {
            return 'quote';
        }
        if (str_contains($text, 'factura') || str_contains($text, 'invoice')) {
            return 'invoice';
        }
        return $masterDetail ? 'invoice' : 'report';
    }

    private function guessReportName(string $label, string $type): string
    {
        if ($type === 'invoice') {
            return 'Factura ' . $label;
        }
        if ($type === 'quote') {
            return 'Cotizacion ' . $label;
        }
        return $label;
    }

    private function pickFieldsByHint(array $fields, array $hints, int $limit = 6): array
    {
        $needle = array_map('strtolower', $hints);
        $selected = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            $label = (string) ($field['label'] ?? $name);
            $hay = strtolower($label . ' ' . $name);
            foreach ($needle as $hint) {
                if ($hint !== '' && str_contains($hay, $hint)) {
                    $selected[] = $name;
                    break;
                }
            }
            if (count($selected) >= $limit) {
                break;
            }
        }
        return array_values(array_unique(array_filter($selected)));
    }
}
