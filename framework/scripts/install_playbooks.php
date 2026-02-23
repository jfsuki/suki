<?php
declare(strict_types=1);

// framework/scripts/install_playbooks.php
// Usage:
//   php framework/scripts/install_playbooks.php --list
//   php framework/scripts/install_playbooks.php --sector=FERRETERIA [--dry-run] [--overwrite]

$root = dirname(__DIR__, 2);
$projectRoot = $root . '/project';
$projectPath = $projectRoot . '/contracts/knowledge/domain_playbooks.json';
$frameworkPath = $root . '/framework/contracts/agents/domain_playbooks.json';

$sourcePath = is_file($projectPath) ? $projectPath : $frameworkPath;
if (!is_file($sourcePath)) {
    fwrite(STDERR, "No se encontro domain_playbooks.json\n");
    exit(1);
}

$raw = file_get_contents($sourcePath);
if (!is_string($raw) || trim($raw) === '') {
    fwrite(STDERR, "domain_playbooks.json vacio\n");
    exit(1);
}
$playbook = json_decode($raw, true);
if (!is_array($playbook)) {
    fwrite(STDERR, "domain_playbooks.json invalido\n");
    exit(1);
}

$opts = getopt('', ['sector::', 'dry-run', 'overwrite', 'list']);
$sectorPlaybooks = is_array($playbook['sector_playbooks'] ?? null) ? $playbook['sector_playbooks'] : [];
if (empty($sectorPlaybooks)) {
    fwrite(STDERR, "No hay sector_playbooks definidos\n");
    exit(1);
}

if (isset($opts['list'])) {
    $out = [];
    foreach ($sectorPlaybooks as $sector) {
        if (!is_array($sector)) {
            continue;
        }
        $out[] = [
            'sector_key' => strtoupper((string) ($sector['sector_key'] ?? '')),
            'profile_key' => (string) ($sector['profile_key'] ?? ''),
            'mini_apps' => is_array($sector['mini_apps'] ?? null) ? $sector['mini_apps'] : [],
        ];
    }
    echo json_encode(['source' => str_replace($root . '/', '', $sourcePath), 'sectors' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$sectorKey = strtoupper(trim((string) ($opts['sector'] ?? '')));
if ($sectorKey === '') {
    fwrite(STDERR, "Falta --sector=SECTOR_KEY. Usa --list para ver opciones.\n");
    exit(1);
}
$dryRun = isset($opts['dry-run']);
$overwrite = isset($opts['overwrite']);

$selected = null;
foreach ($sectorPlaybooks as $sector) {
    if (!is_array($sector)) {
        continue;
    }
    if (strtoupper((string) ($sector['sector_key'] ?? '')) === $sectorKey) {
        $selected = $sector;
        break;
    }
}
if (!is_array($selected)) {
    fwrite(STDERR, "Sector no encontrado: {$sectorKey}\n");
    exit(1);
}

$entities = is_array($selected['blueprint']['entities'] ?? null) ? $selected['blueprint']['entities'] : [];
if (empty($entities)) {
    fwrite(STDERR, "El sector {$sectorKey} no tiene blueprint.entities\n");
    exit(1);
}

$entitiesDir = $projectRoot . '/contracts/entities';
if (!is_dir($entitiesDir) && !$dryRun) {
    if (!mkdir($entitiesDir, 0777, true) && !is_dir($entitiesDir)) {
        fwrite(STDERR, "No se pudo crear {$entitiesDir}\n");
        exit(1);
    }
}

$created = [];
$skipped = [];
foreach ($entities as $entityDef) {
    if (!is_array($entityDef)) {
        continue;
    }
    $name = normalizeEntityName((string) ($entityDef['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $file = $entitiesDir . '/' . $name . '.entity.json';
    if (is_file($file) && !$overwrite) {
        $skipped[] = ['entity' => $name, 'reason' => 'exists'];
        continue;
    }

    $fields = buildEntityFields(is_array($entityDef['fields'] ?? null) ? $entityDef['fields'] : []);
    $contract = [
        'type' => 'entity',
        'name' => $name,
        'label' => ucfirst(str_replace('_', ' ', $name)),
        'version' => '1.0',
        'table' => [
            'name' => tableNameForEntity($name),
            'primaryKey' => 'id',
            'timestamps' => true,
            'softDelete' => false,
            'tenantScoped' => true,
        ],
        'fields' => $fields,
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

    if (!$dryRun) {
        $json = json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            fwrite(STDERR, "No se pudo serializar contrato de {$name}\n");
            exit(1);
        }
        file_put_contents($file, $json . PHP_EOL);
    }
    $created[] = $name;
}

$report = [
    'ok' => true,
    'dry_run' => $dryRun,
    'overwrite' => $overwrite,
    'sector_key' => $sectorKey,
    'source' => str_replace($root . '/', '', $sourcePath),
    'created' => $created,
    'skipped' => $skipped,
    'next_step' => empty($created)
        ? 'No se crearon contratos nuevos. Usa --overwrite si quieres regenerarlos.'
        : 'En builder ejecuta: "crear formulario <entidad>" para cada entidad creada.',
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

function normalizeEntityName(string $name): string
{
    $name = strtolower(trim($name));
    $name = str_replace('-', '_', $name);
    return preg_replace('/[^a-z0-9_]/', '', $name) ?? '';
}

function tableNameForEntity(string $entity): string
{
    if ($entity === '') {
        return 'records';
    }
    if (str_ends_with($entity, 's')) {
        return $entity;
    }
    return $entity . 's';
}

function mapFieldType(string $type): string
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

function buildEntityFields(array $blueprintFields): array
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
        $name = normalizeEntityName((string) ($field['name'] ?? ''));
        if ($name === '' || $name === 'id') {
            continue;
        }
        $fields[] = [
            'name' => $name,
            'type' => mapFieldType((string) ($field['type'] ?? 'text')),
            'label' => ucfirst(str_replace('_', ' ', $name)),
            'required' => (bool) ($field['required'] ?? false),
            'source' => 'form',
        ];
    }
    return $fields;
}
