<?php

declare(strict_types=1);

namespace App\Core;

final class MediaEventLogger
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? PROJECT_ROOT;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $event, string $tenantId, array $context = []): void
    {
        $tenantId = trim($tenantId) !== '' ? trim($tenantId) : 'default';
        $directory = $this->projectRoot
            . '/storage/tenants/'
            . $this->safe($tenantId)
            . '/media_events';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        if (!is_dir($directory)) {
            return;
        }

        $payload = array_merge([
            'ts' => date('c'),
            'event' => trim($event),
            'tenant_id' => $tenantId,
        ], $context);
        $payload = LogSanitizer::sanitizeArray($payload);

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        @file_put_contents($directory . '/' . date('Y-m-d') . '.log.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function safe(string $value): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $value) ?? $value;
        $clean = trim($clean, '_');
        return $clean !== '' ? $clean : 'default';
    }
}
