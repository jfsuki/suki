<?php
// framework/tests/webhook_security_common.php

declare(strict_types=1);

/**
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function runWebhookSecurityRoute(array $request): array
{
    $helper = __DIR__ . '/api_route_turn.php';
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $raw = (string) shell_exec($cmd);
    $json = json_decode($raw, true);

    return [
        'raw' => trim($raw),
        'json' => is_array($json) ? $json : null,
    ];
}
