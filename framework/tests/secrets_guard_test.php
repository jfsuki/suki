<?php
// framework/tests/secrets_guard_test.php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$initialCwd = getcwd();
if (!is_string($initialCwd) || $initialCwd === '') {
    $initialCwd = $repoRoot;
}

chdir($repoRoot);
$tracked = [];
$gitExit = 0;
exec('git ls-files', $tracked, $gitExit);
chdir($initialCwd);

$failures = [];
if ($gitExit !== 0) {
    $failures[] = 'No se pudo listar archivos trackeados con git ls-files.';
}

$ignoreRegex = '#(^|/)\.env\.example$#i';
$directPatterns = [
    [
        'id' => 'google_api_key',
        'regex' => '/AIza[0-9A-Za-z\-_]{20,}/',
        'message' => 'Patron de Google API key detectado',
    ],
    [
        'id' => 'sk_token',
        'regex' => '/\bsk-(?:live|test|proj|or|ant)-[A-Za-z0-9_-]{12,}\b/i',
        'message' => 'Patron de token tipo sk-* detectado',
    ],
    [
        'id' => 'private_key_block',
        'regex' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        'message' => 'Bloque de private key detectado',
    ],
];

$sensitiveEnvKeys = array_flip([
    'GEMINI_API_KEY',
    'OPENROUTER_API_KEY',
    'GROQ_API_KEY',
    'CLAUDE_API_KEY',
    'DEEPSEEK_API_KEY',
    'QDRANT_API_KEY',
    'TELEGRAM_BOT_TOKEN',
    'TELEGRAM_WEBHOOK_SECRET',
    'WHATSAPP_APP_SECRET',
    'WHATSAPP_CLOUD_TOKEN',
    'WHATSAPP_VERIFY_TOKEN',
]);

foreach ($tracked as $rawPath) {
    $relPath = str_replace('\\', '/', trim((string) $rawPath));
    if ($relPath === '' || preg_match($ignoreRegex, $relPath) === 1) {
        continue;
    }

    $absPath = $repoRoot . '/' . $relPath;
    if (!is_file($absPath)) {
        continue;
    }

    $content = file_get_contents($absPath);
    if (!is_string($content) || $content === '') {
        continue;
    }

    // Skip binary files.
    if (str_contains(substr($content, 0, 4096), "\0")) {
        continue;
    }

    foreach ($directPatterns as $pattern) {
        if (preg_match($pattern['regex'], $content, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }
        $offset = (int) ($matches[0][1] ?? 0);
        $line = 1 + substr_count(substr($content, 0, $offset), "\n");
        $failures[] = $relPath . ':' . $line . ' ' . $pattern['message'];
    }

    if (preg_match_all('/^\s*([A-Z0-9_]+)\s*=\s*(.+?)\s*$/m', $content, $envMatches, PREG_SET_ORDER) !== false) {
        foreach ($envMatches as $envMatch) {
            $key = trim((string) ($envMatch[1] ?? ''));
            if ($key === '' || !isset($sensitiveEnvKeys[$key])) {
                continue;
            }
            $value = normalizeEnvValue((string) ($envMatch[2] ?? ''));
            if ($value === '' || isPlaceholderValue($value)) {
                continue;
            }

            $lineText = (string) ($envMatch[0] ?? '');
            $needle = $lineText !== '' ? $lineText : ($key . '=');
            $pos = strpos($content, $needle);
            $line = $pos !== false ? (1 + substr_count(substr($content, 0, $pos), "\n")) : 1;
            $failures[] = $relPath . ':' . $line . ' Variable sensible con valor no-placeholder: ' . $key;
        }
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

function normalizeEnvValue(string $value): string
{
    $value = preg_replace('/\s+#.*$/', '', $value);
    $value = trim((string) $value);
    $value = trim($value, "\"'");
    return trim($value);
}

function isPlaceholderValue(string $value): bool
{
    if ($value === '') {
        return true;
    }
    $lower = strtolower($value);
    if (in_array($lower, ['null', 'none', 'empty', 'todo'], true)) {
        return true;
    }
    if (preg_match('/^(your_|tu_|changeme|change_me|replace_|placeholder|example|demo_|sample_)/i', $value) === 1) {
        return true;
    }
    if (str_starts_with($value, '<') && str_ends_with($value, '>')) {
        return true;
    }
    return false;
}
