<?php

namespace App\Core\Contracts;

final class ContractCache
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $workspaceRoot = dirname(__DIR__, 3);
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : $workspaceRoot . '/project');
    }

    public function get(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        [$key] = $this->buildKey($path);

        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $success = false;
            $cached = apcu_fetch($key, $success);
            if ($success && is_array($cached)) {
                return $cached;
            }
        }

        $cachePath = $this->cachePath($key);
        if (!is_file($cachePath)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($cachePath), true);
        if (!is_array($payload) || ($payload['key'] ?? '') !== $key) {
            return null;
        }

        return is_array($payload['data'] ?? null) ? $payload['data'] : null;
    }

    public function put(string $path, array $data): void
    {
        if (!is_file($path)) {
            return;
        }

        [$key, $mtime, $size] = $this->buildKey($path);

        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            @apcu_store($key, $data, 300);
        }

        $cachePath = $this->cachePath($key);
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = [
            'key' => $key,
            'mtime' => $mtime,
            'size' => $size,
            'data' => $data,
        ];
        file_put_contents(
            $cachePath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function buildKey(string $path): array
    {
        $real = realpath($path) ?: $path;
        $mtime = filemtime($path) ?: 0;
        $size = filesize($path) ?: 0;
        $key = 'suki_contract_' . sha1($real . '|' . $mtime . '|' . $size);
        return [$key, $mtime, $size];
    }

    private function cachePath(string $key): string
    {
        return $this->projectRoot . '/storage/cache/contracts/' . $key . '.json';
    }
}
