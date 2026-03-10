<?php

declare(strict_types=1);

namespace App\Core;

interface MediaStorageInterface
{
    /**
     * @return array<string, mixed>
     */
    public function writeStreamFromPath(string $sourcePath, string $relativePath): array;

    /**
     * @return array<string, mixed>
     */
    public function writeContents(string $relativePath, string $contents): array;

    public function resolveAbsolutePath(string $storagePath): string;

    public function exists(string $storagePath): bool;

    public function delete(string $storagePath): void;
}
