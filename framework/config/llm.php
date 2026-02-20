<?php
// framework/config/llm.php

$hasGroq = trim((string) getenv('GROQ_API_KEY')) !== '';
$hasGemini = trim((string) getenv('GEMINI_API_KEY')) !== '';
$hasOpenRouter = trim((string) getenv('OPENROUTER_API_KEY')) !== '';
$hasClaude = trim((string) getenv('CLAUDE_API_KEY')) !== '';

return [
    'providers' => [
        'groq' => [
            'enabled' => $hasGroq,
            'class' => \App\Core\LLM\Providers\GroqProvider::class,
        ],
        'gemini' => [
            'enabled' => $hasGemini,
            'class' => \App\Core\LLM\Providers\GeminiProvider::class,
        ],
        'openrouter' => [
            'enabled' => $hasOpenRouter,
            'class' => \App\Core\LLM\Providers\OpenRouterProvider::class,
        ],
        'claude' => [
            'enabled' => $hasClaude,
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
