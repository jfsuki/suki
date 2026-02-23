<?php
// app/Core/CreateFormCommandHandler.php

namespace App\Core;

use RuntimeException;

final class CreateFormCommandHandler implements CommandHandlerInterface
{
    public function supports(string $commandName): bool
    {
        return $commandName === 'CreateForm';
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = (string) ($context['mode'] ?? 'app');
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');

        if ($mode === 'app') {
            return $reply('Estas en modo app. Usa el chat creador para crear formularios.', $channel, $sessionId, $userId, 'error');
        }

        $entity = (string) ($command['entity'] ?? '');
        if ($entity === '') {
            return $reply('Necesito la entidad para el formulario.', $channel, $sessionId, $userId, 'error');
        }

        $formExists = $this->contextCallable($context, 'form_exists_for_entity');
        if ((bool) $formExists($entity)) {
            return $reply('El formulario de ' . $entity . ' ya existe. No lo voy a duplicar.', $channel, $sessionId, $userId, 'success', [
                'form' => ['name' => $entity . '.form'],
                'already_exists' => true,
            ]);
        }

        $entities = $context['entities'] ?? null;
        $wizard = $context['wizard'] ?? null;
        $writer = $context['writer'] ?? null;
        if (!$entities instanceof EntityRegistry || !$wizard instanceof FormWizard || !$writer instanceof ContractWriter) {
            throw new RuntimeException('INVALID_CONTEXT');
        }

        $entityData = $entities->get($entity);
        $form = $wizard->buildFromEntity($entityData);
        $writer->writeForm($form);

        $registerForm = $this->contextCallable($context, 'register_form', false);
        if ($registerForm !== null) {
            $registerForm($userId);
        }

        return $reply('Formulario creado para ' . $entity, $channel, $sessionId, $userId, 'success', [
            'form' => $form,
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

