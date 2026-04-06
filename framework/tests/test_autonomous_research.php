<?php
// framework/tests/test_autonomous_research.php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../framework/app/autoload.php';

use App\Core\TrainingPortalController;

echo "🧠 Iniciando Test de Investigación Autónoma Real...\n";

// Asegurar que el entorno permita cambios
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('APP_ENV=local');

$controller = new TrainingPortalController();
$agentId = 'agnt_9b86947e'; // Agente de Ventas
$topic = 'Impuesto al Consumo 2024';
$tenantId = 'default';

$result = $controller->handleAutonomousResearch($agentId, $topic, $tenantId);

if ($result['success']) {
    echo "✅ ÉXITO: " . $result['message'] . "\n";
} else {
    echo "❌ ERROR: " . $result['message'] . "\n";
}
