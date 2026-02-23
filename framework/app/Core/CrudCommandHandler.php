<?php
// app/Core/CrudCommandHandler.php

namespace App\Core;

use RuntimeException;

final class CrudCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private array $commands = [
        'CreateRecord',
        'QueryRecords',
        'ReadRecord',
        'UpdateRecord',
        'DeleteRecord',
    ];

    public function supports(string $commandName): bool
    {
        return in_array($commandName, $this->commands, true);
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = (string) ($context['mode'] ?? 'app');
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');
        $cmd = (string) ($command['command'] ?? '');
        $entity = (string) ($command['entity'] ?? '');
        $id = $command['id'] ?? null;
        $data = (array) ($command['data'] ?? []);
        $filters = (array) ($command['filters'] ?? []);

        if ($mode === 'builder') {
            return $reply('Estas en modo creador. Usa el chat app para registrar datos.', $channel, $sessionId, $userId, 'error');
        }

        $entityExists = $this->contextCallable($context, 'entity_exists');
        if ($entity === '' || !(bool) $entityExists($entity)) {
            return $reply('Esa tabla no existe en esta app. Debe ser agregada por el creador.', $channel, $sessionId, $userId, 'error');
        }

        $commandLayer = $context['command_layer'] ?? null;
        if (!$commandLayer instanceof CommandLayer) {
            throw new RuntimeException('INVALID_CONTEXT');
        }

        switch ($cmd) {
            case 'CreateRecord':
                $result = $commandLayer->createRecord($entity, $data);
                return $reply('Registro creado en ' . $entity, $channel, $sessionId, $userId, 'success', $result);
            case 'QueryRecords':
                $result = $commandLayer->queryRecords($entity, $filters, 20, 0);
                return $reply('Resultados para ' . $entity . ': ' . count($result), $channel, $sessionId, $userId, 'success', $result);
            case 'ReadRecord':
                $result = $commandLayer->readRecord($entity, $id, true);
                return $reply('Registro de ' . $entity, $channel, $sessionId, $userId, 'success', $result);
            case 'UpdateRecord':
                $result = $commandLayer->updateRecord($entity, $id, $data);
                return $reply('Registro actualizado en ' . $entity, $channel, $sessionId, $userId, 'success', $result);
            case 'DeleteRecord':
                $result = $commandLayer->deleteRecord($entity, $id);
                return $reply('Registro eliminado en ' . $entity, $channel, $sessionId, $userId, 'success', $result);
        }

        throw new RuntimeException('COMMAND_NOT_SUPPORTED');
    }

    private function replyCallable(array $context): callable
    {
        $callable = $context['reply'] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }

    private function contextCallable(array $context, string $key): callable
    {
        $callable = $context[$key] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }
}

