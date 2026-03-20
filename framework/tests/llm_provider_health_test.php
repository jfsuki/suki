<?php

declare(strict_types=1);

namespace App\Tests\LLM\Health {
    final class QuotaProvider
    {
        public function __construct(array $config = [])
        {
        }

        public function sendChat(array $messages, array $params = []): array
        {
            throw new \RuntimeException('HTTP 429 quota exceeded for provider');
        }
    }

    final class InvalidConfigProvider
    {
        public function __construct(array $config = [])
        {
        }

        public function sendChat(array $messages, array $params = []): array
        {
            throw new \RuntimeException('User not found.');
        }
    }

    final class SuccessProvider
    {
        public function __construct(array $config = [])
        {
        }

        public function sendChat(array $messages, array $params = []): array
        {
            return [
                'text' => '{"status":"MATCHED","confidence":0.91}',
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                    'total_tokens' => 15,
                ],
                'raw' => ['ok' => true],
            ];
        }
    }
}

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../app/autoload.php';

    use App\Core\LLM\LLMRouter;

    $failures = [];
    $resetCircuit = static function (): void {
        $reflection = new ReflectionClass(LLMRouter::class);
        $property = $reflection->getProperty('circuit');
        $property->setAccessible(true);
        $property->setValue(null, []);
    };

    $capsule = [
        'policy' => [
            'requires_strict_json' => true,
            'latency_budget_ms' => 900,
            'max_output_tokens' => 120,
        ],
        'prompt_contract' => [
            'OUTPUT_FORMAT' => [
                'status' => 'MATCHED|NEEDS_CLARIFICATION',
                'confidence' => '0.0-1.0',
            ],
        ],
        'user_message' => 'clasifica este negocio',
    ];

    $routerQuota = new LLMRouter([
        'providers' => [
            'gemini' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\QuotaProvider::class],
            'openrouter' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\SuccessProvider::class],
        ],
        'models' => [],
        'limits' => ['timeout' => 20, 'max_tokens' => 200],
    ]);

    try {
        $resetCircuit();
        $result = $routerQuota->chat($capsule, [
            'mode' => 'builder',
            'tenant_id' => 'default',
            'project_id' => 'unit_llm_health',
            'session_id' => 'llm_health_quota',
        ]);
        $statuses = is_array($result['provider_statuses'] ?? null) ? (array) $result['provider_statuses'] : [];
        if ((string) ($result['provider'] ?? '') !== 'openrouter') {
            $failures[] = 'El fallback por cuota debe terminar en openrouter.';
        }
        if (($statuses['gemini'] ?? '') !== 'quota_exhausted') {
            $failures[] = 'Gemini debe clasificarse como quota_exhausted.';
        }
        if (($statuses['openrouter'] ?? '') !== 'healthy') {
            $failures[] = 'OpenRouter debe clasificarse como healthy cuando responde.';
        }
    } catch (\Throwable $e) {
        $failures[] = 'Escenario quota->healthy fallo: ' . $e->getMessage();
    }

    $routerInvalid = new LLMRouter([
        'providers' => [
            'openrouter' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\InvalidConfigProvider::class],
        ],
        'models' => [],
        'limits' => ['timeout' => 20, 'max_tokens' => 200],
    ]);

    try {
        $resetCircuit();
        $routerInvalid->chat($capsule, [
            'mode' => 'builder',
            'tenant_id' => 'default',
            'project_id' => 'unit_llm_health',
            'session_id' => 'llm_health_invalid',
        ]);
        $failures[] = 'Escenario invalid_config debio fallar.';
    } catch (\Throwable $e) {
        $message = (string) $e->getMessage();
        if (!str_contains($message, 'provider_statuses=')) {
            $failures[] = 'El error final debe incluir provider_statuses.';
        }
        if (!str_contains($message, '"openrouter":"invalid_config"')) {
            $failures[] = 'OpenRouter debe clasificarse como invalid_config.';
        }
    }

    $previousMode = getenv('LLM_ROUTER_MODE');
    putenv('LLM_ROUTER_MODE=openrouter');
    $routerEnvMode = new LLMRouter([
        'providers' => [
            'gemini' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\QuotaProvider::class],
            'openrouter' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\SuccessProvider::class],
        ],
        'models' => [],
        'limits' => ['timeout' => 20, 'max_tokens' => 200],
    ]);
    try {
        $resetCircuit();
        $result = $routerEnvMode->chat($capsule, [
            'mode' => 'builder',
            'tenant_id' => 'default',
            'project_id' => 'unit_llm_health',
            'session_id' => 'llm_health_env_mode',
        ]);
        if ((string) ($result['provider'] ?? '') !== 'openrouter') {
            $failures[] = 'LLM_ROUTER_MODE=openrouter no debe ser ocultado por mode=builder.';
        }
    } catch (\Throwable $e) {
        $failures[] = 'Provider mode por env fallo: ' . $e->getMessage();
    }
    if ($previousMode === false) {
        putenv('LLM_ROUTER_MODE');
    } else {
        putenv('LLM_ROUTER_MODE=' . $previousMode);
    }

    $previousOpenRouterKey = getenv('OPENROUTER_API_KEY');
    $previousOpenRouterEnabled = getenv('OPENROUTER_ENABLED');
    putenv('OPENROUTER_API_KEY=dummy_openrouter_key');
    putenv('OPENROUTER_ENABLED=0');
    $config = require __DIR__ . '/../config/llm.php';
    $openRouterEnabled = !empty($config['providers']['openrouter']['enabled']);
    if ($openRouterEnabled) {
        $failures[] = 'OPENROUTER_ENABLED=0 debe desactivar openrouter aunque exista key.';
    }
    if ($previousOpenRouterKey === false) {
        putenv('OPENROUTER_API_KEY');
    } else {
        putenv('OPENROUTER_API_KEY=' . $previousOpenRouterKey);
    }
    if ($previousOpenRouterEnabled === false) {
        putenv('OPENROUTER_ENABLED');
    } else {
        putenv('OPENROUTER_ENABLED=' . $previousOpenRouterEnabled);
    }

    $ok = $failures === [];
    echo json_encode([
        'ok' => $ok,
        'failures' => $failures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit($ok ? 0 : 1);
}
