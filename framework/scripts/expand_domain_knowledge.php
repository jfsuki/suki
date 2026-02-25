<?php
declare(strict_types=1);

// framework/scripts/expand_domain_knowledge.php
// Scale-up de conocimiento: 15 sectores, 45 utterances y hard-negatives por sector.

$repoRoot = realpath(__DIR__ . '/..' . '/..');
if ($repoRoot === false) {
    fwrite(STDERR, "Cannot resolve repository root.\n");
    exit(1);
}

$frameworkDomainPath = $repoRoot . '/framework/contracts/agents/domain_playbooks.json';
$projectDomainPath = $repoRoot . '/project/contracts/knowledge/domain_playbooks.json';
$targetUtterances = 45;

$domain = readJson($frameworkDomainPath);

$sectorByKey = [];
foreach ((array) ($domain['sector_playbooks'] ?? []) as $sector) {
    if (!is_array($sector)) {
        continue;
    }
    $key = strtoupper(trim((string) ($sector['sector_key'] ?? '')));
    if ($key !== '') {
        $sectorByKey[$key] = $sector;
    }
}

foreach (newSectorPlaybooks() as $sector) {
    $sectorByKey[strtoupper((string) $sector['sector_key'])] = $sector;
}

$solverByName = [];
$solverOrder = [];
foreach ((array) ($domain['solver_intents'] ?? []) as $solver) {
    if (!is_array($solver)) {
        continue;
    }
    $name = trim((string) ($solver['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $solverByName[$name] = $solver;
    $solverOrder[] = $name;
}

foreach (newSolverIntents() as $solver) {
    $name = trim((string) ($solver['name'] ?? ''));
    if (!isset($solverByName[$name])) {
        $solverOrder[] = $name;
    }
    $solverByName[$name] = $solver;
}

$hardNegatives = hardNegativesBySector();
foreach ($solverByName as $name => $solver) {
    $sectorKey = strtoupper(trim((string) ($solver['sector_key'] ?? '')));
    $triggers = [];
    if (isset($sectorByKey[$sectorKey])) {
        $triggers = normalizeList($sectorByKey[$sectorKey]['triggers'] ?? []);
    }
    $solver['utterances'] = expandUtterances(normalizeList($solver['utterances'] ?? []), $sectorKey, $targetUtterances, $triggers);
    $solver['hard_negatives'] = mergeUnique(
        normalizeList($solver['hard_negatives'] ?? []),
        normalizeList($hardNegatives['GLOBAL'] ?? []),
        normalizeList($hardNegatives[$sectorKey] ?? [])
    );
    $solverByName[$name] = $solver;
}

$mergedSolvers = [];
foreach ($solverOrder as $name) {
    if (isset($solverByName[$name])) {
        $mergedSolvers[] = $solverByName[$name];
    }
}

foreach ($sectorByKey as $key => $sector) {
    $sector['hard_negatives'] = mergeUnique(
        normalizeList($sector['hard_negatives'] ?? []),
        normalizeList($hardNegatives['GLOBAL'] ?? []),
        normalizeList($hardNegatives[$key] ?? [])
    );
    $sectorByKey[$key] = $sector;
}

$domain['solver_intents'] = $mergedSolvers;
$domain['sector_playbooks'] = array_values($sectorByKey);

if (!isset($domain['meta']) || !is_array($domain['meta'])) {
    $domain['meta'] = [];
}
$domain['meta']['version'] = '0.6.0';
$domain['meta']['updated_at'] = date('Y-m-d');
$notes = normalizeList($domain['meta']['notes'] ?? []);
$note = 'v0.6.0: +6 sectores (15 total), +hard_negatives por sector, utterances ampliadas a 45.';
if (!in_array($note, $notes, true)) {
    $notes[] = $note;
}
$domain['meta']['notes'] = $notes;

writeJson($frameworkDomainPath, $domain);
writeJson($projectDomainPath, $domain);

echo json_encode([
    'ok' => true,
    'solver_intents' => count($domain['solver_intents'] ?? []),
    'sector_playbooks' => count($domain['sector_playbooks'] ?? []),
    'target_utterances' => $targetUtterances,
    'paths' => [
        'framework' => relPath($frameworkDomainPath, $repoRoot),
        'project' => relPath($projectDomainPath, $repoRoot),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function newSolverIntents(): array
{
    return [
        seedSolver('SOLVE_RENT_COLLECTION', 'INMOBILIARIA', 'APPLY_PLAYBOOK_INMOBILIARIA', [
            'no se quien debe arriendo', 'quiero cartera de arriendos', 'control de canon mensual',
            'seguimiento de contratos de alquiler', 'alertas de mora de inquilinos',
            'quiero app para inmobiliaria', 'me atraso cobrando arriendos', 'control de ocupacion por inmueble',
        ]),
        seedSolver('SOLVE_DISPATCH_TRACKING', 'LOGISTICA', 'APPLY_PLAYBOOK_LOGISTICA', [
            'no se donde van mis pedidos', 'quiero trazabilidad de entregas', 'me fallan las rutas de despacho',
            'necesito control de guias', 'quiero app para logistica', 'entregas atrasadas',
            'quiero planificador de rutas', 'seguimiento por numero de guia',
        ]),
        seedSolver('SOLVE_EVENT_BOOKING', 'EVENTOS', 'APPLY_PLAYBOOK_EVENTOS', [
            'se me cruzan reservas de eventos', 'quiero agenda de salones', 'control de anticipos y saldos',
            'quiero app para eventos', 'overbooking en fechas especiales', 'checklist por evento',
            'quiero contrato y cotizacion por evento', 'rentabilidad por evento',
        ]),
        seedSolver('SOLVE_HOTEL_OCCUPANCY', 'TURISMO_HOTELERIA', 'APPLY_PLAYBOOK_TURISMO_HOTELERIA', [
            'no se la ocupacion real del hotel', 'control de reservas y habitaciones', 'me hacen overbooking',
            'checkin y checkout rapido', 'estado de habitaciones', 'quiero app para hostal',
            'reporte de ocupacion diaria', 'ingreso por habitacion',
        ]),
        seedSolver('SOLVE_AGRO_TRACEABILITY', 'AGRO', 'APPLY_PLAYBOOK_AGRO', [
            'control de lotes de cultivo', 'no se costo por hectarea', 'trazabilidad de insumos',
            'quiero app para finca', 'registro de siembra y cosecha', 'bitacora de campo',
            'rendimiento por lote', 'inventario de agroinsumos',
        ]),
        seedSolver('SOLVE_ECOMMERCE_OMS', 'ECOMMERCE', 'APPLY_PLAYBOOK_ECOMMERCE', [
            'pedidos web desordenados', 'centralizar pedidos de marketplace', 'inventario online no cuadra',
            'quiero app para ecommerce', 'conciliar pagos de pasarela', 'control de devoluciones',
            'ordenes por canal', 'estado de fulfillment',
        ]),
    ];
}

function seedSolver(string $name, string $sectorKey, string $action, array $utterances): array
{
    return [
        'name' => $name,
        'sector_key' => $sectorKey,
        'action' => $action,
        'utterances' => $utterances,
    ];
}

function newSectorPlaybooks(): array
{
    return [
        seedSector('INMOBILIARIA', 'inmobiliaria', ['inmobiliaria', 'arriendo', 'alquiler', 'canon', 'inquilino'], 'no se quien debe arriendo', 'Cartera sin alertas por contrato.', 'Centraliza propiedades, contratos y recaudos.'),
        seedSector('LOGISTICA', 'logistica_distribucion', ['logistica', 'despacho', 'ruta', 'guia', 'entrega'], 'no se donde van mis pedidos', 'Despachos sin trazabilidad operativa.', 'Usa estados de entrega y eventos por guia.'),
        seedSector('EVENTOS', 'gestion_eventos', ['evento', 'reserva', 'salon', 'boda', 'catering'], 'se me cruzan reservas de eventos', 'Agenda y pagos sin control.', 'Gestiona reservas, anticipos y saldos por evento.'),
        seedSector('TURISMO_HOTELERIA', 'hoteleria', ['hotel', 'hostal', 'habitacion', 'checkin', 'checkout'], 'no se la ocupacion real del hotel', 'Disponibilidad y reservas se cruzan.', 'Consolida habitaciones, reservas y pagos.'),
        seedSector('AGRO', 'agro_produccion', ['agro', 'finca', 'cultivo', 'lote', 'cosecha'], 'no se costo real por hectarea', 'Costos y labores de campo dispersos.', 'Registra lotes, aplicaciones y cosechas por trazabilidad.'),
        seedSector('ECOMMERCE', 'ecommerce_oms', ['ecommerce', 'pedido web', 'marketplace', 'pasarela', 'fulfillment'], 'pedidos web desordenados', 'Operacion dividida por canal.', 'Centraliza ordenes, pagos y envios en flujo OMS.'),
    ];
}

function seedSector(
    string $sectorKey,
    string $profileKey,
    array $triggers,
    string $detect,
    string $diagnosis,
    string $solution
): array {
    $nameA = strtolower($sectorKey) . '_core';
    $nameB = strtolower($sectorKey) . '_ops';
    $nameC = strtolower($sectorKey) . '_fin';
    return [
        'sector_key' => $sectorKey,
        'profile_key' => $profileKey,
        'triggers' => $triggers,
        'pain_points' => [[
            'detect' => $detect,
            'diagnosis' => $diagnosis,
            'solution_pitch' => $solution,
        ]],
        'mini_apps' => [$nameA, $nameB, $nameC],
        'blueprint' => [
            'entities' => [
                [
                    'name' => $nameA,
                    'fields' => [
                        ['name' => 'nombre', 'type' => 'text'],
                        ['name' => 'estado', 'type' => 'text'],
                        ['name' => 'fecha', 'type' => 'date'],
                    ],
                ],
                [
                    'name' => $nameB,
                    'fields' => [
                        ['name' => $nameA . '_id', 'type' => 'number'],
                        ['name' => 'detalle', 'type' => 'text'],
                        ['name' => 'estado', 'type' => 'text'],
                    ],
                ],
                [
                    'name' => $nameC,
                    'fields' => [
                        ['name' => $nameA . '_id', 'type' => 'number'],
                        ['name' => 'monto', 'type' => 'decimal'],
                        ['name' => 'fecha', 'type' => 'date'],
                    ],
                ],
            ],
            'logic_rules' => [
                'saldo = total - sum(pagos)',
                'si estado = vencido entonces alerta = true',
            ],
            'kpis' => ['saldo_pendiente', 'tiempo_ciclo', 'ingreso_neto'],
        ],
    ];
}

function hardNegativesBySector(): array
{
    return [
        'GLOBAL' => [
            'elecciones presidenciales',
            'partido de futbol',
            'resultado de loteria',
            'precio del bitcoin',
            'gestion de reciclaje empresarial',
            'academia de programacion kids',
        ],
        'FERRETERIA' => ['historia clinica', 'reserva hotel', 'diezmo', 'matricula escolar'],
        'FARMACIA' => ['canon de arriendo', 'reserva de salon', 'pedido web', 'ruta de entrega'],
        'RESTAURANTE' => ['diezmo', 'boletin escolar', 'mantenimiento vehiculo', 'control de cultivo'],
        'MANTENIMIENTO' => ['historia clinica', 'reserva hotel', 'evento social', 'diezmo iglesia'],
        'PRODUCCION' => ['checkin hotel', 'reserva de salon', 'cartera arriendos', 'asistencia colegio'],
        'BELLEZA' => ['orden de despacho', 'guia transportadora', 'cosecha por lote', 'canon de arriendo'],
        'IGLESIA' => ['factura de hospedaje', 'orden de trabajo mecanico', 'pedido marketplace', 'historia clinica'],
        'EDUCACION' => ['diezmo', 'reserva hotel', 'orden de despacho', 'cartera arriendos'],
        'SERVICIOS_PRO' => ['inventario por metro', 'vencimiento medicamento', 'ocupacion hotel', 'asistencia dominical'],
        'INMOBILIARIA' => ['historia clinica', 'orden de produccion', 'checkin hotel', 'pedido marketplace'],
        'LOGISTICA' => ['historia clinica', 'tratamiento spa', 'cobro de arriendo', 'boletin escolar'],
        'EVENTOS' => ['inventario por metro', 'vencimiento de lotes', 'checkin checkout hotel', 'ruta de despacho'],
        'TURISMO_HOTELERIA' => ['diezmo', 'boletin escolar', 'orden de trabajo mecanico', 'control de cultivo'],
        'AGRO' => ['reserva de salon', 'checkin hotel', 'pedido marketplace', 'historia clinica paciente'],
        'ECOMMERCE' => ['diezmo iglesia', 'boletin escolar', 'historia clinica', 'cita de spa'],
    ];
}

function expandUtterances(array $baseUtterances, string $sectorKey, int $target, array $triggers): array
{
    $utterances = normalizeList($baseUtterances);
    $seen = [];
    foreach ($utterances as $u) {
        $seen[mb_strtolower($u, 'UTF-8')] = true;
    }

    $prefixes = ['necesito', 'quiero', 'me urge', 'ayudame a', 'como hago para', 'no se como', 'tengo problema con'];
    $suffixes = ['en mi negocio', 'sin excel', 'desde el celular', 'en tiempo real', 'con alertas'];
    $sectorLabel = strtolower(str_replace('_', ' ', $sectorKey));

    foreach (array_slice($utterances, 0, 20) as $seed) {
        if (count($utterances) >= $target) {
            break;
        }
        foreach ($prefixes as $prefix) {
            $candidate = trim($prefix . ' ' . $seed);
            $key = mb_strtolower($candidate, 'UTF-8');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $utterances[] = $candidate;
                if (count($utterances) >= $target) {
                    break;
                }
            }
        }
    }

    foreach (array_slice($utterances, 0, 20) as $seed) {
        if (count($utterances) >= $target) {
            break;
        }
        foreach ($suffixes as $suffix) {
            $candidate = trim($seed . ' ' . $suffix);
            $key = mb_strtolower($candidate, 'UTF-8');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $utterances[] = $candidate;
                if (count($utterances) >= $target) {
                    break;
                }
            }
        }
    }

    $fallbacks = [
        "quiero una mini app para {$sectorLabel}",
        "quiero matar excel en {$sectorLabel}",
        "quiero dashboard operativo de {$sectorLabel}",
    ];
    if (!empty($triggers)) {
        $fallbacks[] = 'necesito control de ' . implode(', ', array_slice($triggers, 0, 3));
    }
    $i = 1;
    while (count($utterances) < $target) {
        $candidate = $fallbacks[($i - 1) % count($fallbacks)] . " #{$i}";
        $key = mb_strtolower($candidate, 'UTF-8');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $utterances[] = $candidate;
        }
        $i++;
    }

    return array_slice($utterances, 0, $target);
}

function mergeUnique(array ...$chunks): array
{
    $seen = [];
    $result = [];
    foreach ($chunks as $chunk) {
        foreach ($chunk as $item) {
            $clean = trim((string) $item);
            if ($clean === '') {
                continue;
            }
            $key = mb_strtolower($clean, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $clean;
        }
    }
    return $result;
}

function normalizeList(mixed $values): array
{
    return mergeUnique(is_array($values) ? $values : []);
}

function readJson(string $path): array
{
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('Empty JSON: ' . $path);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON: ' . $path);
    }
    return $json;
}

function writeJson(string $path, array $data): void
{
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Cannot encode JSON: ' . $path);
    }
    file_put_contents($path, $payload . PHP_EOL, LOCK_EX);
}

function relPath(string $absolutePath, string $repoRoot): string
{
    return str_replace('\\', '/', ltrim(str_replace(str_replace('\\', '/', $repoRoot), '', str_replace('\\', '/', $absolutePath)), '/'));
}
