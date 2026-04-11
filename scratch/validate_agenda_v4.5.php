<?php
require_once __DIR__ . '/../framework/app/autoload.php';

use App\Core\AgentJournalService;

echo "--- VALIDANDO AGENDA ATÓMICA v4.5 ---\n";

$service = new AgentJournalService();
$tenantId = 'test_tenant';
$projectId = 'test_project';
$sid1 = 'sess_apple';
$sid2 = 'sess_orange';

echo "1. Creando bitácora para Sesión APPLE...\n";
$service->updateJournal($tenantId, $projectId, 'architect', ['summary' => 'Resumen de Manzanas'], $sid1);

echo "2. Creando bitácora para Sesión ORANGE...\n";
$service->updateJournal($tenantId, $projectId, 'architect', ['summary' => 'Resumen de Naranjas'], $sid2);

echo "3. Verificando aislamiento...\n";
$j1 = $service->getJournal($tenantId, $projectId, 'architect', $sid1);
$j2 = $service->getJournal($tenantId, $projectId, 'architect', $sid2);

echo "Apple Summary: " . $j1['summary'] . "\n";
echo "Orange Summary: " . $j2['summary'] . "\n";

if ($j1['summary'] !== $j2['summary']) {
    echo "✅ ÉXITO: Las agendas están correctamente aisladas por sesión.\n";
} else {
    echo "❌ FALLA: Las agendas se están sobreescribiendo.\n";
    exit(1);
}

// Cleanup
$path1 = __DIR__ . "/../framework/storage/meta/journals/journal_{$tenantId}_{$projectId}_architect_{$sid1}.json";
$path2 = __DIR__ . "/../framework/storage/meta/journals/journal_{$tenantId}_{$projectId}_architect_{$sid2}.json";
@unlink($path1);
@unlink($path2);

echo "--- VALIDACIÓN COMPLETADA ---\n";
