<?php
// app/Core/LLM/Providers/GeminiProvider.php

namespace App\Core\LLM\Providers;

use App\Core\GeminiClient;

final class GeminiProvider
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function isAvailable(): bool
    {
        return (getenv('GEMINI_API_KEY') ?: '') !== '' && (getenv('GEMINI_ENABLED') ?: '1') !== '0';
    }

    public function sendChat(array $messages, array $params = []): array
    {
        $prompt = $this->messagesToPrompt($messages);
        $model  = getenv('GEMINI_MODEL') ?: ($this->config['model'] ?? null);
        $client = new GeminiClient(null, $model ?: null);
        $result = $client->generate($prompt, $params);
        $content = (string) ($result['content'] ?? '');
        // Gemini tool_call extraction: parts with functionCall key
        $toolCalls = [];
        $parts = $result['raw']['data']['candidates'][0]['content']['parts'] ?? [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $fc = is_array($part['functionCall'] ?? null) ? (array) $part['functionCall'] : null;
                if ($fc !== null && isset($fc['name'])) {
                    $toolCalls[] = ['id' => '', 'name' => (string) $fc['name'], 'input' => (array) ($fc['args'] ?? [])];
                }
            }
        }
        return [
            'text'       => $content,
            'tool_calls' => $toolCalls,
            'usage'      => $result['raw']['data']['usageMetadata'] ?? [],
            'raw'        => $result,
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
}
