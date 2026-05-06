<?php
declare(strict_types=1);

namespace App\Core\Agents;

/**
 * OntologyNormalizer
 *
 * Normaliza texto de usuario aplicando ontología LATAM en dos capas:
 *
 *   Capa 1 — country_language_overrides.json
 *     · global.typo_rules        : errores tipográficos universales
 *     · global.synonyms          : sinónimos neutros (cel→teléfono, etc.)
 *     · countries.<CC>.typo_rules: correcciones específicas del país
 *     · countries.<CC>.synonyms  : vocabulario local (rfc→documento, cuit→documento)
 *     · fiscal_equivalents.<CC>  : DIAN/SAT/SUNAT/AFIP → "autoridad_fiscal"
 *     · sectors.<sector>.synonyms: vocabulario por industria (salud, producción, legal…)
 *
 *   Capa 2 — latam_es_col_conversation_lexicon.json  (modismos + phrase rules + stop tokens)
 *     · phrase_rules : frases coloquiales → frases canónicas
 *     · synonyms     : modismos → términos ERP
 *     · stop_tokens  : muletillas eliminadas antes del embedding
 *
 * Uso:
 *   $clean = OntologyNormalizer::apply($rawText, 'MX', 'app');
 *
 * Sin estado, sin DI, cache de archivos estático. Llamable desde cualquier punto
 * del pipeline antes de que el texto llegue al embedding de Qdrant.
 */
final class OntologyNormalizer
{
    /** @var array<string, array> */
    private static array $overridesCache = [];
    /** @var array<string, array> */
    private static array $lexiconCache   = [];

    /**
     * Punto de entrada principal.
     *
     * @param string $text    Texto ya básicamente normalizado (minúsculas, sin acentos)
     * @param string $country Código ISO-3166-1 alpha-2 (CO, MX, AR, PE, CL, VE, EC…)
     * @param string $mode    Pipeline mode: app | builder | marketplace
     * @param string $sector  Sector de la empresa: commerce | health | production | services | legal | (vacío=todos)
     */
    public static function apply(
        string $text,
        string $country = 'CO',
        string $mode    = 'app',
        string $sector  = ''
    ): string {
        if (trim($text) === '') {
            return $text;
        }

        $overrides = self::loadOverrides();
        $lexicon   = self::loadLexicon();
        $country   = strtoupper(trim($country));

        // ── Capa 1a: typo_rules globales ────────────────────────────────────
        foreach ((array) ($overrides['global']['typo_rules'] ?? []) as $rule) {
            $text = self::applyRule($text, (string) ($rule['match'] ?? ''), (string) ($rule['replace'] ?? ''));
        }

        // ── Capa 1b: synonyms globales ───────────────────────────────────────
        $text = self::applySynonyms($text, (array) ($overrides['global']['synonyms'] ?? []));

        // ── Capa 1c: typo_rules por país ─────────────────────────────────────
        foreach ((array) ($overrides['countries'][$country]['typo_rules'] ?? []) as $rule) {
            $text = self::applyRule($text, (string) ($rule['match'] ?? ''), (string) ($rule['replace'] ?? ''));
        }

        // ── Capa 1d: synonyms por país (RUC→documento, RFC→documento, etc.) ──
        $text = self::applySynonyms($text, (array) ($overrides['countries'][$country]['synonyms'] ?? []));

        // ── Capa 1e: equivalentes fiscales por país (DIAN/SAT/SUNAT→autoridad_fiscal) ──
        $fiscalTerms = (array) ($overrides['fiscal_equivalents'][$country] ?? []);
        if (!empty($fiscalTerms)) {
            $text = self::applySynonyms($text, $fiscalTerms);
        }

        // ── Capa 1f: vocabulario por sector ──────────────────────────────────
        if ($sector !== '') {
            $text = self::applySynonyms($text, (array) ($overrides['sectors'][$sector]['synonyms'] ?? []));
        } else {
            // Sin sector declarado: aplicar todos los sectores (orden alfabético, menos agresivo)
            foreach ((array) ($overrides['sectors'] ?? []) as $sectorDef) {
                $text = self::applySynonyms($text, (array) ($sectorDef['synonyms'] ?? []));
            }
        }

        // ── Capa 2a: phrase_rules (expresiones coloquiales multi-palabra) ────
        foreach ((array) ($lexicon['phrase_rules'] ?? []) as $rule) {
            $text = self::applyRule($text, (string) ($rule['match'] ?? ''), (string) ($rule['replace'] ?? ''));
        }

        // ── Capa 2b: synonyms del lexicón base LATAM ─────────────────────────
        $text = self::applySynonyms($text, (array) ($lexicon['synonyms'] ?? []));

        // ── Capa 2c: stop_tokens (muletillas eliminadas) ─────────────────────
        foreach ((array) ($lexicon['stop_tokens'] ?? []) as $token) {
            $token = strtolower(trim((string) $token));
            if ($token === '') {
                continue;
            }
            $text = (string) (preg_replace('/\b' . preg_quote($token, '/') . '\b/u', ' ', $text) ?? $text);
        }

        return (string) (preg_replace('/\s+/', ' ', trim($text)) ?? $text);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private static function applyRule(string $text, string $match, string $replace): string
    {
        if ($match === '') {
            return $text;
        }
        return str_ireplace($match, $replace, $text);
    }

    private static function applySynonyms(string $text, array $synonyms): string
    {
        foreach ($synonyms as $alias => $target) {
            $alias  = strtolower(trim((string) $alias));
            $target = strtolower(trim((string) $target));
            if ($alias === '' || $target === '' || $alias === $target) {
                continue;
            }
            $text = (string) (preg_replace('/\b' . preg_quote($alias, '/') . '\b/u', $target, $text) ?? $text);
        }
        return $text;
    }

    private static function loadOverrides(): array
    {
        $key = 'overrides';
        if (!isset(self::$overridesCache[$key])) {
            $path = dirname(__DIR__, 3) . '/contracts/agents/country_language_overrides.json';
            $decoded = file_exists($path)
                ? json_decode((string) file_get_contents($path), true)
                : null;
            self::$overridesCache[$key] = is_array($decoded) ? $decoded : [];
        }
        return self::$overridesCache[$key];
    }

    private static function loadLexicon(): array
    {
        $key = 'lexicon';
        if (!isset(self::$lexiconCache[$key])) {
            $path = dirname(__DIR__, 3) . '/contracts/agents/latam_es_col_conversation_lexicon.json';
            $decoded = file_exists($path)
                ? json_decode((string) file_get_contents($path), true)
                : null;
            self::$lexiconCache[$key] = is_array($decoded) ? $decoded : [];
        }
        return self::$lexiconCache[$key];
    }
}
