<?php

declare(strict_types=1);

$encoded = $argv[1] ?? '';
if ($encoded === '') {
    fwrite(STDERR, "Uso: php entity_search_chat_turn.php <payload_base64>\n");
    exit(1);
}

$decoded = base64_decode($encoded, true);
if ($decoded === false) {
    fwrite(STDERR, "payload_base64 invalido.\n");
    exit(1);
}

$request = json_decode($decoded, true);
if (!is_array($request)) {
    fwrite(STDERR, "payload JSON invalido.\n");
    exit(1);
}

$projectRoot = trim((string) ($request['project_root'] ?? ''));
if ($projectRoot === '') {
    fwrite(STDERR, "project_root requerido.\n");
    exit(1);
}

$env = is_array($request['env'] ?? null) ? (array) $request['env'] : [];
foreach ($env as $key => $value) {
    if (!is_string($key) || $key === '') {
        continue;
    }
    putenv($key . '=' . (string) $value);
}

$frameworkRoot = dirname(__DIR__);
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);
define('PROJECT_ROOT', $projectRoot);

require FRAMEWORK_ROOT . '/vendor/autoload.php';
require FRAMEWORK_ROOT . '/app/autoload.php';

$payload = is_array($request['payload'] ?? null) ? (array) $request['payload'] : [];
$result = (new \App\Core\ChatAgent())->handle($payload);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
