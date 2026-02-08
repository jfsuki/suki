<?php
// app/Core/Response.php
namespace App\Core;

class Response
{
    public function json(string $status, string $message, array $data = []): string
    {
        header('Content-Type: application/json');

        return json_encode([
            'status'  => $status,
            'message' => $message,
            'data'    => $data
        ]);
    }
}
