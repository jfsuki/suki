<?php
$dbPath = __DIR__ . '/../project/storage/meta/project_registry.sqlite';
$db = new \PDO('sqlite:' . $dbPath);
$db->exec("DELETE FROM chat_sessions");
$db->exec("DELETE FROM chat_log");
echo "Borradas absolutamente TODAS las sesiones y logs del proyecto.\n";
