<?php
// framework/tests/e2e_semantic_regression_test.php

require_once __DIR__ . '/../app/autoload.php';
use App\Core\ChatAgent;

/**
 * Suite de Regresión Semántica (E2E)
 * Valida que el motor de NLP no sea engañado por palabras comunes
 * ni caiga en habilidades incorrectas por falsos positivos.
 */
function runSemanticRegressionSuite() {
    $agent = new ChatAgent();
    $tenantId = 'test_semantic_' . bin2hex(random_bytes(4));
    
    $payloads = [
        [
            'name' => 'Falso Positivo de Media Ingestion (OCR Bug)',
            'message' => 'Hola vamos a crear una app para venta de herramientas pero que quede generica para otros productos',
            'expected_not_in_reply' => 'He detectado una imagen',
            'expected_in_reply' => ['negocio', 'entendido', 'crear', 'hablame', 'ayudarte', 'nombre'], // Conceptos de onboarding
        ],
        [
            'name' => 'Falso Positivo de Excel Ingestion',
            'message' => 'Quiero una app excelente que me permita llevar datos',
            'expected_not_in_reply' => 'Para importar tus datos o inventario desde Excel',
        ],
        [
            'name' => 'Frustración y Ambigüedad (Fallback Training Hint)',
            'message' => 'de donde? y eso q es',
            'expected_not_in_reply' => 'Parece que me falta información',
            // El mensaje de Entrenamiento ya no debe ser obligatorio por defecto
        ]
    ];

    $total = count($payloads);
    $passed = 0;

    echo "\n=== E2E Semantic Regression Test ===\n\n";

    foreach ($payloads as $i => $test) {
        echo "Prueba " . ($i + 1) . " / {$total}: {$test['name']}\n";
        
        $payload = [
            'tenant_id' => $tenantId,
            'user_id' => 'admin',
            'project_id' => 'test_project',
            'mode' => 'builder',
            'message' => $test['message'],
            'is_authenticated' => true,
        ];

        try {
            // Se ejecuta el pipeline completo sin aislamientos, idéntico a producción
            $response = $agent->handle($payload);
            $reply = mb_strtolower(is_array($response['data']) && isset($response['data']['reply']) ? $response['data']['reply'] : $response['message']);
            
            $pass = true;
            $failReason = '';

            if (isset($test['expected_not_in_reply'])) {
                $forbidden = mb_strtolower($test['expected_not_in_reply']);
                if (str_contains($reply, $forbidden)) {
                    $pass = false;
                    $failReason = "Contenido prohibido detectado: '{$forbidden}'.";
                }
            }

            if ($pass && isset($test['expected_in_reply'])) {
                $foundAny = false;
                foreach ($test['expected_in_reply'] as $expected) {
                    if (str_contains($reply, mb_strtolower($expected))) {
                        $foundAny = true;
                        break;
                    }
                }
                if (!$foundAny) {
                    $pass = false;
                    $failReason = "No se detectó la intención esperada en la respuesta.";
                }
            }

            if ($pass) {
                echo "  [\033[32mOK\033[0m] Pasó exitosamente.\n";
                $passed++;
            } else {
                echo "  [\033[31mFAIL\033[0m] Falló. Razón: {$failReason}\n";
                echo "  Respuesta real: {$reply}\n";
            }

        } catch (\Throwable $e) {
            echo "  [\033[31mERROR\033[0m] Falló por excepción: " . $e->getMessage() . "\n";
        }
        echo "---------------------------------------\n";
    }

    echo "\nResultados: {$passed} de {$total} exitosos.\n\n";
    return $passed === $total;
}

if (php_sapi_name() === 'cli') {
    $success = runSemanticRegressionSuite();
    exit($success ? 0 : 1);
}
