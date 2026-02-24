<?php
// app/Core/OpenApiIntegrationImporter.php

namespace App\Core;

use RuntimeException;

final class OpenApiIntegrationImporter
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function import(array $options, bool $persist = true): array
    {
        $apiName = $this->sanitizeId((string) ($options['api_name'] ?? 'api_externa'));
        if ($apiName === '') {
            throw new RuntimeException('api_name invalido');
        }

        $provider = trim((string) ($options['provider'] ?? ucfirst(str_replace('_', ' ', $apiName))));
        $country = strtoupper(trim((string) ($options['country'] ?? 'CO')));
        $environment = strtolower(trim((string) ($options['environment'] ?? 'sandbox')));
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            $environment = 'sandbox';
        }
        $type = strtolower(trim((string) ($options['type'] ?? 'custom')));
        if (!in_array($type, ['e-invoicing', 'payments', 'erp', 'crm', 'custom'], true)) {
            $type = 'custom';
        }

        $docUrl = trim((string) ($options['doc_url'] ?? ''));
        $openapi = is_array($options['openapi'] ?? null) ? (array) $options['openapi'] : null;
        if ($openapi === null) {
            $openapiJson = trim((string) ($options['openapi_json'] ?? ''));
            if ($openapiJson !== '') {
                $decoded = json_decode($openapiJson, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('openapi_json invalido');
                }
                $openapi = $decoded;
            }
        }
        if ($openapi === null) {
            if ($docUrl === '') {
                throw new RuntimeException('Debes enviar doc_url o openapi_json');
            }
            $openapi = $this->fetchOpenApiFromUrl($docUrl);
        }

        $baseUrl = $this->detectBaseUrl($openapi, $docUrl);
        $auth = $this->detectAuth($openapi, (string) ($options['token_env'] ?? ''));
        $endpoints = $this->extractEndpoints($openapi);

        $contract = [
            'id' => $apiName,
            'type' => $type,
            'provider' => $provider !== '' ? $provider : ucfirst($apiName),
            'country' => $country !== '' ? $country : 'CO',
            'environment' => $environment,
            'base_url' => $baseUrl,
            'enabled' => false,
            'auth' => $auth,
            'metadata' => [
                'source' => 'openapi_import',
                'doc_url' => $docUrl,
                'openapi_version' => (string) ($openapi['openapi'] ?? $openapi['swagger'] ?? ''),
                'endpoints' => $endpoints,
                'imported_at' => date('c'),
            ],
        ];

        IntegrationValidator::validateOrFail($contract);
        $path = $this->integrationPath($apiName);
        if ($persist) {
            $this->writeJson($path, $contract);
        }

        return [
            'contract' => $contract,
            'path' => $path,
            'summary' => [
                'api_name' => $apiName,
                'provider' => $contract['provider'],
                'base_url' => $baseUrl,
                'auth_type' => (string) ($auth['type'] ?? 'custom'),
                'endpoint_count' => count($endpoints),
                'persisted' => $persist,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOpenApiFromUrl(string $docUrl): array
    {
        $parts = parse_url($docUrl);
        if (!is_array($parts)) {
            throw new RuntimeException('doc_url invalida');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Solo se permite doc_url http/https');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new RuntimeException('doc_url host no permitido');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isPrivateIp($host)) {
                throw new RuntimeException('doc_url apunta a IP privada no permitida');
            }
        }

        $allowedHosts = trim((string) (getenv('OPENAPI_IMPORT_ALLOWED_HOSTS') ?: ''));
        if ($allowedHosts !== '') {
            $allow = array_filter(array_map('trim', explode(',', $allowedHosts)));
            if (!empty($allow) && !in_array($host, $allow, true)) {
                throw new RuntimeException('doc_url host no permitido por politica');
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $raw = @file_get_contents($docUrl, false, $context);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('No se pudo leer OpenAPI desde doc_url');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAPI remoto no es JSON valido');
        }
        return $decoded;
    }

    private function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }
            $ranges = [
                ['10.0.0.0', '10.255.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['127.0.0.0', '127.255.255.255'],
            ];
            foreach ($ranges as [$start, $end]) {
                $startLong = ip2long($start);
                $endLong = ip2long($end);
                if ($startLong !== false && $endLong !== false && $long >= $startLong && $long <= $endLong) {
                    return true;
                }
            }
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd') || str_starts_with($ip, 'fe80') || $ip === '::1';
        }
        return true;
    }

    /**
     * @param array<string, mixed> $openapi
     */
    private function detectBaseUrl(array $openapi, string $docUrl): string
    {
        $servers = is_array($openapi['servers'] ?? null) ? (array) $openapi['servers'] : [];
        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }
            $url = trim((string) ($server['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        $schemes = is_array($openapi['schemes'] ?? null) ? (array) $openapi['schemes'] : [];
        $host = trim((string) ($openapi['host'] ?? ''));
        $basePath = trim((string) ($openapi['basePath'] ?? ''));
        if ($host !== '') {
            $scheme = !empty($schemes) ? strtolower((string) $schemes[0]) : 'https';
            $path = $basePath !== '' ? $basePath : '';
            return $scheme . '://' . $host . $path;
        }

        $parts = parse_url($docUrl);
        if (is_array($parts)) {
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
            $hostPart = trim((string) ($parts['host'] ?? ''));
            if ($hostPart !== '') {
                return $scheme . '://' . $hostPart;
            }
        }

        return 'https://api.example.com';
    }

    /**
     * @param array<string, mixed> $openapi
     * @return array<string, mixed>
     */
    private function detectAuth(array $openapi, string $tokenEnv): array
    {
        $tokenEnv = trim($tokenEnv) !== '' ? trim($tokenEnv) : 'INTEGRATION_API_TOKEN';
        $components = is_array($openapi['components'] ?? null) ? (array) $openapi['components'] : [];
        $schemes = is_array($components['securitySchemes'] ?? null) ? (array) $components['securitySchemes'] : [];
        if (empty($schemes) && is_array($openapi['securityDefinitions'] ?? null)) {
            $schemes = (array) $openapi['securityDefinitions'];
        }
        foreach ($schemes as $name => $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            $type = strtolower(trim((string) ($scheme['type'] ?? 'custom')));
            if ($type === 'http') {
                $httpScheme = strtolower(trim((string) ($scheme['scheme'] ?? '')));
                if ($httpScheme === 'bearer') {
                    return ['type' => 'bearer', 'token_env' => $tokenEnv];
                }
                if ($httpScheme === 'basic') {
                    return ['type' => 'basic', 'ref' => strtoupper($this->sanitizeId((string) $name)) . '_BASIC_CREDENTIALS'];
                }
            }
            if ($type === 'apikey' || $type === 'api_key') {
                $header = trim((string) ($scheme['name'] ?? 'X-API-Key'));
                return ['type' => 'api_key', 'token_env' => $tokenEnv, 'header' => $header];
            }
            if ($type === 'oauth2') {
                return ['type' => 'oauth2', 'ref' => strtoupper($this->sanitizeId((string) $name)) . '_OAUTH2'];
            }
        }
        return ['type' => 'custom', 'token_env' => $tokenEnv];
    }

    /**
     * @param array<string, mixed> $openapi
     * @return array<int, array<string, mixed>>
     */
    private function extractEndpoints(array $openapi): array
    {
        $paths = is_array($openapi['paths'] ?? null) ? (array) $openapi['paths'] : [];
        $rows = [];
        foreach ($paths as $path => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            foreach ($definition as $method => $operation) {
                $methodUpper = strtoupper(trim((string) $method));
                if (!in_array($methodUpper, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                $op = is_array($operation) ? $operation : [];
                $rows[] = [
                    'method' => $methodUpper,
                    'path' => (string) $path,
                    'operation_id' => (string) ($op['operationId'] ?? ''),
                    'summary' => (string) ($op['summary'] ?? ''),
                ];
            }
        }
        return $rows;
    }

    private function integrationPath(string $apiName): string
    {
        return $this->projectRoot . '/contracts/integrations/' . $this->sanitizeId($apiName) . '.integration.json';
    }

    private function sanitizeId(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]/', '_', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? $value;
        return trim((string) $value, '_');
    }

    private function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear directorio: ' . $dir);
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar contrato de integracion');
        }
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('No se pudo guardar contrato: ' . $path);
        }
    }
}

