<?php

namespace App\Core;

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
            'checkbox' => $this->checkbox($label, $name, $field['value'] ?? '1', $field['defaultValue'] ?? false),
            'textarea' => $this->textarea($label, $name, $value),
            default    => $this->input($label, $name, $type, $value),
        };
    }

    public function input(?string $label, string $name, string $type, string $value = ''): string 
    {
        $labelHtml = $label 
            ? "<label class='block text-xs font-bold text-gray-500 uppercase mb-1'>{$label}</label>" 
            : '';

        return "
        <div class='flex flex-col mb-2'>
            {$labelHtml}
            <input 
                type='{$type}' 
                name='{$name}' 
                value='{$value}' 
                class='border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition shadow-sm'
            >
        </div>";
    }

    public function textarea(?string $label, string $name, string $value = ''): string 
    {
        $labelHtml = $label 
            ? "<label class='block text-xs font-bold text-gray-500 uppercase mb-1'>{$label}</label>" 
            : '';

        return "
        <div class='flex flex-col mb-2'>
            {$labelHtml}
            <textarea 
                name='{$name}' 
                rows='3' 
                class='border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition shadow-sm'
            >{$value}</textarea>
        </div>";
    }

    public function checkbox(string $label, string $name, string $value = '1', $default = false): string 
    {
        $checked = $default ? 'checked' : '';
        return "
        <div class='flex items-center gap-3 mt-4 mb-2 p-2 border rounded-md bg-gray-50'>
            <input 
                type='checkbox' 
                name='{$name}' 
                value='{$value}' 
                {$checked}
                class='h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded'
            >
            <label class='text-sm font-medium text-gray-700'>{$label}</label>
        </div>";
    }

    public function select(string $label, string $name, array|string $options): string 
    {
        $html = "
        <div class='flex flex-col mb-2'>
            <label class='block text-xs font-bold text-gray-500 uppercase mb-1'>{$label}</label>
            <select 
                name='{$name}' 
                class='border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition shadow-sm bg-white'
        ";

        // Manejo de dependencias (API)
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

        // Opciones Estáticas
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
            <div class='mb-2'>
                <label class='block text-xs font-bold text-gray-500 uppercase mb-1'>{$label}</label>
                <div class='px-3 py-2 bg-gray-100 border border-gray-200 rounded-md text-sm text-gray-800 font-medium'>
                    {$value}
                </div>
            </div>
        ";
    }
}