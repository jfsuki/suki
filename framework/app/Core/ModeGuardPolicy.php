<?php
// app/Core/ModeGuardPolicy.php

namespace App\Core;

final class ModeGuardPolicy
{
    public function evaluate(
        string $mode,
        bool $hasBuildSignals,
        bool $hasRuntimeCrudSignals,
        bool $isPlaybookBuilderRequest = false
    ): ?array {
        if ($mode !== 'builder' && $hasBuildSignals) {
            return [
                'telemetry' => 'build_guard',
                'reply' => 'Esa tabla no existe en esta app. Eso se hace en el Creador de apps. Abre el chat creador para crear tablas o formularios.',
            ];
        }

        if ($mode === 'builder' && $hasRuntimeCrudSignals && !$hasBuildSignals && !$isPlaybookBuilderRequest) {
            return [
                'telemetry' => 'use_guard',
                'reply' => 'Estas en el Creador. Aqui definimos estructura (tablas/formularios). Para registrar datos usa el chat de la app.',
            ];
        }

        return null;
    }
}

