<?php

declare(strict_types=1);

// framework/app/Core/Tools/WebSearchTool.php

namespace App\Core\Tools;

/**
 * Web search tool with provider cascade: Tavily → Brave → DuckDuckGo lite.
 * Sanitizes all external data before returning it.
 * If all providers fail, returns an empty result set — never throws.
 */
final class WebSearchTool
{
    private const TIMEOUT = 5;

    /**
     * @return array{results: list<array{title:string,url:string,snippet:string,domain:string}>,
     *               source_api: string, query: string}
     *         | array{results: list<never>, error: string}
     */
    public function search(string $query, string $tenantId, int $maxResults = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['results' => [], 'error' => 'Query vacío', 'query' => ''];
        }

        // Provider cascade
        $result = $this->tryTavily($query, $maxResults);
        if ($result !== null) {
            return array_merge($result, ['query' => $query]);
        }

        $result = $this->tryBrave($query, $maxResults);
        if ($result !== null) {
            return array_merge($result, ['query' => $query]);
        }

        $result = $this->tryDuckDuckGo($query, $maxResults);
        if ($result !== null) {
            return array_merge($result, ['query' => $query]);
        }

        return ['results' => [], 'error' => 'Búsqueda no disponible temporalmente', 'query' => $query];
    }

    // -------------------------------------------------------------------------
    // Providers
    // -------------------------------------------------------------------------

    /** @return array{results: list<mixed>, source_api: string}|null */
    private function tryTavily(string $query, int $max): ?array
    {
        $apiKey = (string) getenv('TAVILY_API_KEY');
        if ($apiKey === '') {
            return null;
        }

        try {
            $payload = json_encode(['api_key' => $apiKey, 'query' => $query, 'max_results' => $max]);
            $raw = $this->httpPost('https://api.tavily.com/search', $payload, ['Content-Type: application/json']);
            if ($raw === null) {
                return null;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['results'])) {
                return null;
            }

            $results = [];
            foreach ((array) $data['results'] as $item) {
                $results[] = $this->sanitizeResult([
                    'title'   => (string) ($item['title'] ?? ''),
                    'url'     => (string) ($item['url'] ?? ''),
                    'snippet' => (string) ($item['content'] ?? $item['snippet'] ?? ''),
                    'domain'  => (string) ($item['domain'] ?? parse_url((string) ($item['url'] ?? ''), PHP_URL_HOST) ?? ''),
                ]);
            }

            return ['results' => array_slice($results, 0, $max), 'source_api' => 'tavily'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{results: list<mixed>, source_api: string}|null */
    private function tryBrave(string $query, int $max): ?array
    {
        $apiKey = (string) getenv('BRAVE_SEARCH_API_KEY');
        if ($apiKey === '') {
            return null;
        }

        try {
            $url = 'https://api.search.brave.com/res/v1/web/search?q=' . urlencode($query) . '&count=' . $max;
            $raw = $this->httpGet($url, ["Accept: application/json", "X-Subscription-Token: {$apiKey}"]);
            if ($raw === null) {
                return null;
            }
            $data = json_decode($raw, true);
            $items = $data['web']['results'] ?? [];
            if (empty($items)) {
                return null;
            }

            $results = [];
            foreach ((array) $items as $item) {
                $results[] = $this->sanitizeResult([
                    'title'   => (string) ($item['title'] ?? ''),
                    'url'     => (string) ($item['url'] ?? ''),
                    'snippet' => (string) ($item['description'] ?? ''),
                    'domain'  => (string) parse_url((string) ($item['url'] ?? ''), PHP_URL_HOST),
                ]);
            }

            return ['results' => array_slice($results, 0, $max), 'source_api' => 'brave'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{results: list<mixed>, source_api: string}|null */
    private function tryDuckDuckGo(string $query, int $max): ?array
    {
        try {
            $url = 'https://lite.duckduckgo.com/lite/?q=' . urlencode($query);
            $raw = $this->httpGet($url, []);
            if ($raw === null || strlen($raw) < 100) {
                return null;
            }

            $results = $this->parseDuckDuckGoLite($raw, $max);
            if (empty($results)) {
                return null;
            }

            return ['results' => $results, 'source_api' => 'duckduckgo_lite'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Minimal HTML parser for DuckDuckGo Lite results.
     *
     * @return list<array{title:string,url:string,snippet:string,domain:string}>
     */
    private function parseDuckDuckGoLite(string $html, int $max): array
    {
        $results = [];
        // DDG lite wraps each result in a table row with class "result-link"
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*result-link[^"\']*["\'][^>]*>([^<]+)<\/a>.*?<td[^>]*class=["\'][^"\']*result-snippet[^"\']*["\'][^>]*>([^<]*)/si', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $url     = html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES);
            $title   = html_entity_decode(strip_tags((string) ($m[2] ?? '')), ENT_QUOTES);
            $snippet = html_entity_decode(strip_tags((string) ($m[3] ?? '')), ENT_QUOTES);
            $domain  = (string) (parse_url($url, PHP_URL_HOST) ?? '');

            if ($url === '' || $title === '') {
                continue;
            }

            $results[] = $this->sanitizeResult(['title' => $title, 'url' => $url, 'snippet' => $snippet, 'domain' => $domain]);

            if (count($results) >= $max) {
                break;
            }
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /** @param list<string> $headers */
    private function httpPost(string $url, string $body, array $headers): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $code < 200 || $code >= 300) {
            return null;
        }
        return (string) $response;
    }

    /** @param list<string> $headers */
    private function httpGet(string $url, array $headers): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'SUKI-ERP/1.0',
        ]);
        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $code < 200 || $code >= 300) {
            return null;
        }
        return (string) $response;
    }

    // -------------------------------------------------------------------------
    // Sanitization
    // -------------------------------------------------------------------------

    /**
     * @param array{title:string,url:string,snippet:string,domain:string} $result
     * @return array{title:string,url:string,snippet:string,domain:string}
     */
    private function sanitizeResult(array $result): array
    {
        return [
            'title'   => htmlspecialchars(strip_tags(mb_substr($result['title'],   0, 200)), ENT_QUOTES, 'UTF-8'),
            'url'     => filter_var($result['url'], FILTER_SANITIZE_URL) ?: '',
            'snippet' => htmlspecialchars(strip_tags(mb_substr($result['snippet'], 0, 500)), ENT_QUOTES, 'UTF-8'),
            'domain'  => htmlspecialchars(strip_tags($result['domain']), ENT_QUOTES, 'UTF-8'),
        ];
    }
}
