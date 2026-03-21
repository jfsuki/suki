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

    final class NetworkErrorProvider
    {
        public function __construct(array $config = [])
        {
        }

        public function sendChat(array $messages, array $params = []): array
        {
            throw new \RuntimeException('Error HTTP OpenRouter: Could not resolve host: api.openrouter.ai');
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
    use App\Core\LLM\Providers\DeepSeekProvider;
    use App\Core\LLM\Providers\OpenRouterProvider;

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
            'openrouter' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\QuotaProvider::class],
            'deepseek' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\SuccessProvider::class],
        ],
        'models' => [],
        'routing' => ['primary' => 'openrouter', 'secondary' => 'deepseek'],
        'limits' => ['timeout' => 20, 'max_tokens' => 200],
    ]);

    try {
        $resetCircuit();
        $result = $routerQuota->chat($capsule, [
            'mode' => 'builder',
            'provider_mode' => 'openrouter',
            'tenant_id' => 'default',
            'project_id' => 'unit_llm_health',
            'session_id' => 'llm_health_quota',
        ]);
        $statuses = is_array($result['provider_statuses'] ?? null) ? (array) $result['provider_statuses'] : [];
        if ((string) ($result['provider'] ?? '') !== 'deepseek') {
            $failures[] = 'El fallback por cuota debe terminar en deepseek.';
        }
        if (($statuses['openrouter'] ?? '') !== 'quota_exhausted') {
            $failures[] = 'OpenRouter debe clasificarse como quota_exhausted.';
        }
        if (($statuses['deepseek'] ?? '') !== 'healthy') {
            $failures[] = 'DeepSeek debe clasificarse como healthy cuando responde.';
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

    $routerNetwork = new LLMRouter([
        'providers' => [
            'openrouter' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\NetworkErrorProvider::class],
            'deepseek' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\SuccessProvider::class],
        ],
        'models' => [],
        'routing' => ['primary' => 'openrouter', 'secondary' => 'deepseek'],
        'limits' => ['timeout' => 20, 'max_tokens' => 200],
    ]);
    try {
        $resetCircuit();
        $result = $routerNetwork->chat($capsule, [
            'mode' => 'builder',
            'provider_mode' => 'openrouter',
            'tenant_id' => 'default',
            'project_id' => 'unit_llm_health',
            'session_id' => 'llm_health_network',
        ]);
        $statuses = is_array($result['provider_statuses'] ?? null) ? (array) $result['provider_statuses'] : [];
        if (($statuses['openrouter'] ?? '') !== 'network_error') {
            $failures[] = 'OpenRouter debe clasificarse como network_error.';
        }
        if ((string) ($result['provider'] ?? '') !== 'deepseek') {
            $failures[] = 'El fallback por network_error debe terminar en deepseek.';
        }
    } catch (\Throwable $e) {
        $failures[] = 'Escenario network_error fallo: ' . $e->getMessage();
    }

    $previousMode = getenv('LLM_ROUTER_MODE');
    putenv('LLM_ROUTER_MODE=openrouter');
    $routerEnvMode = new LLMRouter([
        'providers' => [
            'openrouter' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\SuccessProvider::class],
            'deepseek' => ['enabled' => true, 'class' => \App\Tests\LLM\Health\QuotaProvider::class],
        ],
        'models' => [],
        'routing' => ['primary' => 'openrouter', 'secondary' => 'deepseek'],
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

    $openRouterReflection = new ReflectionClass(OpenRouterProvider::class);
    $openRouterNormalize = $openRouterReflection->getMethod('normalizeBaseUrl');
    $openRouterNormalize->setAccessible(true);
    $openRouterHeaders = $openRouterReflection->getMethod('buildHeaders');
    $openRouterHeaders->setAccessible(true);
    $openRouterProvider = $openRouterReflection->newInstanceWithoutConstructor();
    if ($openRouterNormalize->invoke($openRouterProvider, 'https://openrouter.ai/api/v1') !== 'https://openrouter.ai/api/v1/chat/completions') {
        $failures[] = 'OpenRouter debe normalizar /api/v1 a /chat/completions.';
    }
    if ($openRouterNormalize->invoke($openRouterProvider, 'https://openrouter.ai/api/v1/chat/completions') !== 'https://openrouter.ai/api/v1/chat/completions') {
        $failures[] = 'OpenRouter no debe duplicar /chat/completions.';
    }
    $headers = (array) $openRouterHeaders->invoke($openRouterProvider, 'sk-or-test');
    if (!in_array('HTTP-Referer: ' . (getenv('OPENROUTER_REFERER') ?: 'http://localhost'), $headers, true)) {
        $failures[] = 'OpenRouter debe mantener HTTP-Referer.';
    }
    if (!in_array('X-Title: ' . (getenv('OPENROUTER_TITLE') ?: 'suki'), $headers, true)) {
        $failures[] = 'OpenRouter debe mantener X-Title.';
    }

    $deepSeekReflection = new ReflectionClass(DeepSeekProvider::class);
    $deepSeekNormalize = $deepSeekReflection->getMethod('normalizeBaseUrl');
    $deepSeekNormalize->setAccessible(true);
    $deepSeekProvider = $deepSeekReflection->newInstanceWithoutConstructor();
    if ($deepSeekNormalize->invoke($deepSeekProvider, 'https://api.deepseek.com/v1') !== 'https://api.deepseek.com/v1/chat/completions') {
        $failures[] = 'DeepSeek debe normalizar /v1 a /chat/completions.';
    }

    $ok = $failures === [];
    echo json_encode([
        'ok' => $ok,
        'failures' => $failures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit($ok ? 0 : 1);
}
