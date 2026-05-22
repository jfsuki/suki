<?php

namespace App\Core\Agents\Registry;

use RuntimeException;

/**
 * SpecialistPersonas
 *
 * Carga las definiciones de agentes desde framework/data/specialist_personas.json.
 * Para añadir o modificar un agente, edita el JSON — no este archivo.
 */
class SpecialistPersonas
{
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $registry = null;

    private static function loadRegistry(): void
    {
        if (self::$registry !== null) {
            return;
        }
        $path = dirname(__DIR__, 4) . '/data/specialist_personas.json';
        if (!file_exists($path)) {
            throw new RuntimeException('specialist_personas.json no encontrado en: ' . $path);
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('specialist_personas.json tiene formato inválido.');
        }
        self::$registry = $decoded;
    }

    /** @return array<string,mixed> */
    public static function getPersona(string $area): array
    {
        self::loadRegistry();
        $key = strtoupper($area);
        if (isset(self::$registry[$key])) {
            return (array) self::$registry[$key];
        }
        return [
            'name' => "Specialist in $area",
            'role' => 'General Assistant',
            'description' => "Agente de soporte para el área de $area.",
            'prompt_base' => "Eres un asistente especializado en $area. Ayuda al usuario con tareas generales de este dominio.",
            'capabilities' => ['general_support'],
            'llm_params' => ['temperature' => 0.3, 'max_tokens' => 400],
        ];
    }

    /**
     * Tenant-aware persona lookup: custom DB agent first, then global JSON fallback.
     *
     * @return array<string,mixed>
     */
    public static function getPersonaForTenant(string $area, string $tenantId, ?\PDO $db = null): array
    {
        if ($db !== null && $tenantId !== '') {
            try {
                $stmt = $db->prepare(
                    'SELECT role, prompt_override, qdrant_collection, config_json
                     FROM ai_agents
                     WHERE tenant_id = ? AND area = ? AND status != \'DISABLED\'
                     ORDER BY created_at DESC LIMIT 1'
                );
                $stmt->execute([$tenantId, $area]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row) && trim((string) ($row['prompt_override'] ?? '')) !== '') {
                    $base = self::getPersona($area);
                    $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
                    $base['prompt_base'] = (string) $row['prompt_override'];
                    $base['source'] = 'tenant_custom';
                    if (!empty($row['qdrant_collection'])) {
                        $base['qdrant_collection'] = (string) $row['qdrant_collection'];
                    }
                    if (is_array($config) && !empty($config)) {
                        $base['config'] = $config;
                    }
                    return $base;
                }
            } catch (\Throwable $e) {
                // Graceful degradation: DB error falls through to global JSON
            }
        }
        return self::getPersona($area);
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        self::loadRegistry();
        return (array) self::$registry;
    }

    public static function exists(string $area): bool
    {
        self::loadRegistry();
        return isset(self::$registry[strtoupper($area)]);
    }
}
