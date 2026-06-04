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
        $model  = getenv('GROQ_MODEL') ?: ($this->config['model'] ?? null);
        $client = new GroqClient(null, $model ?: null);
        $result = $client->chat($messages, $params);
        $content = (string) ($result['content'] ?? '');

        // Groq is OpenAI-compatible — normalize tool_calls from choices[0].message.tool_calls
        $toolCalls = [];
        $msgRaw = $result['raw']['data']['choices'][0]['message'] ?? [];
        if (!empty($msgRaw['tool_calls']) && is_array($msgRaw['tool_calls'])) {
            foreach ($msgRaw['tool_calls'] as $tc) {
                $fn   = is_array($tc['function'] ?? null) ? (array) $tc['function'] : [];
                $name = (string) ($fn['name'] ?? '');
                $args = [];
                if (!empty($fn['arguments'])) {
                    $decoded = json_decode((string) $fn['arguments'], true);
                    if (is_array($decoded)) {
                        $args = $decoded;
                    }
                }
                if ($name !== '') {
                    $toolCalls[] = ['id' => (string) ($tc['id'] ?? ''), 'name' => $name, 'input' => $args];
                }
            }
        }

        return [
            'text'       => $content,
            'tool_calls' => $toolCalls,
            'usage'      => $result['raw']['data']['usage'] ?? [],
            'raw'        => $result,
        ];
    }
}
