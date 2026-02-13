<?php
// app/Core/AlanubeClient.php

namespace App\Core;

use RuntimeException;

final class AlanubeClient
{
    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            throw new RuntimeException('Base URL requerida.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = trim($token);
        if ($this->token === '') {
            throw new RuntimeException('Token requerido.');
        }
    }

    public function testConnection(): array
    {
        return $this->request('GET', '/company', [], true);
    }

    public function emitDocument(string $endpoint, array $payload): array
    {
        return $this->request('POST', $endpoint, $payload);
    }

    public function getDocument(string $endpoint, string $externalId): array
    {
        $endpoint = rtrim($endpoint, '/');
        return $this->request('GET', $endpoint . '/' . rawurlencode($externalId));
    }

    public function cancelDocument(string $endpoint, string $externalId, array $payload = []): array
    {
        $endpoint = rtrim($endpoint, '/');
        return $this->request('POST', $endpoint . '/' . rawurlencode($externalId) . '/cancel', $payload);
    }

    private function request(string $method, string $path, array $payload = [], bool $allowErrors = false): array
    {
        $url = $this->buildUrl($path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar curl.');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
        ];

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $responseBody = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Error HTTP: ' . $err);
        }

        $decoded = json_decode($responseBody, true);
        $payloadData = is_array($decoded) ? $decoded : ['raw' => $responseBody];

        if (!$allowErrors && ($status < 200 || $status >= 300)) {
            $message = $payloadData['message'] ?? $payloadData['error'] ?? 'Error HTTP ' . $status;
            throw new RuntimeException($message);
        }

        return [
            'status' => $status,
            'data' => $payloadData,
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
