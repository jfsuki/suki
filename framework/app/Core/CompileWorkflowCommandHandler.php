<?php
// app/Core/CompileWorkflowCommandHandler.php

namespace App\Core;

use RuntimeException;

final class CompileWorkflowCommandHandler implements CommandHandlerInterface
{
    public function supports(string $commandName): bool
    {
        return $commandName === 'CompileWorkflow';
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = (string) ($context['mode'] ?? 'app');
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');

        if ($mode !== 'builder') {
            return $reply('La compilacion de workflows se hace en modo Creador.', $channel, $sessionId, $userId, 'error');
        }

        $compiler = $context['workflow_compiler'] ?? null;
        $repo = $context['workflow_repository'] ?? null;
        if (!$compiler instanceof WorkflowCompiler || !$repo instanceof WorkflowRepository) {
            throw new RuntimeException('INVALID_CONTEXT');
        }

        $text = trim((string) ($command['text'] ?? ''));
        if ($text === '') {
            return $reply('Necesito una descripcion corta del workflow a compilar.', $channel, $sessionId, $userId, 'error');
        }
        $workflowId = trim((string) ($command['workflow_id'] ?? ''));
        $current = [];
        if ($workflowId !== '' && $repo->exists($workflowId)) {
            $current = $repo->load($workflowId);
        }

        $proposal = $compiler->compile($text, $current);
        $apply = !array_key_exists('apply', $command) || (bool) ($command['apply'] ?? false);
        if (!$apply) {
            return $reply(
                (string) ($proposal['confirmation_reply'] ?? 'Propuesta lista.'),
                $channel,
                $sessionId,
                $userId,
                'success',
                ['proposal' => $proposal]
            );
        }

        $contract = is_array($proposal['proposed_contract'] ?? null) ? (array) $proposal['proposed_contract'] : [];
        if (empty($contract)) {
            return $reply('No pude construir un contrato de workflow valido.', $channel, $sessionId, $userId, 'error');
        }
        $saved = $repo->save($contract, 'chat_compile_workflow');
        $id = (string) ($saved['id'] ?? '');
        $rev = (int) ($saved['revision'] ?? 0);
        return $reply(
            'Workflow compilado y guardado: ' . $id . ' (rev ' . $rev . ').',
            $channel,
            $sessionId,
            $userId,
            'success',
            [
                'proposal' => $proposal,
                'saved' => $saved,
            ]
        );
    }

    private function replyCallable(array $context): callable
    {
        $callable = $context['reply'] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }
}

