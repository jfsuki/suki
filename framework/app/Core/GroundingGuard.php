<?php

declare(strict_types=1);

// framework/app/Core/GroundingGuard.php

namespace App\Core;

/**
 * PHP-only anti-hallucination guard.
 *
 * Extracts numbers/IDs from LLM response text and cross-checks them
 * against real values stored in $state['last_tool_results'].
 * Never blocks the response — if no tool results exist, returns text unchanged.
 */
final class GroundingGuard
{
    /**
     * @param array<string, mixed> $state  Must contain 'last_tool_results' key if grounding is possible.
     * @return array{verified: bool, corrections: list<array{field: string, llm_value: string, real_value: string}>, sanitizedText: string}
     */
    public function verify(string $llmText, array $state): array
    {
        $toolResults = $state['last_tool_results'] ?? [];

        if (empty($toolResults) || !is_array($toolResults)) {
            return ['verified' => true, 'corrections' => [], 'sanitizedText' => $llmText];
        }

        $realValues = $this->flattenToolResults($toolResults);

        if (empty($realValues)) {
            return ['verified' => true, 'corrections' => [], 'sanitizedText' => $llmText];
        }

        $corrections = [];
        $sanitized   = $llmText;

        foreach ($realValues as $field => $realRaw) {
            $realStr = $this->normalizeNumeric($realRaw);
            if ($realStr === null) {
                continue;
            }

            // Find occurrences of a visually different numeric value in the same semantic position.
            // Strategy: look for numbers near the field name in the text.
            $pattern = $this->buildProximityPattern($field);
            if ($pattern === null) {
                continue;
            }

            if (preg_match($pattern, $sanitized, $m)) {
                $llmStr = $this->normalizeNumeric($m['num'] ?? '');
                if ($llmStr !== null && $llmStr !== $realStr) {
                    $corrections[] = [
                        'field'     => $field,
                        'llm_value' => $llmStr,
                        'real_value' => $realStr,
                    ];
                    // Replace the wrong number with the real one from DB.
                    $sanitized = str_replace($m['num'], $realRaw . ' [verificado DB]', $sanitized);
                }
            }
        }

        $verified = empty($corrections);

        return [
            'verified'      => $verified,
            'corrections'   => $corrections,
            'sanitizedText' => $sanitized,
        ];
    }

    /**
     * Flatten nested tool result arrays into field => value pairs,
     * keeping only numeric-looking leaf values (amounts, IDs, quantities).
     *
     * @param array<mixed> $results
     * @return array<string, string>
     */
    private function flattenToolResults(array $results): array
    {
        $flat = [];
        $this->flattenRecursive($results, '', $flat, 0);
        return $flat;
    }

    /** @param array<string, string> $flat */
    private function flattenRecursive(mixed $node, string $prefix, array &$flat, int $depth): void
    {
        if ($depth > 4) {
            return;
        }

        if (is_array($node)) {
            foreach ($node as $key => $value) {
                $path = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;
                $this->flattenRecursive($value, $path, $flat, $depth + 1);
            }
            return;
        }

        if (is_numeric($node) && $prefix !== '') {
            $flat[$prefix] = (string) $node;
        }
    }

    /**
     * Normalize a potentially formatted number ("$1.234,56" → "1234.56").
     * Returns null when the value is not numeric after stripping formatting.
     */
    private function normalizeNumeric(string $raw): ?string
    {
        $clean = preg_replace('/[\$\s,]/', '', $raw);
        $clean = str_replace(',', '.', (string) $clean);
        if (!is_numeric($clean)) {
            return null;
        }
        return $clean;
    }

    /**
     * Build a regex that looks for a number near a field name fragment in the text.
     * Returns null for field paths that are too generic to be useful.
     */
    private function buildProximityPattern(string $fieldPath): ?string
    {
        // Use the leaf key (after last dot) for matching
        $leaf = explode('.', $fieldPath);
        $key  = end($leaf);

        // Skip system fields unlikely to appear in chat responses
        $skip = ['id', 'tenant_id', 'app_id', 'created_at', 'updated_at', 'deleted_at'];
        if (in_array($key, $skip, true)) {
            return null;
        }

        $escaped = preg_quote($key, '/');

        // Match: "fieldname ... {number}" within ~80 characters
        return '/(?:' . $escaped . ')[^0-9]{0,80}?(?P<num>[\$]?[\d]{1,12}(?:[.,]\d{2,3})*)/ui';
    }
}
