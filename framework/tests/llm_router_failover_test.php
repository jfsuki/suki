<?php
// framework/tests/llm_router_failover_test.php

declare(strict_types=1);

namespace App\Tests\LLM\Providers {
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
                    'prompt_tokens' => 12,
                    'completion_tokens' => 6,
                    'total_tokens' => 18,
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

    $tmpDir = __DIR__ . '/tmp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0775, true);
    }
    $quotaDb = $tmpDir . '/llm_router_quota.sqlite';
    if (is_file($quotaDb)) {
        unlink($quotaDb);
    }

    putenv('SECURITY_STATE_DB_PATH=' . $quotaDb);
    putenv('LLM_SESSION_QUOTA_ENABLED=1');
    putenv('LLM_SESSION_QUOTA_WINDOW_SECONDS=60');
    putenv('LLM_MAX_REQUESTS_PER_SESSION=20');

    $config = [
        'providers' => [
            'groq' => ['enabled' => true, 'class' => \App\Tests\LLM\Providers\QuotaProvider::class],
            'gemini' => ['enabled' => true, 'class' => \App\Tests\LLM\Providers\SuccessProvider::class],
        ],
        'models' => [],
        'limits' => ['timeout' => 20, 'max_tokens' => 200],
    ];

    $capsule = [
        'policy' => [
            'requires_strict_json' => true,
            'latency_budget_ms' => 900,
            'max_output_tokens' => 120,
        ],
        'user_message' => 'clasifica este negocio',
    ];

    $failures = [];

    $router = new LLMRouter($config);
    try {
        $result = $router->chat($capsule, [
            'mode' => 'auto',
            'tenant_id' => 'default',
            'project_id' => 'unit_llm',
            'session_id' => 'llm_failover_sess',
        ]);

        if ((string) ($result['provider'] ?? '') !== 'gemini') {
            $failures[] = 'Failover por cuota no uso proveedor secundario gemini.';
        }
        if ((int) ($result['failover_count'] ?? 0) < 1) {
            $failures[] = 'Failover count debe ser >= 1 cuando primer proveedor falla.';
        }
        $errors = is_array($result['provider_errors'] ?? null) ? (array) $result['provider_errors'] : [];
        if (!isset($errors['groq'])) {
            $failures[] = 'Errores por proveedor no registran fallo inicial.';
        }
    } catch (\Throwable $e) {
        $failures[] = 'Fallo inesperado en escenario de failover: ' . $e->getMessage();
    }

    // Modo gemini forzado: si gemini falla por quota, debe ir a siguiente proveedor habilitado.
    $geminiQuotaConfig = [
        'providers' => [
            'gemini' => ['enabled' => true, 'class' => \App\Tests\LLM\Providers\QuotaProvider::class],
            'openrouter' => ['enabled' => true, 'class' => \App\Tests\LLM\Providers\SuccessProvider::class],
        ],
        'models' => [],
        'limits' => ['timeout' => 20, 'max_tokens' => 200],
    ];
    $routerGeminiForced = new LLMRouter($geminiQuotaConfig);
    try {
        $forced = $routerGeminiForced->chat($capsule, [
            'mode' => 'gemini',
            'tenant_id' => 'default',
            'project_id' => 'unit_llm',
            'session_id' => 'llm_forced_gemini_sess',
        ]);
        if ((string) ($forced['provider'] ?? '') !== 'openrouter') {
            $failures[] = 'Modo gemini no hizo failover a proveedor secundario tras quota.';
        }
    } catch (\Throwable $e) {
        $failures[] = 'Fallo en modo gemini con failover: ' . $e->getMessage();
    }

    // Quota por sesion: segundo request en misma sesion debe bloquear.
    putenv('LLM_MAX_REQUESTS_PER_SESSION=1');
    $quotaConfig = [
        'providers' => [
            'openrouter' => ['enabled' => true, 'class' => \App\Tests\LLM\Providers\SuccessProvider::class],
        ],
        'models' => [],
        'limits' => ['timeout' => 20, 'max_tokens' => 200],
    ];
    $routerQuota = new LLMRouter($quotaConfig);
    try {
        $routerQuota->chat($capsule, [
            'mode' => 'auto',
            'tenant_id' => 'default',
            'project_id' => 'unit_llm',
            'session_id' => 'llm_quota_sess',
        ]);
        $routerQuota->chat($capsule, [
            'mode' => 'auto',
            'tenant_id' => 'default',
            'project_id' => 'unit_llm',
            'session_id' => 'llm_quota_sess',
        ]);
        $failures[] = 'Quota por sesion debio bloquear el segundo request.';
    } catch (\Throwable $e) {
        $msg = strtolower(trim($e->getMessage()));
        if (!str_contains($msg, 'quota de solicitudes llm por sesion')) {
            $failures[] = 'Error de quota inesperado: ' . $e->getMessage();
        }
    }

    $ok = empty($failures);
    echo json_encode([
        'ok' => $ok,
        'db_path' => $quotaDb,
        'failures' => $failures,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit($ok ? 0 : 1);
}
