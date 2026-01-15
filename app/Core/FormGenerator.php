<?php

namespace App\Core;

use InvalidArgumentException;

class FormGenerator
{
    protected FormBuilder $builder;

    public function __construct()
    {
        $this->builder = new FormBuilder();
    }

    public function render(array $config): string
    {
        if (!isset($config['type'], $config['name'])) {
            throw new InvalidArgumentException('FormGenerator: falta type o name');
        }

        return match ($config['type']) {
            'form' => $this->renderForm($config),
            'grid' => $this->renderGrid($config),
            default => throw new InvalidArgumentException('Tipo inválido')
        };
    }

    // --- RENDERIZADO DEL FORMULARIO ---
    private function renderForm(array $cfg): string
    {
        if (!isset($cfg['fields'])) {
            throw new InvalidArgumentException('Form: faltan fields');
        }

        $action = $cfg['action'] ?? '#';
        $layout = $cfg['layout'] ?? ['type' => 'grid', 'columns' => 1];

        $html = "<form method='post' action='{$action}' class='space-y-6'>";

        // Sections Layout
        if (($layout['type'] ?? 'grid') === 'sections' && !empty($layout['sections'])) {
            foreach ($layout['sections'] as $section) {
                $html .= $this->renderSection($section, $cfg['fields']);
            }
        } else {
            // Grid Layout simple
            $cols = $layout['columns'] ?? 1;
            $html .= "<div class='grid grid-cols-{$cols} gap-4'>";
            foreach ($cfg['fields'] as $field) {
                $html .= $this->renderField($field);
            }
            $html .= "</div>";
        }

        // Grids integradas
        if (!empty($cfg['grids'])) {
            foreach ($cfg['grids'] as $gridConfig) {
                $html .= $this->renderGrid($gridConfig);
            }
        }

        // Summary
        if (!empty($cfg['summary'])) {
            $html .= $this->renderSummary($cfg['summary']);
        }

        // Botón Submit
        $html .= "
            <div class='pt-4'>
                <button type='submit' class='px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 transition'>
                    Guardar
                </button>
            </div>
        ";

        return $html . "</form>";
    }

    private function renderSection(array $section, array $allFields): string
    {
        $cols   = $section['columns'] ?? 2;
        $fields = $section['fields'] ?? [];

        $html = "<div class='border rounded-lg p-4 space-y-4 bg-white shadow-sm'>";

        if (!empty($section['title'])) {
            $html .= "<h3 class='text-md font-semibold text-gray-800'>{$section['title']}</h3>";
        }
        if (!empty($section['description'])) {
            $html .= "<p class='text-xs text-gray-500 -mt-3'>{$section['description']}</p>";
        }

        $html .= "<div class='grid grid-cols-{$cols} gap-4'>";

        foreach ($fields as $fieldId) {
            foreach ($allFields as $f) {
                if ($f['id'] === $fieldId) {
                    $html .= $this->renderField($f);
                    break;
                }
            }
        }

        return $html . "</div></div>";
    }

    private function renderField(array $f): string
    {
        $mode = $f['mode'] ?? 'edit';

        if ($mode === 'view') {
            return $this->builder->label(
                $f['label'] ?? '',
                $f['value'] ?? ''
            );
        }

        return match ($f['type']) {
            'select'   => $this->builder->select(
                $f['label'] ?? '',
                $f['name'],
                $f['options'] ?? []
            ),
            'checkbox' => $this->builder->checkbox(
                $f['label'] ?? '',
                $f['name'],
                $f['value'] ?? '1',
                $f['defaultValue'] ?? false
            ),
            'textarea' => $this->builder->textarea(
                $f['label'] ?? '',
                $f['name'],
                $f['value'] ?? ''
            ),
            default    => $this->builder->input(
                $f['label'] ?? null,
                $f['name'],
                $f['type'],
                $f['value'] ?? ''
            )
        };
    }

    // --- RENDERIZADO DE LA GRILLA ---
    private function renderGrid(array $cfg): string
    {
        $name = $cfg['name'];
        $html = "<div class='mt-6 mb-6'>";
        
        if (!empty($cfg['label'])) {
            $html .= "<h4 class='font-bold text-gray-700 mb-2'>{$cfg['label']}</h4>";
        }

        $html .= "<table class='w-full border' data-grid='{$name}'>
                    <thead class='bg-gray-50'><tr>";
        
        foreach ($cfg['columns'] as $c) {
            $html .= "<th class='border p-2 text-xs uppercase'>{$c['label']}</th>";
        }
        
        $html .= "<th class='border p-2 w-10'>#</th></tr></thead><tbody></tbody></table>";

        $html .= "<button type='button' data-add-row='{$name}' class='mt-2 bg-green-600 text-white px-3 py-1 rounded text-sm'>+ Agregar ítem</button>";

        // ✅ CORRECCIÓN: Manejo correcto de totales
        $totalColumns = array_filter($cfg['columns'], function($col) {
            return isset($col['total']) && $col['total'] !== false;
        });

        if (!empty($totalColumns)) {
            $html .= "<div class='grid grid-cols-3 gap-4 mt-2 bg-gray-50 p-4 border rounded'>";
            
            foreach ($totalColumns as $col) {
                // ✅ Manejo de total como array u objeto
                $totalLabel = '';
                if (is_array($col['total'])) {
                    $totalLabel = $col['total']['label'] ?? strtoupper($col['name']);
                } elseif ($col['total'] === true) {
                    $totalLabel = 'Total ' . $col['label'];
                } else {
                    $totalLabel = (string)$col['total'];
                }

                $html .= "<div>
                            <label class='text-xs font-bold text-gray-500 uppercase'>{$totalLabel}</label>
                            <div class='text-lg font-bold' data-total='{$name}.{$col['name']}'>0.00</div>
                          </div>";
            }
            
            $html .= "</div>";
        }

        // Configuración oculta para el JS
        $html .= "<script type='application/json' data-grid-config='{$name}'>" . json_encode($cfg) . "</script></div>";
        
        return $html;
    }

    //para arreglar el select

    private function renderGridCell(array $col, string $gridName, int $rowIndex): string
{
    $name = "{$gridName}[{$rowIndex}][{$col['name']}]";
    $base = "w-full border rounded px-2 py-1 text-sm";

    $input = $col['input'] ?? ['type' => 'text'];
    $type  = $input['type'] ?? 'text';

    // ✅ SELECT
    if ($type === 'select') {
        $html = "<select name='{$name}' class='{$base}' 
                    data-column='{$col['name']}'
                    data-grid-name='{$gridName}'
                    data-row-index='{$rowIndex}'>";

        foreach (($input['options'] ?? []) as $opt) {
            $value = htmlspecialchars($opt['value']);
            $label = htmlspecialchars($opt['label']);
            $html .= "<option value='{$value}'>{$label}</option>";
        }

        return $html . "</select>";
    }

    // ✅ DEFAULT INPUT
    return "<input 
        type='{$type}' 
        name='{$name}'
        class='{$base}'
        data-column='{$col['name']}'
        data-grid-name='{$gridName}'
        data-row-index='{$rowIndex}'
    />";
}


    // --- RENDERIZADO DEL SUMMARY ---
    protected function renderSummary(array $summary): string
    {
        $html = "
            <div class='mt-8 p-6 bg-indigo-50 border border-indigo-100 rounded-lg'>
                <h4 class='text-lg font-bold text-indigo-900 mb-4 border-b border-indigo-200 pb-2'>Resumen de Facturación</h4>
                <div class='space-y-3 max-w-md ml-auto'>";

        foreach ($summary as $item) {
            $name   = $item['name'];
            $label  = $item['label'] ?? strtoupper($name);
            $isTotal = $name === 'total_general';
            
            $textClass  = $isTotal ? 'text-indigo-900' : 'text-gray-600';
            $fontClass  = $isTotal ? 'text-2xl font-extrabold' : 'text-sm font-medium';
            $wrapper    = $isTotal ? 'pt-2 border-t border-indigo-200 mt-2' : '';

            $html .= "
                <div class='flex justify-between items-center {$wrapper}'>
                    <span class='{$textClass} text-sm'>{$label}</span>
                    <span class='{$fontClass} {$textClass}' data-summary='{$name}'>0.00</span>
                </div>
            ";
        }

        return $html . "</div></div>";
    }
}