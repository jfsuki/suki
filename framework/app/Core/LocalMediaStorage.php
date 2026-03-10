<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class LocalMediaStorage implements MediaStorageInterface
{
    private string $baseDir;
    private string $logicalPrefix;

    public function __construct(?string $baseDir = null, ?string $logicalPrefix = null)
    {
        $this->logicalPrefix = trim((string) ($logicalPrefix ?? getenv('MEDIA_STORAGE_PREFIX') ?: 'storage'), '/\\');

        $resolvedBaseDir = trim((string) ($baseDir ?? getenv('MEDIA_STORAGE_ROOT') ?: ''));
        if ($resolvedBaseDir === '') {
            $resolvedBaseDir = PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->logicalPrefix);
        }

        $this->baseDir = rtrim($resolvedBaseDir, '/\\');
    }

    /**
     * @return array<string, mixed>
     */
    public function writeStreamFromPath(string $sourcePath, string $relativePath): array
    {
        $sourcePath = trim($sourcePath);
        if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('MEDIA_SOURCE_NOT_READABLE');
        }

        $normalized = $this->normalizeRelativePath($relativePath);
        $absolutePath = $this->absolutePathForRelative($normalized);
        $this->ensureDirectory(dirname($absolutePath));

        $input = fopen($sourcePath, 'rb');
        if (!is_resource($input)) {
            throw new RuntimeException('MEDIA_SOURCE_STREAM_OPEN_FAILED');
        }

        $output = fopen($absolutePath, 'wb');
        if (!is_resource($output)) {
            fclose($input);
            throw new RuntimeException('MEDIA_TARGET_STREAM_OPEN_FAILED');
        }

        $bytesWritten = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);

        if ($bytesWritten === false) {
            @unlink($absolutePath);
            throw new RuntimeException('MEDIA_STREAM_COPY_FAILED');
        }

        return [
            'storage_path' => $this->logicalPath($normalized),
            'absolute_path' => $absolutePath,
            'file_size' => (int) $bytesWritten,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function writeContents(string $relativePath, string $contents): array
    {
        $normalized = $this->normalizeRelativePath($relativePath);
        $absolutePath = $this->absolutePathForRelative($normalized);
        $this->ensureDirectory(dirname($absolutePath));

        $bytesWritten = file_put_contents($absolutePath, $contents, LOCK_EX);
        if ($bytesWritten === false) {
            throw new RuntimeException('MEDIA_WRITE_FAILED');
        }

        return [
            'storage_path' => $this->logicalPath($normalized),
            'absolute_path' => $absolutePath,
            'file_size' => (int) $bytesWritten,
        ];
    }

    public function resolveAbsolutePath(string $storagePath): string
    {
        $storagePath = trim($storagePath);
        if ($storagePath === '') {
            throw new RuntimeException('MEDIA_STORAGE_PATH_REQUIRED');
        }

        $relative = $storagePath;
        $prefix = $this->logicalPrefix . '/';
        if (str_starts_with($relative, $prefix)) {
            $relative = substr($relative, strlen($prefix));
        }

        return $this->absolutePathForRelative($this->normalizeRelativePath($relative));
    }

    public function exists(string $storagePath): bool
    {
        try {
            $absolutePath = $this->resolveAbsolutePath($storagePath);
        } catch (\Throwable $e) {
            return false;
        }

        return is_file($absolutePath);
    }

    public function delete(string $storagePath): void
    {
        if (!$this->exists($storagePath)) {
            return;
        }

        $absolutePath = $this->resolveAbsolutePath($storagePath);
        @unlink($absolutePath);
    }

    private function logicalPath(string $relativePath): string
    {
        return $this->logicalPrefix . '/' . ltrim($relativePath, '/');
    }

    private function absolutePathForRelative(string $relativePath): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('MEDIA_DIRECTORY_CREATE_FAILED');
        }
    }

    private function normalizeRelativePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        $parts = explode('/', $relativePath);
        $normalized = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $normalized[] = $part;
        }

        if ($normalized === []) {
            throw new RuntimeException('MEDIA_RELATIVE_PATH_REQUIRED');
        }

        return implode('/', $normalized);
    }
}
