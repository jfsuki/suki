<?php
// app/Core/LogSanitizer.php

namespace App\Core;

final class LogSanitizer
{
    private const REDACTED = '[REDACTED]';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function sanitizeArray(array $payload): array
    {
        /** @var array<string, mixed> $sanitized */
        $sanitized = self::sanitizeValue($payload, null);
        return $sanitized;
    }

    public static function sanitizeString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $sanitized = $value;
        $sanitized = self::replacePattern(
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/is',
            self::REDACTED,
            $sanitized
        );
        $sanitized = self::replacePattern('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*\b/i', 'Bearer ' . self::REDACTED, $sanitized);
        $sanitized = self::replacePattern('/\bsk-[A-Za-z0-9_-]{12,}\b/i', self::REDACTED, $sanitized);
        $sanitized = self::replacePattern('/\bAIza[0-9A-Za-z\-_]{20,}\b/', self::REDACTED, $sanitized);

        $sanitized = self::replacePattern(
            '/([?&](?:token|access_token|api[_-]?key|secret|key|password)=)[^&\s]+/i',
            '$1' . self::REDACTED,
            $sanitized
        );
        $sanitized = self::replacePattern(
            '/((?:token|secret|password|api[_-]?key|webhook[_-]?secret)\s*[:=]\s*)([^\s,;]+)/i',
            '$1' . self::REDACTED,
            $sanitized
        );
        $sanitized = self::replacePattern(
            '/("(?:(?:x[_-]?api[_-]?key)|authorization|cookie|token|secret|password|api[_-]?key|webhook[_-]?secret|private[_-]?key|key)"\s*:\s*)".*?"/i',
            '$1"' . self::REDACTED . '"',
            $sanitized
        );

        return $sanitized;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sanitizeValue(mixed $value, ?string $parentKey): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? $key : null;
                if ($keyString !== null && self::isSensitiveKey($keyString)) {
                    $out[$key] = self::REDACTED;
                    continue;
                }
                $out[$key] = self::sanitizeValue($item, $keyString ?? $parentKey);
            }
            return $out;
        }

        if (is_string($value)) {
            if ($parentKey !== null && self::isSensitiveKey($parentKey)) {
                return self::REDACTED;
            }
            return self::sanitizeString($value);
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(trim($key));
        if ($normalized === '') {
            return false;
        }
        $normalized = str_replace(['-', '.', ' '], '_', $normalized);

        $exact = [
            'authorization',
            'cookie',
            'set_cookie',
            'x_api_key',
            'api_key',
            'apikey',
            'token',
            'access_token',
            'refresh_token',
            'id_token',
            'secret',
            'webhook_secret',
            'private_key',
            'key',
            'password',
            'passwd',
            'pwd',
            'client_secret',
        ];
        if (in_array($normalized, $exact, true)) {
            return true;
        }

        $fragments = [
            '_token',
            '_secret',
            '_password',
            '_api_key',
            '_apikey',
            '_private_key',
            '_cookie',
            '_authorization',
            '_key',
        ];
        foreach ($fragments as $fragment) {
            if (str_ends_with($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function replacePattern(string $pattern, string $replacement, string $value): string
    {
        $result = preg_replace($pattern, $replacement, $value);
        return is_string($result) ? $result : $value;
    }
}
