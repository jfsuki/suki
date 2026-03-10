<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class POSCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'CreatePOSDraft',
        'GetPOSDraft',
        'AddPOSDraftLine',
        'RemovePOSDraftLine',
        'AttachPOSDraftCustomer',
        'ListPOSOpenDrafts',
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
                'Estas en modo creador. Usa el chat de la app para operar borradores POS.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }

        $service = $context['pos_service'] ?? null;
        if (!$service instanceof POSService) {
            $service = new POSService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'CreatePOSDraft' => $this->handleCreateDraft($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetPOSDraft' => $this->handleGetDraft($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'AddPOSDraftLine' => $this->handleAddLine($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'RemovePOSDraftLine' => $this->handleRemoveLine($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'AttachPOSDraftCustomer' => $this->handleAttachCustomer($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'ListPOSOpenDrafts' => $this->handleListOpenDrafts($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                default => throw new RuntimeException('COMMAND_NOT_SUPPORTED'),
            };
        } catch (Throwable $e) {
            return $this->withReplyText($reply(
                $this->humanizeError((string) $e->getMessage()),
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData([
                    'result_status' => 'error',
                ])
            ));
        }
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleCreateDraft(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draft = $service->createDraft($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);

        return $this->withReplyText($reply(
            'Borrador POS creado: draft_id=' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'create_draft',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleGetDraft(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draftId = $this->draftId($command);
        $draft = $service->getDraft($tenantId, $draftId, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Borrador cargado: draft_id=' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'get_draft',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleAddLine(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draft = $service->addLineToDraft($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);
        $lines = is_array($draft['lines'] ?? null) ? (array) $draft['lines'] : [];
        $lastLine = is_array(end($lines)) ? (array) end($lines) : [];

        return $this->withReplyText($reply(
            'Linea agregada al borrador ' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'add_draft_line',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'product_id' => (string) ($lastLine['product_id'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleRemoveLine(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draftId = $this->draftId($command);
        $lineId = trim((string) ($command['line_id'] ?? $command['id'] ?? ''));
        if ($lineId === '') {
            throw new RuntimeException('POS_LINE_ID_REQUIRED');
        }

        $draft = $service->removeLineFromDraft($tenantId, $draftId, $lineId, $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            'Linea eliminada del borrador ' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'remove_draft_line',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleAttachCustomer(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $draft = $service->attachCustomerToDraft($command + [
            'tenant_id' => $tenantId,
            'app_id' => $appId !== '' ? $appId : null,
        ]);

        return $this->withReplyText($reply(
            'Cliente asociado al borrador ' . (string) ($draft['id'] ?? '') . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'attach_customer',
                'draft' => $draft,
                'item' => $draft,
                'draft_id' => (string) ($draft['id'] ?? ''),
                'session_id' => (string) ($draft['session_id'] ?? ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleListOpenDrafts(
        POSService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $items = $service->listOpenDrafts(
            $tenantId,
            $appId !== '' ? $appId : null,
            max(1, (int) ($command['limit'] ?? 10))
        );
        $text = $items === []
            ? 'No hay borradores POS abiertos.'
            : "Borradores abiertos:\n" . implode("\n", array_map([$this, 'formatDraftLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'pos_action' => 'list_open_drafts',
                'items' => $items,
                'result_count' => count($items),
                'result_status' => 'success',
            ])
        ));
    }

    private function draftId(array $command): string
    {
        $draftId = trim((string) ($command['draft_id'] ?? $command['sale_draft_id'] ?? ''));
        if ($draftId === '') {
            throw new RuntimeException('POS_DRAFT_ID_REQUIRED');
        }

        return $draftId;
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
        return array_merge([
            'module_used' => 'pos',
            'pos_action' => 'none',
            'draft_id' => '',
            'session_id' => '',
            'product_id' => '',
            'result_status' => 'success',
        ], $overrides);
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
     * @param array<string, mixed> $draft
     */
    private function formatDraftLine(array $draft): string
    {
        return '- draft_id=' . (string) ($draft['id'] ?? '')
            . ' total=' . (string) ($draft['total'] ?? 0)
            . ' status=' . (string) ($draft['status'] ?? 'open');
    }

    private function humanizeError(string $message): string
    {
        $message = trim($message);

        return match ($message) {
            'POS_TENANT_ID_REQUIRED', 'Campo requerido faltante: tenant_id.' => 'Necesito el tenant actual para continuar.',
            'POS_DRAFT_ID_REQUIRED' => 'Indica `draft_id` para continuar con el borrador POS.',
            'POS_LINE_ID_REQUIRED' => 'Indica `line_id` para identificar la linea del borrador.',
            'POS_SESSION_NOT_FOUND' => 'No encontre esa sesion POS dentro del tenant actual.',
            'POS_DRAFT_NOT_FOUND' => 'No encontre ese borrador POS dentro del tenant actual.',
            'POS_DRAFT_NOT_OPEN' => 'Solo puedo modificar borradores POS en estado abierto.',
            'POS_PRODUCT_REFERENCE_REQUIRED' => 'Indica un `product_id`, `sku`, `barcode` o `query` para agregar la linea.',
            'POS_PRODUCT_NOT_FOUND' => 'No encontre un producto valido dentro del tenant actual.',
            'POS_PRODUCT_AMBIGUOUS' => 'Encontre varios productos posibles. Indica el producto exacto.',
            'POS_PRODUCT_PRICE_UNAVAILABLE' => 'No pude resolver el precio del producto con seguridad.',
            'POS_CUSTOMER_REFERENCE_REQUIRED' => 'Indica `customer_id` o una referencia del cliente.',
            'POS_CUSTOMER_NOT_FOUND' => 'No encontre ese cliente dentro del tenant actual.',
            'POS_CUSTOMER_AMBIGUOUS' => 'Encontre varios clientes posibles. Indica el cliente exacto.',
            'POS_INVALID_QTY' => 'La cantidad debe ser mayor que cero.',
            'POS_DRAFT_LINE_NOT_FOUND' => 'No encontre esa linea dentro del borrador POS.',
            'COMMAND_NOT_SUPPORTED' => 'No pude ejecutar esa operacion POS.',
            default => $message !== '' ? $message : 'No pude procesar la operacion POS solicitada.',
        };
    }
}
