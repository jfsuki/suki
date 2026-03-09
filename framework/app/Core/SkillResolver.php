<?php
// app/Core/SkillResolver.php

declare(strict_types=1);

namespace App\Core;

final class SkillResolver
{
    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function resolve(string $query, SkillRegistry $registry, array $context = []): array
    {
        $normalizedQuery = $this->normalizeText($query);
        if ($normalizedQuery === '') {
            return [
                'detected' => false,
                'selected' => null,
                'reason' => 'empty_query',
                'matched_skills' => [],
            ];
        }

        $candidates = [];
        foreach ($registry->all() as $skill) {
            $score = 0;
            $reasons = [];

            $patternScore = $this->scoreIntentPatterns($normalizedQuery, (array) ($skill['intent_patterns'] ?? []));
            if ($patternScore > 0) {
                $score += $patternScore;
                $reasons[] = 'intent_pattern_match';
            }

            $keywordMatches = $this->countKeywordMatches($normalizedQuery, (array) ($skill['keywords'] ?? []));
            if ($keywordMatches > 0) {
                $score += $keywordMatches;
                $reasons[] = 'keyword_match';
            }

            if ($score > 0) {
                $hintScore = $this->scoreContextHints($normalizedQuery, (array) ($skill['context_hints'] ?? []), $context);
                if ($hintScore > 0) {
                    $score += $hintScore;
                    $reasons[] = 'context_hint_match';
                }
            }

            if ($score <= 0) {
                continue;
            }

            $candidates[] = [
                'skill' => $skill,
                'score' => $score,
                'priority' => (int) ($skill['priority'] ?? 0),
                'reason' => implode('+', $reasons),
            ];
        }

        if ($candidates === []) {
            return [
                'detected' => false,
                'selected' => null,
                'reason' => 'no_skill_match',
                'matched_skills' => [],
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            if ((int) $left['score'] !== (int) $right['score']) {
                return (int) $right['score'] <=> (int) $left['score'];
            }

            return (int) $right['priority'] <=> (int) $left['priority'];
        });

        $selected = (array) $candidates[0];
        $matchedSkills = [];
        foreach ($candidates as $candidate) {
            $name = trim((string) ($candidate['skill']['name'] ?? ''));
            if ($name !== '') {
                $matchedSkills[] = $name;
            }
        }

        return [
            'detected' => true,
            'selected' => (array) ($selected['skill'] ?? []),
            'reason' => (string) ($selected['reason'] ?? 'skill_match'),
            'score' => (int) ($selected['score'] ?? 0),
            'matched_skills' => array_values(array_unique($matchedSkills)),
        ];
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    /**
     * @param array<int,string> $patterns
     */
    private function scoreIntentPatterns(string $query, array $patterns): int
    {
        $score = 0;
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }

            if (str_starts_with($pattern, 're:')) {
                $regex = substr($pattern, 3);
                if ($regex !== '' && @preg_match('/' . $regex . '/iu', $query) === 1) {
                    $score += 4;
                }
                continue;
            }

            if (str_contains($query, $this->normalizeText($pattern))) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * @param array<int,string> $keywords
     */
    private function countKeywordMatches(string $query, array $keywords): int
    {
        $matches = 0;
        foreach ($keywords as $keyword) {
            $keyword = $this->normalizeText($keyword);
            if ($keyword === '') {
                continue;
            }

            $pattern = '/(?:^|\\b)' . preg_quote($keyword, '/') . '(?:$|\\b)/u';
            if (preg_match($pattern, $query) === 1) {
                $matches++;
            }
        }

        return $matches;
    }

    /**
     * @param array<string,mixed> $hints
     * @param array<string,mixed> $context
     */
    private function scoreContextHints(string $query, array $hints, array $context): int
    {
        $score = 0;
        $attachmentsCount = is_numeric($context['attachments_count'] ?? null)
            ? max(0, (int) $context['attachments_count'])
            : 0;

        if ((bool) ($hints['requires_attachment'] ?? false) && $attachmentsCount > 0) {
            $score += 2;
        }

        if ((bool) ($hints['informative_query'] ?? false) && str_contains($query, ' ')) {
            $score += 1;
        }

        if ((bool) ($hints['needs_scope'] ?? false) && preg_match('/\b(hoy|ayer|semana|mes|ano|año|desde|hasta|202[0-9])\b/u', $query) === 1) {
            $score += 1;
        }

        return $score;
    }
}
