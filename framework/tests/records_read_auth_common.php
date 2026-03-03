<?php
// framework/tests/records_read_auth_common.php

declare(strict_types=1);

/**
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function recordsReadRunApiRoute(array $request): array
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

function recordsReadBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function recordsReadSignToken(array $payload, string $secret): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar payload de token.');
    }
    $signature = hash_hmac('sha256', $json, $secret, true);
    return recordsReadBase64UrlEncode($json) . '.' . recordsReadBase64UrlEncode($signature);
}

function recordsReadResolveEntityName(): string
{
    $entitiesDir = dirname(__DIR__, 2) . '/project/contracts/entities';
    $files = glob($entitiesDir . '/*.json') ?: [];
    sort($files);

    foreach ($files as $file) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            continue;
        }
        $name = trim((string) ($decoded['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }

    throw new RuntimeException('No se encontro una entidad valida en project/contracts/entities.');
}

function recordsReadEnsureEntityMigrated(string $entityName): void
{
    $projectRoot = dirname(__DIR__, 2) . '/project';
    $frameworkRoot = dirname(__DIR__);

    require_once $projectRoot . '/config/env_loader.php';
    require_once $frameworkRoot . '/vendor/autoload.php';
    require_once $frameworkRoot . '/app/autoload.php';

    if (!defined('PROJECT_ROOT')) {
        define('PROJECT_ROOT', $projectRoot);
    }
    if (!defined('FRAMEWORK_ROOT')) {
        define('FRAMEWORK_ROOT', $frameworkRoot);
    }

    $registry = new \App\Core\EntityRegistry($frameworkRoot, $projectRoot);
    $entity = $registry->get($entityName);
    $migrator = new \App\Core\EntityMigrator($registry);
    $migrator->migrateEntity($entity, true);
}

