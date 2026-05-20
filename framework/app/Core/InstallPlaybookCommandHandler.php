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

        // Pre-populate app_tenant_config with data already gathered during Builder interview.
        // This prevents App Chat from asking the user again for data they already provided.
        $initialConfig  = is_array($command['initial_config'] ?? null) ? $command['initial_config'] : [];
        $catalogAppId   = trim((string) ($command['catalog_app_id'] ?? ''));
        $activeMixins   = is_array($command['active_mixins'] ?? null) ? $command['active_mixins'] : null;

        if (!$isDryRun) {
            try {
                $tenantId  = (string) ($context['tenant_id'] ?? '');
                $appId     = ($catalogAppId !== '') ? $catalogAppId : strtolower($sectorKey);
                $configSvc = new \App\Core\AppTenantConfigService();

                // Persist interview fields so App Chat doesn't ask again
                foreach ($initialConfig as $fieldKey => $fieldValue) {
                    if (is_string($fieldKey) && is_string($fieldValue) && $fieldValue !== '') {
                        $configSvc->saveField($tenantId, $appId, $fieldKey, $fieldValue);
                    }
                }

                // Store the resolved catalog app id — ChatAgent reads this instead of the manifest id
                if ($appId !== '') {
                    $configSvc->saveField($tenantId, $appId, '_installed_app_id', $appId);
                    // Also record it under the generic tenant slot so ChatAgent can find it
                    // regardless of which $projectId the manifest sends.
                    $configSvc->saveField($tenantId, 'tenant_meta', '_installed_app_id', $appId);
                }

                // Store active mixins so AppConfigOnboarding only asks relevant fields
                if ($activeMixins !== null) {
                    $configSvc->saveField($tenantId, $appId, '_active_mixins', implode(',', $activeMixins));
                }
            } catch (\Throwable $ignored) {}
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

