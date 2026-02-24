<?php
// framework/tests/api_route_turn.php
// Ejecuta una llamada puntual al endpoint real project/public/api.php?route=...

declare(strict_types=1);

$encoded = $argv[1] ?? '';
if ($encoded === '') {
    fwrite(STDERR, "Uso: php api_route_turn.php <request_base64>\n");
    exit(1);
}

$decoded = base64_decode($encoded, true);
if ($decoded === false) {
    fwrite(STDERR, "request_base64 invalido.\n");
    exit(1);
}

$request = json_decode($decoded, true);
if (!is_array($request)) {
    fwrite(STDERR, "request JSON invalido.\n");
    exit(1);
}

$route = trim((string) ($request['route'] ?? ''));
if ($route === '') {
    fwrite(STDERR, "route requerido.\n");
    exit(1);
}

$method = strtoupper(trim((string) ($request['method'] ?? 'GET')));
$payload = is_array($request['payload'] ?? null) ? (array) $request['payload'] : [];
$query = is_array($request['query'] ?? null) ? (array) $request['query'] : [];
$sessionData = is_array($request['session'] ?? null) ? (array) $request['session'] : [];
$headers = is_array($request['headers'] ?? null) ? (array) $request['headers'] : [];
$env = is_array($request['env'] ?? null) ? (array) $request['env'] : [];

foreach ($env as $key => $value) {
    if (!is_string($key) || $key === '') {
        continue;
    }
    putenv($key . '=' . (string) $value);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
foreach ($sessionData as $key => $value) {
    if (!is_string($key) || $key === '') {
        continue;
    }
    $_SESSION[$key] = $value;
}

$_GET = array_merge(['route' => $route], $query);
$_POST = $payload;
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_SERVER['REMOTE_ADDR'] = (string) ($request['remote_addr'] ?? '127.0.0.1');

foreach ($headers as $name => $value) {
    if (!is_string($name) || $name === '') {
        continue;
    }
    $serverName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $_SERVER[$serverName] = (string) $value;
}

ob_start();
require dirname(__DIR__, 2) . '/project/public/api.php';
$output = (string) ob_get_clean();

echo $output;

