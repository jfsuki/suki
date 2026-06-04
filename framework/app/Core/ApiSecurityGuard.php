<?php
// app/Core/ApiSecurityGuard.php

namespace App\Core;

final class ApiSecurityGuard
{
    private ?SecurityStateRepository $securityRepo;

    public function __construct(?SecurityStateRepository $securityRepo = null)
    {
        $this->securityRepo = $securityRepo;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function enforce(
        string $route,
        string $method,
        array $server,
        array $session,
        array $payload,
        string $storageDir
    ): array {
        $route = trim($route);
        $method = strtoupper(trim($method));
        $isMutation = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if ($this->requiresAuth($route, $method) && empty($session['auth_user'])) {
            return ['ok' => false, 'code' => 401, 'message' => 'Debes iniciar sesion para usar este endpoint.'];
        }

        if ($isMutation && $this->requiresCsrf($route)) {
            $sessionCsrf = trim((string) ($session['csrf_token'] ?? ''));
            $headerCsrf = trim((string) ($server['HTTP_X_CSRF_TOKEN'] ?? ''));
            $bodyCsrf = trim((string) ($payload['csrf_token'] ?? ''));
            $token = $headerCsrf !== '' ? $headerCsrf : $bodyCsrf;
            if ($sessionCsrf === '' || $token === '' || !hash_equals($sessionCsrf, $token)) {
                return ['ok' => false, 'code' => 419, 'message' => 'CSRF token invalido o ausente.'];
            }
        }

        $limit = $this->rateLimitPerMinute($route);
        if ($limit > 0) {
            $clientKey = $this->clientKey($route, $session, $server);
            $bucket = $this->consumeRateLimit($clientKey, $limit, $storageDir);
            if (!$bucket['ok']) {
                return [
                    'ok' => false,
                    'code' => 429,
                    'message' => 'Rate limit excedido. Intenta de nuevo en unos segundos.',
                    'retry_after' => $bucket['retry_after'],
                ];
            }
        }

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function consumeRateLimit(string $key, int $limitPerMinute, string $storageDir): array
    {
        $repo = $this->securityRepository($storageDir);
        if ($repo !== null) {
            try {
                return $repo->consumeRateLimit($key, $limitPerMinute, 60);
            } catch (\Throwable $e) {
                // fallback below
            }
        }

        $file = rtrim($storageDir, '/\\') . '/api_rate_limit.json';
        $now = time();
        $window = 60;

        $data = [];
        if (is_file($file)) {
            $raw = file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $bucket = is_array($data[$key] ?? null) ? (array) $data[$key] : ['window_start' => $now, 'count' => 0];
        $windowStart = (int) ($bucket['window_start'] ?? $now);
        $count = (int) ($bucket['count'] ?? 0);
        if (($now - $windowStart) >= $window) {
            $windowStart = $now;
            $count = 0;
        }
        $count++;
        $bucket = ['window_start' => $windowStart, 'count' => $count];
        $data[$key] = $bucket;

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($count > $limitPerMinute) {
            $retry = max(1, $window - ($now - $windowStart));
            return ['ok' => false, 'retry_after' => $retry];
        }

        return ['ok' => true, 'remaining' => max(0, $limitPerMinute - $count)];
    }

    private function requiresAuth(string $route, string $method): bool
    {
        if ($route === '' || str_starts_with($route, 'channels/')) {
            return false;
        }
        // chat/* no requiere auth (tenant viene del payload verificado internamente)
        if (str_starts_with($route, 'chat/')) {
            return false;
        }
        $public = [
            'auth/login',
            'auth/register',
            'auth/request_code',
            'auth/verify_code',
            'auth/tenant-register',
            'auth/tenant-verify-otp',
            'auth/me',
            'auth/logout',
            'health',
            'integrations/alanube/webhook',
            'channels/telegram/webhook',
            'channels/whatsapp/webhook',
        ];
        if (in_array($route, $public, true)) {
            return false;
        }
        // Prefijos que requieren auth en CUALQUIER método HTTP (incluido GET)
        $protectedAllMethods = [
            'registry/',
            'command',
            'auth/users',
        ];
        foreach ($protectedAllMethods as $prefix) {
            if (str_starts_with($route, $prefix) || $route === rtrim($prefix, '/')) {
                return true;
            }
        }
        // El resto solo protege mutaciones
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }
        $protectedMutation = [
            'entity/',
            'import/',
            'integrations/',
            'workflow/',
            'records/',
        ];
        foreach ($protectedMutation as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function requiresCsrf(string $route): bool
    {
        // CSRF activo por defecto — opt-out solo con API_CSRF_ENFORCE=0 explícito
        $enforce = trim((string) (getenv('API_CSRF_ENFORCE') ?: '1'));
        if ($enforce === '0') {
            return false;
        }
        if (str_starts_with($route, 'chat/') || str_starts_with($route, 'channels/')) {
            return false;
        }
        // Webhooks y auth inicial no necesitan CSRF
        $csrfSkip = [
            'channels/telegram/webhook',
            'channels/whatsapp/webhook',
            'integrations/alanube/webhook',
            'auth/login',
            'auth/register',
            'auth/request_code',
            'auth/verify_code',
            'auth/tenant-register',
            'auth/tenant-verify-otp',
        ];
        return !in_array($route, $csrfSkip, true);
    }

    private function rateLimitPerMinute(string $route): int
    {
        if ($route === 'chat/message') {
            return (int) (getenv('API_RATE_LIMIT_CHAT_PER_MIN') ?: 90);
        }
        if ($route === 'channels/telegram/webhook' || $route === 'channels/whatsapp/webhook') {
            return (int) (getenv('API_RATE_LIMIT_CHANNEL_PER_MIN') ?: 120);
        }
        return (int) (getenv('API_RATE_LIMIT_DEFAULT_PER_MIN') ?: 180);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $server
     */
    private function clientKey(string $route, array $session, array $server): string
    {
        $user = is_array($session['auth_user'] ?? null) ? (array) $session['auth_user'] : [];
        $userId = trim((string) ($user['id'] ?? ''));
        $tenant = trim((string) ($user['tenant_id'] ?? 'default'));
        if ($userId !== '') {
            return $tenant . '::' . $userId . '::' . $route;
        }
        $ip = trim((string) ($server['REMOTE_ADDR'] ?? 'unknown'));
        return $tenant . '::' . $ip . '::' . $route;
    }

    private function securityRepository(string $storageDir): ?SecurityStateRepository
    {
        if ($this->securityRepo !== null) {
            return $this->securityRepo;
        }
        try {
            $dbPath = rtrim($storageDir, '/\\') . '/security_state.sqlite';
            $this->securityRepo = new SecurityStateRepository($dbPath);
            return $this->securityRepo;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
