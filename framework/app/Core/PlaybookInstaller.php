<?php
// app/Core/PlaybookInstaller.php

namespace App\Core;

final class PlaybookInstaller
{
    private string $projectRoot;
    private string $frameworkRoot;

    public function __construct(?string $projectRoot = null, ?string $frameworkRoot = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
        $this->frameworkRoot = $frameworkRoot
            ?? (defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 2));
    }

    public function listSectors(): array
    {
        $playbook = $this->loadPlaybook();
        $sectors = is_array($playbook['sector_playbooks'] ?? null) ? $playbook['sector_playbooks'] : [];
        $profiles = $this->profilesByKey($playbook);

        $out = [];
        foreach ($sectors as $sector) {
            if (!is_array($sector)) {
                continue;
            }
            $sectorKey = strtoupper((string) ($sector['sector_key'] ?? ''));
            if ($sectorKey === '') {
                continue;
            }
            $profileKey = strtolower((string) ($sector['profile_key'] ?? ''));
            $profile = is_array($profiles[$profileKey] ?? null) ? $profiles[$profileKey] : [];
            $out[] = [
                'sector_key' => $sectorKey,
                'profile_key' => $profileKey,
                'label' => (string) ($profile['label'] ?? $this->humanizeSectorKey($sectorKey)),
                'mini_apps' => is_array($sector['mini_apps'] ?? null) ? array_values($sector['mini_apps']) : [],
            ];
        }

        return $out;
    }

    public function installSector(string $sectorKey, bool $dryRun = false, bool $overwrite = false): array
    {
        $sectorKey = strtoupper(trim($sectorKey));
        if ($sectorKey === '') {
            return [
                'ok' => false,
                'error' => 'sector_required',
                'message' => 'Falta sector_key.',
            ];
        }

        $playbook = $this->loadPlaybook();
        $sector = $this->findSector($sectorKey, $playbook);
        if (empty($sector)) {
            return [
                'ok' => false,
                'error' => 'sector_not_found',
                'message' => 'Sector no encontrado: ' . $sectorKey,
            ];
        }

        $entities = is_array($sector['blueprint']['entities'] ?? null) ? $sector['blueprint']['entities'] : [];
        if (empty($entities)) {
            return [
                'ok' => false,
                'error' => 'empty_blueprint',
                'message' => 'El sector no tiene entities en blueprint.',
            ];
        }

        $entitiesDir = $this->projectRoot . '/contracts/entities';
        if (!$dryRun && !is_dir($entitiesDir)) {
            @mkdir($entitiesDir, 0777, true);
        }

        $created = [];
        $skipped = [];
        foreach ($entities as $entityDef) {
            if (!is_array($entityDef)) {
                continue;
            }
            $entityName = $this->normalizeEntityName((string) ($entityDef['name'] ?? ''));
            if ($entityName === '') {
                continue;
            }
            $file = $entitiesDir . '/' . $entityName . '.entity.json';
            if (is_file($file) && !$overwrite) {
                $skipped[] = ['entity' => $entityName, 'reason' => 'exists'];
                continue;
            }

            $contract = $this->buildEntityContract($entityName, (array) ($entityDef['fields'] ?? []));
            if (!$dryRun) {
                $payload = json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($payload)) {
                    file_put_contents($file, $payload . PHP_EOL);
                }
            }
            $created[] = $entityName;
        }

        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'overwrite' => $overwrite,
            'sector_key' => $sectorKey,
            'created' => $created,
            'skipped' => $skipped,
            'next_step' => empty($created)
                ? 'No se crearon contratos nuevos. Usa overwrite si quieres regenerarlos.'
                : 'Siguiente paso: crear formularios de las entidades creadas.',
        ];
    }

    private function loadPlaybook(): array
    {
        $projectPath = $this->projectRoot . '/contracts/knowledge/domain_playbooks.json';
        $frameworkPath = $this->frameworkRoot . '/contracts/agents/domain_playbooks.json';
        $path = is_file($projectPath) ? $projectPath : $frameworkPath;
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function profilesByKey(array $playbook): array
    {
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        $out = [];
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $key = strtolower((string) ($profile['key'] ?? ''));
            if ($key !== '') {
                $out[$key] = $profile;
            }
        }
        return $out;
    }

    private function findSector(string $sectorKey, array $playbook): array
    {
        $sectors = is_array($playbook['sector_playbooks'] ?? null) ? $playbook['sector_playbooks'] : [];
        foreach ($sectors as $sector) {
            if (!is_array($sector)) {
                continue;
            }
            if (strtoupper((string) ($sector['sector_key'] ?? '')) === $sectorKey) {
                return $sector;
            }
        }
        return [];
    }

    private function buildEntityContract(string $entityName, array $blueprintFields): array
    {
        return [
            'type' => 'entity',
            'name' => $entityName,
            'label' => ucfirst(str_replace('_', ' ', $entityName)),
            'version' => '1.0',
            'table' => [
                'name' => $this->tableNameForEntity($entityName),
                'primaryKey' => 'id',
                'timestamps' => true,
                'softDelete' => false,
                'tenantScoped' => true,
            ],
            'fields' => $this->buildEntityFields($blueprintFields),
            'grids' => [],
            'relations' => [],
            'rules' => [],
            'permissions' => [
                'read' => ['admin', 'seller'],
                'create' => ['admin', 'seller'],
                'update' => ['admin', 'seller'],
                'delete' => ['admin'],
            ],
        ];
    }

    private function buildEntityFields(array $blueprintFields): array
    {
        $fields = [
            [
                'name' => 'id',
                'type' => 'int',
                'primary' => true,
                'source' => 'system',
            ],
        ];
        foreach ($blueprintFields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = $this->normalizeEntityName((string) ($field['name'] ?? ''));
            if ($name === '' || $name === 'id') {
                continue;
            }
            $fields[] = [
                'name' => $name,
                'type' => $this->mapFieldType((string) ($field['type'] ?? 'text')),
                'label' => ucfirst(str_replace('_', ' ', $name)),
                'required' => (bool) ($field['required'] ?? false),
                'source' => 'form',
            ];
        }
        return $fields;
    }

    private function mapFieldType(string $type): string
    {
        $type = strtolower(trim($type));
        return match ($type) {
            'number', 'int', 'integer' => 'int',
            'decimal', 'float', 'double', 'money' => 'decimal',
            'date' => 'date',
            'datetime', 'timestamp' => 'datetime',
            'bool', 'boolean' => 'bool',
            default => 'string',
        };
    }

    private function tableNameForEntity(string $entity): string
    {
        if ($entity === '') {
            return 'records';
        }
        if (str_ends_with($entity, 's')) {
            return $entity;
        }
        return $entity . 's';
    }

    private function normalizeEntityName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace('-', '_', $name);
        return preg_replace('/[^a-z0-9_]/', '', $name) ?? '';
    }

    private function humanizeSectorKey(string $sectorKey): string
    {
        $label = strtolower(str_replace('_', ' ', trim($sectorKey)));
        return $label !== '' ? ucfirst($label) : 'Sector';
    }
}
