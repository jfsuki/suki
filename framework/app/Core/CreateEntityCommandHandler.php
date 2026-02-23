<?php
// app/Core/CreateEntityCommandHandler.php

namespace App\Core;

use RuntimeException;

final class CreateEntityCommandHandler implements CommandHandlerInterface
{
    public function supports(string $commandName): bool
    {
        return $commandName === 'CreateEntity';
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = (string) ($context['mode'] ?? 'app');
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');

        if ($mode === 'app') {
            return $reply('Estas en modo app. Usa el chat creador para crear tablas.', $channel, $sessionId, $userId, 'error');
        }

        $entity = (string) ($command['entity'] ?? '');
        if ($entity === '') {
            return $reply('Necesito el nombre de la tabla.', $channel, $sessionId, $userId, 'error');
        }

        $entityExists = $this->contextCallable($context, 'entity_exists');
        if ((bool) $entityExists($entity)) {
            return $reply('La tabla ' . $entity . ' ya existe. No la voy a duplicar.', $channel, $sessionId, $userId, 'success', [
                'entity' => ['name' => $entity],
                'already_exists' => true,
            ]);
        }

        $builder = $context['builder'] ?? null;
        $writer = $context['writer'] ?? null;
        $migrator = $context['migrator'] ?? null;
        if (!$builder instanceof EntityBuilder || !$writer instanceof ContractWriter || !$migrator instanceof EntityMigrator) {
            throw new RuntimeException('INVALID_CONTEXT');
        }

        $entityPayload = $builder->build($entity, $command['fields'] ?? []);
        $writer->writeEntity($entityPayload);
        $migrator->migrateEntity($entityPayload, true);

        $registerEntity = $this->contextCallable($context, 'register_entity', false);
        if ($registerEntity !== null) {
            $registerEntity((string) ($entityPayload['name'] ?? $entity), $userId);
        }

        return $reply('Tabla creada: ' . (string) ($entityPayload['name'] ?? $entity), $channel, $sessionId, $userId, 'success', [
            'entity' => $entityPayload,
        ]);
    }

    private function replyCallable(array $context): callable
    {
        $callable = $context['reply'] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }

    private function contextCallable(array $context, string $key, bool $required = true): ?callable
    {
        $callable = $context[$key] ?? null;
        if ($callable === null && !$required) {
            return null;
        }
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }
}

