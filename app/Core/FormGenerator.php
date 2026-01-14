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

    /**
     * Punto único de entrada
     */
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

    /* =====================================================
     * FORMULARIO (MAESTRO)
     * ===================================================== */
    private function renderForm(array $cfg): string
    {
        if (!isset($cfg['fields'])) {
            throw new InvalidArgumentException('Form: faltan fields');
        }

        $action = $cfg['action'] ?? '#';
        $layout = $cfg['layout'] ?? ['type' => 'grid', 'columns' => 1];

        $html = "<form method='post' action='{$action}' class='space-y-6'>";

        // -------- SECTIONS --------
        if (($layout['type'] ?? 'grid') === 'sections' && !empty($layout['sections'])) {
            foreach ($layout['sections'] as $section) {
                $html .= $this->renderSection($section, $cfg['fields']);
            }
        } else {
            $cols = $layout['columns'] ?? 1;
            $html .= "<div class='grid grid-cols-{$cols} gap-4'>";

            foreach ($cfg['fields'] as $field) {
                $html .= $this->renderField($field);
            }

            $html .= "</div>";
        }

        // -------- GRIDS (DENTRO DEL FORM) --------
        if (!empty($cfg['grids'])) {
            foreach ($cfg['grids'] as $gridConfig) {
                $html .= $this->renderGrid($gridConfig);
            }
        }

        // -------- SUMMARY DEL FORM (DESPUÉS DE GRIDS) --------
        if (!empty($cfg['summary'])) {
            $html .= $this->renderSummary($cfg['summary']);
        }

        // -------- BOTÓN --------
        $html .= "
            <div class='pt-4'>
                <button type='submit' class='px-4 py-2 bg-indigo-600 text-white rounded'>
                    Guardar
                </button>
            </div>
        ";

        return $html . "</form>";
    }

    /* =====================================================
     * SECTION (VISUAL)
     * ===================================================== */
    private function renderSection(array $section, array $allFields): string
    {
        $cols   = $section['columns'] ?? 1;
        $fields = $section['fields'] ?? [];

        $html = "<div class='border rounded-lg p-4 space-y-4'>";

        if (!empty($section['title'])) {
            $html .= "<h3 class='text-sm font-semibold text-gray-700'>{$section['title']}</h3>";
        }

        if (!empty($section['description'])) {
            $html .= "<p class='text-xs text-gray-500'>{$section['description']}</p>";
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

    /* =====================================================
     * FIELD
     * ===================================================== */
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
                $f['value'] ?? '1'
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

    /* =====================================================
     * GRID
     * ===================================================== */
    private function renderGrid(array $cfg): string
    {
        if (!isset($cfg['columns'], $cfg['name'])) {
            throw new InvalidArgumentException('Grid: configuración inválida');
        }

        $name = $cfg['name'];
        $mode = $cfg['mode'] ?? 'create';

        $html = "<div class='mt-6 space-y-4'>";

        // Título del grid (si existe)
        if (!empty($cfg['label'])) {
            $html .= "<h4 class='text-sm font-semibold text-gray-700'>{$cfg['label']}</h4>";
        }

        $html .= "<table class='w-full border text-sm' data-grid='{$name}'>
                    <thead class='bg-gray-100'><tr>";

        foreach ($cfg['columns'] as $c) {
            $html .= "<th class='border p-2'>{$c['label']}</th>";
        }

        $html .= "<th class='border p-2'>Acciones</th></tr></thead><tbody></tbody></table>";

        if ($mode !== 'view') {
            $html .= "
                <button type='button'
                    class='px-4 py-2 bg-indigo-600 text-white rounded text-sm'
                    data-add-row='{$name}'>
                    + Agregar fila
                </button>
            ";
        }

        // Totales visuales del grid
        $totalColumns = array_filter($cfg['columns'], fn($c) => isset($c['total']));

        if ($totalColumns) {
            $html .= "<div class='p-4 border rounded bg-gray-50 grid grid-cols-2 gap-4'>";

            foreach ($totalColumns as $col) {
                $label = $col['total']['label'] ?? strtoupper($col['name']);

                $html .= "
                    <div class='flex justify-between items-center'>
                        <span class='text-sm font-semibold text-gray-600'>{$label}</span>
                        <span class='text-lg font-bold text-indigo-600'
                              data-total='{$name}.{$col['name']}'>0.00</span>
                    </div>
                ";
            }

            $html .= "</div>";
        }

        // ✅ CRÍTICO: Config JSON con identificador único
        $html .= "
            <script type='application/json' data-grid-config='{$name}'>
                " . json_encode($cfg) . "
            </script>
        ";

        return $html . "</div>";
    }

    /* =====================================================
     * SUMMARY DEL FORM
     * ===================================================== */
    protected function renderSummary(array $summary): string
    {
        $html = "
            <div class='mt-8 p-6 border-2 border-indigo-200 rounded-lg bg-indigo-50'>
                <h4 class='text-lg font-bold text-gray-800 mb-4'>Resumen General</h4>
                <div class='grid grid-cols-2 gap-4'>
        ";

        foreach ($summary as $item) {
            $name  = $item['name'];
            $label = $item['label'] ?? strtoupper($name);

            $highlight = $name === 'total_general'
                ? 'text-2xl text-indigo-700 font-extrabold'
                : 'text-lg text-gray-700 font-bold';

            $html .= "
                <div class='flex justify-between items-center border-b border-gray-300 pb-2'>
                    <span class='text-sm font-semibold text-gray-600'>
                        {$label}
                    </span>
                    <span class='{$highlight}'
                          data-summary='{$name}'>
                        0.00
                    </span>
                </div>
            ";
        }

        return $html . "</div></div>";
    }
}