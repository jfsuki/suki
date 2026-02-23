<?php
// app/Core/InstallPlaybookCommandHandler.php

namespace App\Core;

use RuntimeException;

final class InstallPlaybookCommandHandler implements CommandHandlerInterface
{
    public function supports(string $commandName): bool
    {
        return $commandName === 'InstallPlaybook';
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = (string) ($context['mode'] ?? 'app');
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');

        if ($mode === 'app') {
            return $reply('Estas en modo app. Usa el chat creador para instalar playbooks.', $channel, $sessionId, $userId, 'error');
        }

        $sectorKey = strtoupper(trim((string) ($command['sector_key'] ?? (($command['data']['sector_key'] ?? '')))));
        $installer = $context['playbook_installer'] ?? null;
        if (!$installer instanceof PlaybookInstaller) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        if ($sectorKey === '') {
            $sectors = $installer->listSectors();
            $keys = array_map(
                static fn(array $row): string => (string) ($row['sector_key'] ?? ''),
                array_filter($sectors, 'is_array')
            );
            $keys = array_values(array_filter($keys, static fn(string $v): bool => $v !== ''));
            return $reply(
                'Necesito el sector del playbook. Opciones: ' . implode(', ', $keys),
                $channel,
                $sessionId,
                $userId,
                'error',
                ['sectors' => $sectors]
            );
        }

        $isDryRun = !empty($command['dry_run']);
        $result = $installer->installSector(
            $sectorKey,
            $isDryRun,
            !empty($command['overwrite'])
        );
        if (empty($result['ok'])) {
            return $reply(
                (string) ($result['message'] ?? 'No pude instalar ese playbook.'),
                $channel,
                $sessionId,
                $userId,
                'error',
                $result
            );
        }

        $created = is_array($result['created'] ?? null) ? $result['created'] : [];
        $skipped = is_array($result['skipped'] ?? null) ? $result['skipped'] : [];
        $replyText = $isDryRun
            ? 'Playbook ' . $sectorKey . ' validado en simulacion.'
            : 'Playbook ' . $sectorKey . ' instalado.';
        if (!empty($created)) {
            $replyText .= ' Contratos creados: ' . implode(', ', $created) . '.';
        }
        if (!empty($skipped)) {
            $replyText .= ' Ya existian: ' . count($skipped) . '.';
        }

        return $reply($replyText, $channel, $sessionId, $userId, 'success', $result);
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

