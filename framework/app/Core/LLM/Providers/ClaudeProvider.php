<?php
// app/Core/LLM/Providers/ClaudeProvider.php

namespace App\Core\LLM\Providers;

use RuntimeException;

final class ClaudeProvider
{
    public function sendChat(array $messages, array $params = []): array
    {
        $apiKey = getenv('CLAUDE_API_KEY') ?: '';
        $model = getenv('CLAUDE_MODEL') ?: 'claude-3-5-haiku-latest';
        $baseUrl = getenv('CLAUDE_BASE_URL') ?: 'https://api.anthropic.com/v1/messages';

        if ($apiKey === '') {
            throw new RuntimeException('CLAUDE_API_KEY requerido.');
        }

        $prompt = $this->messagesToPrompt($messages);
        $payload = [
            'model' => $model,
            'max_tokens' => $params['max_tokens'] ?? 600,
            'temperature' => $params['temperature'] ?? 0.2,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $response = $this->request($baseUrl, $payload, [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ]);

        $content = $response['data']['content'][0]['text'] ?? '';
        return [
            'text' => $content,
            'usage' => $response['data']['usage'] ?? [],
            'raw' => $response,
        ];
    }

    private function messagesToPrompt(array $messages): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $parts[] = strtoupper($role) . ': ' . $content;
        }
        return implode("\n", $parts);
    }

    private function request(string $url, array $payload, array $extraHeaders): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar curl (Claude).');
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
            throw new RuntimeException('Error HTTP Claude: ' . $err);
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
