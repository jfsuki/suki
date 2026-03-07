<?php
// framework/tests/chat_exec_security_common.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Database;
use App\Core\TableNamespace;

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $options
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function runChatMessageTurn(array $payload, array $options = []): array
{
    $request = [
        'route' => 'chat/message',
        'method' => 'POST',
        'payload' => $payload,
    ];
    if (isset($options['session']) && is_array($options['session'])) {
        $request['session'] = (array) $options['session'];
    }
    if (isset($options['env']) && is_array($options['env'])) {
        $request['env'] = (array) $options['env'];
    }
    if (isset($options['headers']) && is_array($options['headers'])) {
        $request['headers'] = (array) $options['headers'];
    }

    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $helper = __DIR__ . '/api_route_turn.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $raw = (string) shell_exec($cmd);
    $json = json_decode($raw, true);

    return [
        'raw' => trim($raw),
        'json' => is_array($json) ? $json : null,
    ];
}

function cleanupChatExecArtifacts(string $entity): void
{
    $projectRoot = dirname(__DIR__, 2) . '/project';
    @unlink($projectRoot . '/contracts/entities/' . $entity . '.entity.json');
    @unlink($projectRoot . '/contracts/forms/' . $entity . '.form.json');

    try {
        $pdo = Database::connection();
        $logicalTable = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_]/', '', $entity . 's')));
        if ($logicalTable === '') {
            return;
        }

        $projectCandidates = array_values(array_unique([
            TableNamespace::normalizedProjectId(),
            'default',
        ]));
        $tables = [$logicalTable];
        foreach ($projectCandidates as $projectId) {
            $tables[] = TableNamespace::resolve($logicalTable, $projectId);
        }
        $tables = array_values(array_unique(array_filter(array_map(
            static fn($name): string => strtolower(trim((string) $name)),
            $tables
        ))));

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        foreach ($tables as $tableName) {
            if ($tableName === '') {
                continue;
            }
            if ($driver === 'sqlite') {
                $pdo->exec('DROP TABLE IF EXISTS "' . $tableName . '"');
                continue;
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . $tableName . '`');
        }
    } catch (\Throwable $e) {
        // best-effort cleanup for tests
    }
}

function entityContractExists(string $entity): bool
{
    $path = dirname(__DIR__, 2) . '/project/contracts/entities/' . $entity . '.entity.json';
    return is_file($path);
}

function entityTableExists(string $entity): bool
{
    try {
        $pdo = Database::connection();
        $table = $entity . 's';
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: '';
        if ($safeTable === '') {
            return false;
        }

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare('SELECT name FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1');
            $stmt->execute([':type' => 'table', ':name' => $safeTable]);
            return $stmt->fetchColumn() !== false;
        }

        $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $safeTable]);
        return $stmt->fetchColumn() !== false;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * @return array<string,mixed>|null
 */
function latestTelemetryForSession(string $tenantId, string $sessionId): ?array
{
    $date = date('Y-m-d');
    $path = dirname(__DIR__, 2) . '/project/storage/tenants/' . $tenantId . '/telemetry/' . $date . '.log.jsonl';
    if (!is_file($path)) {
        return null;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || empty($lines)) {
        return null;
    }

    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $decoded = json_decode((string) $lines[$i], true);
        if (!is_array($decoded)) {
            continue;
        }
        if ((string) ($decoded['session_id'] ?? '') !== $sessionId) {
            continue;
        }
        return $decoded;
    }

    return null;
}
