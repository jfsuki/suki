<?php
declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\AppDataService;
use App\Core\AppMemoryService;

/**
 * AppCrudSkill
 *
 * Handles insert / update / delete / find on app-creator tables.
 * Called by SkillExecutor for insert_app_data, update_app_data,
 * delete_app_data, find_app_data.
 *
 * Expected params (from LLM tool call):
 *   app_id   (string)  — ID of the created app
 *   entity   (string)  — entity/table name (snake_case, without prefix)
 *   action   (string)  — insert | update | delete | find
 *   id       (int)     — required for update | delete | find
 *   data     (object)  — field values for insert | update
 */
final class AppCrudSkill
{
    private AppDataService   $data;
    private AppMemoryService $memory;

    public function __construct(
        ?AppDataService   $data   = null,
        ?AppMemoryService $memory = null
    ) {
        $this->data   = $data   ?? new AppDataService();
        $this->memory = $memory ?? new AppMemoryService();
    }

    public function execute(array $params, string $tenantId): array
    {
        $appId      = trim((string) ($params['app_id'] ?? ''));
        $entityName = trim((string) ($params['entity']  ?? ''));
        $action     = strtolower(trim((string) ($params['action'] ?? 'insert')));
        $id         = isset($params['id']) ? (int) $params['id'] : null;
        $data       = is_array($params['data'] ?? null) ? (array) $params['data'] : [];

        if ($appId === '') {
            return $this->error('app_id es requerido.');
        }
        if ($entityName === '') {
            return $this->error('entity es requerido.');
        }

        try {
            return match ($action) {
                'insert' => $this->doInsert($tenantId, $appId, $entityName, $data),
                'update' => $this->doUpdate($tenantId, $appId, $entityName, $id, $data),
                'delete' => $this->doDelete($tenantId, $appId, $entityName, $id),
                'find'   => $this->doFind($tenantId, $appId, $entityName, $id),
                default  => $this->error("Acción desconocida: '{$action}'. Usa insert, update, delete o find."),
            };
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('Error interno: ' . $e->getMessage());
        }
    }

    private function doInsert(string $tenantId, string $appId, string $entityName, array $data): array
    {
        if (empty($data)) {
            return $this->error('Se requieren datos para insertar. Incluye el campo "data" con los valores del registro.');
        }

        $newId = $this->data->insertRecord($tenantId, $appId, $entityName, $data);
        $row   = $this->data->findRecord($tenantId, $appId, $entityName, $newId);

        $label = $this->resolveEntityLabel($tenantId, $appId, $entityName);
        $reply = "Registro creado exitosamente en **{$label}** (ID: {$newId}).";
        if ($row !== null) {
            $preview = $this->formatRowPreview($row);
            $reply  .= "\n{$preview}";
        }

        return [
            'action'    => 'respond_local',
            'reply'     => $reply,
            'status'    => 'success',
            'operation' => 'insert',
            'new_id'    => $newId,
            'row'       => $row,
        ];
    }

    private function doUpdate(string $tenantId, string $appId, string $entityName, ?int $id, array $data): array
    {
        if ($id === null || $id <= 0) {
            return $this->error('Se requiere el campo "id" para actualizar un registro.');
        }
        if (empty($data)) {
            return $this->error('Se requieren datos para actualizar. Incluye el campo "data" con los nuevos valores.');
        }

        $affected = $this->data->updateRecord($tenantId, $appId, $entityName, $id, $data);
        $row      = $affected > 0 ? $this->data->findRecord($tenantId, $appId, $entityName, $id) : null;
        $label    = $this->resolveEntityLabel($tenantId, $appId, $entityName);

        if ($affected === 0) {
            return $this->error("No se encontró el registro con ID {$id} en {$label}.");
        }

        $reply = "Registro ID {$id} actualizado en **{$label}**.";
        if ($row !== null) {
            $reply .= "\n" . $this->formatRowPreview($row);
        }

        return [
            'action'    => 'respond_local',
            'reply'     => $reply,
            'status'    => 'success',
            'operation' => 'update',
            'id'        => $id,
            'affected'  => $affected,
            'row'       => $row,
        ];
    }

    private function doDelete(string $tenantId, string $appId, string $entityName, ?int $id): array
    {
        if ($id === null || $id <= 0) {
            return $this->error('Se requiere el campo "id" para eliminar un registro.');
        }

        $label    = $this->resolveEntityLabel($tenantId, $appId, $entityName);
        $affected = $this->data->deleteRecord($tenantId, $appId, $entityName, $id);

        if ($affected === 0) {
            return $this->error("No se encontró el registro con ID {$id} en {$label}.");
        }

        return [
            'action'    => 'respond_local',
            'reply'     => "Registro ID {$id} eliminado de **{$label}**.",
            'status'    => 'success',
            'operation' => 'delete',
            'id'        => $id,
            'affected'  => $affected,
        ];
    }

    private function doFind(string $tenantId, string $appId, string $entityName, ?int $id): array
    {
        if ($id === null || $id <= 0) {
            return $this->error('Se requiere el campo "id" para buscar un registro específico.');
        }

        $label = $this->resolveEntityLabel($tenantId, $appId, $entityName);
        $row   = $this->data->findRecord($tenantId, $appId, $entityName, $id);

        if ($row === null) {
            return $this->error("No se encontró el registro con ID {$id} en {$label}.");
        }

        return [
            'action'    => 'respond_local',
            'reply'     => "Registro encontrado en **{$label}** (ID: {$id}):\n" . $this->formatRowFull($row),
            'status'    => 'success',
            'operation' => 'find',
            'id'        => $id,
            'row'       => $row,
        ];
    }

    private function resolveEntityLabel(string $tenantId, string $appId, string $entityName): string
    {
        foreach ($this->data->getEntityNames($tenantId, $appId) as $e) {
            if ($e['table'] === $entityName) {
                return $e['label'];
            }
        }
        return $entityName;
    }

    private function formatRowPreview(array $row): string
    {
        $skip  = ['tenant_id', 'deleted_at', 'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id'];
        $parts = [];
        foreach ($row as $k => $v) {
            if (in_array($k, $skip, true)) {
                continue;
            }
            if ($v === null || $v === '') {
                continue;
            }
            $parts[] = "**{$k}**: {$v}";
            if (count($parts) >= 5) {
                break;
            }
        }
        return implode(' · ', $parts);
    }

    private function formatRowFull(array $row): string
    {
        $skip  = ['tenant_id', 'deleted_at', 'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id'];
        $lines = [];
        foreach ($row as $k => $v) {
            if (in_array($k, $skip, true)) {
                continue;
            }
            $val     = $v ?? '—';
            $lines[] = "- **{$k}**: {$val}";
        }
        return implode("\n", $lines);
    }

    private function error(string $msg): array
    {
        return [
            'action' => 'respond_local',
            'reply'  => "No pude completar la operación: {$msg}",
            'status' => 'error',
            'error'  => $msg,
        ];
    }
}
