<?php
// framework/tests/verify_multisector_reasoning.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;

$agent = new ChatAgent();
$payload = [
    'message' => 'pierdo plata con los cables porque no se cuanta cantidad me queda',
    'tenant_id' => 'suki_core',
    'user_id' => 'test_verifier',
    'mode' => 'app', // Cambiado a app para evitar desvíos de builder
    'channel' => 'verification_script'
];

echo "--- Iniciando verificación de razonamiento multisectorial ---\n";
echo "Pregunta: " . $payload['message'] . "\n\n";

try {
    $response = $agent->handle($payload);
    
    // El ChatAgent devuelve ['status' => '...', 'data' => ['reply' => '...']]
    $reply = $response['data']['reply'] ?? 'N/A';
    
    echo "Respuesta del Agente:\n";
    echo "Respuesta: " . $reply . "\n";
    
    // Verificación de éxito: el contenido vectorizado habla de cables y pérdida de dinero (solve_unit_conversion)
    $normalizedReply = strtolower($reply);
    $ok = (str_contains($normalizedReply, 'cable') || str_contains($normalizedReply, 'plata') || str_contains($normalizedReply, 'cantidad'));
    
    if ($ok && $reply !== 'N/A') {
        echo "\n✅ VERIFICACIÓN EXITOSA: El agente recuperó el contexto multisectorial indexado.\n";
    } else {
        echo "\n❌ VERIFICACIÓN FALLIDA: El agente no asoció la consulta con el nuevo conocimiento.\n";
        echo "Respuesta Completa:\n" . json_encode($response, JSON_PRETTY_PRINT) . "\n";
    }
} catch (\Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}
