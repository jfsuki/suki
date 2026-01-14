<?php
// app/Core/TableGenerator.php

namespace App\Core;

final class TableGenerator
{
    /**
     * Renderiza una tabla vacía que será llenada por JS vía API
     */
    public static function render(array $config): string
    {
        if (empty($config['columns']) || empty($config['endpoint'])) {
            return '<p class="text-red-600">Configuración de tabla inválida</p>';
        }

        $html  = "<table class='w-full text-sm border border-gray-200 rounded-lg overflow-hidden'>";
        $html .= self::renderHeader($config['columns']);
        $html .= self::renderBody($config['endpoint']);
        $html .= "</table>";

        return $html;
    }

    /**
     * Renderiza el <thead>
     */
    private static function renderHeader(array $columns): string
    {
        $html = "<thead class='bg-gray-100'><tr>";

        foreach ($columns as $col) {
            $label = $col['label'] ?? '';
            $html .= "<th class='border p-2 text-left text-xs font-bold uppercase text-gray-600'>{$label}</th>";
        }

        $html .= "</tr></thead>";

        return $html;
    }

    /**
     * Renderiza el <tbody> vacío con endpoint
     * El JS se encarga de llenarlo
     */
    private static function renderBody(string $endpoint): string
    {
        return "<tbody data-endpoint='{$endpoint}' class='divide-y'></tbody>";
    }
}
