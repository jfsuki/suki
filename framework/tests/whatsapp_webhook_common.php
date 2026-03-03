<?php
// framework/tests/whatsapp_webhook_common.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';
$envLoader = dirname(__DIR__, 2) . '/project/config/env_loader.php';
if (is_file($envLoader)) {
    require_once $envLoader;
}

use App\Core\Database;

/**
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function runWhatsAppWebhookRoute(array $request): array
{
    $helper = __DIR__ . '/api_route_turn.php';
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $raw = (string) shell_exec($cmd);
    $json = json_decode($raw, true);
    return [
        'raw' => trim($raw),
        'json' => is_array($json) ? $json : null,
    ];
}

/**
 * @return array<string,mixed>
 */
function whatsappWebhookBasePayload(string $messageId, string $from, string $text): array
{
    return [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'messages' => [
                                [
                                    'id' => $messageId,
                                    'from' => $from,
                                    'text' => ['body' => $text],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function whatsappTestSignature(string $secret): string
{
    // api_route_turn injects payload via $_POST; php://input is empty in tests.
    return 'sha256=' . hash_hmac('sha256', '', $secret);
}

function whatsappQueueCountByMessageId(string $messageId): int
{
    $db = Database::connection();
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM jobs_queue WHERE job_type = :job_type AND payload_json LIKE :needle"
    );
    $stmt->execute([
        ':job_type' => 'whatsapp.inbound',
        ':needle' => '%' . $messageId . '%',
    ]);
    return (int) $stmt->fetchColumn();
}

/**
 * @return array<string,mixed>|null
 */
function whatsappLatestQueueRowByMessageId(string $messageId): ?array
{
    $db = Database::connection();
    $stmt = $db->prepare(
        "SELECT id, tenant_id, job_type, status, payload_json, created_at
         FROM jobs_queue
         WHERE job_type = :job_type AND payload_json LIKE :needle
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([
        ':job_type' => 'whatsapp.inbound',
        ':needle' => '%' . $messageId . '%',
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
