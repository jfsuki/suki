<?php
// framework/tests/chat_auth_turn.php
// Like chat_api_turn.php but also accepts auth session data as 2nd arg.
// Usage: php chat_auth_turn.php <payload_base64> [auth_base64]
//   payload_base64 : json_encode($payload) base64-encoded
//   auth_base64    : json_encode($auth_user_array) base64-encoded (optional)

declare(strict_types=1);

$encoded  = $argv[1] ?? '';
$authEnc  = $argv[2] ?? '';

if ($encoded === '') {
    fwrite(STDERR, "Uso: php chat_auth_turn.php <payload_base64> [auth_base64]\n");
    exit(1);
}

$decoded = base64_decode($encoded, true);
if ($decoded === false) {
    fwrite(STDERR, "Payload base64 invalido.\n");
    exit(1);
}

$payload = json_decode($decoded, true);
if (!is_array($payload)) {
    fwrite(STDERR, "Payload JSON invalido.\n");
    exit(1);
}

$_GET    = ['route' => 'chat/message'];
$_POST   = $payload;
$_SERVER['REQUEST_METHOD']     = 'POST';
$_SERVER['CONTENT_TYPE']       = 'application/json';
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
$_SERVER['REQUEST_URI']        = '/project/public/api.php?route=chat/message';
$_SERVER['HTTP_HOST']          = 'localhost';

// Inyectar sesión autenticada si se provee
if ($authEnc !== '') {
    $authDecoded = base64_decode($authEnc, true);
    if ($authDecoded !== false) {
        $authData = json_decode($authDecoded, true);
        if (is_array($authData) && !empty($authData)) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['auth_user'] = $authData;
        }
    }
}

ob_start();
require dirname(__DIR__, 2) . '/project/public/api.php';
$output = (string) ob_get_clean();

echo $output;
