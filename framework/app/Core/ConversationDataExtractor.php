<?php

declare(strict_types=1);

namespace App\Core;

/**
 * ConversationDataExtractor — detects typed data inside natural language messages.
 *
 * Country-aware: extraction keywords come from data_validation_policies.json under
 * countries.{countryCode}.extraction_keywords and common.extraction_keywords.
 * Adding a new country requires no PHP changes — only JSON edits.
 *
 * Country code comes from tenant_business_config.country at runtime.
 *
 * Example (CO):
 *   extractFromText("mi cédula es 79421356 y cel 3114567891")
 *   → [{type:'person_id', value:'79421356', valid:true}, {type:'phone', value:'3114567891', valid:true}]
 */
final class ConversationDataExtractor
{
    private DataQualityGuard $guard;
    private array            $keywords;     // merged from JSON
    private array            $countryTypes; // field_types for country

    public function __construct(?DataQualityGuard $guard = null)
    {
        $this->guard = $guard ?? new DataQualityGuard();
        $this->loadConfig();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Extract all identifiable typed data from a natural language message.
     *
     * @return list<array{type:string, value:string, raw:string, confidence:float, valid:bool, violation:?string}>
     */
    public function extractFromText(string $text): array
    {
        $results = [];
        $seen    = [];

        foreach ($this->buildPatterns() as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (!preg_match($pattern, $text, $matches)) {
                    continue;
                }
                $raw   = trim($matches[1]);
                $clean = $this->normalizeValue($raw, $type);
                if ($clean === '') {
                    continue;
                }
                $key = $type . ':' . $clean;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $violations = $this->guard->validateFields([$type => $clean]);
                $valid      = empty($violations['reject']);

                $results[] = [
                    'type'       => $type,
                    'value'      => $clean,
                    'raw'        => $raw,
                    'confidence' => $this->computeConfidence($text, $type),
                    'valid'      => $valid,
                    'violation'  => !$valid ? ($violations['reject'][0]['reason'] ?? null) : null,
                ];
                break; // first matching pattern wins per type
            }
        }

        return $results;
    }

    /**
     * Extract a specific field type from text.
     */
    public function extractField(string $text, string $type): ?array
    {
        foreach ($this->extractFromText($text) as $r) {
            if ($r['type'] === $type) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Flat key→value array of valid, high-confidence fields, ready for CRUD $data.
     */
    public function toDataArray(string $text, float $minConfidence = 0.70): array
    {
        $data = [];
        foreach ($this->extractFromText($text) as $r) {
            if ($r['valid'] && $r['confidence'] >= $minConfidence) {
                $data[$r['type']] = $r['value'];
            }
        }
        return $data;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function loadConfig(): void
    {
        $countryCode  = $this->guard->getCountryCode();
        $common       = (array) PolicyLoader::get('data_validation_policies', 'common', []);
        $allCountries = (array) PolicyLoader::get('data_validation_policies', 'countries', []);
        $countryConf  = $allCountries[$countryCode] ?? $allCountries['CO'] ?? [];

        // Merge keywords: country-specific overrides common
        $this->keywords = array_merge(
            (array) ($common['extraction_keywords']       ?? []),
            (array) ($countryConf['extraction_keywords']  ?? [])
        );

        $this->countryTypes = (array) ($countryConf['field_types'] ?? []);
    }

    /**
     * Build regex patterns dynamically from JSON keywords + type-specific digit templates.
     * Called once per extraction (could be cached if performance matters).
     */
    private function buildPatterns(): array
    {
        $patterns = [];

        // ── Numeric structured types ──────────────────────────────────────────
        foreach (['tax_id', 'person_id'] as $type) {
            $kws = $this->buildKeywordAlternation($this->keywords[$type] ?? []);
            if ($kws === '') continue;
            $digitCapture = $this->digitCapture($type);
            $patterns[$type] = [
                // Contextual: "nit es 900123456-1"
                '/\b(?:' . $kws . ')\b[^0-9]{0,30}?' . $digitCapture . '/ui',
            ];
            // Bare NIT with separator (CO: 900.123.456-1)
            if ($type === 'tax_id') {
                $patterns[$type][] = '/\b([0-9]{2,3}\.[0-9]{3}\.[0-9]{3}-[0-9Kk])\b/u';
            }
        }

        // Phone
        $phoneKws = $this->buildKeywordAlternation($this->keywords['phone'] ?? []);
        $phonePat = $this->countryTypes['phone']['normalized_regex'] ?? '[0-9]{8,12}';
        // Normalize the phone regex for use as a capture group (remove ^ and $)
        $phoneCapPat = '(' . trim($phonePat, '^$') . ')';
        if ($phoneKws !== '') {
            $patterns['phone'] = [
                '/\b(?:' . $phoneKws . ')\b[^0-9]{0,20}?' . $phoneCapPat . '/ui',
                // Bare number matching country pattern
                '/(?<!\d)' . $phoneCapPat . '(?!\d)/u',
            ];
        }

        // Email — bare pattern is sufficient; @ is self-identifying
        // (contextual patterns cause backtracking false matches on "correo info@...")
        $patterns['email'] = [
            '/\b([a-zA-Z0-9._%+\-]{1,64}@[a-zA-Z0-9.\-]{1,253}\.[a-zA-Z]{2,})\b/u',
        ];

        // Nombre
        $nombreKws = $this->buildKeywordAlternation($this->keywords['nombre'] ?? []);
        if ($nombreKws !== '') {
            $patterns['nombre'] = [
                '/\b(?:' . $nombreKws . ')\b[^0-9\n]{0,10}?([\p{L}][\p{L}\s.,\-]{2,80}?)(?:\s*[,.\n;]|$)/uim',
            ];
        }

        return $patterns;
    }

    /**
     * Build keyword alternation string for use inside (?:...) in a regex.
     * Escapes special chars, handles dots for abbreviations like "n.i.t".
     */
    private function buildKeywordAlternation(array $keywords): string
    {
        if (empty($keywords)) {
            return '';
        }
        $escaped = array_map(
            fn(string $kw): string => preg_quote($kw, '/'),
            $keywords
        );
        // Longest first to avoid partial match swallowing
        usort($escaped, fn($a, $b) => strlen($b) - strlen($a));
        return implode('|', $escaped);
    }

    /**
     * Return a capture group pattern for a structured type based on country config.
     * Checks normalized_regex to decide if alphanumeric (RFC, CURP) or numeric-only (NIT, RUC…).
     */
    private function digitCapture(string $type): string
    {
        $typeRegex = (string) ($this->countryTypes[$type]['normalized_regex'] ?? '');
        $isAlpha   = str_contains($typeRegex, 'A-Z') || str_contains($typeRegex, 'a-z');

        if ($type === 'tax_id') {
            if ($isAlpha) {
                // Alphanumeric IDs: RFC MX, CURP, etc. — letter+digit runs, no spaces
                return '([A-Z0-9&Ñ]{8,18})';
            }
            // Numeric IDs with optional separators: NIT CO, RUC PE, RUT CL
            return '([0-9][0-9.\s\-]{5,14}[0-9](?:[\s\-][0-9Kk])?)';
        }
        if ($type === 'person_id' && $isAlpha) {
            return '([A-Z0-9]{8,20})';
        }
        return '([0-9]{5,12})';
    }

    private function normalizeValue(string $raw, string $type): string
    {
        switch ($type) {
            case 'tax_id':
            case 'person_id':
                // If the country's regex expects letters (RFC, CURP, etc.), keep alphanumeric
                $typeRegex = (string) ($this->countryTypes[$type]['normalized_regex'] ?? '');
                if (str_contains($typeRegex, 'A-Z') || str_contains($typeRegex, 'a-z')) {
                    return strtoupper(preg_replace('/[\s.\-]/', '', $raw) ?? $raw);
                }
                // Purely numeric types (NIT, cédula, RUC, DNI…)
                $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';
                return $digits;

            case 'phone':
                $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';
                // Add delimiters before using JSON regex in preg_match
                $rawRegex = (string) ($this->countryTypes['phone']['normalized_regex'] ?? '');
                if ($rawRegex !== '') {
                    $regex = '/' . str_replace('/', '\/', $rawRegex) . '/i';
                    if (@preg_match($regex, $digits)) {
                        return $digits;
                    }
                }
                return (strlen($digits) >= 7 && strlen($digits) <= 13) ? $digits : '';

            case 'email':
                return strtolower(trim($raw));

            default:
                return trim((string) preg_replace('/\s+/', ' ', $raw));
        }
    }

    private function computeConfidence(string $text, string $type): float
    {
        $lower    = strtolower($text);
        $keywords = $this->keywords[$type] ?? [];
        foreach ($keywords as $kw) {
            if (str_contains($lower, strtolower((string) $kw))) {
                return 0.92;
            }
        }
        if ($type === 'email') {
            return 0.85;
        }
        return 0.72;
    }
}
