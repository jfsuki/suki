<?php
// app/Core/GeminiClient.php

namespace App\Core;

use RuntimeException;

final class GeminiClient
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $baseUrl = null)
    {
        $this->apiKey = trim((string) ($apiKey ?? getenv('GEMINI_API_KEY') ?? ''));
        $this->model = trim((string) ($model ?? getenv('GEMINI_MODEL') ?? 'gemini-2.5-flash-lite'));
        $this->baseUrl = rtrim((string) ($baseUrl ?? getenv('GEMINI_BASE_URL') ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');

        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY requerido.');
        }
    }

    public function generate(string $prompt, array $options = []): array
    {
        $url = $this->baseUrl . '/models/' . rawurlencode($this->model) . ':generateContent';

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.2,
                'maxOutputTokens' => $options['max_tokens'] ?? 800,
            ],
        ];

        $response = $this->request('POST', $url, $payload);
        $content = $response['data']['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return [
            'provider' => 'gemini',
            'model' => $this->model,
            'content' => $content,
            'raw' => $response,
        ];
    }

    private function request(string $method, string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar curl (Gemini).');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->apiKey,
        ];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $responseBody = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Error HTTP Gemini: ' . $err);
        }

        $decoded = json_decode($responseBody, true);
        $data = is_array($decoded) ? $decoded : ['raw' => $responseBody];
        if ($status < 200 || $status >= 300) {
            $message = $data['error']['message'] ?? $data['message'] ?? 'Error HTTP ' . $status;
            throw new RuntimeException($message);
        }

        return ['status' => $status, 'data' => $data];
    }
}
