<?php
$dbPath = __DIR__ . '/../project/storage/meta/project_registry.sqlite';
$db = new \PDO('sqlite:' . $dbPath);

// The frontend is using tenant_id = 'demo'. Old sessions were 'default' and user_id 'master_tower' or 'guest'.
// Let's bind everything to 'admin' and 'demo' so the history works.
$db->exec("UPDATE chat_sessions SET user_id = 'admin', tenant_id = 'demo'");
$db->exec("UPDATE chat_log SET tenant_id = 'demo'");

echo "Registros alineados a tenant: demo, user: admin\n";
