<?php
// framework/scripts/seed_semantic_onboarding.php

require_once __DIR__ . '/../app/autoload.php';
require_once __DIR__ . '/../app/Core/SemanticMemoryService.php';
require_once __DIR__ . '/../app/Core/QdrantVectorStore.php';

use App\Core\SemanticMemoryService;

$sectors = [
    [
        'id' => 'ferreteria_v2',
        'sector' => 'comercio_minorista',
        'business_type' => 'ferreteria',
        'content' => 'Ferretería, venta de herramientas, taladros, materiales de construcción, tornillería, pinturas y ferretería técnica.',
        'needs' => ['inventario', 'ventas', 'compras', 'cartera_clientes'],
        'documents' => ['factura', 'remision', 'recibo de pago', 'orden de pedido'],
    ],
    [
        'id' => 'tienda_v2',
        'sector' => 'retail',
        'business_type' => 'retail_tienda',
        'content' => 'Tienda de barrio, minimarket, venta de productos de consumo masivo, víveres, abarrotes y granos.',
        'needs' => ['inventario', 'ventas', 'caja_diaria'],
        'documents' => ['factura', 'ticket_pos', 'recibo'],
    ],
    [
        'id' => 'spa_v1',
        'sector' => 'bienestar',
        'business_type' => 'spa_estetica',
        'content' => 'Spa de belleza, peluquería, masajes, tratamientos estéticos, servicios de relajación y cuidado personal.',
        'needs' => ['citas', 'servicios', 'clientes', 'ventas'],
        'documents' => ['recibo', 'factura', 'agenda'],
    ],
    [
        'id' => 'repuestos_v1',
        'sector' => 'automotriz',
        'business_type' => 'repuestos_moto',
        'content' => 'Venta de repuestos para motos, accesorios, lubricantes, cascos y autopartes de motocicletas.',
        'needs' => ['inventario', 'ventas', 'compras'],
        'documents' => ['factura', 'ticket_pos'],
    ],
    [
        'id' => 'medico_v1',
        'sector' => 'salud',
        'business_type' => 'consultorio_medico',
        'content' => 'Consultorio médico privado, atención de pacientes, consulta externa, historias clínicas y citas.',
        'needs' => ['historias_clinicas', 'citas', 'pacientes', 'consentimientos'],
        'documents' => ['historia_clinica', 'orden_medica', 'recibo'],
    ],
    [
        'id' => 'transporte_v1',
        'sector' => 'logistica',
        'business_type' => 'carga_logistica',
        'content' => 'Empresa de transporte de carga, fletes, distribución de mercancía y servicios logísticos.',
        'needs' => ['fletes', 'vehiculos', 'rutas', 'conductores'],
        'documents' => ['manifiesto_carga', 'remesa', 'factura'],
    ],
    [
        'id' => 'educacion_v1',
        'sector' => 'educacion',
        'business_type' => 'cursos_clases',
        'content' => 'Centro de enseñanza, cursos de idiomas, tutorías, clases de música o capacitación profesional.',
        'needs' => ['alumnos', 'mensualidades', 'asistencia', 'cursos'],
        'documents' => ['recibo_matricula', 'certificado', 'factura'],
    ],
    [
        'id' => 'iglesia_v1',
        'sector' => 'ong_iglesia',
        'business_type' => 'iglesia_comunidad',
        'content' => 'Entidad religiosa, iglesia, control de congregación, donaciones, diezmos y actividades comunitarias.',
        'needs' => ['donaciones', 'miembros', 'eventos', 'tesoreria'],
        'documents' => ['recibo_donacion', 'acta'],
    ],
    [
        'id' => 'contabilidad_v1',
        'sector' => 'servicios_profesionales',
        'business_type' => 'oficina_contable',
        'content' => 'Despacho de contabilidad, asesoría tributaria, gestión de impuestos y auditoría para terceros.',
        'needs' => ['clientes', 'documentos', 'calendario_tributario', 'tareas'],
        'documents' => ['declaracion', 'cuenta_cobro', 'factura'],
    ],
    [
        'id' => 'mascotas_v1',
        'sector' => 'veterinaria',
        'business_type' => 'petshop_veterinaria',
        'content' => 'Centro veterinario, petshop, venta de comida para mascotas, accesorios y salud animal.',
        'needs' => ['mascotas', 'propietarios', 'vacunas', 'inventario'],
        'documents' => ['historia_veterinaria', 'factura', 'ticket_pos'],
    ],
];

function seedSectors($sectors) {
    echo "--- Iniciando entrenamiento semántico ELITE (Seeding Sector Knowledge) ---\n";
    
    $semantic = new SemanticMemoryService();
    if (!SemanticMemoryService::isEnabledFromEnv()) {
        echo "ERROR: Memoria semántica no habilitada en .env\n";
        exit(1);
    }

    $chunks = [];
    foreach ($sectors as $s) {
        $chunks[] = [
            'memory_type' => 'sector_knowledge',
            'tenant_id' => 'system',
            'source' => 'system_playbooks',
            'source_type' => 'playbook', // MANDATORY
            'source_id' => $s['id'],
            'chunk_id' => $s['id'],
            'content' => $s['content'],
            'type' => 'knowledge', // Standard type
            'version' => '1.1',
            'quality_score' => 1.0,
            'tags' => ['sector:' . $s['sector'], 'type:onboarding'], // Tags help retrieval
            'metadata' => [
                'business_type' => $s['business_type'],
                'needs' => $s['needs'],
                'documents' => $s['documents'],
            ],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
    }

    try {
        $result = $semantic->ingestSectorKnowledge($chunks, ['wait' => true]);
        echo "Resultado General: " . ($result['ok'] ? 'ÉXITO' : 'FALLA') . "\n";
        if ($result['ok']) {
            echo "Recibidos: " . ($result['received'] ?? 0) . "\n";
            echo "Aceptados: " . ($result['accepted'] ?? 0) . "\n";
            echo "Ingestados/Actualizados: " . ($result['upserted'] ?? 0) . "\n";
            echo "Rechazados/Dropped: " . ($result['dropped'] ?? 0) . "\n";
        }
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}

seedSectors($sectors);
