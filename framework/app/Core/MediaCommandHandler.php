<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class MediaCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = ['UploadMedia', 'ListMedia', 'GetMedia', 'DeleteMedia', 'GenerateMediaThumbnail'];

    public function supports(string $commandName): bool
    {
        return in_array($commandName, self::SUPPORTED, true);
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = strtolower(trim((string) ($context['mode'] ?? 'app')));
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');
        $tenantId = trim((string) ($command['tenant_id'] ?? $context['tenant_id'] ?? ''));
        $appId = trim((string) ($command['app_id'] ?? $context['project_id'] ?? ''));

        if ($mode === 'builder') {
            return $this->withReplyText($reply(
                'Estas en modo creador. Usa el chat de la app para operar archivos y documentos.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }

        $service = $context['media_service'] ?? null;
        if (!$service instanceof MediaService) {
            $service = new MediaService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'UploadMedia' => $this->handleUpload($service, $tenantId, $appId, $userId, $command, $reply, $channel, $sessionId),
                'ListMedia' => $this->handleList($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetMedia' => $this->handleGet($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'DeleteMedia' => $this->handleDelete($service, $tenantId, $appId, $userId, $command, $reply, $channel, $sessionId),
                'GenerateMediaThumbnail' => $this->handleThumbnail($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                default => throw new RuntimeException('COMMAND_NOT_SUPPORTED'),
            };
        } catch (Throwable $e) {
            return $this->withReplyText($reply(
                $this->humanizeError((string) $e->getMessage()),
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleUpload(
        MediaService $service,
        string $tenantId,
        string $appId,
        string $userId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId
    ): array {
        $media = $service->upload($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
            'uploaded_by_user_id' => $userId,
        ]);

        return $this->withReplyText($reply(
            'Archivo subido: ' . (string) ($media['original_name'] ?? ('media ' . ($media['id'] ?? ''))) . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData(['media_action' => 'upload', 'item' => $media, 'media' => $media])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleList(
        MediaService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $items = $service->list(
            $tenantId,
            (string) ($command['entity_type'] ?? ''),
            (string) ($command['entity_id'] ?? ''),
            $appId !== '' ? $appId : null,
            max(1, (int) ($command['limit'] ?? 50)),
            max(0, (int) ($command['offset'] ?? 0))
        );
        $text = $items === []
            ? 'No hay archivos asociados a ese registro.'
            : "Archivos:\n" . implode("\n", array_map([$this, 'formatLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData(['media_action' => 'list', 'items' => $items])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleGet(
        MediaService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $media = $service->get($tenantId, $this->mediaId($command), $appId !== '' ? $appId : null);
        $text = 'Archivo listo: ' . (string) ($media['original_name'] ?? ('media ' . ($media['id'] ?? ''))) . '.';
        if (trim((string) ($media['access']['original']['url'] ?? '')) !== '') {
            $text .= ' Usa el enlace firmado devuelto para abrirlo.';
        }

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData(['media_action' => 'get', 'item' => $media, 'media' => $media])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleDelete(
        MediaService $service,
        string $tenantId,
        string $appId,
        string $userId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId
    ): array {
        if (!(bool) ($command['confirmed'] ?? false)) {
            throw new RuntimeException('MEDIA_DELETE_CONFIRMATION_REQUIRED');
        }
        $result = $service->delete($tenantId, $this->mediaId($command), $appId !== '' ? $appId : null, $userId);
        $media = is_array($result['media'] ?? null) ? (array) $result['media'] : [];

        return $this->withReplyText($reply(
            'Archivo eliminado: ' . (string) ($media['original_name'] ?? ('media ' . ($media['id'] ?? ''))) . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData(['media_action' => 'delete', 'item' => $media, 'media' => $media, 'deleted' => true])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleThumbnail(
        MediaService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $media = $service->generateThumbnail($tenantId, $this->mediaId($command), $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Miniatura generada para ' . (string) ($media['original_name'] ?? ('media ' . ($media['id'] ?? ''))) . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData(['media_action' => 'get', 'item' => $media, 'media' => $media])
        ));
    }

    private function mediaId(array $command): string
    {
        $mediaId = trim((string) ($command['media_id'] ?? $command['id'] ?? ''));
        if ($mediaId === '') {
            throw new RuntimeException('MEDIA_NOT_FOUND');
        }
        return $mediaId;
    }

    private function replyCallable(array $context): callable
    {
        $callable = $context['reply'] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function moduleData(array $overrides = []): array
    {
        return array_merge(['module_used' => 'media_storage', 'media_action' => 'none'], $overrides);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function withReplyText(array $response): array
    {
        if (!array_key_exists('reply', $response)) {
            $response['reply'] = (string) (($response['data']['reply'] ?? $response['message'] ?? ''));
        }
        return $response;
    }

    /**
     * @param array<string, mixed> $media
     */
    private function formatLine(array $media): string
    {
        $parts = ['- ' . (string) ($media['original_name'] ?? ('media ' . ($media['id'] ?? ''))) ];
        if (trim((string) ($media['file_type'] ?? '')) !== '') {
            $parts[] = '[' . (string) $media['file_type'] . ']';
        }
        if (trim((string) ($media['id'] ?? '')) !== '') {
            $parts[] = 'media_id=' . (string) $media['id'];
        }
        return implode(' ', $parts);
    }

    private function humanizeError(string $message): string
    {
        $message = trim($message);
        return match ($message) {
            'MEDIA_NOT_FOUND' => 'No encontre ese archivo dentro del tenant actual.',
            'MEDIA_DELETE_CONFIRMATION_REQUIRED' => 'Necesito confirmacion explicita para eliminar el archivo.',
            'MEDIA_MAX_SIZE_EXCEEDED' => 'El archivo supera el tamano maximo permitido.',
            'MEDIA_EXTENSION_NOT_ALLOWED' => 'La extension del archivo no esta permitida.',
            'MEDIA_SOURCE_REQUIRED' => 'Necesito el archivo origen para continuar.',
            'MEDIA_SOURCE_NOT_READABLE' => 'No pude leer el archivo origen.',
            'MEDIA_ENTITY_TYPE_INVALID' => 'El tipo de entidad no es valido para este modulo.',
            'MEDIA_VARIANT_NOT_AVAILABLE' => 'La variante solicitada aun no esta disponible.',
            'MEDIA_NOT_IMAGE' => 'Ese archivo no es una imagen.',
            'MEDIA_THUMBNAIL_UNAVAILABLE' => 'No pude generar la miniatura en este entorno.',
            default => $message !== '' ? $message : 'No pude procesar la operacion de archivos.',
        };
    }
}
