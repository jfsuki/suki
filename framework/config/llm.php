<?php
// framework/config/llm.php

return [
    'providers' => [
        'groq' => [
            'enabled' => true,
            'class' => \App\Core\LLM\Providers\GroqProvider::class,
        ],
        'gemini' => [
            'enabled' => true,
            'class' => \App\Core\LLM\Providers\GeminiProvider::class,
        ],
        'openrouter' => [
            'enabled' => false,
            'class' => \App\Core\LLM\Providers\OpenRouterProvider::class,
        ],
        'claude' => [
            'enabled' => false,
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
