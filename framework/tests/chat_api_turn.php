<?php
// framework/tests/chat_api_turn.php
// Ejecuta un turno de chat usando el endpoint real project/public/api.php?route=chat/message

declare(strict_types=1);

$encoded = $argv[1] ?? '';
if ($encoded === '') {
    fwrite(STDERR, "Uso: php chat_api_turn.php <payload_base64>\n");
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

$_GET = ['route' => 'chat/message'];
$_POST = $payload;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

ob_start();
require dirname(__DIR__, 2) . '/project/public/api.php';
$output = (string) ob_get_clean();

echo $output;

