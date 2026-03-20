<?php

declare(strict_types=1);

namespace App\Core;

final class SectorIntentBalance
{
    public const DEFAULT_MIN_TOTAL_UTTERANCES_PER_INTENT = 4;
    public const DEFAULT_MAX_INTENT_DOMINANCE_RATIO = 2.5;

    /**
     * @param array<string, mixed> $options
     * @return array{min_total_utterances_per_intent:int,max_intent_dominance_ratio:float}
     */
    public static function resolveThresholds(array $options = []): array
    {
        return [
            'min_total_utterances_per_intent' => max(
                1,
                (int) ($options['min_total_utterances_per_intent'] ?? self::DEFAULT_MIN_TOTAL_UTTERANCES_PER_INTENT)
            ),
            'max_intent_dominance_ratio' => max(
                1.0,
                (float) ($options['max_intent_dominance_ratio'] ?? self::DEFAULT_MAX_INTENT_DOMINANCE_RATIO)
            ),
        ];
    }

    /**
     * @param array<int, mixed> $intents
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function audit(array $intents, array $options = []): array
    {
        $thresholds = self::resolveThresholds($options);
        $distribution = [];
        $coveredIntents = 0;
        $underrepresented = [];
        $totalUtterances = 0;

        foreach ($intents as $intent) {
            if (!is_array($intent)) {
                continue;
            }

            $action = trim((string) ($intent['action'] ?? ''));
            $intentKey = trim((string) ($intent['intent'] ?? ''));
            if ($action === '' && $intentKey === '') {
                continue;
            }

            $explicit = self::stringList($intent['utterances_explicit'] ?? []);
            $implicit = self::stringList($intent['utterances_implicit'] ?? []);
            $utterancesTotal = count($explicit) + count($implicit);
            $totalUtterances += $utterancesTotal;

            if ($utterancesTotal > 0) {
                $coveredIntents++;
            }
            if ($utterancesTotal < $thresholds['min_total_utterances_per_intent']) {
                $underrepresented[] = $action !== '' ? $action : $intentKey;
            }

            $distribution[] = [
                'intent' => $intentKey,
                'action' => $action,
                'utterances_explicit' => count($explicit),
                'utterances_implicit' => count($implicit),
                'utterances_total' => $utterancesTotal,
            ];
        }

        usort($distribution, static function (array $left, array $right): int {
            $leftTotal = (int) ($left['utterances_total'] ?? 0);
            $rightTotal = (int) ($right['utterances_total'] ?? 0);
            if ($leftTotal === $rightTotal) {
                return strcmp((string) ($left['action'] ?? ''), (string) ($right['action'] ?? ''));
            }
            return $rightTotal <=> $leftTotal;
        });

        $dominant = $distribution[0] ?? null;
        $weakest = $distribution !== [] ? $distribution[count($distribution) - 1] : null;
        $dominantTotal = (int) ($dominant['utterances_total'] ?? 0);
        $weakestTotal = (int) ($weakest['utterances_total'] ?? 0);
        $dominanceRatio = null;
        if ($dominantTotal > 0 && $weakestTotal > 0) {
            $dominanceRatio = round($dominantTotal / $weakestTotal, 4);
        }

        $dominantShare = null;
        if ($totalUtterances > 0 && $dominantTotal > 0) {
            $dominantShare = round($dominantTotal / $totalUtterances, 4);
        }

        $ok = $distribution !== []
            && $underrepresented === []
            && ($dominanceRatio === null || $dominanceRatio <= $thresholds['max_intent_dominance_ratio']);

        return [
            'ok' => $ok,
            'thresholds' => $thresholds,
            'covered_intents' => $coveredIntents,
            'total_intents' => count($distribution),
            'total_utterances' => $totalUtterances,
            'dominant_intent' => is_array($dominant) ? (string) ($dominant['action'] ?? $dominant['intent'] ?? '') : '',
            'dominant_total' => $dominantTotal,
            'weakest_intent' => is_array($weakest) ? (string) ($weakest['action'] ?? $weakest['intent'] ?? '') : '',
            'weakest_total' => $weakestTotal,
            'dominance_ratio' => $dominanceRatio,
            'dominant_share' => $dominantShare,
            'underrepresented_intents' => array_values(array_unique($underrepresented)),
            'distribution' => $distribution,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function catalogKeywords(string $skill, ?string $catalogPath = null): array
    {
        static $cache = [];

        $skill = trim($skill);
        if ($skill === '') {
            return [];
        }

        $catalogPath = $catalogPath ?? self::defaultSkillsCatalogPath();
        $cacheKey = $catalogPath . '|' . $skill;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $catalog = self::readCatalog($catalogPath);
        $result = [];

        foreach ($catalog as $entry) {
            if (!is_array($entry) || trim((string) ($entry['name'] ?? '')) !== $skill) {
                continue;
            }

            $result = self::stringList($entry['keywords'] ?? []);
            break;
        }

        if ($result === []) {
            $result = self::stringList([str_replace('_', ' ', $skill)]);
        }

        $cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function readCatalog(string $path): array
    {
        static $cache = [];
        if (isset($cache[$path])) {
            return $cache[$path];
        }

        if (!is_file($path)) {
            $cache[$path] = [];
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            $cache[$path] = [];
            return [];
        }

        $catalog = is_array($decoded['catalog'] ?? null) ? $decoded['catalog'] : [];
        $cache[$path] = array_values(array_filter($catalog, 'is_array'));
        return $cache[$path];
    }

    private static function defaultSkillsCatalogPath(): string
    {
        return dirname(FRAMEWORK_ROOT) . '/docs/contracts/skills_catalog.json';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        $seen = [];
        foreach ($value as $item) {
            $text = self::sanitizeText((string) $item);
            $key = self::toKey($text);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $text;
        }

        return $result;
    }

    private static function sanitizeText(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return $value;
    }

    private static function toKey(string $value): string
    {
        $value = self::sanitizeText($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        $value = strtolower($value);
        return preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
    }
}
