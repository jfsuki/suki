<?php
// app/Core/Response.php
namespace App\Core;

class Response
{
    public function json(string $status, string $message, array $data = []): string
    {
        header('Content-Type: application/json; charset=utf-8');

        return json_encode([
            'status'  => $status,
            'message' => $message,
            'data'    => $data
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"status":"error","message":"JSON encode error","data":{}}';
    }
}
