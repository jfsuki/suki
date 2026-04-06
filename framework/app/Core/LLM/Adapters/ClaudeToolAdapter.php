<?php
// framework/app/Core/LLM/Adapters/ClaudeToolAdapter.php

declare(strict_types=1);

namespace App\Core\LLM\Adapters;

use App\Core\SkillRegistry;

/**
 * ClaudeToolAdapter
 * 
 * Convierte el SkillRegistry de SUKI a definiciones de herramientas (tools) 
 * compatibles con la API de Anthropic Messages (Claude).
 */
final class ClaudeToolAdapter
{
    /**
     * @param SkillRegistry $registry
     * @return array<int, array<string, mixed>>
     */
    public function map(SkillRegistry $registry): array
    {
        $tools = [];
        foreach ($registry->all() as $skill) {
            $tools[] = [
                'name' => (string) ($skill['name'] ?? 'unknown_tool'),
                'description' => (string) ($skill['description'] ?? 'No description available.'),
                'input_schema' => $this->normalizeSchema($skill['input_schema'] ?? []),
            ];
        }
        return $tools;
    }

    /**
     * Asegura que el esquema de entrada cumpla con JSON Schema requerido por Claude.
     * 
     * @param array<string, mixed> $inputSchema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $inputSchema): array
    {
        // Si ya tiene el formato de JSON Schema (type: object), lo devolvemos
        if (isset($inputSchema['type']) && $inputSchema['type'] === 'object') {
            return $inputSchema;
        }

        // Si es una lista plana de slots (formato SUKI antiguo/reducido), lo envolvemos
        return [
            'type' => 'object',
            'properties' => $inputSchema['properties'] ?? $inputSchema,
            'required' => $inputSchema['required'] ?? array_keys($inputSchema['properties'] ?? $inputSchema),
        ];
    }
}
