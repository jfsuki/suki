<?php
// framework/config/llm.php
// Lee toda la configuración desde llm_providers.json + variables de entorno.
// Cero strings hardcodeados aquí: los modelos, clases y cascade viven en el JSON.

$jsonPath = dirname(__DIR__) . '/data/llm_providers.json';
$jsonCfg  = [];
if (is_file($jsonPath)) {
    $decoded = json_decode((string) file_get_contents($jsonPath), true);
    if (is_array($decoded)) {
        $jsonCfg = $decoded;
    }
}

$envFlag = static function (string $name, bool $default): bool {
    $raw = getenv($name);
    if ($raw === false) { return $default; }
    $value = strtolower(trim((string) $raw));
    if ($value === '') { return $default; }
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
};

// Construir providers desde JSON + env (API keys y enable flags)
$providers = [];
foreach ((array) ($jsonCfg['providers'] ?? []) as $name => $def) {
    $name      = (string) $name;
    $envKey    = (string) ($def['env_key']    ?? '');
    $envEnable = (string) ($def['env_enable'] ?? '');
    $hasKey    = $envKey !== '' && trim((string) getenv($envKey)) !== '';
    $enabled   = $envEnable !== '' ? $envFlag($envEnable, $hasKey) : $hasKey;

    // Model: env override > JSON default
    $modelEnv     = strtoupper($name) . '_MODEL';
    $modelDefault = (string) ($def['model'] ?? '');
    $model        = trim((string) (getenv($modelEnv) ?: $modelDefault));

    $providers[$name] = [
        'enabled' => $enabled,
        'class'   => (string) ($def['class'] ?? ''),
        'model'   => $model,
    ];
}

// Cascade: env override > JSON > fallback implícito de los providers del JSON
$cascadeEnv = trim((string) (getenv('LLM_CASCADE') ?: ''));
if ($cascadeEnv !== '') {
    $cascade = array_values(array_filter(array_map('trim', explode(',', $cascadeEnv)), static fn(string $p): bool => $p !== ''));
} else {
    $cascade = is_array($jsonCfg['cascade'] ?? null) ? array_values((array) $jsonCfg['cascade']) : array_keys($providers);
}

return [
    'providers' => $providers,
    'routing'   => [
        'primary'   => strtolower(trim((string) (getenv('LLM_PRIMARY_PROVIDER')   ?: ($jsonCfg['primary']   ?? 'openrouter')))),
        'secondary' => strtolower(trim((string) (getenv('LLM_SECONDARY_PROVIDER') ?: ($jsonCfg['secondary'] ?? 'gemini')))),
        'cascade'   => $cascade,
    ],
    'limits'    => [
        'timeout'    => (int) (getenv('LLM_TIMEOUT')    ?: ($jsonCfg['limits']['timeout']    ?? 20)),
        'max_tokens' => (int) (getenv('LLM_MAX_TOKENS') ?: ($jsonCfg['limits']['max_tokens'] ?? 600)),
    ],
];
