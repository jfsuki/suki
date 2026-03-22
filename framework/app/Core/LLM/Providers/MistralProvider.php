<?php
// app/Core/LLM/Providers/MistralProvider.php

namespace App\Core\LLM\Providers;

use RuntimeException;

final class MistralProvider
{
    public function sendChat(array $messages, array $params = []): array
    {
        $apiKey = getenv('MISTRAL_API_KEY') ?: '';
        $model = getenv('MISTRAL_MODEL') ?: 'mistral-small-latest';
        $baseUrl = 'https://api.mistral.ai/v1/chat/completions';

        if ($apiKey === '') {
            throw new RuntimeException('MISTRAL_API_KEY requerido.');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $params['temperature'] ?? 0.2,
            'max_tokens' => $params['max_tokens'] ?? 600,
        ];

        if (!empty($params['strict_json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->request($baseUrl, $payload, [
            'Authorization: Bearer ' . $apiKey,
        ]);

        $content = $response['data']['choices'][0]['message']['content'] ?? '';
        return [
            'text' => $content,
            'usage' => $response['data']['usage'] ?? [],
            'raw' => $response,
        ];
    }

    private function request(string $url, array $payload, array $extraHeaders): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar curl (Mistral).');
        }
        $headers = array_merge([
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extraHeaders);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $responseBody = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Error HTTP Mistral: ' . $err);
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
