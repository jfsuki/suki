<?php
// app/Core/IntegrationHttpClient.php

namespace App\Core;

use RuntimeException;

final class IntegrationHttpClient
{
    private string $baseUrl;
    private int $timeout;

    public function __construct(string $baseUrl, int $timeout = 20)
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            throw new RuntimeException('Base URL requerida para integracion.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = max(5, $timeout);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, array $headers = [], array $payload = [], bool $allowErrors = false): array
    {
        $url = $this->buildUrl($path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar cliente HTTP.');
        }

        $method = strtoupper(trim($method));
        $finalHeaders = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            if ($k === '') {
                continue;
            }
            $finalHeaders[] = $k . ': ' . $v;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $finalHeaders[] = 'Content-Type: application/json';
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body !== false ? $body : '{}');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);

        $responseBody = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Error HTTP: ' . $err);
        }

        $decoded = json_decode((string) $responseBody, true);
        $data = is_array($decoded) ? $decoded : ['raw' => $responseBody];

        if (!$allowErrors && ($status < 200 || $status >= 300)) {
            $message = (string) ($data['message'] ?? $data['error'] ?? ('Error HTTP ' . $status));
            throw new RuntimeException($message);
        }

        return [
            'status' => $status,
            'data' => $data,
        ];
    }

    private function buildUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $this->baseUrl;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return $this->baseUrl . $path;
    }
}

