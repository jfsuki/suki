<?php
/**
 * generate_puc_json.php — Genera framework/data/puc_colombia_base.json
 * desde project/storage/sql/seed_puc_colombia.sql
 *
 * Uso: php framework/scripts/generate_puc_json.php
 */

$sqlFile  = __DIR__ . '/../../project/storage/sql/seed_puc_colombia.sql';
$jsonFile = __DIR__ . '/../data/puc_colombia_base.json';

if (!file_exists($sqlFile)) {
    echo "ERROR: No se encontró $sqlFile\n";
    exit(1);
}

$sql   = file_get_contents($sqlFile);
$lines = explode("\n", $sql);

$typeMap = [
    'asset'     => 'ACTIVO',
    'liability' => 'PASIVO',
    'equity'    => 'PATRIMONIO',
    'revenue'   => 'INGRESO',
    'expense'   => 'GASTO',
    'cost'      => 'COSTO',
    'memo'      => 'ORDEN',
];

$naturalezaMap = [
    'ACTIVO'    => 'DEBITO',
    'GASTO'     => 'DEBITO',
    'COSTO'     => 'DEBITO',
    'PASIVO'    => 'CREDITO',
    'PATRIMONIO'=> 'CREDITO',
    'INGRESO'   => 'CREDITO',
    'ORDEN'     => 'DEBITO',
];

$nivelMap = [1 => 'clase', 2 => 'grupo', 3 => 'cuenta', 4 => 'subcuenta'];

$accounts = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (!str_contains($line, 'INSERT INTO cuentas_contables')) {
        continue;
    }

    // Parse: VALUES ('system','CODE','NAME',LEVEL,'TYPE',1)
    if (!preg_match("/VALUES\s*\('system','([^']+)','([^']+)',(\d+),'([^']+)',(\d+)\)/i", $line, $m)) {
        continue;
    }

    [, $code, $name, $level, $type, $active] = $m;
    $level    = (int) $level;
    $active   = (int) $active;

    $tipo       = $typeMap[$type] ?? 'ACTIVO';
    $naturaleza = $naturalezaMap[$tipo] ?? 'DEBITO';
    $nivel      = $nivelMap[$level] ?? 'subcuenta';

    // Calcular parent code
    $parent = null;
    if (strlen($code) === 2) {
        $parent = substr($code, 0, 1);
    } elseif (strlen($code) === 4) {
        $parent = substr($code, 0, 2);
    } elseif (strlen($code) === 6) {
        $parent = substr($code, 0, 4);
    }

    $accounts[] = [
        'codigo'     => $code,
        'nombre'     => $name,
        'tipo'       => $tipo,
        'naturaleza' => $naturaleza,
        'nivel'      => $nivel,
        'parent'     => $parent,
    ];
}

$json = json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($jsonFile, $json);

echo "PUC generado: " . count($accounts) . " cuentas → $jsonFile\n";
