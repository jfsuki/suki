<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Runtime policy loader — reads JSON config files from framework/config/.
 *
 * WHY: PHP const arrays for business logic (intents, triggers, steps) are monolitic.
 * They require code deploys to change. JSON files can be updated by operators,
 * tooling, or future UI without touching the PHP kernel.
 *
 * USAGE:
 *   $triggers = PolicyLoader::get('routing_policies', 'create_triggers', []);
 *   $intents  = PolicyLoader::get('builder_policies', 'allowed_intents', []);
 *
 * Files live at: framework/config/{policy_name}.json
 * Cache is per-request (in-memory only, no persistence overhead).
 */
final class PolicyLoader
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    private static string $configDir = '';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Get a specific key from a policy file. Returns $default if file or key missing.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $policyFile, string $key, mixed $default = null): mixed
    {
        $policy = self::load($policyFile);

        // Soporte dot-notation: "grounding.max_cluster_peers" → $policy['grounding']['max_cluster_peers']
        if (str_contains($key, '.')) {
            $current = $policy;
            foreach (explode('.', $key) as $segment) {
                if (!is_array($current) || !array_key_exists($segment, $current)) {
                    return $default;
                }
                $current = $current[$segment];
            }
            return $current;
        }

        return $policy[$key] ?? $default;
    }

    /**
     * Load a full policy file as array. Returns [] if file is missing or invalid.
     *
     * @return array<string, mixed>
     */
    public static function load(string $policyFile): array
    {
        if (isset(self::$cache[$policyFile])) {
            return self::$cache[$policyFile];
        }

        $path = self::configPath($policyFile . '.json');
        if (!file_exists($path)) {
            self::$cache[$policyFile] = [];
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            self::$cache[$policyFile] = [];
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::$cache[$policyFile] = [];
            return [];
        }

        self::$cache[$policyFile] = $decoded;
        return $decoded;
    }

    /**
     * Merge a key from policy with provided defaults.
     * Policy values WIN over defaults.
     *
     * @param array<mixed> $defaults
     * @return array<mixed>
     */
    public static function merge(string $policyFile, string $key, array $defaults): array
    {
        $fromFile = self::get($policyFile, $key, []);
        if (!is_array($fromFile) || empty($fromFile)) {
            return $defaults;
        }
        return array_unique(array_merge($defaults, $fromFile));
    }

    /** Clear in-memory cache (useful in tests or after file updates). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function configPath(string $filename): string
    {
        if (self::$configDir === '') {
            // Resolve: framework/config/ relative to this file (framework/app/Core/)
            self::$configDir = dirname(__DIR__, 2) . '/config';
        }
        return self::$configDir . DIRECTORY_SEPARATOR . $filename;
    }
}
