<?php
// app/Core/GroqClient.php

namespace App\Core;

use RuntimeException;

final class GroqClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $baseUrl = null)
    {
        $this->apiKey = trim((string) ($apiKey ?? getenv('GROQ_API_KEY') ?? ''));
        $this->model = trim((string) ($model ?? getenv('GROQ_MODEL') ?? 'llama-3.1-8b-instant'));
        $this->baseUrl = rtrim((string) ($baseUrl ?? getenv('GROQ_BASE_URL') ?? 'https://api.groq.com/openai/v1/chat/completions'), '/');

        if ($this->apiKey === '') {
            throw new RuntimeException('GROQ_API_KEY requerido.');
        }
    }

    public function chat(array $messages, array $options = []): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? 800,
        ];

        $response = $this->request('POST', $this->baseUrl, $payload);
        $content = $response['data']['choices'][0]['message']['content'] ?? '';

        return [
            'provider' => 'groq',
            'model' => $this->model,
            'content' => $content,
            'raw' => $response,
        ];
    }

    private function request(string $method, string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar curl (Groq).');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
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
            throw new RuntimeException('Error HTTP Groq: ' . $err);
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
