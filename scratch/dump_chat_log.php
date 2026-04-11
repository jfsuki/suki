<?php
// scratch/dump_chat_log.php
require_once __DIR__ . '/../framework/app/autoload.php';
$db = App\Core\Database::connection();
$stmt = $db->query("SELECT id, tenant_id, session_id, message, created_at FROM chat_log ORDER BY id DESC LIMIT 10");
echo "ID | Tenant | Session | Message | Created At\n";
echo str_repeat("-", 80) . "\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['id']} | {$row['tenant_id']} | {$row['session_id']} | " . substr($row['message'], 0, 20) . " | {$row['created_at']}\n";
}
