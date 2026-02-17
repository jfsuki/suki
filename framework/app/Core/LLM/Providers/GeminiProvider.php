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

    public function sendChat(array $messages, array $params = []): array
    {
        $prompt = $this->messagesToPrompt($messages);
        $client = new GeminiClient();
        $result = $client->generate($prompt, $params);
        $content = (string) ($result['content'] ?? '');
        return [
            'text' => $content,
            'usage' => $result['raw']['data']['usageMetadata'] ?? [],
            'raw' => $result,
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
