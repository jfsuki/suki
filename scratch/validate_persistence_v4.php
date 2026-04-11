<?php
// scratch/validate_persistence_v4.php
require_once __DIR__ . '/../framework/app/autoload.php';

use App\Core\ChatAgent;
use App\Controller\ChatController;

echo "--- 🔍 INICIANDO PRUEBA DE PERSISTENCIA REAL (CERO HUMO v4.0) ---\n";

try {
    $agent = new ChatAgent();
    $sessionId = 'test_sess_' . bin2hex(random_bytes(4));
    
    echo "1. Enviando primer mensaje al Agente (ID: $sessionId)...\n";
    $p1 = [
        'message' => 'Hola Suki, este es el primer mensaje de prueba.',
        'session_id' => $sessionId,
        'user_id' => 'admin',
        'is_authenticated' => true,
        'mode' => 'builder'
    ];
    $res1 = $agent->handle($p1);
    echo "   ✅ Respuesta del Agente recibida.\n";

    echo "2. Enviando segundo mensaje al Agente (Mismo ID)...\n";
    $p2 = [
        'message' => 'Segundo mensaje: ¿Persiste esto?',
        'session_id' => $sessionId,
        'user_id' => 'admin',
        'is_authenticated' => true,
        'mode' => 'builder'
    ];
    $res2 = $agent->handle($p2);
    echo "   ✅ Segunda respuesta recibida.\n";

    echo "3. Simulando carga de historial vía ChatController...\n";
    // Mock the global state that Controller expects
    $_SESSION['auth_user']['tenant_id'] = 'default';
    $_GET['session_id'] = $sessionId;
    
    $controller = new ChatController();
    $historyJson = $controller->history();
    $historyData = json_decode($historyJson, true);
    
    $messages = $historyData['data']['history'] ?? [];
    $count = count($messages);
    
    echo "\n--- ⚖️ RESULTADOS Finales ---\n";
    echo "Mensajes encontrados en SQL: $count\n";
    
    foreach ($messages as $idx => $msg) {
        echo " [" . ($idx+1) . "] " . $msg['direction'] . ": " . substr($msg['message'], 0, 30) . "...\n";
    }

    if ($count >= 2) {
        echo "\n🏁 ✅ PRUEBA SUPERADA: La persistencia entre Agente y Controlador está SINCRONIZADA en SQL.\n";
    } else {
        echo "\n🏁 ❌ FALLO DE PRUEBA: El historial solo tiene $count mensajes.\n";
    }

} catch (Exception $e) {
    echo "\n🏁 ❌ CRASH TÉCNICO: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
