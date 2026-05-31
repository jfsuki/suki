<?php

declare(strict_types=1);

namespace App\Core\Grounding;

use App\Core\PolicyLoader;

/**
 * Output Validator — SCML Fase 3.
 *
 * Valida el JSON que emite el LLM antes de despacharlo al PHP handler.
 * Se ubica entre LLMRouter::complete() y DynamicSkillRegistry::dispatch().
 *
 * Contratos:
 *   - skill name → allowlist [a-zA-Z0-9_]{1,64}
 *   - skill debe existir en ExecutionRegistry (catalog verificado contra PHP real)
 *   - data keys → subset de skill_params[skill] en skills_catalog.json
 *   - data values → sanitizados (truncar strings, descartar keys inválidos, limitar depth)
 *   - action → inyectado desde registry si no viene en data
 *
 * Modos:
 *   - strict=true (default): parámetros desconocidos → error + clarificación al usuario
 *   - strict=false (dev):    parámetros desconocidos → descartados silenciosamente
 *
 * Config dinámica: routing_policies.json#grounding (max_data_value_length, max_data_depth,
 *   output_validator_strict, allowed_intent_pattern)
 */
final class OutputValidator
{
    private ?array $groundingCfg = null;

    public function __construct(
        private readonly ExecutionRegistry $registry,
        private ?string $policiesPath = null
    ) {
        $this->policiesPath = $policiesPath
            ?? dirname(__DIR__, 4) . '/framework/config/routing_policies.json';
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Valida el JSON emitido por el LLM.
     *
     * @param  array<string, mixed> $json  JSON parseado de la respuesta LLM
     * @return array{
     *   valid: bool,
     *   skill?: string,
     *   data?: array<string, mixed>,
     *   action?: string|null,
     *   error?: string,
     *   unknown_keys?: list<string>,
     *   clarification_message?: string
     * }
     */
    public function validate(array $json): array
    {
        $skill = trim((string) ($json['skill'] ?? ''));
        $data  = is_array($json['data'] ?? null) ? $json['data'] : [];

        // 1. Skill name — allowlist
        $pattern = (string) $this->cfg('allowed_intent_pattern', '^[a-zA-Z0-9_]{1,64}$');
        if ($skill === '' || !preg_match('/' . $pattern . '/', $skill)) {
            return [
                'valid' => false,
                'error' => 'invalid_skill_name',
                'skill' => substr($skill, 0, 50),
            ];
        }

        // 2. Skill debe existir en registry (catalog + código PHP verificado)
        $entry = $this->registry->getEntry($skill);
        if ($entry === null) {
            return [
                'valid'                 => false,
                'error'                 => 'skill_not_found',
                'skill'                 => $skill,
                'clarification_message' => $this->notFoundMessage($skill),
            ];
        }

        // 3. Validar keys de data contra skill_params
        $knownKeys      = $entry['input_keys'];
        $paramsDeclared = $entry['params_declared'] ?? false;
        $strict         = (bool) $this->cfg('output_validator_strict', true);

        // Caso A: skill declaró skill_params:{} (sin params) pero LLM envió data
        if ($paramsDeclared && empty($knownKeys) && !empty($data)) {
            if ($strict) {
                return [
                    'valid'                 => false,
                    'error'                 => 'no_params_expected',
                    'skill'                 => $skill,
                    'clarification_message' => "La acción '{$skill}' no requiere parámetros adicionales.",
                ];
            }
            $data = []; // Non-strict: descartar silenciosamente
        }

        // Caso B: hay params conocidos → verificar que los del LLM sean subset
        if (!empty($knownKeys)) {
            $dataKeys    = array_keys($data);
            $unknownKeys = array_values(array_diff($dataKeys, $knownKeys));

            if (!empty($unknownKeys)) {
                if ($strict) {
                    return [
                        'valid'                 => false,
                        'error'                 => 'unknown_params',
                        'skill'                 => $skill,
                        'unknown_keys'          => $unknownKeys,
                        'clarification_message' => $this->unknownParamsMessage($skill, $entry, $unknownKeys),
                    ];
                }
                // Non-strict: descartar keys desconocidas
                $data = array_intersect_key($data, array_flip($knownKeys));
            }
        }

        // 4. Sanitizar valores de data (anti-injection + truncar)
        $sanitized = $this->sanitizeData($data, 0);

        // 5. Inyectar action desde registry si no viene en data y el skill lo requiere
        $action = $entry['action'] ?? null;
        if ($action !== null && !isset($sanitized['action'])) {
            $sanitized['action'] = $action;
        }

        return [
            'valid'  => true,
            'skill'  => $skill,
            'data'   => $sanitized,
            'action' => $action,
        ];
    }

    /**
     * Indica si el JSON del LLM contiene una clave `skill` (es una llamada a skill).
     */
    public function isSkillCall(array $json): bool
    {
        return isset($json['skill']) && is_string($json['skill']) && $json['skill'] !== '';
    }

    // -------------------------------------------------------------------------
    // Private — sanitización y mensajes
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeData(array $data, int $depth): array
    {
        $maxDepth    = (int) $this->cfg('max_data_depth', 5);
        $maxValueLen = (int) $this->cfg('max_data_value_length', 2000);

        if ($depth >= $maxDepth) {
            return [];
        }

        $result  = [];
        $pattern = (string) $this->cfg('allowed_intent_pattern', '^[a-zA-Z0-9_]{1,64}$');

        foreach ($data as $key => $value) {
            // Las claves deben ser identificadores válidos
            if (!preg_match('/' . $pattern . '/', (string) $key)) {
                continue;
            }

            if (is_string($value)) {
                $result[$key] = mb_substr($value, 0, $maxValueLen);
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitizeData($value, $depth + 1);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $result[$key] = $value;
            }
            // Descartar objects/resources — no son tipos válidos en skill data
        }

        return $result;
    }

    private function notFoundMessage(string $skill): string
    {
        return "No encontré la acción '{$skill}'. Por favor describe mejor lo que necesitas.";
    }

    private function unknownParamsMessage(string $skill, array $entry, array $unknownKeys): string
    {
        $known = implode(', ', $entry['input_keys'] ?: ['ninguno declarado']);
        $hint  = count($unknownKeys) === 1
            ? "El parámetro '" . $unknownKeys[0] . "' no es válido para '{$skill}'."
            : "Los parámetros [" . implode(', ', $unknownKeys) . "] no son válidos para '{$skill}'.";
        return "{$hint} Parámetros esperados: {$known}.";
    }

    private function cfg(string $key, mixed $default): mixed
    {
        if ($this->groundingCfg === null) {
            $this->groundingCfg = [];
            // Usa PolicyLoader (con caché) si está disponible; si no, lee el archivo directo
            try {
                $all = PolicyLoader::load('routing_policies');
                if (is_array($all['grounding'] ?? null)) {
                    $this->groundingCfg = $all['grounding'];
                }
            } catch (\Throwable) {
                if (is_file($this->policiesPath ?? '')) {
                    $raw  = file_get_contents($this->policiesPath);
                    $data = $raw !== false ? json_decode($raw, true) : null;
                    if (is_array($data['grounding'] ?? null)) {
                        $this->groundingCfg = $data['grounding'];
                    }
                }
            }
        }

        return $this->groundingCfg[$key] ?? $default;
    }
}
