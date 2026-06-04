<?php

declare(strict_types=1);

// framework/app/Core/Tools/ToolFactory.php

namespace App\Core\Tools;

use App\Core\Database;

/**
 * Creates and executes custom HTTP integration tools defined by agents at runtime.
 * All tools are tenant-scoped. SSRF protection on base_url.
 */
final class ToolFactory
{
    private const TIMEOUT         = 15;
    private const NAME_PATTERN    = '/^[a-z0-9_]{1,64}$/';
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    // -------------------------------------------------------------------------
    // Tool creation
    // -------------------------------------------------------------------------

    /**
     * @param array{name: string, description?: string, http_method?: string,
     *              base_url: string, auth_type?: string,
     *              headers?: array<string,string>, params_schema?: array<mixed>} $config
     * @return array{created: bool, tool_id: int, name: string}
     *       | array{error: string}
     */
    public function createTool(array $config, string $tenantId, Database $db): array
    {
        $name       = strtolower(trim((string) ($config['name']        ?? '')));
        $baseUrl    = trim((string) ($config['base_url']   ?? ''));
        $method     = strtoupper(trim((string) ($config['http_method'] ?? 'GET')));
        $authType   = (string) ($config['auth_type']    ?? 'none');
        $headers    = (array)  ($config['headers']      ?? []);
        $schema     = $config['params_schema'] ?? [];
        $desc       = (string) ($config['description']   ?? '');

        // Validation
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return ['error' => 'Nombre inválido: solo minúsculas, números y guión bajo, máx 64 chars'];
        }

        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return ['error' => 'base_url inválida'];
        }

        $ssrfCheck = $this->validateSsrf($baseUrl);
        if ($ssrfCheck !== null) {
            return ['error' => $ssrfCheck];
        }

        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return ['error' => 'http_method inválido'];
        }

        $this->ensureTable($db);

        $pdo = $db->getPdo();

        // Check uniqueness per tenant
        $stmt = $pdo->prepare('SELECT id FROM custom_tools WHERE tenant_id = ? AND name = ?');
        $stmt->execute([$tenantId, $name]);
        if ($stmt->fetch()) {
            return ['error' => "Ya existe una herramienta con el nombre '{$name}' para este tenant"];
        }

        $ins = $pdo->prepare("
            INSERT INTO custom_tools
                (tenant_id, name, description, http_method, base_url, auth_type, headers_json, params_schema)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $tenantId,
            $name,
            $desc,
            $method,
            $baseUrl,
            $authType,
            json_encode($headers, JSON_UNESCAPED_UNICODE),
            json_encode($schema,  JSON_UNESCAPED_UNICODE),
        ]);

        $toolId = (int) $pdo->lastInsertId();

        return ['created' => true, 'tool_id' => $toolId, 'name' => $name];
    }

    // -------------------------------------------------------------------------
    // Tool execution
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function executeTool(string $toolName, array $params, string $tenantId, Database $db): array
    {
        $this->ensureTable($db);

        $pdo  = $db->getPdo();
        $stmt = $pdo->prepare('SELECT * FROM custom_tools WHERE tenant_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$tenantId, $toolName]);
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return ['error' => "Herramienta '{$toolName}' no encontrada para este tenant"];
        }

        $headers      = json_decode((string) ($tool['headers_json'] ?? '{}'), true) ?? [];
        $method       = strtoupper((string) ($tool['http_method'] ?? 'GET'));
        $baseUrl      = (string) $tool['base_url'];
        $authType     = (string) ($tool['auth_type'] ?? 'none');

        $headers = $this->applyAuth($headers, $authType, $tenantId);

        $response = $this->executeRequest($method, $baseUrl, $params, $headers);

        // Sanitize before returning to agent
        if (is_string($response)) {
            $response = htmlspecialchars(strip_tags($response), ENT_QUOTES, 'UTF-8');
        } elseif (is_array($response)) {
            array_walk_recursive($response, static function (&$v): void {
                if (is_string($v)) {
                    $v = strip_tags($v);
                }
            });
        }

        return [
            'tool'     => $toolName,
            'response' => $response,
            'status'   => 'ok',
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function validateSsrf(string $url): ?string
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return 'URL sin host';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'IP privada no permitida';
            }
        }

        $lower = strtolower($host);
        if ($lower === 'localhost' || str_ends_with($lower, '.local') || str_ends_with($lower, '.internal')) {
            return 'Host local no permitido';
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function applyAuth(array $headers, string $authType, string $tenantId): array
    {
        // Auth credentials come from env vars prefixed by tenant, never from stored payload
        if ($authType === 'bearer') {
            $token = (string) getenv("CUSTOM_TOOL_TOKEN_{$tenantId}");
            if ($token !== '') {
                $headers['Authorization'] = "Bearer {$token}";
            }
        } elseif ($authType === 'api_key') {
            $key = (string) getenv("CUSTOM_TOOL_APIKEY_{$tenantId}");
            if ($key !== '') {
                $headers['X-Api-Key'] = $key;
            }
        }
        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $params
     * @return string|array<mixed>
     */
    private function executeRequest(string $method, string $url, array $params, array $headers): string|array
    {
        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        $ch = curl_init();

        if ($method === 'GET' && !empty($params)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            $body = json_encode($params, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $curlHeaders[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code < 200 || $code >= 300) {
            return "Error HTTP {$code}";
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : (string) $raw;
    }

    private function ensureTable(Database $db): void
    {
        try {
            $db->getPdo()->exec("
                CREATE TABLE IF NOT EXISTS custom_tools (
                    id           INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id    VARCHAR(64)  NOT NULL,
                    name         VARCHAR(64)  NOT NULL,
                    description  TEXT,
                    http_method  VARCHAR(10)  DEFAULT 'GET',
                    base_url     TEXT         NOT NULL,
                    auth_type    VARCHAR(32)  DEFAULT 'none',
                    headers_json JSON,
                    params_schema JSON,
                    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_tool (tenant_id, name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            // Table may already exist or DB may be SQLite in tests — ignore
        }
    }
}
