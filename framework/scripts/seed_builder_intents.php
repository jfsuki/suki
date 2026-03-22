<?php
// framework/scripts/seed_builder_intents.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';


use App\Core\SemanticMemoryService;
use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

echo "Iniciando Auto-Training de Intenciones Semánticas (Builder)...\n";

$memoryService = new SemanticMemoryService(
    new GeminiEmbeddingService(),
    new QdrantVectorStore()
);

// 1. HARDCODED SEED (Reemplazo de los if/else de PHP)
$seedIntents = [
    'greeting' => [
        'hola', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches', 'que tal', 'saludos', 'hey', 'start'
    ],
    'farewell' => [
        'adios', 'chao', 'hasta luego', 'nos vemos', 'bye', 'me voy', 'gracias es todo'
    ],
    'affirmation' => [
        'si', 'ok', 'claro', 'por supuesto', 'perfecto', 'dale', 'afirmativo', 'correcto', 'eso es', 'asi es'
    ],
    'negation' => [
        'no', 'negativo', 'para nada', 'claro que no', 'falso', 'incorrecto', 'no quiero'
    ],
    'frustration' => [
        'no entiendo', 'me rindo', 'esto no sirve', 'basura', 'ayuda', 'no se que hacer', 'estoy confundido', 'como funciona esto', 'que hago'
    ],
    'create_request' => [
        'quiero crear un sistema', 'necesito una aplicacion', 'hazme un erp', 'deseo automatizar mi negocio', 'construye una app', 'crear app', 'nuevo proyecto'
    ],
    'business_description' => [
        'tengo una ferreteria', 'vendo repuestos de autos', 'soy doctor', 'mi negocio es una clinica', 'tengo un restaurante', 'tienda de ropa', 'ofrezco servicios legales', 'soy abogado', 'vendo zapatos'
    ],
    'scope_question' => [
        'que modulos tiene', 'puedes hacer facturacion', 'lleva inventario', 'como maneja los clientes', 'tiene contabilidad', 'hace nominas', 'puedo vender online', 'se conecta con whatsapp'
    ],
    'pricing_question' => [
        'cuanto cuesta', 'que vale', 'cual es el precio', 'cobran mensual', 'es gratis', 'tienen planes'
    ],
    'out_of_scope' => [
        'presidente petro', 'chatgpt', 'que opinas de la politica', 'dime un chiste', 'hazme una tarea', 'quien es dios', 'cocinar una receta'
    ]
];

$chunks = [];
$seedId = 'seed_v1_';

foreach ($seedIntents as $intent => $phrases) {
    foreach ($phrases as $i => $phrase) {
        $chunks[] = [
            'memory_type' => 'agent_training',
            'tenant_id' => 'system',
            'app_id' => 'builder_core',
            'source_type' => 'agent_training',
            'source_id' => 'seed_builder_intents',
            'chunk_id' => $seedId . $intent . '_' . $i,
            'version' => '1.0',
            'content' => $phrase,
            'metadata' => [
                'intent' => $intent,
                'type' => 'canonical_phrase',
                'language' => 'es'
            ]
        ];
    }
}

echo "Inyectando " . count($chunks) . " frases semilla al Qdrant (agent_training)...\n";
try {
    $result = $memoryService->ingestAgentTraining($chunks);
    echo "Resultado Seed: " . $result['upserted'] . " vectores insertados. Drop/Dedupe: " . $result['deduped'] . "\n";
} catch (\Exception $e) {
    echo "Error Seed Qdrant: " . $e->getMessage() . "\n";
}

// 2. AUTO-TRAINING LOOP (Aprender de registros anteriores)
$dbDir = dirname(__DIR__, 2) . '/project/storage/meta';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0775, true);
}
$dbPath = $dbDir . '/intent_training_log.sqlite';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec('CREATE TABLE IF NOT EXISTS training_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_text TEXT,
    intent_classified TEXT,
    llm_score REAL,
    status TEXT DEFAULT \'pending\',
    created_at TEXT
)');

// Upserting pending training logs into Qdrant
$stmt = $db->query("SELECT rowid, user_text, intent_classified FROM training_log WHERE status = 'pending' AND llm_score >= 0.85 LIMIT 50");
$pendingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($pendingRows)) {
    echo "Encontradas " . count($pendingRows) . " frases pendientes de auto-entrenamiento...\n";
    $learningChunks = [];
    foreach ($pendingRows as $row) {
        $learningChunks[] = [
            'memory_type' => 'agent_training',
            'tenant_id' => 'system',
            'app_id' => 'builder_core',
            'source_type' => 'agent_training',
            'source_id' => 'auto_training_loop',
            'chunk_id' => 'auto_' . $row['rowid'] . '_' . md5($row['user_text']),
            'version' => '1.0',
            'content' => $row['user_text'],
            'metadata' => [
                'intent' => $row['intent_classified'],
                'type' => 'learned_phrase',
                'language' => 'es'
            ]
        ];
    }
    
    try {
        $learnResult = $memoryService->ingestAgentTraining($learningChunks);
        echo "Resultado Auto-Training: " . $learnResult['upserted'] . " vectores aprendidos.\n";
        
        // Marcar como entrenados
        $updateStmt = $db->prepare("UPDATE training_log SET status = 'trained' WHERE rowid = :rowid");
        foreach ($pendingRows as $row) {
            $updateStmt->execute([':rowid' => $row['rowid']]);
        }
    } catch (\Exception $e) {
        echo "Error Auto-Training Qdrant: " . $e->getMessage() . "\n";
    }
} else {
    echo "No hay nuevas frases validadas para auto-entrenar hoy.\n";
}

echo "Proceso finalizado con éxito.\n";
