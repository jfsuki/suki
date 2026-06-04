<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dynamic skill registry — resolves skill names to handler classes at runtime.
 *
 * Sources (checked in order):
 *   1. docs/contracts/skills_catalog.json entries with "handler" field
 *   2. custom_tools MySQL table (created at runtime by ToolFactory)
 *
 * New skills become routable by:
 *   a) Adding "handler" to skills_catalog.json (no PHP changes needed)
 *   b) Inserting a row in custom_tools via ToolFactory chat command
 *
 * SkillExecutor calls dispatch() first; falls back to legacy if-chain only when null returned.
 */
final class DynamicSkillRegistry
{
    /** @var array<string, array{class: string, method: string, config: array<string,mixed>}> */
    private array $handlers = [];
    private bool  $loaded   = false;

    private const CATALOG_PATH = __DIR__ . '/../../../docs/contracts/skills_catalog.json';

    public function __construct(private readonly ?Database $db = null) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Dispatch a skill by name. Returns null when not registered (caller falls back to legacy).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function dispatch(string $skillName, array $input, array $context): ?array
    {
        $this->ensureLoaded();

        $descriptor = $this->handlers[$skillName] ?? null;
        if ($descriptor === null) {
            return null;
        }

        $class  = $descriptor['class'];
        $method = $descriptor['method'];
        $config = $descriptor['config'];

        if (!class_exists($class)) {
            return null;
        }

        try {
            $instance = empty($config) ? new $class() : new $class($config);
            return $this->invoke($instance, $method, $skillName, $input, $context);
        } catch (\Throwable $e) {
            return $this->errorResult($skillName, $e->getMessage());
        }
    }

    /** Returns true when the skill name has a registered handler. */
    public function has(string $skillName): bool
    {
        $this->ensureLoaded();
        return isset($this->handlers[$skillName]);
    }

    /** All registered skill names — useful for debugging and tests. */
    public function listRegistered(): array
    {
        $this->ensureLoaded();
        return array_keys($this->handlers);
    }

    // -------------------------------------------------------------------------
    // Loading
    // -------------------------------------------------------------------------

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loadFromCatalog();
        $this->loadFromDatabase();
        $this->loaded = true;
    }

    private function loadFromCatalog(): void
    {
        if (!file_exists(self::CATALOG_PATH)) {
            return;
        }
        $raw = file_get_contents(self::CATALOG_PATH);
        if ($raw === false) {
            return;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        // Structure: { "catalog": [ {...}, ... ] }
        $entries = $decoded['catalog'] ?? [];
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name    = (string) ($entry['name']    ?? '');
            $handler = (string) ($entry['handler'] ?? '');
            if ($name === '' || $handler === '') {
                continue;
            }

            // Format: "Fully\Qualified\ClassName" or "Fully\Qualified\ClassName@methodName"
            [$class, $method] = str_contains($handler, '@')
                ? explode('@', $handler, 2)
                : [$handler, 'handle'];

            $this->handlers[$name] = ['class' => $class, 'method' => $method, 'config' => []];
        }
    }

    private function loadFromDatabase(): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            $rows = $this->db->query(
                'SELECT name, http_method, base_url, auth_type, headers_json, params_schema FROM custom_tools',
                []
            );
            if (!is_array($rows)) {
                return;
            }
            foreach ($rows as $row) {
                $toolName = (string) ($row['name'] ?? '');
                if ($toolName === '') {
                    continue;
                }
                $this->handlers[$toolName] = [
                    'class'  => \App\Core\GenericIntegrationAdapter::class,
                    'method' => 'execute',
                    'config' => [
                        'http_method'   => $row['http_method']   ?? 'GET',
                        'base_url'      => $row['base_url']      ?? '',
                        'auth_type'     => $row['auth_type']     ?? 'none',
                        'headers'       => json_decode((string) ($row['headers_json']  ?? '{}'), true) ?: [],
                        'params_schema' => json_decode((string) ($row['params_schema'] ?? '{}'), true) ?: [],
                    ],
                ];
            }
        } catch (\Throwable) {
            // DB not available — catalog-only mode, no custom tools
        }
    }

    // -------------------------------------------------------------------------
    // Invocation helpers
    // -------------------------------------------------------------------------

    /**
     * Call the skill using the declared method, with per-class signature normalization.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function invoke(object $instance, string $method, string $skillName, array $input, array $context): array
    {
        // ---- Prefer declared method ----
        if ($method === 'handle' && method_exists($instance, 'handle')) {
            $result = $instance->handle($input, $context);
        } elseif ($method === 'execute' && method_exists($instance, 'execute')) {
            $action = $input['action'] ?? $skillName;
            $result = $instance->execute((string) $action, $input, $context);
        } elseif (method_exists($instance, 'handle')) {
            $result = $instance->handle($input, $context);
        } elseif (method_exists($instance, 'execute')) {
            $action = $input['action'] ?? $skillName;
            $result = $instance->execute((string) $action, $input, $context);
        } elseif (method_exists($instance, 'calculate')) {
            // CalculatorSkill, FiscalTaxSkill, ExpiryControlSkill, UnitConversionSkill
            $result = $instance->calculate($input);
        } else {
            return $this->errorResult($skillName, 'No callable method found on ' . get_class($instance));
        }

        return $this->normalize($result);
    }

    /**
     * Ensure result always has the standard SkillExecutor keys.
     *
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [
                'action' => 'respond_local', 'reply' => (string) $raw,
                'command' => [], 'skill_result_status' => 'ok',
                'skill_fallback_reason' => 'none', 'skill_failed' => false, 'telemetry' => [],
            ];
        }
        return array_merge([
            'action' => 'respond_local', 'reply' => '',
            'command' => [], 'skill_result_status' => 'ok',
            'skill_fallback_reason' => 'none', 'skill_failed' => false, 'telemetry' => [],
        ], $raw);
    }

    /** @return array<string, mixed> */
    private function errorResult(string $skillName, string $message): array
    {
        return [
            'action'                => 'respond_local',
            'reply'                 => "Error en skill {$skillName}: {$message}",
            'command'               => [],
            'skill_result_status'   => 'error',
            'skill_fallback_reason' => 'registry_exception',
            'skill_failed'          => true,
            'telemetry'             => [],
        ];
    }
}
