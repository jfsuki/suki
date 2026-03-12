<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class FiscalEngineCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'CreateFiscalDocument',
        'GetFiscalDocument',
        'ListFiscalDocuments',
        'GetFiscalDocumentBySource',
        'RecordFiscalEvent',
        'UpdateFiscalDocumentStatus',
    ];

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
                'Estas en modo creador. Usa el chat de la app para operar documentos fiscales.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }

        $service = $context['fiscal_service'] ?? null;
        if (!$service instanceof FiscalEngineService) {
            $service = new FiscalEngineService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'CreateFiscalDocument' => $this->respondDocument(
                    $reply,
                    $channel,
                    $sessionId,
                    $userId,
                    'Documento fiscal creado: fiscal_document_id=' . (string) (($document = $service->createDocumentFromSource($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]))['id'] ?? '') . '.',
                    $this->documentData('create_document', $document)
                ),
                'GetFiscalDocument' => $this->respondDocument(
                    $reply,
                    $channel,
                    $sessionId,
                    $userId,
                    'Documento fiscal cargado: fiscal_document_id=' . (string) (($document = $service->getDocument($tenantId, $this->documentId($command), $appId !== '' ? $appId : null))['id'] ?? '') . '.',
                    $this->documentData('get_document', $document)
                ),
                'ListFiscalDocuments' => $this->respondList($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetFiscalDocumentBySource' => $this->respondDocument(
                    $reply,
                    $channel,
                    $sessionId,
                    $userId,
                    'Documento fiscal cargado por origen: fiscal_document_id=' . (string) (($document = $service->getDocumentBySource(
                        $tenantId,
                        (string) ($command['source_module'] ?? ''),
                        (string) ($command['source_entity_type'] ?? ''),
                        (string) ($command['source_entity_id'] ?? ''),
                        array_filter([
                            'document_type' => $command['document_type'] ?? null,
                            'app_id' => $appId !== '' ? $appId : null,
                            'limit' => $command['limit'] ?? null,
                        ], static fn($value): bool => $value !== null && $value !== ''),
                        $appId !== '' ? $appId : null
                    ))['id'] ?? '') . '.',
                    $this->documentData('get_by_source', $document)
                ),
                'RecordFiscalEvent' => $this->respondEvent($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'UpdateFiscalDocumentStatus' => $this->respondDocument(
                    $reply,
                    $channel,
                    $sessionId,
                    $userId,
                    'Estado fiscal actualizado: fiscal_document_id=' . (string) (($document = $service->updateStatus($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]))['id'] ?? '') . '.',
                    $this->documentData('update_status', $document)
                ),
                default => throw new RuntimeException('COMMAND_NOT_SUPPORTED'),
            };
        } catch (Throwable $e) {
            return $this->withReplyText($reply(
                $this->humanizeError((string) $e->getMessage()),
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData(['result_status' => 'error'])
            ));
        }
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondList(FiscalEngineService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $items = $service->listDocuments(
            $tenantId,
            array_filter([
                'source_module' => $command['source_module'] ?? null,
                'source_entity_type' => $command['source_entity_type'] ?? null,
                'source_entity_id' => $command['source_entity_id'] ?? null,
                'document_type' => $command['document_type'] ?? null,
                'status' => $command['status'] ?? null,
                'document_number' => $command['document_number'] ?? null,
                'external_provider' => $command['external_provider'] ?? null,
                'external_reference' => $command['external_reference'] ?? null,
                'date_from' => $command['date_from'] ?? null,
                'date_to' => $command['date_to'] ?? null,
                'limit' => $command['limit'] ?? null,
            ], static fn($value): bool => $value !== null && $value !== ''),
            $appId !== '' ? $appId : null
        );
        $text = $items === []
            ? 'No hay documentos fiscales con esos filtros.'
            : "Documentos fiscales:\n" . implode("\n", array_map([$this, 'formatDocumentLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'fiscal_action' => 'list_documents',
                'items' => $items,
                'result_count' => count($items),
                'source_module' => (string) ($command['source_module'] ?? ''),
                'source_entity_type' => (string) ($command['source_entity_type'] ?? ''),
                'source_entity_id' => (string) ($command['source_entity_id'] ?? ''),
                'document_type' => (string) ($command['document_type'] ?? ''),
                'fiscal_status' => (string) ($command['status'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondEvent(FiscalEngineService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $event = $service->recordEvent($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null]);

        return $this->withReplyText($reply(
            'Evento fiscal registrado: fiscal_document_id=' . (string) ($event['fiscal_document_id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'fiscal_action' => 'record_event',
                'event' => $event,
                'item' => $event,
                'fiscal_document_id' => (string) ($event['fiscal_document_id'] ?? ''),
                'event_type' => (string) ($event['event_type'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function respondDocument(callable $reply, string $channel, string $sessionId, string $userId, string $text, array $data): array
    {
        return $this->withReplyText($reply($text, $channel, $sessionId, $userId, 'success', $data));
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function documentData(string $action, array $document): array
    {
        return $this->moduleData([
            'fiscal_action' => $action,
            'document' => $document,
            'item' => $document,
            'fiscal_document_id' => (string) ($document['id'] ?? ''),
            'source_module' => (string) ($document['source_module'] ?? ''),
            'source_entity_type' => (string) ($document['source_entity_type'] ?? ''),
            'source_entity_id' => (string) ($document['source_entity_id'] ?? ''),
            'document_type' => (string) ($document['document_type'] ?? ''),
            'fiscal_status' => (string) ($document['status'] ?? ''),
            'line_count' => count((array) ($document['lines'] ?? [])),
            'total' => $document['total'] ?? null,
            'result_status' => 'success',
        ]);
    }

    private function documentId(array $command): string
    {
        return trim((string) ($command['fiscal_document_id'] ?? $command['document_id'] ?? $command['id'] ?? ''));
    }

    private function replyCallable(array $context): callable
    {
        return $context['reply_callable'] ?? static function (string $text, string $channel, string $sessionId, string $userId, string $status, array $data = []): array {
            return ['ok' => $status === 'success', 'status' => $status, 'channel' => $channel, 'session_id' => $sessionId, 'user_id' => $userId, 'reply' => $text, 'data' => $data];
        };
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function moduleData(array $overrides = []): array
    {
        return $overrides + ['module_used' => 'fiscal', 'fiscal_action' => 'none', 'result_status' => 'success'];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function withReplyText(array $response): array
    {
        $response['text'] = (string) ($response['reply'] ?? '');

        return $response;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatDocumentLine(array $item): string
    {
        return '- fiscal_document_id=' . (string) ($item['id'] ?? '')
            . ' | tipo=' . (string) ($item['document_type'] ?? '')
            . ' | estado=' . (string) ($item['status'] ?? '')
            . ' | origen=' . (string) (($item['source_module'] ?? '') ?: 'n/a')
            . ':' . (string) (($item['source_entity_id'] ?? '') ?: 'n/a');
    }

    private function humanizeError(string $message): string
    {
        return match ($message) {
            'TENANT_ID_REQUIRED' => 'Necesito el tenant activo para operar el motor fiscal.',
            'SOURCE_MODULE_REQUIRED' => 'Indica `source_module` para crear el documento fiscal.',
            'SOURCE_ENTITY_TYPE_REQUIRED' => 'Indica `source_entity_type` para crear el documento fiscal.',
            'SOURCE_ENTITY_ID_REQUIRED' => 'Indica `source_entity_id` para crear el documento fiscal.',
            'DOCUMENT_TYPE_REQUIRED' => 'Indica `document_type` para crear el documento fiscal.',
            'FISCAL_DOCUMENT_TYPE_INVALID' => 'El tipo de documento fiscal no es valido.',
            'FISCAL_DOCUMENT_NOT_FOUND' => 'No encontre ese documento fiscal.',
            'FISCAL_DOCUMENT_SOURCE_AMBIGUOUS' => 'Encontre varios documentos fiscales para ese origen. Indica el tipo exacto.',
            'FISCAL_SOURCE_NOT_FOUND' => 'No encontre la entidad origen para representar ese documento fiscal.',
            'STATUS_REQUIRED', 'FISCAL_STATUS_INVALID' => 'Indica un estado fiscal valido.',
            'FISCAL_STATUS_TRANSITION_INVALID' => 'Ese cambio de estado fiscal no es valido.',
            'EVENT_TYPE_REQUIRED' => 'Indica `event_type` para registrar el evento fiscal.',
            'EVENT_STATUS_REQUIRED' => 'Indica `event_status` para registrar el evento fiscal.',
            'FISCAL_LINE_DESCRIPTION_REQUIRED' => 'Cada linea fiscal necesita descripcion.',
            'FISCAL_LINE_QTY_INVALID' => 'La cantidad de la linea fiscal debe ser mayor que cero.',
            'FISCAL_LINE_TAX_RATE_INVALID' => 'La tasa de impuesto de la linea fiscal no es valida.',
            'FISCAL_AMOUNT_INVALID' => 'Uno de los montos fiscales no es valido.',
            default => $message,
        };
    }
}
