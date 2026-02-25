<?php
// framework/tests/unknown_business_llm_quality_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;

$gateway = new ConversationGateway();
$ref = new ReflectionClass($gateway);

$normalize = $ref->getMethod('normalizeUnknownBusinessLlmResolution');
$normalize->setAccessible(true);
$qualityEval = $ref->getMethod('evaluateUnknownBusinessLlmQuality');
$qualityEval->setAccessible(true);
$persist = $ref->getMethod('persistUnknownBusinessLlmSample');
$persist->setAccessible(true);
$memoryProp = $ref->getProperty('memory');
$memoryProp->setAccessible(true);
$memory = $memoryProp->getValue($gateway);

$failures = [];

$resolved = $normalize->invoke(
    $gateway,
    [
        'status' => 'success',
        'confidence' => '0.91',
        'canonical_business_type' => 'restaurante_cafeteria',
        'business_candidate' => 'panaderia y cafeteria',
        'business_objective' => 'Digitalizar ventas y control de inventario de la panaderia.',
        'expected_result' => 'App minima con POS, inventario y cierre diario.',
        'reason_short' => 'Coincide por alias del sector y por flujo operativo completo de venta y cierre.',
        'needs_normalized' => '["ventas","inventario","pagos","facturacion","gastos/costos"]',
        'documents_normalized' => 'factura_venta, ticket_pos, recibo de caja, comprobante_egreso',
        'key_entities' => ['producto', 'cliente', 'transaccion_venta', 'materia_prima', 'receta_produccion', 'merma'],
        'first_module' => 'POS con facturacion',
        'operator_assistance_flow' => [
            'resumir negocio y confirmar objetivo',
            'guiar operacion diaria en pasos simples',
            'capturar venta o servicio y medio de pago',
            'calcular pago al personal y validar regla',
            'cerrar caja y validar diferencias',
        ],
        'similar_user_signals' => [
            'no se de sistemas',
            'es para ya',
            'no entiendo',
            'solo quiero que funcione',
        ],
        'training_dialog_flow' => [
            'deteccion | usuario: tengo panaderia | asistente: te guio paso a paso | dato: tipo_operacion',
            'resumen | usuario: no se de administracion | asistente: esto entendi... | dato: confirmacion',
            'catalogo | usuario: vendo pan y cafe | asistente: dame precio base | dato: catalogo',
            'cobro | usuario: cobro en efectivo y nequi | asistente: registro medios | dato: medios_pago',
            'personal | usuario: pago por porcentaje | asistente: guardo regla | dato: comision',
            'cierre | usuario: como cierro caja | asistente: te muestro cierre diario | dato: cierre',
        ],
        'training_gaps' => [
            'definir_regla_de_comisiones',
            'definir_cierre_de_caja',
            'definir_catalogo_base',
            'definir_soportes_fiscales',
        ],
        'next_data_questions' => [
            'Como pagas al personal? A) Porcentaje B) Fijo C) Mixto',
            'Que quieres resolver primero? A) POS B) Caja C) Inventario',
            'Como cobras? A) Efectivo B) Nequi/transferencia C) Mixto',
        ],
        'clarifying_question' => '',
    ],
    'panaderia y cafeteria',
    'mi empresa es una panaderia y cafeteria',
    [],
    [],
    0.85,
    ['needs' => [], 'documents' => []]
);

if ((string) ($resolved['status'] ?? '') !== 'MATCHED') {
    $failures[] = 'Normalizacion no mapeo status success -> MATCHED.';
}
$needs = is_array($resolved['needs_normalized'] ?? null) ? (array) $resolved['needs_normalized'] : [];
if (!in_array('inventario', $needs, true) || !in_array('ventas', $needs, true) || !in_array('pagos', $needs, true)) {
    $failures[] = 'Normalizacion no canonicalizo needs_normalized al vocabulario esperado.';
}
$docs = is_array($resolved['documents_normalized'] ?? null) ? (array) $resolved['documents_normalized'] : [];
if (!in_array('factura', $docs, true) || !in_array('ticket', $docs, true) || !in_array('comprobante de egreso', $docs, true) || !in_array('recibo de caja', $docs, true)) {
    $failures[] = 'Normalizacion no canonicalizo documents_normalized al vocabulario esperado.';
}
$entities = is_array($resolved['key_entities'] ?? null) ? (array) $resolved['key_entities'] : [];
if (count($entities) < 6) {
    $failures[] = 'Normalizacion no conservo entidades clave suficientes.';
}
if (count((array) ($resolved['operator_assistance_flow'] ?? [])) < 5) {
    $failures[] = 'Normalizacion no preservo flujo operativo de asistencia.';
}
if (count((array) ($resolved['training_dialog_flow'] ?? [])) < 6) {
    $failures[] = 'Normalizacion no preservo dialogos de entrenamiento suficientes.';
}
if (trim((string) ($resolved['business_objective'] ?? '')) === '' || trim((string) ($resolved['expected_result'] ?? '')) === '') {
    $failures[] = 'Normalizacion no preservo contexto tecnico (objetivo/resultado).';
}

$resolvedNoCanonical = $normalize->invoke(
    $gateway,
    [
        'status' => 'MATCHED',
        'confidence' => 0.95,
        'canonical_business_type' => '',
        'business_candidate' => 'panaderia',
        'business_objective' => '',
        'expected_result' => '',
        'needs_normalized' => [],
        'documents_normalized' => [],
        'key_entities' => [],
        'first_module' => '',
    ],
    'panaderia',
    'soy panaderia',
    [],
    [],
    0.85,
    ['needs' => ['ventas'], 'documents' => ['factura']]
);
if ((string) ($resolvedNoCanonical['status'] ?? '') !== 'NEEDS_CLARIFICATION') {
    $failures[] = 'MATCHED sin canonical debe degradar a NEEDS_CLARIFICATION.';
}

$qualityOk = $qualityEval->invoke($gateway, $resolved, 0.85);
if (!(bool) ($qualityOk['ok'] ?? false)) {
    $failures[] = 'Respuesta normalizada valida debe pasar quality gate.';
}

$qualityBad = $qualityEval->invoke(
    $gateway,
    [
        'status' => 'INVALID_RESPONSE',
        'confidence' => 0.0,
        'canonical_business_type' => '',
        'business_objective' => '',
        'expected_result' => '',
        'needs_normalized' => [],
        'documents_normalized' => [],
        'key_entities' => [],
        'first_module' => '',
        'clarifying_question' => '',
        'reason_short' => '',
    ],
    0.85
);
if ((bool) ($qualityBad['ok'] ?? true)) {
    $failures[] = 'INVALID_RESPONSE debe fallar quality gate.';
}

$tenantId = 'quality_test_' . time();
$userId = 'tester';
$persist->invoke(
    $gateway,
    $tenantId,
    $userId,
    'panaderia',
    'mi empresa es una panaderia y cafeteria',
    array_merge($resolved, ['provider_used' => 'gemini']),
    ['ok' => false, 'score' => 0.62, 'issues' => ['matched_sin_documents']]
);

$samples = $memory->getTenantMemory($tenantId, 'unknown_business_llm_samples', ['items' => []]);
$items = is_array($samples['items'] ?? null) ? (array) $samples['items'] : [];
if (count($items) < 1) {
    $failures[] = 'Persistencia de muestras LLM para entrenamiento no guardo registros.';
}
$queue = $memory->getTenantMemory($tenantId, 'research_queue', ['topics' => []]);
$topics = is_array($queue['topics'] ?? null) ? (array) $queue['topics'] : [];
$foundLowQualityTopic = false;
foreach ($topics as $topic) {
    $name = is_array($topic) ? (string) ($topic['topic'] ?? '') : '';
    if (str_contains($name, ':llm_quality')) {
        $foundLowQualityTopic = true;
        break;
    }
}
if (!$foundLowQualityTopic) {
    $failures[] = 'Persistencia de baja calidad no agrego topic de mejora para investigacion.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
