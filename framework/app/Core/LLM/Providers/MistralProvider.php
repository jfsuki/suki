<?php
// app/Core/LLM/Providers/MistralProvider.php

namespace App\Core\LLM\Providers;

use RuntimeException;

final class MistralProvider
{
    private array $config;
    public function __construct(array $config = []) { $this->config = $config; }

    public function sendChat(array $messages, array $params = []): array
    {
        $apiKey = getenv('MISTRAL_API_KEY') ?: '';
        $model = getenv('MISTRAL_MODEL') ?: ($this->config['model'] ?? 'mistral-small-latest');
        $baseUrl = (string) (getenv('MISTRAL_BASE_URL') ?: 'https://api.mistral.ai/v1/chat/completions');

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
        // Tool calling: convert Claude format → OpenAI format (Mistral is OpenAI-compatible)
        $rawTools = $params['tools'] ?? null;
        if (!empty($rawTools) && is_array($rawTools)) {
            $openAiTools = [];
            foreach ($rawTools as $tool) {
                $name   = (string) ($tool['name'] ?? '');
                $desc   = (string) ($tool['description'] ?? '');
                $schema = is_array($tool['input_schema'] ?? null) ? (array) $tool['input_schema'] : ['type' => 'object', 'properties' => []];
                if ($name === '') continue;
                $openAiTools[] = ['type' => 'function', 'function' => ['name' => $name, 'description' => $desc, 'parameters' => $schema]];
            }
            if ($openAiTools !== []) {
                $payload['tools'] = $openAiTools;
            }
        }

        $response = $this->request($baseUrl, $payload, [
            'Authorization: Bearer ' . $apiKey,
        ]);

        $message = $response['data']['choices'][0]['message'] ?? [];
        $content = (string) ($message['content'] ?? '');

        // Normalize tool_calls from OpenAI format
        $toolCalls = [];
        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $fn   = is_array($tc['function'] ?? null) ? (array) $tc['function'] : [];
                $name = (string) ($fn['name'] ?? '');
                $args = [];
                if (!empty($fn['arguments'])) {
                    $decoded = json_decode((string) $fn['arguments'], true);
                    if (is_array($decoded)) { $args = $decoded; }
                }
                if ($name !== '') {
                    $toolCalls[] = ['id' => (string) ($tc['id'] ?? ''), 'name' => $name, 'input' => $args];
                }
            }
        }

        return [
            'text'       => $content,
            'tool_calls' => $toolCalls,
            'usage'      => $response['data']['usage'] ?? [],
            'raw'        => $response,
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
