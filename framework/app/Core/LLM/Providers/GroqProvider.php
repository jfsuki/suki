<?php
// app/Core/LLM/Providers/GroqProvider.php

namespace App\Core\LLM\Providers;

use App\Core\GroqClient;
use RuntimeException;

final class GroqProvider
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function sendChat(array $messages, array $params = []): array
    {
        $client = new GroqClient();
        $result = $client->chat($messages, $params);
        $content = (string) ($result['content'] ?? '');
        return [
            'text' => $content,
            'usage' => $result['raw']['data']['usage'] ?? [],
            'raw' => $result,
        ];
    }
}
