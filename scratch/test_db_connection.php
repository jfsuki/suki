<?php
// scratch/test_db_connection.php
require_once __DIR__ . '/../framework/app/autoload.php';
require_once __DIR__ . '/../project/public/api.php'; // This bootstraps the env

use App\Core\Database;

try {
    $db = Database::connection();
    echo "✅ Conexión PDO exitosa.\n";
    
    $userId = 'admin';
    $sql = "SELECT s.*, (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.session_id) as msg_count 
            FROM chat_sessions s 
            WHERE s.user_id = :userId 
            ORDER BY s.updated_at DESC 
            LIMIT 5";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([':userId' => $userId]);
    $rows = $stmt->fetchAll();
    
    echo "📊 Sesiones encontradas: " . count($rows) . "\n";
    foreach($rows as $r) {
        echo " - ID: {$r['session_id']} | Msgs: {$r['msg_count']} | Title: {$r['title']}\n";
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
