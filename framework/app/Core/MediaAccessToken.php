<?php

declare(strict_types=1);

namespace App\Core;

final class MediaAccessToken
{
    public static function sign(array $payload, string $secret): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('MEDIA_ACCESS_TOKEN_SERIALIZATION_FAILED');
        }

        $signature = hash_hmac('sha256', $json, $secret, true);
        return self::base64UrlEncode($json) . '.' . self::base64UrlEncode($signature);
    }

    /**
     * @return array{ok: bool, payload: array<string, mixed>, reason: string}
     */
    public static function verify(string $token, string $secret): array
    {
        $parts = explode('.', trim($token), 3);
        if (count($parts) !== 2) {
            return ['ok' => false, 'payload' => [], 'reason' => 'malformed_token'];
        }

        $payloadJson = self::base64UrlDecode((string) ($parts[0] ?? ''));
        $signature = self::base64UrlDecode((string) ($parts[1] ?? ''));
        if (!is_string($payloadJson) || !is_string($signature)) {
            return ['ok' => false, 'payload' => [], 'reason' => 'decode_failed'];
        }

        $expected = hash_hmac('sha256', $payloadJson, $secret, true);
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'payload' => [], 'reason' => 'invalid_signature'];
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'payload' => [], 'reason' => 'invalid_payload'];
        }

        return ['ok' => true, 'payload' => $payload, 'reason' => 'token_valid'];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
