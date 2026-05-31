<?php

declare(strict_types=1);

namespace App\Core\Grounding;

use App\Core\PolicyLoader;

/**
 * PHP Execution Registry — SCML Fase 2 (rev. seguridad + dinámica).
 *
 * Prioridades de datos (en orden descendente):
 *   1. skills_catalog.json  → action + skill_params  (JSON-driven, zero PHP para nuevas skills)
 *   2. SKILL_ACTION_MAP PHP → action via Reflection  (retrocompatibilidad)
 *   3. Análisis estático    → input_keys via regex   (fallback)
 *
 * Config dinámica desde routing_policies.json (sección "grounding"):
 *   - context_keys, callable_methods, max_params_in_hint, max_description_length, allowed_intent_pattern
 *
 * Seguridad: sanitizeForPrompt() elimina patrones de prompt injection antes
 * de que cualquier dato llegue al system prompt del LLM.
 */
final class ExecutionRegistry
{
    private ?array $registry = null;

    private string $catalogPath;
    private string $frameworkRoot;
    private string $policiesPath;

    /** Cache de la sección grounding leída de routing_policies.json */
    private ?array $groundingCfg = null;

    public function __construct(
        ?string $catalogPath  = null,
        ?string $frameworkRoot = null,
        ?string $policiesPath  = null
    ) {
        $this->catalogPath   = $catalogPath   ?? dirname(__DIR__, 4) . '/docs/contracts/skills_catalog.json';
        $this->frameworkRoot = $frameworkRoot ?? dirname(__DIR__, 3);
        $this->policiesPath  = $policiesPath  ?? dirname(__DIR__, 3) . '/config/routing_policies.json';
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Construye el registro completo.
     *
     * @return array<string, array<string, mixed>>
     */
    public function build(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $catalog    = $this->loadCatalog();
        $classCache = [];
        $this->registry = [];

        foreach ($catalog as $skill) {
            $handler = (string) ($skill['handler'] ?? '');
            $intent  = $this->sanitizeIdentifier((string) ($skill['name'] ?? ''));
            if ($handler === '' || $intent === '') {
                continue;
            }

            [$className, $methodName] = $this->parseHandler($handler);

            if (!isset($classCache[$className])) {
                $classCache[$className] = $this->inspectClass($className);
            }
            $info = $classCache[$className];

            $declaredExists = $info['methods'][$methodName] ?? false;
            $handleExists   = $info['methods']['handle']    ?? false;

            // Prioridad 1: action desde catalog JSON
            $catalogAction = isset($skill['action']) && is_string($skill['action'])
                ? $this->sanitizeIdentifier($skill['action'])
                : null;

            // Prioridad 2: action desde SKILL_ACTION_MAP PHP (retrocompat)
            $reflectionAction = $info['action_map'][$intent] ?? null;

            $resolvedAction = $catalogAction !== '' ? $catalogAction : ($reflectionAction ?? null);

            // Prioridad 1: skill_params desde catalog JSON
            $catalogParams = is_array($skill['skill_params'] ?? null)
                ? array_keys($skill['skill_params'])
                : null;

            // Prioridad 2: input_keys extraídas por análisis estático
            $resolvedParams = $catalogParams ?? $info['input_keys'];

            $this->registry[$intent] = [
                'intent'          => $intent,
                'handler'         => $handler,
                'class'           => $className,
                'method'          => $methodName,
                'class_exists'    => $info['exists'],
                'method_exists'   => $declaredExists || $handleExists,
                'method_is_alias' => !$declaredExists && $handleExists,
                'action'          => $resolvedAction !== '' ? $resolvedAction : null,
                'input_keys'      => $resolvedParams,
                'deps'            => $info['deps'],
                'topic_cluster'   => $this->sanitizeIdentifier((string) ($skill['topic_cluster'] ?? 'general')),
                'source'          => $catalogAction !== '' ? 'catalog' : ($reflectionAction !== null ? 'reflection' : 'static'),
            ];
        }

        return $this->registry;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEntry(string $intent): ?array
    {
        return $this->build()[$intent] ?? null;
    }

    /**
     * Codegraph: clase PHP → {intents, deps, action_map, input_keys}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCodeGraph(): array
    {
        $graph = [];

        foreach ($this->build() as $entry) {
            $class = $entry['class'];

            if (!isset($graph[$class])) {
                $graph[$class] = [
                    'class'        => $class,
                    'class_exists' => $entry['class_exists'],
                    'method'       => $entry['method'],
                    'deps'         => $entry['deps'],
                    'input_keys'   => $entry['input_keys'],
                    'intents'      => [],
                    'action_map'   => [],
                ];
            }

            $graph[$class]['intents'][] = $entry['intent'];
            if ($entry['action'] !== null) {
                $graph[$class]['action_map'][$entry['intent']] = $entry['action'];
            }
        }

        return $graph;
    }

    /**
     * @return array{valid: bool, entries: int, errors: list<string>, warnings: list<string>}
     */
    public function validate(): array
    {
        $errors   = [];
        $warnings = [];

        foreach ($this->build() as $intent => $entry) {
            if (!$entry['class_exists']) {
                $errors[] = "CLASS_NOT_FOUND: {$entry['class']} (intent: {$intent})";
                continue;
            }
            if (!$entry['method_exists']) {
                $errors[] = "METHOD_NOT_FOUND: {$entry['class']}::{$entry['method']} (intent: {$intent})";
            } elseif ($entry['method_is_alias'] ?? false) {
                $warnings[] = "METHOD_ALIAS: {$entry['class']} catalog={$entry['method']} real=handle() (intent: {$intent})";
            }
            if (empty($entry['input_keys'])) {
                $warnings[] = "NO_INPUT_KEYS: {$intent} — sin claves detectables (action={$entry['action']})";
            }
        }

        return [
            'valid'    => empty($errors),
            'entries'  => count($this->registry ?? []),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Hint de firma para el bloque de grounding del LLM.
     * Todos los valores sanitizados antes de retornar.
     */
    public function renderSignatureHint(string $intent): string
    {
        $entry = $this->getEntry($intent);
        if ($entry === null) {
            return '';
        }

        $maxParams   = (int) $this->cfg('max_params_in_hint', 7);
        $contextKeys = (array) $this->cfg('context_keys', ['tenant_id', 'agent_id', 'user_id', 'session_id']);

        $parts = [];

        if ($entry['action'] !== null) {
            $safeAction = $this->sanitizeIdentifier($entry['action']);
            if ($safeAction !== '') {
                $parts[] = "Acción: {$safeAction}";
            }
        }

        $keys = array_values(array_diff($entry['input_keys'], $contextKeys));
        if ($entry['action'] !== null) {
            $keys = array_values(array_diff($keys, ['action']));
        }

        $safeKeys = array_filter(
            array_map([$this, 'sanitizeIdentifier'], array_slice($keys, 0, $maxParams)),
            static fn($k) => $k !== ''
        );

        if (!empty($safeKeys)) {
            $parts[] = 'Parámetros: ' . implode(', ', $safeKeys);
        }

        return implode(' | ', $parts);
    }

    /**
     * Sanitiza texto libre que se inyectará en el system prompt del LLM.
     * Elimina patrones de prompt injection, trunca a max_description_length.
     */
    public function sanitizeForPrompt(string $text): string
    {
        $maxLen = (int) $this->cfg('max_description_length', 200);

        // Truncar primero para no procesar strings enormes
        $text = mb_substr($text, 0, $maxLen * 3);

        // Eliminar null bytes y caracteres de control (excepto \n \t)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        // Neutralizar marcadores de bloque que podrían romper el system prompt
        $text = preg_replace('/={3,}/', '---', $text) ?? $text;

        // Neutralizar comandos de sistema embebidos
        $injectionPatterns = [
            '/\[SISTEMA\s*:/i',
            '/\[SYSTEM\s*:/i',
            '/INSTRUCCION\s*:/i',
            '/INSTRUCTION\s*:/i',
            '/ignore\s+previous\s+instructions/i',
            '/olvida\s+(las\s+)?instrucciones\s+anteriores/i',
        ];
        foreach ($injectionPatterns as $pattern) {
            $text = preg_replace($pattern, '[INFO:', $text) ?? $text;
        }

        // Colapsar saltos de línea múltiples
        $text = preg_replace('/[\r\n]{2,}/', "\n", $text) ?? $text;

        return mb_substr(trim($text), 0, $maxLen);
    }

    /**
     * Valida que un nombre de intent/cluster/action sea seguro (solo [a-zA-Z0-9_]).
     * Retorna '' si no pasa el allowlist.
     */
    public function sanitizeIdentifier(string $value): string
    {
        $pattern = (string) $this->cfg('allowed_intent_pattern', '^[a-zA-Z0-9_]{1,64}$');
        return preg_match('/' . $pattern . '/', $value) ? $value : '';
    }

    // -------------------------------------------------------------------------
    // Private — inspección por reflexión
    // -------------------------------------------------------------------------

    private function parseHandler(string $handler): array
    {
        $parts = explode('@', $handler, 2);
        return [$parts[0], $parts[1] ?? 'execute'];
    }

    private function inspectClass(string $className): array
    {
        $callableMethods = (array) $this->cfg('callable_methods', ['execute', 'handle', 'calculate']);

        $methodDefaults = [];
        foreach ($callableMethods as $m) {
            $methodDefaults[(string) $m] = false;
        }

        $result = [
            'exists'     => false,
            'methods'    => $methodDefaults,
            'deps'       => [],
            'input_keys' => [],
            'action_map' => [],
        ];

        if (!class_exists($className, true)) {
            $path = $this->resolveClassPath($className);
            if ($path !== null) {
                require_once $path;
            }
        }

        if (!class_exists($className, false)) {
            return $result;
        }

        $result['exists'] = true;
        $ref = new \ReflectionClass($className);

        foreach ($callableMethods as $m) {
            $result['methods'][(string) $m] = $ref->hasMethod((string) $m);
        }

        $ctor = $ref->getConstructor();
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $parts = explode('\\', $type->getName());
                    $name  = end($parts);
                    if ($name !== false && $name !== '') {
                        $result['deps'][] = $name;
                    }
                }
            }
        }

        if ($ref->hasConstant('SKILL_ACTION_MAP')) {
            $constRef = $ref->getReflectionConstant('SKILL_ACTION_MAP');
            if ($constRef !== false) {
                $val = $constRef->getValue();
                if (is_array($val)) {
                    $result['action_map'] = $val;
                }
            }
        }

        $filePath = $ref->getFileName();
        if ($filePath !== false && $filePath !== '') {
            $result['input_keys'] = $this->extractInputKeys($filePath);
        }

        return $result;
    }

    private function extractInputKeys(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }

        $source = (string) file_get_contents($filePath);
        $keys   = [];

        if (preg_match_all('/[$](?:input|args)\[\'([a-zA-Z_][a-zA-Z0-9_]*)\'\]/', $source, $matches)) {
            foreach ($matches[1] as $key) {
                if (!in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return array_values($keys);
    }

    private function resolveClassPath(string $className): ?string
    {
        if (!str_starts_with($className, 'App\\')) {
            return null;
        }
        $relative = str_replace('\\', '/', substr($className, 4));
        $path     = $this->frameworkRoot . '/app/' . $relative . '.php';
        return is_file($path) ? $path : null;
    }

    private function loadCatalog(): array
    {
        if (!is_file($this->catalogPath)) {
            error_log('[SCML] ExecutionRegistry: catalog not found at ' . $this->catalogPath);
            return [];
        }

        $raw = file_get_contents($this->catalogPath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['catalog'] ?? null)) {
            error_log('[SCML] ExecutionRegistry: unexpected catalog structure.');
            return [];
        }

        return array_filter($data['catalog'], static fn($s) => !empty($s['handler']));
    }

    /**
     * Lee un valor de la sección "grounding" de routing_policies.json.
     * Fallback al valor por defecto si la clave no existe.
     */
    private function cfg(string $key, mixed $default): mixed
    {
        if ($this->groundingCfg === null) {
            $this->groundingCfg = [];
            if (is_file($this->policiesPath)) {
                $raw  = file_get_contents($this->policiesPath);
                $data = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($data) && is_array($data['grounding'] ?? null)) {
                    $this->groundingCfg = $data['grounding'];
                }
            }
        }

        return $this->groundingCfg[$key] ?? $default;
    }
}
