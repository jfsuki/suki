<?php
// app/Core/Agents/DialogStateEngine.php

namespace App\Core\Agents;

final class DialogStateEngine
{
    /**
     * @return array<int, array{id:string,label:string,next:string}>
     */
    private function builderSteps(): array
    {
        return [
            ['id' => 'business_context', 'label' => 'Definir negocio y objetivo', 'next' => 'Dime tu tipo de negocio para iniciar.'],
            ['id' => 'data_model', 'label' => 'Crear primera tabla', 'next' => 'Creemos la primera tabla base (clientes/pacientes/proveedores).'],
            ['id' => 'forms', 'label' => 'Crear primer formulario', 'next' => 'Ahora creo el formulario principal para operar esa tabla.'],
            ['id' => 'validation', 'label' => 'Probar flujo base', 'next' => 'Haz una prueba corta para validar que el flujo funciona.'],
        ];
    }

    /**
     * @return array<int, array{id:string,label:string,next:string}>
     */
    private function appSteps(): array
    {
        return [
            ['id' => 'app_ready', 'label' => 'Validar tablas activas', 'next' => 'Reviso que haya tablas y te digo que puedes usar.'],
            ['id' => 'first_record', 'label' => 'Crear primer registro', 'next' => 'Registremos el primer dato real.'],
            ['id' => 'query_data', 'label' => 'Consultar informacion', 'next' => 'Ahora listamos o buscamos los registros guardados.'],
            ['id' => 'update_data', 'label' => 'Actualizar o eliminar', 'next' => 'Luego actualizamos o eliminamos un registro de prueba.'],
        ];
    }

    public function sync(array $state, string $mode, array $profile, int $entityCount, int $formCount): array
    {
        $mode = strtolower($mode) === 'builder' ? 'builder' : 'app';
        $dialog = is_array($state['dialog'] ?? null) ? $state['dialog'] : [];
        $existingChecklist = is_array($dialog['checklist'] ?? null) ? $dialog['checklist'] : [];
        $existingDone = [];
        foreach ($existingChecklist as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $existingDone[$id] = !empty($item['done']);
        }

        $steps = $mode === 'builder' ? $this->builderSteps() : $this->appSteps();
        $lastAction = is_array($state['last_action'] ?? null) ? $state['last_action'] : [];
        $lastCommand = strtoupper((string) ($lastAction['command'] ?? ''));

        $computedDone = [];
        if ($mode === 'builder') {
            $computedDone['business_context'] = !empty($profile['business_type']) || !empty($profile['needs_scope']);
            $computedDone['data_model'] = $entityCount > 0;
            $computedDone['forms'] = $formCount > 0;
            $computedDone['validation'] = in_array($lastCommand, ['CREATEENTITY', 'CREATEFORM', 'RUNTESTS'], true)
                || ($entityCount > 0 && $formCount > 0 && empty($state['builder_pending_command']));
        } else {
            $computedDone['app_ready'] = $entityCount > 0;
            $computedDone['first_record'] = $lastCommand === 'CREATERECORD';
            $computedDone['query_data'] = in_array($lastCommand, ['QUERYRECORDS', 'READRECORD'], true);
            $computedDone['update_data'] = in_array($lastCommand, ['UPDATERECORD', 'DELETERECORD'], true);
        }

        $checklist = [];
        $currentStepId = '';
        $currentStepLabel = '';
        foreach ($steps as $step) {
            $id = $step['id'];
            $done = !empty($existingDone[$id]) || !empty($computedDone[$id]);
            if (!$done && $currentStepId === '') {
                $currentStepId = $id;
                $currentStepLabel = $step['label'];
            }
            $checklist[] = [
                'id' => $id,
                'label' => $step['label'],
                'done' => $done,
                'next' => $step['next'],
            ];
        }

        if ($currentStepId === '') {
            $last = end($steps);
            $currentStepId = (string) ($last['id'] ?? '');
            $currentStepLabel = 'Flujo base completo';
        }

        $state['dialog'] = [
            'mode' => $mode,
            'current_step_id' => $currentStepId,
            'current_step_label' => $currentStepLabel,
            'checklist' => $checklist,
            'updated_at' => date('c'),
        ];

        return $state;
    }

    public function buildChecklistReply(array $state, string $mode): string
    {
        $mode = strtolower($mode) === 'builder' ? 'builder' : 'app';
        $dialog = is_array($state['dialog'] ?? null) ? $state['dialog'] : [];
        $checklist = is_array($dialog['checklist'] ?? null) ? $dialog['checklist'] : [];
        if (empty($checklist)) {
            return $mode === 'builder'
                ? 'No hay checklist activo aun. Dime tu tipo de negocio y empiezo guiandote.'
                : 'No hay checklist activo aun. Primero necesito una tabla habilitada para poder operar.';
        }

        $doneCount = 0;
        foreach ($checklist as $item) {
            if (!empty($item['done'])) {
                $doneCount++;
            }
        }
        $total = count($checklist);
        $currentId = (string) ($dialog['current_step_id'] ?? '');
        $currentLabel = (string) ($dialog['current_step_label'] ?? '');

        $lines = [];
        $lines[] = $mode === 'builder' ? 'Checklist BUILD:' : 'Checklist USE:';
        $lines[] = '- Avance: ' . $doneCount . '/' . $total . '.';
        if ($currentLabel !== '') {
            $lines[] = '- Paso actual: ' . $currentLabel . '.';
        }

        foreach ($checklist as $item) {
            if (!is_array($item)) {
                continue;
            }
            $done = !empty($item['done']);
            $prefix = $done ? '[x] ' : '[ ] ';
            $lines[] = $prefix . (string) ($item['label'] ?? '');
        }

        foreach ($checklist as $item) {
            if (!is_array($item) || empty($item['id']) || (string) $item['id'] !== $currentId) {
                continue;
            }
            $next = trim((string) ($item['next'] ?? ''));
            if ($next !== '') {
                $lines[] = 'Siguiente accion: ' . $next;
            }
            break;
        }

        if ($doneCount >= $total) {
            $lines[] = $mode === 'builder'
                ? 'Flujo base listo. Puedes pasar al chat de la app para registrar datos reales.'
                : 'Flujo base listo. Ya puedes operar datos reales en la app.';
        }

        return implode("\n", $lines);
    }
}

