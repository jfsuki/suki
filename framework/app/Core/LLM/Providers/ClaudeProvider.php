<?php
// app/Core/LLM/Providers/ClaudeProvider.php

namespace App\Core\LLM\Providers;

use RuntimeException;

/**
 * ClaudeProvider - Anthropic Messages API Implementation
 * 
 * Supports native tool-calling and multi-turn conversations.
 */
final class ClaudeProvider
{
    public function sendChat(array $messages, array $params = []): array
    {
        $apiKey = getenv('CLAUDE_API_KEY') ?: '';
        $model = getenv('CLAUDE_MODEL') ?: 'claude-3-5-sonnet-latest';
        $baseUrl = getenv('CLAUDE_BASE_URL') ?: 'https://api.anthropic.com/v1/messages';

        if ($apiKey === '') {
            throw new RuntimeException('CLAUDE_API_KEY requerido.');
        }

        $systemPrompt = '';
        $formattedMessages = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            if ($role === 'system') {
                $systemPrompt .= ($systemPrompt ? "\n" : "") . $content;
            } else {
                $formattedMessages[] = [
                    'role' => ($role === 'assistant') ? 'assistant' : 'user',
                    'content' => $content
                ];
            }
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $params['max_tokens'] ?? 1024,
            'temperature' => $params['temperature'] ?? 0.2,
            'messages' => $formattedMessages,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        if (!empty($params['tools'])) {
            $payload['tools'] = $params['tools'];
            if (!empty($params['tool_choice'])) {
                $payload['tool_choice'] = $params['tool_choice'];
            }
        }

        $response = $this->request($baseUrl, $payload, [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ]);

        $data = $response['data'];
        $text = '';
        $toolCalls = [];

        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if ($block['type'] === 'text') {
                    $text .= $block['text'];
                } elseif ($block['type'] === 'tool_use') {
                    $toolCalls[] = [
                        'id' => $block['id'],
                        'name' => $block['name'],
                        'input' => $block['input']
                    ];
                }
            }
        }

        return [
            'text' => $text,
            'tool_calls' => $toolCalls,
            'usage' => $data['usage'] ?? [],
            'stop_reason' => $data['stop_reason'] ?? '',
            'raw' => $data,
        ];
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
            throw new RuntimeException('[Claude API] ' . $message);
        }
        return ['status' => $status, 'data' => $data];
    }
}

