<?php

declare(strict_types=1);

namespace App\Core;

/**
 * UnspscLookupService — Búsqueda local de códigos UNSPSC para Colombia.
 *
 * ARQUITECTURA (canon § 12.5 equivalente para productos):
 *   No existe API pública REST de Colombia Compra (confirmado 2026-04-19).
 *   El catálogo vive en framework/data/unspsc_co_catalog.json — actualizable sin tocar PHP.
 *   El código UNSPSC lo asigna el contador/empresario, no el sistema.
 *   El agente SUGIERE basado en búsqueda semántica local; el usuario confirma.
 *
 * Estructura UNSPSC (UN/SPSC versión 22):
 *   Segmento (2d) → Familia (4d) → Clase (6d) → Commodity (8d)
 *   Ejemplo: 50 → 5010 → 501015 → 50101501 (Arroz de grano largo)
 */
final class UnspscLookupService
{
    private const CATALOG_PATH = '/data/unspsc_co_catalog.json';

    /** @var array<string,mixed>|null */
    private ?array $catalog = null;

    private string $frameworkRoot;

    public function __construct(?string $frameworkRoot = null)
    {
        $this->frameworkRoot = $frameworkRoot
            ?? (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2));
    }

    /**
     * Busca códigos UNSPSC por palabra clave en nombre o aliases.
     * Retorna hasta $limit resultados ordenados por relevancia.
     *
     * @return array<int, array{code:string, name_es:string, segment:string, family:string, level:string, score:int}>
     */
    public function search(string $keyword, int $limit = 10): array
    {
        $catalog = $this->loadCatalog();
        $items = $catalog['items'] ?? [];
        if ($items === [] || trim($keyword) === '') {
            return [];
        }

        $kw = strtolower(trim($keyword));
        $words = array_filter(explode(' ', $kw), fn($w) => strlen($w) > 2);

        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $score = $this->scoreItem($item, $kw, $words);
            if ($score > 0) {
                $results[] = array_merge($item, ['score' => $score]);
            }
        }

        usort($results, fn($a, $b) => $b['score'] - $a['score']);
        return array_slice($results, 0, $limit);
    }

    /**
     * Busca por código exacto (8 dígitos) o prefijo de segmento/familia/clase.
     *
     * @return array<string,mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $code = trim($code);
        $catalog = $this->loadCatalog();
        foreach ($catalog['items'] ?? [] as $item) {
            if (is_array($item) && ($item['code'] ?? '') === $code) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Lista todos los segmentos disponibles en el catálogo.
     *
     * @return array<int, array{code:string, name_es:string}>
     */
    public function listSegments(): array
    {
        $catalog = $this->loadCatalog();
        $seen = [];
        $segments = [];
        foreach ($catalog['items'] ?? [] as $item) {
            if (!is_array($item)) continue;
            $seg = (string) ($item['segment_code'] ?? substr((string)($item['code'] ?? ''), 0, 2));
            if ($seg === '' || isset($seen[$seg])) continue;
            $seen[$seg] = true;
            $segments[] = ['code' => $seg, 'name_es' => (string)($item['segment_name'] ?? $seg)];
        }
        return $segments;
    }

    /**
     * Formatea resultado para mostrar al usuario en chat.
     */
    public function formatForChat(array $results): string
    {
        if ($results === []) {
            return "No encontré códigos UNSPSC para esa descripción. Puedes buscar en colombiacompra.gov.co/secop/consulta-codigo-unspsc o indicarme más detalles del producto.";
        }
        $lines = ["Códigos UNSPSC sugeridos:"];
        foreach ($results as $r) {
            $lines[] = "• **{$r['code']}** — {$r['name_es']}";
        }
        $lines[] = "\nConfirma cuál aplica y lo asigno al producto.";
        return implode("\n", $lines);
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function loadCatalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        $path = $this->frameworkRoot . self::CATALOG_PATH;
        if (!is_file($path)) {
            $this->catalog = ['items' => []];
            return $this->catalog;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->catalog = ['items' => []];
            return $this->catalog;
        }
        $decoded = json_decode(ltrim($raw, "\xEF\xBB\xBF"), true);
        $this->catalog = is_array($decoded) ? $decoded : ['items' => []];
        return $this->catalog;
    }

    /**
     * @param array<string,mixed> $item
     * @param string[] $words
     */
    private function scoreItem(array $item, string $fullKw, array $words): int
    {
        $score = 0;
        $code = strtolower((string)($item['code'] ?? ''));
        $name = strtolower((string)($item['name_es'] ?? ''));
        $aliases = array_map('strtolower', (array)($item['aliases'] ?? []));

        // Código exacto
        if ($code === $fullKw) return 200;

        // Nombre exacto
        if ($name === $fullKw) $score += 100;
        // Nombre contiene keyword completo
        elseif (str_contains($name, $fullKw)) $score += 60;

        // Aliases
        foreach ($aliases as $alias) {
            if ($alias === $fullKw) { $score += 80; break; }
            if (str_contains($alias, $fullKw)) { $score += 40; break; }
        }

        // Palabras individuales
        foreach ($words as $word) {
            if (str_contains($name, $word)) $score += 10;
            foreach ($aliases as $alias) {
                if (str_contains($alias, $word)) { $score += 5; break; }
            }
        }

        return $score;
    }
}
