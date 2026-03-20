<?php
// app/Core/LLM/LLMRouter.php

namespace App\Core\LLM;

use App\Core\SecurityStateRepository;
use RuntimeException;

final class LLMRouter
{
    private array $config;
    private static array $circuit = [];
    private ?SecurityStateRepository $securityRepo = null;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? $this->loadConfig();
    }

    public function chat(array $capsule, array $options = []): array
    {
        $policy = $capsule['policy'] ?? [];
        $providers = $this->providerOrder($policy, $options);
        $this->enforceSessionQuota($options);
        $requiresStrictJson = !empty($policy['requires_strict_json']);
        $responseSchema = $this->deriveResponseSchema($capsule);

        $prompt = $this->buildPrompt($capsule);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($policy)],
            ['role' => 'user', 'content' => $prompt],
        ];

        $lastError = null;
        $attempted = [];
        $providerErrors = [];
        $providerStatuses = [];
        $failoverReason = 'none';
        foreach ($providers as $providerName) {
            if ($this->isCircuitOpen($providerName)) {
                $providerStatuses[$providerName] = 'circuit_open';
                continue;
            }
            $localQuota = $this->consumeProviderRateLimit($providerName, $options);
            if (!($localQuota['ok'] ?? true)) {
                $providerErrors[$providerName] = (string) ($localQuota['message'] ?? 'provider quota guard');
                $providerStatuses[$providerName] = 'quota_exhausted';
                if ($failoverReason === 'none') {
                    $failoverReason = 'provider_quota_guard';
                }
                continue;
            }
            $attempted[] = $providerName;
            try {
                $provider = $this->makeProvider($providerName);
                $result = $provider->sendChat($messages, [
                    'max_tokens' => $policy['max_output_tokens'] ?? ($this->config['limits']['max_tokens'] ?? 600),
                    'temperature' => $options['temperature'] ?? 0.2,
                    'strict_json' => $requiresStrictJson,
                    'response_schema' => $responseSchema,
                ]);
                $text = $result['text'] ?? '';
                $json = $this->extractJson($text);
                if ($requiresStrictJson && !is_array($json)) {
                    throw new RuntimeException(
                        'Strict JSON requerido: salida no valida de ' . $providerName
                    );
                }

                return [
                    'provider' => $providerName,
                    'text' => $text,
                    'json' => $json,
                    'usage' => $result['usage'] ?? [],
                    'raw' => $result['raw'] ?? null,
                    'attempted_providers' => $attempted,
                    'provider_errors' => $providerErrors,
                    'provider_statuses' => array_merge($providerStatuses, [$providerName => 'healthy']),
                    'failover_count' => max(0, count($attempted) - 1),
                    'failover_reason' => $failoverReason,
                ];
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                $reason = $this->classifyProviderFailureReason($lastError);
                $providerErrors[$providerName] = $lastError;
                $providerStatuses[$providerName] = $reason;
                if ($failoverReason === 'none') {
                    $failoverReason = $reason;
                }
                $this->tripCircuit($providerName, $reason);
                continue;
            }
        }

        $baseError = $lastError ?: 'No hay proveedores LLM disponibles.';
        if ($providerErrors !== []) {
            $baseError .= ' | provider_errors=' . json_encode(
                $providerErrors,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        if ($providerStatuses !== []) {
            $baseError .= ' | provider_statuses=' . json_encode(
                $providerStatuses,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        throw new RuntimeException($baseError);
    }

    private function loadConfig(): array
    {
        $path = dirname(__DIR__, 3) . '/config/llm.php';
        if (is_file($path)) {
            $cfg = require $path;
            if (is_array($cfg)) {
                return $cfg;
            }
        }
        return [
            'providers' => [
                'deepseek' => [
                    'class' => \App\Core\LLM\Providers\DeepSeekProvider::class,
                    'enabled' => !empty(getenv('DEEPSEEK_API_KEY')),
                ],
                'gemini' => [
                    'class' => \App\Core\LLM\Providers\GeminiProvider::class,
                    'enabled' => !empty(getenv('GEMINI_API_KEY')),
                ],
            ],
            'models' => [],
            'limits' => ['timeout' => 20, 'max_tokens' => 600],
        ];
    }

    private function providerOrder(array $policy, array $options): array
    {
        $mode = $this->resolveProviderMode($options);
        $order = [];

        if (in_array($mode, ['groq', 'gemini', 'openrouter', 'claude', 'deepseek'], true)) {
            $order[] = $mode;
        } else {
            $latency = (int) ($policy['latency_budget_ms'] ?? 1200);
            if ($latency <= 1200) {
                $order[] = 'groq';
                $order[] = 'gemini';
            } else {
                $order[] = 'gemini';
                $order[] = 'groq';
            }
        }

        if (!empty($this->config['providers']['openrouter']['enabled'])) {
            $order[] = 'openrouter';
        }
        if (!empty($this->config['providers']['claude']['enabled'])) {
            $order[] = 'claude';
        }

        foreach (array_keys((array) ($this->config['providers'] ?? [])) as $providerName) {
            if ($this->providerEnabled((string) $providerName)) {
                $order[] = (string) $providerName;
            }
        }

        $unique = [];
        foreach (array_values(array_unique($order)) as $providerName) {
            if ($this->providerEnabled((string) $providerName)) {
                $unique[] = (string) $providerName;
            }
        }

        return $unique;
    }

    private function resolveProviderMode(array $options): string
    {
        $candidates = [
            $options['provider_mode'] ?? null,
            $options['mode'] ?? null,
            getenv('LLM_ROUTER_MODE') ?: null,
        ];

        foreach ($candidates as $candidate) {
            $mode = strtolower(trim((string) $candidate));
            if (in_array($mode, ['auto', 'groq', 'gemini', 'openrouter', 'claude', 'deepseek'], true)) {
                return $mode;
            }
        }

        return 'auto';
    }

    private function makeProvider(string $name)
    {
        $provider = $this->config['providers'][$name] ?? null;
        if (!$provider || empty($provider['class'])) {
            throw new RuntimeException("Proveedor {$name} no configurado.");
        }
        $class = $provider['class'];
        if (!class_exists($class)) {
            throw new RuntimeException("Clase {$class} no encontrada.");
        }
        return new $class($this->config);
    }

    private function systemPrompt(array $policy): string
    {
        $strict = !empty($policy['requires_strict_json']);
        if ($strict) {
            return 'Responde solo con JSON valido. No uses markdown.';
        }
        return 'Responde breve y claro.';
    }

    private function buildPrompt(array $capsule): string
    {
        if (!empty($capsule['prompt_contract']) && is_array($capsule['prompt_contract'])) {
            $payload = json_encode(
                $capsule['prompt_contract'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            if (is_string($payload) && $payload !== '') {
                return $payload;
            }
        }

        $parts = [];
        $parts[] = 'Usuario: ' . ($capsule['user_message'] ?? '');
        if (!empty($capsule['entity'])) {
            $parts[] = 'Entidad: ' . $capsule['entity'];
        }
        if (!empty($capsule['entity_contract_min']['required'])) {
            $parts[] = 'Campos requeridos: ' . implode(', ', $capsule['entity_contract_min']['required']);
        }
        if (!empty($capsule['state']['collected'])) {
            $parts[] = 'Datos ya recibidos: ' . json_encode($capsule['state']['collected'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($capsule['state']['missing'])) {
            $parts[] = 'Faltantes: ' . json_encode($capsule['state']['missing'], JSON_UNESCAPED_UNICODE);
        }
        return implode("\n", $parts);
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') return null;

        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);
        $text = trim($text);

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function isCircuitOpen(string $provider): bool
    {
        if (!isset(self::$circuit[$provider])) {
            return false;
        }
        $entry = self::$circuit[$provider];
        $expiresAt = (int) ($entry['expires_at'] ?? 0);
        if ($expiresAt <= time()) {
            unset(self::$circuit[$provider]);
            return false;
        }
        return true;
    }

    private function tripCircuit(string $provider, string $reason = 'provider_error'): void
    {
        if ($reason === 'quota_exhausted') {
            $ttl = max(30, (int) (getenv('LLM_CIRCUIT_TTL_QUOTA_SECONDS') ?: 600));
        } elseif ($reason === 'invalid_config') {
            $ttl = max(60, (int) (getenv('LLM_CIRCUIT_TTL_INVALID_CONFIG_SECONDS') ?: 3600));
        } elseif ($reason === 'timeout') {
            $ttl = max(20, (int) (getenv('LLM_CIRCUIT_TTL_TIMEOUT_SECONDS') ?: 180));
        } elseif ($reason === 'strict_json_violation') {
            $ttl = max(10, (int) (getenv('LLM_CIRCUIT_TTL_JSON_SECONDS') ?: 45));
        } else {
            $ttl = max(15, (int) (getenv('LLM_CIRCUIT_TTL_ERROR_SECONDS') ?: 120));
        }
        self::$circuit[$provider] = [
            'reason' => $reason,
            'opened_at' => time(),
            'expires_at' => time() + $ttl,
        ];
    }

    private function providerEnabled(string $name): bool
    {
        $provider = $this->config['providers'][$name] ?? null;
        if (!is_array($provider)) {
            return false;
        }
        return !empty($provider['enabled']) && !empty($provider['class']);
    }

    private function isQuotaError(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }
        $patterns = [
            'quota',
            'rate limit',
            'resource has been exhausted',
            'insufficient_quota',
            '429',
            'limit exceeded',
            'too many requests',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isInvalidConfigError(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }
        $patterns = [
            'api key requerido',
            'api key required',
            'invalid api key',
            'invalid key',
            'user not found',
            'unauthorized',
            'unauthorised',
            'forbidden',
            'authentication',
            'permission denied',
            'account not found',
            '401',
            '403',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function isTimeoutError(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }
        $patterns = [
            'timed out',
            'timeout',
            'deadline exceeded',
            'curl error 28',
            'connection timed out',
        ];
        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function classifyProviderFailureReason(string $message): string
    {
        if ($this->isQuotaError($message)) {
            return 'quota_exhausted';
        }
        if ($this->isInvalidConfigError($message)) {
            return 'invalid_config';
        }
        if ($this->isTimeoutError($message)) {
            return 'timeout';
        }
        return $this->isStrictJsonViolation($message);
    }

    private function enforceSessionQuota(array $options): void
    {
        $enabled = (string) (getenv('LLM_SESSION_QUOTA_ENABLED') ?: '1');
        if (!in_array(strtolower($enabled), ['1', 'true', 'yes', 'on'], true)) {
            return;
        }

        $sessionId = trim((string) ($options['session_id'] ?? ''));
        $tenantId = trim((string) ($options['tenant_id'] ?? 'default'));
        $projectId = trim((string) ($options['project_id'] ?? 'default'));
        if ($sessionId === '') {
            return;
        }

        $maxRequests = max(1, (int) (getenv('LLM_MAX_REQUESTS_PER_SESSION') ?: 120));
        $windowSeconds = max(60, (int) (getenv('LLM_SESSION_QUOTA_WINDOW_SECONDS') ?: 86400));
        $bucket = 'llm_session:' . $tenantId . ':' . $projectId . ':' . $sessionId;
        $check = $this->securityRepository()->consumeRateLimit($bucket, $maxRequests, $windowSeconds);
        if (!($check['ok'] ?? false)) {
            $retryAfter = (int) ($check['retry_after'] ?? 0);
            throw new RuntimeException(
                'Quota de solicitudes LLM por sesion excedida. Espera ' . $retryAfter . ' segundos.'
            );
        }
    }

    private function securityRepository(): SecurityStateRepository
    {
        if ($this->securityRepo instanceof SecurityStateRepository) {
            return $this->securityRepo;
        }
        $path = trim((string) (getenv('SECURITY_STATE_DB_PATH') ?: ''));
        $this->securityRepo = new SecurityStateRepository($path !== '' ? $path : null);
        return $this->securityRepo;
    }

    private function consumeProviderRateLimit(string $providerName, array $options): array
    {
        $providerName = strtolower(trim($providerName));
        if ($providerName === '') {
            return ['ok' => true];
        }

        $providerVar = 'LLM_MAX_REQUESTS_PER_MINUTE_' . strtoupper($providerName);
        $limit = (int) (getenv($providerVar) ?: getenv('LLM_MAX_REQUESTS_PER_MINUTE') ?: 0);
        if ($limit <= 0) {
            return ['ok' => true];
        }

        $tenantId = trim((string) ($options['tenant_id'] ?? 'default'));
        $projectId = trim((string) ($options['project_id'] ?? 'default'));
        $bucket = 'llm_provider:' . $providerName . ':' . $tenantId . ':' . $projectId;
        $check = $this->securityRepository()->consumeRateLimit($bucket, $limit, 60);
        if (!($check['ok'] ?? false)) {
            $retryAfter = (int) ($check['retry_after'] ?? 0);
            return [
                'ok' => false,
                'message' => 'Quota local por proveedor excedida para ' . $providerName . ' (retry_after=' . $retryAfter . 's)',
            ];
        }

        return ['ok' => true];
    }

    private function isStrictJsonViolation(string $message): string
    {
        $message = strtolower(trim($message));
        if ($message !== '' && str_contains($message, 'strict json requerido')) {
            return 'strict_json_violation';
        }
        return 'provider_error';
    }

    private function deriveResponseSchema(array $capsule): ?array
    {
        $output = $capsule['prompt_contract']['OUTPUT_FORMAT'] ?? null;
        if (!is_array($output) || $output === []) {
            return null;
        }

        $properties = [];
        $required = [];
        foreach ($output as $key => $descriptor) {
            $name = trim((string) $key);
            if ($name === '') {
                continue;
            }
            $propertySchema = $this->descriptorToSchema($descriptor);
            if ($propertySchema === []) {
                continue;
            }
            $required[] = $name;
            $properties[$name] = $propertySchema;
        }

        if ($properties === []) {
            return null;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    private function descriptorToSchema($descriptor): array
    {
        if (is_object($descriptor)) {
            $descriptor = (array) $descriptor;
        }

        if (is_array($descriptor)) {
            if ($descriptor === []) {
                return ['type' => 'array', 'items' => ['type' => 'string']];
            }
            $isAssociative = array_keys($descriptor) !== range(0, count($descriptor) - 1);
            if ($isAssociative) {
                if (isset($descriptor['type'])) {
                    $schema = ['type' => $this->mapSchemaType($descriptor['type'])];
                    if (!empty($descriptor['enum']) && is_array($descriptor['enum'])) {
                        $enum = [];
                        foreach ($descriptor['enum'] as $item) {
                            $itemText = trim((string) $item);
                            if ($itemText !== '') {
                                $enum[] = $itemText;
                            }
                        }
                        if ($enum !== []) {
                            $schema['enum'] = array_values(array_unique($enum));
                        }
                    }
                    if (isset($descriptor['minimum']) && is_numeric($descriptor['minimum'])) {
                        $schema['minimum'] = (float) $descriptor['minimum'];
                    }
                    if (isset($descriptor['maximum']) && is_numeric($descriptor['maximum'])) {
                        $schema['maximum'] = (float) $descriptor['maximum'];
                    }
                    if (!empty($descriptor['properties']) && is_array($descriptor['properties'])) {
                        $props = [];
                        $req = [];
                        foreach ($descriptor['properties'] as $name => $nestedDescriptor) {
                            $key = trim((string) $name);
                            if ($key === '') {
                                continue;
                            }
                            $nested = $this->descriptorToSchema($nestedDescriptor);
                            if ($nested === []) {
                                continue;
                            }
                            $props[$key] = $nested;
                            $req[] = $key;
                        }
                        if ($props !== []) {
                            $schema['properties'] = $props;
                            $schema['required'] = $req;
                        }
                    }
                    if (array_key_exists('items', $descriptor)) {
                        $items = $this->descriptorToSchema($descriptor['items']);
                        if ($items === []) {
                            $items = ['type' => 'string'];
                        }
                        $schema['items'] = $items;
                    }
                    if (array_key_exists('additionalProperties', $descriptor)) {
                        $schema['additionalProperties'] = (bool) $descriptor['additionalProperties'];
                    }
                    return $schema;
                }

                $props = [];
                $req = [];
                foreach ($descriptor as $name => $nestedDescriptor) {
                    $key = trim((string) $name);
                    if ($key === '') {
                        continue;
                    }
                    $nested = $this->descriptorToSchema($nestedDescriptor);
                    if ($nested === []) {
                        continue;
                    }
                    $props[$key] = $nested;
                    $req[] = $key;
                }
                if ($props === []) {
                    return [];
                }
                return [
                    'type' => 'object',
                    'properties' => $props,
                    'required' => $req,
                    'additionalProperties' => false,
                ];
            }

            $first = reset($descriptor);
            $itemSchema = $this->descriptorToSchema($first);
            if ($itemSchema === []) {
                $itemSchema = ['type' => 'string'];
            }
            return [
                'type' => 'array',
                'items' => $itemSchema,
            ];
        }

        $descriptorText = trim((string) $descriptor);
        if ($descriptorText === '') {
            return ['type' => 'string'];
        }

        if (preg_match('/^\s*[0-9]+(?:\.[0-9]+)?\s*-\s*[0-9]+(?:\.[0-9]+)?\s*$/', $descriptorText) === 1) {
            $parts = preg_split('/\s*-\s*/', $descriptorText);
            $min = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 0.0;
            $max = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 1.0;
            return [
                'type' => 'number',
                'minimum' => $min,
                'maximum' => $max,
            ];
        }

        $enum = $this->parseEnumDescriptor($descriptorText);
        if (count($enum) >= 2) {
            return [
                'type' => 'string',
                'enum' => $enum,
            ];
        }

        $type = $this->mapSchemaType($descriptorText);
        if ($type === 'array') {
            return [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ];
        }
        return ['type' => $type];
    }

    /**
     * @return array<int, string>
     */
    private function parseEnumDescriptor(string $descriptor): array
    {
        if (!str_contains($descriptor, '|')) {
            return [];
        }
        $parts = explode('|', $descriptor);
        $enum = [];
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_.:\\-]{2,80}$/', $token)) {
                return [];
            }
            $enum[] = $token;
        }
        return array_values(array_unique($enum));
    }

    private function mapSchemaType($descriptor): string
    {
        if (is_array($descriptor)) {
            if (isset($descriptor['type'])) {
                return $this->mapSchemaType($descriptor['type']);
            }
            $first = reset($descriptor);
            return $this->mapSchemaType($first);
        }

        if (is_object($descriptor)) {
            return $this->mapSchemaType((array) $descriptor);
        }

        $descriptor = strtolower(trim((string) $descriptor));
        if ($descriptor === '') {
            return 'string';
        }

        $firstPart = trim((string) explode('|', $descriptor)[0]);
        if (str_contains($firstPart, 'number') || str_contains($firstPart, 'decimal')) {
            return 'number';
        }
        if (str_contains($firstPart, 'integer') || str_contains($firstPart, 'int')) {
            return 'integer';
        }
        if (str_contains($firstPart, 'bool')) {
            return 'boolean';
        }
        if (str_contains($firstPart, 'object') || str_contains($firstPart, 'json')) {
            return 'object';
        }
        if (str_contains($firstPart, 'array') || str_contains($firstPart, 'list')) {
            return 'array';
        }

        return 'string';
    }
}
