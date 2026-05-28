<?php
// framework/app/Core/Skills/PlaybookExecutorSkill.php

namespace App\Core\Skills;

final class PlaybookExecutorSkill
{
    public function handle(array $input, array $context = []): array
    {
        $sectorKey = trim((string) ($input['sector_key'] ?? ''));
        $businessGoal = trim((string) ($input['business_goal'] ?? ''));

        if ($sectorKey === '') {
            return [
                'status' => 'error',
                'message' => 'Se requiere sector_key para ejecutar el playbook.',
            ];
        }

        $safeSector = preg_replace('/[^a-z0-9_\-]/i', '', $sectorKey);
        $playbookPath = defined('FRAMEWORK_ROOT')
            ? FRAMEWORK_ROOT . '/data/playbooks/' . $safeSector . '.json'
            : __DIR__ . '/../../../../data/playbooks/' . $safeSector . '.json';

        if (!is_file($playbookPath)) {
            return [
                'status' => 'not_found',
                'sector_key' => $sectorKey,
                'message' => "El playbook para el sector '{$sectorKey}' no está disponible aún. "
                    . "El agente continuará con configuración estándar.",
            ];
        }

        $raw = file_get_contents($playbookPath);
        $playbook = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($playbook)) {
            return [
                'status' => 'error',
                'message' => "Playbook '{$sectorKey}' tiene formato inválido.",
            ];
        }

        return [
            'status' => 'ok',
            'sector_key' => $sectorKey,
            'business_goal' => $businessGoal,
            'playbook' => $playbook,
        ];
    }
}
