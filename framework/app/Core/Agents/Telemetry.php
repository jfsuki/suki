<?php
// app/Core/Agents/Telemetry.php

namespace App\Core\Agents;

final class Telemetry
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
    }

    public function record(string $tenantId, array $payload): void
    {
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $dir = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/telemetry';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . date('Y-m-d') . '.log.jsonl';
        $payload['ts'] = $payload['ts'] ?? time();
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private function safe(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $value) ?? 'default';
        return trim($value, '_');
    }
}
