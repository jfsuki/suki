<?php
$db = new \PDO('sqlite:' . __DIR__ . '/../project/storage/meta/project_registry.sqlite');
$db->exec("DELETE FROM chat_sessions WHERE user_id = 'master_tower'");
echo "Deleted corrupted sessions.\n";
