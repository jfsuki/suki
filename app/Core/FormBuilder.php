<?php
// app/Core/FormBuilder.php

namespace App\Core;

/**
 * FormBuilder
 * ------------
 * Responsabilidad única:
 * Renderizar CAMPOS individuales.
 *
 * ❌ No conoce formularios
 * ❌ No conoce layouts
 * ❌ No conoce grids
 *
 * ✅ Solo UI de campos
 */
class FormBuilder
{
    public function renderField(array $field): string
    {
        $type  = $field['type'] ?? 'text';
        $name  = $field['name'] ?? '';
        $label = $field['label'] ?? null;
        $value = $field['value'] ?? '';

        return match ($type) {
            'select'   => $this->select($label, $name, $field['options'] ?? []),
            'checkbox' => $this->checkbox($label, $name, $field['value'] ?? '1'),
            'textarea' => $this->textarea($label, $name, $value),
            default    => $this->input($label, $name, $type, $value),
        };
    }

    public function input(
        ?string $label,
        string $name,
        string $type,
        string $value = ''
    ): string {
        $labelHtml = $label
            ? "<label class='text-xs font-bold text-gray-400 mb-1'>{$label}</label>"
            : '';

        return "
        <div class='flex flex-col gap-1'>
            {$labelHtml}
            <input
                type='{$type}'
                name='{$name}'
                value='{$value}'
                class='border rounded-lg p-2 text-sm'
            >
        </div>";
    }

    public function textarea(
        ?string $label,
        string $name,
        string $value = ''
    ): string {
        $labelHtml = $label
            ? "<label class='text-xs font-bold text-gray-400 mb-1'>{$label}</label>"
            : '';

        return "
        <div class='flex flex-col gap-1'>
            {$labelHtml}
            <textarea
                name='{$name}'
                rows='3'
                class='border rounded-lg p-2 text-sm'
            >{$value}</textarea>
        </div>";
    }

    public function checkbox(
        string $label,
        string $name,
        string $value = '1'
    ): string {
        return "
        <div class='flex items-center gap-2'>
            <input
                type='checkbox'
                name='{$name}'
                value='{$value}'
                class='rounded border-gray-300'
            >
            <label class='text-sm text-gray-700'>{$label}</label>
        </div>";
    }

    public function select(
        string $label,
        string $name,
        array|string $options
    ): string {
        $html = "
        <div class='flex flex-col'>
            <label class='text-xs font-bold text-gray-400 mb-1'>{$label}</label>
            <select
                name='{$name}'
                class='border rounded-lg p-2 text-sm'
        ";

        // =====================================================
        // OPCIONES DINÁMICAS (dependsOn / api)
        // =====================================================
        if (is_array($options) && isset($options['dependsOn'])) {
            $html .= "
                data-depends-on='{$options['dependsOn']}'
                data-source='{$options['source']}'
                data-endpoint='{$options['endpoint']}'
                data-map-value='{$options['map']['value']}'
                data-map-label='{$options['map']['label']}'
            ";
            $html .= ">
                <option value=''>Seleccione...</option>
            </select></div>";

            return $html;
        }

        // =====================================================
        // OPCIONES ESTÁTICAS
        // =====================================================
        $html .= ">
            <option value=''>Seleccione...</option>";

        if (is_array($options)) {
            foreach ($options as $opt) {
                $value = $opt['value'] ?? '';
                $text  = $opt['label'] ?? '';
                $html .= "<option value='{$value}'>{$text}</option>";
            }
        }

        return $html . "</select></div>";
    }

    public function label(string $label, $value = ''): string
    {
        return "
            <div>
                <label class='text-xs font-semibold text-gray-500 uppercase'>{$label}</label>
                <div class='mt-1 p-2 bg-gray-100 border rounded text-sm font-medium'>
                    {$value}
                </div>
            </div>
        ";
    }
}