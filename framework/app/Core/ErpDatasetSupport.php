<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ErpDatasetSupport
{
    public const ARTIFACT_SCHEMA_VERSION = '1.0.0';
    public const DEFAULT_SKILLS_CATALOG = FRAMEWORK_ROOT . '/../docs/contracts/skills_catalog.json';
    public const DEFAULT_ACTION_CATALOG = FRAMEWORK_ROOT . '/../docs/contracts/action_catalog.json';
    public const DEFAULT_AGENTOPS_CONTRACT = FRAMEWORK_ROOT . '/../docs/contracts/agentops_metrics_contract.json';
    public const SCHEMA_INTENTS = FRAMEWORK_ROOT . '/contracts/schemas/erp_intents_catalog.schema.json';
    public const SCHEMA_SAMPLES = FRAMEWORK_ROOT . '/contracts/schemas/erp_training_samples.schema.json';
    public const SCHEMA_HARD_CASES = FRAMEWORK_ROOT . '/contracts/schemas/erp_hard_cases.schema.json';
    public const SKILL_TYPES = ['tool', 'deterministic', 'hybrid', 'rag'];
    public const MEMORY_TYPES = ['agent_training', 'sector_knowledge', 'user_memory'];
    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];
    public const AMBIGUITY_FLAGS = [
        'entity_reference',
        'document_reference',
        'intent_overlap',
        'missing_scope',
        'customer_reference',
        'product_reference',
        'inventory_reference',
        'numeric_reference',
        'time_reference',
        'payment_terms',
        'channel_reference',
    ];
    public const EXPECTED_RESOLUTIONS = ['resolve', 'clarify', 'deny', 'fallback', 'block'];
    public const ROUTE_STAGES = ['cache', 'rules', 'skills', 'rag', 'llm', 'unknown'];
    public const SHARED_TRAINING_SCOPE = 'shared_non_operational_training';

    /**
     * @return array{version: string, skills: array<string, array<string, mixed>>}
     */
    public static function loadSkillsCatalog(): array
    {
        $decoded = self::loadJsonFile(self::DEFAULT_SKILLS_CATALOG, 'skills_catalog');
        $catalog = is_array($decoded['catalog'] ?? null) ? $decoded['catalog'] : [];
        $skills = [];

        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = self::stringValue($entry, ['name']);
            if ($name === '') {
                continue;
            }
            $skills[$name] = $entry;
        }

        return [
            'version' => (string) ($decoded['version'] ?? 'unknown'),
            'skills' => $skills,
        ];
    }

    /**
     * @return array{version: string, actions: array<string, array<string, mixed>>, risk_levels: array<int, string>}
     */
    public static function loadActionCatalog(): array
    {
        $decoded = self::loadJsonFile(self::DEFAULT_ACTION_CATALOG, 'action_catalog');
        $catalog = is_array($decoded['catalog'] ?? null) ? $decoded['catalog'] : [];
        $actions = [];

        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = self::stringValue($entry, ['name']);
            if ($name === '') {
                continue;
            }
            $actions[$name] = $entry;
        }

        return [
            'version' => (string) ($decoded['version'] ?? 'unknown'),
            'actions' => $actions,
            'risk_levels' => self::stringList($decoded['risk_levels'] ?? []),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function loadSupervisorFlags(): array
    {
        $decoded = self::loadJsonFile(self::DEFAULT_AGENTOPS_CONTRACT, 'agentops_metrics_contract');
        return self::stringList($decoded['supervisor_flag_catalog'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadJsonFile(string $path, string $label): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('No existe %s en %s.', $label, $path));
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException(sprintf('%s esta vacio.', $label));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('%s invalido.', $label));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function resolveMetadata(array $payload): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $recommendedMemoryType = self::normalizeEnum(
            self::stringValue($metadata, ['recommended_memory_type', 'memory_type']),
            self::MEMORY_TYPES,
            'agent_training'
        );

        return [
            'dataset_id' => self::stringValue(
                $metadata,
                ['dataset_id', 'dataset', 'batch_id'],
                (string) ($payload['batch_id'] ?? 'erp_dataset_source')
            ),
            'dataset_version' => self::stringValue(
                $metadata,
                ['dataset_version', 'version'],
                (string) ($payload['dataset_version'] ?? '1.0.0')
            ),
            'domain' => self::stringValue($metadata, ['domain'], 'erp'),
            'subdomain' => self::stringValue($metadata, ['subdomain'], 'general'),
            'locale' => self::normalizeLocale(
                self::stringValue($metadata, ['locale', 'language'], (string) ($payload['language'] ?? 'es-CO'))
            ),
            'recommended_memory_type' => $recommendedMemoryType,
            'generated_at' => gmdate('c'),
            'tenant_data_allowed' => false,
            'dataset_scope' => self::SHARED_TRAINING_SCOPE,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public static function resolveBlock(array $payload, string $block): array
    {
        $candidates = match ($block) {
            'intents_catalog' => ['BLOQUE_A_intents_catalog', 'bloque_a_intents_catalog', 'intents_catalog'],
            'training_samples' => ['BLOQUE_B_training_samples', 'bloque_b_training_samples', 'training_samples'],
            'hard_cases' => ['BLOQUE_C_hard_cases', 'bloque_c_hard_cases', 'hard_cases'],
            default => [$block],
        };

        foreach ($candidates as $candidate) {
            $value = $payload[$candidate] ?? null;
            if (is_array($value)) {
                return array_values(array_filter($value, static fn ($entry): bool => is_array($entry)));
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    public static function stringValue(array $source, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) || is_numeric($value)) {
                $normalized = self::normalizeText((string) $value);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return $default;
    }

    /**
     * Preserva el texto fuente para trazabilidad, sin normalizar espacios internos.
     *
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    public static function originalStringValue(array $source, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) || is_numeric($value)) {
                $raw = trim((string) $value);
                if ($raw !== '') {
                    return $raw;
                }
            }
        }

        return $default;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $text = self::normalizeText((string) $item);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param mixed $value
     */
    public static function boolValue($value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'yes', 'si'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no'], true)) {
                return false;
            }
        }
        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    public static function floatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
            return is_finite($float) ? $float : null;
        }

        return null;
    }

    public static function normalizeLocale(string $locale): string
    {
        $locale = self::normalizeText($locale);
        if ($locale === '') {
            return 'es-CO';
        }

        $locale = str_replace('_', '-', $locale);
        $parts = explode('-', $locale);
        if (count($parts) === 1) {
            return strtolower($parts[0]);
        }

        $language = strtolower((string) ($parts[0] ?? 'es'));
        $region = strtoupper((string) ($parts[1] ?? 'CO'));
        return $language . '-' . $region;
    }

    public static function normalizeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/u', ' ', $value);
        return is_string($value) ? trim($value) : '';
    }

    public static function dedupeKey(string $value): string
    {
        $value = self::normalizeText($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', (string) $value);
        $value = strtolower(trim((string) $value));
        return $value;
    }

    /**
     * @param array<int, string> $allowed
     */
    public static function normalizeEnum(string $value, array $allowed, string $default = ''): string
    {
        $value = strtolower(self::normalizeText($value));
        if ($value === '' || !in_array($value, $allowed, true)) {
            return $default;
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    public static function normalizeAmbiguityFlags($value): array
    {
        $flags = self::stringList($value);
        $normalized = [];
        foreach ($flags as $flag) {
            $enum = self::normalizeEnum($flag, self::AMBIGUITY_FLAGS);
            if ($enum !== '') {
                $normalized[] = $enum;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param mixed $value
     * @return array<string, float>
     */
    public static function normalizeNumericHints($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                continue;
            }
            $float = self::floatOrNull($entry);
            if ($float === null) {
                continue;
            }
            $normalized[self::dedupeKey($key)] = $float;
        }

        ksort($normalized);
        return $normalized;
    }

    public static function isExtremeGarbage(string $text): bool
    {
        $text = self::normalizeText($text);
        if ($text === '' || self::textLength($text) < 3) {
            return true;
        }

        $patterns = [
            '/\b(lorem ipsum|dummy|placeholder|xxxx|asdf|qwerty)\b/i',
            '/^(.)\1{4,}$/',
            '/^[\W_]+$/u',
            '/^\d+$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function nearDuplicateScore(string $left, string $right): float
    {
        $leftKey = self::dedupeKey($left);
        $rightKey = self::dedupeKey($right);
        if ($leftKey === '' || $rightKey === '') {
            return 0.0;
        }
        if ($leftKey === $rightKey) {
            return 1.0;
        }

        $distance = levenshtein($leftKey, $rightKey);
        $length = max(strlen($leftKey), strlen($rightKey));
        if ($length === 0) {
            return 0.0;
        }

        return max(0.0, round(1 - ($distance / $length), 4));
    }

    public static function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    public static function tokenPrefix(string $text, int $count = 3): string
    {
        $deduped = self::dedupeKey($text);
        if ($deduped === '') {
            return '';
        }

        $tokens = preg_split('/\s+/u', $deduped) ?: [];
        $tokens = array_values(array_filter($tokens, static fn ($token): bool => $token !== ''));
        if ($tokens === []) {
            return '';
        }

        return implode(' ', array_slice($tokens, 0, max(1, $count)));
    }

    public static function stableId(string $prefix, string $seed, int $index): string
    {
        $seed = self::dedupeKey($seed);
        if ($seed === '') {
            $seed = (string) $index;
        }

        return $prefix . '_' . substr(sha1($seed . '|' . $index), 0, 12);
    }

    public static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('No se pudo crear directorio: ' . $path);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function writeJsonFile(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('No se pudo serializar JSON: ' . $path);
        }

        if (file_put_contents($path, $encoded . PHP_EOL) === false) {
            throw new RuntimeException('No se pudo escribir archivo JSON: ' . $path);
        }
    }
}
