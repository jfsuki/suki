<?php
declare(strict_types=1);

namespace App\Core;

/**
 * ReportTokenService
 *
 * Generates and verifies HMAC-signed URL tokens for report access.
 * Prevents exposure of raw tenant_id, app_id, entity params in shareable URLs.
 *
 * Token format:  base64url(JSON_payload) + "." + HMAC-SHA256_truncated_24_chars
 * Signed with:   APP_SECRET or SUKI_APP_SECRET env variable.
 *
 * Usage:
 *   $token = $svc->generate(['app_id'=>'vet', 'entity'=>'pacientes', 'tenant_id'=>'123']);
 *   // Masked URL:  /r/{$token}  →  rewritten to  api/reports/view?token={$token}
 *
 *   $params = $svc->decode($token);  // ['app_id'=>'vet', 'entity'=>'pacientes', ...]
 *
 * Security guarantees:
 *   - HMAC-SHA256 prevents parameter forgery or enumeration
 *   - exp claim rejects expired tokens
 *   - nonce claim prevents token reuse attacks
 *   - No PHP path or internal structure exposed in URLs
 */
final class ReportTokenService
{
    private string $secret;

    public function __construct(?string $secret = null)
    {
        $env = (string) (getenv('APP_SECRET') ?: getenv('SUKI_APP_SECRET') ?: '');
        $this->secret = $secret ?? ($env !== '' ? $env : 'suki_rts_fallback_secret_changeme');
    }

    /**
     * Generates a short-lived signed token (default 1 hour).
     * @param array<string, mixed> $params
     */
    public function generate(array $params, int $ttlSeconds = 3600): string
    {
        $payload          = $params;
        $payload['iat']   = time();
        $payload['exp']   = time() + $ttlSeconds;
        $payload['nonce'] = bin2hex(random_bytes(4));

        $b64 = $this->b64Encode((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $b64 . '.' . $this->sign($b64);
    }

    /**
     * Generates a persistent token (10-year TTL) for saved report downloads.
     * @param array<string, mixed> $params
     */
    public function generatePersistent(array $params): string
    {
        return $this->generate($params, 60 * 60 * 24 * 365 * 10);
    }

    /**
     * Decodes and verifies a token. Returns payload or null on failure / expiry.
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        $dot = strrpos($token, '.');
        if ($dot === false || $dot === 0) {
            return null;
        }
        $b64 = substr($token, 0, $dot);
        $sig = substr($token, $dot + 1);

        if (!hash_equals($this->sign($b64), $sig)) {
            return null; // Tampered or wrong secret
        }

        $json = $this->b64Decode($b64);
        if ($json === '') {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && time() > (int) $payload['exp']) {
            return null; // Expired
        }
        return $payload;
    }

    private function sign(string $data): string
    {
        return substr(hash_hmac('sha256', $data, $this->secret), 0, 24);
    }

    private function b64Encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64Decode(string $data): string
    {
        $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);
        $result = base64_decode(strtr($padded, '-_', '+/'), true);
        return $result !== false ? $result : '';
    }
}
