<?php

declare(strict_types=1);

namespace App\Core\Grounding;

/**
 * PHP Execution Registry — SCML Fase 2.
 *
 * Usa PHP Reflection para inspeccionar los handlers reales de skills_catalog.json:
 *   - Verifica que la clase y el método existen en código real
 *   - Lee SKILL_ACTION_MAP (si está presente) para mapear intent → acción interna
 *   - Extrae dependencias del constructor (codegraph de dependencias)
 *   - Extrae claves de $input/$args por análisis estático del fuente
 *
 * Sirve para:
 *   1. Validar consistencia catalog ↔ código PHP
 *   2. Enriquecer el bloque de grounding del LLM con firmas reales
 *   3. Generar el codegraph (mapa clase → {intents, deps})
 */
final class ExecutionRegistry
{
    private ?array $registry = null;

    private string $catalogPath;
    private string $frameworkRoot;

    /** Claves de contexto que no son parámetros de entrada del skill */
    private const CONTEXT_KEYS = ['tenant_id', 'agent_id', 'user_id', 'session_id'];

    public function __construct(?string $catalogPath = null, ?string $frameworkRoot = null)
    {
        $this->catalogPath  = $catalogPath ?? dirname(__DIR__, 4) . '/docs/contracts/skills_catalog.json';
        $this->frameworkRoot = $frameworkRoot ?? dirname(__DIR__, 3);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Construye el registro completo.
     * Retorna array indexado por intent_name con datos de inspección.
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
            $intent  = (string) ($skill['name']    ?? '');
            if ($handler === '' || $intent === '') {
                continue;
            }

            [$className, $methodName] = $this->parseHandler($handler);

            if (!isset($classCache[$className])) {
                $classCache[$className] = $this->inspectClass($className);
            }
            $info = $classCache[$className];

            // method_exists = true si tiene el método declarado O handle() como fallback
            $declaredExists = $info['methods'][$methodName] ?? false;
            $handleExists   = $info['methods']['handle']    ?? false;

            $this->registry[$intent] = [
                'intent'         => $intent,
                'handler'        => $handler,
                'class'          => $className,
                'method'         => $methodName,
                'class_exists'   => $info['exists'],
                'method_exists'  => $declaredExists || $handleExists,
                'method_is_alias'=> !$declaredExists && $handleExists,
                'action'         => $info['action_map'][$intent] ?? null,
                'input_keys'     => $info['input_keys'],
                'deps'           => $info['deps'],
                'topic_cluster'  => (string) ($skill['topic_cluster'] ?? 'general'),
            ];
        }

        return $this->registry;
    }

    /**
     * Retorna la entrada del registro para un intent, o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function getEntry(string $intent): ?array
    {
        return $this->build()[$intent] ?? null;
    }

    /**
     * Retorna el codegraph: mapa de clase PHP → {intents[], deps[], action_map}.
     * Sirve para documentación, tooling y validación de dependencias.
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
     * Valida que todos los handlers del catálogo existen en código real.
     *
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
                // Catálogo dice @execute pero la clase solo tiene handle() — funcional pero inconsistente
                $warnings[] = "METHOD_ALIAS: {$entry['class']} catalog={$entry['method']} real=handle() (intent: {$intent})";
            }
            if (empty($entry['input_keys'])) {
                $warnings[] = "NO_INPUT_KEYS: {$intent} — handler {$entry['class']} sin claves detectables";
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
     * Devuelve una línea compacta de firma para inyectar en el bloque de grounding.
     * Ejemplo: "Acción: record_entry | Parámetros: account_code, amount, description"
     */
    public function renderSignatureHint(string $intent): string
    {
        $entry = $this->getEntry($intent);
        if ($entry === null) {
            return '';
        }

        $parts = [];

        if ($entry['action'] !== null) {
            $parts[] = "Acción: {$entry['action']}";
        }

        $keys = array_values(array_diff($entry['input_keys'], self::CONTEXT_KEYS));
        // Si ya tenemos la acción del action_map, omitir 'action' de las claves
        if ($entry['action'] !== null) {
            $keys = array_values(array_diff($keys, ['action']));
        }
        if (!empty($keys)) {
            $parts[] = 'Parámetros: ' . implode(', ', array_slice($keys, 0, 7));
        }

        return implode(' | ', $parts);
    }

    // -------------------------------------------------------------------------
    // Private — inspección por reflexión
    // -------------------------------------------------------------------------

    /** @return array{class: string, method: string} */
    private function parseHandler(string $handler): array
    {
        $parts = explode('@', $handler, 2);
        return [$parts[0], $parts[1] ?? 'execute'];
    }

    /**
     * @return array{exists: bool, methods: array<string, bool>, deps: list<string>, input_keys: list<string>, action_map: array<string, string>}
     */
    private function inspectClass(string $className): array
    {
        // Siempre chequeamos tanto execute como handle (fallback para skills que solo tienen handle())
        $result = [
            'exists'     => false,
            'methods'    => ['execute' => false, 'handle' => false],
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

        // Métodos relevantes
        foreach (['execute', 'handle'] as $m) {
            $result['methods'][$m] = $ref->hasMethod($m);
        }

        // Dependencias del constructor (codegraph)
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

        // SKILL_ACTION_MAP — lee la constante privada si existe
        if ($ref->hasConstant('SKILL_ACTION_MAP')) {
            $constRef = $ref->getReflectionConstant('SKILL_ACTION_MAP');
            if ($constRef !== false) {
                $val = $constRef->getValue();
                if (is_array($val)) {
                    $result['action_map'] = $val;
                }
            }
        }

        // Análisis estático: claves de $input['key'] y $args['key']
        $filePath = $ref->getFileName();
        if ($filePath !== false && $filePath !== '') {
            $result['input_keys'] = $this->extractInputKeys($filePath);
        }

        return $result;
    }

    /**
     * Extrae claves de $input['key'] / $args['key'] del fuente PHP.
     *
     * @return list<string>
     */
    private function extractInputKeys(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }

        $source = (string) file_get_contents($filePath);
        $keys   = [];

        // [$] en vez de \$ evita ambigüedad de escaping en single-quoted strings PHP
        if (preg_match_all('/[$](?:input|args)\[\'([a-zA-Z_][a-zA-Z0-9_]*)\'\]/', $source, $matches)) {
            foreach ($matches[1] as $key) {
                if (!in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return array_values($keys);
    }

    /**
     * Resuelve la ruta al archivo PHP de una clase usando PSR-4 App\ → framework/app/.
     */
    private function resolveClassPath(string $className): ?string
    {
        if (!str_starts_with($className, 'App\\')) {
            return null;
        }
        $relative = str_replace('\\', '/', substr($className, 4));
        $path     = $this->frameworkRoot . '/app/' . $relative . '.php';
        return is_file($path) ? $path : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
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
}
