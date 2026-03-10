<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class MediaStorageFactory
{
    public static function make(): MediaStorageInterface
    {
        $driver = strtolower(trim((string) (getenv('MEDIA_STORAGE_DRIVER') ?: 'local')));
        if ($driver === '' || $driver === 'local') {
            return new LocalMediaStorage();
        }

        throw new RuntimeException('MEDIA_STORAGE_DRIVER_NOT_SUPPORTED:' . $driver);
    }
}
