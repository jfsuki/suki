<?php
// framework/scripts/setup_knowledge_library.php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$dbPath = $projectRoot . '/project/storage/meta/knowledge_catalog.sqlite';

if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Recreate Nodes Table with UNIQUE constraint and long_content
    $db->exec("DROP TABLE IF EXISTS knowledge_nodes");
    $db->exec("CREATE TABLE knowledge_nodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL,
        authority TEXT,
        country TEXT DEFAULT 'CO',
        sector_key TEXT NOT NULL,
        node_type TEXT DEFAULT 'GENERAL',
        node_name TEXT NOT NULL,
        description TEXT,
        long_content TEXT,
        maturity INTEGER DEFAULT 0,
        status TEXT DEFAULT 'GAP',
        skill_class TEXT,
        last_trained DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(sector_key, node_name)
    )");

    // 2. Create User Memory Table
    $db->exec("CREATE TABLE IF NOT EXISTS user_memory_nodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        node_key TEXT NOT NULL,
        content TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 3. Seed Refined Nodes (Phase 11.2)
    $seeds = [
        // SECTOR: FERRETERIA
        ['LOGISTICA', 'INTERNAL', 'CO', 'FERRETERIA', 'PRODUCTOS', 'Conversión de Medidas', 
         'Matemática de rollos, metros y unidades.', 
         'Este conocimiento permite a SUKI entender que los cables se compran por rollo pero se venden por metro. Aplica factores de conversión de 100m por rollo estándar.', 
         90, 'TRAINED', 'UnitConversionSkill'],
        ['VENTAS', 'INTERNAL', 'CO', 'FERRETERIA', 'VENTAS', 'Venta por Fracción', 
         'Cálculo de metros parciales en punto de venta.', 
         'Habilidad para facturar metros lineales de cable o tubería descontando del stock base del rollo original.', 
         70, 'TRAINED', 'UnitConversionSkill'],
        ['FISCAL', 'DIAN', 'CO', 'FERRETERIA', 'ADMIN', 'Retenciones Construcción', 
         'Retenciones específicas para el sector ferretero.', 
         'Base gravable y porcentajes de retención en la fuente aplicables a materiales de construcción según DIAN.', 
         20, 'GAP', 'FiscalTaxSkill'],

        // SECTOR: FARMACIA / DROGUERIA
        ['SALUD', 'INVIMA', 'CO', 'FARMACIA', 'PRODUCTOS', 'Control de Lotes y Vencimiento', 
         'Trazabilidad sanitaria obligatoria.', 
         'Gestión de números de lote y fechas caducidad para cumplimiento de la norma sanitaria colombiana.', 
         85, 'TRAINED', 'ExpiryControlSkill'],
        ['SALUD', 'INVIMA', 'CO', 'FARMACIA', 'LOGISTICA', 'Semáforo FEFO', 
         'Prioridad de despacho por vencimiento.', 
         'Lógica de sacado de inventario: el primero en vencer es el primero en salir (First Expired, First Out).', 
         60, 'TRAINED', 'ExpiryControlSkill'],
        ['SALUD', 'MIN_SALUD', 'CO', 'FARMACIA', 'PRODUCTOS', 'Fitoterapéuticos (Naturales)', 
         'Venta y regulación de medicina natural.', 
         'Conocimiento sobre dosificación básica y regulación de productos de origen natural registrados como suplementos.', 
         30, 'GAP', NULL],

        // SECTOR: ADMINISTRACION / GESTION
        ['ADMIN', 'CTCP', 'CO', 'ADMIN', 'ADMIN', 'Contabilidad Pyme', 
         'Plan de cuentas simplificado.', 
         'Estructura de activos, pasivos y patrimonio para pequeños negocios comerciales.', 
         40, 'GAP', 'AccountingSkill'],
        ['FISCAL', 'DIAN', 'CO', 'ADMIN', 'ADMIN', 'IVA y Facturación', 
         'Cálculo de impuestos según estatuto tributario.', 
         'Reglas de redondeo a 500 y generación de PDF bajo estándar DIAN.', 
         75, 'TRAINED', 'FiscalTaxSkill'],
        ['VENTAS', 'INTERNAL', 'CO', 'ADMIN', 'VENTAS', 'Cartera y Cobros', 
         'Seguimiento de saldos de clientes.', 
         'Módulo de gestión de cuentas por cobrar para ventas a crédito en mostrador.', 
         25, 'GAP', NULL],
    ];

    $stmt = $db->prepare("INSERT OR REPLACE INTO knowledge_nodes (domain, authority, country, sector_key, node_type, node_name, description, long_content, maturity, status, skill_class) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($seeds as $s) {
        $stmt->execute($s);
    }

    echo "OK: Knowledge Catalog RE-BUILT with " . count($seeds) . " granular nodes and deduplication logic.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
