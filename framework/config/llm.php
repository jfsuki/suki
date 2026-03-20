<?php
// framework/config/llm.php

$envFlag = static function (string $name, bool $default): bool {
    $raw = getenv($name);
    if ($raw === false) {
        return $default;
    }
    $value = strtolower(trim((string) $raw));
    if ($value === '') {
        return $default;
    }
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
};

$hasGroq = trim((string) getenv('GROQ_API_KEY')) !== '';
$hasGemini = trim((string) getenv('GEMINI_API_KEY')) !== '';
$hasOpenRouter = trim((string) getenv('OPENROUTER_API_KEY')) !== '';
$hasClaude = trim((string) getenv('CLAUDE_API_KEY')) !== '';

return [
    'providers' => [
        'groq' => [
            'enabled' => $envFlag('GROQ_ENABLED', $hasGroq),
            'class' => \App\Core\LLM\Providers\GroqProvider::class,
        ],
        'gemini' => [
            'enabled' => $envFlag('GEMINI_ENABLED', $hasGemini),
            'class' => \App\Core\LLM\Providers\GeminiProvider::class,
        ],
        'openrouter' => [
            'enabled' => $envFlag('OPENROUTER_ENABLED', $hasOpenRouter),
            'class' => \App\Core\LLM\Providers\OpenRouterProvider::class,
        ],
        'claude' => [
            'enabled' => $envFlag('CLAUDE_ENABLED', $hasClaude),
            'class' => \App\Core\LLM\Providers\ClaudeProvider::class,
        ],
    ],
    'models' => [
        'fast' => [
            'groq' => getenv('GROQ_MODEL') ?: 'llama-3.1-8b-instant',
            'gemini' => getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash-lite',
        ],
        'default' => [
            'groq' => getenv('GROQ_MODEL') ?: 'llama-3.1-8b-instant',
            'gemini' => getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash-lite',
        ],
        'fallback' => [
            'openrouter' => getenv('OPENROUTER_MODEL') ?: 'openrouter/free',
        ],
        'premium' => [
            'claude' => getenv('CLAUDE_MODEL') ?: 'claude-3-5-haiku-latest',
        ],
    ],
    'limits' => [
        'timeout' => 20,
        'max_tokens' => 600,
    ],
];
