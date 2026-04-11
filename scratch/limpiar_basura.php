<?php
$dbPath = __DIR__ . '/../project/storage/meta/project_registry.sqlite';
$db = new \PDO('sqlite:' . $dbPath);

// Delete all sessions with title 'Nueva Conversación' or 'Nueva sesión' where last_message_at is older than 2 minutes
// Or just nuke all sessions named 'Nueva Conversación' indiscriminately for the user since they are mostly testing spam.
$db->exec("DELETE FROM chat_sessions WHERE title LIKE '%Nueva Conversaci%' OR title LIKE '%Nueva sesi%'");
$db->exec("DELETE FROM chat_sessions WHERE user_id = 'master_tower'");
$db->exec("DELETE FROM chat_sessions WHERE user_id = 'guest'");

echo "Limpieza completada.\n";
