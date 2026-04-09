<?php
// framework/tests/test_identity_hierarchy.php

require_once __DIR__ . '/../app/autoload.php';
use App\Core\ChatAgent;

function runIdentityHierarchySuite() {
    $agent = new ChatAgent();
    $sessionId = 'test_session_' . bin2hex(random_bytes(4));

    echo "=== Test de Jerarquía de Identidad ===\n\n";

    // 1. Prueba: Usuario Anónimo (Nuevo Desarrollador)
    echo "Prueba 1: Usuario Anónimo (Debe ser Developer)...\n";
    $payload1 = [
        'message' => 'Hola Suki',
        'session_id' => $sessionId,
        'channel' => 'web_builder',
        'mode' => 'builder'
    ];
    $res1 = $agent->handle($payload1);
    
    // El userId final se envia en la respuesta si el UI lo requiere, 
    // pero aquí lo verificamos en la telemetría interna o por la respuesta.
    // En este caso, Suki debería tratarnos como desarrollador.
    
    $logsDir = __DIR__ . '/../storage/logs/transcripts';
    $expectedTxt = $logsDir . '/history_default_' . $sessionId . '.txt';

    if (is_file($expectedTxt)) {
        echo "  [OK] Archivo de transcripción TXT generado correctamente.\n";
        echo "  Contenido:\n" . file_get_contents($expectedTxt) . "\n";
    } else {
        echo "  [FAIL] No se encontró el archivo TXT en: $expectedTxt\n";
    }

    // 2. Prueba: Identificación de la Torre (Architect)
    echo "\nPrueba 2: Petición desde la Torre (Channel: portal)...\n";
    $sessionId2 = 'test_session_tower_' . bin2hex(random_bytes(4));
    $expectedTxt2 = $logsDir . '/history_default_' . $sessionId2 . '.txt';
    
    $payload2 = [
        'message' => '¿Cuál es mi rol?',
        'session_id' => $sessionId2,
        'channel' => 'portal', // Señal de la Torre
        'mode' => 'builder'
    ];
    $res2 = $agent->handle($payload2);
    // Verificamos si la respuesta reconoce el nivel superior o simplemente el flujo
    echo "  Respuesta de Suki: " . $res2['data']['reply'] . "\n";
    
    // Verificamos si el archivo TXT se actualizó
    if (is_file($expectedTxt2)) {
        $content = file_get_contents($expectedTxt2);
        if (str_contains($content, '¿Cuál es mi rol?')) {
            echo "  [OK] El archivo TXT se actualizó con el segundo mensaje.\n";
        } else {
            echo "  [FAIL] El mensaje de la Torre no aparece en el TXT.\n";
        }
    } else {
        echo "  [FAIL] No se encontró el archivo TXT 2 en: $expectedTxt2\n";
    }

    echo "\n=== Fin de Pruebas ===\n";
}

runIdentityHierarchySuite();
