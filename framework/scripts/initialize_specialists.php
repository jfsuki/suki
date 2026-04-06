<?php
require __DIR__ . '/../app/autoload.php';

use App\Core\ProjectRegistry;
use App\Core\Agents\Registry\SpecialistPersonas;

$reg = new ProjectRegistry();
$tenant = 'default';
$areas = ['FINANCES', 'SALES', 'ARCHITECT'];

echo "🚀 Starting Agent Initialization for tenant: $tenant\n";

foreach ($areas as $area) {
    try {
        $persona = SpecialistPersonas::getPersona($area);
        $agentId = $reg->createAgent($tenant, $persona['name'], $area, [
            'persona_name' => $persona['name'],
            'prompt_base' => $persona['prompt_base'],
            'capabilities' => $persona['capabilities']
        ]);
        echo "✅ Created $area Specialist: $agentId\n";
    } catch (Exception $e) {
        echo "❌ Error creating $area: " . $e->getMessage() . "\n";
    }
}

echo "✨ Initialization complete.\n";
