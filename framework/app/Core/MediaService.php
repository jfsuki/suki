<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class MediaService
{
    /** @var array<int, string> */
    private const ENTITY_TYPES = ['product', 'purchase', 'invoice', 'sale', 'customer', 'supplier', 'general'];

    /** @var array<string, string> */
    private const FILE_TYPE_BY_EXTENSION = [
        'jpg' => 'image',
        'jpeg' => 'image',
        'png' => 'image',
        'webp' => 'image',
        'pdf' => 'pdf',
        'xml' => 'xml',
        'csv' => 'spreadsheet',
        'xlsx' => 'spreadsheet',
    ];

    /** @var array<string, string> */
    private const DEFAULT_MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'xml' => 'application/xml',
        'csv' => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private MediaRepository $repository;
    private MediaStorageInterface $storage;
    private AuditLogger $auditLogger;
    private MediaEventLogger $eventLogger;

    public function __construct(
        ?MediaRepository $repository = null,
        ?MediaStorageInterface $storage = null,
        ?AuditLogger $auditLogger = null,
        ?MediaEventLogger $eventLogger = null
    ) {
        $this->repository = $repository ?? new MediaRepository();
        $this->storage = $storage ?? MediaStorageFactory::make();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->eventLogger = $eventLogger ?? new MediaEventLogger();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upload(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullable($payload['app_id'] ?? $payload['project_id'] ?? null);
        $entityType = $this->entityType($payload['entity_type'] ?? null);
        $entityId = $this->requireString($payload['entity_id'] ?? null, 'entity_id');
        $userId = $this->userId($payload['uploaded_by_user_id'] ?? $payload['user_id'] ?? RoleContext::getUserId());
        $source = $this->resolveSource($payload);
        $extension = $this->resolveExtension($source);
        $fileType = self::FILE_TYPE_BY_EXTENSION[$extension] ?? null;
        if ($fileType === null) {
            throw new RuntimeException('MEDIA_EXTENSION_NOT_ALLOWED');
        }

        if (($source['file_size'] ?? 0) > $this->maxSizeBytes()) {
            throw new RuntimeException('MEDIA_MAX_SIZE_EXCEEDED');
        }

        $createdAt = date('Y-m-d H:i:s');
        $originalName = $this->sanitizeFilename((string) $source['original_name'], $extension);
        $mimeType = $this->mimeType((string) $source['source_path'], (string) $source['mime_type'], $extension);
        $relativeDir = $this->relativeDir($tenantId, $entityType, $entityId);
        $relativePath = $relativeDir . '/' . $this->uniqueStem($originalName) . '.' . $extension;
        $stored = $this->storage->writeStreamFromPath((string) $source['source_path'], $relativePath);

        $metadata = $this->metadata($payload['metadata'] ?? $payload['metadata_json'] ?? []);
        $metadata['original_name'] = $originalName;
        $metadata['original_extension'] = $extension;
        $metadata['original_mime_type'] = $mimeType;
        $metadata['variants'] = [
            'original' => [
                'status' => 'ready',
                'storage_path' => (string) ($stored['storage_path'] ?? ''),
                'mime_type' => $mimeType,
                'file_size' => (int) ($stored['file_size'] ?? 0),
                'extension' => $extension,
            ],
            'optimized' => ['status' => $fileType === 'image' ? 'pending' : 'not_applicable'],
            'thumbnail' => ['status' => $fileType === 'image' ? ($this->thumbAsync() ? 'pending' : 'processing') : 'not_applicable'],
        ];
        $metadata['hooks'] = $this->hooks($metadata['hooks'] ?? [], $fileType, $entityType);

        $record = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'file_type' => $fileType,
            'storage_path' => (string) ($stored['storage_path'] ?? ''),
            'mime_type' => $mimeType,
            'file_size' => (int) ($stored['file_size'] ?? 0),
            'uploaded_by_user_id' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'metadata' => $metadata,
        ];
        MediaContractValidator::validateFile($record);
        $saved = $this->repository->insertFile($record);

        if ($fileType === 'image') {
            $saved = $this->postProcessImage($saved);
        }

        $saved = $this->decorate($saved);
        $this->auditLogger->log('media_upload', 'media_file', $saved['id'] ?? null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'file_type' => $fileType,
        ]);
        $this->eventLogger->log('upload', $tenantId, [
            'app_id' => $appId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'media_id' => (string) ($saved['id'] ?? ''),
        ]);

        return $saved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(string $tenantId, string $entityType, string $entityId, ?string $appId = null, int $limit = 50, int $offset = 0): array
    {
        $items = $this->repository->listFiles(
            $this->requireString($tenantId, 'tenant_id'),
            [
                'app_id' => $this->nullable($appId),
                'entity_type' => $this->entityType($entityType),
                'entity_id' => $this->requireString($entityId, 'entity_id'),
            ],
            $limit,
            $offset
        );

        return array_map(fn(array $item): array => $this->decorate($item), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $tenantId, string $mediaId, ?string $appId = null): array
    {
        return $this->decorate($this->loadMedia($tenantId, $mediaId, $appId));
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $tenantId, string $mediaId, ?string $appId = null, ?string $userId = null): array
    {
        $media = $this->loadMedia($tenantId, $mediaId, $appId);
        foreach ($this->storagePaths($media) as $storagePath) {
            $this->storage->delete($storagePath);
        }
        $deleted = $this->repository->deleteFile((string) $media['tenant_id'], (string) $media['id']);
        if (!is_array($deleted)) {
            throw new RuntimeException('MEDIA_NOT_FOUND');
        }
        $summary = $this->decorate($deleted, false);
        $actorId = $this->userId($userId ?? ($media['uploaded_by_user_id'] ?? null));
        $this->auditLogger->log('media_delete', 'media_file', $media['id'] ?? null, [
            'tenant_id' => $tenantId,
            'app_id' => $media['app_id'] ?? null,
            'entity_type' => $media['entity_type'] ?? '',
            'entity_id' => $media['entity_id'] ?? '',
            'user_id' => $actorId,
        ]);
        $this->eventLogger->log('delete', $tenantId, [
            'app_id' => $media['app_id'] ?? null,
            'entity_type' => $media['entity_type'] ?? '',
            'entity_id' => $media['entity_id'] ?? '',
            'user_id' => $actorId,
            'media_id' => (string) ($media['id'] ?? ''),
        ]);

        return ['deleted' => true, 'media' => $summary];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateThumbnail(string $tenantId, string $mediaId, ?string $appId = null): array
    {
        $media = $this->loadMedia($tenantId, $mediaId, $appId);
        if ((string) ($media['file_type'] ?? '') !== 'image') {
            throw new RuntimeException('MEDIA_NOT_IMAGE');
        }
        if (!$this->gdAvailable()) {
            throw new RuntimeException('MEDIA_THUMBNAIL_UNAVAILABLE');
        }

        return $this->decorate($this->generateVariant($media, 'thumbnail', $this->thumbMaxSide()));
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveAccess(string $tenantId, string $mediaId, string $variant = 'original', ?string $appId = null, ?string $userId = null): array
    {
        $media = $this->loadMedia($tenantId, $mediaId, $appId);
        $variant = $this->variant($variant);
        if ((string) ($media['file_type'] ?? '') === 'image' && $variant !== 'original') {
            $entry = $this->variantDescriptorOrNull($media, $variant);
            if ($entry === null) {
                $limit = $variant === 'thumbnail' ? $this->thumbMaxSide() : $this->optimizedMaxWidth();
                $media = $this->generateVariant($media, $variant, $limit);
            }
        }

        $entry = $this->variantDescriptor($media, $variant);
        $absolutePath = $this->storage->resolveAbsolutePath((string) ($entry['storage_path'] ?? ''));
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('MEDIA_FILE_NOT_READABLE');
        }

        $actorId = $this->userId($userId ?? RoleContext::getUserId());
        $this->auditLogger->log('media_access', 'media_file', $media['id'] ?? null, [
            'tenant_id' => $tenantId,
            'app_id' => $media['app_id'] ?? null,
            'entity_type' => $media['entity_type'] ?? '',
            'entity_id' => $media['entity_id'] ?? '',
            'user_id' => $actorId,
            'variant' => $variant,
        ]);
        $this->eventLogger->log('access', $tenantId, [
            'app_id' => $media['app_id'] ?? null,
            'entity_type' => $media['entity_type'] ?? '',
            'entity_id' => $media['entity_id'] ?? '',
            'user_id' => $actorId,
            'media_id' => (string) ($media['id'] ?? ''),
            'variant' => $variant,
        ]);

        return [
            'media_id' => (string) ($media['id'] ?? ''),
            'tenant_id' => (string) ($media['tenant_id'] ?? ''),
            'app_id' => $media['app_id'] ?? null,
            'entity_type' => (string) ($media['entity_type'] ?? ''),
            'entity_id' => (string) ($media['entity_id'] ?? ''),
            'variant' => $variant,
            'storage_path' => (string) ($entry['storage_path'] ?? ''),
            'absolute_path' => $absolutePath,
            'mime_type' => (string) ($entry['mime_type'] ?? ($media['mime_type'] ?? 'application/octet-stream')),
            'file_size' => max(0, (int) ($entry['file_size'] ?? (filesize($absolutePath) ?: 0))),
            'file_name' => $this->downloadName($media, $variant),
        ];
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function decorate(array $media, bool $includeAccess = true): array
    {
        $metadata = $this->metadata($media['metadata'] ?? []);
        $media['metadata'] = $metadata;
        $media['original_name'] = (string) ($metadata['original_name'] ?? basename((string) ($media['storage_path'] ?? 'file')));
        $media['variants'] = is_array($metadata['variants'] ?? null) ? (array) $metadata['variants'] : [];
        $media['hooks'] = is_array($metadata['hooks'] ?? null) ? (array) $metadata['hooks'] : [];
        if ($includeAccess) {
            $media['access'] = $this->accessDescriptors($media);
        }
        return $media;
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function postProcessImage(array $media): array
    {
        $metadata = $this->metadata($media['metadata'] ?? []);
        if (!$this->gdAvailable()) {
            $metadata['variants']['optimized'] = ['status' => 'unavailable', 'reason' => 'gd_missing'];
            $metadata['variants']['thumbnail'] = ['status' => 'unavailable', 'reason' => 'gd_missing'];
            return $this->persistMetadata($media, $metadata);
        }

        try {
            $media = $this->generateVariant($media, 'optimized', $this->optimizedMaxWidth());
            if ($this->thumbAsync()) {
                $metadata = $this->metadata($media['metadata'] ?? []);
                $metadata['variants']['thumbnail']['status'] = 'pending';
                $metadata['variants']['thumbnail']['queued_at'] = date('c');
                $media = $this->persistMetadata($media, $metadata);
            } else {
                $media = $this->generateVariant($media, 'thumbnail', $this->thumbMaxSide());
            }
        } catch (Throwable $e) {
            $metadata = $this->metadata($media['metadata'] ?? []);
            $metadata['variants']['optimized'] = ['status' => 'failed', 'reason' => trim((string) $e->getMessage())];
            if (($metadata['variants']['thumbnail']['status'] ?? '') !== 'ready') {
                $metadata['variants']['thumbnail']['status'] = $this->thumbAsync() ? 'pending' : 'failed';
                if (!$this->thumbAsync()) {
                    $metadata['variants']['thumbnail']['reason'] = trim((string) $e->getMessage());
                }
            }
            $media = $this->persistMetadata($media, $metadata);
        }

        return $media;
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function generateVariant(array $media, string $variant, int $limit): array
    {
        if (!$this->gdAvailable()) {
            throw new RuntimeException('MEDIA_THUMBNAIL_UNAVAILABLE');
        }

        $variant = $this->variant($variant);
        $sourcePath = $this->storage->resolveAbsolutePath((string) ($media['storage_path'] ?? ''));
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('MEDIA_SOURCE_NOT_READABLE');
        }

        [$image, $info] = $this->imageResource($sourcePath);
        $size = $this->targetSize((int) ($info['width'] ?? 1), (int) ($info['height'] ?? 1), max(1, $limit), $variant);
        $target = imagecreatetruecolor($size['width'], $size['height']);
        if (!is_resource($target) && !($target instanceof \GdImage)) {
            $this->destroyImage($image);
            throw new RuntimeException('MEDIA_IMAGE_CREATE_FAILED');
        }

        $ext = $this->variantExtension((string) ($media['metadata']['original_extension'] ?? 'jpg'));
        if (in_array($ext, ['png', 'webp'], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        } else {
            imagefilledrectangle($target, 0, 0, $size['width'], $size['height'], imagecolorallocate($target, 255, 255, 255));
        }

        imagecopyresampled($target, $image, 0, 0, 0, 0, $size['width'], $size['height'], (int) $info['width'], (int) $info['height']);
        $tmpPath = tempnam(sys_get_temp_dir(), 'suki_media_');
        if ($tmpPath === false) {
            $this->destroyImage($image);
            $this->destroyImage($target);
            throw new RuntimeException('MEDIA_TEMP_FILE_CREATE_FAILED');
        }

        try {
            $this->saveImage($target, $tmpPath, $ext);
            $stored = $this->storage->writeStreamFromPath($tmpPath, $this->variantPath($media, $variant, $ext));
            $metadata = $this->metadata($media['metadata'] ?? []);
            $metadata['variants'][$variant] = [
                'status' => 'ready',
                'storage_path' => (string) ($stored['storage_path'] ?? ''),
                'mime_type' => self::DEFAULT_MIME_BY_EXTENSION[$ext] ?? 'image/jpeg',
                'file_size' => max(0, (int) ($stored['file_size'] ?? 0)),
                'width' => $size['width'],
                'height' => $size['height'],
                'generated_at' => date('c'),
                'extension' => $ext,
            ];
            return $this->persistMetadata($media, $metadata);
        } finally {
            @unlink($tmpPath);
            $this->destroyImage($image);
            $this->destroyImage($target);
        }
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function persistMetadata(array $media, array $metadata): array
    {
        $record = [
            'id' => $media['id'] ?? '',
            'tenant_id' => $media['tenant_id'] ?? '',
            'app_id' => $media['app_id'] ?? null,
            'entity_type' => $media['entity_type'] ?? '',
            'entity_id' => $media['entity_id'] ?? '',
            'file_type' => $media['file_type'] ?? '',
            'storage_path' => $media['storage_path'] ?? '',
            'mime_type' => $media['mime_type'] ?? '',
            'file_size' => $media['file_size'] ?? 0,
            'uploaded_by_user_id' => $media['uploaded_by_user_id'] ?? 'system',
            'created_at' => $media['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'metadata' => $metadata,
        ];
        MediaContractValidator::validateFile($record);
        $saved = $this->repository->updateFile(
            (string) ($media['tenant_id'] ?? ''),
            (string) ($media['id'] ?? ''),
            ['metadata' => $metadata, 'updated_at' => (string) $record['updated_at']]
        );
        if (!is_array($saved)) {
            throw new RuntimeException('MEDIA_NOT_FOUND');
        }
        return $saved;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMedia(string $tenantId, string $mediaId, ?string $appId): array
    {
        $media = $this->repository->findFile($this->requireString($tenantId, 'tenant_id'), $this->requireString($mediaId, 'media_id'));
        if (!is_array($media)) {
            throw new RuntimeException('MEDIA_NOT_FOUND');
        }
        $recordAppId = $this->nullable($media['app_id'] ?? null);
        $appId = $this->nullable($appId);
        if ($appId !== null && $recordAppId !== null && $recordAppId !== $appId) {
            throw new RuntimeException('MEDIA_NOT_FOUND');
        }
        return $media;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{source_path:string,original_name:string,mime_type:string,file_size:int}
     */
    private function resolveSource(array $payload): array
    {
        $sourcePath = $this->first([
            $payload['source_path'] ?? null,
            $payload['tmp_path'] ?? null,
            $payload['path'] ?? null,
        ]);
        $originalName = $this->first([$payload['original_name'] ?? null, $payload['name'] ?? null]);
        $mimeType = $this->first([$payload['mime_type'] ?? null, $payload['type'] ?? null]);
        $fileSize = $this->int($payload['file_size'] ?? $payload['size'] ?? null);

        if (is_array($payload['file'] ?? null)) {
            $file = (array) $payload['file'];
            $sourcePath = $this->first([$sourcePath, $file['source_path'] ?? null, $file['tmp_path'] ?? null, $file['path'] ?? null]);
            $originalName = $this->first([$originalName, $file['original_name'] ?? null, $file['name'] ?? null]);
            $mimeType = $this->first([$mimeType, $file['mime_type'] ?? null, $file['type'] ?? null]);
            $fileSize = max($fileSize, $this->int($file['file_size'] ?? $file['size'] ?? null));
        }

        if ($sourcePath === '') {
            throw new RuntimeException('MEDIA_SOURCE_REQUIRED');
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('MEDIA_SOURCE_NOT_READABLE');
        }

        return [
            'source_path' => $sourcePath,
            'original_name' => $originalName !== '' ? $originalName : basename($sourcePath),
            'mime_type' => $mimeType,
            'file_size' => $fileSize > 0 ? $fileSize : $this->sourceSize($sourcePath),
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function resolveExtension(array $source): string
    {
        foreach ([
            pathinfo((string) ($source['original_name'] ?? ''), PATHINFO_EXTENSION),
            pathinfo((string) ($source['source_path'] ?? ''), PATHINFO_EXTENSION),
        ] as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate !== '') {
                return $candidate;
            }
        }
        throw new RuntimeException('MEDIA_EXTENSION_NOT_ALLOWED');
    }

    private function mimeType(string $sourcePath, string $provided, string $extension): string
    {
        $provided = trim($provided);
        if ($provided !== '') {
            return $provided;
        }
        $detected = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $raw = finfo_file($finfo, $sourcePath);
                finfo_close($finfo);
                if (is_string($raw)) {
                    $detected = trim($raw);
                }
            }
        }
        if ($extension === 'xlsx' && ($detected === '' || $detected === 'application/zip')) {
            $detected = self::DEFAULT_MIME_BY_EXTENSION[$extension];
        }
        return $detected !== '' ? $detected : (self::DEFAULT_MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream');
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function metadata($value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed $existing
     * @return array<string, mixed>
     */
    private function hooks($existing, string $fileType, string $entityType): array
    {
        $existing = is_array($existing) ? $existing : [];
        return array_merge($existing, [
            'storage_driver' => strtolower(trim((string) (getenv('MEDIA_STORAGE_DRIVER') ?: 'local'))),
            'object_storage_ready' => true,
            'cdn_ready' => true,
            'ocr_ready' => in_array($fileType, ['image', 'pdf', 'xml'], true),
            'ai_product_image_recognition_ready' => $fileType === 'image' && $entityType === 'product',
            'invoice_document_parsing_ready' => in_array($entityType, ['invoice', 'purchase'], true)
                && in_array($fileType, ['pdf', 'xml'], true),
        ]);
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function accessDescriptors(array $media): array
    {
        $descriptors = [];
        foreach (['original', 'optimized', 'thumbnail'] as $variant) {
            $entry = $this->variantDescriptorOrNull($media, $variant);
            if ($entry === null) {
                $metadata = $this->metadata($media['metadata'] ?? []);
                $descriptors[$variant] = ['status' => $variant === 'original' ? 'ready' : (string) (($metadata['variants'][$variant]['status'] ?? 'missing'))];
                continue;
            }
            $exp = time() + $this->accessTtl();
            $payload = [
                'scope' => 'media:access',
                'tenant_id' => (string) ($media['tenant_id'] ?? ''),
                'media_id' => (string) ($media['id'] ?? ''),
                'variant' => $variant,
                'path' => 'media/access',
                'exp' => $exp,
            ];
            $appId = $this->nullable($media['app_id'] ?? null);
            if ($appId !== null) {
                $payload['app_id'] = $appId;
            }
            $token = MediaAccessToken::sign($payload, $this->accessSecret());
            $descriptors[$variant] = [
                'status' => 'ready',
                'token' => $token,
                'url' => '/api.php?route=media/access&id=' . rawurlencode((string) ($media['id'] ?? ''))
                    . '&variant=' . rawurlencode($variant)
                    . '&t=' . rawurlencode($token),
                'expires_at' => date('c', $exp),
                'mime_type' => (string) ($entry['mime_type'] ?? ''),
                'file_size' => max(0, (int) ($entry['file_size'] ?? 0)),
            ];
        }
        return $descriptors;
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>
     */
    private function variantDescriptor(array $media, string $variant): array
    {
        $entry = $this->variantDescriptorOrNull($media, $variant);
        if ($entry === null) {
            throw new RuntimeException('MEDIA_VARIANT_NOT_AVAILABLE');
        }
        return $entry;
    }

    /**
     * @param array<string, mixed> $media
     * @return array<string, mixed>|null
     */
    private function variantDescriptorOrNull(array $media, string $variant): ?array
    {
        $variant = $this->variant($variant);
        if ($variant === 'original') {
            return [
                'storage_path' => (string) ($media['storage_path'] ?? ''),
                'mime_type' => (string) ($media['mime_type'] ?? ''),
                'file_size' => max(0, (int) ($media['file_size'] ?? 0)),
            ];
        }
        $metadata = $this->metadata($media['metadata'] ?? []);
        $entry = is_array(($metadata['variants'][$variant] ?? null)) ? (array) $metadata['variants'][$variant] : [];
        if (($entry['status'] ?? '') !== 'ready' || trim((string) ($entry['storage_path'] ?? '')) === '') {
            return null;
        }
        return $entry;
    }

    /**
     * @param array<string, mixed> $media
     * @return array<int, string>
     */
    private function storagePaths(array $media): array
    {
        $paths = [];
        $original = trim((string) ($media['storage_path'] ?? ''));
        if ($original !== '') {
            $paths[] = $original;
        }
        $metadata = $this->metadata($media['metadata'] ?? []);
        foreach ((array) ($metadata['variants'] ?? []) as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $path = trim((string) ($variant['storage_path'] ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * @return array{0:\GdImage|resource,1:array<string,int>}
     */
    private function imageResource(string $sourcePath): array
    {
        $info = getimagesize($sourcePath);
        if (!is_array($info)) {
            throw new RuntimeException('MEDIA_IMAGE_INVALID');
        }
        $mime = trim((string) ($info['mime'] ?? ''));
        $image = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? imagecreatefromjpeg($sourcePath) : false,
            'image/png' => function_exists('imagecreatefrompng') ? imagecreatefrompng($sourcePath) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : false,
            default => false,
        };
        if (!is_resource($image) && !($image instanceof \GdImage)) {
            throw new RuntimeException('MEDIA_IMAGE_LOAD_FAILED');
        }
        return [$image, ['width' => max(1, (int) ($info[0] ?? 1)), 'height' => max(1, (int) ($info[1] ?? 1))]];
    }

    /**
     * @return array{width:int,height:int}
     */
    private function targetSize(int $width, int $height, int $limit, string $variant): array
    {
        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('MEDIA_IMAGE_INVALID');
        }
        if ($variant === 'optimized' && $width <= $limit) {
            return ['width' => $width, 'height' => $height];
        }
        $scale = $variant === 'optimized' ? ($limit / $width) : min($limit / $width, $limit / $height, 1);
        return [
            'width' => max(1, (int) round($width * $scale)),
            'height' => max(1, (int) round($height * $scale)),
        ];
    }

    private function variantExtension(string $originalExtension): string
    {
        $originalExtension = strtolower(trim($originalExtension));
        if ($originalExtension === 'png') {
            return 'png';
        }
        if ($originalExtension === 'webp' && function_exists('imagewebp')) {
            return 'webp';
        }
        return 'jpg';
    }

    private function saveImage($image, string $targetPath, string $extension): void
    {
        $quality = $this->jpegQuality();
        $saved = false;
        switch ($extension) {
            case 'png':
                $compression = max(0, min(9, 9 - (int) round(($quality / 100) * 9)));
                $saved = function_exists('imagepng') ? imagepng($image, $targetPath, $compression) : false;
                break;
            case 'webp':
                $saved = function_exists('imagewebp') ? imagewebp($image, $targetPath, $quality) : false;
                break;
            default:
                $saved = function_exists('imagejpeg') ? imagejpeg($image, $targetPath, $quality) : false;
                break;
        }
        if ($saved !== true) {
            throw new RuntimeException('MEDIA_IMAGE_SAVE_FAILED');
        }
    }

    private function destroyImage($image): void
    {
        if (is_resource($image) || $image instanceof \GdImage) {
            imagedestroy($image);
        }
    }

    /**
     * @param array<string, mixed> $media
     */
    private function variantPath(array $media, string $variant, string $extension): string
    {
        $storagePath = trim((string) ($media['storage_path'] ?? ''));
        $relative = str_starts_with($storagePath, 'storage/') ? substr($storagePath, strlen('storage/')) : $storagePath;
        $dir = trim((string) pathinfo($relative, PATHINFO_DIRNAME), '/\\');
        $stem = (string) pathinfo($relative, PATHINFO_FILENAME);
        if ($dir === '' || $dir === '.') {
            $dir = $this->relativeDir((string) ($media['tenant_id'] ?? 'default'), (string) ($media['entity_type'] ?? 'general'), (string) ($media['entity_id'] ?? '0'));
        }
        return $dir . '/' . $stem . '.' . $variant . '.' . $extension;
    }

    /**
     * @param array<string, mixed> $media
     */
    private function downloadName(array $media, string $variant): string
    {
        $original = trim((string) (($this->metadata($media['metadata'] ?? []))['original_name'] ?? 'archivo'));
        if ($variant === 'original') {
            return $original !== '' ? $original : 'archivo';
        }
        $base = (string) pathinfo($original, PATHINFO_FILENAME);
        $ext = (string) pathinfo($original, PATHINFO_EXTENSION);
        $base = $base !== '' ? $base : 'archivo';
        return $base . '_' . $variant . ($ext !== '' ? '.' . $ext : '');
    }

    private function entityType($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::ENTITY_TYPES, true)) {
            throw new RuntimeException('MEDIA_ENTITY_TYPE_INVALID');
        }
        return $value;
    }

    private function variant(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['original', 'optimized', 'thumbnail'], true) ? $value : 'original';
    }

    private function sanitizeFilename(string $value, string $fallbackExtension = ''): string
    {
        $value = trim($value) !== '' ? trim($value) : 'file';
        $value = str_replace(['\\', '/'], '_', $value);
        $ext = strtolower((string) pathinfo($value, PATHINFO_EXTENSION));
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) pathinfo($value, PATHINFO_FILENAME)) ?? 'file';
        $base = trim($base, '._-');
        if ($base === '') {
            $base = 'file';
        }
        if ($ext === '' && $fallbackExtension !== '') {
            $ext = strtolower(trim($fallbackExtension));
        }
        return $ext !== '' ? ($base . '.' . $ext) : $base;
    }

    private function uniqueStem(string $name): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower((string) pathinfo($name, PATHINFO_FILENAME))) ?? 'file';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'file';
        }
        return date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '_' . substr($base, 0, 60);
    }

    private function relativeDir(string $tenantId, string $entityType, string $entityId): string
    {
        return implode('/', [$this->pathSegment($tenantId), $this->pathSegment($entityType), $this->pathSegment($entityId)]);
    }

    private function pathSegment(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9._-]+/', '_', trim($value)) ?? 'default';
        $value = trim($value, '._-');
        return $value !== '' ? $value : 'default';
    }

    /**
     * @param array<int, mixed> $values
     */
    private function first(array $values): string
    {
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    private function requireString($value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException('Campo requerido faltante: ' . $field . '.');
        }
        return $value;
    }

    private function nullable($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function userId($value): string
    {
        return $this->nullable($value) ?? 'system';
    }

    private function int($value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function sourceSize(string $path): int
    {
        $size = @filesize($path);
        return $size !== false ? max(0, (int) $size) : 0;
    }

    private function maxSizeBytes(): int
    {
        $bytes = (int) (getenv('MEDIA_MAX_FILE_SIZE_BYTES') ?: 0);
        if ($bytes > 0) {
            return $bytes;
        }
        return max(1, (int) (getenv('MEDIA_MAX_FILE_SIZE_MB') ?: 20)) * 1024 * 1024;
    }

    private function thumbAsync(): bool
    {
        return $this->boolEnv('MEDIA_THUMBNAIL_ASYNC', false);
    }

    private function thumbMaxSide(): int
    {
        return max(64, (int) (getenv('MEDIA_THUMBNAIL_MAX_SIDE') ?: 320));
    }

    private function optimizedMaxWidth(): int
    {
        return max(320, (int) (getenv('MEDIA_OPTIMIZED_MAX_WIDTH') ?: 1600));
    }

    private function jpegQuality(): int
    {
        return max(40, min(95, (int) (getenv('MEDIA_JPEG_QUALITY') ?: 82)));
    }

    private function gdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
    }

    private function accessTtl(): int
    {
        return max(60, (int) (getenv('MEDIA_ACCESS_TTL_SEC') ?: 900));
    }

    private function accessSecret(): string
    {
        $secret = trim((string) (getenv('MEDIA_ACCESS_SECRET') ?: getenv('RECORDS_READ_SECRET') ?: ''));
        return $secret !== '' ? $secret : hash('sha256', FRAMEWORK_ROOT . '|' . PROJECT_ROOT . '|media_access');
    }

    private function boolEnv(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }
        return in_array($value, ['1', 'true', 'yes', 'si', 'on'], true);
    }
}
