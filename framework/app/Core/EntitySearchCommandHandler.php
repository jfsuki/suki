<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class EntitySearchCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = ['SearchEntities', 'ResolveEntityReference', 'GetEntityByReference'];

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
                'Estas en modo creador. Usa el chat de la app para buscar registros operativos.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }

        $service = $context['entity_search_service'] ?? null;
        if (!$service instanceof EntitySearchService) {
            $service = new EntitySearchService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'SearchEntities' => $this->handleSearch($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'ResolveEntityReference' => $this->handleResolve($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetEntityByReference' => $this->handleGetByReference($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
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
    private function handleSearch(
        EntitySearchService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $result = $service->search(
            $tenantId,
            trim((string) ($command['query'] ?? '')),
            is_array($command['filters'] ?? null) ? (array) $command['filters'] : [],
            $appId !== '' ? $appId : null
        );
        $items = is_array($result['results'] ?? null) ? (array) $result['results'] : [];
        $text = $items === []
            ? 'No encontre coincidencias dentro del tenant actual.'
            : "Coincidencias:\n" . implode("\n", array_map([$this, 'formatLine'], $items));

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'entity_search_action' => 'search',
                'query' => (string) ($result['query'] ?? ''),
                'filters' => $result['filters'] ?? [],
                'results' => $items,
                'items' => $items,
                'result_count' => (int) ($result['result_count'] ?? count($items)),
                'selected_entity_type' => (string) ($result['selected_entity_type'] ?? ''),
                'selected_entity_id' => (string) ($result['selected_entity_id'] ?? ''),
                'latency_ms' => (int) ($result['latency_ms'] ?? 0),
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleResolve(
        EntitySearchService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $result = $service->resolveBestMatch(
            $tenantId,
            trim((string) ($command['query'] ?? '')),
            is_array($command['filters'] ?? null) ? (array) $command['filters'] : [],
            $appId !== '' ? $appId : null
        );
        $resolved = (bool) ($result['resolved'] ?? false);
        $selected = is_array($result['result'] ?? null) ? (array) $result['result'] : [];
        $candidates = is_array($result['candidates'] ?? null) ? (array) $result['candidates'] : [];

        if ($resolved && $selected !== []) {
            $text = 'Referencia resuelta: ' . $this->formatLabel($selected) . '.';
        } elseif ($candidates !== []) {
            $text = "Encontre varias coincidencias. Indica cual usar:\n" . implode("\n", array_map([$this, 'formatLine'], $candidates));
        } else {
            $text = 'No encontre una coincidencia segura dentro del tenant actual.';
        }

        return $this->withReplyText($reply(
            $text,
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'entity_search_action' => 'resolve',
                'query' => (string) ($result['query'] ?? ''),
                'filters' => $result['filters'] ?? [],
                'resolved' => $resolved,
                'result' => $resolved ? $selected : null,
                'candidates' => $resolved ? [] : $candidates,
                'result_count' => (int) ($result['result_count'] ?? ($resolved ? 1 : count($candidates))),
                'selected_entity_type' => (string) ($result['selected_entity_type'] ?? ''),
                'selected_entity_id' => (string) ($result['selected_entity_id'] ?? ''),
                'needs_clarification' => !$resolved && $candidates !== [],
                'latency_ms' => (int) ($result['latency_ms'] ?? 0),
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function handleGetByReference(
        EntitySearchService $service,
        string $tenantId,
        string $appId,
        array $command,
        callable $reply,
        string $channel,
        string $sessionId,
        string $userId
    ): array {
        $result = $service->getByReference(
            $tenantId,
            trim((string) ($command['entity_type'] ?? '')),
            trim((string) ($command['entity_id'] ?? '')),
            is_array($command['filters'] ?? null) ? (array) $command['filters'] : [],
            $appId !== '' ? $appId : null
        );
        if (!is_array($result)) {
            throw new RuntimeException('ENTITY_SEARCH_NOT_FOUND');
        }

        return $this->withReplyText($reply(
            'Referencia cargada: ' . $this->formatLabel($result) . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'entity_search_action' => 'get_by_reference',
                'result' => $result,
                'selected_entity_type' => (string) ($result['entity_type'] ?? ''),
                'selected_entity_id' => (string) ($result['entity_id'] ?? ''),
                'result_count' => 1,
            ])
        ));
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
            'module_used' => 'entity_search',
            'entity_search_action' => 'none',
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
     * @param array<string, mixed> $item
     */
    private function formatLine(array $item): string
    {
        $parts = ['- ' . $this->formatLabel($item)];
        if (trim((string) ($item['entity_type'] ?? '')) !== '') {
            $parts[] = '[' . (string) $item['entity_type'] . ']';
        }
        if (trim((string) ($item['entity_id'] ?? '')) !== '') {
            $parts[] = 'id=' . (string) $item['entity_id'];
        }
        if (trim((string) ($item['matched_by'] ?? '')) !== '') {
            $parts[] = 'match=' . (string) $item['matched_by'];
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatLabel(array $item): string
    {
        $label = trim((string) ($item['label'] ?? ''));
        $subtitle = trim((string) ($item['subtitle'] ?? ''));
        if ($subtitle === '') {
            return $label !== '' ? $label : ((string) ($item['entity_type'] ?? 'registro') . ' #' . (string) ($item['entity_id'] ?? ''));
        }

        return ($label !== '' ? $label : 'registro') . ' | ' . $subtitle;
    }

    private function humanizeError(string $message): string
    {
        $message = trim($message);

        return match ($message) {
            'ENTITY_SEARCH_QUERY_REQUIRED' => 'Necesito una referencia mas concreta para buscar.',
            'ENTITY_SEARCH_ENTITY_TYPE_INVALID' => 'El tipo de entidad no es valido para esta busqueda.',
            'ENTITY_SEARCH_NOT_FOUND' => 'No encontre esa referencia dentro del tenant actual.',
            'COMMAND_NOT_SUPPORTED' => 'No pude ejecutar esa operacion de busqueda.',
            default => $message !== '' ? $message : 'No pude procesar la busqueda solicitada.',
        };
    }
}
